<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Mřížka: sloupce, které se samy zalomí, když se nevejdou (výchozí „kolik se vejde po 16rem“). */
final class Mrizka extends Prvek
{
    public const string TYP = 'mrizka';
    public const string NAZEV = 'Mřížka';
    public const string POPIS = 'Sloupce, které se na menší obrazovce samy zalomí pod sebe.';
    public const string IKONA = 'mrizka';
    public const string SKUPINA = 'Rozložení';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['div', 'ul'];

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:16rem', 'mezera' => 'l']];
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        return '<' . $p['znacka'] . $a . '>' . $deti . '</' . $p['znacka'] . '>';
    }
}
