<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

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
        return '.ka-zalozky [role="tablist"] { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-2xs); border-block-end: 1px solid var(--ka-barva-linka); }
.ka-zalozky [role="tab"] { margin-block-end: -1px; padding: 0.6em 1em; border: 1px solid transparent; border-radius: var(--ka-zaobleni-s) var(--ka-zaobleni-s) 0 0; background: none; color: var(--ka-barva-tlumeny); font: inherit; font-weight: 600; cursor: pointer; }
.ka-zalozky [role="tab"][aria-selected="true"] { border-color: var(--ka-barva-linka); border-block-end-color: var(--ka-barva-pozadi); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); }
.ka-zalozky [role="tab"]:focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 2px; }
.ka-zalozky [role="tabpanel"] { padding-block: var(--ka-mezera-m); }
.ka-zalozky [role="tabpanel"] > :last-child { margin-block-end: 0; }
.ka-zalozky:not([data-zapnuto]) [role="tablist"] { display: none; }
.ka-zalozky[data-zapnuto] .ka-zalozky-nadpis { display: none; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $lista = '';
        $panely = '';
        foreach (array_values(array_filter($p['obsah']['karty'], fn (array $x): bool => $x['nazev'] !== '')) as $i => $karta) {
            [$tab, $panel] = ['z-' . $p['id'] . '-' . $i, 'zp-' . $p['id'] . '-' . $i];
            $lista .= '<button type="button" role="tab" id="' . $tab . '" aria-controls="' . $panel . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '"' . ($i === 0 ? '' : ' tabindex="-1"') . '>' . e($karta['nazev']) . '</button>';
            // bez skriptu jsou vidět všechny panely s nadpisem; skript skryje neaktivní a nadpisy (atribut data-zapnuto)
            $panely .= '<div role="tabpanel" id="' . $panel . '" aria-labelledby="' . $tab . '" tabindex="0"><h3 class="ka-zalozky-nadpis">' . e($karta['nazev']) . '</h3>' . $karta['obsah'] . '</div>';
        }

        return '<div' . Text::sTridou($a, 'ka-zalozky') . ' data-zalozky' . ($k->editor ? ' data-zapnuto' : '') . '><div role="tablist">' . $lista . '</div>' . $panely . '</div>';
    }
}
