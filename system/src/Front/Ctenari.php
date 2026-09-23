<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Antispam;
use MiroCMS\Core\App;
use MiroCMS\Core\Posta;
use MiroCMS\Core\Response;
use MiroCMS\Core\Stripe;
use MiroCMS\Core\View;

/**
 * Účty čtenářů a uzamčený obsah (rozšíření "ctenari").
 *
 *   /ctenar              přihlášení + registrace, po přihlášení Můj účet
 *   /ctenar/predplatne   (POST) přihlášený čtenář odchází zaplatit předplatné nebo ho spravovat na stránky Stripe
 *   /ctenar/heslo/<token>  nastavení hesla: dokončení registrace (odkaz platí 3 dny) i zapomenuté heslo (2 hodiny)
 *
 * Přihlášení drží podepsaná cookie mirocms_ctenar (id.platnost.podpis) - bez PHP session, aby web zůstal
 * pro nepřihlášené cachovatelný. Cookie s předponou mirocms_c cache stránek vypíná (Front\Cache).
 */
final class Ctenari
{
    public const string COOKIE = 'mirocms_ctenar';
    public const string COOKIE_CTENO = 'mirocms_cteno';
    public const array PRISTUP = [0 => 'Všichni', 1 => 'Jen přihlášení čtenáři', 2 => 'Jen předplatitelé'];
    private const int PLATNOST = 60 * 86400;

    /** @var array<string, mixed>|false|null */
    private array|false|null $ctenar = null;
    private bool $meri = false;

    /** @var array{precteno:int, limit:int, vycerpano:bool}|null */
    private ?array $stavZdarma = null;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed>|null přihlášený čtenář */
    public function prihlaseny(): ?array
    {
        if ($this->ctenar === null) {
            $this->ctenar = false;
            $casti = explode('.', (string) ($_COOKIE[self::COOKIE] ?? ''));
            if (count($casti) === 3 && ctype_digit($casti[0]) && ctype_digit($casti[1]) && (int) $casti[1] > time()) {
                $ctenar = $this->app->db()->one('SELECT * FROM {ctenari} WHERE idct = ? AND potvrzen = 1', [(int) $casti[0]]);
                // v podpisu je i otisk hesla: změna hesla odhlásí všechna ostatní zařízení
                if ($ctenar !== null && hash_equals($this->podpis($casti[0] . '.' . $casti[1] . '.' . $ctenar['heslo']), $casti[2])) {
                    $this->ctenar = $ctenar;
                }
            }
        }

        return $this->ctenar ?: null;
    }

    public function jePredplatitel(): bool
    {
        $c = $this->prihlaseny();

        return $c !== null && $c['predplatne_do'] !== null && $c['predplatne_do'] >= date('Y-m-d');
    }

    /** Smí návštěvník číst celý článek? Redakce přihlášená v administraci vidí vše. */
    public function smiCist(array $clanek): bool
    {
        $smi = match (true) {
            (int) $clanek['pristup'] === 0, $this->app->auth()->user() !== null => true,
            (int) $clanek['pristup'] === 1 => $this->prihlaseny() !== null,
            default => $this->jePredplatitel(),
        };

        return $smi || ($this->meri && $this->zdarma((int) $clanek['idc']));
    }

    /** Měkký paywall se počítá jen na stránce článku, ne ve výpisech - Kernel ho zapíná kolem načtení článku. */
    public function meritClanek(bool $ano): void
    {
        $this->meri = $ano;
    }

    /** Kolik zamčených článků zdarma už čtenář tento měsíc otevřel a kolik jich má; null = měkký paywall je vypnutý nebo se nepoužil. */
    public function stavZdarma(): ?array
    {
        return $this->stavZdarma;
    }

