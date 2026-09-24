<?php

declare(strict_types=1);

namespace MiroCMS\Admin;

use MiroCMS\Core\App;
use MiroCMS\Core\Migrace;
use MiroCMS\Core\Response;
use MiroCMS\Core\Rozsireni;

/**
 * Administrace. Adresy: admin.php?modul=<ident>&akce=<akce>
 */
final class Kernel
{
    /**
     * Moduly v pořadí, v jakém jsou v menu (po skupinách Obsah, Vzhled, Správa).
     *
     * @var list<class-string<Modul>>
     */
    public const array MODULY = [
        Moduly\Stranky::class,
        Moduly\Novinky::class,
        Moduly\Kategorie::class,
        Moduly\Stitky::class,
        Moduly\Galerie::class,
        Moduly\Statistika::class,
        Moduly\Vzhled::class,
        Moduly\Autori::class,
        Moduly\Presmerovani::class,
        Moduly\ProtokolZmen::class,
        Moduly\Prenos::class,
        Moduly\RozsireniAdmin::class,
        Moduly\Konfigurace::class,
    ];

    public function __construct(public readonly App $app)
    {
    }

    public function handle(): Response
    {
        $app = $this->app;
        $request = $app->request;

        // jazyk administrace: volba uživatele (Můj účet); přihlašovací stránka se řídí jazykem webu. Nastavuje se jako první, aby i hláška o vypršelém formuláři byla přeložená
        $jazyk = (string) ($app->auth()->user()['jazyk'] ?? '') ?: \MiroCMS\Core\Jazyk::vychozi($app->settings());
        \MiroCMS\Core\Jazyk::nastav(isset(\MiroCMS\Core\Jazyk::ADMINISTRACE[$jazyk]) ? $jazyk : 'cs', 'admin-');
        if ($request->isPost() && !$app->session->csrfValid($request)) {
            return $this->page('Neplatný požadavek', $app->view->render('admin/chyba', [
                'text' => 'Platnost formuláře vypršela. Vraťte se zpět, obnovte stránku a odešlete jej znovu.',
            ]), 400);
        }

        // každá změna v administraci zneplatní cache stránek webu; průběžné požadavky editoru (rozepsaný stav, asistent)
        // web nemění - kdyby cache mazaly, při psaní by byla pořád studená
        if ($request->isPost() && !in_array($request->get('akce'), ['koncept', 'asistent'], true)) {
            \MiroCMS\Front\Cache::vymaz();
        }
        $akce = $request->get('akce');
        // adresa webu: starší instalace ji ještě nemá - zapíše se podle adresy, na které pracuje přihlášený administrátor
        if ($app->settings()->get('adresa_webu') === '' && $app->auth()->isAdmin()) {
            $app->settings()->set('adresa_webu', $request->origin());
        }
        $request->setOrigin($app->settings()->get('adresa_webu'));
        $app->casovePasmo();
        if ($app->auth()->user() === null) {
            return $akce === 'heslo' ? (new ObnovaHesla($app))->handle() : $this->login();
        }
        if ($akce === 'logout' && $request->isPost()) {
            $app->auth()->logout();

            return Response::redirect($app->url('admin.php'));
        }

        // po přechodu na novou verzi jednorázově uklidit známé zrušené soubory (viz Aktualizace::ZRUSENE)
        if ($app->auth()->isAdmin() && $app->settings()->get('uklizeno_verze') !== MIROCMS_VERSION) {
            \MiroCMS\Core\Aktualizace::uklidZrusene(MIROCMS_ROOT, $app->settings()->get('layout'));
            $app->settings()->set('uklizeno_verze', MIROCMS_VERSION);
        }

        // aktualizace struktury databáze po nahrání nové verze systému
        if ($app->auth()->isAdmin() && $app->settings()->int('verze_db') < Migrace::posledni()) {
            foreach (Migrace::proved($app->db(), $app->settings()) as $migrace) {
                $app->session->flash('info', t('Databáze byla aktualizována: %s', $migrace));
            }
        }

        if ($app->auth()->isAdmin()) {
            \MiroCMS\Core\Zaloha::automaticka($app->db(), $app->settings());
        }
        if ($app->auth()->user() !== null) {
            Moduly\Novinky::vysypKos($app->db()); // koš drží novinky 30 dní
        }

        $ident = $request->get('modul');
        if ($akce === 'ucet') {
            return (new Ucet($this))->handle();
        }
        if ($akce === 'pruvodce_skryt' && $request->isPost() && $app->auth()->isAdmin()) {
            $app->settings()->set('pruvodce_skryt', '1');

            return Response::redirect($app->url('admin.php'));
        }
        if ($ident === '') {
            $nova = $app->auth()->isAdmin() ? (new \MiroCMS\Core\Aktualizace($app->settings()))->stav()['nova'] : null;
            if ($nova !== null) {
                // text se překládá tady (s číslem verze); cestu v nabídce promění v odkaz až vykreslení hlášky (Admin\Cesty)
                $app->session->flash(!empty($nova['bezpecnostni']) ? 'chyba' : 'info', !empty($nova['bezpecnostni'])
                    ? t('Je k dispozici BEZPEČNOSTNÍ aktualizace %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', (string) $nova['verze'])
                    : t('Je k dispozici nová verze %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', (string) $nova['verze']));
            }

            return $this->page('', $app->view->render('admin/desktop', $this->desktop()));
        }
        $class = $this->moduly()[$ident] ?? null;
        if ($class === null) {
            return $this->page('Chyba', $app->view->render('admin/chyba', ['text' => 'K tomuto modulu nemáte přístup.']), 403);
        }

        $odpoved = (new $class($this))->handle($akce === '' ? 'vypis' : $akce);
        if ($request->isPost() && $odpoved->status === 302 && $akce !== 'poradi') {
            // každá provedená změna v administraci jde do protokolu
            $popis = $request->post('titulek') ?: ($request->post('nazev') ?: ($request->post('user') ?: $request->post('zalozka')));
            Protokol::zapis($app, $ident, $akce, $popis);
        }

        return $odpoved;
    }

