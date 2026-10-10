<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Pre-publish check of a build for Claude (MCP) – the same rules as in the builder (image/stavitel.js, check()):
 * buttons without a link, images without a file or description and, for pages, the heading outline. Text contrast is
 * missing here: it needs the rendered page, and the builder checks it in the browser.
 */
final class Check
{
    public const int MAX = 12;

    /**
     * @param array<string, mixed> $build sanitized build
     * @param bool $headings check the heading outline (a page should have one h1 and not skip levels)
     * @param int $max at most this many findings (the site audit wants them all)
     * @return list<array{id: ?string, zprava: string}>
     */
    public static function builds(array $build, bool $headings, int $max = self::MAX): array
    {
        $findings = [];
        $outline = [];
        $tags = static fn (mixed $x): bool => is_string($x) && str_contains($x, '{{');
        $walk = static function (array $children) use (&$walk, &$findings, &$outline, $tags): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $o = is_array($p['obsah'] ?? null) ? $p['obsah'] : [];
                $id = isset($p['id']) ? (string) $p['id'] : null;
                $type = $p['typ'] ?? '';
                if ($type === 'tlacitko' && in_array($o['odkaz'] ?? '', ['', '#'], true)) {
                    $findings[] = ['id' => $id, 'zprava' => t('The button “%s” leads nowhere – add a link.', self::text($o['text'] ?? ''))];
                }
                if ($type === 'obrazek' && ($o['src'] ?? '') === '') {
                    $findings[] = ['id' => $id, 'zprava' => t('No image selected – it will not appear on the site.')];
                } elseif ($type === 'obrazek' && ($o['alt'] ?? '') === '' && !$tags($o['src'])) {
                    $findings[] = ['id' => $id, 'zprava' => t('The image has no description for blind visitors (alt).')];
                }
                // a heading with the p tag (big number, label) does not belong in the outline
                if ($type === 'nadpis' && preg_match('/^h([1-6])$/', (string) ($p['znacka'] ?? 'h2'), $m)) {
                    $outline[] = [$id, (int) $m[1], self::text($o['text'] ?? '')];
                }
                if (is_array($p['deti'] ?? null)) {
                    $walk($p['deti']);
                }
            }
        };
        $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);
        if ($headings) {
            $h1 = array_values(array_filter($outline, static fn (array $n): bool => $n[1] === 1));
            if ($h1 === []) {
                $findings[] = ['id' => $outline[0][0] ?? null, 'zprava' => t('The page has no main heading (h1) – search engines and screen readers use it to tell what the page is about.')];
            }
            if (count($h1) > 1) {
                $findings[] = ['id' => $h1[1][0], 'zprava' => t('The page has more than one main heading (h1) – keep just one.')];
            }
            foreach ($outline as $i => $n) {
                if ($i > 0 && $n[1] > $outline[$i - 1][1] + 1) {
                    $findings[] = ['id' => $n[0], 'zprava' => t('The heading “%s” skips a level (h%d → h%d).', mb_substr($n[2], 0, 40), $outline[$i - 1][1], $n[1])];
                }
            }
        }

        return array_slice($findings, 0, $max);
    }

    /**
     * Collection lists and item templates (3.9.2): a list of a missing collection or category shows only its empty text, and
     * {{key}} that the collection does not have stays empty on the site – both look fine in the draft JSON and break the page.
     *
     * @param array<string, mixed> $build sanitized build
     * @param ?array<string, mixed> $collection the collection whose item template this is (null = a page, a part…)
     * @return list<array{id: ?string, zprava: string}>
     */
    public static function collections(\Kaleta\Core\Db $db, array $build, ?array $collection, int $max = self::MAX): array
    {
        $findings = [];
        $keys = static function (array $collection) use ($db): array {
            // the keys derived from fields ({{file_name}}, {{date_iso}}…) come from the values of an empty item, those of a
            // preset (an event's place, a product's price) from a real one when there is any
            $empty = ['idp' => 0, 'nazev' => '', 'seo_link' => '', 'datum' => date('Y-m-d H:i:s'), 'obrazek' => '', 'data' => []];
            $item = $db->one('SELECT * FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL ORDER BY zobrazit DESC, idp LIMIT 1', [(int) $collection['idk']]);
            if (is_array($item)) {
                $item['data'] = json_decode((string) ($item['data'] ?? ''), true) ?: [];
            }
            $values = Collections::sample($collection) + Collections::values($collection, $empty, static fn (string $p): string => $p, $db)
                + (is_array($item) ? Collections::values($collection, $item, static fn (string $p): string => $p, $db) : []);

            return [...array_keys($values), 'latest', 'versions']; // + a document library's file links (Core\Documents::values)
        };
        $walk = static function (array $children, ?array $collection, array $known) use (&$walk, &$findings, $db, $keys): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $o = is_array($p['obsah'] ?? null) ? $p['obsah'] : [];
                $id = isset($p['id']) ? (string) $p['id'] : null;
                $inner = [$collection, $known];
                if (($p['typ'] ?? '') === 'kolekce' && ($o['kolekce'] ?? '') !== '') {
                    $list = Collections::bySlug($db, (string) $o['kolekce']);
                    if ($list === null) {
                        $findings[] = ['id' => $id, 'zprava' => t('The collection list shows the collection “%s”, which does not exist.', (string) $o['kolekce'])];
                        continue;
                    }
                    $category = trim((string) ($o['kategorie'] ?? ''));
                    if ($category !== '' && $category !== '*') {
                        $row = CollectionCategories::bySlug($db, (int) $list['idk'], $category, '');
                        if ($row === null) {
                            $findings[] = ['id' => $id, 'zprava' => t('The collection list shows the category “%s”, which the collection does not have – it will stay empty.', $category)];
                        }
                    }
                    $inner = ($o['zdroj'] ?? 'polozky') === 'kategorie' ? [null, []] : [$list, $keys($list)];
                } elseif ($collection !== null) {
                    preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/', (string) json_encode($o, JSON_UNESCAPED_UNICODE), $m);
                    foreach (array_unique($m[1]) as $key) {
                        if (!in_array($key, $known, true)) {
                            $findings[] = ['id' => $id, 'zprava' => t('{{%s}} is not a field of the collection “%s” – it will stay empty on the site.', $key, (string) $collection['nazev'])];
                        }
                    }
                }
                if (is_array($p['deti'] ?? null) && ($p['typ'] ?? '') !== 'komponenta') {
                    $walk($p['deti'], ...$inner);
                }
            }
        };
        $walk(is_array($build['deti'] ?? null) ? $build['deti'] : [], $collection, $collection !== null ? $keys($collection) : []);

        return array_slice($findings, 0, $max);
    }

    private static function text(mixed $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5));
    }
}
