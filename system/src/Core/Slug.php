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
 *
 * Tags (3.14, migration 0095, the owner's answer 5) follow the same rules: a tag created from a news item gets the item's
 * language version, its slug key is (jazyk, seo_link) next to the global one, and the switch drops or restores the global
 * key of tags with the others (Admin\Modules\Tags::findOrCreate).
 *
 * 3.19 (data model §3 step 2, the owner's decisions of 21 and 22 October 2026): on for every site. Migration 0106 checks
 * each site as switchPerLanguage did, drops the global keys and splits tags used in several languages; a fresh install
 * has the setting on and schema.sql has no global keys. A site that cannot switch stays as it was (setting '0'); why is in
 * failure() and on System status, and Settings → General offers only "switch on" there, which runs the same migration
 * again (enableEverywhere). Switching off is no longer offered anywhere: the code of 3.18.x honours the setting, so a
 * rollback to 3.18.3 needs no global keys; switchPerLanguage(off) stays for the documented manual schema rollback only.
 */
final class Slug
{
    /** Tables with a global slug key next to the per-language one: table => [id column, global key, per-language key]. */
    public const array TABLES = [
        'stranky' => ['ids', 'uq_stranky_seo', 'uq_stranky_jazyk_seo'],
        'novinky' => ['idc', 'uq_clanky_seo', 'uq_clanky_jazyk_seo'],
        'kategorie' => ['idt', 'uq_topic_seo', 'uq_topic_jazyk_seo'],
        'stitky' => ['ids', 'uq_stitky_seo', 'uq_stitky_jazyk_seo'], // 3.14, migration 0095
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

    /** The data migration of 3.19 that switches every site on (and splits tags); enableEverywhere() runs it again. */
    public const string MIGRATION = '0106-addresses-per-language';

    /**
     * Why migration 0106 could not switch this site on (3.19): ['reason' => shared | database | busy, 'table' => …, 'slugs' =>
     * list of addresses shared by two rows of one language, or 'error' => the database's message, 'at' => when], null =
     * nothing failed (the site is on, or the migration has not run yet). A site that was on already keeps the setting when
     * the migration fails there (N319-1) – the failure is still recorded, so System status explains the missing key.
     *
     * @return array{reason: string, table: string, slugs?: list<string>, error?: string, at?: string}|null
     */
    public static function failure(Settings $settings): ?array
    {
        $failure = json_decode($settings->get('slugs_switch_failed'), true);

        return is_array($failure) && is_string($failure['reason'] ?? null) ? $failure + ['table' => ''] : null;
    }

    /**
     * Settings → General on a site that could not switch (3.19): runs migration 0106 again – the same checks, the global keys
     * dropped, tags split. null when slugs are per language now and nothing failed, otherwise failure().
     *
     * N319-3: under the same database lock as Core\Migration::apply (a double submit or two administrators at once wait for
     * each other, and the second run finds the work done), with the updater's time limit, and an error of the database is
     * a failure the form shows (and System status keeps), never an error 500 after half of the form was saved.
     *
     * @return array{reason: string, table: string, slugs?: list<string>, error?: string, at?: string}|null
     */
    public static function enableEverywhere(Db $db, Settings $settings): ?array
    {
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(300);
        }
        $lock = Migration::lockName($db);
        if ((int) $db->value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
            self::$perLanguage = null;

            return self::perLanguage($db) && self::failure($settings) === null ? null : ['reason' => 'busy', 'table' => ''];
        }
        try {
            (require KALETA_SYSTEM . '/sql/migrace/' . self::MIGRATION . '.php')($db, $settings);
        } catch (\Throwable $e) {
            $failure = ['reason' => 'database', 'table' => '', 'error' => mb_substr($e->getMessage(), 0, 200), 'at' => date('Y-m-d H:i:s')];
            $settings->set('slugs_switch_failed', (string) json_encode($failure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            $db->run('SELECT RELEASE_LOCK(?)', [$lock]);
            self::$perLanguage = null;
        }

        return self::failure($settings) ?? (self::perLanguage($db) ? null : ['reason' => 'database', 'table' => '']);
    }

    /**
     * The owner's words for a failure() (English source text for t()): what is wrong and what to do. $withError = false
     * (N319-7): without the database's own message, which can name the database user and host – for what leaves the site
     * (the monitoring stav.json, the fleet heartbeat, the monthly report); System status and Settings → General show it.
     * $on = the site is per language already and only a key is missing (N319-1).
     */
    public static function failureText(array $failure, bool $withError = true, bool $on = false): string
    {
        return match (true) {
            $failure['reason'] === 'busy' => t('Another request is changing the keys of the addresses right now. Wait a minute, then look at System status – or save Settings → General once more.'),
            $failure['reason'] === 'shared' && $on => t('Two items of one language version share an address (%s), so the database key that keeps addresses unique within a language is missing. Give one of each pair another address, then save Settings → General once more – the same address in every language stays on.', implode(', ', (array) ($failure['slugs'] ?? []))),
            $failure['reason'] === 'shared' => t('Two items of one language version share an address (%s), so addresses cannot become unique per language yet. Give one of each pair another address, then switch on “The same address in every language” in Settings → General.', implode(', ', (array) ($failure['slugs'] ?? []))),
            default => ($on
                ? t('The database refused to change the keys of the addresses, so an older key that keeps addresses unique across all languages is still there. Ask the hosting whether the database user may change tables (ALTER), then save Settings → General once more.')
                : t('The database refused to change the keys of the addresses, so addresses are still unique across all languages. Ask the hosting whether the database user may change tables (ALTER), then switch on “The same address in every language” in Settings → General.'))
                . ($withError && (string) ($failure['error'] ?? '') !== '' ? ' ' . t('The database said: %s', (string) $failure['error']) : ''),
        };
    }

    /** The type word of a slug made from a number (fromId): table => [id column, word]. */
    public const array FALLBACK = ['stranky' => ['ids', 'page'], 'novinky' => ['idc', 'news'], 'kategorie' => ['idt', 'category'], 'kolekce_polozky' => ['idp', 'item']];

    /**
     * A row just created with slugify's "n-a" (a title with nothing to transliterate – Hebrew, Chinese, emoji) gets its type
     * and number instead: page-12, news-40, category-3, item-7 (unique in its scope like any slug). Only right after an
     * insert – an existing slug never changes. Returns the slug the row has now.
     */
    public static function fromId(Db $db, string $table, int $id): string
    {
        [$key, $word] = self::FALLBACK[$table] ?? throw new \InvalidArgumentException('Unknown table ' . $table);
        $row = $db->one("SELECT * FROM {{$table}} WHERE {$key} = ?", [$id]) ?? [];
        $current = (string) ($row['seo_link'] ?? '');
        if (preg_match('/^n-a(-\d+)?$/D', $current) !== 1) {
            return $current;
        }
        $isTaken = $table === 'kolekce_polozky'
            ? fn (string $s): bool => $db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND seo_link = ? AND idp <> ? LIMIT 1', [(int) $row['idk'], (string) $row['jazyk'], $s, $id]) !== null
            : fn (string $s): bool => self::taken($db, $table, $s, (string) ($row['jazyk'] ?? ''), $id);
        $slug = self::makeUnique($word . '-' . $id, $isTaken, 120);
        $db->update($table, ['seo_link' => $slug], [$key => $id]);

        return $slug;
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
        $added = [];
        try {
            foreach (self::TABLES as $table => [, $global]) {
                if (!self::hasKey($db, $table, $global)) {
                    $db->run("ALTER TABLE {{$table}} ADD UNIQUE KEY {$global} (seo_link)");
                    $added[$table] = $global;
                }
            }
        } catch (\PDOException $e) {
            // a second language version took a shared slug in the meantime: per language stays on – and the keys this call
            // already put back go again, so no table refuses a shared slug while the setting says they are allowed (N39-6)
            foreach ($added as $table => $global) {
                $db->run("ALTER TABLE {{$table}} DROP INDEX {$global}");
            }
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
        // N314-4: a tag's address cannot be changed in Tags and topics – two tags are merged there instead
        return [isset($shared['stitky']) ? 'The same address in every language cannot be switched off while two language versions share an address: %s. Change one of each pair first – two tags with the same address can be merged in Tags and topics.'
            : 'The same address in every language cannot be switched off while two language versions share an address: %s. Change one of each pair first.',
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
        return $db->value('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1', [$db->tableName($table), $key]) !== null;
    }
}
