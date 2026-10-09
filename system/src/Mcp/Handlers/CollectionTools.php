<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Notices;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\CollectionCategories;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: collections (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait CollectionTools
{
    /** list_collections (seznam_kolekci) */
    private function toolListCollections(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return array_map(fn (array $k): array => ['kolekce' => $k['seo_link'], 'nazev' => $k['nazev'], 'detail' => (bool) $k['detail'], 'presmerovat_skryte' => (string) ($k['hidden_redirect'] ?? ''), 'pole' => $k['pole'],
            'preset' => (string) ($k['preset'] ?? ''),
            'polozek' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL', [$k['idk']])]
            + (($sd = \Kaleta\Builder\CollectionSchema::of($k)) !== null ? ['structured_data' => ['type' => $sd['typ'], 'fields' => $sd['pole'], 'currency' => $sd['mena']]] : []), Collections::all($db));
    }

    /** list_collection_presets (2.11) */
    private function toolListCollectionPresets(string $name, array $a): mixed
    {
        $existing = $this->app->db()->pairs("SELECT preset, seo_link FROM {kolekce} WHERE preset <> '' ORDER BY idk DESC");

        return ['presets' => array_map(fn (array $p): array => $p + ['existing_collection' => $existing[$p['preset']] ?? ''], \Kaleta\Builder\Presets::describe()),
            'next' => 'create_collection {"preset":"<key>","name":"…"} creates one; then add items with save_collection_item and put a Collection list on a page.'];
    }

    /** create_collection (vytvor_kolekci) */
    private function toolCreateCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        if (($a['preset'] ?? '') !== '') {
            // a ready-made collection (2.10 people, 2.11 Builder\Presets)
            [$id, $pageId, $extraPages] = \Kaleta\Builder\Presets::createWithPage($this->app, (string) $a['preset'], (string) ($a['nazev'] ?? '')) ?? [null, null, []];
            if ($id === null) {
                throw new \InvalidArgumentException('Unknown preset – use one of: ' . implode(', ', array_keys(\Kaleta\Builder\Presets::all())) . ' (list_collection_presets).');
            }
            $created = (array) Collections::byId($db, $id);
            $preset = (array) \Kaleta\Builder\Presets::get((string) $a['preset']);

            return ['kolekce' => $created['seo_link'], 'nazev' => $created['nazev'], 'pole' => $created['pole'], 'presmerovat_skryte' => $created['hidden_redirect'], 'preset' => (string) $a['preset'],
                'list_page' => $pageId !== null ? ['id' => $pageId, 'path' => '/' . $created['seo_link'], 'visible' => false, 'note' => 'A hidden page listing the items; add an intro, then publish it with update_page visible=true when the user wants.'] : null]
                // further hidden list pages of the preset (2.11): a notice board's archive
                + ($extraPages !== [] ? ['more_pages' => array_map(fn (array $p): array => $p + ['visible' => false], $extraPages)] : [])
                + ['how_to_use' => (string) ($preset['claude'] ?? '')];
        }
        $collectionName = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
        if ($collectionName === '') {
            throw new \InvalidArgumentException('Chybí název kolekce.');
        }
        $seo = $this->availableCollectionSlug((string) ($a['adresa'] ?? '') !== '' ? (string) $a['adresa'] : $collectionName, 0);
        $field = Collections::sanitizeFields($a['pole'] ?? []);
        $redirect = Collections::cleanRedirect((string) ($a['presmerovat_skryte'] ?? ''));
        if ($redirect === null) {
            throw new \InvalidArgumentException('redirect_hidden_to must be an address on the site (/team) or https://…');
        }
        $db->insert('kolekce', ['nazev' => $collectionName, 'seo_link' => $seo, 'detail' => empty($a['detail']) ? 0 : 1, 'hidden_redirect' => $redirect, 'pole' => (string) json_encode($field, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s'),
            'schema_org' => self::collectionSchema($a['schema_org'] ?? null, $field)]);

        return ['kolekce' => $seo, 'pole' => $field];
    }

    /** update_collection (uprav_kolekci) */
    private function toolUpdateCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $collection = $this->collection((string) ($a['kolekce'] ?? ''));
        $changes = ['zmeneno' => date('Y-m-d H:i:s')];
        if (isset($a['nazev']) && trim((string) $a['nazev']) !== '') {
            $changes['nazev'] = mb_substr(trim((string) $a['nazev']), 0, 100);
        }
        if (isset($a['adresa']) && trim((string) $a['adresa']) !== '') {
            $changes['seo_link'] = $this->availableCollectionSlug((string) $a['adresa'], (int) $collection['idk']);
        }
        if (array_key_exists('detail', $a)) {
            $changes['detail'] = empty($a['detail']) ? 0 : 1;
        }
        if (array_key_exists('presmerovat_skryte', $a)) {
            $changes['hidden_redirect'] = Collections::cleanRedirect((string) $a['presmerovat_skryte'])
                ?? throw new \InvalidArgumentException('redirect_hidden_to must be an address on the site (/team) or https://…');
        }
        if (is_array($a['pole'] ?? null)) {
            $changes['pole'] = (string) json_encode(Collections::sanitizeFields($a['pole']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('schema_org', $a)) {
            $changes['schema_org'] = self::collectionSchema($a['schema_org'], json_decode($changes['pole'] ?? '', true) ?: $collection['pole']);
        }
        $db->update('kolekce', $changes, ['idk' => $collection['idk']]);
        \Kaleta\Front\Cache::clear();
        $newVersion = (array) $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$collection['idk']]);

        return ['kolekce' => $newVersion['seo_link'], 'nazev' => $newVersion['nazev'], 'detail' => (bool) $newVersion['detail'], 'presmerovat_skryte' => (string) $newVersion['hidden_redirect'], 'pole' => json_decode((string) $newVersion['pole'], true) ?: []];
    }

    /** delete_collection */
    private function toolDeleteCollection(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };
        $collection = function () use ($a, $db): array {
            return \Kaleta\Builder\Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        };

        $need($auth->isAdmin(), 'Collections can be deleted only by an administrator.');
        $k = $collection();
        $notices = Notices::count($db, $k); // an official notice board keeps its notices for good (2.11)
        $need($notices === 0, sprintf(Notices::REFUSAL_COLLECTION, $notices));
        $db->delete('kolekce', ['idk' => $k['idk']]); // items and templates go with it (foreign keys)
        \Kaleta\Front\Cache::clear();

        return ['deleted' => $k['seo_link']];
    }

    /** list_collection_items (seznam_polozek_kolekce) */
    private function toolListCollectionItems(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        $collection = $this->collection((string) ($a['kolekce'] ?? ''));

        $whereParts = ['idk = ?', 'smazano IS NULL']; // the trash is in list_trash
        $args = [$collection['idk']];
        if (isset($a['jazyk']) && is_string($a['jazyk'])) {
            $whereParts[] = 'jazyk = ?';
            $args[] = $a['jazyk'];
        }
        if (!empty($a['jen_zobrazene']) || !$auth->hasModule('collections')) {
            $whereParts[] = 'zobrazit = 1'; // without the Collections section only published items
        }
        if (is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '') {
            $whereParts[] = '(nazev LIKE ? OR data LIKE ?)';
            $pattern = '%' . addcslashes(mb_substr(trim($a['hledat']), 0, 100), '%_\\') . '%';
            array_push($args, $pattern, $pattern);
        }
        // 3.7 (N37-9): without the Collections section only the categories on the site – a hidden one is neither listed on an
        // item nor accepted as a filter (the same answer as an unknown one, so the filter tells nothing about hidden ones)
        $allCategories = $auth->hasModule('collections');
        if (is_string($a['kategorie'] ?? null) && trim($a['kategorie']) !== '') {
            // 3.7: the items of a category and of its subcategories
            $category = CollectionCategories::idsFromSlugs($db, (int) $collection['idk'], [trim($a['kategorie'])], is_string($a['jazyk'] ?? null) ? Language::column($this->app->settings(), $a['jazyk']) : '')[0] ?? null;
            if ($category === null || (!$allCategories && !CollectionCategories::isPublicId($db, $category))) {
                throw new \InvalidArgumentException('The category is not in this collection. Use list_collection_categories.');
            }
            $ids = CollectionCategories::withChildren($db, $category, !$allCategories);
            $whereParts[] = 'idp IN (SELECT idp FROM {collection_item_categories} WHERE category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            $args = [...$args, ...$ids];
        }
        $rows = $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . implode(' AND ', $whereParts) . ' ORDER BY poradi, nazev LIMIT 5000', $args);
        $withCategories = CollectionCategories::has($db, (int) $collection['idk']);
        if (is_string($a['pole'] ?? null) && $a['pole'] !== '') {
            // exact match of a field value (JSON in the database – filtered here, without depending on the MySQL version)
            $rows = array_values(array_filter($rows, fn (array $r): bool => (string) ((json_decode((string) $r['data'], true) ?: [])[$a['pole']] ?? '') === (string) ($a['hodnota'] ?? '')));
        }
        $pageNumber = max(1, (int) ($a['strana'] ?? 1));
        // an events calendar (2.11): how many registered for the next occurrence and whether registration is open – counts only
        $calendar = \Kaleta\Core\Calendar::fields($collection) !== null && $auth->hasModule('enquiries');
        $registration = function (array $r) use ($calendar, $collection, $db): array {
            if (!$calendar) {
                return [];
            }
            $r['data'] = json_decode((string) $r['data'], true) ?: [];
            $v = \Kaleta\Core\Calendar::values($db, $collection, $r, fn (string $p): string => $p, date('Y-m-d H:i'));

            return ['registration' => ['state' => $v['_registration'][0], 'registered' => \Kaleta\Core\Calendar::registered($db, $collection, $r, (array) \Kaleta\Core\Calendar::fields($collection)),
                'places_left' => $v['places_left'][0] !== '' ? (int) $v['places_left'][0] : null]];
        };

        // a document library (2.11): how often each document was downloaded, and the stable address of its file
        $downloads = \Kaleta\Core\Documents::fileField($collection) !== null ? \Kaleta\Core\Documents::counts($db, (int) $collection['idk']) : null;
        $documentOutput = fn (array $r): array => $downloads === null ? [] : ['downloads' => ['last_30_days' => $downloads[(int) $r['idp']][0] ?? 0, 'total' => $downloads[(int) $r['idp']][1] ?? 0]]
            + ($collection['detail'] ? ['latest_url' => $this->app->request->origin() . $this->app->url(($r['jazyk'] !== '' ? $r['jazyk'] . '/' : '') . $collection['seo_link'] . '/' . $r['seo_link'] . '/latest')] : []);

        return ['celkem' => count($rows), 'strana' => $pageNumber, 'stran' => max(1, (int) ceil(count($rows) / 50)), 'polozky' => array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'nazev' => $r['nazev'], 'seo_link' => $r['seo_link'], 'poradi' => (int) $r['poradi'], 'zobrazit' => (bool) $r['zobrazit'],
            'jazyk' => $r['jazyk'], 'data' => json_decode((string) $r['data'], true) ?: new \stdClass()]
            + array_filter(['seo_titulek' => $r['seo_titulek'], 'popis' => $r['popis'], 'obrazek' => $r['obrazek'], 'noindex' => (bool) $r['noindex'], 'zverejnit_od' => $r['zverejnit_od']]) + self::validityOutput($r) + $registration($r) + $documentOutput($r)
            + ($withCategories ? ['kategorie' => CollectionCategories::slugsOfItem($db, (int) $r['idp'], (string) $r['jazyk'], !$allCategories)] : []), array_slice($rows, ($pageNumber - 1) * 50, 50))];
    }

    /** save_collection_item (uloz_polozku_kolekce) */
    private function toolSaveCollectionItem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!$auth->hasModule('collections')) {
            throw new \DomainException('Kolekce smí upravovat editor nebo správce.');
        }
        $collection = $this->collection((string) ($a['kolekce'] ?? ''));
        $previous = isset($a['id']) ? $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [(int) $a['id'], $collection['idk']]) : null;
        if (isset($a['id']) && $previous === null) {
            throw new \InvalidArgumentException('Položka v kolekci není. Použij seznam_polozek_kolekce.');
        }
        if ($previous !== null && $previous['smazano'] !== null) {
            // saving would put it back on the site while it still waits in the trash to be deleted
            throw new \InvalidArgumentException('The item is in the trash. Bring it back with restore_from_trash first.');
        }
        // a drafts-only connection (3.2) creates hidden items and changes hidden ones – what visitors see stays a person's call
        $draftsOnly = $auth->draftsOnly();
        if ($draftsOnly) {
            $proposeInstead = ' Write the change you propose (the item and its values) into the note of the request or the summary of the run for a person to apply – or the user connects Claude with full access.';
            if ($previous !== null && ((int) $previous['zobrazit'] === 1 || $previous['zverejnit_od'] !== null)) {
                throw new \DomainException('This connection can only save drafts, and this item is on the site (or scheduled to be published), so it cannot be changed here.' . $proposeInstead);
            }
            if ($previous !== null && !empty($a['zobrazit'])) {
                throw new \DomainException('This connection can only save drafts: it cannot make an item visible.' . $proposeInstead);
            }
            if (is_string($a['zverejnit_od'] ?? null) && trim($a['zverejnit_od']) !== '') {
                throw new \DomainException('This connection can only save drafts: it cannot schedule an item to be published.' . $proposeInstead);
            }
            unset($a['zobrazit']); // a new item stays hidden whatever visible says
        }
        // on update the name is optional – the current one stays
        $itemName = mb_substr(trim((string) ($a['nazev'] ?? $previous['nazev'] ?? '')), 0, 200);
        if ($itemName === '') {
            throw new \InvalidArgumentException('Položka musí mít název.');
        }
        if (isset($a['data']) && !is_array($a['data'])) {
            throw new \InvalidArgumentException('Parametr data musí být objekt {"klic":"hodnota"} podle polí kolekce.');
        }
        $errors = [];
        $tooLarge = null;
        $data = Collections::sanitizeData($collection['pole'], (is_array($a['data'] ?? null) ? $a['data'] : []) + (json_decode((string) ($previous['data'] ?? '{}'), true) ?: []), $errors, $tooLarge);
        if ($tooLarge !== null) {
            throw new \Kaleta\Core\HtmlTooLarge($tooLarge); // a rich text field over a limit: nothing is saved, the error says which limit
        }
        $url = trim((string) ($a['adresa'] ?? ''));
        $seo = $url !== '' ? slugify($url, 150) : ($previous['seo_link'] ?? slugify($itemName, 150));
        if ($seo === '' || $seo === '_ukazka') {
            throw new \InvalidArgumentException('Neplatná adresa položky.');
        }
        // the slug is unique within a language: an item's translation should have the same one (the language
        // switcher and hreflang find it by the slug)
        $itemLanguage = array_key_exists('jazyk', $a) ? Language::column($siteSettings, (string) $a['jazyk']) : (string) ($previous['jazyk'] ?? '');
        // 3.7: never a category's address – a given one is refused, one made from the name gets a number
        $storedSlug = (string) ($previous['seo_link'] ?? '');
        if ($url !== '' && $seo !== $storedSlug && CollectionCategories::slugIsCategory($db, (int) $collection['idk'], $seo, $itemLanguage)) {
            throw new \InvalidArgumentException(Language::runWith('en', fn (): string => CollectionCategories::itemSlugRefusal($seo), 'admin-'));
        }
        $seo = CollectionCategories::freeItemSlug($db, (int) $collection['idk'], $itemLanguage, $seo, (int) ($previous['idp'] ?? 0), $storedSlug);
        // 3.7: the categories by their slugs (in the item's language, else the default one); checked before anything is saved
        $unknownCategories = [];
        $categoryIds = null;
        if (array_key_exists('kategorie', $a)) {
            $slugs = is_array($a['kategorie']) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $a['kategorie']) : explode(',', is_scalar($a['kategorie']) ? (string) $a['kategorie'] : '');
            $categoryIds = CollectionCategories::idsFromSlugs($db, (int) $collection['idk'], array_values(array_filter(array_map('trim', $slugs), fn (string $v): bool => $v !== '')), $itemLanguage, $unknownCategories);
        }
        $row = ['nazev' => $itemName, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]
            + (array_key_exists('jazyk', $a) ? ['jazyk' => $itemLanguage] : [])
            + (array_key_exists('poradi', $a) ? ['poradi' => max(-9999, min(9999, (int) $a['poradi']))] : [])
            + (array_key_exists('zobrazit', $a) ? ['zobrazit' => (int) (bool) $a['zobrazit']] : []);
        // SEO and scheduled publishing (1.9): only what was sent changes, the rest stays
        $pageKeys = ['seo_titulek', 'popis', 'obrazek', 'noindex', 'zverejnit_od'];
        if (array_intersect($pageKeys, array_keys($a)) !== []) {
            $fields = Collections::pageFields(array_intersect_key($a, array_flip($pageKeys)) + ($previous ?? []), (bool) ($row['zobrazit'] ?? $previous['zobrazit'] ?? 0));
            $row = array_intersect_key($fields, array_flip(array_intersect(['seo_titulek', 'popis', 'obrazek', 'noindex'], array_keys($a)))) + $row;
            if (array_key_exists('zverejnit_od', $a)) {
                $row['zverejnit_od'] = $fields['zverejnit_od'];
                $row['zobrazit'] = $fields['zobrazit'];
            }
        }
        $row += self::validityDates($a); // true until and review by (2.10)
        // a notice that is (or was) on the board cannot be hidden (2.11, Core\Notices)
        if (Notices::refusesHiding($collection, $data, (bool) ($row['zobrazit'] ?? $previous['zobrazit'] ?? 0))) {
            throw new \DomainException(Notices::REFUSAL_HIDE . ($draftsOnly ? ' This connection can only save hidden drafts: give a posting date in the future, or propose the notice in the note for a person.' : ' Pass visible true, or a posting date in the future.'));
        }
        if ($previous !== null) {
            Collections::saveVersion($this->app, $previous, $row);
            $db->update('kolekce_polozky', $row, ['idp' => $previous['idp']]);
            $idp = (int) $previous['idp'];
        } else {
            $row += ['idk' => $collection['idk'], 'datum' => date('Y-m-d H:i:s'), 'zobrazit' => 0];
            $idp = $db->insert('kolekce_polozky', $row);
        }
        if ($categoryIds !== null) {
            CollectionCategories::assign($db, $idp, (int) $collection['idk'], $categoryIds);
        }
        Notices::recordSave($this->app, $collection, $previous, $row, $idp); // the audit trail of a notice board (2.11)
        \Kaleta\Front\Cache::clear(); // item pages, lists, the sitemap and llms.txt show the change at once (as after a save in the admin)

        // a key the collection does not have (a typo, „nazev“ in data instead of the parameter) would otherwise be silently dropped
        $unknownKeys = array_values(array_diff(array_keys(is_array($a['data'] ?? null) ? $a['data'] : []), array_column($collection['pole'], 'klic')));

        return ['id' => $idp, 'kolekce' => $collection['seo_link'], 'neplatna_pole' => array_keys($errors)] + ($unknownKeys !== [] ? ['nezname_klice' => $unknownKeys] : []) + self::validityOutput($row)
            + ($categoryIds !== null ? ['kategorie' => CollectionCategories::slugsOfItem($db, $idp, $itemLanguage)] : [])
            + ($unknownCategories !== [] ? ['nezname_kategorie' => $unknownCategories, 'next' => 'Some category slugs are not categories of this collection (list_collection_categories); the item is in the others.'] : [])
            + ($draftsOnly ? ['zobrazit' => false, 'next' => 'Saved hidden: a person reviews the item and makes it visible (Collections, or Waiting for you on the dashboard).'] : []) + [
            'adresa' => $collection['detail'] ? $this->app->request->origin() . $this->app->url(($itemLanguage !== '' ? $itemLanguage . '/' : '') . $collection['seo_link'] . '/' . $seo) : null]
            // a document (2.11): the stable address of its current file, for links and buttons
            + ($collection['detail'] && \Kaleta\Core\Documents::fileField($collection) !== null ? ['latest_url' => $this->app->request->origin() . $this->app->url(($itemLanguage !== '' ? $itemLanguage . '/' : '') . $collection['seo_link'] . '/' . $seo . '/latest')] : []);
    }

    /**
     * save_collection_items (3.7): up to 200 items in one call – created, or changed by id or by slug – with the rules of
     * save_collection_item (Builder\ItemBatch), media by https address, a result per item; dry_run only says what would happen.
     */
    private function toolSaveCollectionItems(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->hasModule('collections')) {
            throw new \DomainException('Collection items are saved by an editor or an administrator.');
        }
        $collection = $this->collection((string) ($a['collection'] ?? ''));
        $items = is_array($a['items'] ?? null) ? array_values($a['items']) : [];
        if ($items === [] || count($items) > \Kaleta\Builder\ItemBatch::MAX_ITEMS) {
            throw new \InvalidArgumentException('Send 1–' . \Kaleta\Builder\ItemBatch::MAX_ITEMS . ' items: [{"name":"…","slug":"…","values":{"field key":"value"}}]; more in further calls.');
        }
        $rows = array_map(\Kaleta\Builder\ItemBatch::fromMcp(...), $items);
        $dryRun = !empty($a['dry_run']);
        $options = ['drafts_only' => $auth->draftsOnly()];
        $plan = $dryRun ? \Kaleta\Builder\ItemBatch::plan($this->app, $collection, $rows, $options) : \Kaleta\Builder\ItemBatch::save($this->app, $collection, $rows, $options);
        $counts = array_count_values(array_column($plan, 'status')) + ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'refused' => 0];
        $media = ['downloaded' => 0, 'failed' => 0, 'deferred' => 0, 'to_download' => 0];
        $results = [];
        foreach ($plan as $p) {
            $media['downloaded'] += count($p['media_downloaded'] ?? []);
            $media['failed'] += count($p['media_failed']);
            $media['deferred'] += count($p['media_deferred'] ?? []);
            $media['to_download'] += $dryRun && $p['status'] !== 'refused' ? count($p['media']) : 0;
            $results[] = array_filter(['index' => $p['index'], 'status' => $p['status'], 'reason' => $p['reason'], 'note' => $p['note'] !== [] ? sprintf(...$p['note']) : '', 'id' => $p['id'] ?: null, 'slug' => $p['slug'],
                'language' => $p['language'], 'visible' => $p['status'] !== 'refused' ? $p['visible'] : null,
                'invalid_fields' => $p['invalid'], 'unknown_keys' => $p['unknown'], 'unknown_item_keys' => $p['unknown_item'], 'media_downloaded' => $p['media_downloaded'] ?? [], 'media_failed' => $p['media_failed'],
                'media_deferred' => $p['media_deferred'] ?? []], fn (mixed $v): bool => $v !== '' && $v !== null && $v !== []);
        }
        $media = array_filter($media);

        return ['dry_run' => $dryRun, 'collection' => $collection['seo_link'], 'added' => $counts['added'], 'changed' => $counts['changed'], 'unchanged' => $counts['unchanged'], 'refused' => $counts['refused']]
            + ($media !== [] ? ['media' => $media] : []) + ['results' => $results]
            + (($media['deferred'] ?? 0) > 0 ? ['next' => 'Some media were not downloaded in this call (at most ' . \Kaleta\Builder\ItemBatch::MAX_DOWNLOADS . ' downloads per call): send those items again (by id or slug) with media only to download the rest.'] : [])
            + ($auth->draftsOnly() && !$dryRun && $counts['added'] + $counts['changed'] > 0 ? ['note' => 'Saved hidden: a person reviews the items and makes them visible (Collections, or Waiting for you on the dashboard).'] : []);
    }

    /** delete_collection_item */
    private function toolDeleteCollectionItem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };
        $collection = function () use ($a, $db): array {
            return \Kaleta\Builder\Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        };

        $need($auth->hasModule('collections'), 'Collection items can be deleted only by users with the Collections section.');
        $k = $collection();
        // the permanent archive of an official notice board (2.11, Core\Notices)
        $need(!Notices::isNotices($k), Notices::REFUSAL_DELETE . ' (save_collection_item with values {"taken_down": "YYYY-MM-DD"}; the notice then moves to the archive.)');
        if (!\Kaleta\Admin\Modules\Collections::trashItem($db, $id, (int) $k['idk'])) {
            throw new \InvalidArgumentException('The item is not in this collection (or it is already in the trash). Use list_collection_items.');
        }

        return ['trashed' => $id, 'restore' => 'restore_from_trash with type collection_item within 30 days'];
    }

    /** list_item_versions and restore_item_version */
    private function toolListItemVersions(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };
        $collection = function () use ($a, $db): array {
            return \Kaleta\Builder\Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        };

        $need($auth->hasModule('collections'), 'Collection items can be changed only by an editor or an administrator.');
        $k = $collection();
        $item = $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [$id, $k['idk']]) ?? throw new \InvalidArgumentException('The item is not in the collection. Use list_collection_items.');
        if ($name === 'list_item_versions') {
            return ['versions' => array_map(fn (array $v): array => ['id' => (int) $v['idr'], 'saved' => substr((string) $v['datum'], 0, 16), 'by' => (string) ($v['kdo'] ?? '')],
                \Kaleta\Builder\Publisher::listAll($db, ['cast' => 'polozka:' . $id]))];
        }
        $version = \Kaleta\Builder\Collections::loadVersion($db, $id, (int) ($a['version'] ?? 0)) ?? throw new \InvalidArgumentException('The version does not exist. Use list_item_versions.');
        $kept = null;
        if (isset($version['seo_link']) && !CollectionCategories::itemSlugFree($db, (int) $k['idk'], (string) $item['jazyk'], (string) $version['seo_link'], $id, (string) $item['seo_link'])) {
            $kept = 'The address ' . $version['seo_link'] . ' of that version is taken by another item or a category now, so the item keeps ' . $item['seo_link'] . '.';
            unset($version['seo_link']); // the address is taken by another item or a category meanwhile (3.7, N37-8)
        }
        \Kaleta\Builder\Collections::saveVersion($this->app, $item, $version);
        $db->update('kolekce_polozky', $version + ['zmeneno' => date('Y-m-d H:i:s')], ['idp' => $id]);
        Notices::recordSave($this->app, $k, $item, $version, $id);
        \Kaleta\Front\Cache::clear();

        return ['restored' => $id, 'name' => $version['nazev'] ?? $item['nazev']] + ($kept !== null ? ['note' => $kept] : []);
    }

    /** list_notice_log (2.11, Core\Notices): the append-only audit trail of an official notice board – administrators */
    private function toolListNoticeLog(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The notice log is read by administrators.');
        }
        $db = $this->app->db();
        $k = Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        if (!Notices::isNotices($k)) {
            throw new \InvalidArgumentException('The collection is not an official notice board (preset notices). Use list_collections.');
        }
        $entries = Notices::entries($db, (int) $k['idk'], isset($a['id']) ? (int) $a['id'] : null);

        return ['collection' => $k['seo_link'], 'count' => count($entries),
            'entries' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'item' => (int) $r['idp'], 'name' => (string) ($r['nazev'] ?? ''), 'action' => (string) $r['action'],
                'at' => (string) $r['at'], 'by' => (string) $r['by'], 'fields' => $r['fields'] !== [] ? $r['fields'] : new \stdClass()], array_slice($entries, -500)),
            'note' => 'Oldest first (the last 500). fields: key => [old, new] for created and changed; {posted: date} and {taken_down: date} are written by the hourly job once each. Nothing in the log can be edited or deleted.'];
    }

    /** get_email_signature (2.10, Builder\EmailSignature): the signature of one person by the item id or slug */
    private function toolGetEmailSignature(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $k = Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        $id = (int) ($a['id'] ?? 0);
        $slug = trim((string) ($a['slug'] ?? ''));
        $visible = $this->app->auth()->hasModule('collections') ? '' : ' AND zobrazit = 1'; // without the Collections section only people on the site
        $item = match (true) {
            $id > 0 => $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL' . $visible, [$id, $k['idk']]),
            $slug !== '' => $db->one('SELECT * FROM {kolekce_polozky} WHERE seo_link = ? AND idk = ? AND smazano IS NULL' . $visible . ' ORDER BY jazyk LIMIT 1', [$slug, $k['idk']]),
            default => null,
        };
        if ($item === null) {
            throw new \InvalidArgumentException('The item is not in the collection. Give its id or slug from list_collection_items.');
        }
        $item['data'] = json_decode((string) $item['data'], true) ?: [];

        return ['name' => $item['nazev'], 'people_collection' => \Kaleta\Builder\EmailSignature::isPeople($k), 'fields' => array_filter(\Kaleta\Builder\EmailSignature::fields($k))]
            + \Kaleta\Builder\EmailSignature::forItem($this->app, $k, $item);
    }

    /* ---------- collection categories (3.7, Builder\CollectionCategories) ---------- */

    /** The language of a category tool: '' for the default one, an additional language of the site, anything else refused. */
    private function categoryLanguage(array $a): string
    {
        $siteSettings = $this->app->settings();
        $code = trim((string) ($a['language'] ?? ''));
        if ($code === '' || $code === Language::defaults($siteSettings)) {
            return '';
        }
        if (!in_array($code, Language::additional($siteSettings), true)) {
            throw new \InvalidArgumentException('The language version "' . $code . '" is not switched on (Features → Language versions, languages in Settings).');
        }

        return $code;
    }

    /** list_collection_categories */
    private function toolListCollectionCategories(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $k = Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        $language = $this->categoryLanguage($a);
        $all = $this->app->auth()->hasModule('collections'); // without the Collections section only categories on the site
        $languages = [];
        foreach ($db->all('SELECT category_id, language FROM {collection_category_texts} WHERE idk = ? ORDER BY language', [$k['idk']]) as $t) {
            $languages[(int) $t['category_id']][] = $t['language'] === '' ? Language::defaults($this->app->settings()) : (string) $t['language'];
        }
        $out = [];
        $visible = [];
        foreach (CollectionCategories::tree($db, (int) $k['idk'], $language, !$all) as $c) {
            $visible[$c['id']] = $c['visible']; // a top-level category comes before its subcategories
            $public = CollectionCategories::isPublic($c + ['parent_visible' => $c['parent_id'] === null || ($visible[$c['parent_id']] ?? false)]);
            $out[] = ['id' => $c['id'], 'name' => $c['name'], 'slug' => $c['slug']] + ($c['parent_id'] !== null ? ['parent' => $c['parent_slug'], 'parent_id' => $c['parent_id']] : [])
                + array_filter(['description' => $c['description'], 'image' => $c['image'], 'seo_title' => $c['seo_title'], 'seo_description' => $c['seo_description']])
                + ['order' => $c['sort_order'], 'visible' => $c['visible'], 'items' => CollectionCategories::countItems($db, CollectionCategories::withChildren($db, $c['id'], false), $language),
                    'languages' => $languages[$c['id']] ?? [],
                    'url' => $this->app->request->origin() . $this->app->url(($language !== '' ? $language . '/' : '') . CollectionCategories::path((string) $k['seo_link'], $c))]
                + ($public ? [] : ['note' => 'hidden – not on the site']);
        }
        $missing = $language === '' ? 0 : (int) $db->value('SELECT COUNT(*) FROM {collection_categories} c WHERE c.idk = ? AND NOT EXISTS (SELECT 1 FROM {collection_category_texts} t WHERE t.category_id = c.id AND t.language = ?)', [$k['idk'], $language]);

        return ['collection' => $k['seo_link'], 'language' => $language === '' ? Language::defaults($this->app->settings()) : $language, 'categories' => $out]
            + ($missing > 0 ? ['without_this_language' => $missing, 'note' => 'Categories without texts in this language have no page in it – add them with save_collection_category (id + language).'] : [])
            + ($out === [] ? ['next' => 'save_collection_category creates one; the category pages are drawn by the category template (get_build/save_build with collection and category_template).'] : []);
    }

    /** save_collection_category */
    private function toolSaveCollectionCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if (!$auth->hasModule('collections')) {
            throw new \DomainException('Collection categories can be changed only by users with the Collections section.');
        }
        $k = Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        $language = $this->categoryLanguage($a);
        $id = isset($a['id']) && (int) $a['id'] > 0 ? (int) $a['id'] : null;
        $previous = $id !== null ? CollectionCategories::byId($db, $id) : null;
        if ($id !== null && ($previous === null || $previous['idk'] !== (int) $k['idk'])) {
            throw new \InvalidArgumentException('The category is not in this collection. Use list_collection_categories.');
        }
        // a drafts-only connection (3.2) creates hidden categories and changes hidden ones – a category page is public
        $draftsOnly = $auth->draftsOnly();
        if ($draftsOnly) {
            $proposeInstead = ' Write the change you propose into the note of the request or the summary of the run for a person to apply – or the user connects Claude with full access.';
            if ($previous !== null && $previous['visible']) {
                throw new \DomainException('This connection can only save drafts, and this category is on the site, so it cannot be changed here.' . $proposeInstead);
            }
            if (!empty($a['visible'])) {
                throw new \DomainException('This connection can only save drafts: it cannot make a category visible.' . $proposeInstead);
            }
        }
        $input = [];
        foreach (['name' => 'name', 'slug' => 'slug', 'description' => 'description', 'seo_title' => 'seo_title', 'seo_description' => 'seo_description', 'image' => 'image', 'order' => 'sort_order', 'visible' => 'visible'] as $from => $to) {
            if (array_key_exists($from, $a)) {
                $input[$to] = $a[$from];
            }
        }
        if ($previous === null && !array_key_exists('visible', $input)) {
            $input['visible'] = false; // a new category waits hidden until the user wants it on the site
        }
        if (array_key_exists('parent', $a)) {
            $parent = trim((string) (is_scalar($a['parent']) ? $a['parent'] : ''));
            $input['parent_id'] = match (true) {
                $parent === '' || $parent === '0' => 0,
                ctype_digit($parent) => (int) $parent,
                default => CollectionCategories::idsFromSlugs($db, (int) $k['idk'], [$parent], $language)[0] ?? throw new \InvalidArgumentException('The parent category "' . $parent . '" is not in this collection. Use list_collection_categories.'),
            };
        }
        $id = Language::runWith('en', fn (): int => CollectionCategories::save($db, (int) $k['idk'], $id, $language, $input), 'admin-');
        \Kaleta\Front\Cache::clear();
        $saved = CollectionCategories::bySlug($db, (int) $k['idk'], (string) (CollectionCategories::byId($db, $id)['texts'][$language]['slug'] ?? ''), $language);
        $path = $saved !== null ? CollectionCategories::path((string) $k['seo_link'], $saved) : '';

        return ['id' => $id, 'collection' => $k['seo_link'], 'language' => $language === '' ? Language::defaults($this->app->settings()) : $language, 'slug' => $saved['slug'] ?? '',
            'visible' => $saved !== null && CollectionCategories::isPublic($saved), 'url' => $this->app->request->origin() . $this->app->url(($language !== '' ? $language . '/' : '') . $path)]
            + ($draftsOnly ? ['next' => 'Saved hidden: a person reviews the category and makes it visible (Collections → Categories).'] : []);
    }

    /** delete_collection_category */
    private function toolDeleteCollectionCategory(string $name, array $a): mixed
    {
        $db = $this->app->db();
        if (!$this->app->auth()->hasModule('collections')) {
            throw new \DomainException('Collection categories can be deleted only by users with the Collections section.');
        }
        $k = Collections::bySlug($db, (string) ($a['collection'] ?? '')) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
        $id = (int) ($a['id'] ?? 0);
        $language = array_key_exists('language', $a) && (string) $a['language'] !== '' ? $this->categoryLanguage($a) : null;
        Language::runWith('en', function () use ($db, $k, $id, $language): void {
            if ($language !== null && $language !== '') {
                CollectionCategories::deleteLanguage($db, (int) $k['idk'], $id, $language);
            } else {
                CollectionCategories::delete($db, (int) $k['idk'], $id);
            }
        }, 'admin-');
        \Kaleta\Front\Cache::clear();

        return ['deleted' => $id] + ($language !== null && $language !== '' ? ['language' => $language] : []) + ['note' => 'The items of the category stay in the collection.'];
    }

    /** restore_item_version: the same as list_item_versions */
    private function toolRestoreItemVersion(string $name, array $a): mixed
    {
        return $this->toolListItemVersions($name, $a);
    }
}
