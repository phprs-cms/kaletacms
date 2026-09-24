<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Aktualizace systému z administrace.
 *
 * Zdroj je soubor aktualizace.json: {"verze","vydano","url","sha256","podpis","min_php","bezpecnostni","zmeny":[...]}.
 * Vydání označené "bezpecnostni": true se umí nainstalovat samo (Nastavení -> Zálohy a aktualizace).
 * Balíček (ZIP) se přijme jen tehdy, když sedí SHA-256 a podpis Ed25519 (Core\Podpis::zpravaBalicku) ověřený některým
 * z veřejných klíčů v system/aktualizace.pub (provozní + záložní, viz docs/VYDAVANI.md). Soukromý klíč má jen vydavatel (tools/vydani.php).
 * Nikdy se nepřepisuje config.php, media/, storage/, install.php ani layouty, které nejsou součástí balíčku.
 */
final class Aktualizace
{
    /** Výchozí zdroj aktualizací; doplní se, až poběží web projektu. Lze přepsat v Nastavení. */
    public const string VYCHOZI_URL = 'https://kaletacms.com/aktualizace.json';

    private const array CHRANENE = ['config.php', 'install.php', 'media/', 'storage/', 'image/ukazka/', 'tools/', '.git/'];
    private const int MAX_BAJTU = 60 * 1024 * 1024;

    public function __construct(
        private readonly Settings $settings,
        private readonly string $koren = KALETA_ROOT,
        private readonly string $klicSoubor = KALETA_SYSTEM . '/aktualizace.pub',
    ) {
    }

    public function url(): string
    {
        return $this->settings->get('aktualizace_url') !== '' ? $this->settings->get('aktualizace_url') : self::VYCHOZI_URL;
    }

    /**
     * Stav aktualizací; výsledek dotazu se pamatuje 12 hodin.
     *
     * @return array{nastaveno:bool, aktualni:string, nova:?array<string, mixed>, chyba:?string, overeno:int}
     */
    public function stav(bool $vynutit = false): array
    {
        $stav = ['nastaveno' => $this->url() !== '', 'aktualni' => KALETA_VERSION, 'nova' => null, 'chyba' => null, 'overeno' => 0];
        if (!$stav['nastaveno']) {
            return $stav;
        }
        $cache = json_decode($this->settings->get('aktualizace_cache'), true);
        if (!$vynutit && is_array($cache) && ($cache['url'] ?? '') === $this->url() && time() - (int) ($cache['overeno'] ?? 0) < 12 * 3600) {
            $manifest = $cache['manifest'] ?? null;
            $stav['chyba'] = $cache['chyba'] ?? null;
            $stav['overeno'] = (int) $cache['overeno'];
        } else {
            try {
                $manifest = $this->manifest();
            } catch (\RuntimeException $e) {
                $manifest = null;
                $stav['chyba'] = $e->getMessage();
            }
            $stav['overeno'] = time();
            $this->settings->set('aktualizace_cache', (string) json_encode(['url' => $this->url(), 'overeno' => time(), 'manifest' => $manifest, 'chyba' => $stav['chyba']], JSON_UNESCAPED_UNICODE));
        }
        if (is_array($manifest) && version_compare((string) $manifest['verze'], KALETA_VERSION, '>')) {
            $stav['nova'] = $manifest;
        }

        return $stav;
    }

