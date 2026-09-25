<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Export celého webu do jednoho archivu – aby obsah nikdy nezůstal v Kaletě zamčený.
 *
 * Archiv storage/zalohy/export-RRRRMMDD-HHMMSS.zip obsahuje obsah.json (stránky, kategorie, štítky, novinky, přesměrování,
 * knihovnu médií a veřejná nastavení), README.txt s popisem formátu a složku media/.
 *
 * Co v exportu NIKDY není: hesla, klíče API, tokeny, údaje SMTP a FTP, účty uživatelů administrace.
 * Nastavení se proto vybírají ze seznamu povolených (NASTAVENI), ne vylučováním.
 * Není to záloha pro obnovu Kalety (tou je záloha databáze), ale přenosný otevřený formát.
 */
final class ExportWebu
{
    /** Horní mez velikosti médií v archivu; nad ni (nebo když nestačí místo na disku) vznikne export bez médií. */
    public const int MAX_MEDII = 1024 * 1024 * 1024;
    private const int PONECHAT = 3;

    /** Jediná nastavení, která se exportují: název, popis, identita a jazyky webu. */
    private const array NASTAVENI = ['nazev_webu', 'popis_webu', 'klicova_slova', 'adresa_webu', 'logo_webu', 'favicon', 'design_system', 'firma_nazev', 'firma_typ', 'firma_ico', 'firma_dic', 'firma_rejstrik', 'firma_zastupce', 'firma_ulice', 'firma_mesto', 'firma_psc', 'firma_zeme', 'firma_telefon', 'firma_hodiny', 'firma_mapa', 'firma_gps', 'brand_akcent', 'tmavy_rezim',
        'brand_pismo_titulky', 'brand_pismo_text', 'text_paticky', 'soc_facebook', 'soc_instagram', 'soc_x', 'soc_youtube', 'soc_linkedin',
        'casove_pasmo', 'jazyk_webu', 'jazyky_dalsi', 'layout', 'titulni_stranka'];

    /** Sloupce novinky, které jsou jen provozní (index hledání, kontrola odkazů…) a do exportu nepatří. */
    private const array VYNECHAT_U_CLANKU = ['hledani', 'odkazy_cas', 'oznameno', 'autor', 'autor_jmeno'];