    /**
     * Měkký paywall: pár zamčených článků měsíčně zdarma. Počítá podepsaná cookie mirocms_cteno (měsíc + čísla článků);
     * kdo ji smaže, začíná znovu - to je u měkkého paywallu záměr, ne chyba. Vyhledávače cookie neposílají a vidí celý text.
     */
    private function zdarma(int $idc): bool
    {
        $limit = $this->app->settings()->int('paywall_zdarma');
        if ($limit <= 0) {
            return false;
        }
        $mesic = date('Ym');
        $prectene = [];
        $casti = explode('.', (string) ($_COOKIE[self::COOKIE_CTENO] ?? ''));
        if (count($casti) === 3 && $casti[0] === $mesic && hash_equals($this->podpis('cteno.' . $casti[0] . '.' . $casti[1]), $casti[2])) {
            $prectene = array_slice(array_map(intval(...), array_filter(explode('-', $casti[1]), ctype_digit(...))), 0, 50);
        }
        if (!in_array($idc, $prectene, true)) {
            if (count($prectene) >= $limit) {
                $this->stavZdarma = ['precteno' => count($prectene), 'limit' => $limit, 'vycerpano' => true];

                return false;
            }
            $prectene[] = $idc;
            $seznam = implode('-', $prectene);
            setcookie(self::COOKIE_CTENO, $mesic . '.' . $seznam . '.' . $this->podpis('cteno.' . $mesic . '.' . $seznam), [
                'expires' => time() + 40 * 86400, 'path' => $this->app->request->basePath() . '/', 'secure' => $this->app->request->isHttps(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        $this->stavZdarma = ['precteno' => count($prectene), 'limit' => $limit, 'vycerpano' => false];

        return true;
    }

    /**
     * Zamčenému článku nechá jen ukázku textu. Volá se z Front\Clanky::priprav(), takže celý text
     * neunikne ani přes RSS, feed.json, API nebo /clanek/<adresa>.md.
     *
     * @param array<string, mixed> $clanek
     * @return array<string, mixed>
     */
    public function zamkni(array $clanek): array
    {
        $clanek['zamceno'] = !$this->smiCist($clanek);
        if ($clanek['zamceno']) {
            $ukazka = $this->ukazka((string) $clanek['text']);
            $clanek['text'] = $ukazka === '' ? '' : '<div class="mc-ukazka">' . $ukazka . '</div>';
            $clanek['faq'] = '';
        }

        return $clanek;
    }

    /** Ukázka zamčeného článku: prvních pár odstavců textu. */
    public function ukazka(string $html): string
    {
        $pocet = max(0, $this->app->settings()->int('zamek_odstavcu'));
        if ($pocet === 0 || !preg_match_all('#<p\b[^>]*>.*?</p>#is', $html, $m)) {
            return '';
        }

        return implode("\n", array_slice($m[0], 0, $pocet));
    }

    /**
     * Kam vede tlačítko „Získat předplatné“: s nastavenými platbami přes Stripe do účtu čtenáře (tam se platí, nepřihlášený se
     * nejdřív přihlásí nebo zaregistruje), jinak na stránku webu nebo https odkaz z Nastavení; cokoli jiného se ignoruje.
     */
    public function predplatneUrl(string $zpet = ''): string
    {
        if (Stripe::nastaveno($this->app->settings())) {
            return $this->app->url('ctenar') . ($zpet !== '' ? '?zpet=' . rawurlencode($zpet) : '');
        }
        $cil = trim($this->app->settings()->get('predplatne_url'));
        if (preg_match('#^https://[^\s"<>]+$#i', $cil)) {
            return $cil;
        }

        return preg_match('#^/?[a-z0-9][a-z0-9/_-]*$#i', $cil) ? $this->app->url(ltrim($cil, '/')) : '';
    }

    /** Výzva pod ukázkou zamčeného článku. */
    public function zamekHtml(array $clanek, View $view): string
    {
        return $view->render('zamek', [
            'predplatne' => (int) $clanek['pristup'] === 2,
            'prihlasen' => $this->prihlaseny() !== null,
            'text' => $this->app->settings()->get('zamek_text'),
            'predplatneUrl' => $this->predplatneUrl('clanek/' . $clanek['seo_link']),
            'ucet' => $this->app->url('ctenar') . '?zpet=' . rawurlencode('clanek/' . $clanek['seo_link']),
            'registrace' => $this->app->settings()->bool('ctenari_registrace'),
            'zdarma' => $this->stavZdarma,
        ]);
    }

    /**
     * Obsluha adres /ctenar…
     *
     * @return Response|array{0:string, 1:string} hotová odpověď (přesměrování), nebo [titulek, HTML obsahu stránky]
     */
    public function handle(string $path, View $view): Response|array
    {
        $r = $this->app->request;
        if (preg_match('#^/ctenar/vstup/([a-f0-9]{32})$#', $path, $m)) {
            return $this->vstupOdkazem($m[1], $view);
        }
        if (preg_match('#^/ctenar/heslo/([a-f0-9]{32})$#', $path, $m)) {
            return $this->noveHeslo($m[1], $view);
        }
        if ($path === '/ctenar/predplatne' && $r->isPost()) {
            return $this->predplatne();
        }
        if ($path !== '/ctenar') {
            return $this->zprava($view, t('Stránka nenalezena'), t('Tahle adresa neexistuje.'));
        }
        if ($r->isPost()) {
            Cache::vymaz();

            return match ($r->post('akce')) {
                'registrace' => $this->registrace(),
                'prihlaseni' => $this->prihlaseni(),
                'zapomenute' => $this->zapomenute(),
                'odkaz' => $this->zapomenute(true),
                'odhlasit' => $this->odhlas(),
                'ucet' => $this->ulozUcet(),
                'smazat' => $this->smazUcet(),
                'ulozit' => $this->ulozClanek(),
                default => Response::redirect($this->app->url('ctenar'), 303),
            };
        }

        $ctenar = $this->prihlaseny();
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $web = $this->app->settings();
        if ($ctenar !== null && $r->get('stav') === 'sprava') {
            $ctenar = $this->srovnejPredplatne($ctenar);
        }

        return [t($ctenar === null ? 'Přihlášení čtenáře' : 'Můj účet'), $view->render('ctenar', [
            'ctenar' => $ctenar,
            'predplatitel' => $this->jePredplatitel(),
            'predplatneUrl' => Stripe::nastaveno($web) ? '' : $this->predplatneUrl(),
            // platby přes Stripe: nabízená období s popisem ceny; kdo už přes Stripe platí, předplatné místo toho spravuje
            'platby' => $ctenar !== null && Stripe::nastaveno($web) ? Stripe::nabidka($web) : [],
            'bezici' => $ctenar !== null && self::beziStripe($ctenar),
            'platbyZapnute' => Stripe::nastaveno($web),
            'akcePlatby' => $this->app->url('ctenar/predplatne'),
            'akce' => $this->app->url('ctenar'),
            'zpet' => $this->zpet($r->get('zpet')),
            'stav' => $r->get('stav'),
            'pole' => $ctenar === null ? $antispam->pole('ctenar') : '',
            'podpis' => $ctenar === null ? '' : $this->podpis('formular.' . $ctenar['idct']),
            'registrace' => $this->app->settings()->bool('ctenari_registrace'),
            'newsletter' => \MiroCMS\Core\Rozsireni::je($this->app->settings(), 'newsletter'),
            'koren' => $this->app->request->basePath() . '/',
            'ulozene' => $ctenar === null ? [] : $this->app->db()->all('SELECT c.idc, c.titulek, c.seo_link, c.jazyk, c.datum FROM {ctenari_ulozene} u JOIN {clanky} c ON c.idc = u.idc WHERE u.idct = ? AND c.visible = 1 ORDER BY u.cas DESC LIMIT 100', [$ctenar['idct']]),
            'url' => $this->app->url(...),
        ])];
    }

    private function registrace(): Response
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $email = mb_strtolower(trim(mb_substr($r->post('email'), 0, 190)));
        if (!$this->app->settings()->bool('ctenari_registrace')) {
            return $this->na('zavreno');
        }
        if (($chyba = $this->overFormular()) !== null) {
            return $chyba;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->na('udaje');
        }
        $web = $this->app->settings();
        $existujici = $db->one('SELECT * FROM {ctenari} WHERE email = ?', [$email]);
        if ($existujici !== null && $existujici['potvrzen']) {
            // odpověď webu nesmí prozradit, že e-mail už účet má - majitel se to dozví e-mailem
            Posta::odesli($web, $email, t('Účet už máte') . ' – ' . $web->get('nazev_webu'),
                t('Dobrý den,') . "\n\n" . t('na webu %s se někdo pokusil znovu zaregistrovat váš e-mail. Účet už máte – stačí se přihlásit:', $web->get('nazev_webu')) . "\n"
                . $r->origin() . $this->app->url('ctenar') . "\n\n" . t('Pokud jste zapomněli heslo, na stejné stránce si necháte poslat odkaz pro nové.') . "\n");

            return $this->na('poslano');
        }
        $token = bin2hex(random_bytes(16));
        // heslo si čtenář nastaví až po kliknutí na odkaz z e-mailu: kdo registruje cizí adresu, nezíská k účtu nic
        $data = ['jmeno' => mb_substr(trim($r->post('jmeno')), 0, 80), 'heslo' => '', 'token' => $token, 'token_cas' => date('Y-m-d H:i:s')];
        if ($existujici === null) {
            $db->insert('ctenari', $data + ['email' => $email, 'vytvoren' => date('Y-m-d H:i:s')]);
        } else {
            $db->update('ctenari', $data, ['idct' => $existujici['idct']]);
        }
        if ($r->postBool('newsletter') && \MiroCMS\Core\Rozsireni::je($web, 'newsletter') && $db->value('SELECT ido FROM {odberatele} WHERE email = ?', [$email]) === null) {
            // odběr se potvrdí spolu s účtem - e-mail ověřuje stejný odkaz
            $db->insert('odberatele', ['email' => $email, 'token' => bin2hex(random_bytes(16)), 'prihlasen' => date('Y-m-d H:i:s'), 'jazyk' => \MiroCMS\Core\Jazyk::sloupecWebu()]);
        }
        // e-maily čtenáři odcházejí na jeho vlastní žádost, takže jazyk zobrazené verze webu je i jazyk příjemce
        Posta::odesli($web, $email, t('Potvrďte registraci') . ' – ' . $web->get('nazev_webu'),
            t('Dobrý den,') . "\n\n" . t('registraci na webu %s dokončíte nastavením hesla na této adrese (odkaz platí 3 dny):', $web->get('nazev_webu')) . "\n"
            . $r->origin() . $this->app->url('ctenar/heslo/' . $token) . "\n\n" . t('Pokud jste se neregistrovali, e-mail ignorujte.') . "\n");

        return $this->na('poslano');
    }

    private function prihlaseni(): Response
    {
        $r = $this->app->request;
        if (($chyba = $this->overFormular()) !== null) {
            return $chyba;
        }
        $email = mb_strtolower(trim($r->post('email')));
        // limit na účet, ne jen na IP adresu: 10 chybných hesel za 15 minut. Počítá se podle e-mailu bez ohledu na to,
        // zda účet existuje - hláška tak neprozradí, které e-maily jsou registrované. Přihlášení odkazem z e-mailu funguje dál.
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        if ($antispam->pocet('ctenar:' . $email, 'ctenar-heslo', 0, 15) >= 10) {
            return $this->na('zamceno');
        }
        $ctenar = $this->app->db()->one('SELECT * FROM {ctenari} WHERE email = ? AND potvrzen = 1', [$email]);
        // hash se ověřuje i pro neexistující účet, aby doba odpovědi neprozradila, které e-maily jsou registrované
        $hash = $ctenar['heslo'] ?? '$2y$12$.rGL5BCVuy.khmu9ByuEeuXls/1.SsuwX7g78BdiBRuq./37G0q1q';
        if (!password_verify($r->post('heslo'), $hash) || $ctenar === null) {
            $antispam->zapis('ctenar:' . $email, 'ctenar-heslo', 0);

            return $this->na('spatne');
        }
        $this->prihlas((int) $ctenar['idct']);
        $zpet = $this->zpet($r->post('zpet'));

        return Response::redirect($zpet !== '' ? $this->app->url($zpet) : $this->app->url('ctenar'), 303);
    }

    /** Odkaz e-mailem: nové heslo (platí 2 hodiny), nebo jednorázové přihlášení bez hesla (20 minut). */
    private function zapomenute(bool $prihlaseni = false): Response
    {
        $r = $this->app->request;
        if (($chyba = $this->overFormular()) !== null) {
            return $chyba;
        }
        $ctenar = $this->app->db()->one('SELECT * FROM {ctenari} WHERE email = ? AND potvrzen = 1', [mb_strtolower(trim($r->post('email')))]);
        if ($ctenar !== null) {
            $token = bin2hex(random_bytes(16));
            $this->app->db()->update('ctenari', ['token' => $token, 'token_cas' => date('Y-m-d H:i:s')], ['idct' => $ctenar['idct']]);
            $web = $this->app->settings();
            if ($prihlaseni) {
                Posta::odesli($web, $ctenar['email'], t('Přihlášení') . ' – ' . $web->get('nazev_webu'),
                    t('Dobrý den,') . "\n\n" . t('na web %s se přihlásíte tímto odkazem (platí 20 minut a jde použít jednou):', $web->get('nazev_webu')) . "\n"
                    . $r->origin() . $this->app->url('ctenar/vstup/' . $token) . "\n\n" . t('Pokud jste o přihlášení nežádali, e-mail ignorujte.') . "\n");

                return $this->na('odkaz-poslan');
            }
            Posta::odesli($web, $ctenar['email'], t('Nové heslo') . ' – ' . $web->get('nazev_webu'),
                t('Dobrý den,') . "\n\n" . t('nové heslo k účtu na webu %s si nastavíte tady (odkaz platí 2 hodiny):', $web->get('nazev_webu')) . "\n"
                . $r->origin() . $this->app->url('ctenar/heslo/' . $token) . "\n\n" . t('Pokud jste o nové heslo nežádali, e-mail ignorujte.') . "\n");
        }

        return $this->na($prihlaseni ? 'odkaz-poslan' : 'heslo-poslano');
    }

    /**
     * Přihlášení odkazem z e-mailu. Odkaz jen ukáže tlačítko - přihlásí až jeho odeslání, aby jednorázový odkaz
     * nespotřeboval poštovní program, který si adresy z e-mailů otevírá předem.
     *
     * @return Response|array{0:string, 1:string}
     */
    private function vstupOdkazem(string $token, View $view): Response|array
    {
        $db = $this->app->db();
        $ctenar = $db->one('SELECT * FROM {ctenari} WHERE token = ? AND potvrzen = 1 AND token_cas > NOW() - INTERVAL 20 MINUTE', [$token]);
        if ($ctenar === null) {
            return $this->zprava($view, t('Odkaz už neplatí'), t('Přihlašovací odkaz platí 20 minut a jde použít jen jednou. Nechte si prosím poslat nový.'));
        }
        if ($this->app->request->isPost()) {
            $db->update('ctenari', ['token' => bin2hex(random_bytes(16)), 'token_cas' => null], ['idct' => $ctenar['idct']]);
            $this->prihlas((int) $ctenar['idct']);

            return Response::redirect($this->app->url('ctenar'), 303);
        }

        return [t('Přihlášení čtenáře'), $view->render('ctenar_vstup', ['akce' => $this->app->url('ctenar/vstup/' . $token), 'email' => $ctenar['email']])];
    }

    /** @return Response|array{0:string, 1:string} */
    private function noveHeslo(string $token, View $view): Response|array
    {
        $db = $this->app->db();
        $ctenar = $db->one('SELECT * FROM {ctenari} WHERE token = ? AND ((potvrzen = 1 AND token_cas > NOW() - INTERVAL 2 HOUR) OR (potvrzen = 0 AND token_cas > NOW() - INTERVAL 3 DAY))', [$token]);
        if ($ctenar === null) {
            return $this->zprava($view, t('Odkaz už neplatí'), t('Nechte si prosím poslat nový odkaz ze stránky přihlášení.'));
        }
        $r = $this->app->request;
        if ($r->isPost() && mb_strlen($r->post('heslo')) >= 8) {
            $db->update('ctenari', ['heslo' => password_hash($r->post('heslo'), PASSWORD_DEFAULT), 'token' => bin2hex(random_bytes(16)), 'token_cas' => null, 'potvrzen' => 1], ['idct' => $ctenar['idct']]);
            if (!$ctenar['potvrzen']) {
                $db->run('UPDATE {odberatele} SET potvrzen = 1 WHERE email = ?', [$ctenar['email']]); // o odběr požádal při registraci
            }
            $this->prihlas((int) $ctenar['idct']);

            return Response::redirect($this->app->url('ctenar') . '?stav=' . ($ctenar['potvrzen'] ? 'heslo-zmeneno' : 'vitejte'), 303);
        }

        return [t('Nové heslo'), $view->render('ctenar_heslo', ['akce' => $this->app->url('ctenar/heslo/' . $token), 'chyba' => $r->isPost()])];
    }

    /** Má čtenář ve Stripe předplatné, ze kterého se (ještě) platí? */
    public static function beziStripe(array $ctenar): bool
    {
        return (string) $ctenar['stripe_predplatne'] !== '' && in_array($ctenar['predplatne_stav'], ['aktivni', 'konci', 'nezaplaceno'], true);
    }

    /**
     * Přihlášený čtenář odchází na stránky Stripe: zaplatit předplatné (plan = mesic | rok), nebo ho spravovat (plan = sprava).
     * Odsud se nic nezapisuje - předplatné zapne až ověřený webhook (Front\Platby).
     */
    private function predplatne(): Response
    {
        $r = $this->app->request;
        $web = $this->app->settings();
        $ctenar = $this->overPrihlaseneho();
        if ($ctenar === null || !Stripe::nastaveno($web)) {
            return $this->na('');
        }
        // odchozí volání na cizí službu: nejvýš 10 za čtvrt hodiny z jedné adresy
        $antispam = new Antispam($this->app->db(), $web);
        if ($antispam->pocet($r->ip(), 'ctenar-platba', 0, 15) >= 10) {
            return $this->na('pomalu');
        }
        $antispam->zapis($r->ip(), 'ctenar-platba', 0);
        $zpet = $this->zpet($r->post('zpet'));
        $navrat = $r->origin() . $this->app->url('ctenar') . ($zpet !== '' ? '?zpet=' . rawurlencode($zpet) : '');
        $jazyk = \MiroCMS\Core\Jazyk::kod();
        try {
            $stripe = new Stripe($web);
            if ($r->post('plan') === 'sprava') {
                $cil = (string) $ctenar['stripe_zakaznik'] === '' ? null : $stripe->portal((string) $ctenar['stripe_zakaznik'], $navrat . ($zpet !== '' ? '&' : '?') . 'stav=sprava', $jazyk);
            } else {
                // kdo už přes Stripe platí, nesmí si založit druhé předplatné vedle prvního
                $cena = self::beziStripe($ctenar) ? null : (Stripe::ceny($web)[$r->post('plan')] ?? null);
                $cil = $cena === null ? null : $stripe->checkout($cena, $ctenar, $navrat, $jazyk);
            }
        } catch (\RuntimeException $e) {
            Stripe::zaloguj($e->getMessage());

            return $this->na('platba-chyba');
        }

        return $cil === null ? $this->na('') : Response::redirect($cil, 303);
    }

    /**
     * Po návratu ze správy předplatného se stav načte rovnou ze Stripe - webhook se změnou může dorazit až za chvíli.
     * Číslo předplatného je z naší databáze, ne z prohlížeče; když Stripe neodpoví, zůstane stav, jaký je.
     *
     * @param array<string, mixed> $ctenar
     * @return array<string, mixed>
     */
    private function srovnejPredplatne(array $ctenar): array
    {
        $web = $this->app->settings();
        $antispam = new Antispam($this->app->db(), $web);
        if (!self::beziStripe($ctenar) || !Stripe::nastaveno($web) || $antispam->pocet($this->app->request->ip(), 'ctenar-platba', 0, 15) >= 10) {
            return $ctenar;
        }
        $antispam->zapis($this->app->request->ip(), 'ctenar-platba', 0);
        try {
            (new Platby($this->app->db(), $web))->zapisStav($ctenar, (new Stripe($web))->predplatne((string) $ctenar['stripe_predplatne']));
        } catch (\RuntimeException $e) {
            Stripe::zaloguj($e->getMessage());
        }

        return $this->app->db()->one('SELECT * FROM {ctenari} WHERE idct = ?', [$ctenar['idct']]) ?? $ctenar;
    }

    private function ulozUcet(): Response
    {
        $r = $this->app->request;
        $ctenar = $this->overPrihlaseneho();
        if ($ctenar === null) {
            return $this->na('');
        }
        $data = ['jmeno' => mb_substr(trim($r->post('jmeno')), 0, 80)];
        if ($r->post('heslo') !== '') {
            if (mb_strlen($r->post('heslo')) < 8 || !password_verify($r->post('heslo_stare'), $ctenar['heslo'])) {
                return $this->na('heslo-chyba');
            }
            $data['heslo'] = password_hash($r->post('heslo'), PASSWORD_DEFAULT);
        }
        $this->app->db()->update('ctenari', $data, ['idct' => $ctenar['idct']]);
        if (isset($data['heslo'])) {
            $this->prihlas((int) $ctenar['idct']);
        }

        return $this->na('ulozeno');
    }

    /** Tlačítko "Uložit na později" pod článkem. Nepřihlášenému vede na přihlášení (stránka článku bývá z cache, společná pro všechny). */
    public function ulozitHtml(array $clanek): string
    {
        $ctenar = $this->prihlaseny();
        if ($ctenar === null) {
            return '<p class="mc-ulozit"><a href="' . e($this->app->url('ctenar') . '?zpet=' . rawurlencode('clanek/' . $clanek['seo_link'])) . '">☆ ' . e(t('Uložit na později')) . '</a></p>';
        }
        $ulozeno = $this->app->db()->value('SELECT 1 FROM {ctenari_ulozene} WHERE idct = ? AND idc = ?', [$ctenar['idct'], $clanek['idc']]) !== null;

        return '<form class="mc-ulozit" method="post" action="' . e($this->app->url('ctenar')) . '"><input type="hidden" name="akce" value="ulozit"><input type="hidden" name="idc" value="' . (int) $clanek['idc'] . '">'
            . '<input type="hidden" name="podpis" value="' . e($this->podpis('formular.' . $ctenar['idct'])) . '"><button type="submit">' . ($ulozeno ? '★ ' . e(t('Uloženo – odebrat')) : '☆ ' . e(t('Uložit na později'))) . '</button>'
            . ($ulozeno ? ' <a href="' . e($this->app->url('ctenar')) . '">' . e(t('Moje uložené články')) . '</a>' : '') . '</form>';
    }

    /** Uloží článek, nebo ho z uložených odebere (přepínač). */
    private function ulozClanek(): Response
    {
        $ctenar = $this->overPrihlaseneho();
        $db = $this->app->db();
        $clanek = $db->one('SELECT idc, seo_link, jazyk FROM {clanky} WHERE idc = ? AND visible = 1 AND datum <= NOW()', [$this->app->request->postInt('idc')]);
        if ($ctenar === null || $clanek === null) {
            return $this->na('');
        }
        if ($db->delete('ctenari_ulozene', ['idct' => $ctenar['idct'], 'idc' => $clanek['idc']]) === 0
            && (int) $db->value('SELECT COUNT(*) FROM {ctenari_ulozene} WHERE idct = ?', [$ctenar['idct']]) < 500) {
            $db->insert('ctenari_ulozene', ['idct' => $ctenar['idct'], 'idc' => $clanek['idc'], 'cas' => date('Y-m-d H:i:s')]);
        }
        $zpet = $this->app->request->post('z_uctu') === '1' ? 'ctenar' : ($clanek['jazyk'] !== '' ? $clanek['jazyk'] . '/' : '') . 'clanek/' . $clanek['seo_link'];

        return Response::redirect($this->app->request->basePath() . '/' . $zpet, 303);
    }

    private function smazUcet(): Response
    {
        $ctenar = $this->overPrihlaseneho();
        if ($ctenar === null || !password_verify($this->app->request->post('heslo'), $ctenar['heslo'])) {
            return $this->na('heslo-chyba');
        }
        if (in_array($ctenar['predplatne_stav'], ['aktivni', 'nezaplaceno'], true) && (string) $ctenar['stripe_predplatne'] !== '') {
            return $this->na('nejdriv-zrusit'); // jinak by Stripe strhával platby za účet, který už neexistuje
        }
        $this->app->db()->delete('ctenari', ['idct' => $ctenar['idct']]);
        $this->cookie('', 1);

        return $this->na('smazano');
    }

    private function odhlas(): Response
    {
        $this->cookie('', 1);

        return Response::redirect($this->app->url(''), 303);
    }

    /** Formuláře přihlášeného čtenáře chrání podpis vázaný na jeho účet (náhrada CSRF tokenu bez session). */
    private function overPrihlaseneho(): ?array
    {
        $ctenar = $this->prihlaseny();

        return $ctenar !== null && hash_equals($this->podpis('formular.' . $ctenar['idct']), $this->app->request->post('podpis')) ? $ctenar : null;
    }

    private function overFormular(): ?Response
    {
        $r = $this->app->request;
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $duvod = $antispam->over($r, 'ctenar');
        if ($duvod !== null || $antispam->pocet($r->ip(), 'ctenar', 0, 15) >= 10) {
            return $this->na($duvod === 'robot' ? 'poslano' : 'pomalu');
        }
        $antispam->zapis($r->ip(), 'ctenar', 0);

        return null;
    }

    private function prihlas(int $id): void
    {
        $platnost = time() + self::PLATNOST;
        $heslo = (string) $this->app->db()->value('SELECT heslo FROM {ctenari} WHERE idct = ?', [$id]);
        $this->cookie($id . '.' . $platnost . '.' . $this->podpis($id . '.' . $platnost . '.' . $heslo), $platnost);
        $this->app->db()->update('ctenari', ['naposledy' => date('Y-m-d H:i:s')], ['idct' => $id]);
    }

    private function cookie(string $hodnota, int $platnost): void
    {
        setcookie(self::COOKIE, $hodnota, [
            'expires' => $platnost,
            'path' => $this->app->request->basePath() . '/',
            'secure' => $this->app->request->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function podpis(string $data): string
    {
        return hash_hmac('sha256', 'ctenar|' . $data, (new Antispam($this->app->db(), $this->app->settings()))->klic());
    }

    /** Návrat po přihlášení jen na vlastní web. */
    private function zpet(string $cesta): string
    {
        return preg_match('#^[a-z0-9][a-z0-9/_-]*$#i', $cesta) ? $cesta : '';
    }

    private function na(string $stav): Response
    {
        $zpet = $this->zpet($this->app->request->post('zpet'));

        return Response::redirect($this->app->url('ctenar') . '?' . http_build_query(array_filter(['stav' => $stav, 'zpet' => $zpet])), 303);
    }

    /** @return array{0:string, 1:string} */
    private function zprava(View $view, string $nadpis, string $text): array
    {
        return [$nadpis, $view->render('zprava', ['nadpis' => $nadpis, 'text' => $text, 'url' => $this->app->url(...)])];
    }
}
