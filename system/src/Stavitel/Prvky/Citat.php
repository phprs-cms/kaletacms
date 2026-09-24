<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/** Reference zákazníka nebo citát: text, jméno a pozice / firma. */
final class Citat extends Prvek
{
    public const string TYP = 'citat';
    public const string NAZEV = 'Reference';
    public const string POPIS = 'Citát nebo reference zákazníka se jménem.';
    public const string IKONA = 'citat';
    public const array ZNACKY = ['blockquote'];

    public static function vlastnosti(): array
    {
        return [
            'text' => ['typ' => 'inline', 'popisek' => 'Text', 'vychozi' => t('Spolupráce byla rychlá a bez starostí. Doporučujeme.'), 'max' => 1500],
            'autor' => ['typ' => 'text', 'popisek' => 'Jméno', 'vychozi' => t('Jana Nováková'), 'max' => 120],
            'pozice' => ['typ' => 'text', 'popisek' => 'Pozice nebo firma', 'vychozi' => '', 'max' => 160],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.mc-citat { margin: 0; }
.mc-citat p { margin: 0; font-size: var(--mc-krok-1); line-height: 1.5; }
.mc-citat footer { margin-block-start: var(--mc-mezera-s); font-size: var(--mc-krok--1); color: var(--mc-barva-tlumeny); }
.mc-citat footer strong { color: var(--mc-barva-text); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];
        $kdo = $o['autor'] !== '' ? '<strong>' . e($o['autor']) . '</strong>' . ($o['pozice'] !== '' ? ', ' . e($o['pozice']) : '') : e($o['pozice']);

        return '<blockquote' . Text::sTridou($a, 'mc-citat') . '><p>' . $o['text'] . '</p>' . ($kdo !== '' ? '<footer>' . $kdo . '</footer>' : '') . '</blockquote>';
    }
}
