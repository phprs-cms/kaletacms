<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/**
 * Ukazatele průběhu (dovednosti, plnění cíle): každý řádek je <meter> s popiskem a procenty. Pruh se při rolování
 * vysune čistě v CSS (animace řízená posunem stránky); bez podpory nebo s omezeným pohybem je rovnou plný.
 */
final class Prubeh extends Prvek
{
    public const string TYP = 'prubeh';
    public const string NAZEV = 'Ukazatele průběhu';
    public const string POPIS = 'Pruhy s procenty – plnění cíle, podíl, úroveň dovedností.';
    public const string IKONA = 'prubeh';
    public const array ZNACKY = ['div'];

    public static function vlastnosti(): array
    {
        return ['polozky' => ['typ' => 'polozky', 'popisek' => 'Ukazatele', 'max' => 12, 'pole' => [
            'nazev' => ['typ' => 'text', 'popisek' => 'Název', 'vychozi' => '', 'max' => 120],
            'hodnota' => ['typ' => 'cislo', 'popisek' => 'Procenta', 'vychozi' => 50, 'min' => 0, 'max' => 100],
        ], 'vychozi' => [['nazev' => t('Projekty dokončené v termínu'), 'hodnota' => 96], ['nazev' => t('Zákazníci, kteří se vracejí'), 'hodnota' => 78]]]];
    }

    public static function zakladniCss(): string
    {
        return '.ka-prubeh { display: grid; gap: var(--ka-mezera-m); }
.ka-prubeh-radek { display: grid; grid-template-columns: 1fr auto; gap: var(--ka-mezera-2xs) var(--ka-mezera-s); }
.ka-prubeh-radek meter { grid-column: 1 / -1; width: 100%; height: 0.6rem; border: 0; border-radius: 999px; background: var(--ka-barva-plocha); appearance: none; }
.ka-prubeh-radek meter::-webkit-meter-bar { height: 0.6rem; border: 0; border-radius: 999px; background: var(--ka-barva-plocha); }
.ka-prubeh-radek meter::-webkit-meter-optimum-value { border-radius: 999px; background: var(--ka-barva-primarni); }
.ka-prubeh-radek meter::-moz-meter-bar { border-radius: 999px; background: var(--ka-barva-primarni); }
.ka-prubeh-hodnota { font-variant-numeric: tabular-nums; font-weight: 600; }
@supports (animation-timeline: view()) { @media (prefers-reduced-motion: no-preference) {
	.ka-prubeh-radek meter { transform-origin: left; animation: ka-prubeh linear both; animation-timeline: view(); animation-range: entry 10% cover 35%; }
	@keyframes ka-prubeh { from { scale: 0 1; } }
} }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $html = '';
        foreach ($p['obsah']['polozky'] as $i => $r) {
            $id = 'pr-' . $p['id'] . '-' . $i;
            $html .= '<div class="ka-prubeh-radek"><span id="' . e($id) . '">' . e((string) $r['nazev']) . '</span><span class="ka-prubeh-hodnota">' . (int) $r['hodnota'] . ' %</span>'
                . '<meter min="0" max="100" low="0" optimum="100" value="' . (int) $r['hodnota'] . '" aria-labelledby="' . e($id) . '">' . (int) $r['hodnota'] . ' %</meter></div>';
        }

        return '<div' . Text::sTridou($a, 'ka-prubeh') . '>' . $html . '</div>';
    }
}
