<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use MiroCMS\Core\App;
use MiroCMS\Core\Db;
use MiroCMS\Core\WpObsah;

/**
 * Stavba stránky: strom prvků {"v": 1, "deti": [{"id", "typ", "znacka", "obsah", "styl", "tridy", "kotva", "deti"}]}.
 *
 * Jediný validátor pro editor, MCP i API (vycisti) a jediný vykreslovač v PHP (vykresli) – co editor ukáže, je přesně web.
 * Neplatná část stromu se při čištění opraví nebo zahodí a nahlásí; na veřejném webu se nikdy nevyhazuje výjimka.
 */
final class Stavba
{
    public const int VERZE = 1;
    public const int MAX_PRVKU = 800;
    public const int MAX_HLOUBKA = 12;
    public const string VZOR_TRIDA = '/^[a-z][a-z0-9-]{0,40}(__[a-z0-9-]{1,30})?(--[a-z0-9-]{1,30})?$/';

    /** Registr typů prvků (pořadí = pořadí v panelu Přidat). @var list<class-string<Prvek>> */
    public const array PRVKY = [
        Prvky\Sekce::class, Prvky\Kontejner::class, Prvky\Mrizka::class,
        Prvky\Nadpis::class, Prvky\Text::class, Prvky\Obrazek::class, Prvky\Tlacitko::class, Prvky\Seznam::class,
        Prvky\Citat::class, Prvky\Faq::class, Prvky\Video::class, Prvky\Oddelovac::class,
        Prvky\Ikona::class, Prvky\Galerie::class, Prvky\Zalozky::class, Prvky\Karusel::class, Prvky\Mapa::class, Prvky\Okno::class, Prvky\Drobecky::class,
        Prvky\Novinky::class, Prvky\VypisKolekce::class, Prvky\Formular::class, Prvky\Komponenta::class, Prvky\Html::class,
        Prvky\Logo::class, Prvky\Navigace::class, Prvky\Udaje::class, Prvky\ObsahStranky::class,
    ];

    /** @return class-string<Prvek>|null */
    public static function trida(string $typ): ?string
    {
        foreach (self::PRVKY as $trida) {
            if ($trida::TYP === $typ) {
                return $trida;
            }
        }

        return null;
    }

