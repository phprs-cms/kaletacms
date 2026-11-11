<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\WpFile;

/**
 * The CSV/JSON import of collection items in the admin (3.7, Collections → the collection → Import).
 *
 * How it holds together:
 *  - Upload: a CSV (comma, semicolon or tab; UTF-8 or Windows-1250 from Excel – converted before parse()) or JSON
 *    (a list of objects, or {"items": […]}) with up to MAX_ROWS rows. The rows are kept in storage/import (never
 *    reachable from the web) next to a small state file, so every step can be resumed.
 *  - Preview: every column is mapped to a field of the collection, the name, the slug and the other item columns – matched
 *    automatically by the field key or label (automap) – and Builder\ItemBatch::plan says per row what saving would do:
 *    will be added, will change, no change, refused (with the reason). Importing the same file again updates the items:
 *    a row without a slug finds its item by the slug made from its name. An empty cell leaves the item's value as it is.
 *  - Save: in batches of BATCH rows (the form submits itself) through Builder\ItemBatch::save – the rules of one item saved
 *    in the admin. New items are hidden unless the administrator ticks "visible"; changed items keep their visibility.
 *  - Images: an image or file column holding https addresses is downloaded after the save, IMAGE_BATCH at a time
 *    (Builder\ItemBatch::downloadMedia – the rules of upload_file), and the item points at the file in Media.
 *  - Clean-up (3.7, N37-25): the rows file (up to 20 MB, possibly personal data) is deleted as soon as the rows are saved;
 *    the small record of a finished import stays for its result until the administrator removes it or the daily clean-up
 *    does after WpFile::KEEP_DAYS (WpFile::purgeOld).
 */
final class ItemImport
{
    public const int MAX_ROWS = 5000;
    public const int MAX_BYTES = 20 * 1024 * 1024;
    public const int MAX_COLUMNS = 60;
    public const int BATCH = 250;
    public const int IMAGE_BATCH = 10;
    public const float SECONDS = 8.0;

    /** Item columns a CSV column can fill besides the fields (the keys start with _, a field key never does). */
    public const array TARGETS = ['_name' => 'Name', '_slug' => 'Address (slug)', '_language' => 'Language', '_order' => 'Order',
        '_seo_title' => 'Title for search engines', '_description' => 'Description for search engines', '_share_image' => 'Image for sharing'];

    /** Column names that mean an item column (normalized by key()). */
    private const array ALIASES = [
        '_name' => ['name', 'nazev', 'title', 'titulek', 'post_title', 'product_name', 'nazev_produktu', 'jmeno', 'titel'],
        '_slug' => ['slug', 'adresa', 'seo_link', 'post_name', 'url_slug', 'url_key'],
        '_language' => ['language', 'jazyk', 'lang', 'locale', 'sprache'],
        '_order' => ['order', 'poradi', 'menu_order', 'position', 'sort', 'reihenfolge'],
        '_seo_title' => ['seo_title', 'seo_titulek', 'meta_title'],
        '_description' => ['meta_description', 'seo_description', 'seo_popis'],
        '_share_image' => ['share_image', 'og_image'],
    ];

    /* ---------- reading the file (pure, unit-tested) ---------- */

    /**
     * The header and the rows of a CSV or JSON file (UTF-8), or why it cannot be read.
     *
     * @return array{0: list<string>, 1: list<list<string>>}|string
     */
    public static function parse(string $text, string $fileName = ''): array|string
    {
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (trim($text) === '') {
            return 'The file is empty.';
        }
        $json = str_ends_with(strtolower($fileName), '.json') || preg_match('/^\s*[\[{]/', $text) === 1;
        $parsed = $json ? self::parseJson($text) : self::parseCsv($text);
        if (is_string($parsed)) {
            return $parsed;
        }
        [$header, $rows] = $parsed;
        if ($header === [] || $rows === []) {
            return 'The file has no rows – the first row names the columns, every further row is one item.';
        }
        if (count($rows) > self::MAX_ROWS) {
            return 'The file has more than 5,000 rows – split it into smaller files.';
        }

        return [$header, $rows];
    }

