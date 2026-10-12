<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Files;
use Kaleta\Core\ImageDownloader;
use Kaleta\Core\Images;
use Kaleta\Core\Notices;
use Kaleta\Core\Outbound;
use Kaleta\Core\Slug;
use Kaleta\Core\WpFile;

/**
 * Many collection items at once (3.7): MCP save_collection_items and the CSV/JSON import of the admin (Builder\ItemImport).
 *
 * The same rules as one item saved in the admin or with save_collection_item:
 *  - values go through Collections::sanitizeData (an invalid value is left empty and reported), fields left out keep
 *    their value on an update;
 *  - an item is found by its id, or by its slug in its language; without either a new one is created (the slug made
 *    unique within the language, so a translation can share it);
 *  - an item never takes a category's address (CollectionCategories::itemSlugFree, 3.7 N37-8): such a slug gets a number
 *    (tables-2) and the row says so in `note`; a row with the category's slug finds the item that got the numbered one,
 *    so importing the same file again updates it;
 *  - an item in the trash is not changed (saving would put it back on the site);
 *  - a drafts-only connection creates hidden items and changes hidden ones only (Auth::draftsOnly, 3.2) – never a visible
 *    or scheduled item, never visible: true;
 *  - a notice of an official notice board cannot be hidden (Core\Notices), a change of an item keeps the previous version.
 * Every item is checked and saved on its own: one invalid item never blocks the others. plan() only says what would happen.
 *
 * Media by URL: an image or file field can be filled from an https address – downloaded like upload_file does it
 * (Core\ImageDownloader: public addresses only, a pinned connection, size and time limits, images never SVG), at most
 * MAX_DOWNLOADS per call; the rest is reported as deferred. A file downloaded once is reused (ka_import_mapa).
 *
 * Saving (3.7, N37-21): the media downloads can take a while, so every item is read again and locked (SELECT … FOR UPDATE)
 * in its save transaction; the trash and drafts-only rules are applied to that fresh row, fields changed meanwhile are kept
 * (only the fields a row sets are written), and the visibility is written only when the row sets it – an item a person
 * published meanwhile stays published.
 *
 * @phpstan-type Row array{id: ?int, slug: string, name: ?string, values: array<string, mixed>, language: ?string, visible: ?bool, order: ?int, page: array<string, mixed>, media: array<string, string>, error: string, extra: list<string>}
 */
final class ItemBatch
{
    /** Items in one MCP call. */
    public const int MAX_ITEMS = 200;

    /** Media downloads in one call, and the seconds they may take together (the rest is deferred to the next call). */
    public const int MAX_DOWNLOADS = 20;
    public const float DOWNLOAD_SECONDS = 20.0;

    /** Where downloaded media are remembered in ka_import_mapa (cizi_id = sha1 of the address). */
    private const string MEDIA_SOURCE = 'media-url';

    /** The reason a row is refused when its item came earlier in the same batch. */
    public const string DUPLICATE = 'The same item is in this batch twice – only the first one is saved.';

    /** The SEO columns of an item a row may set (1.9). */
    private const array PAGE_COLUMNS = ['seo_titulek', 'popis', 'obrazek', 'noindex'];

    /** The keys of an item from MCP; any other key is reported back as unknown (3.7, N37-28). */
    private const array ITEM_KEYS = ['id', 'slug', 'name', 'values', 'language', 'visible', 'order', 'seo_title', 'description', 'share_image', 'noindex', 'media'];

    /** The order of an item (ka_kolekce_polozky.poradi) goes from -MAX_ORDER to MAX_ORDER. */
    private const int MAX_ORDER = 9999;

    /** Why a drafts-only connection cannot change an item that is on the site. */
    private const string DRAFTS_ONLY_VISIBLE = 'This connection can only save drafts, and this item is on the site (or scheduled to be published), so it cannot be changed here.';