    /**
     * @return array{soubor:string, media:bool, duvod:string} název vytvořeného souboru; media = false, když jsou v něm jen data (duvod říká proč)
     * @throws \RuntimeException
     */
    public static function vytvor(Db $db, Settings $nastaveni): array
    {
        if (!is_dir(Zaloha::SLOZKA) && !mkdir(Zaloha::SLOZKA, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku storage/zalohy - zkontrolujte práva k zápisu.');
        }
        @set_time_limit(300);
        $zaklad = Zaloha::SLOZKA . '/export-' . date('Ymd-His');
        $json = $zaklad . '.json';
        self::zapisObsah($db, $nastaveni, $json);
        if (!class_exists(\ZipArchive::class)) {
            self::uklid();

            return ['soubor' => basename($json), 'media' => false, 'duvod' => 'Na serveru chybí rozšíření PHP zip, export proto obsahuje jen data (JSON). Složku media/ si stáhněte přes FTP.'];
        }

        $soubory = self::media();
        $velikost = array_sum($soubory);
        $volno = @disk_free_space(Zaloha::SLOZKA);
        $duvod = match (true) {
            $velikost > self::MAX_MEDII => 'Média mají přes 1 GB, export proto obsahuje jen data (JSON). Složku media/ si stáhněte přes FTP.',
            $volno !== false && $velikost * 1.1 + (int) filesize($json) > $volno => 'Na disku není dost místa pro archiv s médii, export proto obsahuje jen data (JSON). Složku media/ si stáhněte přes FTP.',
            default => '',
        };
        $zip = new \ZipArchive();
        if ($zip->open($zaklad . '.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Archiv se nepodařilo vytvořit - zkontrolujte práva k zápisu do storage/zalohy.');
        }
        $zip->addFile($json, 'obsah.json');
        $zip->addFromString('README.txt', self::readme($duvod === ''));
        if ($duvod === '') {
            // soubor po souboru; obrázky už komprimované jsou, proto se jen ukládají (CM_STORE) – archiv je pak hotový rychle
            foreach (array_keys($soubory) as $cesta) {
                $zip->addFile(KALETA_ROOT . '/' . $cesta, $cesta);
                $zip->setCompressionName($cesta, \ZipArchive::CM_STORE);
            }
        }
        if (!$zip->close()) {
            @unlink($zaklad . '.zip');
            throw new \RuntimeException('Archiv se nepodařilo dokončit - nejspíš došlo místo na disku.');
        }
        unlink($json);
        self::uklid();

        return ['soubor' => basename($zaklad) . '.zip', 'media' => $duvod === '', 'duvod' => $duvod];
    }

    /** @return list<array{soubor:string, velikost:int, cas:int}> nejnovější nahoře */
    public static function seznam(): array
    {
        $exporty = [];
        foreach (glob(Zaloha::SLOZKA . '/export-*') ?: [] as $cesta) {
            if (self::cesta(basename($cesta)) !== null) {
                $exporty[] = ['soubor' => basename($cesta), 'velikost' => (int) filesize($cesta), 'cas' => (int) filemtime($cesta)];
            }
        }
        usort($exporty, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $exporty;
    }

    /** Cesta k existujícímu exportu podle názvu z adresy; null = neplatný název. */
    public static function cesta(string $soubor): ?string
    {
        return preg_match('/^export-\d{8}-\d{6}\.(zip|json)$/', $soubor) && is_file(Zaloha::SLOZKA . '/' . $soubor) ? Zaloha::SLOZKA . '/' . $soubor : null;
    }

    /* ---------- obsah.json ---------- */

    /** Zapisuje se rovnou do souboru a po řádcích z databáze – ani web s desítkami tisíc článků se tak nemusí vejít do paměti. */
    private static function zapisObsah(Db $db, Settings $nastaveni, string $cesta): void
    {
        $f = fopen($cesta, 'wb');
        if ($f === false) {
            throw new \RuntimeException('Nelze zapisovat do storage/zalohy - zkontrolujte práva k zápisu.');
        }
        fwrite($f, '{"format":"kaleta-export","verze_formatu":1,"kaleta":' . self::json(KALETA_VERSION) . ',"vytvoreno":' . self::json(date('c')) . ',"nastaveni":' . self::json(self::nastaveni($db)));

        $autori = "(SELECT NULLIF(u.jmeno, '') FROM {uzivatele} u WHERE u.idu = c.autor) AS autor_jmeno";
        self::pole($f, 'stranky', self::postupne($db, 'SELECT * FROM {stranky} WHERE ids > ? AND smazano IS NULL ORDER BY ids LIMIT 200', 'ids')); // koš se nevyváží
        self::pole($f, 'kategorie', self::postupne($db, 'SELECT idt, nazev, seo_link, popis, hodnost, jazyk, preklad_z FROM {kategorie} WHERE idt > ? ORDER BY idt LIMIT 500', 'idt'));
        self::pole($f, 'stitky', self::postupne($db, 'SELECT ids, nazev, seo_link, popis, obrazek FROM {stitky} WHERE ids > ? ORDER BY ids LIMIT 500', 'ids'));
        self::pole($f, 'novinky', self::clanky($db, $autori));
        self::pole($f, 'presmerovani', self::postupne($db, 'SELECT idp, z_adresy, na_adresu FROM {presmerovani} WHERE idp > ? ORDER BY idp LIMIT 1000', 'idp'));
        // builder: sdílené třídy, části webu (záhlaví, patička, obálky) a kolekce; poptávky ne – jsou to osobní údaje návštěvníků
        self::pole($f, 'tridy', $db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev'));
        self::pole($f, 'casti', $db->all('SELECT typ, jazyk, varianta, nazev, stranky, stavba FROM {casti} WHERE stavba IS NOT NULL ORDER BY typ, jazyk, varianta'));
        // komponenty (prvky „komponenta“ na ně odkazují číslem) a vlastní sekce knihovny
        self::pole($f, 'komponenty', $db->all('SELECT idm, nazev, vlastnosti, stavba FROM {komponenty} ORDER BY idm'));
        self::pole($f, 'sekce', $db->all('SELECT idx, nazev, prvek FROM {sekce} ORDER BY idx'));
        self::pole($f, 'menu', $db->all('SELECT umisteni, jazyk, polozky FROM {menu} ORDER BY umisteni, jazyk'));
        self::pole($f, 'kolekce', $db->all('SELECT idk, nazev, seo_link, pole, detail, stavba FROM {kolekce} ORDER BY idk'));
        self::pole($f, 'kolekce_polozky', self::postupne($db, 'SELECT idp, idk, nazev, seo_link, data, poradi, zobrazit, jazyk, datum FROM {kolekce_polozky} WHERE idp > ? ORDER BY idp LIMIT 500', 'idp'));
        self::pole($f, 'media', self::postupne($db, 'SELECT ido, nazev, popis, obr_poloha AS soubor, obr_width AS sirka, obr_height AS vyska, nahl_poloha AS nahled, datum FROM {media} WHERE ido > ? ORDER BY ido LIMIT 500', 'ido'));
        fwrite($f, "}\n");
        fclose($f);
    }

    /**
     * Novinky se všemi sloupci; autor jako jméno (účty se neexportují), k tomu štítky.
     * Překlady drží sloupec preklad_z = idc novinky ve výchozím jazyce, tedy číslo platné i uvnitř exportu.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function clanky(Db $db, string $autori): \Generator
    {
        foreach (self::postupne($db, "SELECT c.*, {$autori} FROM {novinky} c WHERE c.idc > ? AND c.smazano IS NULL ORDER BY c.idc LIMIT 100", 'idc') as $c) {
            $clanek = array_diff_key($c, array_flip(self::VYNECHAT_U_CLANKU));
            $clanek['autor'] = (string) ($c['autor_jmeno'] ?? '');
            $clanek['stitky'] = array_map(intval(...), array_column($db->all('SELECT ids FROM {novinky_stitky} WHERE idc = ?', [$c['idc']]), 'ids'));
            yield $clanek;
        }
    }

    /**
     * Čte tabulku po stránkách podle primárního klíče (dotaz má jediný otazník: "klíč > ?").
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function postupne(Db $db, string $sql, string $klic): \Generator
    {
        $od = 0;
        do {
            $radky = $db->all($sql, [$od]);
            foreach ($radky as $radek) {
                $od = (int) $radek[$klic];
                yield $radek;
            }
        } while ($radky !== []);
    }

    /**
     * @param resource $f
     * @param iterable<array<string, mixed>> $radky
     */
    private static function pole($f, string $nazev, iterable $radky): void
    {
        fwrite($f, ",\n" . self::json($nazev) . ':[');
        $prvni = true;
        foreach ($radky as $radek) {
            fwrite($f, ($prvni ? "\n" : ",\n") . self::json($radek));
            $prvni = false;
        }
        fwrite($f, "\n]");
    }

    /** @return array<string, string> */
    private static function nastaveni(Db $db): array
    {
        $vse = $db->pairs('SELECT promenna, hodnota FROM {nastaveni}');
        $vyber = [];
        foreach ($vse as $klic => $hodnota) {
            // název a popis webu mohou mít variantu pro další jazyk (nazev_webu_en)
            $zaklad = (string) preg_replace('/_[a-z]{2}$/', '', (string) $klic);
            if (in_array($klic, self::NASTAVENI, true) || (in_array($zaklad, Settings::PODLE_JAZYKA, true) && $zaklad !== $klic)) {
                $vyber[(string) $klic] = (string) $hodnota;
            }
        }
        ksort($vyber);

        return $vyber;
    }

    private static function json(mixed $hodnota): string
    {
        return (string) json_encode($hodnota, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /* ---------- média a úklid ---------- */

    /** @return array<string, int> cesta od kořene webu => velikost; bez skrytých souborů a bez PHP */
    private static function media(): array
    {
        $soubory = [];
        if (!is_dir(KALETA_ROOT . '/media')) {
            return $soubory;
        }
        $pruchod = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS));
        foreach ($pruchod as $soubor) {
            $cesta = 'media/' . ltrim(str_replace('\\', '/', substr($soubor->getPathname(), strlen(KALETA_ROOT . '/media'))), '/');
            if ($soubor->isFile() && !$soubor->isLink() && !preg_match('#(^|/)\.|\.php\d?$#i', $cesta)) {
                $soubory[$cesta] = (int) $soubor->getSize();
            }
        }
        ksort($soubory);

        return $soubory;
    }

    /** Exporty bývají velké: nechávají se jen poslední tři. */
    private static function uklid(): void
    {
        foreach (array_slice(self::seznam(), self::PONECHAT) as $stary) {
            @unlink(Zaloha::SLOZKA . '/' . $stary['soubor']);
        }
    }

    private static function readme(bool $sMedii): string
    {
        return "Export webu z Kalety " . KALETA_VERSION . " (" . date('j. n. Y H:i') . ")\n"
            . "==========================================\n\n"
            . "obsah.json  všechen obsah webu v kódování UTF-8\n"
            . ($sMedii ? "media/      nahrané obrázky a přílohy; cesty odpovídají sloupcům \"obrazek\" a poli \"media\"\n" : "media/      v archivu NENÍ (příliš velká nebo málo místa) – stáhněte si složku media/ z webu přes FTP\n")
            . "\nStruktura obsah.json\n--------------------\n"
            . "format, verze_formatu, kaleta, vytvoreno – hlavička\n"
            . "nastaveni     název a popis webu, identita (logo, barva, písma, sítě), časové pásmo, jazyky, šablona, úvodní stránka\n"
            . "stranky       ids, seo_link, titulek, popis, text (HTML), jazyk ('' = výchozí jazyk webu), preklad_z\n"
            . "kategorie     idt, nazev, seo_link, popis, jazyk, preklad_z\n"
            . "stitky        ids, nazev, seo_link, popis\n"
            . "novinky       titulek, uvod a text (HTML), datum, visible (1 = vydaná), tema (= kategorie.idt), jazyk,\n"
            . "              preklad_z (= idc novinky ve výchozím jazyce, jejíž je tato překladem), autor (jméno), stitky (seznam stitky.ids) a další\n"
            . "presmerovani  z_adresy → na_adresu\n"
            . "media         knihovna médií: soubor (cesta ve složce media/), nazev (alternativní text), popis, rozměry\n"
            . "\nAdresy na webu: stránka /<seo_link>, novinka /novinky/<seo_link>, kategorie /novinky/kategorie/<seo_link>,\n"
            . "štítek /novinky/stitek/<seo_link>; další jazykové verze mají předponu /<jazyk>/.\n"
            . "\nCo v exportu záměrně není: hesla a účty uživatelů, klíče a tokeny, údaje k poště a zálohám, statistiky návštěvnosti.\n"
            . "Pro obnovu téhož webu použijte zálohu databáze (Nastavení → Zálohy).\n";
    }
}
