<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;

/**
 * Ready-made collections (2.11): a team, events, jobs, documents, branches… created in one click – with their fields,
 * item pages, structured data and the redirect of hidden items – and kept current by the features that know them.
 *
 * Each preset is a data file system/presets/<key>.php returning:
 *
 *  - name, description – English (translated with t() when shown or created); button – optional text of the create button
 *  - order – position in the gallery; detail – items have pages; redirect_hidden – hidden items lead to the list page
 *  - fields – list of [key, label, type] or [key, label, type, options]: options 'preset' => another preset's key for an
 *    item link (the field links the first collection created from it; without one the field is left out)
 *  - schema – the schema.org setting {typ, pole: property => field key, mena} (CollectionSchema)
 *  - claude – how to use it: the list element, the item template, what keeps it current (list_collection_presets)
 *  - list – options of the Collection list on the page created with it (sorting, period…); card – field keys (or values
 *    a feature computes, like an event's when) shown on a card under the name (the first image field is its picture)
 *  - calendar – role => field key for Core\Calendar (start, end, place, repeat, capacity…): the collection is an events calendar
 *  - template – optional fn (array $fields): array returning the children of the item page (Build::fresh elements); the
 *    collection then starts with that item template instead of the one assembled from the fields
 *  - card_extra, page_extra – optional fn (): array of elements added to each card of the list page and after the list
 *  - extra_pages – optional further hidden list pages, each {suffix, name, list}: at /<address>-<suffix>, named with
 *    sprintf(name, the collection name), with its own Collection list options (a notice board's archive)
 *
 * Creating one also creates a hidden page at /<address> listing the items – the administrator adds a text and publishes it.
 * The collection keeps its preset (ka_kolekce.preset), so a feature finds its field by key – field() checks it still exists
 * with the expected type, because the administrator may change the fields afterwards.
 */
