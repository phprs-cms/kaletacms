<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Stav systému (health check): sada rychlých kontrol serveru, databáze, bezpečnosti a provozu.
 * Výsledek se zobrazuje v Nastavení a je dostupný i jako JSON pro monitoring (/stav.json?token=...).
 */
final class Stav
{
    /**
     * @return list<array{skupina:string, nazev:string, stav:string, info:string}> stav: ok | varovani | chyba
     */
    public static function kontroly(App $app): array
    {
        $k = [];
        $pridej = function (string $skupina, string $nazev, bool|string $stav, string $info) use (&$k): void {
            $k[] = ['skupina' => $skupina, 'nazev' => $nazev, 'stav' => is_bool($stav) ? ($stav ? 'ok' : 'chyba') : $stav, 'info' => $info];
        };
        $db = $app->db();
        $web = $app->settings();

        // --- server (názvy a texty jdou přes t(); hodnoty "stav" se nepřekládají - čte je monitoring)
        $pridej(t('Server'), t('Verze PHP'), PHP_VERSION_ID >= 80400, PHP_VERSION_ID >= 80400 ? PHP_VERSION : t('%s - systém vyžaduje 8.4 nebo novější', PHP_VERSION));
        foreach (['pdo_mysql' => t('databáze'), 'mbstring' => t('text s diakritikou'), 'gd' => t('zpracování obrázků')] as $ext => $ucel) {
            $pridej(t('Server'), t('Rozšíření %s', $ext), extension_loaded($ext), extension_loaded($ext) ? $ucel : t('%s - chybí', $ucel));
        }
        foreach (['exif' => t('správné otočení fotek z mobilu'), 'intl' => t('řazení podle češtiny'), 'curl' => t('oznamování novinek vyhledávačům')] as $ext => $ucel) {
            $pridej(t('Server'), t('Rozšíření %s', $ext), extension_loaded($ext) ? 'ok' : 'varovani', extension_loaded($ext) ? $ucel : t('%s - doporučeno doinstalovat', $ucel));
        }
        $pridej(t('Server'), t('Limit nahrávaných souborů'), self::bajty((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024 ? 'ok' : 'varovani', 'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size'));
        $volno = @disk_free_space(MIROCMS_ROOT);
        if ($volno !== false) {
            $pridej(t('Server'), t('Volné místo na disku'), $volno > 200 * 1024 * 1024 ? 'ok' : 'varovani', self::velikost((int) $volno));
        }

        // --- databáze
        $pridej(t('Databáze'), t('Server'), 'ok', (string) $db->value('SELECT VERSION()'));
        $cekajici = Migrace::posledni() - max(1, $web->int('verze_db'));
        $pridej(t('Databáze'), t('Struktura databáze'), $cekajici <= 0, $cekajici <= 0 ? t('aktuální (verze %d)', $web->int('verze_db')) : t('čeká %d aktualizací - proběhnou při příštím načtení administrace', $cekajici));
        $velikost = (int) $db->value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [addcslashes($db->prefix, '_%') . '%']);
        $pridej(t('Databáze'), t('Velikost'), 'ok', t('%s, novinek: %d', self::velikost($velikost), (int) $db->value('SELECT COUNT(*) FROM {clanky}')));

        // --- soubory a bezpečnost
        foreach (['media' => t('nahrané obrázky'), 'storage/log' => t('záznam chyb'), 'storage/cache' => t('dočasná data')] as $slozka => $ucel) {
            $ok = is_dir(MIROCMS_ROOT . '/' . $slozka) ? is_writable(MIROCMS_ROOT . '/' . $slozka) : is_writable(MIROCMS_ROOT);
            $pridej(t('Soubory'), t('Zápis do %s/', $slozka), $ok, $ok ? $ucel : t('%s - nastavte práva k zápisu', $ucel));
        }
        $pridej(t('Bezpečnost'), t('Instalátor'), !is_file(MIROCMS_ROOT . '/install.php') ? 'ok' : 'varovani', is_file(MIROCMS_ROOT . '/install.php') ? t('soubor install.php je stále na serveru - smažte ho') : t('install.php je odstraněn'));
        $pridej(t('Bezpečnost'), 'HTTPS', $app->request->isHttps() ? 'ok' : 'varovani', $app->request->isHttps() ? t('web běží na šifrovaném spojení') : t('web neběží na HTTPS - přihlašovací údaje putují nešifrovaně'));
        $pridej(t('Bezpečnost'), t('Ladicí režim'), !$app->debug(), $app->debug() ? t('v config.php je debug = true; na ostrém webu vypněte') : t('vypnutý'));
        $pridej(t('Bezpečnost'), t('Bezpečnostní hlavičky'), 'ok', t('systém odesílá X-Content-Type-Options, Referrer-Policy a X-Frame-Options; administrace navíc Content-Security-Policy a zákaz ukládání do mezipaměti'));
        $bez2fa = (int) $db->value("SELECT COUNT(*) FROM {user} WHERE admin = 2 AND blokovat = 0 AND totp_tajemstvi = ''");
        $pridej(t('Bezpečnost'), t('Dvoufázové přihlášení administrátorů'), $bez2fa === 0 ? 'ok' : 'varovani', $bez2fa === 0 ? t('mají ho všichni administrátoři') : t('%d administrátor(ů) ho nemá - zapíná se v nabídce Můj účet (avatar vpravo nahoře)', $bez2fa));
        $slabi = (int) $db->value('SELECT COUNT(*) FROM {user} WHERE blokovat = 1');
        $pridej(t('Bezpečnost'), t('Zablokované účty'), $slabi === 0 ? 'ok' : 'varovani', $slabi === 0 ? t('žádné') : t('%d - zablokoval je správce; odblokujete je v Uživatelích', $slabi));

        $jadro = Integrita::kontrola();
        $pridej(t('Bezpečnost'), t('Soubory jádra'), $jadro['stav'], $jadro['info']);

        // --- provoz
        $log = MIROCMS_ROOT . '/storage/log/chyby.log';
        $chyb = 0;
        if (is_file($log)) {
            $od = date('c', time() - 86400);
            foreach (array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -500) as $radek) {
                $chyb += (int) (substr($radek, 1, 25) >= $od);
            }
        }
        $pridej(t('Provoz'), t('Chyby za posledních 24 hodin'), $chyb === 0 ? 'ok' : 'varovani', $chyb === 0 ? t('žádné') : t('%d - podrobnosti v storage/log/chyby.log', $chyb));
        $posledni = Zaloha::seznam()[0]['cas'] ?? 0;
        $stari = $posledni > 0 ? (int) floor((time() - $posledni) / 86400) : null;
        $pridej(t('Provoz'), t('Záloha databáze'), $stari !== null && $stari <= 8 ? 'ok' : 'varovani', $stari === null ? t('zatím žádná - vytvořte ji v záložce Zálohy a aktualizace') : ($stari === 0 ? t('dnes') : t('před %d dny', $stari)) . ', ' . ($web->bool('zalohy_auto') ? t('automatické zálohy zapnuté') : t('automatické zálohy vypnuté')));
        $pridej(t('Provoz'), t('Indexování vyhledávači'), $web->bool('indexovani') ? 'ok' : 'varovani', $web->bool('indexovani') ? t('povoleno') : t('zakázáno v záložce SEO a GEO - web se neobjeví ve vyhledávání'));
        $media = 0;
        if (is_dir(MIROCMS_ROOT . '/media')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(MIROCMS_ROOT . '/media', \FilesystemIterator::SKIP_DOTS)) as $soubor) {
                $media += $soubor->getSize();
            }
        }
        $pridej(t('Provoz'), t('Velikost médií'), 'ok', self::velikost($media));
        $vzdalena = explode('|', $app->settings()->get('zaloha_vzdalena_stav'), 2);
        if ($app->settings()->get('zaloha_vzdalena') !== 'vypnuto') {
            $pridej(t('Provoz'), t('Zálohy mimo server'), ($vzdalena[1] ?? '') === 'ok' ? 'ok' : 'varovani', ($vzdalena[1] ?? '') === 'ok' ? t('poslední kopie nahrána %s', $vzdalena[0]) : (($vzdalena[1] ?? '') !== '' ? t('poslední pokus %s selhal: %s', $vzdalena[0], $vzdalena[1]) : t('zatím žádná kopie nevznikla')));
        } else {
            $pridej(t('Provoz'), t('Zálohy mimo server'), 'varovani', t('vypnuté – zálohy leží jen na stejném serveru jako web (Nastavení → Zálohy a aktualizace)'));
        }
        $smtp = $app->settings()->get('posta_rezim') === 'smtp' && $app->settings()->get('smtp_host') !== '';
        // úlohy na pozadí (naplánované články, fronta pošty, push, newsletter) spouští návštěvy webu nebo cron
        $naposledy = $web->int('oznameni_kontrola');
        $pred = $naposledy > 0 ? (int) floor((time() - $naposledy) / 60) : null;
        $pridej(t('Provoz'), t('Úlohy na pozadí'), $pred !== null && $pred <= 30 ? 'ok' : 'varovani', $pred === null
            ? t('zatím neproběhly – spustí je první návštěva webu')
            : ($pred <= 30 ? t('naposledy před %d min', $pred) : ($pred < 120 ? t('naposledy před %d min', $pred) : t('naposledy před %d h', (int) round($pred / 60)))
                . ' – ' . t('na webu s malou návštěvností nastavte cron, adresu najdete níže na této stránce')));
        $aktualizace = (new Aktualizace($web))->stav();
        $pridej(t('Provoz'), t('Aktualizace'), !$aktualizace['nastaveno'] || $aktualizace['chyba'] !== null || $aktualizace['nova'] !== null ? 'varovani' : 'ok', match (true) {
            !$aktualizace['nastaveno'] => t('zdroj aktualizací není nastaven'),
            $aktualizace['chyba'] !== null => t('zdroj aktualizací neodpovídá: %s', (string) $aktualizace['chyba']),
            $aktualizace['nova'] !== null => t('je k dispozici verze %s (Nastavení → Zálohy a aktualizace)', (string) $aktualizace['nova']['verze']),
            default => t('systém je aktuální (%s)', MIROCMS_VERSION) . ($aktualizace['overeno'] > 0 ? ', ' . t('ověřeno %s', date('j. n. Y H:i', $aktualizace['overeno'])) : ''),
        });
        $pridej(t('Provoz'), t('Odesílání pošty'), $smtp || function_exists('mail') ? 'ok' : 'varovani', $smtp ? t('přes SMTP server %s', $app->settings()->get('smtp_host')) : (function_exists('mail') ? t('funkcí mail() serveru – spolehlivější je SMTP (Nastavení → Pošta)') : t('funkce mail() je vypnutá – nastavte SMTP (Nastavení → Pošta)')));

        return $k;
    }

    /** Souhrn pro monitoring: nejhorší nalezený stav. */
    public static function souhrn(array $kontroly): string
    {
        $stavy = array_column($kontroly, 'stav');

        return in_array('chyba', $stavy, true) ? 'chyba' : (in_array('varovani', $stavy, true) ? 'varovani' : 'ok');
    }

    private static function velikost(int $bajtu): string
    {
        foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $jednotka) {
            if ($bajtu < 1024 || $jednotka === 'TB') {
                return cislo($bajtu, $jednotka === 'B' ? 0 : 1) . ' ' . $jednotka;
            }
            $bajtu /= 1024;
        }

        return '';
    }

    private static function bajty(string $ini): int
    {
        $cislo = (int) $ini;

        return match (strtoupper(substr(trim($ini), -1))) {
            'G' => $cislo * 1024 ** 3, 'M' => $cislo * 1024 ** 2, 'K' => $cislo * 1024, default => $cislo,
        };
    }
}
