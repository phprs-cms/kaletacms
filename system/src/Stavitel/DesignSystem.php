<?php

declare(strict_types=1);

namespace Kaleta\Stavitel;

use Kaleta\Core\Settings;
use Kaleta\Front\Identita;

/**
 * Design systém webu: pár rozhodnutí (barvy, písma, základní velikost a poměr škály, šířka, zaoblení), ze kterých se dopočítají
 * CSS proměnné (tokeny) pro šablonu i stavitel. Typografie a mezery jsou fluidní (clamp mezi šířkou telefonu a velkého monitoru),
 * odstíny barev se míchají v prohlížeči (color-mix v OKLCH) – web tak potřebuje jen hrstku čísel a nic se neduplikuje.
 *
 * Uloženo v nastavení „design_system“ (JSON). Chybějící klíč = výchozí hodnota; hlavní barva a písma se berou i ze starší Identity webu.
 */
final class DesignSystem
{
    /** Barvy, které si web volí; ostatní odstíny se z nich dopočítají. */
    public const array BARVY = ['primarni' => 'Hlavní', 'sekundarni' => 'Doplňková', 'text' => 'Text', 'pozadi' => 'Pozadí', 'plocha' => 'Plocha (karty, patička)'];

    /** Barevné tokeny, ze kterých se vybírá ve stavitelu (klíč => popis). */
    public const array TOKENY_BAREV = [
        'primarni' => 'Hlavní', 'primarni-jemna' => 'Hlavní – jemná', 'na-primarni' => 'Text na hlavní', 'sekundarni' => 'Doplňková',
        'text' => 'Text', 'tlumeny' => 'Tlumený text', 'pozadi' => 'Pozadí', 'plocha' => 'Plocha', 'linka' => 'Linka', 'bila' => 'Bílá', 'cerna' => 'Černá',
    ];

    public const array MEZERY = ['2xs' => 0.25, 'xs' => 0.5, 's' => 0.75, 'm' => 1, 'l' => 1.5, 'xl' => 2.5, '2xl' => 4, '3xl' => 6];
    public const array KROKY = ['-1', '0', '1', '2', '3', '4', '5'];
    public const array ZAOBLENI = ['0' => '0', 's' => '0.375rem', 'm' => '0.75rem', 'l' => '1.25rem', 'plne' => '999px'];
    public const array STINY = [
        's' => '0 1px 2px rgb(0 0 0 / 0.06), 0 1px 3px rgb(0 0 0 / 0.1)',
        'm' => '0 4px 12px rgb(0 0 0 / 0.08), 0 2px 4px rgb(0 0 0 / 0.06)',
        'l' => '0 18px 40px rgb(0 0 0 / 0.12), 0 6px 12px rgb(0 0 0 / 0.06)',
    ];

    /**
     * Typografické styly: pojmenovaná kombinace velikosti, tloušťky, řádkování a písma. Prvek dostane styl jedním výběrem
     * („Nadpis sekce“, „Perex“) a změna ve Vzhledu se projeví na celém webu. klíč => [název, krok, tloušťka, řádkování, písmo titulků]
     */
    public const array TYPOGRAFIE = [
        'titulek' => ['Hlavní titulek', '5', 800, 1.1, true],
        'nadpis-sekce' => ['Nadpis sekce', '4', 700, 1.15, true],
        'podnadpis' => ['Podnadpis', '2', 600, 1.3, true],
        'perex' => ['Perex', '1', 400, 1.55, false],
        'text' => ['Běžný text', '0', 400, 1.6, false],
        'drobny' => ['Drobný text', '-1', 400, 1.5, false],
        'nadtitulek' => ['Nadtitulek', '-1', 600, 1.3, false],
    ];

    /** Tloušťky písma nabízené u typografických stylů. */
    public const array TLOUSTKY = [300 => 'tenké', 400 => 'normální', 500 => 'střední', 600 => 'polotučné', 700 => 'tučné', 800 => 'extra tučné'];