    /**
     * Moduly dostupné přihlášenému uživateli.
     *
     * @return array<string, class-string<Modul>> ident => třída
     */
    public function moduly(): array
    {
        $auth = $this->app->auth();
        $moduly = [];
        foreach (self::MODULY as $class) {
            if (!Rozsireni::je($this->app->settings(), $class::ROZSIRENI)) {
                continue;
            }
            $povolen = $class::JEN_ADMIN ? $auth->isAdmin() : $auth->maModul($class::IDENT, $class::PRO_VSECHNY);
            if ($povolen) {
                $moduly[$class::IDENT] = $class;
            }
        }

        return $moduly;
    }

    /** Obalí obsah společným rámcem administrace (menu, login proužek, hlášky). */
    public function page(string $nadpis, string $obsah, int $status = 200): Response
    {
        $app = $this->app;

        return Response::html($app->view->render('admin/layout', [
            'app' => $app,
            'nadpis' => t($nadpis),
            'obsah' => $obsah,
            'moduly' => $app->auth()->user() !== null ? $this->moduly() : [],
            'aktivni' => $app->request->get('modul'),
            'user' => $app->auth()->user(),
            'hlasky' => $app->session->takeFlashes(),
        ]), $status);
    }

    /**
     * Data úvodní obrazovky: přehled webu.
     *
     * @return array<string, mixed>
     */
    private function desktop(): array
    {
        $data = ['app' => $this->app, 'moduly' => $this->moduly()];
        $db = $this->app->db();
        $jen = ' AND smazano IS NULL' . $this->app->auth()->articleScope();      // pro dotazy bez aliasu (novinky v koši se nepočítají)
        $jenC = ' AND c.smazano IS NULL' . $this->app->auth()->articleScope('c.');  // pro dotazy s aliasem c

        return $data + [
            'pruvodce' => $this->pruvodce(),
            // návštěvnost za 14 dní (vlastní měření bez cookies)
            'navstevnost' => Rozsireni::je($this->app->settings(), 'statistika') && isset($this->moduly()['stat'])
                ? $db->all('SELECT den, navstevy, zobrazeni FROM {stat_dny} WHERE den > CURDATE() - INTERVAL 14 DAY ORDER BY den') : [],
            'pocty' => [
                'Stránky' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE zobrazit = 1'),
                'Vydané novinky' => (int) $db->value("SELECT COUNT(*) FROM {clanky} WHERE visible = 1 AND datum <= NOW(){$jen}"),
                'Naplánované' => (int) $db->value("SELECT COUNT(*) FROM {clanky} WHERE visible = 1 AND datum > NOW(){$jen}"),
                'Koncepty' => (int) $db->value("SELECT COUNT(*) FROM {clanky} WHERE visible = 0{$jen}"),
            ],
            'posledni' => $db->all(
                "SELECT c.idc, c.titulek, c.datum, c.visible, t.nazev AS tema_jm
                 FROM {clanky} c JOIN {topic} t ON t.idt = c.tema WHERE 1 = 1" . $jenC . "
                 ORDER BY COALESCE(c.zmeneno, c.datum) DESC LIMIT 6",
            ),
        ];
    }

