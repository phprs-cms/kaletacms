<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;
use Kaleta\Core\GoogleBusiness;

/**
 * Customer reviews from Google (2.13, Core\GoogleBusiness): the newest reviews of the connected Business Profile with at
 * least so many stars, the profile's average rating and review count, and a link to all reviews. The structured data
 * (AggregateRating and Review on the company node) carry only what Google returned – never a typed-in number.
 * Without the Google connection the editor sees a note and visitors nothing.
 */
final class GoogleReviews extends Element
{
    public const string TYPE = 'recenze_google';
    public const string NAME = 'Google reviews';
    public const string DESCRIPTION = 'The newest reviews from your Google Business Profile with the rating – they update themselves daily.';
    public const string ICON = 'hvezda';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['div', 'section'];

    public static function properties(): array
    {
        return [
            'pocet' => ['typ' => 'cislo', 'popisek' => 'Number of reviews', 'vychozi' => 3, 'min' => 1, 'max' => 12],
            'min_hvezd' => ['typ' => 'cislo', 'popisek' => 'Only reviews with at least this many stars', 'vychozi' => 4, 'min' => 1, 'max' => 5],
            'souhrn' => ['typ' => 'prepinac', 'popisek' => 'Show the average rating and the count', 'vychozi' => true],
            'odkaz' => ['typ' => 'odkaz', 'popisek' => 'Link to all reviews (your Google Maps address)', 'vychozi' => ''],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']];
    }

    public static function baseCss(): string
    {
        return '.ka-recenze-souhrn { display: flex; flex-wrap: wrap; align-items: center; gap: var(--ka-mezera-xs); margin: 0; }
.ka-recenze-souhrn strong { font-size: var(--ka-krok-1); font-variant-numeric: tabular-nums; }
.ka-recenze-seznam { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: var(--ka-mezera-m); margin: 0; padding: 0; list-style: none; }
.ka-recenze { display: flex; flex-direction: column; gap: var(--ka-mezera-xs); padding: var(--ka-mezera-m); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-plocha); }
.ka-recenze header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--ka-mezera-xs); }
.ka-recenze header strong { font-weight: 600; }
.ka-recenze time { font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }
.ka-recenze p { margin: 0; }
.ka-recenze-odpoved { margin: 0; padding-inline-start: var(--ka-mezera-s); border-inline-start: 2px solid var(--ka-barva-linka); color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-recenze-hvezdy { width: 6.5em; height: 1.3em; flex: none; }
.ka-recenze-hvezdy-plne { fill: #f5a524; }
.ka-recenze-hvezdy-prazdne { fill: var(--ka-barva-linka); }
.ka-recenze-vse { align-self: flex-start; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $db = $k->app->db();
        if (!GoogleBusiness::ready($db)) {
            return $k->editor ? '<' . $p['znacka'] . $a . '><p>' . e(t('Connect Google and choose a Business Profile location under Administration → Integrations; the reviews then appear here.')) . '</p></' . $p['znacka'] . '>' : '';
        }
        $reviews = GoogleBusiness::reviews($db, (int) $o['pocet'], (int) $o['min_hvezd']);
        $summary = GoogleBusiness::summary($k->app->settings());
        $html = '';
        if ($o['souhrn'] && $summary['rating'] !== null && $summary['count'] > 0) {
            $number = rtrim(rtrim(format_number($summary['rating']), '0'), ',.');
            $html .= '<p class="ka-recenze-souhrn" role="img" aria-label="' . e(t('Rated %s out of 5', $number) . ' · ' . t('%d reviews on Google', $summary['count'])) . '">'
                . self::stars($summary['rating'], $p['id'] . '-s') . '<strong aria-hidden="true">' . e($number) . '</strong><span aria-hidden="true">' . e(t('%d reviews on Google', $summary['count'])) . '</span></p>';
        }
        if ($reviews !== []) {
            $html .= '<ul class="ka-recenze-seznam">';
            foreach ($reviews as $i => $r) {
                $html .= '<li class="ka-recenze"><header><strong>' . e($r['author'] !== '' ? $r['author'] : t('Google user')) . '</strong>'
                    . self::stars((float) $r['stars'], $p['id'] . '-' . $i, t('Rated %s out of 5', (string) $r['stars'])) . '</header>'
                    . '<time datetime="' . e(date('Y-m-d', strtotime($r['reviewed_at']) ?: 0)) . '">' . e(format_date($r['reviewed_at'])) . '</time>'
                    . ($r['comment'] !== '' ? '<p>' . nl2br(e($r['comment'])) . '</p>' : '')
                    . ($r['reply'] !== null && $r['reply'] !== '' ? '<p class="ka-recenze-odpoved"><strong>' . e(t('Reply from the business')) . ':</strong> ' . nl2br(e($r['reply'])) . '</p>' : '')
                    . '</li>';
            }
            $html .= '</ul>';
        } elseif ($k->editor) {
            $html .= '<p>' . e(t('No reviews with this many stars yet – they are fetched from Google once a day.')) . '</p>';
        }
        if ($o['odkaz'] !== '' && $html !== '') {
            $html .= '<a class="ka-tlacitko ka-tlacitko--obrys ka-recenze-vse" href="' . e($o['odkaz']) . '" target="_blank" rel="noopener">' . e(t('All reviews on Google')) . '</a>';
        }
        if ($html === '') {
            return '';
        }

        return '<' . $p['znacka'] . Text::withClass($a, 'ka-recenze-google') . '>' . $html . self::jsonLd($k, $reviews, $summary) . '</' . $p['znacka'] . '>';
    }

    /** Five stars with the fill clipped to the value's share (as the Rating element draws them). */
    private static function stars(float $value, string $id, string $label = ''): string
    {
        $value = max(0.0, min(5.0, $value));
        $path = '';
        for ($i = 0; $i < 5; $i++) {
            $path .= '<path d="M' . ($i * 26 + 12) . ' 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1-6.4-3.4-6.4 3.4 1.4-7.1-5.3-5 7.2-.9z"/>';
        }
        $clip = 'rg-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);

        return '<svg class="ka-recenze-hvezdy" viewBox="0 0 128 24"' . ($label !== '' ? ' role="img" aria-label="' . e($label) . '"' : ' aria-hidden="true"') . ' focusable="false">'
            . '<defs><clipPath id="' . $clip . '"><rect width="' . round($value / 5 * 128, 2) . '" height="24"/></clipPath></defs>'
            . '<g class="ka-recenze-hvezdy-prazdne">' . $path . '</g><g class="ka-recenze-hvezdy-plne" clip-path="url(#' . $clip . ')">' . $path . '</g></svg>';
    }

    /**
     * AggregateRating and the shown reviews on the company node (the same @id Front\Seo gives it, so search engines
     * join them) – only from what Google returned.
     *
     * @param list<array<string, mixed>> $reviews
     * @param array{rating: ?float, count: int, synced: string} $summary
     */
    private static function jsonLd(Context $k, array $reviews, array $summary): string
    {
        $s = $k->app->settings();
        $node = ['@context' => 'https://schema.org', '@type' => isset(\Kaleta\Front\Company::TYPES[$s->get('company_type')]) ? $s->get('company_type') : 'Organization',
            '@id' => $k->app->request->origin() . $k->app->url('') . '#firma', 'name' => $s->get('site_name')];
        if ($summary['rating'] !== null && $summary['count'] > 0) {
            $node['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $summary['rating'], 'reviewCount' => $summary['count'], 'bestRating' => 5, 'worstRating' => 1];
        }
        if ($reviews !== []) {
            $node['review'] = array_map(fn (array $r): array => array_filter([
                '@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r['author'] !== '' ? $r['author'] : t('Google user')], 'datePublished' => date('Y-m-d', strtotime((string) $r['reviewed_at']) ?: 0),
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $r['stars'], 'bestRating' => 5, 'worstRating' => 1], 'reviewBody' => $r['comment'] !== '' ? $r['comment'] : null,
            ], fn (mixed $v): bool => $v !== null), $reviews);
        }
        if (!isset($node['aggregateRating']) && !isset($node['review'])) {
            return '';
        }

        return '<script type="application/ld+json">' . str_replace('</', '<\/', (string) json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</script>';
    }
}
