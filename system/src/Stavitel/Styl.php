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
        // stav na menší obrazovce: najetí a stisk se dají doladit zvlášť pro tablet a mobil
        'hover_tablet' => '@media (max-width: 1023px)',
        'hover_mobil' => '@media (max-width: 767px)',
        'aktivni_tablet' => '@media (max-width: 1023px)',
        'aktivni_mobil' => '@media (max-width: 767px)',
    ];

    /** Odkud stav dědí hodnotu, kterou sám nemá (editor ji ukáže šedě): nejbližší nejdřív. */
    public const array DEDENI = [
        'zaklad' => [], 'tablet' => ['zaklad'], 'mobil' => ['tablet', 'zaklad'],
        'hover' => ['zaklad'], 'hover_tablet' => ['hover', 'tablet', 'zaklad'], 'hover_mobil' => ['hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'],
        'aktivni' => ['hover', 'zaklad'], 'aktivni_tablet' => ['aktivni', 'hover_tablet', 'hover', 'tablet', 'zaklad'],
        'aktivni_mobil' => ['aktivni_tablet', 'aktivni', 'hover_mobil', 'hover_tablet', 'hover', 'mobil', 'tablet', 'zaklad'],
    ];

    /**
     * klíč => [CSS vlastnost, typ, skupina, popisek, možnosti výčtu]
     * Typy: mezera | delka | barva | krok | zaobleni | stin | ramecek | vyber | cislo | obrazek | sloupce | radky | oblasti | oblast | text
     */
    public const array VLASTNOSTI = [
        // rozložení
        'zobrazeni' => ['display', 'vyber', 'rozlozeni', 'Zobrazení', ['block' => 'blok', 'flex' => 'flex (řada / sloupec)', 'grid' => 'mřížka', 'none' => 'skrýt']],
        'smer' => ['flex-direction', 'vyber', 'rozlozeni', 'Směr', ['row' => 'vedle sebe', 'column' => 'pod sebou', 'row-reverse' => 'vedle sebe obráceně', 'column-reverse' => 'pod sebou obráceně']],
        'zalamovani' => ['flex-wrap', 'vyber', 'rozlozeni', 'Zalamování', ['wrap' => 'zalamovat', 'nowrap' => 'nezalamovat']],
        'sloupce' => ['grid-template-columns', 'sloupce', 'rozlozeni', 'Sloupce mřížky', null],
        'radky' => ['grid-template-rows', 'radky', 'rozlozeni', 'Řádky mřížky', null],
        'oblasti' => ['grid-template-areas', 'oblasti', 'rozlozeni', 'Oblasti mřížky', null],
        'oblast' => ['grid-area', 'oblast', 'rozlozeni', 'Oblast v mřížce (název)', null],
        'rozpeti_sloupcu' => ['grid-column', 'vyber', 'rozlozeni', 'Přes sloupce (v mřížce)', ['span 2' => '2 sloupce', 'span 3' => '3 sloupce', 'span 4' => '4 sloupce', '1 / -1' => 'celá šířka']],
        'rozpeti_radku' => ['grid-row', 'vyber', 'rozlozeni', 'Přes řádky (v mřížce)', ['span 2' => '2 řádky', 'span 3' => '3 řádky', 'span 4' => '4 řádky']],
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
        // typografie: nejdřív pojmenovaný styl z Vzhledu, jednotlivé vlastnosti pod ním ho doladí
        'typ_styl' => ['font', 'vyber', 'typografie', 'Typografický styl', [
            'titulek' => 'Hlavní titulek', 'nadpis-sekce' => 'Nadpis sekce', 'podnadpis' => 'Podnadpis', 'perex' => 'Perex',
            'text' => 'Běžný text', 'drobny' => 'Drobný text', 'nadtitulek' => 'Nadtitulek',
        ]],
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
        'paralaxa' => ['background-attachment', 'vyber', 'pozadi', 'Obrázek pozadí při posunu', ['fixed' => 'stojí (paralaxa)', 'scroll' => 'posouvá se s obsahem']],
        'prekryv' => ['--ka-prekryv', 'barva', 'pozadi', 'Překryv obrázku (barva)', null],
        'ramecek' => ['border', 'ramecek', 'pozadi', 'Rámeček', ['none' => 'žádný', '1px solid var(--ka-barva-linka)' => 'tenký', '2px solid currentColor' => 'výrazný', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'barva_ramecku' => ['border-color', 'barva', 'pozadi', 'Barva rámečku', null],
        'linka_nahore' => ['border-block-start', 'vyber', 'pozadi', 'Linka nahoře', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'linka_dole' => ['border-block-end', 'vyber', 'pozadi', 'Linka dole', ['none' => 'žádná', '1px solid var(--ka-barva-linka)' => 'tenká', '2px solid var(--ka-barva-primarni)' => 'v hlavní barvě']],
        'zaobleni' => ['border-radius', 'zaobleni', 'pozadi', 'Zaoblení rohů', null],
        'stin' => ['box-shadow', 'stin', 'pozadi', 'Stín', null],
        'pruhlednost' => ['opacity', 'vyber', 'pozadi', 'Průhlednost', ['1' => 'žádná', '0.8' => '80 %', '0.6' => '60 %', '0.4' => '40 %']],
        'orez' => ['overflow', 'vyber', 'pozadi', 'Přesah obsahu', ['hidden' => 'oříznout', 'visible' => 'nechat']],
        'pozice' => ['position', 'vyber', 'pokrocile', 'Umístění', ['relative' => 'běžné (kotva pro vnořené)', 'sticky' => 'přilepit při posunu', 'absolute' => 'volně v nadřazeném', 'fixed' => 'pevně v okně']],
        'odshora' => ['top', 'mezera', 'pokrocile', 'Odshora', null],
        'zdola' => ['bottom', 'mezera', 'pokrocile', 'Zdola', null],
        'zleva' => ['left', 'mezera', 'pokrocile', 'Zleva', null],
        'zprava' => ['right', 'mezera', 'pokrocile', 'Zprava', null],
        'posun' => ['translate', 'vyber', 'pokrocile', 'Posun', ['0 -4px' => 'nadzvednout', '0 -0.5rem' => 'nadzvednout víc', '0 4px' => 'snížit', '-50% -50%' => 'vycentrovat (u volného umístění)']],
        'meritko' => ['scale', 'vyber', 'pokrocile', 'Měřítko', ['0.95' => '95 %', '1' => '100 %', '1.03' => '103 %', '1.05' => '105 %', '1.1' => '110 %']],
        'otoceni' => ['rotate', 'vyber', 'pokrocile', 'Otočení', ['-3deg' => '−3°', '3deg' => '3°', '-90deg' => '−90°', '90deg' => '90°', '180deg' => '180°']],
        'plynule' => ['transition', 'vyber', 'pokrocile', 'Plynulá změna (u najetí)', ['all 0.2s ease' => 'rychlá', 'all 0.4s ease' => 'pomalejší', 'none' => 'žádná']],
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
            'barva' => self::barva($hodnota),
            'zaobleni' => isset(DesignSystem::ZAOBLENI[$hodnota]) ? 'var(--ka-zaobleni-' . $hodnota . ')' : (preg_match(self::VZOR_DELKA, $hodnota) ? $hodnota : null),
            'stin' => isset(DesignSystem::STINY[$hodnota]) ? 'var(--ka-stin-' . $hodnota . ')' : ($hodnota === 'none' ? 'none' : self::stin($hodnota)),
            'ramecek' => isset($moznosti[$hodnota]) ? $hodnota : self::ramecek($hodnota),
            'radky' => preg_match('/^([1-9]|1[0-2])$/', $hodnota) ? 'repeat(' . $hodnota . ', auto)' : (preg_match('/^((\d{1,2}(\.\d)?fr|auto|min-content|max-content|\d{1,4}(px|rem))\s?){1,8}$/', $hodnota) ? trim($hodnota) : null),
            'oblasti' => self::oblasti($hodnota),
            'oblast' => preg_match('/^[a-z][a-z0-9-]{0,20}$/', $hodnota) ? $hodnota : null,
            'cislo' => preg_match('/^-?\d{1,3}$/', $hodnota) ? $hodnota : null,
            'sloupce' => self::sloupce($hodnota),
            'obrazek' => preg_match('#^(https://[^\s"\'()<>\\\\]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#', $hodnota) ? $hodnota : null,
            default => preg_match(self::VZOR_VOLNA, $hodnota) ? $hodnota : null,
        };
    }

    /**
     * CSS deklarace jako vlastnosti stylu (převod <style> z HTML na stavy třídy – breakpointy a najetí). Tokeny se vrátí
     * jako klíče („var(--ka-mezera-l)“ → „l“), zkratky padding/margin se rozloží. Co ve stylu obdobu nemá, vrátí null.
     *
     * @return array<string, string>|null klíč stylu => hodnota
     */
    public static function zCss(string $vlastnost, string $hodnota): ?array
    {
        $vlastnost = strtolower(trim($vlastnost));
        $hodnota = trim((string) preg_replace('/\s*!important$/i', '', trim($hodnota)));
        // běžné zápisy, které builder zná pod logickým jménem: margin-top → margin-block-start, flex-start → start
        $vlastnost = ['margin-top' => 'margin-block-start', 'margin-bottom' => 'margin-block-end', 'margin-left' => 'margin-inline-start', 'margin-right' => 'margin-inline-end'][$vlastnost] ?? $vlastnost;
        // zkratka background jen s barvou (background: #EFECE5) je barva pozadí
        if ($vlastnost === 'background' && preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([^()]*\)|var\(--ka-barva-[a-z0-9-]+\)|[a-z]+)$/i', $hodnota)) {
            $vlastnost = 'background-color';
        }
        if (in_array($vlastnost, ['align-items', 'align-self', 'justify-content'], true)) {
            $hodnota = ['flex-start' => 'start', 'flex-end' => 'end'][$hodnota] ?? $hodnota;
        }
        if ($vlastnost === 'text-align') {
            $hodnota = ['left' => 'start', 'right' => 'end'][$hodnota] ?? $hodnota;
        }
        $token = static fn (string $h): string => (string) preg_replace_callback('/var\(--ka-(mezera|krok|zaobleni|stin|barva)-([a-z0-9-]{1,20})\)/',
            static fn (array $m): string => match ($m[1]) {
                'mezera' => isset(DesignSystem::MEZERY[$m[2]]) ? $m[2] : $m[0],
                'krok' => in_array($m[2], DesignSystem::KROKY, true) ? $m[2] : $m[0],
                'zaobleni' => isset(DesignSystem::ZAOBLENI[$m[2]]) ? (string) $m[2] : $m[0],
                'stin' => isset(DesignSystem::STINY[$m[2]]) ? $m[2] : $m[0],
                default => isset(DesignSystem::TOKENY_BAREV[$m[2]]) ? $m[2] : $m[0],
            }, $h);
        $dvojice = static function (string $h): ?array {
            $casti = preg_split('/\s+/', trim($h)) ?: [];

            return match (count($casti)) {
                1 => [$casti[0], $casti[0]],
                2 => [$casti[0], $casti[1]],
                3 => $casti[0] === $casti[2] ? [$casti[0], $casti[1]] : null,
                4 => $casti[0] === $casti[2] && $casti[1] === $casti[3] ? [$casti[0], $casti[1]] : null,
                default => null,
            };
        };
        if (in_array($vlastnost, ['padding', 'margin'], true)) {
            $par = $dvojice($token($hodnota));
            if ($par === null) {
                return null;
            }
            $klice = $vlastnost === 'padding' ? ['odsazeni_y', 'odsazeni_x'] : null;
            if ($klice === null) {
                $vysledek = [];
                foreach (['okraj_nahore' => $par[0], 'okraj_dole' => $par[0], 'okraj_vlevo' => $par[1], 'okraj_vpravo' => $par[1]] as $k => $h) {
                    if ($h === 'auto' && str_starts_with($k, 'okraj_v')) {
                        $vysledek['na_stred'] = 'auto';
                        continue;
                    }
                    if (self::hodnota($k, $h) === null) {
                        return null;
                    }
                    $vysledek[$k] = $h;
                }

                return $vysledek;
            }

            return self::hodnota($klice[0], $par[0]) !== null && self::hodnota($klice[1], $par[1]) !== null ? [$klice[0] => $par[0], $klice[1] => $par[1]] : null;
        }
        $hodnota = $token($hodnota);
        if ($vlastnost === 'transform') {
            // posun a zvětšení (efekt najetí) mají ve stylu vlastní vlastnosti translate a scale
            $hodnota = match (true) {
                (bool) preg_match('/^translateY\(([^()]+)\)$/', $hodnota, $m) => '0 ' . trim($m[1]),
                (bool) preg_match('/^translate\(([^(),]+),\s*([^(),]+)\)$/', $hodnota, $m) => trim($m[1]) . ' ' . trim($m[2]),
                (bool) preg_match('/^scale\(([\d.]+)\)$/', $hodnota, $m) => $m[1],
                default => '',
            };
            $vlastnost = str_contains($hodnota, ' ') ? 'translate' : 'scale';
        }
        if ($vlastnost === 'grid-template-columns') {
            if (preg_match('/^repeat\(\s*([1-9]|1[0-2])\s*,\s*(minmax\(0,\s*1fr\)|1fr)\s*\)$/', $hodnota, $m)) {
                $hodnota = $m[1];
            } elseif (preg_match('/^repeat\(\s*auto-(fit|fill)\s*,\s*minmax\(\s*(?:min\(100%,\s*)?(\d{1,3}(?:\.\d{1,2})?(?:rem|px|ch))\)?\s*,\s*1fr\s*\)\s*\)$/', $hodnota, $m)) {
                $hodnota = 'auto:' . $m[2];
            } elseif (preg_match('/^1fr$/', $hodnota)) {
                $hodnota = '1';
            }
        }
        foreach (self::VLASTNOSTI as $klic => [$css]) {
            if ($css === $vlastnost && self::hodnota($klic, $hodnota) !== null) {
                return [$klic => $hodnota];
            }
        }

        return null;
    }

    /** Barva: token design systému, nebo bezpečně zapsaná vlastní barva. */
    public static function barva(string $hodnota): ?string
    {
        if (isset(DesignSystem::TOKENY_BAREV[$hodnota])) {
            return 'var(--ka-barva-' . $hodnota . ')';
        }

        return preg_match('/^(#[0-9a-f]{3,8}|transparent|currentColor|(rgba?|hsla?|oklch|oklab|lab|lch|hwb)\([0-9., %\/+-]{3,60}\)|var\(--ka-barva-[a-z-]{1,30}\))$/i', $hodnota) ? $hodnota : null;
    }

    /**
     * Vlastní stín z editoru stínu: až tři vrstvy „[inset] x y [rozostření] [roztažení] barva“ (barva i jako token, např. „0 8px 24px primarni“).
     */
    private static function stin(string $hodnota): ?string
    {
        $vrstvy = preg_split('/,(?![^(]*\))/', $hodnota) ?: [];
        if (count($vrstvy) > 3) {
            return null;
        }
        $css = [];
        foreach ($vrstvy as $vrstva) {
            if (!preg_match('/^\s*(inset\s+)?((?:-?\d{1,3}(?:\.\d{1,2})?(?:px|rem|em)?\s+){2,4})(\S.*?)\s*$/i', $vrstva, $m) || ($barva = self::barva($m[3])) === null) {
                return null;
            }
            $css[] = $m[1] . trim($m[2]) . ' ' . $barva;
        }

        return $css === [] ? null : implode(', ', $css);
    }

    /** Vlastní rámeček: „2px dashed primarni“ – šířka, styl čáry a barva (token nebo vlastní). */
    private static function ramecek(string $hodnota): ?string
    {
        if (!preg_match('/^(\d{1,2}(?:\.\d)?px)\s+(solid|dashed|dotted|double)\s+(\S+)$/', $hodnota, $m) || ($barva = self::barva($m[3])) === null) {
            return null;
        }

        return $m[1] . ' ' . $m[2] . ' ' . $barva;
    }

    /** Oblasti mřížky: řádky oddělené „/“, v řádku názvy oblastí (tečka = prázdná buňka); všechny řádky stejně dlouhé. */
    private static function oblasti(string $hodnota): ?string
    {
        $radky = array_map(fn (string $r): array => preg_split('/\s+/', trim($r)) ?: [], explode('/', $hodnota));
        if (count($radky) > 8 || count(array_unique(array_map('count', $radky))) !== 1 || count($radky[0]) > 12) {
            return null;
        }
        foreach ($radky as $radek) {
            foreach ($radek as $nazev) {
                if (!preg_match('/^([a-z][a-z0-9-]{0,20}|\.)$/', $nazev)) {
                    return null;
                }
            }
        }

        return implode(' ', array_map(fn (array $r): string => '"' . implode(' ', $r) . '"', $radky));
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
            if (isset($vlastnosti['typ_styl'])) {
                // typografický styl jako první: velikost nebo tloušťka nastavené zvlášť ho doladí (pozdější deklarace vyhrává)
                $vlastnosti = ['typ_styl' => $vlastnosti['typ_styl']] + $vlastnosti;
            }
            foreach ($vlastnosti as $klic => $hodnota) {
                $css = self::hodnota($klic, (string) $hodnota);
                if ($css === null) {
                    continue;
                }
                [$vlastnost, $typ] = self::VLASTNOSTI[$klic];
                if ($klic === 'typ_styl') {
                    $radky[] = 'font: var(--ka-typ-' . $css . ')';
                    if ($css === 'nadtitulek') {
                        array_push($radky, 'text-transform: uppercase', 'letter-spacing: 0.08em');
                    }
                    continue;
                }
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
            $blok = '';
            if (($d = $deklarace($styl[$stav] ?? [])) !== '') {
                $blok .= $selektor . ' { ' . $d . ' } ';
            }
            if (($d = $deklarace($styl['hover_' . $stav] ?? [])) !== '') {
                $blok .= $selektor . ':is(:hover, :focus-visible) { ' . $d . ' } ';
            }
            if (($d = $deklarace($styl['aktivni_' . $stav] ?? [])) !== '') {
                $blok .= $selektor . ':active { ' . $d . ' } ';
            }
            if ($blok !== '') {
                $css .= self::STAVY[$stav] . ' { ' . trim($blok) . " }\n";
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
            // zakázané: načítání cizích zdrojů (url, image-set, image, src), komentáře a neuzavřené uvozovky – ty by rozbily CSS zbytku stránky
            if (preg_match('/^(--ka-[a-z0-9-]{1,40}|-?[a-z][a-z-]{1,40})\s*:\s*([^;{}<>\\\\@]{1,200})$/i', $deklarace, $m)
                && !preg_match('/url\s*\(|image-set|image\s*\(|src\s*\(|cross-fade|element\s*\(|expression|javascript|behavior|-moz-binding|\/\*|\*\//i', $m[2])
                && substr_count($m[2], '"') % 2 === 0 && substr_count($m[2], "'") % 2 === 0) {
                $vystup[] = strtolower($m[1]) . ': ' . trim($m[2]) . ';';
            } else {
                $zahozeno[] = $deklarace;
            }
        }

        return implode(' ', $vystup);
    }
}
