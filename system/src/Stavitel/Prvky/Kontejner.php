<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/** Skupina prvků (flex): karta, řada tlačítek, sloupec textu. S odkazem se celá stane odkazem. */
final class Kontejner extends Prvek
{
    public const string TYP = 'kontejner';
    public const string NAZEV = 'Kontejner';
    public const string POPIS = 'Skupina prvků pod sebou nebo vedle sebe – karta, řada tlačítek.';
    public const string IKONA = 'kontejner';
    public const string SKUPINA = 'Rozložení';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['div', 'article', 'aside', 'nav', 'header', 'footer', 'ul', 'li'];

    public static function vlastnosti(): array
    {
        return ['odkaz' => ['typ' => 'odkaz', 'popisek' => 'Celý kontejner jako odkaz (nepovinné)', 'vychozi' => '']];
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']];
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $odkaz = (string) ($p['obsah']['odkaz'] ?? '');
        if ($odkaz !== '') {
            return '<a' . $a . ' href="' . e($odkaz) . '">' . $deti . '</a>';
        }

        return '<' . $p['znacka'] . $a . '>' . $deti . '</' . $p['znacka'] . '>';
    }
}
