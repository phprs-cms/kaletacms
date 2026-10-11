<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Star rating ("4.8 out of 5 · 120 reviews"). The stars are one SVG with a clipped fill (half a star too),
 * a screen reader reads the sentence from aria-label. Nothing is calculated – the administrator enters the value from real reviews.
 */
final class Rating extends Element
{
    public const string TYPE = 'hodnoceni';
    public const string NAME = 'Rating';
    public const string DESCRIPTION = 'Stars with a score and number of reviews (e.g. from Google).';
    public const string ICON = 'hvezda';
    public const array HTML_TAGS = ['div', 'p'];

    public static function properties(): array
    {
        return [
            'hodnota' => ['typ' => 'text', 'popisek' => 'Rating (0–5, e.g. 4.8)', 'vychozi' => '4,8', 'max' => 4],
            'text' => ['typ' => 'text', 'popisek' => 'Text next to the stars', 'vychozi' => t('out of 5 · [number] reviews'), 'max' => 120], // 3.11: the count is the owner's to fill in
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-hodnoceni { display: flex; flex-wrap: wrap; align-items: center; gap: var(--ka-mezera-xs); }
.ka-hodnoceni svg { width: 6.5em; height: 1.3em; flex: none; }
.ka-hodnoceni-plne { fill: #f5a524; }
.ka-hodnoceni-prazdne { fill: var(--ka-barva-linka); }
.ka-hodnoceni strong { font-variant-numeric: tabular-nums; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $value = max(0.0, min(5.0, (float) str_replace(',', '.', (string) $o['hodnota'])));
        // stars side by side every 26 units; the fill is clipped to the value's share
        $stars = '';
        for ($i = 0; $i < 5; $i++) {
            $x = $i * 26;
            $stars .= '<path d="M' . ($x + 12) . ' 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1-6.4-3.4-6.4 3.4 1.4-7.1-5.3-5 7.2-.9z"/>';
        }
        $width = round($value / 5 * 128, 2);
        $number = rtrim(rtrim(format_number($value), '0'), ',.');
        $svg = '<svg viewBox="0 0 128 24" aria-hidden="true" focusable="false"><defs><clipPath id="hv-' . e($p['id']) . '"><rect width="' . $width . '" height="24"/></clipPath></defs>'
            . '<g class="ka-hodnoceni-prazdne">' . $stars . '</g><g class="ka-hodnoceni-plne" clip-path="url(#hv-' . e($p['id']) . ')">' . $stars . '</g></svg>';

        return '<' . $p['znacka'] . Text::withClass($a, 'ka-hodnoceni') . ' role="img" aria-label="' . e(t('Rated %s out of 5', $number) . ($o['text'] !== '' ? ' – ' . $o['text'] : '')) . '">'
            . $svg . '<strong aria-hidden="true">' . e($number) . '</strong>' . ($o['text'] !== '' ? '<span aria-hidden="true">' . e($o['text']) . '</span>' : '') . '</' . $p['znacka'] . '>';
    }
}
