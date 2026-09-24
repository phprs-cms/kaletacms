<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Místo, kam systém vloží obsah stránky v obálce: text novinky, výpis novinek, hlášení 404. Obálka kolem něj přidá
 * sekce (výzva, novinky, kontakt) – obsah sám zůstává ze šablony, takže vypadá stejně jako bez obálky.
 */
final class ObsahStranky extends Prvek
{
    public const string TYP = 'obsah';
    public const string NAZEV = 'Obsah stránky';
    public const string POPIS = 'Sem systém vloží novinku, výpis novinek nebo hlášení 404. V obálce patří právě jednou.';
    public const string IKONA = 'clanek';
    public const string SKUPINA = 'Části webu';
    public const array ZNACKY = ['div', 'article'];
    public const bool JEN_CASTI = true;

    public static function zakladniCss(): string
    {
        // v obálce navazují další sekce hned pod obsahem – výška „aspoň na celou obrazovku“ ze šablony tu nepatří
        return ':where(.stavba) > .obsah { min-height: 0; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $obsah = $k->obsah !== '' ? $k->obsah : ($k->editor ? '<p>' . e(t('Sem se vloží obsah stránky (novinka, výpis novinek, hlášení 404).')) . '</p>' : '');

        // třídy šablony „obal obsah“: obsah vypadá stejně jako bez obálky
        return '<' . $p['znacka'] . Text::sTridou($a, 'obal obsah') . '>' . $obsah . '</' . $p['znacka'] . '>';
    }
}
