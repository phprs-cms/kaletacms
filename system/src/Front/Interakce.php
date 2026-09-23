<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\Antispam;
use MiroCMS\Core\App;
use MiroCMS\Core\Response;
use MiroCMS\Core\Rozsireni;
use MiroCMS\Core\View;

/**
 * Zapojení čtenářů: komentáře, hodnocení článků hvězdičkami a ankety.
 * Všechny formuláře chrání Core\Antispam; opakované hlasování hlídá otisk IP adresy a cookie.
 */
final class Interakce
{
    private readonly Antispam $antispam;

    public function __construct(private readonly App $app, private readonly View $view)
    {
        $this->antispam = new Antispam($app->db(), $app->settings());
    }

    /* ---------- komentáře ---------- */

    /** @param array<string, mixed> $clanek */
    public function komentareHtml(array $clanek): string
    {
        if (!Rozsireni::je($this->app->settings(), 'komentare') || !$this->app->settings()->bool('povolit_komentare') || !$clanek['povolit_kom']) {
            return '';
        }
        $vse = $this->app->db()->all('SELECT * FROM {komentare} WHERE clanek = ? AND zobrazit = 1 ORDER BY datum, idk', [$clanek['idc']]);
        $koreny = [];
        $reakce = [];
        foreach ($vse as $k) {
            if ($k['reakce_na'] === null) {
                $koreny[] = $k;
            } else {
                $reakce[(int) $k['reakce_na']][] = $k;
            }
        }

        $ctenar = $this->ctenar();

        return $this->view->render('komentare', [
            'ctenar' => $ctenar,
            'jenPrihlaseni' => $this->jenPrihlaseni() && $ctenar === null,
            'prihlaseni' => $this->app->url('ctenar') . '?zpet=' . rawurlencode('clanek/' . $clanek['seo_link']),
            'nahlasit' => $this->app->url('komentar/nahlasit'),
            'clanek' => $clanek, 'koreny' => $koreny, 'reakce' => $reakce, 'pocet' => count($vse),
            'akce' => $this->app->url('komentar'), 'pole' => $this->antispam->pole('komentar-' . $clanek['idc']),
            'zprava' => ['ok' => t('Děkujeme, komentář byl přidán.'), 'ceka' => t('Děkujeme. Komentář se zobrazí po schválení redakcí.'), 'nahlaseno' => t('Děkujeme, komentář jsme předali redakci k posouzení.')][$this->app->request->get('komentar')] ?? '',
            'chyba' => $this->app->request->get('komentar') === 'chyba' ? (string) $this->app->session->get('komentar_chyba', t('Komentář se nepodařilo uložit.')) : '',
        ]);
    }

    public function ulozKomentar(): Response
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $clanek = $db->one('SELECT idc, titulek, seo_link, povolit_kom FROM {clanky} WHERE idc = ? AND visible = 1 AND datum <= NOW()', [$r->postInt('idc')]);
        if ($clanek === null || !$clanek['povolit_kom'] || !$this->app->settings()->bool('povolit_komentare')) {
            return Response::redirect($this->app->url(''));
        }
        $zpet = fn (string $stav): Response => Response::redirect($this->app->url('clanek/' . $clanek['seo_link'] . '?komentar=' . $stav . '#komentare'), 303);
        // text chyby se překládá tady (jazyk verze webu, ze které formulář přišel); šablona ho už jen vypíše
        $chyba = function (string $text) use ($zpet): Response {
            $this->app->session->set('komentar_chyba', $text);

            return $zpet('chyba');
        };