    /**
     * První kroky po instalaci: co už je hotové, se pozná z dat. Vidí je jen administrátor, dokud je neskryje nebo nesplní.
     *
     * @return list<array{nazev:string, popis:string, url:string, hotovo:bool}>
     */
    private function pruvodce(): array
    {
        $app = $this->app;
        $s = $app->settings();
        if (!$app->auth()->isAdmin() || $s->bool('pruvodce_skryt')) {
            return [];
        }
        $db = $app->db();
        $kroky = [
            ['Dejte webu tvář', 'Logo, hlavní barva a písmo.', 'admin.php?modul=vzhled', $s->get('logo_webu') !== '' || $s->get('brand_akcent') !== ''],
            ['Vyplňte údaje o firmě', 'Kontakty a adresa se ukážou v patičce a vyhledávačům.', 'admin.php?modul=config', $s->get('email_webu') !== ''],
            ['Připravte stránky', 'O nás, Služby, Kontakt – a vyberte, která bude úvodní.', 'admin.php?modul=stranky', (int) $db->value('SELECT COUNT(*) FROM {stranky}') >= 3],
            ['Napište první novinku', 'Ukázkovou novinku pak můžete smazat.', 'admin.php?modul=novinky&akce=novy', (int) $db->value("SELECT COUNT(*) FROM {clanky} WHERE seo_link <> 'vitejte-v-mirocms'") >= 1],
            ['Nastavte poštu', 'Odkud web odesílá e-maily (formuláře, obnova hesla).', 'admin.php?modul=config&zalozka=posta', $s->get('posta_rezim') === 'smtp' || $s->get('posta_od') !== ''],
        ];
        $vysledek = array_map(fn (array $k): array => ['nazev' => $k[0], 'popis' => $k[1], 'url' => $app->url($k[2]), 'hotovo' => (bool) $k[3]], $kroky);

        return array_filter($vysledek, fn (array $k): bool => !$k['hotovo']) === [] ? [] : $vysledek;
    }

    private function login(): Response
    {
        $app = $this->app;
        $chyba = null;
        // druhý krok přihlašovacím klíčem (otisk prstu, Face ID): skript image/klice.js si řekne o výzvu a pošle podpis zařízení
        if ($app->request->isPost() && in_array($app->request->post('krok'), ['klic_moznosti', 'klic'], true)) {
            $adresa = $app->settings()->get('adresa_webu') ?: $app->request->origin();
            if ($app->request->post('krok') === 'klic_moznosti') {
                $moznosti = $app->auth()->vyzvaKlice($adresa);

                return Response::json($moznosti ?? ['chyba' => t('Přihlášení vypršelo, začněte prosím znovu.')], $moznosti === null ? 400 : 200);
            }
            $chyba = $app->auth()->overKlic((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $adresa, $app->request->ip());
            if ($chyba === null) {
                Protokol::zapis($app, 'prihlaseni', 'login', 'přihlašovacím klíčem');
            }

            return Response::json($chyba === null ? ['ok' => true, 'kam' => $app->url('admin.php')] : ['chyba' => $chyba], $chyba === null ? 200 : 401);
        }
        if ($app->request->isPost()) {
            $druhyKrok = $app->request->post('kod') !== '' || $app->request->post('krok') === 'kod';
            $chyba = $druhyKrok
                ? $app->auth()->overKod($app->request->post('kod'), $app->request->ip())
                : $app->auth()->login($app->request->post('user'), $app->request->post('password'), $app->request->ip());
            if ($chyba === null && $app->auth()->user() !== null) {
                Protokol::zapis($app, 'prihlaseni', 'login', $druhyKrok ? 'dvoufázově' : '');

                return Response::redirect($app->url('admin.php'));
            }
            if ($chyba !== null && !$druhyKrok) {
                Protokol::zapis($app, 'prihlaseni', 'neuspech', 'účet: ' . mb_substr($app->request->post('user'), 0, 40));
            }
        }

        return Response::html($app->view->render('admin/login', [
            'app' => $app,
            'chyba' => $chyba,
            'login' => $app->request->post('user'),
            'kod' => $app->auth()->cekaNaKod(),
            'klice' => $app->auth()->cekaSKlici(),
        ]), $chyba === null ? 200 : 401);
    }
}
