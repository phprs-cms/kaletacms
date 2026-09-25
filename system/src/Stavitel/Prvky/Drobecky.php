<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

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
        return '.ka-drobecky ol { display: flex; flex-wrap: wrap; gap: 0.35em; margin: 0; padding: 0; list-style: none; color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-drobecky li + li::before { content: "›"; margin-inline-end: 0.35em; }
.ka-drobecky a { color: inherit; }
.ka-drobecky [aria-current] { color: var(--ka-barva-text); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $cesta = $k->drobecky !== [] ? $k->drobecky : ($k->editor ? [[t('Úvod'), '#'], [t('Tato stránka'), '']] : []);
        if (count($cesta) < 2) {
            return ''; // na úvodní stránce drobečky nemají smysl
        }
        $html = '';
        foreach ($cesta as $i => [$text, $adresa]) {
            // aktuální je jen poslední článek; úroveň bez vlastní stránky (kolekce bez rozcestníku) je prostý text
            $html .= match (true) {
                $i === array_key_last($cesta) => '<li><span aria-current="page">' . e($text) . '</span></li>',
                $adresa === '' => '<li><span>' . e($text) . '</span></li>',
                default => '<li><a href="' . e($adresa) . '">' . e($text) . '</a></li>',
            };
        }

        return '<nav' . Text::sTridou($a, 'ka-drobecky') . ' aria-label="' . e(t('Drobečková navigace')) . '"><ol>' . $html . '</ol></nav>';
    }
}
