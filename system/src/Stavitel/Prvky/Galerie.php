<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Core\Obrazky;
use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Fotogalerie: mřížka náhledů, klepnutím se fotka otevře přes celou obrazovku (prohlížečka z image/web.js, šipky i swipe).
 * Počet sloupců řídí styl (Rozložení → Sloupce), na telefonu se mřížka sama zúží.
 */
final class Galerie extends Prvek
{
    public const string TYP = 'galerie';
    public const string NAZEV = 'Galerie';
    public const string POPIS = 'Mřížka fotek, které se po klepnutí otevřou přes celou obrazovku.';
    public const string IKONA = 'galerie';
    public const array ZNACKY = ['figure', 'div'];

    public static function vlastnosti(): array
    {
        return [
            'fotky' => ['typ' => 'polozky', 'popisek' => 'Fotky', 'max' => 60, 'vychozi' => [], 'pole' => [
                'src' => ['typ' => 'obrazek', 'popisek' => 'Fotka', 'vychozi' => ''],
                'alt' => ['typ' => 'text', 'popisek' => 'Popis (pro nevidomé i pod fotkou v prohlížečce)', 'vychozi' => '', 'max' => 300],
            ]],
            'pomer' => ['typ' => 'vyber', 'popisek' => 'Tvar náhledů', 'vychozi' => '4 / 3', 'moznosti' => ['4 / 3' => 'na šířku 4 : 3', '1 / 1' => 'čtverec', '3 / 4' => 'na výšku 3 : 4', '16 / 9' => 'široký 16 : 9']],
            'popisek' => ['typ' => 'text', 'popisek' => 'Popisek galerie', 'vychozi' => '', 'max' => 300],
        ];
    }

    public static function zakladniCss(): string
    {
        // mřížka sama podle šířky; Styl → Sloupce ji přepíše (vrstva prvků je až za vrstvou stavitele)
        return '.mc-galerie { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(12rem, 45%), 1fr)); gap: var(--mc-mezera-s); margin: 0; }
.mc-galerie img { display: block; width: 100%; height: auto; object-fit: cover; border-radius: var(--mc-zaobleni-s); cursor: zoom-in; }
.mc-galerie figcaption { grid-column: 1 / -1; color: var(--mc-barva-tlumeny); font-size: var(--mc-krok--1); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $zaklad = $k->app->request->basePath();
        $html = '';
        foreach ($o['fotky'] as $f) {
            if ($f['src'] === '') {
                continue;
            }
            $src = $k->obrazek($f['src']);
            $srcset = Obrazky::srcset(ltrim(preg_replace('#^' . preg_quote($zaklad, '#') . '/#', '', $src) ?? $src, '/'), $zaklad);
            $html .= '<img src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="(max-width: 700px) 50vw, 400px"' : '')
                . ' alt="' . e($f['alt']) . '" loading="lazy" style="aspect-ratio:' . e($o['pomer']) . '">';
        }
        if ($html === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--mc-barva-plocha)">' . e(t('Přidejte fotky v panelu Obsah.')) . '</div>' : '';
        }
        if ($o['popisek'] !== '') {
            $html .= $p['znacka'] === 'figure' ? '<figcaption>' . e($o['popisek']) . '</figcaption>' : '<p>' . e($o['popisek']) . '</p>';
        }

        return '<' . $p['znacka'] . Text::sTridou($a, 'mc-galerie') . '>' . $html . '</' . $p['znacka'] . '>';
    }
}