    /**
     * Pořadí vrstev kaskády pro celý web: tokeny, společné prvky (image/web.css), šablona, základ prvků stavitele, třídy, styl prvků.
     * Pozdější vrstva vyhrává bez ohledu na specifičnost – nic se nemusí přebíjet selektory ani !important.
     */
    public const string VRSTVY = '@layer tokeny, spolecne, sablona, stavitel, tridy, prvky;';

    /** Fluidní škály se roztahují mezi těmito šířkami okna (rem). */
    private const float VIEWPORT_MIN = 22.5;
    private const float VIEWPORT_MAX = 80;

    public const array VYCHOZI = [
        'barvy' => ['primarni' => '#2b5be3', 'sekundarni' => '#0f766e', 'text' => '#16181d', 'pozadi' => '#ffffff', 'plocha' => '#f5f6f8'],
        'barvy_tmave' => ['text' => '#eceef2', 'pozadi' => '#121418', 'plocha' => '#1b1e24'],
        'pismo_titulky' => 'moderni', 'pismo_text' => 'moderni',
        'zaklad_min' => 1.0, 'zaklad_max' => 1.125, 'pomer_min' => 1.2, 'pomer_max' => 1.25,
        'sirka' => 72, 'sirka_textu' => 44, 'zaobleni' => 'm',
    ];

    /** Poměry typografické škály (krok n = základ × poměr^n): čím větší, tím víc se nadpisy liší od textu. */
    public const array POMERY = ['1.125' => 'jemný (1,125)', '1.2' => 'klidný (1,2)', '1.25' => 'vyvážený (1,25)', '1.333' => 'výrazný (1,333)', '1.414' => 'dramatický (1,414)', '1.5' => 'plakátový (1,5)'];

    /**
     * Předvolby: celý vzhled webu jedním klikem, pak se dá doladit. Nezadané klíče mají výchozí hodnotu.
     * klíč => [název, popis, hodnoty]
     */
    public const array PREDVOLBY = [
        'firemni' => ['Firemní', 'Modrá, bezpatkové písmo, střídmé zaoblení', [
            'barvy' => ['primarni' => '#2b5be3', 'sekundarni' => '#0f766e', 'text' => '#16181d', 'pozadi' => '#ffffff', 'plocha' => '#f5f6f8'],
            'pismo_titulky' => 'moderni', 'pismo_text' => 'moderni', 'pomer_min' => 1.2, 'pomer_max' => 1.25, 'zaobleni' => 'm',
        ]],
        'remeslo' => ['Řemeslo', 'Teplé zemité barvy, patkové titulky', [
            'barvy' => ['primarni' => '#9a3412', 'sekundarni' => '#3f6212', 'text' => '#1c1917', 'pozadi' => '#fffbf5', 'plocha' => '#f5ede1'],
            'pismo_titulky' => 'klasicke', 'pismo_text' => 'moderni', 'pomer_min' => 1.2, 'pomer_max' => 1.333, 'zaobleni' => 's',
        ]],
        'pratelsky' => ['Přátelský', 'Svěží zelená, zaoblené písmo i rohy', [
            'barvy' => ['primarni' => '#047857', 'sekundarni' => '#7c3aed', 'text' => '#132a22', 'pozadi' => '#ffffff', 'plocha' => '#effaf5'],
            'pismo_titulky' => 'zaoblene', 'pismo_text' => 'moderni', 'pomer_min' => 1.2, 'pomer_max' => 1.25, 'zaobleni' => 'l',
        ]],
        'elegantni' => ['Elegantní', 'Tmavé tóny, velké patkové nadpisy, ostré hrany', [
            'barvy' => ['primarni' => '#1e293b', 'sekundarni' => '#a16207', 'text' => '#0f172a', 'pozadi' => '#fcfcfa', 'plocha' => '#f1f0ea'],
            'pismo_titulky' => 'elegantni', 'pismo_text' => 'knizni', 'pomer_min' => 1.25, 'pomer_max' => 1.414, 'zaobleni' => '0',
        ]],
        'technologie' => ['Technologie', 'Fialová, výrazný grotesk, velký kontrast', [
            'barvy' => ['primarni' => '#6d28d9', 'sekundarni' => '#0e7490', 'text' => '#0b0b12', 'pozadi' => '#ffffff', 'plocha' => '#f4f3fb'],
            'pismo_titulky' => 'grotesk', 'pismo_text' => 'moderni', 'pomer_min' => 1.25, 'pomer_max' => 1.414, 'zaobleni' => 'm',
        ]],
    ];

