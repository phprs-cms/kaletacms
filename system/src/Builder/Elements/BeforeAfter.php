<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Before and after: two photos of the same thing with a draggable divider. The control is a plain <input type="range"> laid over the
 * photos (keyboard, touch and screen readers for free); the after image is clipped at the divider (image/web.js moves it).
 * Without the script the two photos stand side by side with their labels – nothing is lost.
 */
final class BeforeAfter extends Element
{
    public const string TYPE = 'pred_po';
    public const string NAME = 'Before and after';
    public const string DESCRIPTION = 'Two photos with a draggable divider – a renovation, a cleaning, a makeover.';
    public const string ICON = 'pred-po';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'obrazek_pred' => ['typ' => 'obrazek', 'popisek' => 'Image before', 'vychozi' => ''],
            'alt_pred' => ['typ' => 'text', 'popisek' => 'Description of the before image (alt)', 'vychozi' => '', 'max' => 300],
            'popisek_pred' => ['typ' => 'text', 'popisek' => 'Label of the before image', 'vychozi' => t('Before'), 'max' => 40],
            'obrazek_po' => ['typ' => 'obrazek', 'popisek' => 'Image after', 'vychozi' => ''],
            'alt_po' => ['typ' => 'text', 'popisek' => 'Description of the after image (alt)', 'vychozi' => '', 'max' => 300],
            'popisek_po' => ['typ' => 'text', 'popisek' => 'Label of the after image', 'vychozi' => t('After'), 'max' => 40],
            'delic' => ['typ' => 'cislo', 'popisek' => 'Divider position (%)', 'vychozi' => 50, 'min' => 0, 'max' => 100],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-pred-po { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(14rem, 100%), 1fr)); gap: var(--ka-mezera-s); }
.ka-pred-po figure { position: relative; margin: 0; }
.ka-pred-po img { display: block; width: 100%; height: auto; border-radius: var(--ka-zaobleni); }
.ka-pred-po figcaption { position: absolute; inset-block-start: var(--ka-mezera-xs); padding: 0.2em 0.75em; border-radius: 999px; background: color-mix(in oklch, var(--ka-barva-cerna) 60%, transparent); color: var(--ka-barva-bila); font-size: var(--ka-krok--1); font-weight: 600; }
.ka-pred-po-pred figcaption { inset-inline-start: var(--ka-mezera-xs); }
.ka-pred-po-po figcaption { inset-inline-end: var(--ka-mezera-xs); }
.ka-pred-po:not([data-zapnuto]) .ka-pred-po-ovladac { display: none; }
.ka-pred-po[data-zapnuto] { position: relative; grid-template-columns: 1fr; gap: 0; overflow: hidden; border-radius: var(--ka-zaobleni); }
.ka-pred-po[data-zapnuto] figure { grid-area: 1 / 1; }
.ka-pred-po[data-zapnuto] .ka-pred-po-po { clip-path: inset(0 0 0 var(--ka-delic)); }
.ka-pred-po[data-zapnuto] .ka-pred-po-ovladac { grid-area: 1 / 1; z-index: 2; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: ew-resize; appearance: none; background: transparent; }
.ka-pred-po[data-zapnuto]::before { content: ""; position: absolute; z-index: 1; inset-block: 0; inset-inline-start: var(--ka-delic); width: 2px; translate: -50% 0; background: var(--ka-barva-bila); box-shadow: 0 0 0 1px color-mix(in oklch, var(--ka-barva-cerna) 25%, transparent); pointer-events: none; }
.ka-pred-po[data-zapnuto]::after { content: "↔"; position: absolute; z-index: 1; inset-block-start: 50%; inset-inline-start: var(--ka-delic); display: grid; place-items: center; width: 2.75rem; height: 2.75rem; translate: -50% -50%; border-radius: 50%; background: var(--ka-barva-bila); color: var(--ka-barva-cerna); font-size: 1.25rem; box-shadow: var(--ka-stin-m); pointer-events: none; }
.ka-pred-po[data-zapnuto]:has(.ka-pred-po-ovladac:focus-visible)::after { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }';
    }

    public static function rtlCss(): string
    {
        // the range runs from the right: the divider stands at --ka-delic from the right and the after photo shows to its left
        return '.ka-pred-po[data-zapnuto] .ka-pred-po-po { clip-path: inset(0 var(--ka-delic) 0 0); }
.ka-pred-po[data-zapnuto]::before { translate: 50% 0; }
.ka-pred-po[data-zapnuto]::after { translate: 50% -50%; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        if ($o['obrazek_pred'] === '' || $o['obrazek_po'] === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Choose the before and after images in the Content panel.')) . '</div>' : '';
        }
        $figure = fn (string $className, string $src, string $alt, string $label): string => '<figure class="' . $className . '"><img src="' . e($k->image($src)) . '" alt="' . e($alt) . '" loading="lazy">'
            . ($label !== '' ? '<figcaption>' . e($label) . '</figcaption>' : '') . '</figure>';
        $position = (int) $o['delic'];

        return '<div' . Text::withClass($a, 'ka-pred-po') . ' data-pred-po' . ($k->editor ? ' data-zapnuto' : '') . ' style="--ka-delic:' . $position . '%">'
            . $figure('ka-pred-po-pred', $o['obrazek_pred'], $o['alt_pred'], $o['popisek_pred'])
            . $figure('ka-pred-po-po', $o['obrazek_po'], $o['alt_po'], $o['popisek_po'])
            . '<input type="range" class="ka-pred-po-ovladac" min="0" max="100" value="' . $position . '" aria-label="' . e(t('Compare before and after')) . '">'
            . '</div>';
    }
}
