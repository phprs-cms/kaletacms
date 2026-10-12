<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Alphabetical lists in the order of the site language (3.12, docs/design/4.0-data-model.md §1.2).
 *
 * Every table stores text in utf8mb4_czech_ci (the owner's decision of 11 October 2026: the storage collation stays), so
 * "chata" sorts after "hrad" on every site. A list people read by name – pages, tags, categories, collections and their
 * items, media, users, services – sorts through Db::sortText(), which adds COLLATE <the collation of the language> to
 * that ORDER BY expression only:
 *  - Czech (and any language without a mapping on a server that lacks every fallback) adds nothing, so a Czech site sends
 *    the very same SQL and gets byte-identical ordering;
 *  - the other languages take the first collation of their list the database server has, then the language-neutral
 *    FALLBACK. All names are the legacy UCA collations MySQL 8 and MariaDB 10.6 both have; the server is asked once (and
 *    the answer is kept a day in storage/cache), so a host without one of them still sorts, by the next one.
 * Only ORDER BY, never WHERE or JOIN: an explicit collation there would mix with the column's own and lose the index. The
 * lists are hundreds of rows, so a sort without the index costs nothing.
 */
final class Collation
{
    /** The collation the tables store text in: a sort in it needs no COLLATE. */
    public const string STORAGE = 'utf8mb4_czech_ci';

    /** Language (the base of a tag, Language::base) => collations to try in order. Languages not listed use FALLBACK. */
    public const array LANGUAGES = [
        'cs' => [self::STORAGE],
        'sk' => ['utf8mb4_slovak_ci'],
        'pl' => ['utf8mb4_polish_ci'],
        'de' => ['utf8mb4_german2_ci'],
        'da' => ['utf8mb4_danish_ci'],
        'no' => ['utf8mb4_danish_ci'], // æ, ø, å after z, as in Danish
        'sv' => ['utf8mb4_swedish_ci'],
        'fi' => ['utf8mb4_swedish_ci'], // å, ä, ö after z, as in Swedish
        'et' => ['utf8mb4_estonian_ci'],
        'hu' => ['utf8mb4_hungarian_ci'],
        'is' => ['utf8mb4_icelandic_ci'],
        'lv' => ['utf8mb4_latvian_ci'],
        'lt' => ['utf8mb4_lithuanian_ci'],
        'ro' => ['utf8mb4_romanian_ci'],
        'sl' => ['utf8mb4_slovenian_ci'],
        'hr' => ['utf8mb4_croatian_ci'],
        'bs' => ['utf8mb4_croatian_ci'],
        'sr' => ['utf8mb4_croatian_ci'], // Kaleta's Serbian is Latin (Srpski)
        'tr' => ['utf8mb4_turkish_ci'],
        'es' => ['utf8mb4_spanish_ci'],
        'vi' => ['utf8mb4_vietnamese_ci'],
    ];

    /** Language-neutral collations (Unicode 5.2, then 4.0, then MySQL's own) for every other language and a missing specific one. */
    public const array FALLBACK = ['utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci'];

    /** How long the list of the server's collations is kept in storage/cache (a site moved to another host re-checks within a day). */
    private const int CACHE_SECONDS = 86400;

    /** The language lists sort by: the shown version on the site (Language::setSite), the default language in the administration. */
    private static string $language = 'cs';

    /** @var list<string>|null the utf8mb4 collations of the server, null = not asked yet */
    private static ?array $available = null;

    public static function setLanguage(string $code): void
    {
        self::$language = $code;
    }

    public static function language(): string
    {
        return self::$language;
    }

    /**
     * The collation a list in the language sorts by, from the collations the server has; '' = the storage collation (no
     * COLLATE needed). Pure, so the unit tests check the mapping without a database.
     *
     * @param list<string> $available
     */
    public static function choose(string $language, array $available): string
    {
        foreach ([...(self::LANGUAGES[Language::base(strtolower($language))] ?? []), ...self::FALLBACK] as $collation) {
            if ($collation === self::STORAGE) {
                return '';
            }
            if (in_array($collation, $available, true)) {
                return $collation;
            }
        }

        return '';
    }

    /**
     * An ORDER BY expression (a utf8mb4 text column or expression, never an ASCII one) sorted in the language: "titulek"
     * on a Czech site, "titulek COLLATE utf8mb4_german2_ci" on a German one. $language null = the current list language.
     */
    public static function sort(Db $db, string $expression, ?string $language = null): string
    {
        $language ??= self::$language;
        $collation = Language::base($language) === 'cs' ? '' : self::choose($language, self::available($db)); // Czech asks nothing

        return $collation === '' ? $expression : $expression . ' COLLATE ' . $collation;
    }

    /**
     * The utf8mb4 collations of the database server: asked once a day (storage/cache/collations.json) and once per request.
     *
     * @return list<string>
     */
    public static function available(Db $db): array
    {
        if (self::$available !== null) {
            return self::$available;
        }
        $file = KALETA_ROOT . '/storage/cache/collations.json';
        $cached = is_file($file) && time() - (int) filemtime($file) < self::CACHE_SECONDS ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($cached) && $cached !== [] && array_is_list($cached)) {
            return self::$available = array_values(array_filter($cached, is_string(...)));
        }
        try {
            $names = array_map('strval', array_column($db->all("SHOW COLLATION WHERE Charset = 'utf8mb4'"), 'Collation'));
        } catch (\Throwable) {
            $names = []; // a server that refuses the question sorts by the storage collation, as before
        }
        if ($names !== []) {
            @file_put_contents($file, (string) json_encode($names), LOCK_EX);
        }

        return self::$available = $names;
    }
}
