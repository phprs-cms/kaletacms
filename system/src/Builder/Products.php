<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * A product catalogue without a checkout (2.11): products with parameters to compare, variants, a datasheet and an
 * enquiry basket – the visitor collects products and sends one enquiry instead of ordering and paying.
 *
 *  - Parameters: one "Name: value" per line (field type parametry); {{parameters}} is a table, the comparison page puts
 *    the same names side by side.
 *  - Variants: one "name | code | price" per line (field type varianty); the price is text as written (from 1 200 Kč).
 *  - The basket lives in the visitor's browser (localStorage, no cookies); the Form field "basket" sends it as JSON and
 *    the server rebuilds every line from the database (basketLines) – a visitor can only send products that exist.
 *  - The comparison: /<collection>/_compare?i=a,b,c (up to four items, Front\Kernel; the Czech _porovnat answers too).
 */
final class Products
{
    public const int MAX_LINES = 60;
    public const int MAX_BASKET = 50;
    public const int MAX_COMPARE = 4;

    /** Roles of a products collection: role => [field key in the preset, types]. */
    private const array ROLES = ['parameters' => ['parameters', ['parametry']], 'variants' => ['variants', ['varianty']], 'image' => ['image', ['obrazek']],
        'code' => ['code', ['text']], 'price' => ['price', ['cislo', 'text']], 'price_note' => ['price_note', ['text']]];

    /** Parameters from a form or Claude: "Name: value" lines, tags removed; null when a line has no name or value. */
    public static function cleanParameters(string $text): ?string
    {
        $out = [];
        foreach (self::lines($text) as $line) {
            if (preg_match('/^([^:]{1,80}):\s*(.{1,200})$/u', $line, $m) !== 1) {
                return null;
            }
            $out[] = trim($m[1]) . ': ' . trim($m[2]);
        }

        return implode("\n", array_slice($out, 0, self::MAX_LINES));
    }

    /** @return list<array{0: string, 1: string}> [name, value] */
    public static function parameters(string $text): array
    {
        $out = [];
        foreach (self::lines($text) as $line) {
            if (preg_match('/^([^:]{1,80}):\s*(.+)$/u', $line, $m) === 1) {
                $out[] = [trim($m[1]), trim($m[2])];
            }
        }

        return $out;
    }

