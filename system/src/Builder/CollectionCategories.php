<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;
use Kaleta\Core\Slug;
use Kaleta\Core\WpContent;

/**
 * Categories of a collection (3.7): two levels – a category and its subcategories – with a landing page each.
 *
 * URLs: /<collection>/<category> and /<collection>/<category>/<subcategory>. They share the first level with item pages
 * (/<collection>/<item>), so an address can never be both: saving a category refuses an address an item of the collection
 * has (in any language), saving an item refuses an address a category has, and should a clash still come in (an import of
 * old data) the item wins – an existing link never changes what it shows. A prefix (/<collection>/c/<category>) would avoid
 * the check, but every migrated shop and reference list (WordPress /products/<cat>/<sub>/, /stresni-fve/) keeps a clean
 * address this way, and the 3.6 pattern redirects map the old ones.
 *
 * The tree, the order, the image and the visibility are shared by every language; the name, the address, the description
 * and the SEO texts are per language (ka_collection_category_texts). A category is in a language version when it has texts
 * there. An item row is one language version, so it is assigned to categories itself (ka_collection_item_categories).
 */
final class CollectionCategories
{
    /** Levels: a category and its subcategories. */
    public const int LEVELS = 2;

    /** Addresses a category can never have: the stable address of a document's file (/<collection>/<item>/latest). */
    public const array RESERVED_SLUGS = ['latest'];

