<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use MiroCMS\Core\Settings;
use MiroCMS\Front\Identita;

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
            'pismo_titulky' => isset(Identita::PISMA_TITULKU[$ds['pismo_titulky'] ?? '']) && $ds['pismo_titulky'] !== 'vychozi' ? $ds['pismo_titulky'] : $v['pismo_titulky'],
            'pismo_text' => isset(Identita::PISMA_TEXTU[$ds['pismo_text'] ?? '']) && $ds['pismo_text'] !== 'vychozi' ? $ds['pismo_text'] : $v['pismo_text'],
            'zaklad_min' => $cislo($ds['zaklad_min'] ?? null, 0.8, 1.5, $v['zaklad_min']),
            'zaklad_max' => $cislo($ds['zaklad_max'] ?? null, 0.8, 1.6, $v['zaklad_max']),
            'pomer_min' => $cislo($ds['pomer_min'] ?? null, 1.05, 1.5, $v['pomer_min']),
            'pomer_max' => $cislo($ds['pomer_max'] ?? null, 1.05, 1.62, $v['pomer_max']),
            'sirka' => $cislo($ds['sirka'] ?? null, 40, 120, $v['sirka']),
            'sirka_textu' => $cislo($ds['sirka_textu'] ?? null, 28, 60, $v['sirka_textu']),
            'zaobleni' => isset(self::ZAOBLENI[$ds['zaobleni'] ?? '']) ? $ds['zaobleni'] : $v['zaobleni'],
        ];
        foreach (self::BARVY as $klic => $_) {
            $cisty['barvy'][$klic] = $barva($ds['barvy'][$klic] ?? null, $v['barvy'][$klic]);
        }
        foreach ($v['barvy_tmave'] as $klic => $vychozi) {
            $cisty['barvy_tmave'][$klic] = $barva($ds['barvy_tmave'][$klic] ?? null, $vychozi);
        }

        return $cisty;
    }

    /** Tokeny jako CSS proměnné v první vrstvě kaskády; šablona a stavitel je jen používají. */
    public static function css(array $ds): string
    {
        $b = $ds['barvy'];
        $p = [
            '--mc-barva-primarni' => $b['primarni'], '--mc-barva-sekundarni' => $b['sekundarni'], '--mc-barva-text' => $b['text'],
            '--mc-barva-pozadi' => $b['pozadi'], '--mc-barva-plocha' => $b['plocha'],
            '--mc-barva-na-primarni' => self::kontrastni($b['primarni']),
            '--mc-barva-bila' => '#ffffff', '--mc-barva-cerna' => '#000000',
            '--mc-barva-tlumeny' => 'color-mix(in oklch, var(--mc-barva-text) 64%, var(--mc-barva-pozadi))',
            '--mc-barva-linka' => 'color-mix(in oklch, var(--mc-barva-text) 14%, var(--mc-barva-pozadi))',
            '--mc-barva-primarni-jemna' => 'color-mix(in oklch, var(--mc-barva-primarni) 12%, var(--mc-barva-pozadi))',
            '--mc-akcent' => 'var(--mc-barva-primarni)', // starší jméno z Identity webu
            '--mc-pismo-text' => Identita::PISMA_TEXTU[$ds['pismo_text']][2],
            '--mc-pismo-titulky' => Identita::PISMA_TITULKU[$ds['pismo_titulky']][2],
            '--mc-sirka' => $ds['sirka'] . 'rem', '--mc-sirka-textu' => $ds['sirka_textu'] . 'rem',
            '--mc-zaobleni' => 'var(--mc-zaobleni-' . $ds['zaobleni'] . ')',
        ];
        // typografická škála: krok n = základ × poměr^n, na telefonu menší základ i poměr, na velkém monitoru větší
        foreach (self::KROKY as $n) {
            $p['--mc-krok-' . $n] = self::clamp($ds['zaklad_min'] * $ds['pomer_min'] ** (int) $n, $ds['zaklad_max'] * $ds['pomer_max'] ** (int) $n);
        }
        foreach (self::MEZERY as $klic => $nasobek) {
            $p['--mc-mezera-' . $klic] = self::clamp($ds['zaklad_min'] * $nasobek, $ds['zaklad_max'] * $nasobek * ($nasobek >= 2 ? 1.25 : 1));
        }
        foreach (self::ZAOBLENI as $klic => $hodnota) {
            $p['--mc-zaobleni-' . $klic] = $hodnota;
        }
        foreach (self::STINY as $klic => $hodnota) {
            $p['--mc-stin-' . $klic] = $hodnota;
        }
        $radky = array_map(fn (string $k, string $h): string => "\t{$k}: {$h};", array_keys($p), $p);
        $tmave = array_map(fn (string $k, string $h): string => "\t\t--mc-barva-{$k}: {$h};", array_keys($ds['barvy_tmave']), $ds['barvy_tmave']);

        return self::VRSTVY . "\n@layer tokeny {\n:root {\n" . implode("\n", $radky) . "\n}\n"
            . "@media (prefers-color-scheme: dark) {\n\t:root[data-tmavy] {\n" . implode("\n", $tmave) . "\n\t}\n}\n}\n";
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