    /** Variants from a form or Claude: "name | code | price" lines (code and price optional); null when a line has no name. */
    public static function cleanVariants(string $text): ?string
    {
        $out = [];
        foreach (self::lines($text) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] === '' || count($parts) > 3 || mb_strlen($parts[0]) > 100 || mb_strlen($parts[1] ?? '') > 60 || mb_strlen($parts[2] ?? '') > 40) {
                return null;
            }
            $out[] = rtrim(implode(' | ', [$parts[0], $parts[1] ?? '', $parts[2] ?? '']), ' |');
        }

        return implode("\n", array_slice($out, 0, self::MAX_LINES));
    }

    /** @return list<array{name: string, code: string, price: string}> */
    public static function variants(string $text): array
    {
        $out = [];
        foreach (self::lines($text) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] !== '') {
                $out[] = ['name' => $parts[0], 'code' => $parts[1] ?? '', 'price' => $parts[2] ?? ''];
            }
        }

        return $out;
    }

    public static function parametersTable(string $text): string
    {
        $rows = self::parameters($text);

        return $rows === [] ? '' : '<table class="ka-parametry"><tbody>' . implode('', array_map(fn (array $r): string => '<tr><th scope="row">' . e($r[0]) . '</th><td>' . e($r[1]) . '</td></tr>', $rows)) . '</tbody></table>';
    }

    public static function variantsTable(string $text): string
    {
        $rows = self::variants($text);
        if ($rows === []) {
            return '';
        }
        $code = array_filter(array_column($rows, 'code')) !== [];
        $price = array_filter(array_column($rows, 'price')) !== [];

        return '<table class="ka-varianty"><thead><tr><th scope="col">' . e(t('Variant')) . '</th>' . ($code ? '<th scope="col">' . e(t('Code')) . '</th>' : '') . ($price ? '<th scope="col">' . e(t('Price')) . '</th>' : '')
            . '</tr></thead><tbody>' . implode('', array_map(fn (array $r): string => '<tr><td>' . e($r['name']) . '</td>' . ($code ? '<td>' . e($r['code']) . '</td>' : '') . ($price ? '<td>' . e($r['price']) . '</td>' : '') . '</tr>', $rows))
            . '</tbody></table>';
    }

    /** @return list<string> non-empty trimmed lines without tags */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map(fn (string $l): string => trim(strip_tags($l)), preg_split('/\R/u', $text) ?: []), fn (string $l): bool => $l !== ''));
    }

    /** The product fields of a collection made from the products preset: role => key ('' when gone); null otherwise. @return array<string, string>|null */
    public static function fields(array $collection): ?array
    {
        if (($collection['preset'] ?? '') !== 'products') {
            return null;
        }
        $out = [];
        foreach (self::ROLES as $role => [$key, $types]) {
            $out[$role] = Presets::field($collection, 'products', $key, $types) ?? '';
        }

        return $out;
    }

    /**
     * The internal _product value of a product (for the "Add to enquiry" element): collection, address, name and variant
     * names as JSON. A key outside the placeholder pattern, so no text can show it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(array $collection, array $item): array
    {
        $fields = self::fields($collection);
        if ($fields === null || ($item['seo_link'] ?? '') === '') {
            return [];
        }
        $variants = $fields['variants'] !== '' ? array_column(self::variants((string) ($item['data'][$fields['variants']] ?? '')), 'name') : [];

        return ['_product' => [(string) json_encode(['c' => (string) $collection['seo_link'], 'i' => (string) $item['seo_link'], 'n' => (string) $item['nazev'], 'v' => $variants],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'text']];
    }

    /**
     * The basket a visitor sends: JSON [{c: collection, i: item address, v: variant, q: quantity}]. Every line must be a
     * visible item of a products collection and a variant it has; the text is rebuilt from the database ("2 × Name –
     * Variant (code)"). [] = an empty basket, null = not valid (too many lines, unknown products, a bad quantity).
     *
     * @return list<string>|null
     */
    public static function basketLines(Db $db, string $json): ?array
    {
        if (trim($json) === '' || trim($json) === '[]') {
            return [];
        }
        $lines = json_decode($json, true);
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::MAX_BASKET) {
            return null;
        }
        $out = [];
        foreach ($lines as $line) {
            $quantity = is_array($line) && is_int($line['q'] ?? null) ? $line['q'] : 0;
            $collection = is_array($line) && is_string($line['c'] ?? null) && preg_match('/^[a-z0-9-]{1,110}$/D', $line['c']) === 1 ? Collections::bySlug($db, $line['c']) : null;
            $fields = $collection !== null ? self::fields($collection) : null;
            if ($fields === null || $quantity < 1 || $quantity > 9999 || !is_string($line['i'] ?? null) || preg_match('/^[a-z0-9-]{1,160}$/D', $line['i']) !== 1) {
                return null;
            }
            $item = $db->one('SELECT nazev, data FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND zobrazit = 1 AND smazano IS NULL LIMIT 1', [(int) $collection['idk'], $line['i']]);
            if ($item === null) {
                return null;
            }
            $data = json_decode((string) $item['data'], true) ?: [];
            $variant = is_string($line['v'] ?? null) ? $line['v'] : '';
            $known = $fields['variants'] !== '' ? self::variants((string) ($data[$fields['variants']] ?? '')) : [];
            $match = array_values(array_filter($known, fn (array $v): bool => $v['name'] === $variant))[0] ?? null;
            if ($variant !== '' && $match === null) {
                return null;
            }
            $code = $match !== null ? $match['code'] : ($fields['code'] !== '' ? (string) ($data[$fields['code']] ?? '') : '');
            $out[] = $quantity . ' × ' . $item['nazev'] . ($variant !== '' ? ' – ' . $variant : '') . ($code !== '' ? ' (' . $code . ')' : '');
        }

        return $out;
    }

    /**
     * The comparison of up to four items: the names of all their parameters in the order they first appear, and each
     * item's value of each (or '').
     *
     * @param list<array<string, mixed>> $items with decoded data
     * @return list<array{0: string, 1: list<string>}> [parameter name, values by item]
     */
    public static function comparison(string $parametersKey, array $items): array
    {
        $byItem = array_map(fn (array $item): array => array_column(self::parameters((string) ($item['data'][$parametersKey] ?? '')), 1, 0), $items);
        $names = [];
        foreach ($byItem as $parameters) {
            foreach (array_keys($parameters) as $name) {
                $names[(string) $name] = true;
            }
        }

        return array_map(fn (string $name): array => [$name, array_map(fn (array $parameters): string => (string) ($parameters[$name] ?? ''), $byItem)], array_keys($names));
    }
}