    /**
     * Údržba na pozadí: jednou za 12 hodin ověří novou verzi; bezpečnostní vydání nainstaluje samo (je-li to
     * povoleno), jinak administrátora upozorní e-mailem. Volá se po odeslání stránky, takže návštěvníka nezdržuje.
     */
    public static function naPozadi(App $app): void
    {
        $s = $app->settings();
        $a = new self($s);
        if ($a->url() === '') {
            return;
        }
        $cache = json_decode($s->get('aktualizace_cache'), true);
        if (is_array($cache) && time() - (int) ($cache['overeno'] ?? 0) < 12 * 3600) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        $nova = $a->stav(true)['nova'];
        if ($nova === null || empty($nova['bezpecnostni']) || $s->get('aktualizace_pokus') === $nova['verze']) {
            return;
        }
        $s->set('aktualizace_pokus', (string) $nova['verze']); // každá verze se zkouší a oznamuje jen jednou
        // píše se na e-mail webu (adresa bez účtu): texty administrace ve výchozím jazyce webu. Úloha běží i z veřejného webu,
        // kde slovník administrace načtený není – Jazyk::docasne() ho načte jen na tuto chvíli (i pro hlášky chyb instalace).
        [$predmet, $text] = Jazyk::docasne(Jazyk::vychozi($s), function () use ($app, $a, $s, $nova): array {
            $vysledek = t('Je k dispozici bezpečnostní aktualizace %s. Nainstalujte ji v administraci: Nastavení → Zálohy a aktualizace.', (string) $nova['verze']);
            if ($s->bool('aktualizace_auto')) {
                try {
                    Zaloha::vytvor($app->db(), 'predaktualizaci');
                    $a->nainstaluj();
                    $vysledek = t('Bezpečnostní aktualizace %s byla nainstalována automaticky. Před instalací vznikla záloha databáze.', (string) $nova['verze']);
                } catch (\Throwable $e) {
                    $vysledek .= ' ' . t('Automatická instalace se nezdařila: %s', $e->getMessage());
                }
            }

            return [
                t('Kaleta: bezpečnostní aktualizace %s', (string) $nova['verze']),
                $vysledek . "\n\n" . t('Změny:') . "\n- " . implode("\n- ", $nova['zmeny']) . "\n\n" . $s->get('nazev_webu'),
            ];
        }, 'admin-');
        $komu = $s->get('email_webu');
        if ($komu !== '') {
            Posta::odesli($s, $komu, $predmet, $text);
        }
    }

