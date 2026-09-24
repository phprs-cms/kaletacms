<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;
use MiroCMS\Stavitel\Stavba;

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
        return '.mc-karusel { position: relative; }
.mc-karusel-pas { display: flex; gap: var(--mc-mezera-m); overflow-x: auto; overscroll-behavior-x: contain; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: thin; padding-block-end: var(--mc-mezera-2xs); }
.mc-karusel-pas > * { flex: 0 0 calc((100% - (var(--mc-naraz) - 1) * var(--mc-mezera-m)) / var(--mc-naraz)); scroll-snap-align: start; min-width: 0; }
@media (max-width: 767px) { .mc-karusel-pas > * { flex-basis: 85%; } }
.mc-karusel-sipky { display: flex; justify-content: flex-end; gap: var(--mc-mezera-2xs); margin-block-start: var(--mc-mezera-xs); }
.mc-karusel-sipky button { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--mc-barva-linka); border-radius: 50%; background: var(--mc-barva-pozadi); color: var(--mc-barva-text); font-size: 1.2em; cursor: pointer; }
.mc-karusel-sipky button:disabled { opacity: 0.35; cursor: default; }
.mc-karusel:not([data-zapnuto]) .mc-karusel-sipky { display: none; }
@media (prefers-reduced-motion: reduce) { .mc-karusel-pas { scroll-behavior: auto; } }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $popis = $o['popis'] !== '' ? ' aria-label="' . e($o['popis']) . '"' : '';

        return '<' . $p['znacka'] . Text::sTridou($a, 'mc-karusel') . ' data-karusel aria-roledescription="' . e(t('karusel')) . '"' . $popis . ' style="--mc-naraz:' . (int) $o['naraz'] . '">'
            . '<div class="mc-karusel-pas" tabindex="0">' . $deti . '</div>'
            . '<div class="mc-karusel-sipky"><button type="button" data-krok="-1" aria-label="' . e(t('Předchozí')) . '">‹</button><button type="button" data-krok="1" aria-label="' . e(t('Další')) . '">›</button></div>'
            . '</' . $p['znacka'] . '>';
    }
}
