<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Záložky: obsah rozdělený pod přepínací karty (ARIA tabs, šipky na klávesnici – image/web.js).
 * Bez JavaScriptu se ukážou všechny panely pod sebou i s nadpisy, nic se neztratí.
 */
final class Zalozky extends Prvek
{
    public const string TYP = 'zalozky';
    public const string NAZEV = 'Záložky';
    public const string POPIS = 'Obsah rozdělený do přepínacích karet – ceník po balíčcích, služby po oborech.';
    public const string IKONA = 'zalozky';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return ['karty' => ['typ' => 'polozky', 'popisek' => 'Karty', 'max' => 12, 'pole' => [
            'nazev' => ['typ' => 'text', 'popisek' => 'Název karty', 'vychozi' => '', 'max' => 80],
            'obsah' => ['typ' => 'html', 'popisek' => 'Obsah', 'vychozi' => ''],
        ], 'vychozi' => [['nazev' => t('První karta'), 'obsah' => '<p>' . t('Obsah první karty.') . '</p>'], ['nazev' => t('Druhá karta'), 'obsah' => '<p>' . t('Obsah druhé karty.') . '</p>']]]];
    }

    public static function zakladniCss(): string
    {
        return '.mc-zalozky [role="tablist"] { display: flex; flex-wrap: wrap; gap: var(--mc-mezera-2xs); border-block-end: 1px solid var(--mc-barva-linka); }
.mc-zalozky [role="tab"] { margin-block-end: -1px; padding: 0.6em 1em; border: 1px solid transparent; border-radius: var(--mc-zaobleni-s) var(--mc-zaobleni-s) 0 0; background: none; color: var(--mc-barva-tlumeny); font: inherit; font-weight: 600; cursor: pointer; }
.mc-zalozky [role="tab"][aria-selected="true"] { border-color: var(--mc-barva-linka); border-block-end-color: var(--mc-barva-pozadi); background: var(--mc-barva-pozadi); color: var(--mc-barva-text); }
.mc-zalozky [role="tab"]:focus-visible { outline: 2px solid var(--mc-barva-primarni); outline-offset: 2px; }
.mc-zalozky [role="tabpanel"] { padding-block: var(--mc-mezera-m); }
.mc-zalozky [role="tabpanel"] > :last-child { margin-block-end: 0; }
.mc-zalozky:not([data-zapnuto]) [role="tablist"] { display: none; }
.mc-zalozky[data-zapnuto] .mc-zalozky-nadpis { display: none; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $lista = '';
        $panely = '';
        foreach (array_values(array_filter($p['obsah']['karty'], fn (array $x): bool => $x['nazev'] !== '')) as $i => $karta) {
            [$tab, $panel] = ['z-' . $p['id'] . '-' . $i, 'zp-' . $p['id'] . '-' . $i];
            $lista .= '<button type="button" role="tab" id="' . $tab . '" aria-controls="' . $panel . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '"' . ($i === 0 ? '' : ' tabindex="-1"') . '>' . e($karta['nazev']) . '</button>';
            // bez skriptu jsou vidět všechny panely s nadpisem; skript skryje neaktivní a nadpisy (atribut data-zapnuto)
            $panely .= '<div role="tabpanel" id="' . $panel . '" aria-labelledby="' . $tab . '" tabindex="0"><h3 class="mc-zalozky-nadpis">' . e($karta['nazev']) . '</h3>' . $karta['obsah'] . '</div>';
        }

        return '<div' . Text::sTridou($a, 'mc-zalozky') . ' data-zalozky' . ($k->editor ? ' data-zapnuto' : '') . '><div role="tablist">' . $lista . '</div>' . $panely . '</div>';
    }
}
