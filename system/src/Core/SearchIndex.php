<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The stored search text of pages, collection items and news (3.53, A3): the column hledani, normalized by
 * Search::normalize, which the site search reads instead of the raw HTML and JSON. NULL = to index.
 *
 * Three ways in: page() / item() right after a save (a saved record is searchable in the next request), complete() fills
 * the rows still NULL in short slices (the background job search_index, an import, the installer), and reindexNews()
 * rewrites the news index made by an older Search::VERSION – row by row, the old text stays until its row is rewritten,
 * so news never fall back to title-only. Every write is unjournaled (derived data, never in the undo journal of a Claude
 * session). The column exists from migration 0141 (ready()); on an older schema every method does nothing.
 */
final class SearchIndex
{
    /**
     * The content columns of each table the search text is made of – the one list for text() and for the rule that empties
     * the index when one of them changes (Db, 3.53 A4).
     */
    public const array CONTENT = [
        'stranky' => ['titulek', 'seo_titulek', 'popis', 'text', 'stavba'],
        'kolekce_polozky' => ['nazev', 'popis', 'data'],
    ];

    /**
     * The item field types whose values go to the index (Builder\Collections::FIELD_TYPES): texts, choices, parameters
     * and variants (a variant code is searchable). Never an image, link, file, place, item link, number or date.
     */
    public const array SEARCH_TYPES = ['text', 'radky', 'html', 'volba', 'parametry', 'varianty'];

    /** The longest stored text in bytes; a longer one is cut (mb_strcut, never inside a character). */
    public const int MAX_BYTES = 204800;

    /** Rows read at a time by complete() and reindexNews(). */
    public const int BATCH = 25;

    /** The first database version with the column (migration 0141). */
    public const int DB_VERSION = 141;

    /** The internal setting "version:cursor": the news index is complete for version, rewritten up to idc cursor for the next one. */
    public const string STATE = 'search_index_at';

    /** The internal setting "previous:current" – the rows waiting after the last two job runs (System status warns when it does not fall). */
    public const string WAITING = 'search_index_checked';

    /** Table => [primary key, content columns read for text()]. */
    private const array TABLES = [
        'stranky' => ['ids', 'titulek, seo_titulek, popis, text, stavba'],
        'kolekce_polozky' => ['idp', 'idk, nazev, popis, data'],
        'novinky' => ['idc', 'titulek, t_slova, uvod, text'],
    ];

    /** Is the column there (the database is at migration 0141 or later)? */
    public static function ready(Settings $settings): bool
    {
        return $settings->int('db_version') >= self::DB_VERSION;
    }

    /** The columns the admin search of pages looks in besides the index (3.53, B4): the Pages list, palette, MCP list_pages. */
    public const array PAGE_COLUMNS = ['titulek', 'seo_link', 'seo_titulek'];

    /** The columns the admin search of collection items looks in besides the index: the list of items, palette, MCP. */
    public const array ITEM_COLUMNS = ['nazev', 'seo_link'];

