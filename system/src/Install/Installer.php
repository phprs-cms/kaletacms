<?php

declare(strict_types=1);

namespace MiroCMS\Install;

use MiroCMS\Core\Auth;
use MiroCMS\Core\Db;
use MiroCMS\Core\Migrace;
use MiroCMS\Core\Request;
use MiroCMS\Core\Response;
use MiroCMS\Core\View;
use MiroCMS\Front\Layouty;

/**
 * Webový instalátor: ověří server, založí tabulky, prvního admina a zapíše config.php.
 */
final class Installer
{
    private readonly Request $request;
    private readonly View $view;

    public function __construct()
    {
        $this->request = Request::fromGlobals();
        $this->view = new View([MIROCMS_SYSTEM . '/views']);
    }

    /** Jazyky instalace (= jazyky administrace) a výchozí časové pásmo, které k nim nabídneme. */
    private const array PASMA = ['cs' => 'Europe/Prague', 'sk' => 'Europe/Bratislava', 'en' => 'Europe/London', 'de' => 'Europe/Berlin'];

    private string $jazyk = 'cs';

    /** Jazyk instalace: výslovná volba (?jazyk=, skryté pole formuláře), jinak první známý jazyk z hlavičky prohlížeče. */
    private function zvolJazyk(): string
    {
        $volba = (string) ($_POST['jazyk'] ?? $_GET['jazyk'] ?? '');
        if (isset(self::PASMA[$volba])) {
            return $volba;
        }
        foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $cast) {
            $kod = substr(trim($cast), 0, 2);
            if (isset(self::PASMA[$kod])) {
                return $kod;
            }
        }

