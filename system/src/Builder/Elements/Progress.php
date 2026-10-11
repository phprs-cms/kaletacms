<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Progress bars (skills, progress toward a goal): each row is a <meter> with a label and percentage. The bar slides out on scroll
 * purely in CSS (a scroll-driven animation); without support or with reduced motion it is full right away.
 */
final class Progress extends Element
{
    public const string TYPE = 'prubeh';
    public const string NAME = 'Progress bars';
    public const string DESCRIPTION = 'Bars with percentages – goal progress, share, skill level.';
    public const string ICON = 'prubeh';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return ['polozky' => ['typ' => 'polozky', 'popisek' => 'Bars', 'max' => 12, 'pole' => [
            'nazev' => ['typ' => 'text', 'popisek' => 'Název', 'vychozi' => '', 'max' => 120],
            'hodnota' => ['typ' => 'cislo', 'popisek' => 'Percent', 'vychozi' => 50, 'min' => 0, 'max' => 100],
        ], 'vychozi' => [['nazev' => t('Projects delivered on time'), 'hodnota' => 96], ['nazev' => t('Returning customers'), 'hodnota' => 78]]]];
    }

    public static function baseCss(): string
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

    public static function rtlCss(): string
    {
        return '.ka-prubeh-radek meter { transform-origin: right; }'; // a meter fills from the right in a right-to-left page
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = '';
        foreach ($p['obsah']['polozky'] as $i => $r) {
            $id = 'pr-' . $p['id'] . '-' . $i;
            $html .= '<div class="ka-prubeh-radek"><span id="' . e($id) . '">' . e((string) $r['nazev']) . '</span><span class="ka-prubeh-hodnota">' . (int) $r['hodnota'] . ' %</span>'
                . '<meter min="0" max="100" low="0" optimum="100" value="' . (int) $r['hodnota'] . '" aria-labelledby="' . e($id) . '">' . (int) $r['hodnota'] . ' %</meter></div>';
        }

        return '<div' . Text::withClass($a, 'ka-prubeh') . '>' . $html . '</div>';
    }
}
