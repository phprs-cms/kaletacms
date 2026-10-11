<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Hotspots: an image with numbered points; each point is a <details> whose summary is the button and whose content is a small
 * popover with the label and text – no script, works with the keyboard, closes with a second click, one open at a time (the name
 * attribute). The points are listed under the image as well: for screen readers, search engines and anyone who prefers reading.
 * The editor sets the position as percentages (x from the left, y from the top) – no dragging needed.
 */
final class Hotspots extends Element
{
    public const string TYPE = 'hotspoty';
    public const string NAME = 'Hotspots';
    public const string DESCRIPTION = 'An image with numbered points – a tap on a point shows its label and text (a product, a floor plan, a map of premises).';
    public const string ICON = 'hotspoty';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'src' => ['typ' => 'obrazek', 'popisek' => 'Image', 'vychozi' => ''],
            'alt' => ['typ' => 'text', 'popisek' => 'Popis pro nevidomé (alt)', 'vychozi' => '', 'max' => 300],
            'body' => ['typ' => 'polozky', 'popisek' => 'Points', 'max' => 20, 'pole' => [
                'x' => ['typ' => 'cislo', 'popisek' => 'From the left (%)', 'vychozi' => 50, 'min' => 0, 'max' => 100],
                'y' => ['typ' => 'cislo', 'popisek' => 'From the top (%)', 'vychozi' => 50, 'min' => 0, 'max' => 100],
                'nazev' => ['typ' => 'text', 'popisek' => 'Label', 'vychozi' => '', 'max' => 80],
                'popis' => ['typ' => 'radky', 'popisek' => 'Text', 'vychozi' => '', 'max' => 600],
            ], 'vychozi' => [
                ['x' => 30, 'y' => 40, 'nazev' => t('First point'), 'popis' => t('What is here and why it matters.')],
                ['x' => 70, 'y' => 60, 'nazev' => t('Second point'), 'popis' => t('What is here and why it matters.')],
            ]],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-hotspoty-obraz { position: relative; }
.ka-hotspoty-obraz img { display: block; width: 100%; height: auto; border-radius: var(--ka-zaobleni); }
.ka-hotspoty-bod { position: absolute; inset-block-start: var(--y); inset-inline-start: var(--x); width: 0; height: 0; }
.ka-hotspoty-bod summary { position: absolute; display: grid; place-items: center; width: 2rem; height: 2rem; translate: -50% -50%; border: 2px solid var(--ka-barva-bila); border-radius: 50%; background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font-size: var(--ka-krok--1); font-weight: 700; line-height: 1; list-style: none; cursor: pointer; box-shadow: var(--ka-stin-m); }
.ka-hotspoty-bod summary::-webkit-details-marker { display: none; }
.ka-hotspoty-bod summary:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }
.ka-hotspoty-bod[open] summary { background: var(--ka-barva-text); color: var(--ka-barva-pozadi); }
@media (prefers-reduced-motion: no-preference) {
	.ka-hotspoty-bod:not([open]) summary::after { content: ""; position: absolute; inset: -2px; border: 2px solid var(--ka-barva-primarni); border-radius: 50%; animation: ka-hotspot 2s ease-out infinite; }
	@keyframes ka-hotspot { to { scale: 1.8; opacity: 0; } }
}
.ka-hotspoty-popis { position: absolute; z-index: 2; inset-block-start: 1.4rem; inset-inline-start: -1rem; width: max-content; max-width: min(18rem, calc(100vw - 2rem)); padding: var(--ka-mezera-s); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); box-shadow: var(--ka-stin-l); font-size: var(--ka-krok--1); text-align: start; }
.ka-hotspoty-popis p { margin: var(--ka-mezera-2xs) 0 0; color: var(--ka-barva-tlumeny); }
.ka-hotspoty-bod--vlevo .ka-hotspoty-popis { inset-inline-start: auto; inset-inline-end: -1rem; }
.ka-hotspoty-bod--nahoru .ka-hotspoty-popis { inset-block-start: auto; inset-block-end: 1.4rem; }
.ka-hotspoty-seznam { display: grid; gap: var(--ka-mezera-2xs); margin: var(--ka-mezera-s) 0 0; padding-inline-start: 1.5em; color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-hotspoty-seznam strong { color: var(--ka-barva-text); }
.ka-hotspoty-sr { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }';
    }

    public static function rtlCss(): string
    {
        // a point sits on the photo, which does not mirror: "from the left" stays from the left, and the description opens away from the edge
        return '.ka-hotspoty-bod { right: auto; left: var(--x); }
.ka-hotspoty-popis { inset-inline-start: auto; inset-inline-end: -1rem; }
.ka-hotspoty-bod--vlevo .ka-hotspoty-popis { inset-inline-end: auto; inset-inline-start: -1rem; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        if ($o['src'] === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Choose an image')) . '</div>' : '';
        }
        $points = '';
        $list = '';
        foreach (array_values(array_filter($o['body'], fn (array $b): bool => $b['nazev'] !== '')) as $i => $point) {
            [$x, $y] = [max(0, min(100, (int) $point['x'])), max(0, min(100, (int) $point['y']))];
            $text = $point['popis'] !== '' ? nl2br(e($point['popis'])) : '';
            // the popover opens to the side and in the direction where there is room: left of a point on the right, above a point low down
            $points .= '<details class="ka-hotspoty-bod' . ($x > 50 ? ' ka-hotspoty-bod--vlevo' : '') . ($y > 60 ? ' ka-hotspoty-bod--nahoru' : '') . '" name="hs-' . e($p['id']) . '" style="--x:' . $x . '%;--y:' . $y . '%">'
                . '<summary><span aria-hidden="true">' . ($i + 1) . '</span><span class="ka-hotspoty-sr">' . e($point['nazev']) . '</span></summary>'
                . '<div class="ka-hotspoty-popis"><strong>' . e($point['nazev']) . '</strong>' . ($text !== '' ? '<p>' . $text . '</p>' : '') . '</div></details>';
            $list .= '<li><strong>' . e($point['nazev']) . '</strong>' . ($text !== '' ? ' – ' . $text : '') . '</li>';
        }

        return '<div' . Text::withClass($a, 'ka-hotspoty') . '><div class="ka-hotspoty-obraz"><img src="' . e($k->image($o['src'])) . '" alt="' . e($o['alt']) . '" loading="lazy">' . $points . '</div>'
            . ($list !== '' ? '<ol class="ka-hotspoty-seznam">' . $list . '</ol>' : '') . '</div>';
    }
}