        return 'cs';
    }

    public function handle(): Response
    {
        if (is_file(MIROCMS_ROOT . '/config.php')) {
            \MiroCMS\Core\Jazyk::nastav($this->zvolJazyk(), 'install-');

            return $this->stranka('hotovo', ['jizNainstalovano' => true, 'smazano' => $this->smazSe()]);
        }

        $this->jazyk = $this->zvolJazyk();
        \MiroCMS\Core\Jazyk::nastav($this->jazyk, 'install-');
        $pozadavky = $this->pozadavky();
        $data = [
            'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_password' => '', 'db_prefix' => 'mc_',
            'nazev_webu' => t('Můj magazín'), 'user' => 'admin', 'jmeno' => '', 'email' => '',
            'casove_pasmo' => self::PASMA[$this->jazyk], 'layout' => Layouty::VYCHOZI,
            'demo' => '', // '1' = místo uvítacího článku nahrát ukázkový obsah (Core\Demo)
        ];
        $chyby = [];

        if ($this->request->isPost() && !in_array(false, array_column($pozadavky, 'ok'), true)) {
            foreach (array_keys($data) as $klic) {
                // heslo k databázi se neořezává - může obsahovat mezery
                $data[$klic] = $klic === 'db_password' ? (string) ($_POST[$klic] ?? '') : $this->request->post($klic);
            }
            $data['demo'] = $this->request->postBool('demo') ? '1' : '';
            $chyby = $this->instaluj($data, (string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''));
            if ($chyby === []) {
                return $this->stranka('hotovo', ['jizNainstalovano' => false, 'smazano' => $this->smazSe()]);
            }
        }

        return $this->stranka('formular', ['pozadavky' => $pozadavky, 'data' => $data, 'chyby' => $chyby, 'layouty' => Layouty::seznam()]);
    }

    /**
     * Po instalaci instalátor smaže sám sebe, ať správce nemusí na FTP. Když to hosting nedovolí (práva k souborům),
     * zůstane výzva ke smazání a Stav systému na soubor dál upozorňuje. Ve vývojové kopii (složka .git) se nemaže.
     */
    private function smazSe(): bool
    {
        if (is_dir(MIROCMS_ROOT . '/.git')) {
            return false;
        }

        return !is_file(MIROCMS_ROOT . '/install.php') || @unlink(MIROCMS_ROOT . '/install.php');
    }

    /** @return list<array{nazev:string, ok:bool, info:string}> */
    private function pozadavky(): array
    {
        $zapis = fn (string $cesta): bool => is_writable(MIROCMS_ROOT . $cesta);

        return [
            ['nazev' => 'PHP 8.4 nebo novější', 'ok' => PHP_VERSION_ID >= 80400, 'info' => t('běží') . ' ' . PHP_VERSION],
            ['nazev' => 'Rozšíření pdo_mysql', 'ok' => extension_loaded('pdo_mysql'), 'info' => t('připojení k databázi MySQL / MariaDB')],
            ['nazev' => 'Rozšíření mbstring', 'ok' => extension_loaded('mbstring'), 'info' => t('práce s češtinou')],
            ['nazev' => 'Zápis do kořenové složky', 'ok' => $zapis(''), 'info' => t('kvůli vytvoření config.php')],
            ['nazev' => 'Zápis do složky storage/', 'ok' => $zapis('/storage/log') && $zapis('/storage/cache'), 'info' => t('logy a cache')],
        ];
    }

    /**
     * @param array<string, string> $d
     * @return array<string, string> chyby; prázdné pole = nainstalováno
     */
    private function instaluj(array $d, string $heslo, string $heslo2): array
    {
        $chyby = [];
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $d['db_prefix'])) {
            $chyby['db_prefix'] = t('Předpona: malá písmena, číslice a podtržítko, nejvýše 16 znaků (např. mc_).');
        }
        if ($d['db_name'] === '' || $d['db_user'] === '') {
            $chyby['db_name'] = t('Vyplňte název databáze a uživatele.');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $d['user'])) {
            $chyby['user'] = t('Přihlašovací jméno: 2-40 znaků, písmena bez diakritiky, číslice, tečka, pomlčka, podtržítko.');
        }
        if (mb_strlen($heslo) < 10) {
            $chyby['password'] = t('Heslo musí mít alespoň 10 znaků.');
        } elseif ($heslo !== $heslo2) {
            $chyby['password'] = t('Hesla se neshodují.');
        }
        if ($d['email'] !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
            $chyby['email'] = t('E-mail nemá platný tvar.');
        }
        if (!isset(Layouty::seznam()[$d['layout']])) {
            $d['layout'] = Layouty::VYCHOZI;
        }
        if ($chyby !== []) {
            return $chyby;
        }

        $config = [
            'db' => [
                'host' => $d['db_host'] !== '' ? $d['db_host'] : 'localhost',
                'port' => (int) $d['db_port'] ?: 3306,
                'name' => $d['db_name'],
                'user' => $d['db_user'],
                'password' => $d['db_password'],
                'prefix' => $d['db_prefix'],
            ],
            'debug' => false,
        ];

        try {
            $db = Db::fromConfig($config['db']);
            $db->pdo();
        } catch (\PDOException $e) {
            return ['db_name' => t('K databázi se nepodařilo připojit:') . ' ' . $e->getMessage()];
        }
        $existuje = $db->value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$d['db_prefix'] . 'user'],
        );
        if ((int) $existuje > 0) {
            return ['db_prefix' => t('V databázi už tabulky s touto předponou existují. Zvolte jinou předponu, nebo je nejprve odstraňte.')];
        }

        try {
            foreach (Migrace::prikazy((string) file_get_contents(MIROCMS_SYSTEM . '/sql/schema.sql'), $d['db_prefix']) as $sql) {
                $db->pdo()->exec($sql);
            }
            $this->vychoziData($db, $d, $heslo);
        } catch (\PDOException $e) {
            return ['db_name' => t('Vytvoření tabulek selhalo:') . ' ' . $e->getMessage()];
        }

        $obsah = "<?php\n/**\n * MiroCMS - konfigurace vytvořená instalátorem " . date('j. n. Y') . ".\n */\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents(MIROCMS_ROOT . '/config.php', $obsah, LOCK_EX) === false) {
            return ['db_name' => t('Tabulky jsou vytvořeny, ale nepodařilo se zapsat config.php. Zkontrolujte práva k zápisu.')];
        }

        return [];
    }

    /** @param array<string, string> $d */
    private function vychoziData(Db $db, array $d, string $heslo): void
    {
        // zvolené časové pásmo platí už pro ukázkový obsah: jinak by uvítací článek mohl mít datum „v budoucnosti“ a web by ho neukázal
        $pasmo = in_array($d['casove_pasmo'], \DateTimeZone::listIdentifiers(), true) ? $d['casove_pasmo'] : self::PASMA[$this->jazyk];
        date_default_timezone_set($pasmo);
        $db->pdo()->exec("SET time_zone = '" . date('P') . "'");
        $d['casove_pasmo'] = $pasmo;
        $db->transaction(function (Db $db) use ($d, $heslo): void {
            $admin = $db->insert('user', [
                'user' => $d['user'],
                'password' => password_hash($heslo, PASSWORD_DEFAULT),
                'jmeno' => $d['jmeno'],
                'email' => $d['email'],
                'admin' => Auth::ADMIN,
                'pravo_vydavat' => 1,
                'jazyk' => $this->jazyk === 'cs' ? '' : $this->jazyk, // administrace prvního účtu v jazyce instalace
            ]);

            \MiroCMS\Core\Hledani::dopln($db);
            $nastaveni = ['nazev_webu' => $d['nazev_webu'], 'adresa_webu' => $this->request->origin(), 'email_webu' => $d['email'], 'jazyk_webu' => $this->jazyk,
                'casove_pasmo' => $d['casove_pasmo'], 'layout' => $d['layout'], 'rozvrzeni' => Layouty::seznam()[$d['layout']]['rozvrzeni'], 'verze_db' => (string) Migrace::posledni()];
            foreach ($nastaveni as $klic => $hodnota) {
                $db->insert('config', ['promenna' => $klic, 'hodnota' => $hodnota]);
            }
            $db->insert('levely', ['nazev_levelu' => t('Základní'), 'hodnota' => 0, 'zakladni' => 1]);
            $sablona = $db->insert('cla_sab', ['nazev_cla_sab' => t('Standardní'), 'soubor_cla_sab' => 'standard']);
            foreach (['dlouhe-cteni' => 'Dlouhé čtení', 'fotoreportaz' => 'Fotoreportáž', 'rozhovor' => 'Rozhovor'] as $soubor => $nazevSablony) {
                $db->insert('cla_sab', ['nazev_cla_sab' => t($nazevSablony), 'soubor_cla_sab' => $soubor]);
            }

            $bloky = [['leva', 'Rubriky', 'rub', 200], ['leva', 'Vyhledávání', 'hle', 100], ['prava', 'Nejčtenější články', 'nej', 200]]; // Novinky a Ankety jsou rozšíření, která si web zapne sám
            foreach ($bloky as [$zona, $nazev, $sys, $hodnost]) {
                $db->insert('bloky', ['nazev' => t($nazev), 'obsah' => '', 'sys_funkce' => $sys, 'hodnost' => $hodnost, 'zona' => $zona]);
            }

            if ($d['demo'] === '1') {
                // ukázkový magazín nahrazuje uvítací článek: titulní strana pak vypadá jako skutečné noviny a vše jde později smazat najednou
                \MiroCMS\Core\Demo::nahraj($db, new \MiroCMS\Core\Settings($db), $this->jazyk, $admin);

                return;
            }
            $rubrika = $db->insert('topic', ['nazev' => t('Aktuality'), 'seo_link' => slugify(t('Aktuality')), 'popis' => '']);
            $db->insert('clanky', [
                'seo_link' => slugify(t('Vítejte v MiroCMS')),
                'titulek' => t('Vítejte v MiroCMS'),
                'uvod' => '<p>' . e(t('Redakční systém je nainstalován a připraven. Tento článek můžete v administraci upravit nebo smazat.')) . '</p>',
                'text' => '<p>' . e(t('Do administrace se dostanete na adrese admin.php. Na přehledu vás provedou První kroky: dejte webu tvář, založte rubriky a napište první článek. Rozložení webu do sloupců a bloků upravíte přímo na stránce v sekci Bloky a rozvržení.')) . '</p>',
                'tema' => $rubrika,
                'autor' => $admin,
                'datum' => date('Y-m-d H:i:s'),
                'visible' => 1,
                'sablona' => $sablona,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    private function stranka(string $sablona, array $data): Response
    {
        return Response::html($this->view->render('install/' . $sablona, $data + [
            'base' => $this->request->basePath(), 'jazyk' => \MiroCMS\Core\Jazyk::kod(),
            'jazyky' => array_intersect_key(\MiroCMS\Core\Jazyk::ADMINISTRACE, self::PASMA),
        ]));
    }
}