    /**
     * The admin search of pages (3.53, B4): every word in PAGE_COLUMNS or the index, so "strasse" finds "Straße" and a word
     * inside a tag attribute does not count; a page not indexed yet by its text. $ready = ready(). $alias with its dot.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function pageWords(string $q, bool $ready, string $alias = ''): array
    {
        return Search::indexWords($q, $alias, self::PAGE_COLUMNS, $ready, ['text']);
    }

    /**
     * The admin search of collection items (3.53, B4): every word in ITEM_COLUMNS or the index (the values of the text
     * fields – never a JSON key); an item not indexed yet by its fields (Db::likeJson), as in 3.52.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function itemWords(string $q, bool $ready, string $alias = ''): array
    {
        return Search::indexWords($q, $alias, self::ITEM_COLUMNS, $ready, json: ['data']);
    }

    /**
     * The search text of one row, normalized and cut at MAX_BYTES. A page: its title, SEO title, description and body – the
     * published build as text (BuildText::of, which Builder\Build::asText is) when it has one (also an empty one), else its text. An item: its name, description and the
     * values of its SEARCH_TYPES fields ($fields = the collection's field definitions; broken JSON or no fields = name and
     * description only). A news item: title, keywords, intro and text, as Search::index has made it since 1.x.
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>>|null $fields
     */
    public static function text(string $kind, array $row, ?array $fields = null): string
    {
        $parts = match ($kind) {
            'stranky' => [$row['titulek'] ?? '', $row['seo_titulek'] ?? '', $row['popis'] ?? '', self::pageBody($row)],
            'kolekce_polozky' => [$row['nazev'] ?? '', $row['popis'] ?? '', ...self::itemValues($row, $fields)],
            'novinky' => [$row['titulek'] ?? '', $row['t_slova'] ?? '', $row['uvod'] ?? '', $row['text'] ?? ''],
            default => throw new \InvalidArgumentException('No search text for ' . $kind),
        };

        return mb_strcut(Search::normalize(implode(' ', array_map(strval(...), $parts))), 0, self::MAX_BYTES, 'UTF-8');
    }