    /**
     * One row from MCP (English keys) as a Row; what is wrong with its shape goes into `error`.
     *
     * @return Row
     */
    public static function fromMcp(mixed $item): array
    {
        $row = self::emptyRow();
        if (!is_array($item)) {
            return ['error' => 'Each item is an object with name, values and the optional id, slug, language and visible.'] + $row;
        }
        $text = fn (string $key): ?string => is_scalar($item[$key] ?? null) ? (string) $item[$key] : null;
        $row['extra'] = array_values(array_diff(array_map(strval(...), array_keys($item)), self::ITEM_KEYS));
        // whole numbers only, checked – a huge or broken number never becomes another item's id or a wrapped order (3.7, N37-27)
        $id = isset($item['id']) ? filter_var($item['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        $order = isset($item['order']) ? filter_var($item['order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => -self::MAX_ORDER, 'max_range' => self::MAX_ORDER]]) : null;
        if ($id === false || $order === false) {
            return ['error' => $id === false ? 'id must be the whole number of an item (list_collection_items).' : 'order must be a whole number from -' . self::MAX_ORDER . ' to ' . self::MAX_ORDER . '.'] + $row;
        }
        $row['id'] = $id;
        $row['slug'] = trim((string) $text('slug'));
        $row['name'] = $text('name');
        $row['language'] = $text('language');
        $row['visible'] = array_key_exists('visible', $item) ? filter_var($item['visible'], FILTER_VALIDATE_BOOL) : null;
        $row['order'] = $order;
        foreach (['seo_title' => 'seo_titulek', 'description' => 'popis', 'share_image' => 'obrazek', 'noindex' => 'noindex'] as $en => $cs) {
            if (array_key_exists($en, $item)) {
                $row['page'][$cs] = $item[$en];
            }
        }
        $values = $item['values'] ?? [];
        $media = $item['media'] ?? [];
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            $row['error'] = 'values must be an object {"field key":"value"} by the fields of the collection.';
        } elseif (!is_array($media) || ($media !== [] && array_is_list($media))) {
            $row['error'] = 'media must be an object {"field key":"https://…"}.';
        } else {
            $row['values'] = $values;
            $row['media'] = array_map(fn (mixed $u): string => is_string($u) ? trim($u) : '', $media);
        }

        return $row;
    }

    /** @return Row */
    public static function emptyRow(): array
    {
        return ['id' => null, 'slug' => '', 'name' => null, 'values' => [], 'language' => null, 'visible' => null, 'order' => null, 'page' => [], 'media' => [], 'error' => '', 'extra' => []];
    }

    /**
     * What saving the rows would do, row by row: added, changed, unchanged or refused (with the reason).
     *
     * @param array<string, mixed> $collection Collections::bySlug / byId
     * @param list<Row> $rows
     * @param array{drafts_only?: bool, slug_from_name?: bool, new_visible?: bool} $options slug_from_name: a row without
     *        a slug finds its item by the slug made from its name (the CSV import, so that importing a file again updates);
     *        new_visible: new items are visible (the admin's tick box)
     * @return list<array<string, mixed>> one entry per row: index, status, reason, note ([] or [format, …] for t() – the slug
     *         got a number), id, slug, name, language, visible, invalid (field labels), unknown (keys of values), unknown_item
     *         (keys of the item, 3.7), media, media_failed; and for saving row, data, set (the field values the row sets), previous
     */
    public static function plan(App $app, array $collection, array $rows, array $options = []): array
    {
        $db = $app->db();
        $settings = $app->settings();
        $fields = (array) $collection['pole'];
        $types = array_column($fields, 'typ', 'klic');
        $byKey = [];
        $byId = [];
        $taken = []; // "language|slug" => the item that has it (0 = a new one in this batch)
        // the addresses of the collection's categories in every language – never an item's (3.7, N37-8)
        $categories = array_fill_keys(array_map('strval', array_column($db->all('SELECT DISTINCT slug FROM {collection_category_texts} WHERE idk = ?', [(int) $collection['idk']]), 'slug')), true);
        foreach ($db->all('SELECT * FROM {kolekce_polozky} WHERE idk = ?', [(int) $collection['idk']]) as $r) {
            $byKey[$r['jazyk'] . '|' . $r['seo_link']] = $r;
            $byId[(int) $r['idp']] = $r;
            $taken[$r['jazyk'] . '|' . $r['seo_link']] = (int) $r['idp'];
        }
        $draftsOnly = (bool) ($options['drafts_only'] ?? false);
        $seen = [];
        $plan = [];
        foreach ($rows as $index => $row) {
            $entry = ['index' => $index, 'status' => 'refused', 'reason' => '', 'note' => [], 'id' => 0, 'slug' => '', 'name' => (string) ($row['name'] ?? ''), 'language' => '',
                'visible' => false, 'invalid' => [], 'unknown' => [], 'unknown_item' => $row['extra'], 'media' => [], 'media_failed' => [], 'row' => [], 'data' => [], 'set' => [], 'previous' => null];
            $refuse = function (string $reason) use (&$entry, &$plan): void {
                $entry['reason'] = $reason;
                $plan[] = $entry;
            };
            if ($row['error'] !== '') {
                $refuse($row['error']);
                continue;
            }
            $language = $row['language'] !== null ? \Kaleta\Core\Language::column($settings, trim($row['language'])) : null;
            $given = $row['slug'] !== '' ? slugify($row['slug'], 150) : '';
            if ($given === '' && ($options['slug_from_name'] ?? false) && trim((string) $row['name']) !== '') {
                $given = slugify(trim((string) $row['name']), 150);
            }
            $requested = $given;
            if ($row['id'] !== null) {
                $previous = $byId[$row['id']] ?? null;
                if ($previous === null) {
                    $refuse('The item is not in this collection. Use list_collection_items.');
                    continue;
                }
            } else {
                if ($given !== '' && isset($categories[$given]) && !isset($byKey[($language ?? '') . '|' . $given])) {
                    // a category's address: the item has (or gets) the first numbered one that is no category's
                    $given = Slug::makeUnique($given, fn (string $s): bool => isset($categories[$s]), 150);
                }
                $previous = $given !== '' ? ($byKey[($language ?? '') . '|' . $given] ?? null) : null;
            }
            if ($previous !== null && $previous['smazano'] !== null) {
                $refuse('The item is in the trash – restore it first.');
                continue;
            }
            $identity = $previous !== null ? 'id:' . $previous['idp'] : ($given !== '' ? 'new:' . ($language ?? '') . '|' . $given : '');
            if ($identity !== '' && isset($seen[$identity])) {
                $refuse(self::DUPLICATE);
                continue;
            }
            // a drafts-only connection (3.2): hidden items only, what visitors see stays a person's call
            if ($draftsOnly && $previous !== null && ((int) $previous['zobrazit'] === 1 || $previous['zverejnit_od'] !== null)) {
                $refuse(self::DRAFTS_ONLY_VISIBLE);
                continue;
            }
            if ($draftsOnly && $previous !== null && $row['visible'] === true) {
                $refuse('This connection can only save drafts: it cannot make an item visible.');
                continue;
            }
            $name = mb_substr(trim((string) ($row['name'] ?? $previous['nazev'] ?? '')), 0, 200);
            if ($name === '') {
                $refuse('The item needs a name.');
                continue;
            }
            $errors = [];
            $data = Collections::sanitizeData($fields, $row['values'] + (json_decode((string) ($previous['data'] ?? '{}'), true) ?: []), $errors);
            $language ??= (string) ($previous['jazyk'] ?? '');
            $seo = $given !== '' ? $given : (string) ($previous['seo_link'] ?? slugify($name, 150));
            if ($seo === '' || $seo === '_ukazka') {
                $refuse('Invalid item address.');
                continue;
            }
            $own = (int) ($previous['idp'] ?? -1);
            $stored = (string) ($previous['seo_link'] ?? '');
            $wanted = $requested !== '' ? $requested : $seo;
            $seo = Slug::makeUnique($seo, fn (string $s): bool => (isset($taken[$language . '|' . $s]) && $taken[$language . '|' . $s] !== $own) || ($s !== $stored && isset($categories[$s])), 150);
            $note = $wanted !== $seo && $wanted !== $stored && isset($categories[$wanted]) ? ['The address “%s” belongs to a category of this collection, so the item has “%s”.', $wanted, $seo] : [];
            $visible = $draftsOnly ? ($previous === null ? false : (bool) $previous['zobrazit'])
                : ($row['visible'] ?? ($previous !== null ? (bool) $previous['zobrazit'] : (bool) ($options['new_visible'] ?? false)));
            // the visibility is written for a new item, or when the row sets it – never by a drafts-only connection (N37-21)
            $setsVisible = $previous === null || (!$draftsOnly && $row['visible'] !== null);
            $columns = ['nazev' => $name, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'jazyk' => $language]
                + ($setsVisible ? ['zobrazit' => (int) $visible] : [])
                + ($row['order'] !== null ? ['poradi' => max(-self::MAX_ORDER, min(self::MAX_ORDER, $row['order']))] : []);
            if ($row['page'] !== []) {
                $page = Collections::pageFields(array_intersect_key($row['page'], array_flip(self::PAGE_COLUMNS)) + ($previous ?? []), $visible);
                $columns = array_intersect_key($page, $row['page']) + $columns;
            }
            if (Notices::refusesHiding($collection, $data, $visible)) {
                $refuse(Notices::REFUSAL_HIDE);
                continue;
            }
            $entry = ['status' => self::status($previous, $columns), 'note' => $note, 'id' => (int) ($previous['idp'] ?? 0), 'slug' => $seo, 'name' => $name, 'language' => $language, 'visible' => $visible,
                'invalid' => array_values($errors), 'unknown' => array_values(array_diff(array_map('strval', array_keys($row['values'])), array_keys($types))),
                'row' => $columns, 'data' => $data, 'set' => array_intersect_key($data, $row['values']), 'previous' => $previous] + $entry;
            foreach ($row['media'] as $field => $url) {
                $field = (string) $field;
                $reason = match (true) {
                    !in_array($types[$field] ?? '', ['obrazek', 'soubor'], true) => 'The field does not exist or is not an image or file field.',
                    !self::downloadable($url) => 'Only https addresses are downloaded.',
                    default => '',
                };
                if ($reason !== '') {
                    $entry['media_failed'][] = ['field' => $field, 'url' => mb_substr($url, 0, 300), 'reason' => $reason];
                } else {
                    $entry['media'][$field] = $url;
                }
            }
            $taken[$language . '|' . $seo] = $own === -1 ? 0 : $own;
            $seen[$previous !== null ? 'id:' . $previous['idp'] : 'new:' . $language . '|' . $seo] = true;
            if ($identity !== '') {
                $seen[$identity] = true;
            }
            $plan[] = $entry;
        }

        return $plan;
    }

    /**
     * Saves what plan() accepted: first the media by URL (within the limits), then every item in its own transaction.
     *
     * @param array<string, mixed> $collection
     * @param list<Row> $rows
     * @param array{drafts_only?: bool, slug_from_name?: bool, new_visible?: bool} $options
     * @param array{shown: list<string>, waiting: list<string>}|null $categories the hidden categories the saved items showed (3.12, N312-3)
     * @return list<array<string, mixed>> the plan with the saved ids, and media_downloaded / media_deferred per item
     */
    public static function save(App $app, array $collection, array $rows, array $options = [], ?array &$categories = null): array
    {
        $plan = self::plan($app, $collection, $rows, $options);
        $budget = self::MAX_DOWNLOADS;
        $end = microtime(true) + self::DOWNLOAD_SECONDS;
        $done = []; // url => [path, error] within this call
        foreach ($plan as &$entry) {
            $entry['media_downloaded'] = [];
            $entry['media_deferred'] = [];
            if ($entry['status'] === 'refused') {
                continue;
            }
            foreach ($entry['media'] as $field => $url) {
                if (!isset($done[$url])) {
                    if ($budget <= 0 || microtime(true) > $end) {
                        $entry['media_deferred'][] = $field;
                        continue;
                    }
                    $reused = self::mediaFromMap($app, $url);
                    if ($reused === null) {
                        $budget--;
                    }
                    $done[$url] = $reused ?? self::downloadMedia($app, $url, (string) (array_column((array) $collection['pole'], 'typ', 'klic')[$field] ?? 'obrazek'), $entry['name']);
                }
                [$path, $error] = $done[$url];
                if ($path === null) {
                    $entry['media_failed'][] = ['field' => (string) $field, 'url' => mb_substr($url, 0, 300), 'reason' => $error];
                    continue;
                }
                $entry['data'][$field] = $path;
                $entry['set'][$field] = $path;
                $entry['media_downloaded'][] = $field;
            }
            if ($entry['media_downloaded'] !== []) {
                $entry['row']['data'] = (string) json_encode($entry['data'], JSON_UNESCAPED_UNICODE);
                $entry['status'] = self::status($entry['previous'], $entry['row']);
            }
        }
        unset($entry);
        $db = $app->db();
        $draftsOnly = (bool) ($options['drafts_only'] ?? false);
        $written = false;
        foreach ($plan as &$entry) {
            if (!in_array($entry['status'], ['added', 'changed'], true)) {
                continue;
            }
            try {
                $entry['id'] = (int) $db->transaction(function () use ($app, $db, $collection, $entry, $draftsOnly): int {
                    $row = $entry['row'] + ['zmeneno' => date('Y-m-d H:i:s')];
                    $previous = null;
                    if ($entry['previous'] !== null) {
                        // the item as it is now, locked until this save is done (N37-21): plan() read it before the downloads
                        $previous = $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? FOR UPDATE', [(int) $entry['previous']['idp']]);
                        if ($previous === null || $previous['smazano'] !== null) {
                            throw new \DomainException('The item was deleted or moved to the trash in the meantime.');
                        }
                        if ($draftsOnly && ((int) $previous['zobrazit'] === 1 || $previous['zverejnit_od'] !== null)) {
                            throw new \DomainException(self::DRAFTS_ONLY_VISIBLE); // a person published it in the meantime
                        }
                        if ((string) $previous['data'] !== (string) $entry['previous']['data']) {
                            // a field changed in the meantime keeps its new value: only the fields this row sets are written
                            $row['data'] = (string) json_encode($entry['set'] + (json_decode((string) $previous['data'], true) ?: []), JSON_UNESCAPED_UNICODE);
                        }
                        Collections::saveVersion($app, $previous, $row);
                        $db->update('kolekce_polozky', $row, ['idp' => $previous['idp']]);
                        $idp = (int) $previous['idp'];
                    } else {
                        $row += ['idk' => $collection['idk'], 'datum' => date('Y-m-d H:i:s')];
                        $idp = $db->insert('kolekce_polozky', $row);
                    }
                    Notices::recordSave($app, $collection, $previous, $row, $idp);

                    return $idp;
                });
                $written = true;
            } catch (\DomainException $e) {
                $entry['status'] = 'refused';
                $entry['reason'] = $e->getMessage();
            } catch (\RuntimeException) {
                $entry['status'] = 'refused';
                $entry['reason'] = 'The item could not be saved.';
            }
        }
        unset($entry);
        if ($written) {
            // 3.12: items that were hidden before show their hidden categories (the first published item of a category)
            $categories = CollectionCategories::showForItems($db, array_map(fn (array $e): int => (int) ($e['previous']['zobrazit'] ?? 0) === 0 ? (int) ($e['id'] ?? 0) : 0, $plan), $app);
            \Kaleta\Front\Cache::clear();
        }

        return $plan;
    }

    /**
     * added (no previous item), changed, or unchanged – nothing the row sets differs from what is stored.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $columns
     */
    private static function status(?array $previous, array $columns): string
    {
        if ($previous === null) {
            return 'added';
        }
        foreach ($columns as $key => $value) {
            $same = $key === 'data'
                ? (json_decode((string) $value, true) ?: []) == (json_decode((string) $previous['data'], true) ?: [])
                : (string) $value === (string) ($previous[$key] ?? '');
            if (!$same) {
                return 'changed';
            }
        }

        return 'unchanged';
    }

    /** An https address, as upload_file takes (the automated tests also their own http server on 127.0.0.1, KALETA_IMPORT_LOCAL=1). */
    private static function downloadable(string $url): bool
    {
        return Outbound::url($url) !== null && (str_starts_with(strtolower($url), 'https://')
            || (getenv('KALETA_IMPORT_LOCAL') === '1' && str_starts_with($url, 'http://127.0.0.1:')));
    }

    /**
     * A file downloaded from this address before (and still in Media).
     *
     * @return array{0: string, 1: string}|null [path, '']
     */
    private static function mediaFromMap(App $app, string $url): ?array
    {
        $db = $app->db();
        $ido = $db->value("SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = 'medium' AND cizi_id = ?", [self::MEDIA_SOURCE, sha1($url)]);
        $path = $ido === null ? null : $db->value('SELECT obr_poloha FROM {media} WHERE ido = ?', [(int) $ido]);

        return $path === null ? null : [(string) $path, ''];
    }

    /**
     * Downloads one file for an image field (JPEG, PNG, GIF or WebP only) or a file field (the attachment types of
     * Core\Files) into Media, the same way upload_file does with a url. Never SVG.
     *
     * @return array{0: ?string, 1: string} [path in Media, or null and the reason]
     */
    public static function downloadMedia(App $app, string $url, string $fieldType, string $alt): array
    {
        $reused = self::mediaFromMap($app, $url);
        if ($reused !== null) {
            return $reused;
        }
        if (!self::downloadable($url)) {
            return [null, 'Only https addresses are downloaded.'];
        }
        if (!ImageDownloader::isAvailable()) {
            return [null, 'This server cannot download files from other sites.'];
        }
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $image = $fieldType !== 'soubor' || !Files::isAttachment($name);
        if (str_ends_with(strtolower($name), '.svg')) {
            return [null, 'SVG files are not downloaded – upload them with upload_file.'];
        }
        $temporary = WpFile::folder() . '/polozka-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            file_put_contents($temporary, (new ImageDownloader($url))->download($url, $image));
            $saved = $image ? Images::saveFile($temporary, $name !== '' ? $name : 'image.jpg') : Files::saveFile($temporary, $name);
            if ($alt !== '') {
                $saved['nazev'] = mb_substr($alt, 0, 150);
            }
            $ido = $app->db()->insert('media', $saved + ['vlastnik' => $app->auth()->id() ?: null, 'datum' => date('Y-m-d H:i:s')]);
            $app->db()->run('INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nase_id = VALUES(nase_id)', [self::MEDIA_SOURCE, 'medium', sha1($url), $ido]);

            return [(string) $saved['obr_poloha'], ''];
        } catch (\RuntimeException $e) {
            return [null, $e->getMessage() . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')];
        } finally {
            @unlink($temporary);
        }
    }
}
