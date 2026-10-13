<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The versions of obsah.json, the content file of a site export (SiteExport writes it, SiteImport reads it).
 *
 * Format 2 (1.8, still the one SiteExport writes by default in 3.14): the keys are the physical names of today – the
 * header "format", "verze_formatu", "kaleta", "vytvoreno", "nastaveni", then one key per table without the prefix
 * ("stranky", "novinky", "stitky"…) holding a list of rows, one JSON object per line, with the column names as keys.
 *
 * Format 3 (defined in 3.14, docs/design/4.0-data-model.md §4; 4.0 writes it by default): the same structure and the
 * same values, with English keys from the rename map system/sql/names.php (Core\Names):
 * - header: "format": "kaleta-export", "format_version": 3, "kaleta", "created", "settings" (HEADER);
 * - a table is under its English name ("pages", "news", "tags", "redirects", "site_parts"…; Names::english), a table
 *   the map names the same ("facts", "media") keeps its key;
 * - a column the map lists is under its English name ("seo_link" → "slug", "jazyk" → "language", "idc" → "id"), every
 *   other column keeps its name – the map covers the identifiers and the most-read columns, the rest follows in 5.0;
 * - the two values of a news item that are not columns: "autor" (the author's name, accounts are never exported) is
 *   "author", "stitky" (the ids of its tags) is "tag_ids" (COMPUTED);
 * - the values are the same as in format 2: dates keep the site's wall time (UTC instants come with 4.1), builds,
 *   menus and other JSON inside a column keep their Czech keys (Mcp\Vocabulary is a separate decision).
 *
 * SiteImport reads both: a format-3 file is turned into the format-2 shape while it is split into tables, so every row
 * goes through the same validators. A Kaleta before 3.14 refuses a format-3 file as "from a newer version".
 */
final class ExportFormat
{
    /** What SiteExport writes when nothing else is asked for – a 3.14 export still imports into older 3.x. */
    public const int DEFAULT = 2;

    /** The newest format SiteImport reads (and SiteExport writes on request, SiteExport::create($db, $settings, 3)). */
    public const int NEWEST = 3;

    /** Header keys of format 2 => format 3 ("format" and "kaleta" are the same in both). */
    public const array HEADER = ['verze_formatu' => 'format_version', 'vytvoreno' => 'created', 'nastaveni' => 'settings'];

    /**
     * Values of an exported row that are not columns of the table (or that mean something else than the column):
     * table => [format-2 key => format-3 key]. They win over the rename map (novinky.autor is the author's name in the
     * export, not the author_id the column holds).
     */
    public const array COMPUTED = ['novinky' => ['autor' => 'author', 'stitky' => 'tag_ids']];

    /** The format version of a header in either form; 1 = an export from before the version was written. */
    public static function version(array $header): int
    {
        return (int) ($header['format_version'] ?? $header['verze_formatu'] ?? 1);
    }

    /** The key a table has in the given format: novinky → news in format 3. */
    public static function tableKey(string $table, int $format): string
    {
        return $format >= 3 ? Names::english($table) : $table;
    }

    /** The table a key of the given format names (news → novinky in format 3); an unknown key stays as it is. */
    public static function tableFromKey(string $key, int $format): string
    {
        return $format >= 3 ? Names::physical($key) : $key;
    }

    /** @return array<string, string> format-2 key => format-3 key of a table's rows (only those that differ) */
    public static function columnKeys(string $table): array
    {
        return array_filter((self::COMPUTED[$table] ?? []) + Names::columns($table), fn (string $english, string $key): bool => $english !== $key, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * A row of the physical table as the given format writes it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function rowOut(string $table, array $row, int $format): array
    {
        return $format >= 3 ? self::renameKeys($row, self::columnKeys($table)) : $row;
    }

    /**
     * A row read from an export of the given format, with the keys of format 2 (= the columns of today).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function rowIn(string $table, array $row, int $format): array
    {
        return $format >= 3 ? self::renameKeys($row, array_flip(self::columnKeys($table))) : $row;
    }

    /**
     * The header as the given format writes it.
     *
     * @param array<string, mixed> $header with the keys of format 2
     * @return array<string, mixed>
     */
    public static function headerOut(array $header, int $format): array
    {
        return $format >= 3 ? self::renameKeys($header, self::HEADER) : $header;
    }

    /**
     * A header of either format with the keys of format 2 (what SiteImport reads).
     *
     * @param array<string, mixed> $header
     * @return array<string, mixed>
     */
    public static function headerIn(array $header): array
    {
        return self::version($header) >= 3 ? self::renameKeys($header, array_flip(self::HEADER)) : $header;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $names old key => new key
     * @return array<string, mixed>
     */
    private static function renameKeys(array $values, array $names): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$names[$key] ?? $key] = $value;
        }

        return $out;
    }
}
