<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

final class Nadpis extends Prvek
{
    public const string TYP = 'nadpis';
    public const string NAZEV = 'Nadpis';
    public const string POPIS = 'Nadpis H1–H6 nebo zvýrazněný řádek.';
    public const string IKONA = 'nadpis';
    public const array ZNACKY = ['h2', 'h1', 'h3', 'h4', 'h5', 'h6', 'p'];

    public static function vlastnosti(): array
    {
        return ['text' => ['typ' => 'inline', 'popisek' => 'Text', 'vychozi' => t('Nadpis'), 'max' => 400]];
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        return '<' . $p['znacka'] . $a . '>' . $p['obsah']['text'] . '</' . $p['znacka'] . '>';
    }
}
