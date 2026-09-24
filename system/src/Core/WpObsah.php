<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Převod obsahu příspěvku z WordPressu na HTML, jaké píše editor článků MiroCMS. Jen převádí text – nic nečte ani nezapisuje.
 *
 * Pořadí kroků: bloky Gutenbergu → zkratky v hranatých závorkách ([caption], [gallery]…) → vložená videa → odstavce
 * (starý „klasický“ editor je odděluje jen prázdným řádkem) → propuštění jen povolených značek a atributů.
 * Obsah exportu se bere jako nedůvěryhodný: co není na seznamu POVOLENE, do článku se nedostane (skripty, rámce, styly, onclick…).
 */
final class WpObsah
{
    /** Stejná podmnožina HTML, jakou propouští editor článků při vkládání (image/editor.js, POVOLENE) – jen bez IFRAME. */
    private const array POVOLENE = [
        'p' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'br' => [], 'hr' => [],
        'a' => ['href', 'title', 'target'], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'code' => [], 'pre' => [],
        'figure' => ['class'], 'figcaption' => [], 'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
    ];

    /** Značky, které editor nezná, ale mají blízkou náhradu. */
    private const array PREVOD = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    /** Tyhle značky mizí i s obsahem (u ostatních nepovolených zůstává aspoň jejich text). */
    private const array ZAHODIT = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'noscript', 'svg', 'math', 'template', 'head', 'title', 'meta', 'link', 'base',
        'input', 'button', 'select', 'textarea', 'video', 'audio', 'canvas', 'frame', 'frameset'];

    /** Značky, které tvoří vlastní odstavec – kolem nich se <p> nedělá. */
    private const string BLOKOVE = 'table|thead|tfoot|tbody|tr|td|th|caption|div|dl|dd|dt|ul|ol|li|pre|blockquote|figure|figcaption|h[1-6]|hr|p|address|section|article|aside|header|footer|nav|details|summary';

    /** Zkratky, které převádíme sami, a proto na ně náhled neupozorňuje. */
    private const array ZNAME_ZKRATKY = ['caption', 'wp_caption', 'gallery', 'embed'];

    /** Zkratky WordPressu bez atributů a párové značky, které by jinak vypadaly jako běžný text v závorce. */
    private const array VZDY_ZKRATKY = ['audio', 'video', 'playlist', 'more', 'toc', 'contact-form-7', 'contact-form', 'sitemap', 'products', 'recent_posts'];

    /**
     * @param array<int|string, string> $prilohy čísla příloh WordPressu => adresa souboru (pro [gallery ids="…"])
     */
    public static function vycisti(string $obsah, array $prilohy = []): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $obsah);
        $blokovy = str_contains($html, '<!-- wp:'); // Gutenberg má odstavce hotové, klasický editor ne
        $html = self::bloky($html);
        $html = self::mimoKod($html, fn (string $cast): string => self::zkratkyNaHtml($cast, $prilohy));
        $html = self::vlozenaVidea($html);
        if (!$blokovy) {
            $html = self::odstavce($html);
        }

        return self::povoleneHtml($html);
    }

    /**
     * Perex a text článku. Perex je ruční výtah z WordPressu; když chybí, vezme se část před značkou „Číst dál“ (<!--more-->),
     * a když není ani ta, první odstavec textu – ten se pak v textu neopakuje.
     *
     * @param array<int|string, string> $prilohy
     * @return array{0:string, 1:string} [perex, text]
     */
    public static function perexAText(string $perex, string $obsah, array $prilohy = []): array
    {
        $perex = trim(html_entity_decode(strip_tags($perex), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($perex !== '') {
            return ['<p>' . nl2br(e($perex), false) . '</p>', self::vycisti($obsah, $prilohy)];
        }
        $casti = preg_split('/<!--\s*more\b.*?-->/s', $obsah, 2) ?: [$obsah];
        if (count($casti) === 2 && trim(strip_tags($casti[0])) !== '') {
            return [self::vycisti($casti[0], $prilohy), self::vycisti($casti[1], $prilohy)];
        }
        $text = self::vycisti($obsah, $prilohy);
        // první odstavec, ve kterém je skutečný text
        if (preg_match('#<p>((?:(?!</?p>).)*?\p{L}(?:(?!</?p>).)*?)</p>\s*#su', $text, $m, PREG_OFFSET_CAPTURE)) {
            return ['<p>' . $m[1][0] . '</p>', trim(substr($text, 0, $m[0][1]) . substr($text, $m[0][1] + strlen($m[0][0])))];
        }

        return ['', $text];
    }

    /**
     * Zkratky doplňků (plug-inů), které v obsahu jsou a které import neumí převést – pro varování v náhledu.
     *
     * @return list<string> názvy zkratek
     */
    public static function ciziZkratky(string $obsah): array
    {
        $nalezene = [];
        $obsah = (string) preg_replace('#<pre\b.*?</pre>|<code\b.*?</code>#is', '', $obsah); // v ukázkách kódu jsou závorky běžný text
        preg_match_all('#\[/([a-zA-Z][\w-]*)\]#', $obsah, $parove);
        if (preg_match_all('#(?<!\[)\[([a-zA-Z][\w-]*)((?:\s[^\]]*)?)/?\]#', $obsah, $m, PREG_SET_ORDER)) {
            foreach ($m as $z) {
                if (!in_array(strtolower($z[1]), self::ZNAME_ZKRATKY, true) && self::jeZkratka($z[1], $z[2], $parove[1])) {
                    $nalezene[strtolower($z[1])] = true;
                }
            }
        }

        return array_keys($nalezene);
    }

    /* ---------- bloky Gutenbergu ---------- */

    private static function bloky(string $html): string
    {
        // fotogalerie: z bloku zůstanou jen obrázky, složené do naší galerie
        $html = preg_replace_callback('#<!--\s*wp:gallery\b.*?-->(.*?)<!--\s*/wp:gallery\s*-->#s', function (array $m): string {
            preg_match_all('#<img\b[^>]*>#i', $m[1], $obrazky);

            return self::galerie($obrazky[0]);
        }, $html) ?? $html;
        // vložené video či příspěvek: adresa je v nastavení bloku; u nás stačí adresa na samostatném řádku (Front\TextNovinky)
        $html = preg_replace_callback('#<!--\s*wp:(?:core-embed/[\w-]+|embed)\s+(\{.*?\})\s*-->.*?<!--\s*/wp:(?:core-embed/[\w-]+|embed)\s*-->#s', function (array $m): string {
            $adresa = (string) (json_decode($m[1], true)['url'] ?? '');

            return preg_match('#^https?://[^\s<>"]+$#i', $adresa) ? "\n<p>" . e($adresa) . "</p>\n" : '';
        }, $html) ?? $html;

        // ostatní komentáře (hranice bloků, <!--more-->, <!--nextpage-->) už nic neznamenají
        return preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    }

    /** @param list<string> $znackyImg hotové značky <img …> */
    private static function galerie(array $znackyImg): string
    {
        return $znackyImg === [] ? '' : "\n" . '<figure class="galerie">' . implode('', $znackyImg) . '</figure>' . "\n";
    }

    /* ---------- zkratky v hranatých závorkách ---------- */

    /** @param array<int|string, string> $prilohy */
    private static function zkratkyNaHtml(string $html, array $prilohy): string
    {
        // [caption]<img> Popisek[/caption] → obrázek s popiskem
        $html = preg_replace_callback('#\[(?:wp_)?caption\b([^\]]*)\](.*?)\[/(?:wp_)?caption\]#s', function (array $m): string {
            if (!preg_match('#<img\b[^>]*>#i', $m[2], $img)) {
                return $m[2];
            }
            $popisek = preg_match('#\bcaption="([^"]*)"#', $m[1], $a) ? $a[1] : trim((string) preg_replace('#<a\b[^>]*>\s*</a>#i', '', str_replace($img[0], '', $m[2])));

            return "\n\n<figure>" . $img[0] . ($popisek !== '' ? '<figcaption>' . $popisek . '</figcaption>' : '') . "</figure>\n\n";
        }, $html) ?? $html;

        // [gallery ids="1,2,3"] → naše galerie; obrázky se dohledají mezi přílohami exportu
        $html = preg_replace_callback('#\[gallery\b([^\]]*)\]#', function (array $m) use ($prilohy): string {
            $obrazky = [];
            foreach (preg_match('#\bids="([\d,\s]+)"#', $m[1], $a) ? explode(',', $a[1]) : [] as $id) {
                $adresa = $prilohy[(int) $id] ?? '';
                if (preg_match('#\.(jpe?g|png|gif|webp)$#i', (string) parse_url($adresa, PHP_URL_PATH))) {
                    $obrazky[] = '<img src="' . e($adresa) . '" alt="">';
                }
            }

            return "\n\n" . self::galerie($obrazky) . "\n\n";
        }, $html) ?? $html;

        // [embed]adresa[/embed] → adresa na samostatném řádku
        $html = preg_replace('#\[embed[^\]]*\]\s*(https?://[^\s\[]+)\s*\[/embed\]#i', "\n\n$1\n\n", $html) ?? $html;

        // zbylé zkratky doplňků: značka zmizí, text uvnitř zůstává
        preg_match_all('#\[/([a-zA-Z][\w-]*)\]#', $html, $parove);

        return preg_replace_callback('#(?<!\[)\[(/?)([a-zA-Z][\w-]*)((?:\s[^\]]*)?)/?\]#', function (array $z) use ($parove): string {
            return $z[1] === '/' || self::jeZkratka($z[2], $z[3], $parove[1]) ? '' : $z[0];
        }, $html) ?? $html;
    }

    /**
     * Pozná zkratku od běžného textu v závorce ([sic], [1]): zkratka má atributy, uzavírací značku, podtržítko či pomlčku v názvu,
     * nebo je na seznamu známých.
     *
     * @param list<string> $parove názvy, ke kterým v textu existuje uzavírací [/název]
     */
    private static function jeZkratka(string $nazev, string $atributy, array $parove): bool
    {
        return str_contains($atributy, '=') || in_array($nazev, $parove, true) || preg_match('/[_-]/', $nazev) === 1
            || in_array(strtolower($nazev), self::VZDY_ZKRATKY, true) || in_array(strtolower($nazev), self::ZNAME_ZKRATKY, true);
    }

    /** Přehrávače vložené jako <iframe> se mění na adresu videa na samostatném řádku; ostatní rámce později zmizí. */
    private static function vlozenaVidea(string $html): string
    {
        return preg_replace_callback('#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>.*?</iframe>#is', function (array $m): string {
            $adresa = match (true) {
                (bool) preg_match('#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})#', $m[1], $v) => 'https://www.youtube.com/watch?v=' . $v[1],
                (bool) preg_match('#player\.vimeo\.com/video/(\d+)#', $m[1], $v) => 'https://vimeo.com/' . $v[1],
                (bool) preg_match('#open\.spotify\.com/embed/(episode|show|track)/([A-Za-z0-9]+)#', $m[1], $v) => 'https://open.spotify.com/' . $v[1] . '/' . $v[2],
                default => '',
            };

            return $adresa === '' ? '' : "\n\n<p>" . $adresa . "</p>\n\n";
        }, $html) ?? $html;
    }

    /* ---------- odstavce klasického editoru ---------- */

    /**
     * Klasický editor WordPressu ukládá odstavce jen jako text oddělený prázdným řádkem; značky <p> doplňuje až při zobrazení.
     * Tady se doplní natrvalo: prázdný řádek = nový odstavec, jednoduchý konec řádku = <br>. Blokové značky se neobalují.
     */
    public static function odstavce(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        // <pre> se nesmí dotknout nic – schová se a na konci vrátí
        $schovane = [];
        $text = preg_replace_callback('#<pre\b.*?</pre>#is', function (array $m) use (&$schovane): string {
            $klic = "\x02PRE" . count($schovane) . "\x03";
            $schovane[$klic] = $m[0];

            return "\n\n" . $klic . "\n\n";
        }, $text) ?? $text;
        // adresa sama na řádku (video z YouTube…) má být vlastním odstavcem, aby ji web poznal
        $text = preg_replace('#^[ \t]*(https?://[^\s<>"]+)[ \t]*$#m', "\n\n$1\n\n", $text) ?? $text;
        $text = preg_replace('#<br\s*/?>\s*<br\s*/?>#i', "\n\n", $text) ?? $text;
        $text = preg_replace('#(<(?:' . self::BLOKOVE . ')(?:\s[^>]*)?/?>)#i', "\n\n$1", $text) ?? $text;
        $text = preg_replace('#(</(?:' . self::BLOKOVE . ')>)#i', "$1\n\n", $text) ?? $text;

        $html = '';
        foreach (preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $cast) {
            $cast = trim($cast);
            if ($cast === '' || isset($schovane[$cast]) || preg_match('#^</?(?:' . self::BLOKOVE . ')\b#i', $cast)) {
                $html .= $cast . "\n";
            } elseif (preg_match('#^(.*?)((?:</(?:' . self::BLOKOVE . ')>\s*)+)$#is', $cast, $m)) {
                $html .= '<p>' . self::zalomeni($m[1]) . '</p>' . $m[2] . "\n"; // text těsně před koncem bloku: „…text</div>“
            } else {
                $html .= '<p>' . self::zalomeni($cast) . "</p>\n";
            }
        }

        return strtr($html, $schovane);
    }

    private static function zalomeni(string $text): string
    {
        return (string) preg_replace('#(?<!<br>)\n#', "<br>\n", trim((string) preg_replace('#<br\s*/?>[ \t]*\n?#i', "<br>\n", $text)));
    }

    /* ---------- jen povolené značky ---------- */

    /** Propustí jen značky a atributy ze seznamu POVOLENE; HTML čte skutečný analyzátor HTML5, ne regulární výrazy. */
    private static function povoleneHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $telo = $doc->body;
        if ($telo === null) {
            return '';
        }
        self::vycistiUzel($doc, $telo);
        self::srovnejKoren($doc, $telo);
        $vystup = '';
        foreach ($telo->childNodes as $n) {
            $vystup .= $doc->saveHtml($n) . ($n instanceof \Dom\Element ? "\n" : '');
        }
        $vystup = str_replace(['&nbsp;', "\u{00A0}"], ' ', $vystup);

        // úklid mimo ukázky kódu: zalomení na kraji odstavce a prázdné řádky nic neznamenají
        return trim(self::mimoKod($vystup, fn (string $cast): string => (string) preg_replace(['#<p>(?:\s*<br>)+\s*#', '#(?:\s*<br>)+\s*</p>#', "/\n{2,}/"], ['<p>', '</p>', "\n"], $cast)));
    }

    private static function vycistiUzel(\Dom\HTMLDocument $doc, \Dom\Node $uzel): void
    {
        foreach (iterator_to_array($uzel->childNodes) as $n) {
            if (!$n instanceof \Dom\Element) {
                // komentáře a instrukce pryč; stejně tak prázdné řádky mezi obrázkem a popiskem
                if (!$n instanceof \Dom\Text || ($uzel instanceof \Dom\Element && $uzel->localName === 'figure' && trim($n->data) === '')) {
                    $n->parentNode?->removeChild($n);
                }
                continue;
            }
            $tag = strtolower($n->localName);
            if (in_array($tag, self::ZAHODIT, true)) {
                $n->remove();
                continue;
            }
            self::vycistiUzel($doc, $n);
            if ($tag === 'a' && self::jeOdkazNaVlastniObrazek($n)) {
                self::rozbal($n); // náhled odkazující na velký obrázek: prohlížečku fotek má web vlastní
                continue;
            }
            if ($tag === 'div' || isset(self::PREVOD[$tag])) {
                // <div> s bloky uvnitř jen zmizí, <div> s textem je odstavec
                $novy = $tag === 'div' ? (self::maBlokovehoPotomka($n) ? null : 'p') : self::PREVOD[$tag];
                if ($novy === null) {
                    self::rozbal($n);
                    continue;
                }
                $n = self::prejmenuj($doc, $n, $novy);
                $tag = $novy;
            }
            if (!isset(self::POVOLENE[$tag])) {
                self::rozbal($n);
                continue;
            }
            self::vycistiAtributy($n, $tag);
            if ($tag === 'a' && !$n->hasAttribute('href')) {
                self::rozbal($n); // odkaz, kterému nezbyla bezpečná adresa, je jen text
            }
        }
    }

    private static function vycistiAtributy(\Dom\Element $n, string $tag): void
    {
        if ($tag === 'img') {
            // doplňky pro líné načítání dávají skutečnou adresu do data-src
            $adresa = $n->getAttribute('data-src') ?? $n->getAttribute('data-lazy-src') ?? $n->getAttribute('src') ?? '';
            if (!self::bezpecnaAdresa($adresa) || str_starts_with(strtolower(trim($adresa)), 'data:')) {
                $n->remove();

                return;
            }
            $n->setAttribute('src', trim($adresa));
        }
        foreach (iterator_to_array($n->attributes) as $a) {
            $nazev = strtolower($a->name);
            $ok = in_array($nazev, self::POVOLENE[$tag], true) && match ($nazev) {
                'href', 'src' => self::bezpecnaAdresa($a->value),
                'width', 'height', 'colspan', 'rowspan' => ctype_digit($a->value),
                'target' => $a->value === '_blank',
                'class' => preg_match('/(^|\s)galerie(\s|$)/', $a->value) === 1,
                default => true,
            };
            if (!$ok) {
                $n->removeAttribute($a->name);
            }
        }
        if ($tag === 'figure' && $n->hasAttribute('class')) {
            $n->setAttribute('class', 'galerie');
        }
        if ($tag === 'a' && $n->hasAttribute('target')) {
            $n->setAttribute('rel', 'noopener');
        }
        if ($tag === 'img') {
            $n->setAttribute('alt', (string) $n->getAttribute('alt'));
            $n->setAttribute('loading', 'lazy');
        }
    }

    /** Adresa smí být http(s), mailto, tel nebo místní; javascript:, data:, vbscript: a podobné ne (ani s vloženými mezerami a tabulátory). */
    public static function bezpecnaAdresa(string $adresa): bool
    {
        $adresa = (string) preg_replace('/[\x00-\x20]+/', '', $adresa);

        return $adresa !== '' && (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $adresa) || preg_match('#^(https?|mailto|tel):#i', $adresa) === 1);
    }

    /** Po vyčištění nesmí na nejvyšší úrovni zůstat volný text ani samotný obrázek: text patří do <p>, obrázek do <figure>. */
    private static function srovnejKoren(\Dom\HTMLDocument $doc, \Dom\Element $telo): void
    {
        $odstavec = null;
        foreach (iterator_to_array($telo->childNodes) as $n) {
            $radkovy = $n instanceof \Dom\Text || ($n instanceof \Dom\Element && in_array(strtolower($n->localName), ['a', 'strong', 'em', 'b', 'i', 'u', 's', 'sub', 'sup', 'br', 'code'], true));
            if ($n instanceof \Dom\Element && strtolower($n->localName) === 'img') {
                $obal = $doc->createElement('figure');
                $telo->replaceChild($obal, $n);
                $obal->appendChild($n);
                $odstavec = null;
            } elseif ($radkovy) {
                if ($odstavec === null) {
                    if ($n instanceof \Dom\Text && trim($n->data) === '') {
                        $n->remove();
                        continue;
                    }
                    $odstavec = $doc->createElement('p');
                    $telo->insertBefore($odstavec, $n);
                }
                $odstavec->appendChild($n);
            } else {
                $odstavec = null;
            }
        }
        foreach (iterator_to_array($telo->childNodes) as $n) {
            if (!$n instanceof \Dom\Element || strtolower($n->localName) !== 'p') {
                continue;
            }
            $obrazky = [];
            foreach ($n->childNodes as $potomek) {
                if ($potomek instanceof \Dom\Element && strtolower($potomek->localName) === 'img') {
                    $obrazky[] = $potomek;
                }
            }
            $text = trim(str_replace("\u{00A0}", ' ', (string) $n->textContent));
            if ($text === '' && $obrazky === []) {
                $n->remove(); // prázdný odstavec
            } elseif ($text === '' && count($obrazky) === 1) {
                $obal = $doc->createElement('figure'); // odstavec jen s obrázkem = obrázek, jak ho vkládá editor
                $obal->appendChild($obrazky[0]);
                $telo->replaceChild($obal, $n);
            }
        }
    }

    private static function jeOdkazNaVlastniObrazek(\Dom\Element $a): bool
    {
        $potomci = array_values(array_filter(iterator_to_array($a->childNodes), fn (\Dom\Node $n): bool => !($n instanceof \Dom\Text && trim($n->data) === '')));

        return count($potomci) === 1 && $potomci[0] instanceof \Dom\Element && strtolower($potomci[0]->localName) === 'img'
            && preg_match('#\.(jpe?g|png|gif|webp)$#i', (string) parse_url((string) $a->getAttribute('href'), PHP_URL_PATH)) === 1;
    }

    private static function maBlokovehoPotomka(\Dom\Element $n): bool
    {
        foreach ($n->childNodes as $potomek) {
            if ($potomek instanceof \Dom\Element && preg_match('#^(?:' . self::BLOKOVE . ')$#i', $potomek->localName)) {
                return true;
            }
        }

        return false;
    }

    /** Odstraní značku, její obsah nechá na místě. */
    private static function rozbal(\Dom\Element $n): void
    {
        while ($n->firstChild !== null) {
            $n->parentNode?->insertBefore($n->firstChild, $n);
        }
        $n->remove();
    }

    private static function prejmenuj(\Dom\HTMLDocument $doc, \Dom\Element $n, string $tag): \Dom\Element
    {
        $novy = $doc->createElement($tag);
        while ($n->firstChild !== null) {
            $novy->appendChild($n->firstChild);
        }
        $n->parentNode?->replaceChild($novy, $n);

        return $novy;
    }

    /**
     * Zavolá funkci jen na části HTML mimo <pre> a <code> – v ukázkách kódu jsou hranaté závorky běžný text.
     *
     * @param callable(string): string $funkce
     */
    private static function mimoKod(string $html, callable $funkce): string
    {
        $casti = preg_split('#(<pre\b.*?</pre>|<code\b.*?</code>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($casti as $i => $cast) {
            if ($i % 2 === 0) {
                $casti[$i] = $funkce($cast);
            }
        }

        return implode('', $casti);
    }
}