    /** @return string nainstalovaná verze */
    public function nainstaluj(): string
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_verify_detached')) {
            throw new \RuntimeException(t('Server nemá rozšíření zip nebo sodium - aktualizujte ručně nahráním souborů přes FTP.'));
        }
        $m = $this->manifest();
        if (!version_compare((string) $m['verze'], KALETA_VERSION, '>')) {
            throw new \RuntimeException(t('Žádná novější verze není k dispozici.'));
        }
        if (version_compare(PHP_VERSION, (string) ($m['min_php'] ?? '8.4'), '<')) {
            throw new \RuntimeException(t('Nová verze vyžaduje PHP %s, na serveru běží %s.', (string) $m['min_php'], PHP_VERSION));
        }
        if (!is_writable($this->koren) || !is_writable($this->koren . '/system')) {
            throw new \RuntimeException(t('Soubory systému nejsou zapisovatelné - aktualizujte ručně přes FTP.'));
        }
        if (Podpis::klice($this->klicSoubor) === []) {
            throw new \RuntimeException(t('Chybí veřejný klíč vydavatele (system/aktualizace.pub), balíček nelze ověřit.'));
        }

        // zámek: automatická aktualizace z úloh na pozadí a klik správce (nebo dvě návštěvy naráz) nesmějí přepisovat soubory současně
        $zamek = fopen(KALETA_ROOT . '/storage/cache/aktualizace.zamek', 'c');
        if ($zamek === false || !flock($zamek, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException(t('Aktualizace už právě běží. Zkuste to za chvíli.'));
        }
        $pracovni = KALETA_ROOT . '/storage/cache/aktualizace-' . bin2hex(random_bytes(4));
        $zip = $pracovni . '.zip';
        try {
            $this->stahni((string) $m['url'], $zip);
            $sha = hash_file('sha256', $zip);
            if (!hash_equals(strtolower((string) $m['sha256']), $sha)) {
                throw new \RuntimeException(t('Kontrolní součet balíčku nesouhlasí.'));
            }
            // podpis kryje i příznak bezpečnostního vydání: kdo by ovládl jen web s manifestem, nesmí běžné vydání prohlásit za bezpečnostní
            if (!Podpis::plati(Podpis::zpravaBalicku((string) $m['verze'], $sha, !empty($m['bezpecnostni'])), (string) $m['podpis'], $this->klicSoubor)) {
                throw new \RuntimeException(t('Podpis balíčku není platný - balíček nepochází od vydavatele Kalety.'));
            }
            $soubory = $this->rozbal($zip, $pracovni);
            $puvodni = $this->souboryVydani();
            $otiskyVydani = $this->otiskyVydani();
            touch(KALETA_ROOT . '/storage/udrzba.lock');
            // každý přepisovaný soubor se nejdřív odloží: selže-li zápis uprostřed, web se vrátí do původního stavu (ne směs verzí)
            $odlozene = $pracovni . '-puvodni';
            $zapsane = [];
            try {
                foreach ($soubory as $relativni) {
                    $cil = $this->koren . '/' . $relativni;
                    if ($relativni === '.htaccess' && is_file($cil) && isset($otiskyVydani['.htaccess']) && !hash_equals($otiskyVydani['.htaccess'], (string) hash_file('sha256', $cil))) {
                        // vlastní úpravy .htaccess (HTTPS, www, přesměrování) se nepřepíšou – nová verze leží vedle k porovnání
                        copy($pracovni . '/' . $relativni, $cil . '.kaleta-nova');
                        continue;
                    }
                    if (!is_dir(dirname($cil)) && !mkdir(dirname($cil), 0775, true)) {
                        throw new \RuntimeException(t('Nelze vytvořit složku %s.', dirname($relativni)));
                    }
                    if (is_file($cil)) {
                        if (!is_dir(dirname($odlozene . '/' . $relativni)) && !mkdir(dirname($odlozene . '/' . $relativni), 0775, true) || !copy($cil, $odlozene . '/' . $relativni)) {
                            throw new \RuntimeException(t('Nelze zapsat soubor %s.', 'storage/cache'));
                        }
                    }
                    if (!copy($pracovni . '/' . $relativni, $cil)) {
                        throw new \RuntimeException(t('Nelze zapsat soubor %s.', $relativni));
                    }
                    $zapsane[] = $relativni;
                }
            } catch (\Throwable $e) {
                foreach (array_reverse($zapsane) as $relativni) {
                    is_file($odlozene . '/' . $relativni) ? @copy($odlozene . '/' . $relativni, $this->koren . '/' . $relativni) : @unlink($this->koren . '/' . $relativni);
                }
                self::smazSlozku($odlozene);
                throw new \RuntimeException($e->getMessage() . ' ' . t('Soubory webu jsou vrácené do stavu před aktualizací.'), 0, $e);
            }
            self::smazSlozku($odlozene);
            self::uklidZastarale($this->koren, $puvodni, $soubory);
        } finally {
            @unlink(KALETA_ROOT . '/storage/udrzba.lock');
            @unlink($zip);
            self::smazSlozku($pracovni);
            flock($zamek, LOCK_UN);
            fclose($zamek);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->settings->set('aktualizace_cache', '');

        return (string) $m['verze'];
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $json = $this->http($this->url(), 200 * 1024, 6); // krátký limit: kontrola běží po odeslání stránky, ale ne každý server ji umí oddělit
        $m = json_decode($json, true);
        if (!is_array($m) || !isset($m['verze'], $m['url'], $m['sha256'], $m['podpis']) || !preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', (string) $m['verze'])) {
            throw new \RuntimeException(t('Soubor s informací o aktualizaci nemá platný tvar.'));
        }
        $m['zmeny'] = array_values(array_filter(array_map(fn ($z): string => mb_substr((string) $z, 0, 300), (array) ($m['zmeny'] ?? []))));

        return $m;
    }

    private function stahni(string $url, string $cil): void
    {
        file_put_contents($cil, $this->http($url, self::MAX_BAJTU));
    }

    private function http(string $url, int $maxBajtu, int $limitSekund = 30): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $mistni = in_array($host, ['localhost', '127.0.0.1'], true);
        if (!preg_match('#^https://#i', $url) && !($mistni && preg_match('#^http://#i', $url))) {
            throw new \RuntimeException(t('Zdroj aktualizací musí být na adrese https://.'));
        }
        $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $limitSekund, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta/" . KALETA_VERSION . "\r\n"]]), 0, $maxBajtu + 1);
        if ($data === false || $data === '') {
            throw new \RuntimeException(t('Zdroj aktualizací není dostupný (%s).', $host));
        }
        if (strlen($data) > $maxBajtu) {
            throw new \RuntimeException(t('Stahovaný soubor je nečekaně velký.'));
        }

        return $data;
    }

    /**
     * Rozbalí balíček do pracovní složky a vrátí seznam souborů k přepsání (bez chráněných cest).
     *
     * @return list<string>
     */
    private function rozbal(string $zipSoubor, string $kam): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipSoubor) !== true) {
            throw new \RuntimeException(t('Balíček nelze otevřít.'));
        }
        $jmena = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $jmena[] = (string) $zip->getNameIndex($i);
        }
        // balíček může mít všechno v jedné složce navrch (jak to dělá GitHub) - ta se odřízne
        $prvni = array_unique(array_map(fn (string $j): string => explode('/', $j, 2)[0], $jmena));
        $predpona = count($prvni) === 1 && !in_array('index.php', $jmena, true) ? $prvni[0] . '/' : '';

        $soubory = [];
        foreach ($jmena as $i => $jmeno) {
            $relativni = substr($jmeno, strlen($predpona));
            if ($relativni === '' || str_ends_with($relativni, '/')) {
                continue;
            }
            if (str_contains($relativni, '..') || str_starts_with($relativni, '/') || str_contains($relativni, "\0") || str_contains($relativni, '\\')) {
                throw new \RuntimeException(t('Balíček obsahuje nebezpečnou cestu.'));
            }
            foreach (self::CHRANENE as $chranena) {
                if ($relativni === $chranena || (str_ends_with($chranena, '/') && str_starts_with($relativni, $chranena))) {
                    continue 2;
                }
            }
            $cil = $kam . '/' . $relativni;
            if (!is_dir(dirname($cil))) {
                mkdir(dirname($cil), 0775, true);
            }
            file_put_contents($cil, $zip->getFromIndex($i));
            $soubory[] = $relativni;
        }
        $zip->close();
        if (!in_array('system/bootstrap.php', $soubory, true) || !in_array('index.php', $soubory, true)) {
            throw new \RuntimeException(t('Balíček neobsahuje Kaletu.'));
        }

        return $soubory;
    }

    /**
     * Soubory, které z balíčku odešly dřív, než aktualizace uměla po sobě uklízet (nebo je web přeskočil): cesta => otisky
     * všech vydaných podob. Seznam souborů jádra o nich už neví, proto se uklízejí podle tohoto výčtu.
     */
    private const array ZRUSENE = [
        // 'cesta/k/souboru.php' => ['sha256 vydané podoby', …] – soubory, které nová verze zrušila
    ];

    /**
     * Jednorázový úklid po přechodu na novou verzi: smaže známé zrušené soubory, ale jen když jsou přesně takové, jaké
     * jsme je vydali. Soubor, který si správce upravil (nebo šablonu, kterou web právě používá), nechává být.
     *
     * @return int počet smazaných souborů
     */
    public static function uklidZrusene(string $koren, string $pouzivanaSablona = ''): int
    {
        $smazano = 0;
        foreach (self::ZRUSENE as $relativni => $otisky) {
            $soubor = $koren . '/' . $relativni;
            if (str_starts_with($relativni, 'layout/' . $pouzivanaSablona . '/') && $pouzivanaSablona !== '') {
                continue;
            }
            if (is_file($soubor) && in_array(hash_file('sha256', $soubor), $otisky, true) && @unlink($soubor)) {
                $smazano++;
                @rmdir(dirname($soubor)); // složka zmizí, jen když zůstala prázdná
            }
        }

        return $smazano;
    }

    /** @return array<string, string> otisky souborů právě nainstalovaného vydání (system/soubory.json) */
    private function otiskyVydani(): array
    {
        $data = json_decode((string) @file_get_contents($this->koren . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', $data['soubory']) : [];
    }

    /** @return list<string> soubory jádra podle seznamu právě nainstalovaného vydání (system/soubory.json); bez seznamu prázdné */
    private function souboryVydani(): array
    {
        $data = json_decode((string) @file_get_contents($this->koren . '/system/soubory.json'), true);

        return is_array($data['soubory'] ?? null) ? array_map('strval', array_keys($data['soubory'])) : [];
    }

    /**
     * Smaže soubory, které patřily ke starému vydání a v novém už nejsou (přejmenované a zrušené části systému).
     * Maže jen to, co staré vydání samo přineslo - vlastní soubory, šablony, média ani chráněné cesty se nedotkne.
     *
     * @param list<string> $puvodni
     * @param list<string> $nove
     * @return int počet smazaných souborů
     */
    public static function uklidZastarale(string $koren, array $puvodni, array $nove): int
    {
        $smazano = 0;
        foreach (array_diff($puvodni, $nove) as $relativni) {
            if (str_contains($relativni, '..') || str_starts_with($relativni, '/') || str_contains($relativni, '\\') || str_contains($relativni, "\0")) {
                continue;
            }
            foreach (self::CHRANENE as $chranena) {
                if ($relativni === $chranena || (str_ends_with($chranena, '/') && str_starts_with($relativni, $chranena))) {
                    continue 2;
                }
            }
            $soubor = $koren . '/' . $relativni;
            if (is_file($soubor) && @unlink($soubor)) {
                $smazano++;
                @rmdir(dirname($soubor)); // složka zmizí, jen když zůstala prázdná
            }
        }

        return $smazano;
    }

    private static function smazSlozku(string $slozka): void
    {
        if (!is_dir($slozka)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($slozka, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $polozka) {
            $polozka->isDir() ? @rmdir($polozka->getPathname()) : @unlink($polozka->getPathname());
        }
        @rmdir($slozka);
    }
}