    /** @return array{0: list<string>, 1: list<list<string>>}|string */
    private static function parseCsv(string $text): array|string
    {
        $first = (string) strtok($text, "\n");
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($first, $candidate) > substr_count($first, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return 'The file could not be read.';
        }
        fwrite($stream, $text);
        rewind($stream);
        $header = null;
        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(fn (?string $c): string => trim((string) $c), array_slice($cells, 0, self::MAX_COLUMNS));
            if (implode('', $cells) === '') {
                continue;
            }
            if ($header === null) {
                $header = $cells;
                continue;
            }
            $rows[] = array_pad($cells, count($header), '');
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        fclose($stream);

        return [$header ?? [], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<string>>}|string */
    private static function parseJson(string $text): array|string
    {
        $data = json_decode($text, true, 32);
        if (is_array($data) && !array_is_list($data) && is_array($data['items'] ?? null)) {
            $data = $data['items'];
        }
        if (!is_array($data) || !array_is_list($data)) {
            return 'The JSON file must be a list of items: [{"name":"…","price":"…"}, …].';
        }
        $objects = [];
        $header = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            // nested values (the shape of save_collection_items) come up one level
            foreach (['values', 'data'] as $nested) {
                if (is_array($item[$nested] ?? null) && !array_is_list($item[$nested])) {
                    $item = $item[$nested] + array_diff_key($item, [$nested => 1]);
                }
            }
            $object = [];
            foreach ($item as $key => $value) {
                $key = trim((string) $key);
                if ($key === '' || (!isset($header[$key]) && count($header) >= self::MAX_COLUMNS)) {
                    continue;
                }
                $header[$key] = true;
                $object[$key] = match (true) {
                    is_bool($value) => $value ? '1' : '0',
                    is_scalar($value) => trim((string) $value),
                    default => '',
                };
            }
            if (implode('', $object) !== '') {
                $objects[] = $object;
            }
            if (count($objects) > self::MAX_ROWS) {
                break;
            }
        }
        $columns = array_map('strval', array_keys($header));

        return [$columns, array_map(fn (array $o): array => array_map(fn (string $c): string => $o[$c] ?? '', $columns), $objects)];
    }

