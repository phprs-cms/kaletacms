<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * WooCommerce products of a WordPress export as a Kaleta product catalogue (3.12). A shop moves to the Products preset
 * (system/presets/products.php – products with parameters, a price and an enquiry basket instead of a checkout):
 *
 *  - the post type "product" with WooCommerce's own meta becomes a collection with the preset's fields: the featured image,
 *    the SKU as the code, the category, the excerpt as the summary, the content as the description, the attributes as
 *    parameters, the current price and the stock status as availability;
 *  - product categories (the taxonomy product_cat) become categories of the collection (Builder\CollectionCategories, two
 *    levels, a name and an address per language), and their old addresses (WooCommerce's default /product-category/…)
 *    redirect to the category pages;
 *  - variations, orders, coupons and the shop's settings have no counterpart and are reported.
 *
 * Pure functions, covered by tools/unit-tests.php; Core\WpFile reads the values, Core\WpImport writes.
 */
final class WpWooCommerce
{
    public const string TYPE = 'product';

    /** The taxonomy of product categories and of the product type (simple, variable…). */
    public const string CATEGORIES = 'product_cat';
    public const string PRODUCT_TYPE = 'product_type';

    /** Global attributes are taxonomies pa_<name> (pa_colour: Red). */
    public const string ATTRIBUTE_PREFIX = 'pa_';

    /** WooCommerce's default base of product category addresses (Settings → Permalinks): /product-category/<parent>/<child>/. */
    public const string CATEGORY_BASE = 'product-category';

    /** The product meta that is read: SKU, prices, stock status and the product's own attributes (serialized). */
    public const array META = ['_sku', '_regular_price', '_sale_price', '_price', '_stock_status', '_product_attributes'];

    /** The preset the products go to. */
    public const string PRESET = 'products';

    /** Stock status => the text of the availability field (a site text, translated into the product's language). */
    public const array AVAILABILITY = ['instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'On backorder'];

    /** Is this post a WooCommerce product (the post type and something only WooCommerce writes)? */
    public static function isProduct(array $p): bool
    {
        return ($p['typ'] ?? '') === self::TYPE && is_array($p['woo'] ?? null) && $p['woo'] !== [];
    }

    /**
     * The price a visitor saw: the sale price while it runs (WooCommerce keeps the current one in _price), else the
     * regular price. A number with a dot; '' = none.
     *
     * @param array<string, string> $woo
     */
    public static function price(array $woo): string
    {
        foreach (['_price', '_sale_price', '_regular_price'] as $key) {
            $value = str_replace(',', '.', trim((string) ($woo[$key] ?? '')));
            if (preg_match('/^\d{1,12}(\.\d{1,6})?$/D', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    /**
     * The product's attributes as "Name: value" lines (the Parameters field): its own attributes from _product_attributes
     * (values "Red | Blue" become "Red, Blue") and the global ones by their terms; variation-only attributes are kept too –
     * they say what the variants differ in.
     *
     * @param list<array{0: string, 1: string}> $terms [taxonomy, term name] of the global attributes
     */
    public static function parameters(string $serialized, array $terms): string
    {
        $lines = [];
        $global = [];
        foreach ($terms as [$taxonomy, $name]) {
            if ($name !== '') {
                $global[$taxonomy][] = $name;
            }
        }
        foreach (WpLanguages::unserializeArray($serialized) ?? [] as $key => $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $taxonomy = (string) ($attribute['name'] ?? $key);
            $isTaxonomy = (bool) ($attribute['is_taxonomy'] ?? false);
            $values = $isTaxonomy ? ($global[$taxonomy] ?? []) : array_map('trim', explode('|', (string) ($attribute['value'] ?? '')));
            unset($global[$taxonomy]);
            $label = $isTaxonomy ? WpTypes::label((string) preg_replace('/^' . self::ATTRIBUTE_PREFIX . '/', '', $taxonomy)) : trim($taxonomy);
            $values = array_values(array_filter($values, fn (string $v): bool => $v !== ''));
            if ($label !== '' && $values !== []) {
                $lines[] = self::line($label, $values);
            }
        }
        foreach ($global as $taxonomy => $values) {
            $lines[] = self::line(WpTypes::label(substr((string) $taxonomy, strlen(self::ATTRIBUTE_PREFIX))), $values);
        }

        return implode("\n", array_slice($lines, 0, 40));
    }

    /** One "Name: value" line within the limits of Products::cleanParameters (80 and 200 characters). @param list<string> $values */
    private static function line(string $label, array $values): string
    {
        return mb_substr(trim(str_replace([':', "\n"], ' ', $label)), 0, 80) . ': ' . mb_substr(trim(str_replace("\n", ' ', implode(', ', array_unique($values)))), 0, 200);
    }

    /**
     * The category shown on the product (the Category field, which the list page filters by): the most specific one – a
     * category whose parent the product also has – else the first.
     *
     * @param array<string, string> $categories slug => name of the product's categories
     * @param array<string, string> $parents slug => slug of its parent
     */
    public static function mainCategory(array $categories, array $parents): string
    {
        foreach ($categories as $slug => $name) {
            if (isset($categories[$parents[(string) $slug] ?? ''])) {
                return $name;
            }
        }

        return (string) (reset($categories) ?: '');
    }

    /**
     * The values of the preset's fields for one product (before Collections::sanitizeData); the image is the address of the
     * featured image, downloaded with the other images.
     *
     * @param array<string, mixed> $p a post from WpFile::item()
     * @param array<int, string> $attachments attachment number => address
     * @param array<string, string> $parents category slug => parent slug
     * @param callable(string): string $text translates a site text into the product's language
     * @return array<string, string>
     */
    public static function values(array $p, array $attachments, array $parents, callable $text): array
    {
        $woo = (array) $p['woo'];
        $status = (string) ($woo['_stock_status'] ?? '');

        return [
            'image' => (string) ($attachments[(int) ($p['nahled'] ?? 0)] ?? ''),
            'code' => mb_substr(trim((string) ($woo['_sku'] ?? '')), 0, 200),
            'category' => self::mainCategory((array) $p['kategorie'], $parents),
            'summary' => trim(html_entity_decode(strip_tags((string) $p['perex']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'description' => WpContent::sanitize((string) $p['obsah'], $attachments),
            'parameters' => self::parameters((string) ($woo['_product_attributes'] ?? ''), (array) $p['vlastnosti']),
            'price' => self::price($woo),
            'availability' => isset(self::AVAILABILITY[$status]) ? $text(self::AVAILABILITY[$status]) : '',
        ];
    }

    /**
     * The old addresses of a product category: under WooCommerce's default base and, when the export shows the shop used
     * another one (categoryBase()), under that too – with the parent's slug (/product-category/<parent>/<child>/) and
     * without it (WordPress answers both), behind the language prefix of a translation (/ar/…).
     *
     * @return list<string> paths without the slashes at the ends
     */
    public static function oldCategoryPaths(string $slug, string $parentSlug, string $languagePrefix, string $customBase = ''): array
    {
        $decode = fn (string $s): string => rawurldecode($s);
        $paths = [];
        foreach (array_unique(array_filter([self::CATEGORY_BASE, trim($customBase, '/')])) as $base) {
            $prefix = ($languagePrefix !== '' ? $languagePrefix . '/' : '') . $base . '/';
            $paths[] = $parentSlug !== '' ? $prefix . $decode($parentSlug) . '/' . $decode($slug) : '';
            $paths[] = $prefix . $decode($slug);
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * The links of a post to the old site's own pages (paths, decoded, without the slashes at the ends) – the analysis keeps
     * them to find the shop's product category base (categoryBase()).
     *
     * @return list<string>
     */
    public static function ownPaths(string $html, string $domain): array
    {
        if ($domain === '' || !str_contains($html, $domain)) {
            return [];
        }
        preg_match_all('#href=["\']https?://(?:www\.)?' . preg_quote($domain, '#') . '(?::\d+)?(/[^"\'?\#]*)#i', $html, $m);

        return array_values(array_unique(array_filter(array_map(fn (string $p): string => trim(rawurldecode($p), '/'), $m[1]), fn (string $p): bool => substr_count($p, '/') >= 1)));
    }

    /**
     * The base of product category addresses the old shop used, from links to its category pages in menus and texts
     * (WooCommerce keeps the base in its permalink settings, which an export does not contain): a path whose last segment
     * is a product category's slug (after an optional language prefix and the parent's slug) votes for what stands before
     * it. '' = no other base than the default one was found.
     *
     * @param list<string> $paths decoded paths without the slashes at the ends
     * @param list<string> $slugs product category slugs (as in the export, possibly percent-encoded)
     * @param list<string> $languages language prefixes of the old site (en, ar)
     */
    public static function categoryBase(array $paths, array $slugs, array $languages): string
    {
        $known = array_flip(array_map(fn (string $s): string => mb_strtolower(rawurldecode($s)), $slugs));
        $votes = [];
        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', mb_strtolower($path)), fn (string $s): bool => $s !== ''));
            if ($segments !== [] && in_array($segments[0], $languages, true)) {
                array_shift($segments);
            }
            if (count($segments) < 2 || !isset($known[(string) end($segments)])) {
                continue;
            }
            array_pop($segments);
            if (count($segments) >= 2 && isset($known[(string) end($segments)])) {
                array_pop($segments); // the parent's slug of a subcategory
            }
            $base = implode('/', $segments);
            if ($base !== '' && $base !== self::CATEGORY_BASE && count($segments) <= 3) {
                $votes[$base] = ($votes[$base] ?? 0) + 1;
            }
        }
        arsort($votes);

        return (string) (array_key_first($votes) ?? '');
    }
}
