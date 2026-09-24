<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/** Tlačítko = odkaz vzhledu tlačítka. Varianty z design systému: hlavní, doplňkové, obrys, textový odkaz. */
final class Tlacitko extends Prvek
{
    public const string TYP = 'tlacitko';
    public const string NAZEV = 'Tlačítko';
    public const string POPIS = 'Výzva k akci: odkaz ve tvaru tlačítka.';
    public const string IKONA = 'tlacitko';
    public const array ZNACKY = ['a'];
    public const array VARIANTY = ['primarni' => 'hlavní', 'sekundarni' => 'doplňkové', 'obrys' => 'obrys', 'odkaz' => 'textový odkaz'];

    public static function vlastnosti(): array
    {
        return [
            'text' => ['typ' => 'text', 'popisek' => 'Text', 'vychozi' => t('Kontaktujte nás'), 'max' => 120],
            'odkaz' => ['typ' => 'odkaz', 'popisek' => 'Odkaz', 'vychozi' => '#'],
            'varianta' => ['typ' => 'vyber', 'popisek' => 'Vzhled', 'vychozi' => 'primarni', 'moznosti' => self::VARIANTY],
            'nove_okno' => ['typ' => 'prepinac', 'popisek' => 'Otevřít v novém okně', 'vychozi' => false],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.mc-tlacitko { display: inline-flex; align-items: center; justify-content: center; gap: 0.5em; padding: 0.75em 1.35em; border: 2px solid transparent; border-radius: var(--mc-zaobleni); font: 600 var(--mc-krok-0) / 1.2 var(--mc-pismo-text); text-decoration: none; cursor: pointer; transition: background-color 0.15s, color 0.15s, border-color 0.15s; }
.mc-tlacitko--primarni { background: var(--mc-barva-primarni); color: var(--mc-barva-na-primarni); }
.mc-tlacitko--primarni:hover { background: color-mix(in oklch, var(--mc-barva-primarni) 85%, black); }
.mc-tlacitko--sekundarni { background: var(--mc-barva-primarni-jemna); color: var(--mc-barva-primarni); }
.mc-tlacitko--sekundarni:hover { background: color-mix(in oklch, var(--mc-barva-primarni) 20%, var(--mc-barva-pozadi)); }
.mc-tlacitko--obrys { border-color: currentColor; color: inherit; background: transparent; }
.mc-tlacitko--obrys:hover { background: color-mix(in oklch, currentColor 8%, transparent); }
.mc-tlacitko--odkaz { padding-inline: 0; color: var(--mc-barva-primarni); text-decoration: underline; text-underline-offset: 0.2em; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $o = $p['obsah'];

        return '<a' . Text::sTridou($a, 'mc-tlacitko mc-tlacitko--' . $o['varianta']) . ' href="' . e($o['odkaz'] !== '' ? $o['odkaz'] : '#') . '"'
            . ($o['nove_okno'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($o['text']) . '</a>';
    }
}
