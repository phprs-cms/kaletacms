<?php

declare(strict_types=1);

namespace MiroCMS\Core;

use MiroCMS\Admin\Moduly\Galerie;
use MiroCMS\Admin\Moduly\Presmerovani;
use MiroCMS\Admin\Moduly\Stranky;

/**
 * Import z WordPressu: stránky, příspěvky (→ novinky), kategorie, štítky, přesměrování ze starých adres a (zvlášť) obrázky.
 * Komentáře se nepřenášejí – firemní web je nemá.
 *
 * Jak to drží pohromadě:
 *  - Soubor se čte proudem (Core\WpSoubor) a pracuje se PO DÁVKÁCH – nejvýš DAVKA příspěvků nebo SEKUND vteřin na jeden požadavek,
 *    aby import přežil časové limity sdíleného hostingu. Kde se skončilo (kolikátý <item>), drží stavový soubor
 *    storage/import/stav-<otisk>.json; další požadavek naváže.
 *  - Tabulka rs_import_mapa si pamatuje, který cizí záznam se stal kterým naším. Stejný soubor jde proto pustit znovu bez duplicit
 *    (už převedená novinka se přeskočí a pozdější úpravy se nepřepíší) a obrázky se nestahují dvakrát.
 *  - Průchody jsou tři: náhled (jen počítá, do databáze nesahá), import obsahu a – až na výslovné přání – stažení obrázků.
 *  - Účty se nezakládají: novinka patří tomu, kdo importuje.
 *  - Importované novinky jsou rovnou „oznámené“ – stovky starých textů nesmí spustit webhook ani IndexNow.
 */
final class WpImport
{
    public const int DAVKA = 100;
    public const int DAVKA_OBRAZKU = 10;
    public const int SEKUND = 8;

    /** Výchozí volby importu (krok Náhled). */
    public const array VOLBY = ['jazyk' => '', 'koncepty' => true, 'stranky' => true, 'presmerovani' => true, 'rubrika' => 0];

    /** Typy příspěvků, které umíme; ostatní (menu, vlastní typy doplňků…) náhled jen vyjmenuje. */
    private const array TYPY = ['post', 'page', 'attachment'];

    private string $zdroj = 'wp';

    /** @var array{nazev:string, adresa:string, autori:array<string,string>, rubriky:array<string,array{nazev:string, predek:string}>, stitky:array<string,string>} */
    private array $hlavicka = ['nazev' => '', 'adresa' => '', 'autori' => [], 'rubriky' => [], 'stitky' => []];

    /** @var array<string, int> rubriky převedené v tomto požadavku (adresa rubriky ve WordPressu => naše idt) */
    private array $rubriky = [];

    private int $zbyvaStazeni = 0;
    private float $konec = 0.0;

    /**
     * @param string $zaklad složka webu pro adresy obrázků v textu (Request::basePath(), u webu v kořeni prázdné)
     * @param int $autor účet, kterému budou importované články patřit
     */
    public function __construct(private readonly Db $db, private readonly Settings $nastaveni, private readonly string $zaklad, private readonly int $autor)
    {
    }

    /* ---------- stavový soubor ---------- */