    /** Předvolba jako kompletní design systém. @return array<string, mixed>|null */
    public static function predvolba(string $klic): ?array
    {
        return isset(self::PREDVOLBY[$klic]) ? self::vycisti(self::PREDVOLBY[$klic][2] + self::VYCHOZI) : null;
    }

    /**
     * Čitelnost dvojic barev podle WCAG 2.2 AA (text 4,5 : 1). Obecné dvojice, které se na webu opravdu potkávají.
     *
     * @return list<array{popis: string, pomer: float, ok: bool}>
     */
    public static function kontrasty(array $ds): array
    {
        $b = $ds['barvy'];
        $dvojice = [
            ['Text na pozadí', $b['text'], $b['pozadi']],
            ['Text na ploše', $b['text'], $b['plocha']],
            ['Odkaz (hlavní barva) na pozadí', $b['primarni'], $b['pozadi']],
            ['Text tlačítka na hlavní barvě', self::kontrastni($b['primarni']), $b['primarni']],
            ['Doplňková barva na pozadí', $b['sekundarni'], $b['pozadi']],
        ];

        return array_map(fn (array $d): array => ['popis' => $d[0], 'pomer' => $p = self::kontrast($d[1], $d[2]), 'ok' => $p >= 4.5], $dvojice);
    }

    /** @return array<string, mixed> uložená hodnota doplněná o výchozí (a o barvu a písma ze starší Identity webu) */
    public static function nacti(Settings $web): array
    {
        $ulozeno = json_decode($web->get('design_system'), true);
        $ds = is_array($ulozeno) ? $ulozeno + self::VYCHOZI : self::VYCHOZI;
        $ds['barvy'] = (is_array($ulozeno['barvy'] ?? null) ? $ulozeno['barvy'] : []) + self::VYCHOZI['barvy'];
        $ds['barvy_tmave'] = (is_array($ulozeno['barvy_tmave'] ?? null) ? $ulozeno['barvy_tmave'] : []) + self::VYCHOZI['barvy_tmave'];
        if (!isset($ulozeno['barvy']['primarni']) && preg_match('/^#[0-9a-f]{6}$/i', $web->get('brand_akcent'))) {
            $ds['barvy']['primarni'] = strtolower($web->get('brand_akcent'));
        }
        foreach (['pismo_titulky' => 'brand_pismo_titulky', 'pismo_text' => 'brand_pismo_text'] as $klic => $stary) {
            if (!isset($ulozeno[$klic]) && $web->get($stary) !== '' && $web->get($stary) !== 'vychozi') {
                $ds[$klic] = $web->get($stary);
            }
        }

        return self::vycisti($ds);
    }