    public const string SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,159}$/D';

    /** The key of the category template's versions and signed preview: kategorie:<idk>, another language kategorie:<idk>:<jazyk>. */
    public const string TEMPLATE_PREFIX = 'kategorie:';

    /**
     * The categories of a collection in one language, as a tree in display order: every top-level category followed by its
     * subcategories. Only categories with texts in the language; $visibleOnly leaves out hidden ones (and the children of a
     * hidden category).
     *
     * @return list<array{id: int, parent_id: ?int, image: string, sort_order: int, visible: bool, name: string, slug: string, description: string, seo_title: string, seo_description: string, parent_slug: string, parent_name: string}>
     */
    public static function tree(Db $db, int $idk, string $language, bool $visibleOnly = false): array
    {
        $rows = $db->all('SELECT c.id, c.parent_id, c.image, c.sort_order, c.visible, t.name, t.slug, t.description, t.seo_title, t.seo_description
            FROM {collection_categories} c JOIN {collection_category_texts} t ON t.category_id = c.id AND t.language = ?
            WHERE c.idk = ?' . ($visibleOnly ? ' AND c.visible = 1' : '') . ' ORDER BY c.sort_order, t.name, c.id', [$language, $idk]);
        $top = [];
        $children = [];
        foreach ($rows as $r) {
            $row = ['id' => (int) $r['id'], 'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null, 'image' => (string) $r['image'], 'sort_order' => (int) $r['sort_order'],
                'visible' => (bool) $r['visible'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'description' => (string) $r['description'],
                'seo_title' => (string) $r['seo_title'], 'seo_description' => (string) $r['seo_description'], 'parent_slug' => '', 'parent_name' => ''];
            if ($row['parent_id'] === null) {
                $top[] = $row;
            } else {
                $children[$row['parent_id']][] = $row;
            }
        }
        $out = [];
        foreach ($top as $row) {
            $out[] = $row;
            foreach ($children[$row['id']] ?? [] as $child) {
                $out[] = ['parent_slug' => $row['slug'], 'parent_name' => $row['name']] + $child;
            }
        }

        return $out;
    }

    /**
     * Categories to tick on an item of a language (the item form): the tree with the names of the language, a category
     * without them under its default-language name.
     *
     * @return list<array{id: int, parent_id: ?int, name: string, visible: bool}>
     */
    public static function choices(Db $db, int $idk, string $language): array
    {
        $names = $language === '' ? [] : array_column(self::tree($db, $idk, $language), 'name', 'id');

        return array_map(fn (array $c): array => ['id' => $c['id'], 'parent_id' => $c['parent_id'], 'name' => (string) ($names[$c['id']] ?? $c['name']), 'visible' => $c['visible']], self::tree($db, $idk, ''));
    }

    /** Does the collection have any category at all (in any language)? */
    public static function has(Db $db, int $idk): bool
    {
        return $db->value('SELECT 1 FROM {collection_categories} WHERE idk = ? LIMIT 1', [$idk]) !== null;
    }

    /**
     * One category with its texts in every language.
     *
     * @return array{id: int, idk: int, parent_id: ?int, image: string, sort_order: int, visible: bool, texts: array<string, array{name: string, slug: string, description: string, seo_title: string, seo_description: string}>}|null
     */
    public static function byId(Db $db, int $id): ?array
    {
        $r = $db->one('SELECT * FROM {collection_categories} WHERE id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $texts = [];
        foreach ($db->all('SELECT language, name, slug, description, seo_title, seo_description FROM {collection_category_texts} WHERE category_id = ? ORDER BY language', [$id]) as $t) {
            $texts[(string) $t['language']] = ['name' => (string) $t['name'], 'slug' => (string) $t['slug'], 'description' => (string) $t['description'],
                'seo_title' => (string) $t['seo_title'], 'seo_description' => (string) $t['seo_description']];
        }

        return ['id' => (int) $r['id'], 'idk' => (int) $r['idk'], 'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null, 'image' => (string) $r['image'],
            'sort_order' => (int) $r['sort_order'], 'visible' => (bool) $r['visible'], 'texts' => $texts];
    }

    /**
     * A category of the collection by its address in a language (with the parent's address for a subcategory).
     *
     * @return array{id: int, parent_id: ?int, image: string, sort_order: int, visible: bool, name: string, slug: string, description: string, seo_title: string, seo_description: string, parent_slug: string, parent_name: string, parent_visible: bool}|null
     */
    public static function bySlug(Db $db, int $idk, string $slug, string $language): ?array
    {
        $r = $db->one('SELECT c.id, c.parent_id, c.image, c.sort_order, c.visible, t.name, t.slug, t.description, t.seo_title, t.seo_description,
                COALESCE(pt.slug, \'\') AS parent_slug, COALESCE(pt.name, \'\') AS parent_name, COALESCE(p.visible, 1) AS parent_visible
            FROM {collection_category_texts} t JOIN {collection_categories} c ON c.id = t.category_id
            LEFT JOIN {collection_categories} p ON p.id = c.parent_id LEFT JOIN {collection_category_texts} pt ON pt.category_id = c.parent_id AND pt.language = t.language
            WHERE t.idk = ? AND t.language = ? AND t.slug = ?', [$idk, $language, $slug]);
        if ($r === null) {
            return null;
        }

        return ['id' => (int) $r['id'], 'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null, 'image' => (string) $r['image'], 'sort_order' => (int) $r['sort_order'],
            'visible' => (bool) $r['visible'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'description' => (string) $r['description'],
            'seo_title' => (string) $r['seo_title'], 'seo_description' => (string) $r['seo_description'], 'parent_slug' => (string) $r['parent_slug'],
            'parent_name' => (string) $r['parent_name'], 'parent_visible' => (bool) $r['parent_visible']];
    }

    /** Is the category on the site: visible, and so is its parent (a subcategory needs its parent's text in the language for its address). */
    public static function isPublic(array $category): bool
    {
        return $category['visible'] && ($category['parent_id'] === null || ($category['parent_visible'] && $category['parent_slug'] !== ''));
    }

    /** The path of a category's page within the site (without the language prefix): <collection>/<category>[/<subcategory>]. */
    public static function path(string $collectionSlug, array $category): string
    {
        return $collectionSlug . '/' . ($category['parent_slug'] !== '' ? $category['parent_slug'] . '/' : '') . $category['slug'];
    }

    /** The category and its subcategories (an item list of a top-level category shows the items of its subcategories too). @return list<int> */
    public static function withChildren(Db $db, int $id, bool $visibleOnly = true): array
    {
        return [$id, ...array_map(intval(...), array_column($db->all('SELECT id FROM {collection_categories} WHERE parent_id = ?' . ($visibleOnly ? ' AND visible = 1' : ''), [$id]), 'id'))];
    }

    /** SQL condition: the category (alias c) is on the site – visible, and so is its parent (isPublic, without the texts). */
    private const string PUBLIC_SQL = 'c.visible = 1 AND (c.parent_id IS NULL OR EXISTS (SELECT 1 FROM {collection_categories} p WHERE p.id = c.parent_id AND p.visible = 1))';

    /** Ids of the categories an item belongs to, subcategories first, then by the category order. @return list<int> */
    public static function ofItem(Db $db, int $idp, bool $visibleOnly = false): array
    {
        return array_map(intval(...), array_column($db->all('SELECT c.id FROM {collection_item_categories} ic JOIN {collection_categories} c ON c.id = ic.category_id
            WHERE ic.idp = ?' . ($visibleOnly ? ' AND c.visible = 1' : '') . ' ORDER BY c.parent_id IS NULL, c.sort_order, c.id', [$idp]), 'id'));
    }

    /**
     * Addresses of an item's categories in a language (for MCP and the export): the category's own address, in the item's
     * language or, without a text there, in the default language.
     *
     * @return list<string>
     */
    public static function slugsOfItem(Db $db, int $idp, string $language, bool $publicOnly = false): array
    {
        // $publicOnly: only the categories on the site – a user without the Collections section never learns a hidden one (3.7, N37-9)
        return array_map('strval', array_column($db->all('SELECT COALESCE(t.slug, d.slug) AS slug FROM {collection_item_categories} ic JOIN {collection_categories} c ON c.id = ic.category_id
            LEFT JOIN {collection_category_texts} t ON t.category_id = c.id AND t.language = ? LEFT JOIN {collection_category_texts} d ON d.category_id = c.id AND d.language = \'\'
            WHERE ic.idp = ?' . ($publicOnly ? ' AND ' . self::PUBLIC_SQL : '') . ' ORDER BY c.parent_id IS NULL, c.sort_order, c.id', [$language, $idp]), 'slug'));
    }

    /** Is the category on the site: visible, and so is its parent (by id, without the texts of a language). */
    public static function isPublicId(Db $db, int $id): bool
    {
        return $db->value('SELECT 1 FROM {collection_categories} c WHERE c.id = ? AND ' . self::PUBLIC_SQL, [$id]) !== null;
    }

    /**
     * Assigns an item to categories (replaces what it had). Ids that are not categories of the item's collection are dropped.
     *
     * @param list<int> $ids
     */
    public static function assign(Db $db, int $idp, int $idk, array $ids): void
    {
        $valid = $ids === [] ? [] : array_map(intval(...), array_column($db->all('SELECT id FROM {collection_categories} WHERE idk = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$idk, ...array_values(array_unique($ids))]), 'id'));
        $db->delete('collection_item_categories', ['idp' => $idp]);
        foreach ($valid as $id) {
            $db->insert('collection_item_categories', ['idp' => $idp, 'category_id' => $id]);
        }
    }

    /**
     * Category ids from addresses (MCP, import): the address in the item's language, otherwise in the default language.
     *
     * @param list<string> $slugs
     * @param list<string> $unknown addresses that are no category of the collection
     * @return list<int>
     */
    public static function idsFromSlugs(Db $db, int $idk, array $slugs, string $language, array &$unknown = []): array
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $slug = trim($slug);
            $id = $db->value('SELECT category_id FROM {collection_category_texts} WHERE idk = ? AND slug = ? AND language IN (?, \'\') ORDER BY language = ? DESC LIMIT 1', [$idk, $slug, $language, $language]);
            if ($id === null) {
                $unknown[] = $slug;
            } else {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Is the address taken by a category of the collection (in any language)? The item save refuses it. */
    public static function slugIsCategory(Db $db, int $idk, string $slug, ?string $language = null): bool
    {
        // with slugs per language (3.9, Core\Slug) only a category of the item's own language version takes the address
        [$sameLanguage, $languageParams] = $language !== null ? Slug::scope($db, $language, 'language') : ['', []];

        return $db->value('SELECT 1 FROM {collection_category_texts} WHERE idk = ? AND slug = ?' . $sameLanguage . ' LIMIT 1', [$idk, $slug, ...$languageParams]) !== null;
    }

    /**
     * May an item of the collection have the address in its language (3.7, N37-8): no other item of the language has it,
     * and no category of the collection has it in any language (with slugs per language, 3.9: in the item's language) –
     * the item would take over the category page. The item's
     * own stored address passes the category check (old data with a clash: the item keeps it and wins, see the class
     * comment). The one check of every place that sets an item address: the item form, save_collection_item,
     * save_collection_items and the CSV/JSON import, a copy, a restore from the trash or of a version, the WordPress
     * import and testimonials.
     */
    public static function itemSlugFree(Db $db, int $idk, string $language, string $slug, int $idp = 0, string $stored = ''): bool
    {
        return ($slug === $stored || !self::slugIsCategory($db, $idk, $slug, $language))
            && $db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ?', [$idk, $language, $slug, $idp]) === null;
    }

    /** A free item address made from $slug (stul, stul-2…) by itemSlugFree. */
    public static function freeItemSlug(Db $db, int $idk, string $language, string $slug, int $idp = 0, string $stored = '', int $max = 160): string
    {
        return Slug::makeUnique($slug, fn (string $a): bool => !self::itemSlugFree($db, $idk, $language, $a, $idp, $stored), $max);
    }

    /**
     * An item back from the trash whose address a category got meanwhile (an import of old data) comes back under a free
     * one, like a page (Pages::freeRestoredSlug). Returns the new address, null = unchanged.
     */
    public static function freeRestoredItemSlug(Db $db, int $idp): ?string
    {
        $item = $db->one('SELECT idk, jazyk, seo_link FROM {kolekce_polozky} WHERE idp = ?', [$idp]);
        if ($item === null || !self::slugIsCategory($db, (int) $item['idk'], (string) $item['seo_link'], (string) $item['jazyk'])) {
            return null;
        }
        $free = self::freeItemSlug($db, (int) $item['idk'], (string) $item['jazyk'], $item['seo_link'] . '-2', $idp);
        $db->update('kolekce_polozky', ['seo_link' => $free], ['idp' => $idp]);

        return $free;
    }

    /** The message an item save gets for an address a category has. */
    public static function itemSlugRefusal(string $slug): string
    {
        return t('The address “%s” belongs to a category of this collection – give the item another address.', $slug);
    }

    /**
     * Saves a category's shared settings and its texts in one language; returns the id. Refused (the message in
     * \InvalidArgumentException): a missing name, an address that is not valid, reserved, another category's in the
     * language, or an item's of the collection; a parent that is not a top-level category of the collection, and making a
     * category with subcategories a subcategory (only two levels).
     *
     * @param array{name?: mixed, slug?: mixed, description?: mixed, seo_title?: mixed, seo_description?: mixed, image?: mixed, sort_order?: mixed, parent_id?: mixed, visible?: mixed} $input
     *        keys left out keep the stored value (a new category: the defaults)
     */
    public static function save(Db $db, int $idk, ?int $id, string $language, array $input): int
    {
        $previous = $id !== null ? self::byId($db, $id) : null;
        if ($id !== null && ($previous === null || $previous['idk'] !== $idk)) {
            throw new \InvalidArgumentException(t('The category does not exist in this collection.'));
        }
        $text = fn (string $key, int $max): ?string => array_key_exists($key, $input) ? mb_substr(trim(strip_tags(is_scalar($input[$key]) ? (string) $input[$key] : '')), 0, $max) : null;
        $stored = $previous['texts'][$language] ?? null;
        $name = $text('name', 200) ?? $stored['name'] ?? '';
        if ($name === '') {
            throw new \InvalidArgumentException(t('The category needs a name.'));
        }
        $given = $text('slug', 160);
        $slug = $given !== null && $given !== '' ? slugify($given, 160) : ($stored['slug'] ?? slugify($name, 160));
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new \InvalidArgumentException(t('The address “%s” cannot be used for a category.', $slug));
        }
        if ($db->value('SELECT 1 FROM {collection_category_texts} WHERE idk = ? AND language = ? AND slug = ? AND category_id <> ?', [$idk, $language, $slug, (int) $id]) !== null) {
            throw new \InvalidArgumentException(t('The address “%s” is already used by another category of this collection.', $slug));
        }
        [$sameLanguage, $languageParams] = Slug::scope($db, $language); // 3.9: only an item of the category's language with slugs per language
        if ($db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ?' . $sameLanguage . ' LIMIT 1', [$idk, $slug, ...$languageParams]) !== null) {
            throw new \InvalidArgumentException(t('The address “%s” is already used by an item of this collection – a category page and an item page cannot share it.', $slug));
        }
        $parent = array_key_exists('parent_id', $input) ? (int) (is_scalar($input['parent_id']) ? $input['parent_id'] : 0) : ($previous['parent_id'] ?? 0);
        if ($parent > 0) {
            $parentRow = $db->one('SELECT idk, parent_id FROM {collection_categories} WHERE id = ?', [$parent]);
            if ($parentRow === null || (int) $parentRow['idk'] !== $idk || $parentRow['parent_id'] !== null || $parent === $id) {
                throw new \InvalidArgumentException(t('A subcategory belongs under a top-level category of the same collection (two levels).'));
            }
            if ($id !== null && $db->value('SELECT 1 FROM {collection_categories} WHERE parent_id = ? LIMIT 1', [$id]) !== null) {
                throw new \InvalidArgumentException(t('The category has subcategories, so it cannot become a subcategory itself (two levels).'));
            }
        }
        $image = array_key_exists('image', $input) ? trim(is_scalar($input['image']) ? (string) $input['image'] : '') : ($previous['image'] ?? '');
        if ($image !== '' && (preg_match(Collections::MEDIA_PATTERN, $image) !== 1 || str_contains($image, '..'))) {
            throw new \InvalidArgumentException(t('The image must be a file from Media or an https address.'));
        }
        $shared = [
            'parent_id' => $parent > 0 ? $parent : null, 'image' => mb_substr($image, 0, 255),
            'sort_order' => array_key_exists('sort_order', $input) ? max(-9999, min(9999, (int) (is_scalar($input['sort_order']) ? $input['sort_order'] : 100))) : ($previous['sort_order'] ?? 100),
            'visible' => array_key_exists('visible', $input) ? (filter_var($input['visible'], FILTER_VALIDATE_BOOL) ? 1 : 0) : (int) ($previous['visible'] ?? true),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        try {
            $description = array_key_exists('description', $input)
                ? \Kaleta\Core\HtmlLimits::guard(fn (): string => WpContent::safeHtml(mb_substr(is_scalar($input['description']) ? (string) $input['description'] : '', 0, 100000)))
                : ($stored['description'] ?? '');
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            throw new \InvalidArgumentException(t('The description: %s', \Kaleta\Core\HtmlLimits::message($e->violation)), 0, $e); // nothing saved
        }
        $texts = [
            'name' => $name, 'slug' => $slug,
            'description' => $description,
            'seo_title' => $text('seo_title', 200) ?? $stored['seo_title'] ?? '', 'seo_description' => $text('seo_description', 300) ?? $stored['seo_description'] ?? '',
        ];

        return $db->transaction(function () use ($db, $idk, $id, $language, $shared, $texts, $stored): int {
            if ($id === null) {
                $id = $db->insert('collection_categories', $shared + ['idk' => $idk]);
            } else {
                $db->update('collection_categories', $shared, ['id' => $id]);
            }
            if ($stored === null) {
                $db->insert('collection_category_texts', $texts + ['category_id' => $id, 'language' => $language, 'idk' => $idk]);
            } else {
                $db->update('collection_category_texts', $texts, ['category_id' => $id, 'language' => $language]);
            }

            return $id;
        });
    }

    /**
     * Deletes a category (all its language versions and item assignments; the items stay). Refused while it has
     * subcategories – they would go with it.
     */
    public static function delete(Db $db, int $idk, int $id): void
    {
        if ($db->value('SELECT 1 FROM {collection_categories} WHERE id = ? AND idk = ?', [$id, $idk]) === null) {
            throw new \InvalidArgumentException(t('The category does not exist in this collection.'));
        }
        if ($db->value('SELECT 1 FROM {collection_categories} WHERE parent_id = ? LIMIT 1', [$id]) !== null) {
            throw new \InvalidArgumentException(t('The category has subcategories – delete them or move them under another category first.'));
        }
        $db->delete('collection_categories', ['id' => $id]);
    }

    /** Removes one language version of a category (the category stays in the other languages; the last one deletes it). */
    public static function deleteLanguage(Db $db, int $idk, int $id, string $language): void
    {
        $category = self::byId($db, $id);
        if ($category === null || $category['idk'] !== $idk || !isset($category['texts'][$language])) {
            throw new \InvalidArgumentException(t('The category does not exist in this collection.'));
        }
        if (count($category['texts']) === 1) {
            self::delete($db, $idk, $id);

            return;
        }
        $db->delete('collection_category_texts', ['category_id' => $id, 'language' => $language]);
    }

    /** How many visible items of a language are in the categories. @param list<int> $ids */
    public static function countItems(Db $db, array $ids, string $language): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $db->value('SELECT COUNT(DISTINCT p.idp) FROM {kolekce_polozky} p JOIN {collection_item_categories} ic ON ic.idp = p.idp
            WHERE ic.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ?', [...$ids, $language]);
    }

    /**
     * Values of a category for the {{tags}} of the category template and of a Collection list of categories: {{nazev}},
     * {{url}}, {{popis}} (formatted), {{obrazek}}, {{seo}}, {{pocet}} (visible items, subcategories included),
     * {{nadrazena}} and {{nadrazena_url}} (the parent of a subcategory).
     *
     * @param callable(string): string $url url within the site
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(Db $db, array $collection, array $category, string $language, callable $url): array
    {
        $parentPath = $category['parent_slug'] !== '' ? $collection['seo_link'] . '/' . $category['parent_slug'] : '';

        return [
            'nazev' => [$category['name'], 'text'],
            'url' => [$url(self::path((string) $collection['seo_link'], $category)), 'odkaz'],
            'popis' => [$category['description'], 'html'],
            'obrazek' => [$category['image'], 'obrazek'],
            'seo' => [$category['slug'], 'text'],
            'pocet' => [(string) self::countItems($db, self::withChildren($db, $category['id']), $language), 'text'],
            'nadrazena' => [(string) ($category['parent_name'] ?? ''), 'text'],
            'nadrazena_url' => [$parentPath !== '' ? $url($parentPath) : '', 'odkaz'],
        ];
    }

    /** Sample values for the editor of the category template when the collection has no category yet. @return array<string, array{0: string, 1: string}> */
    public static function sample(): array
    {
        return ['nazev' => ['[' . t('Category name') . ']', 'text'], 'url' => ['#', 'odkaz'], 'popis' => ['<p>[' . t('Category description') . ']</p>', 'html'], 'obrazek' => ['', 'obrazek'],
            'seo' => ['', 'text'], 'pocet' => ['0', 'text'], 'nadrazena' => ['', 'text'], 'nadrazena_url' => ['', 'odkaz']];
    }

    /* ---------- the category template (builder) ---------- */

    /**
     * The collection with the category template of a language in place of the item template (stavba, stavba_koncept,
     * zmeneno), marked so Collections::writeTemplate and templateKey address the category template. A language without
     * its own row has both null.
     *
     * @param array<string, mixed> $collection @return array<string, mixed>
     */
    public static function template(Db $db, array $collection, string $language): array
    {
        $r = $db->one('SELECT stavba, stavba_koncept, zmeneno FROM {collection_category_templates} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]);

        return ['stavba' => $r['stavba'] ?? null, 'stavba_koncept' => $r['stavba_koncept'] ?? null, 'zmeneno' => $r['zmeneno'] ?? null, 'sablona_jazyk' => $language, 'sablona_druh' => 'kategorie'] + $collection;
    }

    /** Writes the columns of the category template (Collections::writeTemplate for a template from template()). @param array<string, mixed> $columns */
    public static function writeTemplate(Db $db, array $collection, array $columns): void
    {
        $language = (string) ($collection['sablona_jazyk'] ?? '');
        if ($db->value('SELECT 1 FROM {collection_category_templates} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]) !== null) {
            $db->update('collection_category_templates', $columns, ['idk' => $collection['idk'], 'jazyk' => $language]);
        } else {
            $db->insert('collection_category_templates', $columns + ['idk' => $collection['idk'], 'jazyk' => $language]);
        }
    }

    /** The build a category page shows: the language's published template (the draft in the editor), the default language's, then the built-in one. */
    public static function build(Db $db, array $collection, string $language, bool $draft): array
    {
        $own = self::template($db, $collection, $language);
        $default = $language === '' ? $own : self::template($db, $collection, '');

        return Build::fromJson($draft ? ($own['stavba_koncept'] ?? $own['stavba']) : $own['stavba'])
            ?? Build::fromJson($draft ? ($default['stavba_koncept'] ?? $default['stavba']) : $default['stavba'])
            ?? self::defaultTemplate($collection);
    }

    /** The draft the builder opens the category template with until anyone saves it (another language: a copy of the default one). */
    public static function initialTemplateDraft(Db $db, array $collection): string
    {
        if (($collection['sablona_jazyk'] ?? '') !== '') {
            $default = self::template($db, $collection, '');
            if (($default['stavba_koncept'] ?? $default['stavba']) !== null) {
                return (string) ($default['stavba_koncept'] ?? $default['stavba']);
            }
        }

        return Build::toJson(self::defaultTemplate($collection));
    }

    /**
     * The category page until the administrator edits it: breadcrumbs, the name, the description, the subcategories as
     * cards and the items of the category with pages.
     */
    public static function defaultTemplate(array $collection): array
    {
        $n = Build::fresh(...);
        $card = fn (string $titleTag, string $image): array => ['tridy' => ['karta']] + $n('kontejner', [], [
            ...($image !== '' ? [$n('obrazek', ['src' => '{{' . $image . '}}', 'alt' => '{{nazev}}'])] : []),
            ['znacka' => $titleTag] + $n('nadpis', ['text' => '{{nazev}}']),
            $n('tlacitko', ['text' => t('More information'), 'odkaz' => '{{url}}', 'varianta' => 'odkaz']),
        ]);
        // the item cards show the collection's first image field
        $itemImage = (string) (array_values(array_filter((array) $collection['pole'], fn (array $f): bool => $f['typ'] === 'obrazek'))[0]['klic'] ?? '');

        return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('sekce', [], [
            ['styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]] + $n('kontejner', [], [
                $n('drobecky'),
                ['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}']),
                $n('text', ['html' => '{{popis}}']),
                $n('kolekce', ['kolekce' => (string) $collection['seo_link'], 'zdroj' => 'kategorie', 'kategorie' => '*', 'pocet' => 50], [$card('h2', 'obrazek')]),
                $n('kolekce', ['kolekce' => (string) $collection['seo_link'], 'kategorie' => '*', 'pocet' => 12, 'strankovani' => true, 'prazdne' => t('There is nothing in this category yet.')], [$card('h3', $itemImage)]),
            ]),
        ])]])[0];
    }
}
