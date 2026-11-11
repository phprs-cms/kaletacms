<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Pages;

/**
 * Where an imported page lands (3.33, M3): at its old path, parents and all – /services/heating/boilers stays
 * /services/heating/boilers instead of becoming /boilers with a 301. Both importers (Core\WebImport, Core\WpImport) use it.
 *
 * Kaleta's rules for a page address hold: each part is a slug (slugify), the whole address fits ka_stranky.seo_link
 * (MAX characters), and the first part must not be a word of the system (Pages::slugReserved – /novinky/…, /admin…,
 * a language code). An address over the limit has its last part shortened (never below MIN_LEAF characters); when even
 * that does not fit, or the first part is a system word, the page lands at its last part alone, as before 3.33. Either
 * way plan() says so (note shortened | flattened), the importer lists it and the old address redirects to the new one.
 *
 * The parent page is set when a page already has the parent path (same language); pages imported before their parent
 * are linked at the end of the import (linkParents). A page whose old parent never came over keeps its full address
 * without a parent – the router finds pages by the whole address, and the page form and update_page keep its prefix.
 */
final class ImportPath
{
    /** ka_stranky.seo_link */
    public const int MAX = 120;

    /** The shortest a shortened last part may get before the page lands at its last part alone. */
    public const int MIN_LEAF = 20;

    /**
     * The address for an old path: [path, note] – note '' (kept), shortened or flattened.
     *
     * @param string $oldPath the old path without slashes at the ends (WpImport::oldPath)
     * @param string $leaf the slug the importer chose for the page itself (from the last part, the WordPress slug or the title)
     * @param string $languageCode a leading part that is the import's language code is not a parent (/de/… of the German version)
     * @param (\Closure(string): bool)|null $reserved is a first part taken by the system (default Pages::slugReserved without a database)
     * @return array{0: string, 1: string}
     */
    public static function plan(string $oldPath, string $leaf, string $languageCode = '', ?\Closure $reserved = null): array
    {
        $segments = array_values(array_filter(explode('/', trim($oldPath, '/')), fn (string $s): bool => $s !== ''));
        if ($languageCode !== '' && count($segments) > 1 && strtolower($segments[0]) === strtolower($languageCode)) {
            array_shift($segments);
        }
        if (count($segments) <= 1) {
            return [$leaf, ''];
        }
        $parents = array_map(fn (string $s): string => slugify((string) preg_replace('/\.(html?|php|aspx?)$/i', '', rawurldecode($s)), 110), array_slice($segments, 0, -1));
        $reserved ??= fn (string $s): bool => Pages::slugReserved($s, null);
        if ($reserved($parents[0])) {
            return [$leaf, 'flattened'];
        }
        $prefix = implode('/', $parents) . '/';
        if (strlen($prefix . $leaf) <= self::MAX) {
            return [$prefix . $leaf, ''];
        }
        $room = self::MAX - strlen($prefix);
        if ($room < self::MIN_LEAF) {
            return [$leaf, 'flattened'];
        }

        return [$prefix . trim(substr($leaf, 0, $room), '-'), 'shortened'];
    }

    /**
     * The address and the parent page for an imported page: plan() with the site's system words, and the page that already
     * has the parent address in the language version (null = none yet – linkParents links it later).
     *
     * @return array{slug: string, parent: ?int, note: string}
     */
    public static function target(Db $db, string $oldPath, string $leaf, string $language, string $languageCode = ''): array
    {
        [$slug, $note] = self::plan($oldPath, $leaf, $languageCode, fn (string $s): bool => Pages::slugReserved($s, $db));

        return ['slug' => $slug, 'parent' => self::parentOf($db, $slug, $language), 'note' => $note];
    }

    /** The page at the parent address of a nested address (same language, not in the trash); null = none or not nested. */
    public static function parentOf(Db $db, string $slug, string $language): ?int
    {
        if (!str_contains($slug, '/')) {
            return null;
        }
        $id = $db->value('SELECT ids FROM {stranky} WHERE jazyk = ? AND seo_link = ? AND smazano IS NULL LIMIT 1', [$language, substr($slug, 0, (int) strrpos($slug, '/'))]);

        return $id !== null ? (int) $id : null;
    }

    /**
     * After an import: nested pages without a parent get the page at their parent address, if one exists now (a child
     * imported before its parent). Returns how many were linked.
     */
    public static function linkParents(Db $db): int
    {
        $linked = 0;
        foreach ($db->all("SELECT ids, seo_link, jazyk FROM {stranky} WHERE nadrazena IS NULL AND smazano IS NULL AND seo_link LIKE '%/%'") as $page) {
            $parent = self::parentOf($db, (string) $page['seo_link'], (string) $page['jazyk']);
            if ($parent !== null && $parent !== (int) $page['ids']) {
                $db->update('stranky', ['nadrazena' => $parent], ['ids' => (int) $page['ids']]);
                $linked++;
            }
        }

        return $linked;
    }

    /**
     * The address prefix a page keeps when it is saved without a parent (the page form, update_page): a nested page
     * whose old parent never came over keeps its directory; a page with a parent, or one that had a parent removed, does not.
     *
     * @param array<string, mixed>|null $stored the page as stored (seo_link, nadrazena); null = a new page
     */
    public static function keptPrefix(?array $stored): string
    {
        if ($stored === null || ($stored['nadrazena'] ?? null) !== null) {
            return '';
        }
        $slug = (string) ($stored['seo_link'] ?? '');

        return str_contains($slug, '/') ? substr($slug, 0, (int) strrpos($slug, '/') + 1) : '';
    }

    /**
     * The old paths an import could not keep as they were, in English (MCP and the result pages).
     *
     * @param array<mixed> $rows [old, new, shortened | flattened]
     * @return list<array{old: string, new: string, why: string}>
     */
    public static function summary(array $rows): array
    {
        $why = ['shortened' => 'the address is longer than ' . self::MAX . ' characters – its last part was shortened; the old address redirects',
            'flattened' => 'the address could not be kept (too long, or its first part is a word the system uses) – the page is at its last part; the old address redirects'];
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = ['old' => (string) ($r[0] ?? ''), 'new' => (string) ($r[1] ?? ''), 'why' => $why[(string) ($r[2] ?? '')] ?? (string) ($r[2] ?? '')];
            }
        }

        return $out;
    }
}
