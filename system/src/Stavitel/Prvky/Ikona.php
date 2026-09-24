<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Ikony;
use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Ikona z vestavěné sady (Stavitel\Ikony) jako vložené SVG: barva = barva textu prvku, velikost = velikost písma.
 * Bez popisu je dekorativní (čtečky ji přeskočí), s popisem ji přečtou.
 */
final class Ikona extends Prvek
{
    public const string TYP = 'ikona';
    public const string NAZEV = 'Ikona';
    public const string POPIS = 'Jednoduchá ikona (fajfka, telefon, hvězda…) – barvu a velikost nastavíte stylem.';
    public const string IKONA = 'ikona';
    public const array ZNACKY = ['span', 'div'];

    public static function vlastnosti(): array
    {
        return [
            'ikona' => ['typ' => 'vyber', 'popisek' => 'Ikona', 'vychozi' => 'fajfka-kruh', 'moznosti' => Ikony::moznosti()],
            'tvar' => ['typ' => 'vyber', 'popisek' => 'Podklad', 'vychozi' => '', 'moznosti' => ['' => 'bez podkladu', 'kruh' => 'kruh', 'ctverec' => 'zaoblený čtverec']],
            'popis' => ['typ' => 'text', 'popisek' => 'Popis pro čtečky (prázdné = jen ozdoba)', 'vychozi' => '', 'max' => 120],
        ];
    }

    public static function zakladniCss(): string
    {
        // výchozí velikost a barva (styl prvku je přepíše); prvek vložený přes AI nebo MCP tak vypadá stejně jako z editoru
        return '.mc-ikona { display: inline-grid; place-items: center; flex: none; width: 1em; height: 1em; line-height: 1; font-size: var(--mc-krok-3); color: var(--mc-barva-primarni); }
.mc-ikona svg { display: block; width: 100%; height: 100%; }
.mc-ikona--kruh, .mc-ikona--ctverec { width: 1.9em; height: 1.9em; padding: 0.45em; background: var(--mc-barva-primarni-jemna); }
.mc-ikona--kruh { border-radius: 50%; }
.mc-ikona--ctverec { border-radius: var(--mc-zaobleni-m); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $trida = 'mc-ikona' . ($o['tvar'] !== '' ? ' mc-ikona--' . $o['tvar'] : '');
        $popis = $o['popis'] !== '' ? ' role="img" aria-label="' . e($o['popis']) . '"' : ' aria-hidden="true"';

        return '<' . $p['znacka'] . Text::sTridou($a, $trida) . $popis . '>' . Ikony::svg($o['ikona']) . '</' . $p['znacka'] . '>';
    }
}
