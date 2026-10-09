<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * A free slug in the URL (seo_link of a page, news item, category, collection item, popup slug): when the base is taken,
 * it gets a sequence number (o-nas, o-nas-2, o-nas-3…). The base is shortened so that it fits in the column with the number.
 *
 * Slugs per language (3.9, docs/design/4.0-data-model.md §3 step 1): with the setting slugs_per_language a page, a news
 * item or a news category may have the same slug as one in another language version (/kontakt and /en/kontakt – a
 * multilingual WordPress site moves without renaming its addresses). Every "is the slug taken?" check of those three
 * tables asks scope() or taken(), so the check is per language exactly when the setting is on. The setting is changed
 * only through switchPerLanguage(), which drops the global unique keys (switching on) or puts them back (switching off,
 * refused while two language versions share a slug); the per-language keys (jazyk, seo_link) of migration 0083 always
 * stay. Collections stay global (/<collection> is the same in every language); collection items and their categories
 * were per language already. System addresses and language codes stay reserved in every language (Pages::slugReserved).
 */
final class Slug
{
    /** Tables with a global slug key next to the per-language one: table => [id column, global key, per-language key]. */
    public const array TABLES = [
        'stranky' => ['ids', 'uq_stranky_seo', 'uq_stranky_jazyk_seo'],
        'novinky' => ['idc', 'uq_clanky_seo', 'uq_clanky_jazyk_seo'],
        'kategorie' => ['idt', 'uq_topic_seo', 'uq_topic_jazyk_seo'],
    ];

    /** The setting slugs_per_language once per request; null = not read yet. */
    private static ?bool $perLanguage = null;

    /** @param callable(string): bool $isTaken */
    public static function makeUnique(string $base, callable $isTaken, int $max = 160): string
    {
        $url = mb_substr($base, 0, $max);
        for ($i = 2; $isTaken($url); $i++) {
            if ($i > 10000) {
                throw new \RuntimeException('No free address was found.');
            }
            $extension = '-' . $i;
            $url = rtrim(mb_substr($base, 0, $max - strlen($extension)), '-') . $extension;
        }

        return $url;
    }

    /** Are slugs of pages, news and categories unique per language version (setting slugs_per_language)? Off without a database. */
    public static function perLanguage(?Db $db): bool
    {
        if (self::$perLanguage === null && $db !== null) {
            try {
                self::$perLanguage = $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'slugs_per_language'") === '1';
            } catch (\Throwable) {
                return false; // a site before installation
            }
        }

        return self::$perLanguage ?? false;
    }

    /** Sets the value without the database (tests); null forgets it, so it is read again (Settings::set). */
    public static function setPerLanguage(?bool $on): void
    {
        self::$perLanguage = $on;
    }

    /**
     * The part of a "slug taken?" query that limits it to the language version when slugs are per language: [" AND jazyk = ?",
     * [language]], otherwise ["", []] – the slug is then taken by any language version, as before 3.9.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function scope(?Db $db, string $language, string $column = 'jazyk'): array
    {
        return self::perLanguage($db) ? [' AND ' . $column . ' = ?', [$language]] : ['', []];
    }

    /**
     * Does a page, news item or category other than $except have the slug – in the language version $language when slugs
     * are per language, in any otherwise? Items in the trash keep their slug (a restore must not collide).
     */
    public static function taken(Db $db, string $table, string $slug, string $language, int $except = 0): bool
    {
        [$id] = self::TABLES[$table] ?? throw new \InvalidArgumentException('Unknown table ' . $table);
        [$where, $params] = self::scope($db, $language);

        return $db->value("SELECT 1 FROM {{$table}} WHERE seo_link = ? AND {$id} <> ?{$where} LIMIT 1", [$slug, $except, ...$params]) !== null;
    }

    /**
     * Slugs that more than one language version uses: table => list of slugs (at most $limit per table, trash included –
     * the global key covers it too). Empty = the global keys can come back.
     *
     * @return array<string, list<string>>
     * @phpstan-impure
     */
    public static function duplicates(Db $db, int $limit = 10): array
    {
        $found = [];
        foreach (array_keys(self::TABLES) as $table) {
            $slugs = array_map('strval', array_column($db->all("SELECT seo_link FROM {{$table}} GROUP BY seo_link HAVING COUNT(*) > 1 ORDER BY seo_link LIMIT " . max(1, $limit)), 'seo_link'));
            if ($slugs !== []) {
                $found[$table] = $slugs;
            }
        }

        return $found;
    }

    /**
     * Switches slugs per language on or off – the only way the setting changes. On: the global unique keys are dropped (the
     * per-language ones stay), then the setting is written. Off: the setting goes first (checks are global again from that
     * moment), then the global keys come back; refused while a slug is shared by two language versions. Returns null when
     * done, otherwise the reason (English source text for t(), with %s for the shared slugs).
     *
     * @return array{0: string, 1: string}|null [message, the slugs it names]
     */
    public static function switchPerLanguage(Db $db, Settings $settings, bool $on): ?array
    {
        if ($on === self::perLanguage($db) && $on === ($settings->get('slugs_per_language') === '1') && self::globalKeysMatch($db, $on)) {
            return null;
        }
        if ($on) {
            foreach (self::TABLES as $table => [, $global, $perLanguage]) {
                if (!self::hasKey($db, $table, $perLanguage)) {
                    return ['The database is not updated yet (migration 0083) – open the administration once more and try again.', ''];
                }
                if (self::hasKey($db, $table, $global)) {
                    $db->run("ALTER TABLE {{$table}} DROP INDEX {$global}");
                }
            }
            $settings->set('slugs_per_language', '1');

            return null;
        }
        if (($shared = self::duplicates($db)) !== []) {
            return self::refusal($shared);
        }
        $settings->set('slugs_per_language', '0');
        try {
            foreach (self::TABLES as $table => [, $global]) {
                if (!self::hasKey($db, $table, $global)) {
                    $db->run("ALTER TABLE {{$table}} ADD UNIQUE KEY {$global} (seo_link)");
                }
            }
        } catch (\PDOException $e) {
            // a second language version took a shared slug in the meantime: per language stays on
            $settings->set('slugs_per_language', '1');

            return ($shared = self::duplicates($db)) !== [] ? self::refusal($shared) : throw $e;
        }

        return null;
    }

    /**
     * The form of an address a redirect is stored under for content of the language version $language: with the prefix
     * (en/old) while slugs are per language – /en/old and /old may then be two different pages –, otherwise as before 3.9,
     * without it (the redirect then answers in every version, Front\Kernel adds the visitor's prefix to the target).
     */
    public static function redirectPath(?Db $db, string $path, string $language): string
    {
        return $language !== '' && self::perLanguage($db) ? $language . '/' . ltrim($path, '/') : $path;
    }

    /** @param array<string, list<string>> $shared @return array{0: string, 1: string} */
    private static function refusal(array $shared): array
    {
        return ['The same address in every language cannot be switched off while two language versions share an address: %s. Change one of each pair first.',
            implode(', ', array_map(fn (string $s): string => '/' . $s, array_unique(array_merge(...array_values($shared)))))];
    }

    /** Do the global keys exist exactly when slugs are not per language? */
    private static function globalKeysMatch(Db $db, bool $on): bool
    {
        foreach (self::TABLES as $table => [, $global]) {
            if (self::hasKey($db, $table, $global) === $on) {
                return false;
            }
        }

        return true;
    }

    private static function hasKey(Db $db, string $table, string $key): bool
    {
        return $db->value('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1', [$db->prefix . $table, $key]) !== null;
    }
}
