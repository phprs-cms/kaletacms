<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Drobečková navigace: Úvod › Novinky › Kategorie › Novinka. Cestu skládá web podle zobrazené stránky (Kontext::$drobecky),
 * do obálky novinky nebo šablony detailu kolekce ji tak stačí vložit jednou. Vyhledávače dostanou i BreadcrumbList.
 */
final class Drobecky extends Prvek
{
    public const string TYP = 'drobecky';
    public const string NAZEV = 'Drobečková navigace';
    public const string POPIS = 'Cesta ke stránce (Úvod › Novinky › …) – sestaví se sama podle zobrazené stránky.';
    public const string IKONA = 'drobecky';
    public const array ZNACKY = ['nav'];

    public static function zakladniCss(): string
    {
        return '.mc-drobecky ol { display: flex; flex-wrap: wrap; gap: 0.35em; margin: 0; padding: 0; list-style: none; color: var(--mc-barva-tlumeny); font-size: var(--mc-krok--1); }
.mc-drobecky li + li::before { content: "›"; margin-inline-end: 0.35em; }
.mc-drobecky a { color: inherit; }
.mc-drobecky [aria-current] { color: var(--mc-barva-text); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $cesta = $k->drobecky !== [] ? $k->drobecky : ($k->editor ? [[t('Úvod'), '#'], [t('Tato stránka'), '']] : []);
        if (count($cesta) < 2) {
            return ''; // na úvodní stránce drobečky nemají smysl
        }
        $html = '';
        foreach ($cesta as $i => [$text, $adresa]) {
            $html .= $i === array_key_last($cesta) || $adresa === ''
                ? '<li><span aria-current="page">' . e($text) . '</span></li>'
                : '<li><a href="' . e($adresa) . '">' . e($text) . '</a></li>';
        }

        return '<nav' . Text::sTridou($a, 'mc-drobecky') . ' aria-label="' . e(t('Drobečková navigace')) . '"><ol>' . $html . '</ol></nav>';
    }
}