        $duvod = $this->antispam->over($r, 'komentar-' . $clanek['idc']);
        if ($duvod === 'robot') {
            return $zpet('ok'); // robot se nedozví, že neuspěl
        }
        if ($duvod !== null) {
            return $chyba($duvod);
        }
        $ctenar = $this->ctenar();
        if ($ctenar === null && $this->jenPrihlaseni()) {
            return $chyba(t('Komentovat mohou jen přihlášení čtenáři.'));
        }
        // přihlášený čtenář komentuje pod svým účtem: jméno a e-mail se berou z účtu, ne z formuláře
        $od = $ctenar !== null ? ($ctenar['jmeno'] !== '' ? $ctenar['jmeno'] : ucfirst((string) strstr($ctenar['email'], '@', true))) : mb_substr($r->post('od'), 0, 60);
        $obsah = mb_substr($r->post('obsah'), 0, 5000);
        $mail = $ctenar !== null ? $ctenar['email'] : mb_substr($r->post('od_mail'), 0, 190);
        if ($od === '' || mb_strlen($obsah) < 3) {
            return $chyba(t('Vyplňte jméno a text komentáře.'));
        }
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            return $chyba(t('E-mail nemá platný tvar.'));
        }
        if ($this->antispam->pocet($r->ip(), 'komentar', 0, 10) >= 5) {
            return $chyba(t('Příliš mnoho komentářů za krátkou dobu. Zkuste to prosím později.'));
        }
        $reakceNa = $db->value('SELECT idk FROM {komentare} WHERE idk = ? AND clanek = ? AND reakce_na IS NULL', [$r->postInt('reakce_na'), $clanek['idc']]);

        // podezřelý komentář (odkazy) čeká na schválení i v režimu "hned"
        $podezrely = preg_match_all('#https?://|www\.#i', $obsah) >= 2;
        $zobrazit = $this->app->settings()->get('komentare_rezim') === 'hned' && !$podezrely;
        $db->insert('komentare', [
            'clanek' => $clanek['idc'], 'reakce_na' => $reakceNa === null ? null : (int) $reakceNa, 'datum' => date('Y-m-d H:i:s'),
            'obsah' => $obsah, 'od' => $od, 'od_mail' => $mail, 'od_ip' => \MiroCMS\Core\Antispam::otisk($r->ip()), // otisk, ne adresa: moderátor pozná téhož pisatele, ale IP se neukládá 'zobrazit' => (int) $zobrazit,
            'idct' => $ctenar['idct'] ?? null, 'upozornit' => (int) ($mail !== '' && $r->postBool('upozornit')),
        ]);
        $idk = (int) $db->value('SELECT LAST_INSERT_ID()');
        if ($zobrazit) {
            self::upozorniNaOdpoved($this->app, $idk);
        }
        $this->antispam->zapis($r->ip(), 'komentar', 0);
        self::prepocitej($db, (int) $clanek['idc']);
        Cache::vymaz(); // až po skutečném zápisu - odmítnutý spam nesmí držet cache studenou
        $this->upozorniRedakci($clanek, $od, $obsah, $zobrazit);

        return $zpet($zobrazit ? 'ok' : 'ceka');
    }

    /** Počet zveřejněných komentářů článku (rs_clanky.kom). */
    /**
     * E-mail redakci o novém komentáři. Nejvýš jeden za 10 minut - při náporu (nebo spamu) stačí vědět, že je co schvalovat.
     *
     * @param array<string, mixed> $clanek
     */
    private function upozorniRedakci(array $clanek, string $od, string $obsah, bool $zverejnen): void
    {
        $web = $this->app->settings();
        $rezim = $web->get('upozorneni_komentare');
        if ($web->get('email_webu') === '' || $rezim === 'nic' || ($rezim === 'schvaleni' && $zverejnen) || time() - $web->int('upozorneni_cas') < 600) {
            return;
        }
        $web->set('upozorneni_cas', (string) time());
        $ceka = (int) $this->app->db()->value('SELECT COUNT(*) FROM {komentare} WHERE zobrazit = 0');
        $sprava = $this->app->request->origin() . $this->app->request->basePath() . '/admin.php?modul=comment';
        // píše se redakci, ne čtenáři: texty administrace ve výchozím jazyce webu (adresa redakce nemá vlastní účet s jazykem)
        [$predmet, $text] = \MiroCMS\Core\Jazyk::docasne(\MiroCMS\Core\Jazyk::vychozi($web), fn (): array => [
            t($zverejnen ? 'Nový komentář' : 'Komentář čeká na schválení'),
            t('Článek: %s', (string) $clanek['titulek']) . "\n" . t('Od: %s', $od) . "\n\n" . mb_strimwidth($obsah, 0, 600, '…') . "\n\n"
                . ($ceka > 0 ? t('Ke schválení čeká komentářů: %s', $ceka) . "\n" : '')
                . t('Správa komentářů: %s', $sprava) . "\n\n"
                . t('Další upozornění přijde nejdřív za 10 minut. Vypnete je v Nastavení → Základní.') . "\n",
        ], 'admin-');
        \MiroCMS\Core\Posta::odesli($web, $web->get('email_webu'), $predmet . ' – ' . $web->get('nazev_webu'), $text);
    }

    private function ctenar(): ?array
    {
        return Rozsireni::je($this->app->settings(), 'ctenari') ? (new Ctenari($this->app))->prihlaseny() : null;
    }

    private function jenPrihlaseni(): bool
    {
        return Rozsireni::je($this->app->settings(), 'ctenari') && $this->app->settings()->bool('komentare_jen_prihlaseni');
    }

    /** E-mail autorovi původního komentáře, že mu někdo odpověděl (jen když o to stál). Volá se po zveřejnění odpovědi. */
    public static function upozorniNaOdpoved(App $app, int $idk): void
    {
        $db = $app->db();
        $o = $db->one('SELECT k.od, k.od_mail, k.obsah, k.reakce_na, c.titulek, c.seo_link, c.jazyk FROM {komentare} k JOIN {clanky} c ON c.idc = k.clanek WHERE k.idk = ? AND k.zobrazit = 1', [$idk]);
        $puvodni = $o === null || $o['reakce_na'] === null ? null : $db->one("SELECT idk, od, od_mail FROM {komentare} WHERE idk = ? AND upozornit = 1 AND od_mail <> ''", [$o['reakce_na']]);
        if ($puvodni === null || mb_strtolower($puvodni['od_mail']) === mb_strtolower($o['od_mail'])) {
            return;
        }
        $web = $app->settings();
        $koren = $app->request->origin() . $app->request->basePath() . '/' . ($o['jazyk'] !== '' ? $o['jazyk'] . '/' : ''); // adresy v jazykové verzi článku
        // jazyk příjemce = jazyková verze článku, pod kterým komentoval; odpověď může schválit redaktor z administrace v jiném jazyce
        [$predmet, $text] = \MiroCMS\Core\Jazyk::docasne($o['jazyk'] !== '' ? $o['jazyk'] : \MiroCMS\Core\Jazyk::vychozi($web), fn (): array => [
            t('Odpověď na váš komentář'),
            t('Dobrý den,') . "\n\n" . t('na váš komentář u článku „%s“ odpověděl(a) %s:', (string) $o['titulek'], (string) $o['od']) . "\n\n" . mb_strimwidth($o['obsah'], 0, 600, '…') . "\n\n"
                . t('Celá diskuse: %s', $koren . 'clanek/' . $o['seo_link'] . '#komentar-' . $idk) . "\n\n"
                . t('Další upozornění k tomuto komentáři vypnete zde: %s', $koren . 'komentar/neupozornovat?k=' . $puvodni['idk'] . '&p=' . self::podpis($app, (int) $puvodni['idk'])) . "\n",
        ]);
        \MiroCMS\Core\Posta::odesli($web, $puvodni['od_mail'], $predmet . ' – ' . $web->get('nazev_webu'), $text);
    }

    /** Čtenář nahlásil komentář. Po třech nahlášeních komentář počká na posouzení redakcí. */
    public function nahlas(): Response
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $k = $db->one('SELECT k.idk, k.clanek, k.nahlaseno, c.seo_link FROM {komentare} k JOIN {clanky} c ON c.idc = k.clanek WHERE k.idk = ? AND k.zobrazit = 1', [$r->postInt('idk')]);
        if ($k === null) {
            return Response::redirect($this->app->url(''), 303);
        }
        $zpet = Response::redirect($this->app->url('clanek/' . $k['seo_link'] . '?komentar=nahlaseno#komentare'), 303);
        if ($this->antispam->pocet($r->ip(), 'nahlaseni', (int) $k['idk'], 1440) > 0 || $this->antispam->pocet($r->ip(), 'nahlaseni-vse', 0, 60) >= 10) {
            return $zpet; // jedna adresa = jedno nahlášení komentáře; hromadné nahlašování se nepočítá
        }
        $this->antispam->zapis($r->ip(), 'nahlaseni', (int) $k['idk']);
        $this->antispam->zapis($r->ip(), 'nahlaseni-vse', 0);
        $db->run('UPDATE {komentare} SET nahlaseno = nahlaseno + 1, zobrazit = IF(nahlaseno >= 3, 0, zobrazit) WHERE idk = ?', [$k['idk']]);
        if ((int) $k['nahlaseno'] + 1 >= 3) {
            self::prepocitej($db, (int) $k['clanek']);
            Cache::vymaz();
        }

        return $zpet;
    }

    /** Vypnutí upozornění na odpovědi odkazem z e-mailu. */
    public function neupozornovat(): bool
    {
        $idk = $this->app->request->getInt('k');
        if (!hash_equals(self::podpis($this->app, $idk), $this->app->request->get('p'))) {
            return false;
        }
        $this->app->db()->update('komentare', ['upozornit' => 0], ['idk' => $idk]);

        return true;
    }

    private static function podpis(App $app, int $idk): string
    {
        return substr(hash_hmac('sha256', 'komentar-upozorneni|' . $idk, (new Antispam($app->db(), $app->settings()))->klic()), 0, 24);
    }

    public static function prepocitej(\MiroCMS\Core\Db $db, int $idc): void
    {
        $db->run('UPDATE {clanky} SET kom = (SELECT COUNT(*) FROM {komentare} WHERE clanek = ? AND zobrazit = 1) WHERE idc = ?', [$idc, $idc]);
    }

    /* ---------- hodnocení ---------- */

    /** @param array<string, mixed> $clanek */
    public function hodnoceniHtml(array $clanek): string
    {
        if (!Rozsireni::je($this->app->settings(), 'komentare') || !$this->app->settings()->bool('povolit_hodnoceni')) {
            return '';
        }

        return $this->view->render('hodnoceni', [
            'clanek' => $clanek, 'akce' => $this->app->url('hodnoceni'),
            'prumer' => $clanek['mn_hodnoceni'] > 0 ? $clanek['hodnoceni'] / $clanek['mn_hodnoceni'] : 0.0,
            'hlasoval' => isset($_COOKIE['mirocms_h' . $clanek['idc']]) || $this->app->request->get('hodnoceni') !== '',
            'pole' => $this->antispam->pole('hodnoceni-' . $clanek['idc']),
        ]);
    }

    public function ulozHodnoceni(): Response
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $clanek = $db->one('SELECT idc, seo_link FROM {clanky} WHERE idc = ? AND visible = 1 AND datum <= NOW()', [$r->postInt('idc')]);
        $znamka = $r->postInt('znamka');
        if ($clanek === null) {
            return Response::redirect($this->app->url(''));
        }
        $idc = (int) $clanek['idc'];
        $smi = $this->app->settings()->bool('povolit_hodnoceni') && $znamka >= 1 && $znamka <= 5
            && $this->antispam->over($r, 'hodnoceni-' . $idc) === null
            && !isset($_COOKIE['mirocms_h' . $idc]) && $this->antispam->pocet($r->ip(), 'hodnoceni', $idc, 60 * 24 * 30) === 0;
        if ($smi) {
            $db->run('UPDATE {clanky} SET hodnoceni = hodnoceni + ?, mn_hodnoceni = mn_hodnoceni + 1 WHERE idc = ?', [$znamka, $idc]);
            Cache::vymaz();
            $this->antispam->zapis($r->ip(), 'hodnoceni', $idc);
        }
        $odpoved = Response::redirect($this->app->url('clanek/' . $clanek['seo_link'] . '?hodnoceni=' . ($smi ? 'ok' : 'uz') . '#hodnoceni'), 303);
        setcookie('mirocms_h' . $idc, '1', ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);

        return $odpoved;
    }

    /* ---------- ankety ---------- */

    /** Systémový blok Anketa: aktivní anketa z Nastavení / modulu Ankety. */
    public function anketaHtml(): string
    {
        $db = $this->app->db();
        // aktivní anketa platí pro svůj jazyk; v ostatních jazykových verzích se ukáže nejnovější otevřená anketa daného jazyka
        $jazyk = \MiroCMS\Core\Jazyk::sloupecWebu();
        $anketa = $db->one('SELECT * FROM {ankety} WHERE ida = ? AND zobrazit = 1 AND jazyk = ?', [$this->app->settings()->int('aktivni_anketa'), $jazyk])
            ?? $db->one('SELECT * FROM {ankety} WHERE zobrazit = 1 AND uzavrena = 0 AND jazyk = ? ORDER BY ida DESC LIMIT 1', [$jazyk]);
        if ($anketa === null) {
            return '';
        }
        $odpovedi = $db->all('SELECT * FROM {odpovedi} WHERE anketa = ? ORDER BY poradi, ido', [$anketa['ida']]);

        return $this->view->render('blok_ank', [
            'anketa' => $anketa, 'odpovedi' => $odpovedi, 'celkem' => (int) array_sum(array_column($odpovedi, 'pocitadlo')),
            'hlasoval' => $anketa['uzavrena'] || isset($_COOKIE['mirocms_a' . $anketa['ida']]),
            'akce' => $this->app->url('anketa'), 'pole' => $this->antispam->pole('anketa-' . $anketa['ida']),
            'zpet' => $this->app->request->path(),
        ]);
    }

    public function ulozHlas(): Response
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $ida = $r->postInt('ida');
        $zpet = preg_match('#^/[a-z0-9/_-]*$#i', $r->post('zpet')) ? ltrim($r->post('zpet'), '/') : '';
        $odpoved = $db->one('SELECT o.ido FROM {odpovedi} o JOIN {ankety} a ON a.ida = o.anketa WHERE o.ido = ? AND a.ida = ? AND a.zobrazit = 1 AND a.uzavrena = 0', [$r->postInt('ido'), $ida]);
        if ($odpoved !== null && $this->antispam->over($r, 'anketa-' . $ida) === null
            && !isset($_COOKIE['mirocms_a' . $ida]) && $this->antispam->pocet($r->ip(), 'anketa', $ida, 60 * 24 * 30) === 0) {
            $db->run('UPDATE {odpovedi} SET pocitadlo = pocitadlo + 1 WHERE ido = ?', [$odpoved['ido']]);
            Cache::vymaz();
            $this->antispam->zapis($r->ip(), 'anketa', $ida);
        }
        setcookie('mirocms_a' . $ida, '1', ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);

        return Response::redirect($this->app->url($zpet), 303);
    }
}