final class Presets
{
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,29}$/D';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $all = null;

    /** @return array<string, array<string, mixed>> key => definition, by order */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $all = [];
        foreach (glob(KALETA_SYSTEM . '/presets/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $definition = preg_match(self::KEY_PATTERN, $key) === 1 ? require $file : null;
            if (is_array($definition) && is_string($definition['name'] ?? null) && is_array($definition['fields'] ?? null)) {
                $all[$key] = $definition + ['description' => '', 'order' => 100, 'detail' => true, 'redirect_hidden' => false, 'schema' => null, 'claude' => '', 'list' => [], 'card' => [], 'template' => null, 'card_extra' => null, 'page_extra' => null, 'extra_pages' => []];
            }
        }
        uasort($all, fn (array $a, array $b): int => [$a['order'], $a['name']] <=> [$b['order'], $b['name']]);

        return self::$all = $all;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, mixed>|null the preset a collection row was created from */
    public static function of(array $collection): ?array
    {
        return self::get((string) ($collection['preset'] ?? ''));
    }

    /**
     * The key of a preset field in a collection, when the collection was created from the preset and the field is still
     * there with one of the types; null otherwise (a feature then does nothing).
     *
     * @param list<string> $types
     */
    public static function field(array $collection, string $preset, string $key, array $types): ?string
    {
        if (($collection['preset'] ?? '') !== $preset) {
            return null;
        }
        foreach ((array) ($collection['pole'] ?? []) as $f) {
            if (($f['klic'] ?? '') === $key && in_array($f['typ'] ?? '', $types, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Field definitions of a preset for Collections::sanitizeFields: labels translated, an item link pointing to the
     * collection created from its preset (without one the field is left out).
     *
     * @param array<string, mixed> $preset
     * @return list<array{klic: string, popisek: string, typ: string, kolekce?: string}>
     */
    public static function fields(\Kaleta\Core\Db $db, array $preset): array
    {
        $out = [];
        foreach ($preset['fields'] as $f) {
            [$key, $label, $type] = $f;
            $field = ['klic' => $key, 'popisek' => t($label), 'typ' => $type] + ($type === 'volba' ? ['moznosti' => (array) ($f[3]['options'] ?? [])] : []);
            if ($type === 'polozka') {
                $target = (string) ($db->value('SELECT seo_link FROM {kolekce} WHERE preset = ? ORDER BY idk LIMIT 1', [(string) ($f[3]['preset'] ?? '')]) ?? '');
                if ($target === '') {
                    continue; // e.g. a team without branches has no branch field
                }
                $field['kolekce'] = $target;
            }
            $out[] = $field;
        }

        return Collections::sanitizeFields($out);
    }

    /**
     * Creates a collection from a preset; returns its id, or null for an unknown preset. The address comes from the name
     * (made unique); the hidden items of a preset with redirect_hidden lead to its list page.
     */
    public static function create(App $app, string $key, string $name = ''): ?int
    {
        return self::createWithPage($app, $key, $name)[0] ?? null;
    }

    /**
     * Creates a collection from a preset and a hidden page listing its items (when no page has its address yet), plus the
     * preset's extra_pages (a notice board's archive) the same way.
     *
     * Everything a preset writes – the name, field labels, the item template and the list pages – is site content, so it is
     * written in the site's default language from the site dictionary, whoever creates it: an MCP request (English interface),
     * an administrator using another language, a visitor on a language version (a testimonial).
     *
     * @return array{0: int, 1: ?int, 2: list<array{id: int, path: string, name: string}>}|null [collection id, page id or null, extra pages]
     */
    public static function createWithPage(App $app, string $key, string $name = '', bool $withPage = true): ?array
    {
        return \Kaleta\Core\Language::runWith(\Kaleta\Core\Language::defaults($app->settings()), fn (): ?array => self::build($app, $key, $name, $withPage));
    }

    /**
     * createWithPage() in the site language.
     *
     * @return array{0: int, 1: ?int, 2: list<array{id: int, path: string, name: string}>}|null
     */
    private static function build(App $app, string $key, string $name, bool $withPage): ?array
    {
        $preset = self::get($key);
        if ($preset === null) {
            return null;
        }
        $db = $app->db();
        $name = mb_substr(trim($name) !== '' ? trim($name) : t($preset['name']), 0, 100);
        $seo = $base = slugify($name, 100);
        for ($i = 2; $db->value('SELECT 1 FROM {kolekce} WHERE seo_link = ?', [$seo]) !== null || in_array($seo, \Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true)
            || isset(\Kaleta\Core\Language::AVAILABLE[$seo]); $i++) {
            $seo = $base . '-' . $i;
        }
        $fields = self::fields($db, $preset);
        $schema = is_array($preset['schema']) ? CollectionSchema::sanitize($preset['schema'], $fields) : null;
        $id = $db->insert('kolekce', ['nazev' => $name, 'seo_link' => $seo, 'detail' => $preset['detail'] ? 1 : 0, 'preset' => $key,
            'hidden_redirect' => $preset['redirect_hidden'] ? '/' . $seo : '', 'pole' => (string) json_encode($fields, JSON_UNESCAPED_UNICODE),
            'zmeneno' => date('Y-m-d H:i:s'), 'schema_org' => $schema === null ? null : (string) json_encode($schema, JSON_UNESCAPED_UNICODE),
            'stavba' => is_callable($preset['template']) && $preset['detail'] ? Build::toJson(self::itemTemplate($preset, $fields)) : null]);
        \Kaleta\Admin\ChangeLog::write($app, 'collections', 'preset', $key . ': ' . $seo);
        $pageId = null;
        $extra = [];
        if ($withPage) {
            Library::createClasses($db, ['karta']);
            $pageId = self::createListPage($app, $preset, $key, $name, $seo, $seo, $fields);
            foreach ((array) $preset['extra_pages'] as $page) {
                $pageSeo = $seo . '-' . slugify((string) ($page['suffix'] ?? ''), 30);
                $pageName = mb_substr(t((string) ($page['name'] ?? '%s'), $name), 0, 200);
                $extraId = self::createListPage($app, ['list' => (array) ($page['list'] ?? [])] + $preset, $key, $pageName, $pageSeo, $seo, $fields);
                if ($extraId !== null) {
                    $extra[] = ['id' => $extraId, 'path' => '/' . $pageSeo, 'name' => $pageName];
                }
            }
        }

        return [$id, $pageId, $extra];
    }

    /**
     * A hidden page listing the collection with the preset's list options – when no page has the address yet; returns its
     * id. Hidden until the administrator adds a text and publishes it.
     *
     * @param array<string, mixed> $preset
     * @param list<array{klic: string, popisek: string, typ: string}> $fields
     */
    private static function createListPage(App $app, array $preset, string $key, string $name, string $pageSeo, string $collectionSeo, array $fields): ?int
    {
        $db = $app->db();
        if (\Kaleta\Core\Slug::taken($db, 'stranky', $pageSeo, '') || \Kaleta\Admin\Modules\Pages::slugReserved($pageSeo, $db)) {
            return null;
        }
        $build = self::listPage($preset, $name, $collectionSeo, $fields);
        $pageId = $db->insert('stranky', ['titulek' => $name, 'seo_link' => $pageSeo, 'stavba' => Build::toJson($build), 'text' => Build::asText($build),
            'zobrazit' => 0, 'v_menu' => 0, 'poradi' => 50, 'zmeneno' => date('Y-m-d H:i:s')]);
        \Kaleta\Admin\ChangeLog::write($app, 'pages', 'create', $name . ' (' . $key . ')');

        return $pageId;
    }

    /**
     * The item template a preset brings: its children in a narrow section, sanitized like any build.
     *
     * @param array<string, mixed> $preset
     * @param list<array{klic: string, popisek: string, typ: string}> $fields
     * @return array<string, mixed>
     */
    public static function itemTemplate(array $preset, array $fields): array
    {
        $n = Build::fresh(...);
        $children = ($preset['template'])($fields);

        return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('sekce', ['sirka' => 'uzka'], [
            ['styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]] + $n('kontejner', [], is_array($children) ? array_values($children) : []),
        ])]])[0];
    }

    /**
     * The build of the list page of a new preset collection: the name as the heading and a Collection list with the
     * preset's options; a card shows the first image, the name, the card fields and a link to the item page.
     *
     * @param array<string, mixed> $preset
     * @param list<array{klic: string, popisek: string, typ: string}> $fields
     * @return array<string, mixed>
     */
    public static function listPage(array $preset, string $name, string $seo, array $fields): array
    {
        $n = Build::fresh(...);
        $types = array_column($fields, 'typ', 'klic');
        $image = array_search('obrazek', $types, true);
        $card = [];
        if (is_string($image)) {
            $card[] = $n('obrazek', ['src' => '{{' . $image . '}}', 'alt' => '{{nazev}}']);
        }
        $card[] = ['znacka' => 'h3'] + $n('nadpis', ['text' => '{{nazev}}']);
        foreach ((array) $preset['card'] as $key) {
            // a field, or a value a feature computes for the preset (an event's {{when}}); an empty one leaves no paragraph
            if (is_string($key) && preg_match(Collections::KEY_PATTERN, $key) === 1) {
                $card[] = $n('text', ['html' => '<p>{{' . $key . '}}</p>']);
            }
        }
        if ($preset['detail']) {
            $card[] = $n('tlacitko', ['text' => t('More information'), 'odkaz' => '{{url}}', 'varianta' => 'odkaz']);
        }
        if (is_callable($preset['card_extra'])) {
            array_push($card, ...array_values((array) ($preset['card_extra'])()));
        }
        $list = $n('kolekce', ['kolekce' => $seo, 'pocet' => 24] + (array) $preset['list'], [['tridy' => ['karta']] + $n('kontejner', [], $card)]);
        $after = is_callable($preset['page_extra']) ? array_values((array) ($preset['page_extra'])()) : [];

        return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('sekce', [], [
            ['styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'l']]] + $n('kontejner', [], [['znacka' => 'h1'] + $n('nadpis', ['text' => $name]), $list, ...$after]),
        ])]])[0];
    }

    /**
     * The presets for Claude and the admin gallery: key, name, description, fields and how to use it (translated).
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::all() as $key => $p) {
            $out[] = ['preset' => $key, 'name' => t($p['name']), 'description' => t((string) $p['description']), 'item_pages' => (bool) $p['detail'],
                'fields' => array_map(fn (array $f): array => ['key' => $f[0], 'label' => t($f[1]), 'type' => $f[2]] + (isset($f[3]['preset']) ? ['links_to_preset' => $f[3]['preset']] : []), $p['fields']),
                'structured_data' => is_array($p['schema']) ? (string) $p['schema']['typ'] : '', 'how_to_use' => (string) $p['claude']];
        }

        return $out;
    }
}