    /** @return array<string, mixed> */
    public static function novyStav(string $soubor): array
    {
        return [
            'soubor' => $soubor, 'faze' => 'analyza', 'pozice' => 0, 'celkem' => 0, 'web' => ['nazev' => '', 'adresa' => ''],
            'prehled' => ['clanky' => [], 'stranky' => [], 'rubriky' => 0, 'stitky' => 0, 'autori' => 0, 'prilohy' => 0, 'obrazky' => 0, 'jine' => [], 'zkratky' => []],
            'prilohy' => [], 'volby' => self::VOLBY, 'nahledy' => [],
            'vysledek' => ['clanky' => 0, 'stranky' => 0, 'rubriky' => 0, 'presmerovani' => 0, 'preskoceno' => 0],
            'obr' => ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => 0, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function nactiStav(string $soubor): ?array
    {
        $cesta = self::souborStavu($soubor);
        $stav = is_file($cesta) ? json_decode((string) file_get_contents($cesta), true) : null;

        return is_array($stav) && ($stav['soubor'] ?? '') === $soubor ? array_replace_recursive(self::novyStav($soubor), $stav) : null;
    }

    /** @param array<string, mixed> $stav */
    public static function ulozStav(array $stav): void
    {
        $cesta = self::souborStavu((string) $stav['soubor']);
        // nejdřív vedle, pak přejmenovat: přerušený zápis nesmí nechat poloviční soubor
        file_put_contents($cesta . '.tmp', (string) json_encode($stav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($cesta . '.tmp', $cesta);
    }

    public static function smazStav(string $soubor): void
    {
        @unlink(self::souborStavu($soubor));
    }

    private static function souborStavu(string $soubor): string
    {
        return WpSoubor::slozka() . '/stav-' . substr(sha1($soubor), 0, 16) . '.json';
    }

    /* ---------- 1. průchod: náhled (nic nezapisuje do databáze) ---------- */

    /**
     * Projde další kus souboru a přičte ho do přehledu. Až dojde na konec, přepne fázi na "nahled".
     *
     * @param array<string, mixed> $stav
     */
    public static function analyzuj(array &$stav, float $sekund = self::SEKUND, ?string $cesta = null): void
    {
        $wp = new WpSoubor($cesta ?? (string) WpSoubor::cesta((string) $stav['soubor'])); // $cesta jen pro testy; jinak vždy soubor ze storage/import
        if ($stav['pozice'] === 0) {
            $h = $wp->hlavicka();
            $stav['web'] = ['nazev' => $h['nazev'], 'adresa' => $h['adresa']];
            $stav['prehled']['rubriky'] = count($h['rubriky']);
            $stav['prehled']['stitky'] = count($h['stitky']);
            $stav['prehled']['autori'] = count($h['autori']);
        }
        $konec = microtime(true) + $sekund;
        foreach ($wp->polozky((int) $stav['pozice']) as $poradi => $p) {
            self::zapocti($stav, $p);
            $stav['pozice'] = $poradi + 1;
            if (microtime(true) > $konec) {
                return;
            }
        }
        $stav['celkem'] = $stav['pozice'];
        $stav['pozice'] = 0;
        $stav['faze'] = 'nahled';
        arsort($stav['prehled']['zkratky']);
        $stav['prehled']['zkratky'] = array_slice($stav['prehled']['zkratky'], 0, 15, true);
    }

    /**
     * @param array<string, mixed> $stav
     * @param array<string, mixed> $p příspěvek z WpSoubor::polozka()
     */
    private static function zapocti(array &$stav, array $p): void
    {
        $prehled = &$stav['prehled'];
        if ($p['typ'] === 'attachment') {
            $prehled['prilohy']++;
            if ($p['priloha_url'] !== '') {
                $stav['prilohy'][(int) $p['id']] = $p['priloha_url']; // pro [gallery ids] a hlavní obrázky článků
            }
        } elseif ($p['typ'] === 'post' || $p['typ'] === 'page') {
            $kam = $p['typ'] === 'post' ? 'clanky' : 'stranky';
            $prehled[$kam][$p['stav']] = ($prehled[$kam][$p['stav']] ?? 0) + 1;
            $prehled['obrazky'] += substr_count(strtolower($p['obsah']), '<img');
            foreach (WpObsah::ciziZkratky($p['obsah']) as $zkratka) {
                $prehled['zkratky'][$zkratka] = ($prehled['zkratky'][$zkratka] ?? 0) + 1;
            }
        } elseif (!in_array($p['typ'], self::TYPY, true)) {
            $prehled['jine'][$p['typ']] = ($prehled['jine'][$p['typ']] ?? 0) + 1;
        }
    }

    /* ---------- čisté převody (hlídá je tools/testy.php) ---------- */

    /**
     * Stav příspěvku ve WordPressu → naše novinka; null = neimportuje se (soukromé, koš, automatické koncepty, revize).
     * Naplánovaný příspěvek je u nás vydaná novinka s budoucím datem; „čeká na schválení“ je koncept.
     *
     * @return array{visible:int}|null
     */
    public static function stavClanku(string $stavWp, bool $chranenHeslem = false): ?array
    {
        $stav = match ($stavWp) {
            'publish', 'future' => ['visible' => 1],
            'draft', 'pending' => ['visible' => 0],
            default => null,
        };

        // příspěvek chráněný heslem u nás nemá obdobu – nesmí se tiše zveřejnit, zůstane jako koncept
        return $stav !== null && $chranenHeslem ? ['visible' => 0] : $stav;
    }

    /**
     * Datum vydání: místní čas starého webu; koncepty ho mívají nulové, pak poslouží čas GMT, datum z RSS, nakonec dnešek.
     *
     * @param array<string, mixed> $p
     */
    public static function datum(array $p, ?int $ted = null): string
    {
        foreach ([$p['datum'] ?? '', $p['datum_gmt'] ?? '', $p['vydano'] ?? ''] as $i => $hodnota) {
            $cas = $hodnota === '' || str_starts_with((string) $hodnota, '0000') ? false : strtotime($hodnota . ($i === 1 ? ' UTC' : ''));
            if ($cas !== false && $cas > 0) {
                return date('Y-m-d H:i:s', $cas);
            }
        }

        return date('Y-m-d H:i:s', $ted ?? time());
    }

    /**
     * Volná adresa (seo_link): když je základ obsazený, dostane pořadové číslo – stejně jako v administraci.
     *
     * @param callable(string): bool $obsazena
     */
    public static function volnaAdresa(string $zaklad, callable $obsazena): string
    {
        $adresa = $zaklad;
        for ($i = 2; $obsazena($adresa); $i++) {
            $adresa = $zaklad . '-' . $i;
        }

        return $adresa;
    }

    /** Cesta staré adresy pro přesměrování (bez domény a lomítek na krajích); prázdná = není co přesměrovat. */
    public static function staraCesta(string $odkaz): string
    {
        $cesta = trim(rawurldecode((string) parse_url($odkaz, PHP_URL_PATH)), '/ ');

        return mb_strlen($cesta) > 255 || !mb_check_encoding($cesta, 'UTF-8') ? '' : $cesta;
    }

    /** Adresa obrázku bez rozměru náhledu a bez parametrů: foto-300x200.jpg?x=1 → foto.jpg (WordPress vkládá do textu zmenšeniny). */
    public static function bezRozmeru(string $adresa): string
    {
        $adresa = (string) preg_replace('/[?#].*$/', '', $adresa);

        return (string) preg_replace('#-\d{2,5}x\d{2,5}(?=\.(?:jpe?g|png|gif|webp)$)#i', '', $adresa);
    }

    /** Označení zdroje v rs_import_mapa: dva různé staré weby mají stejná čísla příspěvků, proto je v něm doména. */
    public static function zdroj(string $adresaWebu): string
    {
        $domena = StahovaniObrazku::domenaZAdresy($adresaWebu);

        return mb_substr($domena === '' ? 'wp' : 'wp:' . $domena, 0, 40);
    }

    /* ---------- 2. průchod: import obsahu ---------- */

    /**
     * Převede další dávku příspěvků. Každý příspěvek je jedna transakce: buď je v databázi celý (s komentáři a mapou), nebo vůbec.
     *
     * @param array<string, mixed> $stav
     */
    public function importuj(array &$stav, ?string $cesta = null, int $davka = self::DAVKA): void
    {
        $wp = new WpSoubor($cesta ?? (string) WpSoubor::cesta((string) $stav['soubor']));
        $this->hlavicka = $wp->hlavicka();
        $this->zdroj = self::zdroj((string) $stav['web']['adresa']);
        $konec = microtime(true) + self::SEKUND;
        $pocet = 0;
        foreach ($wp->polozky((int) $stav['pozice']) as $poradi => $p) {
            $this->db->transaction(function () use ($p, &$stav): void {
                match ($p['typ']) {
                    'post' => $this->clanek($p, $stav),
                    'page' => $stav['volby']['stranky'] ? $this->stranka($p, $stav) : null,
                    default => null,
                };
            });
            $stav['pozice'] = $poradi + 1;
            if ((++$pocet >= $davka || microtime(true) > $konec) && $stav['pozice'] < $stav['celkem']) {
                return; // zbytek příště; počet příspěvků (celkem) zná import z náhledu
            }
        }
        $stav['faze'] = 'hotovo';
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $stav
     */
    private function clanek(array $p, array &$stav): void
    {
        $stavClanku = self::stavClanku($p['stav'], $p['heslo'] !== '');
        if ($stavClanku === null || (!$stavClanku['visible'] && !$stav['volby']['koncepty'])) {
            return;
        }
        $idc = $this->prevedeny('clanek', (string) $p['id'], 'clanky', 'idc');
        if ($idc !== null) {
            $stav['vysledek']['preskoceno']++; // už převedená novinka zůstává, jak je – mezitím ji mohl někdo upravit
        } else {
            $idc = $this->zalozClanek($p, $stavClanku, $stav);
        }
        // hlavní obrázek si zatím jen poznamenáme – stahuje se až ve zvláštním kroku (i u dříve převedené novinky, která ho ještě nemá)
        $nahled = (string) ($stav['prilohy'][$p['nahled']] ?? '');
        if ($nahled !== '' && (string) $this->db->value('SELECT obrazek FROM {clanky} WHERE idc = ?', [$idc]) === '') {
            $stav['nahledy'][$idc] = $nahled;
        }
    }

    /**
     * @param array<string, mixed> $p
     * @param array{visible:int} $stavClanku
     * @param array<string, mixed> $stav
     */
    private function zalozClanek(array $p, array $stavClanku, array &$stav): int
    {
        [$uvod, $text] = WpObsah::perexAText($p['perex'], $p['obsah'], $stav['prilohy']);
        $tema = $p['rubriky'] === [] ? $this->vychoziRubrika($stav) : $this->rubrika((string) array_key_first($p['rubriky']), (string) reset($p['rubriky']), $stav);
        $jazyk = (string) $this->db->value('SELECT jazyk FROM {topic} WHERE idt = ?', [$tema]); // novinka přebírá jazyk kategorie, jako při uložení v administraci
        $titulek = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(bez názvu)'), 0, 255);
        $ted = date('Y-m-d H:i:s');

        $seo = self::volnaAdresa(
            slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $titulek, 150),
            fn (string $adresa): bool => $this->db->value('SELECT idc FROM {clanky} WHERE seo_link = ?', [$adresa]) !== null,
        );
        $idc = $this->db->insert('clanky', [
            'seo_link' => $seo, 'titulek' => $titulek, 'uvod' => $uvod, 'text' => $text, 'tema' => $tema, 'jazyk' => $jazyk,
            'autor' => $this->autor,
            'datum' => self::datum($p),
            'visible' => $stavClanku['visible'],
            'zmeneno' => $ted,
            'oznameno' => $ted, // stará novinka se neoznamuje (webhook, IndexNow)
        ]);
        foreach (array_slice($p['stitky'], 0, 20, true) as $adresa => $nazev) {
            $this->stitek($idc, (string) $adresa, $nazev !== '' ? $nazev : (string) ($this->hlavicka['stitky'][$adresa] ?? $adresa));
        }
        Hledani::indexuj($this->db, $idc);
        Galerie::zapisPouziti($this->db, $idc, '', $uvod, $text);
        $this->zapisMapu('clanek', (string) $p['id'], $idc);
        $stav['vysledek']['clanky']++;

        if ($stav['volby']['presmerovani']) {
            $stav['vysledek']['presmerovani'] += $this->presmeruj($p, ($jazyk !== '' ? $jazyk . '/' : '') . 'novinky/' . $seo);
        }

        return $idc;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $stav
     */
    private function stranka(array $p, array &$stav): void
    {
        $stavClanku = self::stavClanku($p['stav'], $p['heslo'] !== '');
        if ($stavClanku === null || (!$stavClanku['visible'] && !$stav['volby']['koncepty'])) {
            return;
        }
        if ($this->prevedeny('stranka', (string) $p['id'], 'stranky', 'ids') !== null) {
            $stav['vysledek']['preskoceno']++;

            return;
        }
        $titulek = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(bez názvu)'), 0, 200);
        $jazyk = Jazyk::sloupec($this->nastaveni, (string) $stav['volby']['jazyk']);
        // stránka má adresu přímo pod kořenem webu, nesmí proto zabrat adresu, kterou používá systém
        $seo = self::volnaAdresa(
            slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $titulek, 110),
            fn (string $adresa): bool => in_array($adresa, Stranky::VYHRAZENE, true) || isset(Jazyk::DOSTUPNE[$adresa])
                || $this->db->value('SELECT ids FROM {stranky} WHERE seo_link = ?', [$adresa]) !== null,
        );
        $ids = $this->db->insert('stranky', [
            'seo_link' => $seo, 'titulek' => $titulek, 'text' => WpObsah::vycisti($p['obsah'], $stav['prilohy']),
            'popis' => mb_substr(trim(html_entity_decode(strip_tags($p['perex']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300),
            'zobrazit' => $stavClanku['visible'],
            'v_menu' => 0, // desítky starých stránek by zaplavily navigaci; do nabídky si je správce zařadí sám
            'zmeneno' => date('Y-m-d H:i:s'), 'jazyk' => $jazyk,
        ]);
        $this->zapisMapu('stranka', (string) $p['id'], $ids);
        $stav['vysledek']['stranky']++;
        if ($stav['volby']['presmerovani']) {
            $stav['vysledek']['presmerovani'] += $this->presmeruj($p, ($jazyk !== '' ? $jazyk . '/' : '') . $seo);
        }
    }

    /**
     * Kategorie podle adresy kategorie ve WordPressu (strom se zplošťuje); založí ji, když ještě není. Zakládají se jen kategorie s příspěvky.
     *
     * @param array<string, mixed> $stav
     */
    private function rubrika(string $adresa, string $nazev, array &$stav): int
    {
        if (isset($this->rubriky[$adresa])) {
            return $this->rubriky[$adresa];
        }
        $idt = $this->prevedeny('rubrika', $adresa, 'topic', 'idt');
        if ($idt === null) {
            $popis = $this->hlavicka['rubriky'][$adresa] ?? ['nazev' => $nazev, 'predek' => ''];
            $nazev = mb_substr($popis['nazev'] !== '' ? $popis['nazev'] : ($nazev !== '' ? $nazev : $adresa), 0, 100);
            $jazyk = Jazyk::sloupec($this->nastaveni, (string) $stav['volby']['jazyk']);
            $seo = slugify(rawurldecode($adresa), 110);
            // stejná adresa, název i jazyk = tatáž kategorie, která na webu už je; jinak nová s volnou adresou
            $idt = $this->db->value('SELECT idt FROM {topic} WHERE seo_link = ? AND jazyk = ? AND LOWER(nazev) = LOWER(?)', [$seo, $jazyk, $nazev]);
            if ($idt === null) {
                $idt = $this->db->insert('topic', [
                    'nazev' => $nazev, 'popis' => '', 'jazyk' => $jazyk,
                    'seo_link' => self::volnaAdresa($seo, fn (string $a): bool => $this->db->value('SELECT idt FROM {topic} WHERE seo_link = ?', [$a]) !== null),
                ]);
                $stav['vysledek']['rubriky']++;
            }
            $this->zapisMapu('rubrika', $adresa, (int) $idt);
        }

        return $this->rubriky[$adresa] = (int) $idt;
    }

    /**
     * Kategorie pro příspěvky bez kategorie: zvolená v náhledu, jinak se založí „Nezařazené“.
     *
     * @param array<string, mixed> $stav
     */
    private function vychoziRubrika(array &$stav): int
    {
        $idt = (int) $stav['volby']['rubrika'];
        if ($idt > 0 && $this->db->value('SELECT idt FROM {topic} WHERE idt = ?', [$idt]) !== null) {
            return $idt;
        }

        return $stav['volby']['rubrika'] = $this->rubrika('nezarazene', t('Nezařazené'), $stav);
    }

    /** Štítek se hledá podle adresy z názvu a neznámý se založí – stejně jako při uložení novinky v administraci. */
    private function stitek(int $idc, string $adresaWp, string $nazev): void
    {
        $nazev = mb_substr(trim($nazev), 0, 80);
        if ($nazev === '') {
            return;
        }
        $seo = slugify($nazev, 90);
        $ids = $this->db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
        $ids = $ids !== null ? (int) $ids : $this->db->insert('stitky', ['nazev' => $nazev, 'seo_link' => $seo]);
        $this->db->run('INSERT IGNORE INTO {clanky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        $this->zapisMapu('stitek', $adresaWp, $ids);
    }

    /**
     * Přesměrování ze staré adresy (hezké i číselné /?p=123) na novou. Totožnou adresu Presmerovani::pridej přeskočí samo.
     *
     * @param array<string, mixed> $p
     */
    private function presmeruj(array $p, string $nova): int
    {
        $pocet = 0;
        foreach (array_unique([self::staraCesta($p['odkaz']), $p['id'] > 0 ? '?p=' . (int) $p['id'] : '']) as $stara) {
            if ($stara !== '' && $stara !== $nova) {
                Presmerovani::pridej($this->db, $stara, $nova);
                $pocet++;
            }
        }

        return $pocet;
    }

    /* ---------- 3. průchod: obrázky ze starého webu ---------- */

    /**
     * Začátek (nebo opakování) stahování obrázků: počitadla od nuly; co se minule nepovedlo stáhnout, dostane druhou šanci.
     *
     * @param array<string, mixed> $stav
     */
    public function zacniObrazky(array &$stav): void
    {
        $this->zdroj = self::zdroj((string) $stav['web']['adresa']);
        $this->db->run("DELETE FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND nase_id = 0", [$this->zdroj]);
        $celkem = (int) $this->db->value("SELECT COUNT(*) FROM {import_mapa} WHERE zdroj = ? AND typ IN ('clanek', 'stranka')", [$this->zdroj]);
        $stav['obr'] = ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => $celkem, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []];
        $stav['faze'] = 'obrazky';
    }

    /**
     * Stáhne další dávku obrázků: hlavní obrázek článku a obrázky v textu, které leží na doméně starého webu.
     * Pracuje nad už převedenými záznamy (ne nad souborem); pozice = poslední hotový článek nebo stránka.
     *
     * @param array<string, mixed> $stav
     */
    public function obrazky(array &$stav, StahovaniObrazku $stahovani): void
    {
        $this->zdroj = self::zdroj((string) $stav['web']['adresa']);
        $this->zbyvaStazeni = self::DAVKA_OBRAZKU;
        $this->konec = microtime(true) + self::SEKUND;
        while (true) {
            $id = $this->db->value('SELECT MIN(nase_id) FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND nase_id > ?', [$this->zdroj, $stav['obr']['typ'], (int) $stav['obr']['id']]);
            if ($id === null && $stav['obr']['typ'] === 'clanek') {
                $stav['obr'] = ['typ' => 'stranka', 'id' => 0] + $stav['obr']; // po článcích stránky
                continue;
            }
            if ($id === null) {
                $stav['faze'] = 'obrazky-hotovo';

                return;
            }
            if (!$this->obrazkyZaznamu((string) $stav['obr']['typ'], (int) $id, $stav, $stahovani)) {
                return; // dávka je vyčerpaná uprostřed záznamu – příště se pokračuje tímtéž
            }
            $stav['obr']['id'] = (int) $id;
            $stav['obr']['hotovo']++;
            if ($this->zbyvaStazeni <= 0 || microtime(true) > $this->konec) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $stav
     * @return bool false = došel rozpočet dávky, záznam ještě není celý
     */
    private function obrazkyZaznamu(string $typ, int $id, array &$stav, StahovaniObrazku $stahovani): bool
    {
        $zaznam = $typ === 'clanek'
            ? $this->db->one('SELECT idc, titulek, uvod, text, obrazek FROM {clanky} WHERE idc = ?', [$id])
            : $this->db->one("SELECT ids, titulek, '' AS uvod, text, '' AS obrazek FROM {stranky} WHERE ids = ?", [$id]);
        if ($zaznam === null) {
            return true; // záznam mezitím někdo smazal
        }
        $cely = true;
        $nove = ['uvod' => (string) $zaznam['uvod'], 'text' => (string) $zaznam['text'], 'obrazek' => (string) $zaznam['obrazek']];
        foreach (['uvod', 'text'] as $pole) {
            $nove[$pole] = (string) preg_replace_callback('#<img\b[^>]*>#i', function (array $m) use ($stahovani, &$stav, &$cely, $zaznam): string {
                $src = preg_match('#\bsrc="([^"]+)"#i', $m[0], $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5) : '';
                if (!$cely || !$stahovani->povolenaAdresa($src)) {
                    return $m[0]; // cizí obrázky (jiná doména) zůstávají, jak jsou – nestahují se nikdy
                }
                $alt = preg_match('#\balt="([^"]*)"#i', $m[0], $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5) : '';
                $obr = $this->obrazek($src, $alt !== '' ? $alt : (string) $zaznam['titulek'], $stav, $stahovani);
                if ($obr === false) {
                    $cely = false;
                }

                return is_array($obr)
                    ? '<img src="' . e($this->zaklad . '/' . $obr['obr_poloha']) . '" alt="' . e($alt) . '" width="' . (int) $obr['obr_width'] . '" height="' . (int) $obr['obr_height'] . '" loading="lazy" data-id="' . (int) $obr['ido'] . '">'
                    : $m[0];
            }, $nove[$pole]);
        }
        $nahled = (string) ($stav['nahledy'][$id] ?? '');
        if ($typ === 'clanek' && $cely && $nahled !== '') {
            $obr = $this->obrazek($nahled, (string) $zaznam['titulek'], $stav, $stahovani);
            $cely = $obr !== false;
            if (is_array($obr) && $nove['obrazek'] === '') {
                $nove['obrazek'] = (string) $obr['obr_poloha'];
            }
            if ($cely) {
                unset($stav['nahledy'][$id]);
            }
        }
        if ($typ === 'clanek' && $nove !== ['uvod' => $zaznam['uvod'], 'text' => $zaznam['text'], 'obrazek' => $zaznam['obrazek']]) {
            $this->db->update('clanky', $nove, ['idc' => $id]);
            Galerie::zapisPouziti($this->db, $id, $nove['obrazek'], $nove['uvod'], $nove['text']);
        } elseif ($typ === 'stranka' && $nove['text'] !== $zaznam['text']) {
            $this->db->update('stranky', ['text' => $nove['text']], ['ids' => $id]);
        }

        return $cely;
    }

    /**
     * Jeden obrázek: z mapy (už stažený), nebo ze starého webu přes Core\Obrazky do Médií.
     *
     * @param array<string, mixed> $stav
     * @return array<string, mixed>|null|false řádek rs_imggal_obr; null = nejde stáhnout; false = dávka je vyčerpaná
     */
    private function obrazek(string $adresa, string $nazev, array &$stav, StahovaniObrazku $stahovani): array|null|false
    {
        $original = self::bezRozmeru($adresa);
        $klic = sha1($original);
        $ido = $this->db->value("SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND cizi_id = ?", [$this->zdroj, $klic]);
        $radek = $ido === null ? null : $this->db->one('SELECT * FROM {imggal_obr} WHERE ido = ?', [(int) $ido]);
        if ($radek !== null || ($ido !== null && (int) $ido === 0)) {
            return $radek; // hotovo dřív, nebo už jednou selhalo (null)
        }
        if ($this->zbyvaStazeni <= 0 || microtime(true) > $this->konec) {
            return false;
        }
        $this->zbyvaStazeni--;
        $docasny = WpSoubor::slozka() . '/obrazek-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            try {
                $data = $stahovani->stahni($original);
            } catch (\RuntimeException $e) {
                if ($original === $adresa) {
                    throw $e;
                }
                $data = $stahovani->stahni((string) preg_replace('/[?#].*$/', '', $adresa)); // originál chybí, zkusí se aspoň zmenšenina z textu
            }
            file_put_contents($docasny, $data);
            $ulozeny = Obrazky::ulozSoubor($docasny, basename((string) parse_url($original, PHP_URL_PATH)));
            $ulozeny['nazev'] = mb_substr($nazev !== '' ? $nazev : $ulozeny['nazev'], 0, 150);
            $ulozeny['ido'] = $this->db->insert('imggal_obr', $ulozeny + ['vlastnik' => $this->autor, 'datum' => date('Y-m-d H:i:s')]);
            $this->zapisMapu('obrazek', $klic, (int) $ulozeny['ido']);
            $stav['obr']['stazeno']++;

            return $ulozeny;
        } catch (\RuntimeException $e) {
            $this->zapisMapu('obrazek', $klic, 0); // nezkoušet znovu u každého článku, který obrázek používá
            $stav['obr']['chyb']++;
            $stav['obr']['chyby'] = array_slice(array_merge($stav['obr']['chyby'], [mb_substr($original, 0, 200) . ' – ' . t($e->getMessage()) . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')]), -10);

            return null;
        } finally {
            @unlink($docasny);
        }
    }

    /* ---------- mapa cizích a našich záznamů ---------- */

    /** Číslo našeho záznamu, do kterého byl cizí už převeden – jen pokud pořád existuje (smazaný se importuje znovu). */
    private function prevedeny(string $typ, string $ciziId, string $tabulka, string $klic): ?int
    {
        $nase = $this->db->value('SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND cizi_id = ?', [$this->zdroj, $typ, mb_substr($ciziId, 0, 190)]);
        if ($nase === null || $this->db->value('SELECT ' . $klic . ' FROM {' . $tabulka . '} WHERE ' . $klic . ' = ?', [(int) $nase]) === null) {
            return null;
        }

        return (int) $nase;
    }

    private function zapisMapu(string $typ, string $ciziId, int $naseId): void
    {
        $this->db->run(
            'INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nase_id = VALUES(nase_id)',
            [$this->zdroj, $typ, mb_substr($ciziId, 0, 190), $naseId],
        );
    }
}
