<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;
use Kaleta\Stavitel\Stavba;

/**
 * Karusel: vodorovný pás snímků (vnořené prvky) s přichycením při posunu – na telefonu prstem, na počítači šipkami
 * (image/web.js). Bez skriptu jde pás posouvat taky. Nic se samo neposouvá – automatické střídání vadí čtení i přístupnosti.
 */
final class Karusel extends Prvek
{
    public const string TYP = 'karusel';
    public const string NAZEV = 'Karusel';
    public const string POPIS = 'Snímky vedle sebe s posunem šipkami nebo prstem – reference, fotky, karty.';
    public const string IKONA = 'karusel';
    public const string SKUPINA = 'Rozložení';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['div', 'section'];

    public static function vlastnosti(): array
    {
        return [
            'naraz' => ['typ' => 'vyber', 'popisek' => 'Snímků vedle sebe na počítači', 'vychozi' => '1', 'moznosti' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4']],
            'popis' => ['typ' => 'text', 'popisek' => 'Název pro čtečky (např. Reference)', 'vychozi' => '', 'max' => 120],
        ];
    }

    public static function vychoziDeti(): array
    {
        $snimek = fn (string $n): array => ['styl' => ['zaklad' => ['odsazeni_y' => 'l', 'odsazeni_x' => 'l', 'pozadi' => 'plocha', 'zaobleni' => 'm']]] + Stavba::novy('kontejner', [], [
            ['znacka' => 'h3'] + Stavba::novy('nadpis', ['text' => $n]),
            Stavba::novy('text', ['html' => '<p>' . t('Text snímku.') . '</p>']),
        ]);

        return [$snimek(t('První snímek')), $snimek(t('Druhý snímek')), $snimek(t('Třetí snímek'))];
    }

    public static function zakladniCss(): string
    {
        return '.ka-karusel { position: relative; }
.ka-karusel-pas { display: flex; gap: var(--ka-mezera-m); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: thin; padding-block-end: var(--ka-mezera-2xs); }
.ka-karusel-pas > * { flex: 0 0 calc((100% - (var(--ka-naraz) - 1) * var(--ka-mezera-m)) / var(--ka-naraz)); scroll-snap-align: start; min-width: 0; }
@media (max-width: 767px) { .ka-karusel-pas > * { flex-basis: 85%; } }
.ka-karusel-sipky { display: flex; justify-content: flex-end; gap: var(--ka-mezera-2xs); margin-block-start: var(--ka-mezera-xs); }
.ka-karusel-sipky button { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--ka-barva-linka); border-radius: 50%; background: var(--ka-barva-pozadi); color: var(--ka-barva-text); font-size: 1.2em; cursor: pointer; }
.ka-karusel-sipky button:disabled { opacity: 0.35; cursor: default; }
.ka-karusel:not([data-zapnuto]) .ka-karusel-sipky { display: none; }
@media (prefers-reduced-motion: reduce) { .ka-karusel-pas { scroll-behavior: auto; } }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $popis = $o['popis'] !== '' ? ' aria-label="' . e($o['popis']) . '"' : '';

        return '<' . $p['znacka'] . Text::sTridou($a, 'ka-karusel') . ' data-karusel aria-roledescription="' . e(t('karusel')) . '"' . $popis . ' style="--ka-naraz:' . (int) $o['naraz'] . '">'
            . '<div class="ka-karusel-pas" tabindex="0">' . $deti . '</div>'
            . '<div class="ka-karusel-sipky"><button type="button" data-krok="-1" aria-label="' . e(t('Předchozí')) . '">‹</button><button type="button" data-krok="1" aria-label="' . e(t('Další')) . '">›</button></div>'
            . '</' . $p['znacka'] . '>';
    }
}
