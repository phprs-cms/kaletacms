<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Čtení exportu z WordPressu (soubor WXR: Nástroje → Export → Veškerý obsah). Nic nezapisuje, jen čte.
 *
 * Bezpečnost a velikost:
 *  - čte se proudem přes XMLReader, takže ani export o stovkách MB nezabere paměť – v paměti je vždy jediný příspěvek;
 *  - soubor s DOCTYPE se odmítá celý. Export z WordPressu ho nikdy nemá a bez DOCTYPE nejde definovat žádná entita
 *    (ani vnější soubor či adresa – útok XXE, ani „miliarda smíchů“). Navíc se čte s LIBXML_NONET a bez LIBXML_NOENT;
 *  - z komentářů se e-mail ani IP adresa vůbec nečtou, takže se nemohou dostat dál.
 */
final class WpSoubor
{
    public const string SLOZKA = MIROCMS_ROOT . '/storage/import';

    /** Horní mez velikosti souboru: větší export je lepší rozdělit (WordPress to umí podle data nebo autora). */
    public const int MAX_BAJTU = 1024 * 1024 * 1024;

    /** @param string $cesta úplná cesta k souboru na disku */
    public function __construct(private readonly string $cesta)
    {
    }

    /* ---------- složka storage/import ---------- */

    /** Složka pro exporty; vznikne sama i se zákazem přístupu z webu. */
    public static function slozka(): string
    {
        if (!is_dir(self::SLOZKA) && !@mkdir(self::SLOZKA, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit složku storage/import – zkontrolujte práva k zápisu.');
        }
        if (!is_file(self::SLOZKA . '/.htaccess')) {
            @file_put_contents(self::SLOZKA . '/.htaccess', "Require all denied\n");
        }

        return self::SLOZKA;
    }

    /**
     * Soubory *.xml, které ve složce leží (nahrané formulářem i přes FTP), nejnovější nahoře.
     *
     * @return list<array{soubor:string, velikost:int, cas:int}>
     */
    public static function seznam(): array
    {
        $soubory = [];
        foreach (glob(self::SLOZKA . '/*.{xml,XML}', GLOB_BRACE) ?: [] as $cesta) {
            if (self::platnyNazev(basename($cesta))) {
                $soubory[] = ['soubor' => basename($cesta), 'velikost' => (int) filesize($cesta), 'cas' => (int) filemtime($cesta)];
            }
        }
        usort($soubory, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $soubory;
    }

    /** Název souboru z formuláře nesmí vést jinam než do složky importu. */
    public static function platnyNazev(string $soubor): bool
    {
        return $soubor !== '' && strlen($soubor) <= 150 && basename($soubor) === $soubor && !str_starts_with($soubor, '.')
            && !preg_match('#[/\\\\\x00-\x1f]#', $soubor) && strtolower(pathinfo($soubor, PATHINFO_EXTENSION)) === 'xml';
    }

    /** Cesta k existujícímu souboru podle názvu z formuláře; null = neplatný název nebo soubor není. */
    public static function cesta(string $soubor): ?string
    {
        return self::platnyNazev($soubor) && is_file(self::SLOZKA . '/' . $soubor) ? self::SLOZKA . '/' . $soubor : null;
    }

    /** Bezpečný název pro nahraný soubor: bez diakritiky a mezer, vždy s příponou .xml. */
    public static function nazevProNahrani(string $puvodni): string
    {
        return slugify(pathinfo($puvodni, PATHINFO_FILENAME), 80) . '.xml';
    }

    /* ---------- ověření a čtení ---------- */

    /**
     * Rychlé ověření před přijetím souboru: přípona, velikost, správně utvořený začátek XML a jmenný prostor exportu WordPressu.
     * Zbytek souboru se ověří při prvním průchodu (náhled) – chyba v XML ho zastaví s číslem řádku.
     *
     * @throws \RuntimeException s českou hláškou pro uživatele
     */
    public function over(): void
    {
        if (strtolower(pathinfo($this->cesta, PATHINFO_EXTENSION)) !== 'xml') {
            throw new \RuntimeException('Soubor musí mít příponu .xml – je to export z WordPressu (Nástroje → Export).');
        }
        $this->overObsah();
    }

    /**
     * Totéž bez kontroly přípony – pro právě nahraný soubor, který ještě leží v dočasné složce pod náhodným jménem.
     *
     * @throws \RuntimeException
     */
    public function overObsah(): void
    {
        $velikost = (int) @filesize($this->cesta);
        if ($velikost === 0 || $velikost > self::MAX_BAJTU) {
            throw new \RuntimeException('Soubor je prázdný nebo větší než 1 GB. Velký web exportujte z WordPressu po částech (podle data).');
        }
        $this->hlavicka();
    }

    /**
     * Údaje ze začátku souboru (před prvním příspěvkem): starý web, autoři, rubriky, štítky.
     *
     * @return array{nazev:string, adresa:string, autori:array<string,string>, rubriky:array<string,array{nazev:string, predek:string}>, stitky:array<string,string>}
     */
    public function hlavicka(): array
    {
        $h = ['nazev' => '', 'adresa' => '', 'autori' => [], 'rubriky' => [], 'stitky' => []];
        $odkaz = '';
        $ctecka = $this->otevri();
        try {
            $this->najdiKanal($ctecka);
            // potomci <channel>: čtou se jeden po druhém až k prvnímu <item>
            $dal = $this->cti($ctecka);
            while ($dal && !($ctecka->nodeType === \XMLReader::END_ELEMENT && $ctecka->depth === 1)) {
                if ($ctecka->nodeType !== \XMLReader::ELEMENT || $ctecka->depth !== 2) {
                    $dal = $this->cti($ctecka);
                    continue;
                }
                if ($ctecka->name === 'item') {
                    break;
                }
                $pole = self::pole($this->uzel($ctecka));
                match ($ctecka->name) {
                    'title' => $h['nazev'] = self::prostyText($pole['title'] ?? ''),
                    'link' => $odkaz = trim($pole['link'] ?? ''),
                    'wp:base_site_url' => $h['adresa'] = trim($pole['wp:base_site_url'] ?? ''),
                    'wp:author' => $h['autori'][(string) ($pole['wp:author_login'] ?? '')] = self::prostyText(($pole['wp:author_display_name'] ?? '') !== '' ? $pole['wp:author_display_name'] : ($pole['wp:author_login'] ?? '')),
                    'wp:category' => $h['rubriky'][(string) ($pole['wp:category_nicename'] ?? '')] = ['nazev' => self::prostyText($pole['wp:cat_name'] ?? ''), 'predek' => (string) ($pole['wp:category_parent'] ?? '')],
                    'wp:tag' => $h['stitky'][(string) ($pole['wp:tag_slug'] ?? '')] = self::prostyText($pole['wp:tag_name'] ?? ''),
                    default => null,
                };
                $dal = $this->dalsi($ctecka);
            }
        } finally {
            $ctecka->close();
        }
        // adresa starého webu: <link> kanálu je adresa, na které web opravdu běžel; base_site_url jen jako záloha
        $h['adresa'] = $odkaz !== '' ? $odkaz : $h['adresa'];
        unset($h['autori'][''], $h['rubriky'][''], $h['stitky']['']);

        return $h;
    }

    /**
     * Příspěvky souboru (články, stránky, přílohy…) jeden po druhém. Klíčem je pořadí od nuly – podle něj import ví, kde minule skončil.
     *
     * @param int $preskocit kolik příspěvků od začátku přeskočit (přeskakuje se rychle, bez rozebírání obsahu)
     * @return \Generator<int, array<string, mixed>>
     */
    public function polozky(int $preskocit = 0): \Generator
    {
        $ctecka = $this->otevri();
        try {
            $this->najdiKanal($ctecka);
            $poradi = 0;
            $dal = $this->cti($ctecka);
            while ($dal) {
                if ($ctecka->nodeType === \XMLReader::ELEMENT && $ctecka->depth === 2) {
                    if ($ctecka->name === 'item') {
                        if ($poradi >= $preskocit) {
                            yield $poradi => self::polozka($this->uzel($ctecka));
                        }
                        $poradi++;
                    }
                    $dal = $this->dalsi($ctecka);
                } else {
                    $dal = $this->cti($ctecka);
                }
            }
        } finally {
            $ctecka->close();
        }
    }

    /**
     * Rozebere jeden <item>. E-mail a IP adresa komentujících se záměrně vůbec nečtou.
     *
     * @return array<string, mixed>
     */
    public static function polozka(\DOMElement $item): array
    {
        $p = [
            'id' => 0, 'typ' => 'post', 'stav' => '', 'titulek' => '', 'odkaz' => '', 'adresa' => '', 'datum' => '', 'datum_gmt' => '', 'vydano' => '',
            'autor' => '', 'obsah' => '', 'perex' => '', 'heslo' => '', 'pripnuty' => false, 'priloha_url' => '', 'nahled' => 0,
            'rubriky' => [], 'stitky' => [], 'komentare' => [],
        ];
        foreach ($item->childNodes as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }
            $text = $n->textContent;
            switch ($n->nodeName) {
                case 'title': $p['titulek'] = self::prostyText($text); break;
                case 'link': $p['odkaz'] = trim($text); break;
                case 'pubDate': $p['vydano'] = trim($text); break;
                case 'dc:creator': $p['autor'] = trim($text); break;
                case 'content:encoded': $p['obsah'] = $text; break;
                case 'excerpt:encoded': $p['perex'] = $text; break;
                case 'wp:post_id': $p['id'] = (int) $text; break;
                case 'wp:post_date': $p['datum'] = trim($text); break;
                case 'wp:post_date_gmt': $p['datum_gmt'] = trim($text); break;
                case 'wp:post_name': $p['adresa'] = trim($text); break;
                case 'wp:status': $p['stav'] = trim($text); break;
                case 'wp:post_type': $p['typ'] = trim($text); break;
                case 'wp:post_password': $p['heslo'] = trim($text); break;
                case 'wp:is_sticky': $p['pripnuty'] = trim($text) === '1'; break;
                case 'wp:attachment_url': $p['priloha_url'] = trim($text); break;
                case 'category':
                    $druh = $n->getAttribute('domain') === 'post_tag' ? 'stitky' : ($n->getAttribute('domain') === 'category' ? 'rubriky' : '');
                    if ($druh !== '' && $n->getAttribute('nicename') !== '') {
                        $p[$druh][$n->getAttribute('nicename')] = self::prostyText($text);
                    }
                    break;
                case 'wp:postmeta':
                    $meta = self::pole($n);
                    if (($meta['wp:meta_key'] ?? '') === '_thumbnail_id') {
                        $p['nahled'] = (int) ($meta['wp:meta_value'] ?? 0);
                    }
                    break;
                case 'wp:comment':
                    $k = self::pole($n);
                    $p['komentare'][] = [
                        'id' => (int) ($k['wp:comment_id'] ?? 0), 'autor' => self::prostyText($k['wp:comment_author'] ?? ''), 'datum' => trim($k['wp:comment_date'] ?? ''),
                        'text' => (string) ($k['wp:comment_content'] ?? ''), 'predek' => (int) ($k['wp:comment_parent'] ?? 0),
                        'schvalen' => trim($k['wp:comment_approved'] ?? '') === '1', 'typ' => trim($k['wp:comment_type'] ?? ''),
                    ];
                    break;
            }
        }

        return $p;
    }

    /* ---------- vnitřní pomůcky ---------- */

    private function otevri(): \XMLReader
    {
        if (!class_exists(\XMLReader::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('Na serveru chybí rozšíření PHP xmlreader nebo dom – bez nich nejde export z WordPressu přečíst.');
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        // LIBXML_NONET: nic se nenačítá ze sítě. Záměrně BEZ LIBXML_NOENT (nahrazování entit) a BEZ LIBXML_DTDLOAD.
        $ctecka = new \XMLReader();
        if (!@$ctecka->open($this->cesta, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Soubor se nepodařilo otevřít.');
        }

        return $ctecka;
    }

    /** Posune čtečku na <channel> a cestou ověří, že jde o export WordPressu bez DOCTYPE. */
    private function najdiKanal(\XMLReader $ctecka): void
    {
        while ($this->cti($ctecka)) {
            if ($ctecka->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($ctecka->depth === 0) {
                $prostor = (string) $ctecka->getAttribute('xmlns:wp');
                if ($ctecka->name !== 'rss' || !preg_match('#^https?://wordpress\.org/export/\d+\.\d+/?$#', $prostor)) {
                    throw new \RuntimeException('Tohle není export z WordPressu. Ve WordPressu otevřete Nástroje → Export, zvolte „Veškerý obsah“ a stáhněte soubor .xml.');
                }
            } elseif ($ctecka->depth === 1 && $ctecka->name === 'channel') {
                return;
            }
        }
        throw new \RuntimeException('V souboru chybí obsah webu (značka channel).');
    }

    /** Jeden krok čtečky; hlídá DOCTYPE a chyby v XML. */
    private function cti(\XMLReader $ctecka): bool
    {
        return $this->zkontroluj($ctecka, @$ctecka->read());
    }

    /** Přeskočí celý právě čtený prvek (i s potomky) na jeho dalšího sourozence. */
    private function dalsi(\XMLReader $ctecka): bool
    {
        return $this->zkontroluj($ctecka, @$ctecka->next());
    }

    private function zkontroluj(\XMLReader $ctecka, bool $ok): bool
    {
        if ($ok && ($ctecka->nodeType === \XMLReader::DOC_TYPE || $ctecka->nodeType === \XMLReader::ENTITY_REF || $ctecka->nodeType === \XMLReader::ENTITY)) {
            throw new \RuntimeException('Soubor obsahuje DOCTYPE nebo vlastní entity. Export z WordPressu nic takového nemá – soubor byl z bezpečnostních důvodů odmítnut.');
        }
        $chyba = libxml_get_last_error();
        if (!$ok && $chyba !== false) {
            libxml_clear_errors();
            throw new \RuntimeException('Soubor není platné XML – je poškozený nebo neúplný. Stáhněte export z WordPressu znovu. Chyba je na řádku:', $chyba->line);
        }

        return $ok;
    }

    /** Právě čtený prvek jako samostatný uzel DOM (jen tento jeden prvek – zbytek souboru v paměti není). */
    private function uzel(\XMLReader $ctecka): \DOMElement
    {
        $uzel = @$ctecka->expand(new \DOMDocument());
        if (!$uzel instanceof \DOMElement) {
            $chyba = libxml_get_last_error();
            libxml_clear_errors();
            throw new \RuntimeException('Soubor není platné XML – je poškozený nebo neúplný. Stáhněte export z WordPressu znovu. Chyba je na řádku:', $chyba !== false ? $chyba->line : 0);
        }
        foreach ($uzel->getElementsByTagName('*') as $potomek) {
            foreach ($potomek->childNodes as $n) {
                if ($n instanceof \DOMEntityReference) {
                    throw new \RuntimeException('Soubor obsahuje DOCTYPE nebo vlastní entity. Export z WordPressu nic takového nemá – soubor byl z bezpečnostních důvodů odmítnut.');
                }
            }
        }

        return $uzel;
    }

    /**
     * Přímí potomci prvku jako pole "název značky => text".
     *
     * @return array<string, string>
     */
    private static function pole(\DOMElement $prvek): array
    {
        $pole = [$prvek->nodeName => $prvek->textContent];
        foreach ($prvek->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                $pole[$n->nodeName] = $n->textContent;
            }
        }

        return $pole;
    }

    /** Titulky a jména: bez značek, entity převedené na znaky, bez okrajových mezer. */
    private static function prostyText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