    /** A column name for matching: lowercase, without accents, words joined with _. */
    private static function key(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(remove_diacritics($name))), '_');
    }

    /**
     * The target of every column: a field key (by the key, then by the label), an item column (TARGETS, by ALIASES) or
     * '' (not imported). Each target is used once – the first column wins.
     *
     * @param list<string> $header
     * @param list<array{klic: string, popisek: string, typ: string}> $fields
     * @return list<string>
     */
    public static function automap(array $header, array $fields): array
    {
        $byKey = [];
        foreach ($fields as $f) {
            $byKey[$f['klic']] ??= $f['klic'];
        }
        foreach ($fields as $f) {
            $byKey[self::key($f['popisek'])] ??= $f['klic'];
        }
        foreach (self::ALIASES as $target => $aliases) {
            foreach ($aliases as $alias) {
                $byKey[$alias] ??= $target;
            }
        }
        $used = [];
        $mapping = [];
        foreach ($header as $column) {
            $target = $byKey[self::key($column)] ?? '';
            if ($target !== '' && isset($used[$target])) {
                $target = '';
            }
            $used[$target] = true;
            $mapping[] = $target;
        }

        return $mapping;
    }

    /**
     * A mapping from the form, checked: only known targets, each one once.
     *
     * @param list<string> $header
     * @param array<array-key, mixed> $input
     * @param list<array{klic: string, popisek: string, typ: string}> $fields
     * @return list<string>
     */
    public static function cleanMapping(array $header, array $input, array $fields): array
    {
        $allowed = array_merge(array_keys(self::TARGETS), array_column($fields, 'klic'));
        $used = [];
        $mapping = [];
        foreach (array_keys($header) as $i) {
            $target = is_string($input[$i] ?? null) ? $input[$i] : '';
            if (!in_array($target, $allowed, true) || isset($used[$target])) {
                $target = '';
            }
            if ($target !== '') {
                $used[$target] = true;
            }
            $mapping[] = $target;
        }

        return $mapping;
    }

    /**
     * The rows as Builder\ItemBatch rows, and the media to download after the save: an https or http address in an image
     * or file column is not a value yet – the item keeps its current file until the download replaces it.
     *
     * @param array<string, mixed> $collection
     * @param list<list<string>> $rows
     * @param list<string> $mapping
     * @return array{0: list<array<string, mixed>>, 1: list<array{0: int, 1: string, 2: string}>} [rows, [row index, field, url]]
     */
    public static function toRows(array $collection, array $rows, array $mapping, string $language): array
    {
        $types = array_column((array) $collection['pole'], 'typ', 'klic');
        $page = ['_seo_title' => 'seo_titulek', '_description' => 'popis', '_share_image' => 'obrazek'];
        $out = [];
        $media = [];
        foreach ($rows as $index => $cells) {
            $row = ItemBatch::emptyRow();
            $row['language'] = $language;
            foreach ($mapping as $i => $target) {
                $cell = (string) ($cells[$i] ?? '');
                match (true) {
                    // an empty cell leaves the value as it is: a file with a blank column never wipes what an item has
                    $target === '' || ($cell === '' && $target !== '_language') => null,
                    $target === '_name' => $row['name'] = $cell,
                    $target === '_slug' => $row['slug'] = $cell,
                    $target === '_language' => $row['language'] = $cell !== '' ? strtolower($cell) : $language,
                    $target === '_order' => $row['order'] = is_numeric($cell) ? (int) $cell : null,
                    isset($page[$target]) => $row['page'][$page[$target]] = $cell,
                    in_array($types[$target] ?? '', ['obrazek', 'soubor'], true) && preg_match('#^https?://#i', $cell) === 1 => $media[] = [$index, $target, $cell],
                    default => $row['values'][$target] = $cell,
                };
            }
            $out[] = $row;
        }

        return [$out, $media];
    }

    /* ---------- the import's state (storage/import, like the other imports) ---------- */

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    public static function create(array $collection, string $fileName, array $header, array $rows, int $user): array
    {
        $id = bin2hex(random_bytes(8));
        file_put_contents(self::file($id, 'rows'), (string) json_encode(['header' => $header, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $state = ['id' => $id, 'idk' => (int) $collection['idk'], 'soubor' => mb_substr(basename(str_replace('\\', '/', $fileName)), 0, 120), 'uzivatel' => $user,
            'zalozeno' => date('Y-m-d H:i:s'), 'radku' => count($rows), 'mapovani' => self::automap($header, (array) $collection['pole']), 'jazyk' => '', 'zobrazit' => false,
            'faze' => 'nahled', 'pozice' => 0, 'duplicity' => [], 'pocty' => ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'refused' => 0], 'odmitnute' => [], 'neplatna' => 0,
            'obrazky' => ['fronta' => [], 'pozice' => 0, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []]];
        self::save($state);

        return $state;
    }

    /** @return array<string, mixed>|null */
    public static function load(string $id, int $idk): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id) || !is_file(self::file($id))) {
            return null;
        }
        $state = json_decode((string) file_get_contents(self::file($id)), true);

        return is_array($state) && (int) ($state['idk'] ?? 0) === $idk ? $state : null;
    }

    /** @return array{header: list<string>, rows: list<list<string>>} */
    public static function rows(string $id): array
    {
        $data = preg_match('/^[a-f0-9]{16}$/D', $id) && is_file(self::file($id, 'rows')) ? json_decode((string) file_get_contents(self::file($id, 'rows')), true) : null;

        return ['header' => is_array($data['header'] ?? null) ? $data['header'] : [], 'rows' => is_array($data['rows'] ?? null) ? $data['rows'] : []];
    }

    /** @param array<string, mixed> $state */
    public static function save(array $state): void
    {
        file_put_contents(self::file((string) $state['id']), (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public static function delete(string $id): void
    {
        if (preg_match('/^[a-f0-9]{16}$/D', $id)) {
            @unlink(self::file($id));
            @unlink(self::file($id, 'rows'));
        }
    }

    /** @return list<array<string, mixed>> the imports of a collection that are not finished, newest first */
    public static function unfinished(int $idk): array
    {
        return array_values(array_filter(self::all($idk), fn (array $state): bool => $state['faze'] !== 'hotovo'));
    }

    /** @return list<array<string, mixed>> the finished imports of a collection (their results), newest first (3.7, N37-25) */
    public static function finished(int $idk): array
    {
        return array_values(array_filter(self::all($idk), fn (array $state): bool => $state['faze'] === 'hotovo'));
    }

    /** @return list<array<string, mixed>> every import of a collection, newest first */
    private static function all(int $idk): array
    {
        $all = [];
        foreach (glob(WpFile::folder() . '/polozky-*.json') ?: [] as $file) {
            if (preg_match('/^polozky-([a-f0-9]{16})\.json$/D', basename($file), $m) && ($state = self::load($m[1], $idk)) !== null) {
                $all[] = $state;
            }
        }
        usort($all, fn (array $a, array $b): int => strcmp((string) $b['zalozeno'], (string) $a['zalozeno']));

        return $all;
    }

    private static function file(string $id, string $part = ''): string
    {
        return WpFile::folder() . '/polozky-' . $id . ($part !== '' ? '.' . $part : '') . '.json';
    }

    /* ---------- the steps ---------- */

    /**
     * What saving would do with every row under the current mapping.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $collection
     * @return list<array<string, mixed>>
     */
    public static function plan(App $app, array $collection, array $state): array
    {
        [$rows] = self::toRows($collection, self::rows((string) $state['id'])['rows'], (array) $state['mapovani'], (string) $state['jazyk']);

        return ItemBatch::plan($app, $collection, $rows, ['slug_from_name' => true, 'new_visible' => (bool) $state['zobrazit'], 'drafts_only' => !$app->auth()->canPublish()]); // 3.35 (N107)
    }

    /**
     * Starts saving: the whole file is planned once, so a row repeated further on stays refused in every batch.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $collection
     */
    public static function start(App $app, array $collection, array &$state): void
    {
        $state['duplicity'] = array_values(array_map(fn (array $p): int => (int) $p['index'], array_filter(self::plan($app, $collection, $state),
            fn (array $p): bool => $p['reason'] === ItemBatch::DUPLICATE)));
        $state['faze'] = 'ulozeni';
        $state['pozice'] = 0;
        $state['pocty'] = ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'refused' => 0];
        $state['odmitnute'] = [];
        $state['prejmenovane'] = [];
        $state['neplatna'] = 0;
        $state['obrazky'] = ['fronta' => [], 'pozice' => 0, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []];
    }

    /**
     * One batch: BATCH rows saved, then (when all are) IMAGE_BATCH downloads; the phase moves on by itself.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $collection
     */
    public static function step(App $app, array $collection, array &$state): void
    {
        if ($state['faze'] === 'ulozeni') {
            $all = self::rows((string) $state['id'])['rows'];
            $from = (int) $state['pozice'];
            [$rows, $media] = self::toRows($collection, array_slice($all, $from, self::BATCH), (array) $state['mapovani'], (string) $state['jazyk']);
            foreach ($state['duplicity'] as $duplicate) {
                if ($duplicate >= $from && $duplicate < $from + count($rows)) {
                    $rows[$duplicate - $from]['error'] = ItemBatch::DUPLICATE;
                }
            }
            $saved = ItemBatch::save($app, $collection, $rows, ['slug_from_name' => true, 'new_visible' => (bool) $state['zobrazit'], 'drafts_only' => !$app->auth()->canPublish()]); // 3.35 (N107): a role that does not publish imports hidden items
            $ids = [];
            foreach ($saved as $p) {
                $state['pocty'][$p['status']]++;
                $state['neplatna'] += count($p['invalid']) > 0 ? 1 : 0;
                $ids[(int) $p['index']] = $p['status'] !== 'refused' ? (int) $p['id'] : 0;
                if ($p['status'] === 'refused' && count($state['odmitnute']) < 100) {
                    $state['odmitnute'][] = [$from + (int) $p['index'] + 2, (string) $p['name'], (string) $p['reason']]; // + 2: the header is row 1 of the file
                }
                if ($p['status'] !== 'refused' && $p['note'] !== [] && count($state['prejmenovane'] ?? []) < 100) {
                    $state['prejmenovane'][] = [$from + (int) $p['index'] + 2, (string) $p['name'], ...$p['note']]; // an address of a category got a number (3.7)
                }
            }
            foreach ($media as [$index, $field, $url]) {
                if (($ids[$index] ?? 0) > 0) {
                    $state['obrazky']['fronta'][] = [$ids[$index], $field, $url];
                }
            }
            $state['pozice'] = $from + count($rows);
            if ($state['pozice'] >= count($all)) {
                $state['faze'] = $state['obrazky']['fronta'] !== [] ? 'obrazky' : 'hotovo';
                @unlink(self::file((string) $state['id'], 'rows')); // every row is saved: the uploaded file is not kept (3.7, N37-25)
                \Kaleta\Admin\ChangeLog::write($app, 'collections', 'import', mb_substr($collection['seo_link'] . ': ' . $state['pocty']['added'] . ' + ' . $state['pocty']['changed'], 0, 80));
            }

            return;
        }
        if ($state['faze'] === 'obrazky') {
            self::images($app, $collection, $state);
        }
    }

    /**
     * Downloads the next images of the imported items into Media and points the items at them.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $collection
     */
    private static function images(App $app, array $collection, array &$state): void
    {
        $db = $app->db();
        $types = array_column((array) $collection['pole'], 'typ', 'klic');
        $end = microtime(true) + self::SECONDS;
        $downloads = 0;
        $queue = $state['obrazky']['fronta'];
        while ($state['obrazky']['pozice'] < count($queue) && $downloads < self::IMAGE_BATCH && microtime(true) < $end) {
            [$idp, $field, $url] = $queue[$state['obrazky']['pozice']];
            $state['obrazky']['pozice']++;
            $item = $db->one('SELECT idp, nazev, data FROM {kolekce_polozky} WHERE idp = ? AND idk = ? AND smazano IS NULL', [(int) $idp, (int) $collection['idk']]);
            if ($item === null) {
                continue;
            }
            $downloads++;
            [$path, $error] = ItemBatch::downloadMedia($app, (string) $url, (string) ($types[$field] ?? 'obrazek'), (string) $item['nazev']);
            if ($path === null) {
                $state['obrazky']['chyb']++;
                $state['obrazky']['chyby'] = array_slice([...$state['obrazky']['chyby'], [(string) $item['nazev'], mb_substr((string) $url, 0, 200), $error]], -20);
                continue;
            }
            $state['obrazky']['stazeno']++;
            $data = json_decode((string) $item['data'], true) ?: [];
            if (($data[$field] ?? null) !== $path) {
                $data[$field] = $path;
                $db->update('kolekce_polozky', ['data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')], ['idp' => (int) $idp]);
            }
        }
        if ($state['obrazky']['pozice'] >= count($queue)) {
            $state['faze'] = 'hotovo';
            $state['obrazky']['fronta'] = [];
        }
        \Kaleta\Front\Cache::clear();
    }
}
