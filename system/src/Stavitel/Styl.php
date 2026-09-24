<?php

declare(strict_types=1);

namespace Kaleta\Stavitel;

/**
 * Styl prvku nebo třídy: {"zaklad": {...}, "tablet": {...}, "mobil": {...}, "hover": {...}}.
 * Vlastnosti jsou kurátorované (VLASTNOSTI) a berou přednostně tokeny design systému (mezera „l“, barva „primarni“, krok „2“),
 * volná hodnota jde taky, ale jen v bezpečném tvaru. Úprava jednoho breakpointu nikdy nesahá na jiný.
 */
final class Styl
{
    /** Breakpointy a stavy: klíč => media query nebo pseudotřída (prázdné = základ). */
    public const array STAVY = [
        'zaklad' => '',
        'tablet' => '@media (max-width: 1023px)',
        'mobil' => '@media (max-width: 767px)',
        'hover' => ':hover',     // platí i pro fokus z klávesnice (:focus-visible) – kdo nepoužívá myš, uvidí totéž
        'aktivni' => ':active',  // stisknutí (tlačítko, karta-odkaz)
    ];

    /**
     * klíč => [CSS vlastnost, typ, skupina, popisek, možnosti výčtu]
     * Typy: mezera | delka | barva | krok | zaobleni | stin | vyber | cislo | obrazek | sloupce | text
     */
    public const array VLASTNOSTI = [
        // rozložení
        'zobrazeni' => ['display', 'vyber', 'rozlozeni', 'Zobrazení', ['block' => 'blok', 'flex' => 'flex (řada / sloupec)', 'grid' => 'mřížka', 'none' => 'skrýt']],
        'smer' => ['flex-direction', 'vyber', 'rozlozeni', 'Směr', ['row' => 'vedle sebe', 'column' => 'pod sebou', 'row-reverse' => 'vedle sebe obráceně', 'column-reverse' => 'pod sebou obráceně']],
        'zalamovani' => ['flex-wrap', 'vyber', 'rozlozeni', 'Zalamování', ['wrap' => 'zalamovat', 'nowrap' => 'nezalamovat']],
        'sloupce' => ['grid-template-columns', 'sloupce', 'rozlozeni', 'Sloupce mřížky', null],
        'mezera' => ['gap', 'mezera', 'rozlozeni', 'Mezera mezi prvky', null],
        'zarovnani' => ['align-items', 'vyber', 'rozlozeni', 'Zarovnání (příčně)', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'stretch' => 'roztáhnout', 'baseline' => 'účaří']],
        'rozmisteni' => ['justify-content', 'vyber', 'rozlozeni', 'Rozmístění (hlavní osa)', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'space-between' => 'do krajů', 'space-around' => 'rovnoměrně']],
        'vlastni_zarovnani' => ['align-self', 'vyber', 'rozlozeni', 'Vlastní zarovnání', ['start' => 'začátek', 'center' => 'střed', 'end' => 'konec', 'stretch' => 'roztáhnout']],
        'poradi' => ['order', 'cislo', 'rozlozeni', 'Pořadí', null],
        'rust' => ['flex', 'vyber', 'rozlozeni', 'Roztažení ve flexu', ['1 1 0%' => 'vyplnit místo', '0 0 auto' => 'podle obsahu']],
        // rozměry
        'sirka' => ['width', 'delka', 'rozmery', 'Šířka', null],
        'max_sirka' => ['max-width', 'delka', 'rozmery', 'Max. šířka', null],
        'vyska' => ['height', 'delka', 'rozmery', 'Výška', null],
        'min_vyska' => ['min-height', 'delka', 'rozmery', 'Min. výška', null],
        'pomer_stran' => ['aspect-ratio', 'vyber', 'rozmery', 'Poměr stran', ['1' => '1 : 1', '4/3' => '4 : 3', '3/2' => '3 : 2', '16/9' => '16 : 9', '21/9' => '21 : 9', '3/4' => '3 : 4']],
        'prizpusobeni' => ['object-fit', 'vyber', 'rozmery', 'Přizpůsobení obrázku', ['cover' => 'vyplnit (oříznout)', 'contain' => 'celý obrázek']],
        'na_stred' => ['margin-inline', 'vyber', 'rozmery', 'Na střed', ['auto' => 'ano']],
        // mezery
        'odsazeni_y' => ['padding-block', 'mezera', 'mezery', 'Vnitřní odsazení nahoře a dole', null],
        'odsazeni_x' => ['padding-inline', 'mezera', 'mezery', 'Vnitřní odsazení vlevo a vpravo', null],
        'okraj_nahore' => ['margin-block-start', 'mezera', 'mezery', 'Vnější okraj nahoře', null],
        'okraj_dole' => ['margin-block-end', 'mezera', 'mezery', 'Vnější okraj dole', null],
        'okraj_vlevo' => ['margin-inline-start', 'mezera', 'mezery', 'Vnější okraj vlevo', null],
        'okraj_vpravo' => ['margin-inline-end', 'mezera', 'mezery', 'Vnější okraj vpravo', null],
        // typografie
        'velikost_pisma' => ['font-size', 'krok', 'typografie', 'Velikost písma', null],
        'tloustka_pisma' => ['font-weight', 'vyber', 'typografie', 'Tloušťka písma', ['300' => 'tenké', '400' => 'normální', '500' => 'střední', '600' => 'polotučné', '700' => 'tučné', '800' => 'extra tučné']],
        'pismo' => ['font-family', 'vyber', 'typografie', 'Písmo', ['var(--ka-pismo-text)' => 'textové', 'var(--ka-pismo-titulky)' => 'titulkové']],
        'zarovnani_textu' => ['text-align', 'vyber', 'typografie', 'Zarovnání textu', ['start' => 'vlevo', 'center' => 'na střed', 'end' => 'vpravo']],
        'radkovani' => ['line-height', 'vyber', 'typografie', 'Řádkování', ['1.1' => 'těsné', '1.3' => 'menší', '1.6' => 'běžné', '1.8' => 'volné']],
        'velka_pismena' => ['text-transform', 'vyber', 'typografie', 'Velká písmena', ['uppercase' => 'VELKÁ', 'none' => 'normální']],
        'proklad' => ['letter-spacing', 'vyber', 'typografie', 'Proklad písmen', ['-0.02em' => 'užší', '0' => 'normální', '0.06em' => 'širší', '0.12em' => 'široký']],
        'max_radek' => ['max-width', 'vyber', 'typografie', 'Délka řádku', ['var(--ka-sirka-textu)' => 'pohodlná pro čtení', '20ch' => 'krátká (titulek)', '60ch' => '60 znaků']],
        'barva' => ['color', 'barva', 'typografie', 'Barva textu', null],
        // pozadí a rámeček
        'pozadi' => ['background-color', 'barva', 'pozadi', 'Barva pozadí', null],
        'obrazek_pozadi' => ['background-image', 'obrazek', 'pozadi', 'Obrázek pozadí', null],
        'prechod' => ['background-image', 'vyber', 'pozadi', 'Barevný přechod', [
            'linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))' => 'hlavní → doplňková',
            'linear-gradient(180deg, var(--ka-barva-primarni-jemna), var(--ka-barva-pozadi))' => 'jemně shora',
            'linear-gradient(180deg, var(--ka-barva-pozadi), var(--ka-barva-plocha))' => 'pozadí → plocha',
            'radial-gradient(circle at 25% 15%, var(--ka-barva-primarni-jemna), transparent 60%)' => 'záře v rohu',
            'linear-gradient(180deg, transparent, rgb(0 0 0 / 0.55))' => 'ztmavení dole (na fotku)',
        ]],
        'prekryv' => ['--ka-prekryv', 'barva', 'pozadi', 'Překryv obrázku (barva)', null],
        'ramecek' => ['border', 'vyber', 'pozadi', 'Rámeček', ['none' => 'žádný', '1px solid var(--ka-barva-linka)' => 'tenký', '2px solid currentColor' => 'výrazný', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'linka_nahore' => ['border-block-start', 'vyber', 'pozadi', 'Linka nahoře', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'linka_dole' => ['border-block-end', 'vyber', 'pozadi', 'Linka dole', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'zaobleni' => ['border-radius', 'zaobleni', 'pozadi', 'Zaoblení rohů', null],
        'stin' => ['box-shadow', 'stin', 'pozadi', 'Stín', null],
        'pruhlednost' => ['opacity', 'vyber', 'pozadi', 'Průhlednost', ['1' => 'žádná', '0.8' => '80 %', '0.6' => '60 %', '0.4' => '40 %']],
        'orez' => ['overflow', 'vyber', 'pozadi', 'Přesah obsahu', ['hidden' => 'oříznout', 'visible' => 'nechat']],
        'pozice' => ['position', 'vyber', 'pokrocile', 'Umístění', ['relative' => 'běžné (kotva pro vnořené)', 'sticky' => 'přilepit při posunu']],
        'odshora' => ['top', 'mezera', 'pokrocile', 'Odshora (u přilepení)', null],
        'vrstva' => ['z-index', 'cislo', 'pokrocile', 'Vrstva (nad ostatním obsahem)', null],
        // objevení při rolování: animace řízená posunem stránky (CSS scroll-driven), bez JavaScriptu; kde to prohlížeč neumí, prvek je rovnou vidět
        'animace' => ['animation', 'vyber', 'pokrocile', 'Objevení při rolování', ['ka-objevit' => 'prolnutí', 'ka-vyjet' => 'vyjetí zdola', 'ka-priblizit' => 'přiblížení', 'none' => 'žádné']],
    ];

    public const array SKUPINY = ['rozlozeni' => 'Rozložení', 'rozmery' => 'Rozměry', 'mezery' => 'Mezery', 'typografie' => 'Typografie', 'pozadi' => 'Pozadí a rámeček', 'pokrocile' => 'Pokročilé'];

    /** Bezpečný tvar volné hodnoty: čísla s jednotkami, klíčová slova, calc/min/max/clamp, var(--ka-…). Nikdy ; { } < > \ ani url(). */
    private const string VZOR_VOLNA = '/^(?!.*(?:url|expression|javascript|@import))[-a-z0-9 .,%()#+*\/]{1,80}$/i';
    private const string VZOR_DELKA = '/^(auto|0|-?\d{1,5}(\.\d{1,4})?(px|rem|em|%|vw|vh|svh|dvh|ch|fr)|(min|max|clamp|calc)\([-a-z0-9 .,%+*\/()]{1,70}\)|var\(--ka-[a-z0-9-]{1,40}\)|fit-content|min-content|max-content)$/i';

    /**
     * Vyčistí styl: zná jen stavy ze STAVY a vlastnosti z VLASTNOSTI; neplatná hodnota se zahodí a zapíše do $chyby.
     *
     * @param array<string, string> $chyby cesta => text chyby (pro zprávu editoru a MCP)
     * @return array<string, array<string, string>>
     */
    public static function vycisti(mixed $styl, string $cesta = '', array &$chyby = []): array
    {
        $cisty = [];
        foreach (is_array($styl) ? $styl : [] as $stav => $vlastnosti) {
            if (!isset(self::STAVY[$stav]) || !is_array($vlastnosti)) {
                $chyby[$cesta . '.' . $stav] = 'Neznámý breakpoint nebo stav (povolené: ' . implode(', ', array_keys(self::STAVY)) . ').';
                continue;
            }
            foreach ($vlastnosti as $klic => $hodnota) {
                if (!isset(self::VLASTNOSTI[$klic])) {
                    $chyby[$cesta . '.' . $stav . '.' . $klic] = 'Neznámá vlastnost stylu.';
                    continue;
                }
                $hodnota = is_scalar($hodnota) ? trim((string) $hodnota) : '';
                if ($hodnota === '') {
                    continue;
                }
                if (self::hodnota($klic, $hodnota) === null) {
                    $chyby[$cesta . '.' . $stav . '.' . $klic] = 'Neplatná hodnota „' . mb_substr($hodnota, 0, 40) . '“.';
                    continue;
                }
                $cisty[$stav][$klic] = $hodnota;
            }
        }

        return $cisty;
    }

    /** CSS hodnota pro uloženou hodnotu vlastnosti; null = neplatná. */
    public static function hodnota(string $klic, string $hodnota): ?string
    {
        [, $typ, , , $moznosti] = self::VLASTNOSTI[$klic];

        return match ($typ) {
            'vyber' => isset($moznosti[$hodnota]) ? $hodnota : null,
            'mezera' => isset(DesignSystem::MEZERY[$hodnota]) ? 'var(--ka-mezera-' . $hodnota . ')' : (preg_match(self::VZOR_DELKA, $hodnota) ? $hodnota : null),
            'delka' => preg_match(self::VZOR_DELKA, $hodnota) ? $hodnota : null,
            'krok' => in_array($hodnota, DesignSystem::KROKY, true) ? 'var(--ka-krok-' . $hodnota . ')' : (preg_match(self::VZOR_DELKA, $hodnota) ? $hodnota : null),
            'barva' => isset(DesignSystem::TOKENY_BAREV[$hodnota]) ? 'var(--ka-barva-' . $hodnota . ')'
                : (preg_match('/^(#[0-9a-f]{3,8}|transparent|currentColor|(rgba?|hsla?|oklch|oklab|lab|lch|hwb)\([0-9., %\/+-]{3,60}\))$/i', $hodnota) ? $hodnota : null),
            'zaobleni' => isset(DesignSystem::ZAOBLENI[$hodnota]) ? 'var(--ka-zaobleni-' . $hodnota . ')' : (preg_match(self::VZOR_DELKA, $hodnota) ? $hodnota : null),
            'stin' => isset(DesignSystem::STINY[$hodnota]) ? 'var(--ka-stin-' . $hodnota . ')' : ($hodnota === 'none' ? 'none' : null),
            'cislo' => preg_match('/^-?\d{1,3}$/', $hodnota) ? $hodnota : null,
            'sloupce' => self::sloupce($hodnota),
            'obrazek' => preg_match('#^(https://[^\s"\'()<>\\\\]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $hodnota) ? $hodnota : null,
            default => preg_match(self::VZOR_VOLNA, $hodnota) ? $hodnota : null,
        };
    }

    /** Sloupce mřížky: „3“ = tři stejné, „auto:16rem“ = kolik se vejde po min. 16rem, „2fr 1fr“ = vlastní poměr. */
    private static function sloupce(string $hodnota): ?string
    {
        if (preg_match('/^([1-9]|1[0-2])$/', $hodnota)) {
            return 'repeat(' . $hodnota . ', minmax(0, 1fr))';
        }
        if (preg_match('/^auto:(\d{1,3}(\.\d{1,2})?)(rem|px|ch)$/', $hodnota, $m)) {
            return 'repeat(auto-fit, minmax(min(100%, ' . $m[1] . $m[3] . '), 1fr))';
        }

        return preg_match('/^((\d{1,2}(\.\d)?fr|auto|\d{1,4}(px|rem))\s?){1,6}$/', $hodnota) ? trim($hodnota) : null;
    }

    /**
     * CSS pro selektor ze stylu: základ, :hover, pak breakpointy (od většího k menšímu, aby menší vyhrál).
     *
     * @param array<string, array<string, string>> $styl
     */
    public static function css(string $selektor, array $styl, string $vlastniCss = '', string $zaklad = ''): string
    {
        $css = '';
        $deklarace = function (array $vlastnosti) use ($zaklad): string {
            $radky = [];
            $obrazek = null;
            foreach ($vlastnosti as $klic => $hodnota) {
                $css = self::hodnota($klic, (string) $hodnota);
                if ($css === null) {
                    continue;
                }
                [$vlastnost, $typ] = self::VLASTNOSTI[$klic];
                if ($klic === 'animace') {
                    if ($css !== 'none') {
                        array_push($radky, 'animation: ' . $css . ' linear both', 'animation-timeline: view()', 'animation-range: entry 0% cover 28%');
                    }
                    continue;
                }
                if ($typ === 'obrazek') {
                    $obrazek = $css;
                    continue;
                }
                $radky[] = $vlastnost . ': ' . $css;
                if ($klic === 'pozadi' && ($hodnota === 'bila' || $hodnota === 'cerna') && !isset($vlastnosti['barva'])) {
                    // bílá a černá se v tmavém režimu nemění: text a odvozené odstíny uvnitř se jim přizpůsobí (jinak světlý text na bílé)
                    $text = $hodnota === 'bila' ? 'var(--ka-barva-text-svetle)' : 'var(--ka-barva-text-tmave)';
                    $plocha = $hodnota === 'bila' ? '#ffffff' : '#000000';
                    array_push($radky, '--ka-barva-text: ' . $text, 'color: ' . $text,
                        '--ka-barva-tlumeny: color-mix(in oklch, ' . $text . ' 64%, ' . $plocha . ')', '--ka-barva-linka: color-mix(in oklch, ' . $text . ' 14%, ' . $plocha . ')');
                }
            }
            if ($obrazek !== null) {
                // médium webu vždy od kořene instalace – relativní url() by se na /en/… nebo /kolekce/polozka hledalo jinde
                if (!str_starts_with($obrazek, 'https://') && !str_starts_with($obrazek, '/')) {
                    $obrazek = $zaklad . '/' . $obrazek;
                }
                // obrázek pozadí vždy pokrývá plochu; volitelný překryv (--ka-prekryv) jde přes něj kvůli čitelnosti textu
                $radky[] = 'background-image: linear-gradient(var(--ka-prekryv, transparent), var(--ka-prekryv, transparent)), url("' . $obrazek . '")';
                $radky[] = 'background-size: cover';
                $radky[] = 'background-position: center';
            }

            return $radky === [] ? '' : implode('; ', $radky) . ';';
        };
        $zaklad = $deklarace($styl['zaklad'] ?? []) . ($vlastniCss !== '' ? ' ' . $vlastniCss : '');
        if (trim($zaklad) !== '') {
            $css .= $selektor . ' { ' . trim($zaklad) . " }\n";
        }
        if (($hover = $deklarace($styl['hover'] ?? [])) !== '') {
            $css .= $selektor . ':is(:hover, :focus-visible) { ' . $hover . " }\n";
        }
        if (($aktivni = $deklarace($styl['aktivni'] ?? [])) !== '') {
            $css .= $selektor . ':active { ' . $aktivni . " }\n";
        }
        foreach (['tablet', 'mobil'] as $stav) {
            if (($d = $deklarace($styl[$stav] ?? [])) !== '') {
                $css .= self::STAVY[$stav] . ' { ' . $selektor . ' { ' . $d . " } }\n";
            }
        }

        return $css;
    }

    /**
     * Vlastní CSS třídy (zadává správce, nebo vzniká převodem HTML od AI): jen deklarace „vlastnost: hodnota;“ bez bloků,
     * url(), importů a skriptových konstrukcí. Nepovolené řádky se zahodí.
     */
    public static function vlastniCss(string $css, array &$zahozeno = []): string
    {
        $vystup = [];
        foreach (preg_split('/;(?![^(]*\))/', str_replace(["\r", "\n"], ' ', $css)) ?: [] as $deklarace) {
            $deklarace = trim($deklarace);
            if ($deklarace === '') {
                continue;
            }
            if (preg_match('/^(--ka-[a-z0-9-]{1,40}|-?[a-z][a-z-]{1,40})\s*:\s*([^;{}<>\\\\@]{1,200})$/i', $deklarace, $m) && !preg_match('/url\s*\(|expression|javascript|behavior|-moz-binding/i', $m[2])) {
                $vystup[] = strtolower($m[1]) . ': ' . trim($m[2]) . ';';
            } else {
                $zahozeno[] = $deklarace;
            }
        }

        return implode(' ', $vystup);
    }
}
