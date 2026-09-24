<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Animované počítadlo („1 200 spokojených zákazníků“): číslo je v HTML celé (vyhledávače, čtečky, web bez skriptu),
 * web.js ho při objevení na obrazovce jednou napočítá od nuly. Kdo nechce pohyb (nastavení systému), vidí rovnou výsledek.
 */
final class Pocitadlo extends Prvek
{
    public const string TYP = 'pocitadlo';
    public const string NAZEV = 'Počítadlo';
    public const string POPIS = 'Velké číslo s popiskem, které se při zobrazení napočítá (roky praxe, zákazníci, projekty).';
    public const string IKONA = 'pocitadlo';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return [
            'cislo' => ['typ' => 'cislo', 'popisek' => 'Číslo', 'vychozi' => 1200, 'min' => 0, 'max' => 999999999],
            'pred' => ['typ' => 'text', 'popisek' => 'Před číslem (např. „+“)', 'vychozi' => '', 'max' => 10],
            'za' => ['typ' => 'text', 'popisek' => 'Za číslem (např. „ %“, „+“, „ let“)', 'vychozi' => '+', 'max' => 20],
            'popisek' => ['typ' => 'text', 'popisek' => 'Popisek', 'vychozi' => t('spokojených zákazníků'), 'max' => 120],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.ka-pocitadlo { display: grid; gap: var(--ka-mezera-2xs); }
.ka-pocitadlo-cislo { font: 800 var(--ka-krok-5)/1 var(--ka-pismo-titulky); font-variant-numeric: tabular-nums; color: var(--ka-barva-primarni); }
.ka-pocitadlo-popisek { color: var(--ka-barva-tlumeny); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $cislo = (int) $o['cislo'];
        $format = number_format($cislo, 0, ',', "\u{00a0}");

        return '<div' . Text::sTridou($a, 'ka-pocitadlo') . '><span class="ka-pocitadlo-cislo">' . e($o['pred'])
            . '<span data-pocitadlo="' . $cislo . '">' . $format . '</span>' . e($o['za']) . '</span>'
            . ($o['popisek'] !== '' ? '<span class="ka-pocitadlo-popisek">' . e($o['popisek']) . '</span>' : '') . '</div>';
    }
}