    /**
     * Hodnoty z formuláře nebo od AI: jen známé klíče ve správném tvaru a rozsahu, jinak výchozí.
     *
     * @param array<string, mixed> $ds
     * @return array<string, mixed>
     */
    public static function vycisti(array $ds): array
    {
        $barva = fn (mixed $v, string $vychozi): string => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $vychozi;
        $cislo = fn (mixed $v, float $min, float $max, float $vychozi): float => is_numeric($v) ? round(max($min, min($max, (float) $v)), 3) : $vychozi;
        $v = self::VYCHOZI;
        $cisty = [
            'barvy' => [], 'barvy_tmave' => [],
            'vlastni_pisma' => self::vlastniPisma($ds['vlastni_pisma'] ?? []),
            'pismo_titulky' => isset(Identita::PISMA_TITULKU[$ds['pismo_titulky'] ?? '']) && $ds['pismo_titulky'] !== 'vychozi' ? $ds['pismo_titulky'] : $v['pismo_titulky'],
            'pismo_text' => isset(Identita::PISMA_TEXTU[$ds['pismo_text'] ?? '']) && $ds['pismo_text'] !== 'vychozi' ? $ds['pismo_text'] : $v['pismo_text'],
            'zaklad_min' => $cislo($ds['zaklad_min'] ?? null, 0.8, 1.5, $v['zaklad_min']),
            'zaklad_max' => $cislo($ds['zaklad_max'] ?? null, 0.8, 1.6, $v['zaklad_max']),
            'pomer_min' => $cislo($ds['pomer_min'] ?? null, 1.05, 1.5, $v['pomer_min']),
            'pomer_max' => $cislo($ds['pomer_max'] ?? null, 1.05, 1.62, $v['pomer_max']),
            'sirka' => $cislo($ds['sirka'] ?? null, 40, 120, $v['sirka']),
            'sirka_textu' => $cislo($ds['sirka_textu'] ?? null, 28, 60, $v['sirka_textu']),
            'zaobleni' => isset(self::ZAOBLENI[$ds['zaobleni'] ?? '']) ? $ds['zaobleni'] : $v['zaobleni'],
            'typografie' => [],
        ];
        // typografické styly: uloží se jen to, co se liší od výchozího (krok a tloušťka)
        foreach (self::TYPOGRAFIE as $klic => [, $krok, $tloustka]) {
            $t = is_array($ds['typografie'][$klic] ?? null) ? $ds['typografie'][$klic] : [];
            $zmena = [];
            if (in_array((string) ($t['krok'] ?? ''), self::KROKY, true) && (string) $t['krok'] !== $krok) {
                $zmena['krok'] = (string) $t['krok'];
            }
            if (isset(self::TLOUSTKY[(int) ($t['tloustka'] ?? 0)]) && (int) $t['tloustka'] !== $tloustka) {
                $zmena['tloustka'] = (int) $t['tloustka'];
            }
            if ($zmena !== []) {
                $cisty['typografie'][$klic] = $zmena;
            }
        }
        // vlastní písmo (vlastni-1…3) jde vybrat jen, když je nahrané
        foreach (['pismo_titulky', 'pismo_text'] as $klic) {
            if (preg_match('/^vlastni-([1-3])$/', (string) ($ds[$klic] ?? ''), $m) && isset($cisty['vlastni_pisma'][(int) $m[1] - 1])) {
                $cisty[$klic] = $ds[$klic];
            }
        }
        foreach (self::BARVY as $klic => $_) {
            $cisty['barvy'][$klic] = $barva($ds['barvy'][$klic] ?? null, $v['barvy'][$klic]);
        }
        foreach ($v['barvy_tmave'] as $klic => $vychozi) {
            $cisty['barvy_tmave'][$klic] = $barva($ds['barvy_tmave'][$klic] ?? null, $vychozi);
        }

        return $cisty;
    }