    public static function noveId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }

    /** Nový prvek daného typu s výchozím obsahem a stylem (pro editor, knihovnu i převod HTML). */
    public static function novy(string $typ, array $obsah = [], array $deti = []): array
    {
        $trida = self::trida($typ) ?? throw new \InvalidArgumentException('Neznámý typ prvku ' . $typ);
        $vychozi = array_map(fn (array $pole): mixed => $pole['vychozi'] ?? '', $trida::vlastnosti());

        return ['id' => self::noveId(), 'typ' => $typ, 'znacka' => $trida::ZNACKY[0], 'obsah' => $obsah + $vychozi, 'styl' => $trida::vychoziStyl(), 'tridy' => [], 'deti' => $deti];
    }

    /**
     * Vyčistí strom od kohokoli (editor, AI, import). Neznámé typy, vlastnosti a hodnoty zahodí, chybějící doplní výchozími,
     * duplicitní nebo chybějící id vytvoří znovu.
     *
     * @param bool $spravce smí měnit prvky JEN_SPRAVCE (vlastní HTML); ostatním se jejich obsah převezme z $predchozi
     * @param array<string, mixed>|null $predchozi dosavadní stavba (kvůli prvkům JEN_SPRAVCE)
     * @return array{0: array<string, mixed>, 1: array<string, string>} [stavba, chyby cesta => text]
     */
    public static function vycisti(mixed $vstup, bool $spravce = true, ?array $predchozi = null): array
    {
        $chyby = [];
        $pouzita = [];
        $pocet = 0;
        $chranene = $spravce || $predchozi === null ? [] : self::prvkyTypu($predchozi, fn (string $trida): bool => $trida::JEN_SPRAVCE);
        $deti = is_array($vstup) && is_array($vstup['deti'] ?? null) ? $vstup['deti'] : (is_array($vstup) && array_is_list($vstup) ? $vstup : []);
        if (!is_array($vstup)) {
            $chyby['stavba'] = 'Stavba musí být objekt {"v": 1, "deti": [...]}.';
        }
        $stavba = ['v' => self::VERZE, 'deti' => self::vycistiDeti($deti, 'deti', 1, $chyby, $pouzita, $pocet, $spravce, $chranene)];

        return [$stavba, $chyby];
    }

    /** @param array<string, array<string, mixed>> $chranene */
    private static function vycistiDeti(array $deti, string $cesta, int $hloubka, array &$chyby, array &$pouzita, int &$pocet, bool $spravce, array $chranene): array
    {
        $vystup = [];
        foreach (array_values($deti) as $i => $p) {
            $misto = $cesta . '[' . $i . ']';
            if (!is_array($p) || ($trida = self::trida((string) ($p['typ'] ?? ''))) === null) {
                $chyby[$misto] = 'Neznámý typ prvku „' . mb_substr((string) (is_array($p) ? ($p['typ'] ?? '') : ''), 0, 30) . '“ – vynechán. Typy: ' . implode(', ', array_map(fn (string $t): string => $t::TYP, self::PRVKY)) . '.';
                continue;
            }
            if (++$pocet > self::MAX_PRVKU) {
                $chyby[$misto] = 'Stavba má víc než ' . self::MAX_PRVKU . ' prvků – zbytek vynechán.';
                break;
            }
            $id = is_string($p['id'] ?? null) && preg_match('/^[a-z0-9]{3,16}$/', $p['id']) && !isset($pouzita[$p['id']]) ? $p['id'] : self::noveId();
            $pouzita[$id] = true;
            if ($trida::JEN_SPRAVCE && !$spravce) {
                if (!isset($chranene[$id])) {
                    $chyby[$misto] = 'Prvek „' . $trida::NAZEV . '“ smí vložit jen správce webu – vynechán.';
                    continue;
                }
                $vystup[] = $chranene[$id]; // obsah vlastního HTML se nemění, jen může zůstat na místě
                continue;
            }
            $znacka = in_array($p['znacka'] ?? null, $trida::ZNACKY, true) ? $p['znacka'] : $trida::ZNACKY[0];
            $cisty = ['id' => $id, 'typ' => $trida::TYP, 'znacka' => $znacka, 'obsah' => self::vycistiObsah($trida::vlastnosti(), is_array($p['obsah'] ?? null) ? $p['obsah'] : [], $misto . '.obsah', $chyby)];
            $styl = Styl::vycisti($p['styl'] ?? [], $misto . '.styl', $chyby);
            if ($styl !== []) {
                $cisty['styl'] = $styl;
            }
            $tridy = array_values(array_unique(array_filter(is_array($p['tridy'] ?? null) ? $p['tridy'] : [], fn (mixed $t): bool => is_string($t) && preg_match(self::VZOR_TRIDA, $t) === 1)));
            if ($tridy !== []) {
                $cisty['tridy'] = array_slice($tridy, 0, 8);
            }
            if (is_string($p['kotva'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $p['kotva'])) {
                $cisty['kotva'] = $p['kotva'];
            }
            if (is_string($p['popis'] ?? null) && trim($p['popis']) !== '') {
                $cisty['popis'] = mb_substr(trim(strip_tags($p['popis'])), 0, 60); // jméno prvku ve stromu editoru
            }
            if ($trida::KONTEJNER) {
                if ($hloubka >= self::MAX_HLOUBKA) {
                    $chyby[$misto . '.deti'] = 'Příliš hluboké vnoření – vnořené prvky vynechány.';
                    $cisty['deti'] = [];
                } else {
                    $cisty['deti'] = self::vycistiDeti(is_array($p['deti'] ?? null) ? $p['deti'] : [], $misto . '.deti', $hloubka + 1, $chyby, $pouzita, $pocet, $spravce, $chranene);
                }
            } elseif (!empty($p['deti'])) {
                $chyby[$misto . '.deti'] = 'Prvek „' . $trida::NAZEV . '“ nemůže obsahovat další prvky – vynechány.';
            }
            $vystup[] = $cisty;
        }

        return $vystup;
    }

    /** @param array<string, array<string, mixed>> $pole */
    private static function vycistiObsah(array $pole, array $obsah, string $cesta, array &$chyby): array
    {
        $cisty = [];
        foreach ($pole as $klic => $def) {
            $hodnota = $obsah[$klic] ?? $def['vychozi'] ?? '';
            $max = (int) ($def['max'] ?? 5000);
            $cisty[$klic] = match ($def['typ']) {
                'text' => mb_substr(trim(strip_tags(is_scalar($hodnota) ? (string) $hodnota : '')), 0, $max),
                'radky' => mb_substr(strip_tags(is_scalar($hodnota) ? (string) $hodnota : ''), 0, $max),
                'inline' => self::inline(is_scalar($hodnota) ? (string) $hodnota : '', $max),
                'html' => WpObsah::bezpecneHtml(is_scalar($hodnota) ? mb_substr((string) $hodnota, 0, 200000) : ''),
                'kod' => self::kod(is_scalar($hodnota) ? mb_substr((string) $hodnota, 0, $max) : ''),
                'odkaz' => self::odkaz(is_scalar($hodnota) ? (string) $hodnota : '', $cesta . '.' . $klic, $chyby),
                'obrazek' => is_string($hodnota) && preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300}|\{\{[a-z][a-z0-9_]{0,30}\}\})$#', $hodnota) ? $hodnota : '',
                'vyber' => is_scalar($hodnota) && isset($def['moznosti'][(string) $hodnota]) ? (string) $hodnota : (string) $def['vychozi'],
                'cislo' => is_numeric($hodnota) ? max((int) ($def['min'] ?? 0), min((int) ($def['max'] ?? 100), (int) $hodnota)) : (int) $def['vychozi'],
                'prepinac' => (bool) $hodnota,
                // hodnoty vlastností komponenty: jen klíč => text; podle typu vlastnosti se zkontrolují při vykreslení
                'hodnoty' => array_slice(array_filter(
                    array_map(fn (mixed $v): ?string => is_scalar($v) ? mb_substr((string) $v, 0, 20000) : null, is_array($hodnota) ? $hodnota : []),
                    fn (?string $v, int|string $k): bool => $v !== null && is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,30}$/', $k) === 1,
                    ARRAY_FILTER_USE_BOTH,
                ), 0, 30, true),
                'polozky' => array_slice(array_values(array_map(
                    fn (mixed $polozka): array => self::vycistiObsah($def['pole'], is_array($polozka) ? $polozka : [], $cesta . '.' . $klic, $chyby),
                    is_array($hodnota) ? $hodnota : [],
                )), 0, (int) ($def['max'] ?? 30)),
                default => '',
            };
            if ($def['typ'] === 'obrazek' && $hodnota !== '' && $cisty[$klic] === '') {
                $chyby[$cesta . '.' . $klic] = 'Obrázek musí být z Médií (media/…) nebo na adrese https://.';
            }
        }

        return $cisty;
    }

    /** Krátký text s tučným písmem, kurzívou, zalomením a odkazem – nic dalšího. */
    private static function inline(string $html, int $max): string
    {
        $cisty = WpObsah::bezpecneHtml('<p>' . mb_substr($html, 0, $max * 2) . '</p>');
        $cisty = (string) preg_replace('#^<p>|</p>$#', '', trim($cisty));
        $cisty = strip_tags($cisty, '<strong><b><em><i><br><a><s><sub><sup>');

        return trim(str_replace(['</p>', '<p>'], ['<br>', ''], $cisty));
    }

    /** Vlastní HTML správce: bez skriptů a obsluh událostí (vložené mapy a formuláře služeb jsou <iframe>). */
    private static function kod(string $html): string
    {
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

        return (string) preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $html);
    }

    private static function odkaz(string $adresa, string $cesta, array &$chyby): string
    {
        $adresa = trim($adresa);
        if ($adresa === '' || $adresa === '#' || preg_match('/^\{\{[a-z][a-z0-9_]{0,30}\}\}$/', $adresa)) {
            return $adresa; // {{url}} a další pole kolekce: dosadí se a zkontrolují při vykreslení
        }
        if (WpObsah::bezpecnaAdresa($adresa) && !preg_match('/[\s"<>]/', $adresa)) {
            return mb_substr($adresa, 0, 500);
        }
        $chyby[$cesta] = 'Odkaz může být jen https://…, mailto:, tel:, #kotva nebo adresa na webu (/…).';

        return '';
    }

    /** @return array<string, array<string, mixed>> id => prvek pro všechny prvky, jejichž třída splní podmínku */
    private static function prvkyTypu(array $stavba, callable $podminka): array
    {
        $nalezene = [];
        $projdi = function (array $deti) use (&$projdi, &$nalezene, $podminka): void {
            foreach ($deti as $p) {
                $trida = is_array($p) ? self::trida((string) ($p['typ'] ?? '')) : null;
                if ($trida !== null && $podminka($trida) && isset($p['id'])) {
                    $nalezene[(string) $p['id']] = $p;
                }
                if (is_array($p['deti'] ?? null)) {
                    $projdi($p['deti']);
                }
            }
        };
        $projdi($stavba['deti'] ?? []);

        return $nalezene;
    }

    /**
     * Vykreslí stavbu: HTML a CSS jen toho, co stránka používá (základ typů, použité třídy, styl prvků) ve vrstvách kaskády.
     * V režimu editoru dostane každý prvek data-mc-id, aby šel na plátně vybrat.
     *
     * @return array{html:string, css:string, faq:list<array{0:string, 1:string}>}
     */
    public static function vykresli(App $app, array $stavba, bool $editor = false): array
    {
        $k = new Kontext($app, $editor);
        $html = self::html($stavba, $k);

        return ['html' => $html, 'css' => self::css($app->db(), $k), 'faq' => $k->faq];
    }

    /** HTML stavby ve sdíleném kontextu stránky (web tak skládá stránku, záhlaví a patičku a CSS vypíše jednou přes css()). */
    public static function html(array $stavba, Kontext $k): string
    {
        return self::vykresliDeti($stavba['deti'] ?? [], $k);
    }

    private static function vykresliDeti(array $deti, Kontext $k): string
    {
        $html = '';
        foreach ($deti as $p) {
            try {
                $html .= self::vykresliPrvek($p, $k);
            } catch (\Throwable $e) {
                // „doktor“: vadný prvek se na webu vynechá, v editoru se ukáže hláška
                error_log('Stavitel: prvek ' . ($p['id'] ?? '?') . ' – ' . $e->getMessage());
                $html .= $k->editor ? '<div data-mc-id="' . e((string) ($p['id'] ?? '')) . '" style="padding:1rem;border:2px dashed #b3261e;color:#b3261e">' . e(t('Prvek se nepodařilo vykreslit.')) . '</div>' : '';
            }
        }

        return $html;
    }

    private static function vykresliPrvek(array $p, Kontext $k): string
    {
        $trida = self::trida((string) $p['typ']);
        if ($trida === null) {
            return '';
        }
        $k->typy[$trida::TYP] = true;
        if ($k->polozka !== null) {
            $p['obsah'] = self::dosadPolozku($trida::vlastnosti(), $p['obsah'] ?? [], $k->polozka);
        }
        $deti = match (true) {
            $trida === Prvky\VypisKolekce::class => Prvky\VypisKolekce::opakuj($p, $k, fn (): string => self::vykresliDeti($p['deti'] ?? [], $k)),
            $trida === Prvky\Komponenta::class => Prvky\Komponenta::vnitrek($p, $k, fn (array $stavba): string => self::vykresliDeti($stavba['deti'] ?? [], $k)),
            $trida::KONTEJNER => self::vykresliDeti($p['deti'] ?? [], $k),
            default => '',
        };
        $styl = $p['styl'] ?? [];
        // uvnitř Výpisu kolekce se prvek opakuje: styl přes třídu s-<id>, ne přes id (id musí být na stránce jen jednou)
        $opakuje = $k->vSmycce > 0;
        $id = $opakuje ? null : ($p['kotva'] ?? ($styl !== [] ? 's-' . $p['id'] : null));
        $tridy = array_merge($opakuje && $styl !== [] ? ['s-' . $p['id']] : [], $p['tridy'] ?? []);
        if ($styl !== [] && !isset($k->styly[$p['id']])) {
            $k->styly[$p['id']] = true;
            $k->css .= Styl::css($opakuje ? '.s-' . $p['id'] : '#' . $id, $styl, '', $k->app->request->basePath());
        }
        foreach ($p['tridy'] ?? [] as $t) {
            $k->tridy[$t] = true;
        }
        $a = ($id !== null ? ' id="' . e($id) . '"' : '')
            . ($tridy !== [] ? ' class="' . e(implode(' ', $tridy)) . '"' : '')
            . ($k->editor ? ' data-mc-id="' . e((string) $p['id']) . '" data-mc-typ="' . e($trida::TYP) . '"' : '');

        return $trida::vykresli($p, $a, $deti, $k);
    }

    /**
     * Hodnoty položky kolekce do polí obsahu podle jejich typu ({{nazev}} v nadpisu, {{foto}} v obrázku, {{url}} v odkazu…).
     *
     * @param array<string, array<string, mixed>> $vlastnosti
     * @param array<string, array{0: string, 1: string}> $hodnoty
     */
    private static function dosadPolozku(array $vlastnosti, array $obsah, array $hodnoty): array
    {
        foreach ($vlastnosti as $klic => $def) {
            if (is_string($obsah[$klic] ?? null)) {
                $obsah[$klic] = Kolekce::dosad($obsah[$klic], $def['typ'], $hodnoty);
            } elseif ($def['typ'] === 'hodnoty' && is_array($obsah[$klic] ?? null)) {
                // komponenta ve výpisu kolekce: {{pole}} položky v hodnotách vlastností (zkontrolují se až podle typu vlastnosti)
                $obsah[$klic] = array_map(fn (mixed $v): mixed => is_string($v) ? Kolekce::dosad($v, 'text', $hodnoty) : $v, $obsah[$klic]);
            } elseif ($def['typ'] === 'polozky' && is_array($obsah[$klic] ?? null)) {
                $obsah[$klic] = array_map(fn (array $polozka): array => self::dosadPolozku($def['pole'], $polozka, $hodnoty), $obsah[$klic]);
            }
        }

        return $obsah;
    }

    /** CSS stránky: základ použitých typů, použité třídy (z mc_tridy) a styl jednotlivých prvků – každé ve své vrstvě. */
    public static function css(Db $db, Kontext $k): string
    {
        // ve stavbě řídí rozestupy mezery kontejnerů (gap), ne okraje nadpisů a odstavců ze šablony; text uvnitř prvku Text je má
        $zaklad = ':where(.stavba) :where(h1, h2, h3, h4, h5, h6, p, ul, ol, blockquote, figure, hr) { margin-block: 0; }' . "\n"
            . ':where(.stavba) :where(.mc-text) > * + * { margin-block-start: 1em; }' . "\n";
        foreach (self::PRVKY as $trida) {
            if (isset($k->typy[$trida::TYP]) && $trida::zakladniCss() !== '') {
                $zaklad .= $trida::zakladniCss() . "\n";
            }
        }
        $tridy = '';
        if ($k->tridy !== []) {
            $nazvy = array_keys($k->tridy);
            foreach ($db->all('SELECT nazev, styl, css FROM {tridy} WHERE nazev IN (' . implode(',', array_fill(0, count($nazvy), '?')) . ') ORDER BY nazev', $nazvy) as $r) {
                $tridy .= Styl::css('.' . $r['nazev'], json_decode((string) $r['styl'], true) ?: [], Styl::vlastniCss((string) $r['css']), $k->app->request->basePath());
            }
        }
        $css = DesignSystem::VRSTVY . "\n";
        foreach (['stavitel' => $zaklad, 'tridy' => $tridy, 'prvky' => $k->css] as $vrstva => $obsah) {
            if (trim($obsah) !== '') {
                $css .= '@layer ' . $vrstva . " {\n" . $obsah . "}\n";
            }
        }

        return $css;
    }

    /** Stavba ze stránky: publikovaná, nebo koncept (editor, náhled). Neplatný JSON = null. */
    public static function zJson(?string $json): ?array
    {
        $data = $json === null ? null : json_decode($json, true);

        return is_array($data) && isset($data['deti']) ? $data : null;
    }

    public static function naJson(array $stavba): string
    {
        return (string) json_encode($stavba, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Obsah stavby jako prosté sémantické HTML bez rozložení a stylu (nadpisy, odstavce, seznamy, odkazy, obrázky).
     * Při publikování se ukládá do sloupce text – z něj čerpá hledání, llms.txt, verze .md, API, MCP i export, a je to
     * i obsah stránky, kdyby se vrátila k textu.
     */
    public static function jakoText(array $stavba): string
    {
        $html = '';
        $projdi = function (array $deti) use (&$projdi, &$html): void {
            foreach ($deti as $p) {
                $o = $p['obsah'] ?? [];
                $z = preg_match('/^h[1-6]$/', $p['znacka'] ?? '') ? $p['znacka'] : 'p';
                $html .= match ($p['typ'] ?? '') {
                    'nadpis' => "<{$z}>" . ($o['text'] ?? '') . "</{$z}>\n",
                    'text' => ($o['html'] ?? '') . "\n",
                    'obrazek' => ($o['src'] ?? '') !== '' ? '<figure><img src="' . e($o['src']) . '" alt="' . e($o['alt'] ?? '') . '">' . (($o['popisek'] ?? '') !== '' ? '<figcaption>' . e($o['popisek']) . '</figcaption>' : '') . "</figure>\n" : '',
                    'tlacitko' => ($o['text'] ?? '') !== '' ? '<p>' . (($o['odkaz'] ?? '') !== '' ? '<a href="' . e($o['odkaz']) . '">' . e($o['text']) . '</a>' : e($o['text'])) . "</p>\n" : '',
                    'seznam' => ($radky = array_filter(array_map('trim', explode("\n", (string) ($o['polozky'] ?? ''))))) !== []
                        ? "<{$p['znacka']}>" . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $radky)) . "</{$p['znacka']}>\n" : '',
                    'citat' => '<blockquote><p>' . ($o['text'] ?? '') . '</p>' . (($o['autor'] ?? '') !== '' ? '<p>– ' . e($o['autor']) . (($o['pozice'] ?? '') !== '' ? ', ' . e($o['pozice']) : '') . '</p>' : '') . "</blockquote>\n",
                    'faq' => implode('', array_map(fn (array $f): string => '<h3>' . e($f['otazka'] ?? '') . '</h3>' . ($f['odpoved'] ?? '') . "\n", $o['polozky'] ?? [])),
                    'video' => ($o['url'] ?? '') !== '' ? '<p><a href="' . e($o['url']) . '">' . e(($o['titulek'] ?? '') !== '' ? $o['titulek'] : $o['url']) . "</a></p>\n" : '',
                    'oddelovac' => "<hr>\n",
                    default => '',
                };
                // vnitřek Výpisu kolekce je vzor se {{značkami}}, ne obsah stránky
                if (is_array($p['deti'] ?? null) && ($p['typ'] ?? '') !== 'kolekce') {
                    $projdi($p['deti']);
                }
            }
        };
        $projdi($stavba['deti'] ?? []);

        return trim($html);
    }

    /** Textová stránka převedená na stavbu: jedna úzká sekce s nadpisem a textem (zpět jde přes verze). */
    public static function zTextu(string $titulek, string $html): array
    {
        return ['v' => self::VERZE, 'deti' => [self::novy('sekce', ['sirka' => 'uzka'], [
            ['znacka' => 'h1'] + self::novy('nadpis', ['text' => e($titulek)]),
            self::novy('text', ['html' => $html !== '' ? $html : '<p></p>']),
        ])]];
    }

    /**
     * Popis schématu pro editor a pro jazykové modely (MCP stavba_schema): typy prvků s poli, vlastnosti stylu a tokeny.
     *
     * @return array<string, mixed>
     */
    public static function schema(bool $spravce = true, string $jazyk = 'cs', bool $casti = false): array
    {
        // výchozí obsah nových prvků je v jazyce stránky, popisky polí překládá editor do jazyka administrace
        return \MiroCMS\Core\Jazyk::docasne($jazyk, fn (): array => self::sestavSchema($spravce, $casti));
    }

    /** @return array<string, mixed> */
    private static function sestavSchema(bool $spravce, bool $casti): array
    {
        $prvky = [];
        foreach (self::PRVKY as $trida) {
            if (($trida::JEN_SPRAVCE && !$spravce) || ($trida::JEN_CASTI && !$casti)) {
                continue;
            }
            $prvky[] = [
                'typ' => $trida::TYP, 'nazev' => $trida::NAZEV, 'popis' => $trida::POPIS, 'ikona' => $trida::IKONA, 'skupina' => $trida::SKUPINA,
                'kontejner' => $trida::KONTEJNER, 'znacky' => $trida::ZNACKY, 'vlastnosti' => $trida::vlastnosti(), 'vychozi_styl' => $trida::vychoziStyl() ?: new \stdClass(),
                'vychozi_deti' => $trida::vychoziDeti(),
            ];
        }
        $styl = [];
        foreach (Styl::VLASTNOSTI as $klic => [$css, $typ, $skupina, $popisek, $moznosti]) {
            $styl[$klic] = ['css' => $css, 'typ' => $typ, 'skupina' => $skupina, 'popisek' => $popisek] + ($moznosti !== null ? ['moznosti' => $moznosti] : []);
        }

        return [
            'verze' => self::VERZE, 'prvky' => $prvky, 'styl' => $styl, 'skupiny_stylu' => Styl::SKUPINY, 'stavy' => array_keys(Styl::STAVY),
            'tokeny' => ['barvy' => DesignSystem::TOKENY_BAREV, 'mezery' => array_keys(DesignSystem::MEZERY), 'kroky' => DesignSystem::KROKY,
                'zaobleni' => array_keys(DesignSystem::ZAOBLENI), 'stiny' => array_keys(DesignSystem::STINY)],
            'pravidla' => [
                'Jeden prvek = jedna HTML značka; sekce má nejvýš jeden vnitřní obal. Obsah stránky skládej ze sekcí.',
                'Styl ber z tokenů (mezera „l“, barva „primarni“, krok „2“); volnou hodnotu jen když token nestačí.',
                'Styl má stavy zaklad, tablet (do 1023 px), mobil (do 767 px), hover; úprava jednoho stavu nemění ostatní.',
                'Opakovaný vzhled (karty, štítky) dej do třídy, ne do stylu každého prvku.',
                'Sloupce mřížky: číslo („3“), „auto:16rem“ (kolik se vejde) nebo poměr („2fr 1fr“).',
            ],
        ];
    }
}
