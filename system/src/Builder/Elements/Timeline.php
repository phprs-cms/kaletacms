<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Timeline: milestones one after another – a date or year, a title, formatted text and an optional image. A vertical line with dots
 * on phones; on wide screens the items alternate left and right of the line – CSS only, the markup is one ordered list.
 */
final class Timeline extends Element
{
    public const string TYPE = 'casova_osa';
    public const string NAME = 'Timeline';
    public const string DESCRIPTION = 'Milestones one after another – the company history, project steps, how a service proceeds.';
    public const string ICON = 'casova-osa';
    public const array HTML_TAGS = ['ol'];

    public static function properties(): array
    {
        $milestone = fn (string $date, string $title): array => ['datum' => $date, 'nazev' => $title, 'obsah' => '<p>' . t('What happened and what it changed.') . '</p>', 'src' => '', 'alt' => ''];

        return ['udalosti' => ['typ' => 'polozky', 'popisek' => 'Milestones', 'max' => 30, 'pole' => [
            'datum' => ['typ' => 'text', 'popisek' => 'Date or year', 'vychozi' => '', 'max' => 40],
            'nazev' => ['typ' => 'text', 'popisek' => 'Title', 'vychozi' => '', 'max' => 120],
            'obsah' => ['typ' => 'html', 'popisek' => 'Text', 'vychozi' => ''],
            'src' => ['typ' => 'obrazek', 'popisek' => 'Image (optional)', 'vychozi' => ''],
            'alt' => ['typ' => 'text', 'popisek' => 'Popis pro nevidomé (alt)', 'vychozi' => '', 'max' => 300],
        ], 'vychozi' => [$milestone('2020', t('First milestone')), $milestone('2023', t('Second milestone')), $milestone(t('Today'), t('Third milestone'))]]];
    }

    public static function baseCss(): string
    {
        return '.ka-casova-osa { --ka-osa-x: 0.75rem; position: relative; display: grid; gap: var(--ka-mezera-l); margin: 0; padding: 0; list-style: none; }
.ka-casova-osa::before { content: ""; position: absolute; inset-block: 0; inset-inline-start: var(--ka-osa-x); width: 2px; translate: -50% 0; background: var(--ka-barva-linka); }
.ka-casova-osa-polozka { position: relative; padding-inline-start: calc(var(--ka-osa-x) + var(--ka-mezera-m)); }
.ka-casova-osa-polozka::before { content: ""; position: absolute; inset-block-start: 0.3rem; inset-inline-start: var(--ka-osa-x); width: 1rem; height: 1rem; translate: -50% 0; border: 3px solid var(--ka-barva-pozadi); border-radius: 50%; background: var(--ka-barva-primarni); box-shadow: 0 0 0 2px var(--ka-barva-primarni); }
.ka-casova-osa-datum { display: block; margin-block-end: var(--ka-mezera-2xs); color: var(--ka-barva-primarni); font-size: var(--ka-krok--1); font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
.ka-casova-osa h3 { margin: 0; font-size: var(--ka-krok-1); }
.ka-casova-osa img { display: block; width: 100%; height: auto; border-radius: var(--ka-zaobleni); }
.ka-casova-osa-karta > * + * { margin-block-start: var(--ka-mezera-xs); }
@media (min-width: 768px) {
	.ka-casova-osa { --ka-osa-x: 50%; }
	.ka-casova-osa-polozka { width: 50%; padding-inline-start: 0; }
	.ka-casova-osa-polozka:nth-child(odd) { padding-inline-end: var(--ka-mezera-l); text-align: end; }
	.ka-casova-osa-polozka:nth-child(odd)::before { inset-inline-start: 100%; }
	.ka-casova-osa-polozka:nth-child(even) { margin-inline-start: 50%; padding-inline-start: var(--ka-mezera-l); }
	.ka-casova-osa-polozka:nth-child(even)::before { inset-inline-start: 0; }
}';
    }

    public static function rtlCss(): string
    {
        return '.ka-casova-osa::before, .ka-casova-osa-polozka::before { translate: 50% 0; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = '';
        foreach ($p['obsah']['udalosti'] as $item) {
            if ($item['nazev'] === '' && $item['datum'] === '') {
                continue;
            }
            $html .= '<li class="ka-casova-osa-polozka"><div class="ka-casova-osa-karta">'
                . ($item['datum'] !== '' ? '<span class="ka-casova-osa-datum">' . e($item['datum']) . '</span>' : '')
                . ($item['nazev'] !== '' ? '<h3>' . e($item['nazev']) . '</h3>' : '')
                . ($item['src'] !== '' ? '<img src="' . e($k->image($item['src'])) . '" alt="' . e($item['alt']) . '" loading="lazy">' : '')
                . $item['obsah'] . '</div></li>';
        }
        if ($html === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Add milestones in the Content panel.')) . '</div>' : '';
        }

        return '<ol' . Text::withClass($a, 'ka-casova-osa') . '>' . $html . '</ol>';
    }
}