    /**
     * Vlastní písma webu (soubory WOFF2 z Médií, hostované na vlastním serveru – žádné cizí servery ani souhlas).
     *
     * @return list<array{nazev: string, soubor: string, tucny: string}>
     */
    private static function vlastniPisma(mixed $pisma): array
    {
        $soubor = fn (mixed $v): string => is_string($v) && preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,200}\.woff2?)$#', trim($v), $m) && !str_contains($m[1], '..') ? $m[1] : '';
        $vysledek = [];
        foreach (array_slice(is_array($pisma) ? $pisma : [], 0, 3) as $p) {
            $nazev = is_array($p) ? trim((string) preg_replace('/[^\p{L}\p{N} -]/u', '', (string) ($p['nazev'] ?? ''))) : '';
            if ($nazev !== '' && ($s = $soubor($p['soubor'] ?? null)) !== '') {
                $vysledek[] = ['nazev' => mb_substr($nazev, 0, 40), 'soubor' => $s, 'tucny' => $soubor($p['tucny'] ?? null)];
            }
        }

        return $vysledek;
    }

    /** Hodnota font-family pro zvolené písmo (i vlastní); záloha je systémové písmo stejného charakteru. */
    public static function rodina(array $ds, string $klic, bool $titulky): string
    {
        if (preg_match('/^vlastni-([1-3])$/', $klic, $m) && isset($ds['vlastni_pisma'][(int) $m[1] - 1])) {
            return '"' . $ds['vlastni_pisma'][(int) $m[1] - 1]['nazev'] . '", system-ui, -apple-system, "Segoe UI", sans-serif';
        }

        return ($titulky ? Identita::PISMA_TITULKU : Identita::PISMA_TEXTU)[$klic][2] ?? 'system-ui, sans-serif';
    }

    /** Tokeny jako CSS proměnné v první vrstvě kaskády; šablona a stavitel je jen používají. $zaklad = složka instalace (pro soubory písem). */
    public static function css(array $ds, string $zaklad = ''): string
    {
        $pisma = '';
        foreach ($ds['vlastni_pisma'] ?? [] as $p) {
            // jeden soubor = běžný řez (i variabilní písmo se všemi tloušťkami), druhý případně tučný
            $pisma .= '@font-face { font-family: "' . $p['nazev'] . '"; src: url("' . $zaklad . '/' . $p['soubor'] . '") format("woff2"); font-weight: ' . ($p['tucny'] !== '' ? '400' : '100 900') . '; font-display: swap; }' . "\n";
            if ($p['tucny'] !== '') {
                $pisma .= '@font-face { font-family: "' . $p['nazev'] . '"; src: url("' . $zaklad . '/' . $p['tucny'] . '") format("woff2"); font-weight: 600 900; font-display: swap; }' . "\n";
            }
        }
        $b = $ds['barvy'];
        $p = [
            '--ka-barva-primarni' => $b['primarni'], '--ka-barva-sekundarni' => $b['sekundarni'], '--ka-barva-text' => $b['text'],
            '--ka-barva-pozadi' => $b['pozadi'], '--ka-barva-plocha' => $b['plocha'],
            '--ka-barva-na-primarni' => self::kontrastni($b['primarni']),
            '--ka-barva-bila' => '#ffffff', '--ka-barva-cerna' => '#000000',
            // text světlého a tmavého režimu napevno – pro plochy, které se s režimem nemění (bílé a černé pozadí)
            '--ka-barva-text-svetle' => $b['text'], '--ka-barva-text-tmave' => $ds['barvy_tmave']['text'],
            '--ka-barva-tlumeny' => 'color-mix(in oklch, var(--ka-barva-text) 64%, var(--ka-barva-pozadi))',
            '--ka-barva-linka' => 'color-mix(in oklch, var(--ka-barva-text) 14%, var(--ka-barva-pozadi))',
            '--ka-barva-primarni-jemna' => 'color-mix(in oklch, var(--ka-barva-primarni) 12%, var(--ka-barva-pozadi))',
            '--ka-akcent' => 'var(--ka-barva-primarni)', // starší jméno z Identity webu
            '--ka-pismo-text' => self::rodina($ds, $ds['pismo_text'], false),
            '--ka-pismo-titulky' => self::rodina($ds, $ds['pismo_titulky'], true),
            '--ka-sirka' => $ds['sirka'] . 'rem', '--ka-sirka-textu' => $ds['sirka_textu'] . 'rem',
            '--ka-zaobleni' => 'var(--ka-zaobleni-' . $ds['zaobleni'] . ')',
        ];
        // typografická škála: krok n = základ × poměr^n, na telefonu menší základ i poměr, na velkém monitoru větší
        foreach (self::KROKY as $n) {
            $p['--ka-krok-' . $n] = self::clamp($ds['zaklad_min'] * $ds['pomer_min'] ** (int) $n, $ds['zaklad_max'] * $ds['pomer_max'] ** (int) $n);
        }
        foreach (self::MEZERY as $klic => $nasobek) {
            $p['--ka-mezera-' . $klic] = self::clamp($ds['zaklad_min'] * $nasobek, $ds['zaklad_max'] * $nasobek * ($nasobek >= 2 ? 1.25 : 1));
        }
        foreach (self::ZAOBLENI as $klic => $hodnota) {
            $p['--ka-zaobleni-' . $klic] = $hodnota;
        }
        foreach (self::STINY as $klic => $hodnota) {
            $p['--ka-stin-' . $klic] = $hodnota;
        }
        foreach (self::TYPOGRAFIE as $klic => [, $krok, $tloustka, $radkovani, $titulky]) {
            $t = ($ds['typografie'] ?? [])[$klic] ?? [];
            $p['--ka-typ-' . $klic] = ($t['tloustka'] ?? $tloustka) . ' var(--ka-krok-' . ($t['krok'] ?? $krok) . ')/' . $radkovani . ' var(--ka-pismo-' . ($titulky ? 'titulky' : 'text') . ')';
        }
        $radky = array_map(fn (string $k, string $h): string => "\t{$k}: {$h};", array_keys($p), $p);
        $tmave = array_map(fn (string $k, string $h): string => "\t\t--ka-barva-{$k}: {$h};", array_keys($ds['barvy_tmave']), $ds['barvy_tmave']);

        return self::VRSTVY . "\n" . $pisma . "@layer tokeny {\n:root {\n" . implode("\n", $radky) . "\n}\n"
            . "@media (prefers-color-scheme: dark) {\n\t:root[data-tmavy] {\n" . implode("\n", $tmave) . "\n\t}\n}\n}\n";
    }

    /**
     * Design tokeny ve formátu W3C Design Tokens (DTCG, https://tr.designtokens.org/format/) pro Figmu, Tokens Studio a jiné nástroje.
     * Úplný design systém Kalety je navíc v $extensions, aby se při importu zpět nic neztratilo.
     *
     * @param array<string, mixed> $ds
     * @return array<string, mixed>
     */
    public static function doDtcg(array $ds): array
    {
        $barvy = fn (array $b): array => array_map(fn (string $hex): array => ['$type' => 'color', '$value' => $hex], $b);
        $pismo = fn (string $klic, bool $titulky): array => ['$type' => 'fontFamily', '$value' => array_map(fn (string $x): string => trim($x, " \"'"), explode(',', self::rodina($ds, $klic, $titulky)))];
        $kroky = [];
        foreach (self::KROKY as $n) {
            $kroky[$n] = ['$type' => 'dimension', '$value' => ['value' => round($ds['zaklad_max'] * $ds['pomer_max'] ** (int) $n, 3), 'unit' => 'rem'], '$description' => 'monitor; na telefonu ' . round($ds['zaklad_min'] * $ds['pomer_min'] ** (int) $n, 3) . ' rem'];
        }
        $typografie = [];
        foreach (self::TYPOGRAFIE as $klic => [$nazev, $krok, $tloustka, $radkovani, $titulky]) {
            $t = ($ds['typografie'] ?? [])[$klic] ?? [];
            $typografie[$klic] = ['$type' => 'typography', '$description' => $nazev, '$value' => [
                'fontFamily' => '{pismo.' . ($titulky ? 'titulky' : 'text') . '}', 'fontSize' => '{velikost.' . ($t['krok'] ?? $krok) . '}',
                'fontWeight' => $t['tloustka'] ?? $tloustka, 'lineHeight' => $radkovani, 'letterSpacing' => ['value' => $klic === 'nadtitulek' ? 0.08 : 0, 'unit' => 'rem'],
            ]];
        }

        return [
            'barva' => $barvy($ds['barvy']),
            'barva-tmava' => $barvy($ds['barvy_tmave']),
            'pismo' => ['titulky' => $pismo($ds['pismo_titulky'], true), 'text' => $pismo($ds['pismo_text'], false)],
            'velikost' => $kroky,
            'typografie' => $typografie,
            'mezera' => array_map(fn (float $n): array => ['$type' => 'dimension', '$value' => ['value' => round($ds['zaklad_max'] * $n, 3), 'unit' => 'rem']], self::MEZERY),
            'zaobleni' => ['$type' => 'dimension', '$value' => ['value' => (float) (self::ZAOBLENI[$ds['zaobleni']] === '999px' ? 999 : (float) self::ZAOBLENI[$ds['zaobleni']]), 'unit' => self::ZAOBLENI[$ds['zaobleni']] === '999px' ? 'px' : 'rem']],
            'sirka' => ['obsah' => ['$type' => 'dimension', '$value' => ['value' => $ds['sirka'], 'unit' => 'rem']], 'text' => ['$type' => 'dimension', '$value' => ['value' => $ds['sirka_textu'], 'unit' => 'rem']]],
            '$extensions' => ['cz.kaleta' => ['design_system' => $ds]],
        ];
    }

    /**
     * Design systém z tokenů DTCG: z exportu Kalety celý (rozšíření cz.kaleta), z cizího nástroje aspoň barvy – podle našich
     * klíčů i běžných anglických názvů (primary, secondary, text, background, surface). Ostatní zůstává, jak je.
     *
     * @param array<string, mixed> $tokeny
     * @param array<string, mixed> $ds stávající design systém
     * @return array<string, mixed>|null null = soubor neobsahuje nic použitelného
     */
    public static function zDtcg(array $tokeny, array $ds): ?array
    {
        if (is_array($tokeny['$extensions']['cz.kaleta']['design_system'] ?? null)) {
            return self::vycisti($tokeny['$extensions']['cz.kaleta']['design_system'] + $ds);
        }
        $nazvy = ['primarni' => ['primarni', 'primary', 'brand', 'accent'], 'sekundarni' => ['sekundarni', 'secondary'], 'text' => ['text', 'foreground', 'on-background'],
            'pozadi' => ['pozadi', 'background', 'bg'], 'plocha' => ['plocha', 'surface', 'muted']];
        $nalezene = [];
        $projdi = function (array $skupina, string $cesta) use (&$projdi, &$nalezene): void {
            foreach ($skupina as $klic => $hodnota) {
                if (!is_array($hodnota) || str_starts_with((string) $klic, '$')) {
                    continue;
                }
                if (isset($hodnota['$value']) && is_string($hodnota['$value']) && preg_match('/^#[0-9a-f]{6}$/i', $hodnota['$value'])) {
                    $nalezene[strtolower($cesta . '.' . $klic)] = strtolower($hodnota['$value']);
                } else {
                    $projdi($hodnota, $cesta . '.' . $klic);
                }
            }
        };
        $projdi($tokeny, '');
        $zmena = false;
        foreach ($nazvy as $nas => $kandidati) {
            foreach ($nalezene as $cesta => $hex) {
                if (!str_contains($cesta, 'tmav') && !str_contains($cesta, 'dark') && in_array(substr($cesta, strrpos($cesta, '.') + 1), $kandidati, true)) {
                    $ds['barvy'][$nas] = $hex;
                    $zmena = true;
                    break;
                }
            }
        }

        return $zmena ? self::vycisti($ds) : null;
    }

    /** Fluidní hodnota v rem mezi VIEWPORT_MIN a VIEWPORT_MAX. */
    public static function clamp(float $min, float $max): string
    {
        if (abs($max - $min) < 0.001) {
            return self::rem($min);
        }
        $sklon = ($max - $min) / (self::VIEWPORT_MAX - self::VIEWPORT_MIN);
        $posun = $min - $sklon * self::VIEWPORT_MIN;
        [$dolni, $horni] = $min < $max ? [$min, $max] : [$max, $min];

        return 'clamp(' . self::rem($dolni) . ', ' . self::rem($posun) . ' + ' . round($sklon * 100, 4) . 'vw, ' . self::rem($horni) . ')';
    }

    private static function rem(float $hodnota): string
    {
        return rtrim(rtrim(number_format($hodnota, 4, '.', ''), '0'), '.') . 'rem';
    }

    /** Bílá nebo téměř černá – podle toho, co má na dané barvě lepší kontrast (WCAG relativní jas). */
    public static function kontrastni(string $hex): string
    {
        return self::kontrast($hex, '#ffffff') >= self::kontrast($hex, '#111111') ? '#ffffff' : '#111111';
    }

    /** Kontrastní poměr dvou barev podle WCAG 2.2 (1–21). */
    public static function kontrast(string $a, string $b): float
    {
        $jas = function (string $hex): float {
            $kanaly = array_map(fn (string $h): float => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
            $lin = array_map(fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $kanaly);

            return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
        };
        [$svetlejsi, $tmavsi] = [max($jas($a), $jas($b)), min($jas($a), $jas($b))];

        return round(($svetlejsi + 0.05) / ($tmavsi + 0.05), 2);
    }
}