    /** @param array<string, mixed> $row */
    private static function pageBody(array $row): string
    {
        // the published build as Builder\Build::fromJson reads it: a JSON object with children; invalid = a text page
        $build = isset($row['stavba']) ? json_decode((string) $row['stavba'], true) : null;

        // a page with a published build is its build, also when the build has no text: the text column may be older then
        // (Publisher::page keeps it when the build text is empty)
        return plain_text(is_array($build) && isset($build['deti']) ? BuildText::of($build) : (string) ($row['text'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>>|null $fields
     * @return list<string>
     */
    private static function itemValues(array $row, ?array $fields): array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);
        if (!is_array($data) || $fields === null) {
            return [];
        }
        $values = [];
        foreach ($fields as $field) {
            $value = $data[(string) ($field['klic'] ?? '')] ?? null;
            if (in_array($field['typ'] ?? '', self::SEARCH_TYPES, true) && is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /** Indexes one page now (after a save or a publish). Never throws: a page the index could not take waits NULL for the job. */
    public static function page(Db $db, int $ids): void
    {
        self::now($db, 'stranky', $ids);
    }

    /** @var array<int, list<array<string, mixed>>|null> the field definitions of collections read by this run of complete() / item() */
    private static array $fields = [];

    /** Indexes one collection item now (after a save). Never throws, as page(). */
    public static function item(Db $db, int $idp): void
    {
        self::$fields = []; // the fields may have changed in this request
        self::now($db, 'kolekce_polozky', $idp);
    }

    /**
     * The index right after a save (3.53, A4): the save is done, so nothing here may undo or fail it – a database without
     * the column (before migration 0141) is silence, any other error is logged and the row stays NULL for the job.
     */
    private static function now(Db $db, string $table, int $id): void
    {
        try {
            self::one($db, $table, $id, false);
        } catch (\Throwable $e) {
            if (!$e instanceof \PDOException || !Db::tableMissing($e)) {
                ErrorLog::write(ErrorLog::exception($e));
            }
        }
    }

    /** @var array<string, string> the column lists of columns(), by the full table name */
    private static array $columns = [];

    /**
     * Every column of pages or collection items but the search text, for the reads of many rows that would otherwise carry
     * it for nothing (up to MAX_BYTES a row): 'SELECT ' . SearchIndex::columns($db, 'stranky') . ' FROM {stranky} …'. Asked
     * once per request; on a database before migration 0141 it is simply every column.
     */
    public static function columns(Db $db, string $table): string
    {
        return self::$columns[$db->tableName($table)] ??= implode(', ', array_map(Db::quoteName(...), array_values(array_filter(
            array_map(strval(...), array_column($db->all('SHOW COLUMNS FROM {' . $table . '}'), 'Field')), fn (string $c): bool => $c !== 'hledani'))));
    }

    /**
     * Fills the rows still NULL – pages, then items, then news – for at most $seconds (at least one row when there is
     * time at all). Each row is read and written in a short transaction with its row locked (SELECT … FOR UPDATE), so a
     * save running at the same moment is never overwritten by a text made from the older content. A row whose text cannot
     * be made is written as '' and counted (never retried for ever). Resumable: what is left stays NULL for the next run.
     *
     * @return array{pages: int, items: int, news: int, failed: int, left: bool}
     */
    public static function complete(Db $db, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        $done = ['pages' => 0, 'items' => 0, 'news' => 0, 'failed' => 0, 'left' => $seconds <= 0];
        if ($done['left']) {
            return $done;
        }
        self::$fields = [];
        $first = true;
        try {
            foreach (['stranky' => 'pages', 'kolekce_polozky' => 'items', 'novinky' => 'news'] as $table => $count) {
                [$key] = self::TABLES[$table];
                $after = 0; // within one run each row is visited once, also when its write was refused
                while (true) {
                    $ids = array_map(intval(...), array_column($db->all('SELECT ' . $key . ' FROM {' . $table . '} WHERE hledani IS NULL AND ' . $key . ' > ? ORDER BY ' . $key . ' LIMIT ' . self::BATCH, [$after]), $key));
                    if ($ids === []) {
                        break;
                    }
                    foreach ($ids as $id) {
                        if (!$first && microtime(true) >= $deadline) {
                            $done['left'] = true;

                            return $done;
                        }
                        $first = false;
                        $after = $id;
                        $result = self::one($db, $table, $id, true);
                        $done[$count] += $result === null ? 0 : 1;
                        $done['failed'] += $result === false ? 1 : 0;
                    }
                }
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // an older schema: nothing to fill yet
        }

        return $done;
    }

    /**
     * Rewrites the news index made by an older Search::VERSION (3.53: version 1 knew only Latin, Arabic and Hebrew) for at
     * most $seconds: by idc above the stored cursor, only the rows whose text changes, the cursor stored after each batch;
     * at the end the version is stored. The old text stays until its row is rewritten. Returns the rows rewritten.
     */
    public static function reindexNews(Db $db, Settings $settings, float $seconds): int
    {
        [$version, $cursor] = self::state($settings);
        if ($version >= Search::VERSION) {
            return 0;
        }
        $deadline = microtime(true) + $seconds;
        $rewritten = 0;
        $first = true;
        while (true) {
            $ids = array_map(intval(...), array_column($db->all('SELECT idc FROM {novinky} WHERE idc > ? ORDER BY idc LIMIT ' . self::BATCH, [$cursor]), 'idc'));
            if ($ids === []) {
                $settings->set(self::STATE, Search::VERSION . ':0');

                return $rewritten;
            }
            foreach ($ids as $id) {
                if (!$first && microtime(true) >= $deadline) {
                    $settings->set(self::STATE, $version . ':' . $cursor);

                    return $rewritten;
                }
                $first = false;
                $rewritten += self::one($db, 'novinky', $id, false, true) === true ? 1 : 0;
                $cursor = $id;
            }
            $settings->set(self::STATE, $version . ':' . $cursor);
        }
    }

    /**
     * [version, cursor] of the news index: '' = version 1 (an index of 3.52 or older), cursor 0.
     *
     * @return array{0: int, 1: int}
     */
    public static function state(Settings $settings): array
    {
        $parts = explode(':', $settings->get(self::STATE));

        return [max(1, (int) $parts[0]), max(0, (int) ($parts[1] ?? 0))];
    }

    /**
     * The rows waiting for their text: NULL pages, items and news, plus the news a re-index has not rewritten yet. Zeros on
     * an older schema.
     *
     * @return array{pages: int, items: int, news: int}
     */
    public static function pending(Db $db, Settings $settings): array
    {
        if (!self::ready($settings)) {
            return ['pages' => 0, 'items' => 0, 'news' => 0];
        }
        [$version, $cursor] = self::state($settings);
        try {
            return [
                'pages' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE hledani IS NULL'),
                'items' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE hledani IS NULL'),
                'news' => (int) ($version >= Search::VERSION
                    ? $db->value('SELECT COUNT(*) FROM {novinky} WHERE hledani IS NULL')
                    : $db->value('SELECT COUNT(*) FROM {novinky} WHERE hledani IS NULL OR idc > ?', [$cursor])),
            ];
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return ['pages' => 0, 'items' => 0, 'news' => 0];
        }
    }

    /**
     * The background job search_index: complete() and then reindexNews() within $seconds; afterwards it remembers how many
     * rows wait (WAITING), for System status. A short result for the job list.
     */
    public static function run(Db $db, Settings $settings, float $seconds): string
    {
        if (!self::ready($settings)) {
            return 'not yet: the database update of 3.53 has not run';
        }
        $start = microtime(true);
        $done = self::complete($db, $seconds);
        $rewritten = $done['left'] ? 0 : self::reindexNews($db, $settings, max(0.0, $seconds - (microtime(true) - $start)));
        $waiting = array_sum(self::pending($db, $settings));
        $previous = explode(':', $settings->get(self::WAITING))[1] ?? '';
        if ($settings->get(self::WAITING) !== $previous . ':' . $waiting) {
            $settings->set(self::WAITING, $previous . ':' . $waiting);
        }

        return 'filled ' . $done['pages'] . ' pages, ' . $done['items'] . ' items, ' . $done['news'] . ' news'
            . ($done['failed'] > 0 ? ', ' . $done['failed'] . ' could not be read' : '') . ($rewritten > 0 ? ', news re-indexed ' . $rewritten : '')
            . ($waiting > 0 ? ', ' . $waiting . ' waiting' : '');
    }

    /**
     * Whether the index is stuck (System status): rows wait, and their number did not fall between the last two job runs.
     */
    public static function stuck(Settings $settings): bool
    {
        $parts = explode(':', $settings->get(self::WAITING));

        return count($parts) === 2 && $parts[0] !== '' && (int) $parts[1] > 0 && (int) $parts[1] >= (int) $parts[0];
    }

    /**
     * Makes and writes the text of one row in a short transaction with the row locked. $onlyNull: only while it is still
     * NULL (complete()); $onlyChanged: write only when the text differs (a re-index). true = written, null = nothing to do
     * (the row is gone, already indexed, unchanged), false = its text could not be made – '' written instead.
     */
    private static function one(Db $db, string $table, int $id, bool $onlyNull, bool $onlyChanged = false): ?bool
    {
        [$key, $columns] = self::TABLES[$table];

        return $db->unjournaled(fn (): ?bool => $db->transaction(function (Db $db) use ($table, $key, $columns, $id, $onlyNull, $onlyChanged): ?bool {
            $row = $db->one('SELECT ' . $columns . ', hledani FROM {' . $table . '} WHERE ' . $key . ' = ?' . ($onlyNull ? ' AND hledani IS NULL' : '') . ' FOR UPDATE', [$id]);
            if ($row === null) {
                return null;
            }
            try {
                $text = self::text($table, $row, $table === 'kolekce_polozky' ? self::fields($db, (int) $row['idk']) : null);
                $made = true;
            } catch (\Throwable $e) {
                ErrorLog::write(ErrorLog::exception($e));
                [$text, $made] = ['', false];
            }
            if ($onlyChanged && $text === (string) $row['hledani']) {
                return null;
            }
            $db->run('UPDATE {' . $table . '} SET hledani = ? WHERE ' . $key . ' = ?', [$text, $id]);

            return $made;
        }));
    }

    /**
     * The field definitions of a collection, read once per run of complete() or item().
     *
     * @return list<array<string, mixed>>|null
     */
    private static function fields(Db $db, int $idk): ?array
    {
        if (!array_key_exists($idk, self::$fields)) {
            $pole = $db->value('SELECT pole FROM {kolekce} WHERE idk = ?', [$idk]);
            $decoded = $pole === null ? null : json_decode((string) $pole, true);
            self::$fields[$idk] = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : null;
        }

        return self::$fields[$idk];
    }
}
