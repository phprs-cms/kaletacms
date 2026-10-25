<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * "Ready for 4.0" (3.14): what on this site would get in the way of the 4.0 data model (docs/design/4.0-data-model.md
 * §1.3, §3, §4) – the database server and the rights of its user, add-ons that write the old table names, tags used in
 * several languages, redirects whose old addresses collide once compared exactly, and slugs shared across languages.
 *
 * Read-only: nothing here changes the site. System status shows it as its own section (kept out of Health::checks, so
 * monitoring and the fleet console do not turn amber for a release that is not out yet); get_health gives Claude the same
 * under ready_for_4_0. Every check is quick: indexed queries, and a file scan bounded by SCAN_MAX_FILES and SCAN_MAX_BYTES.
 */
final class Readiness
{
    /** What the update to 4.0 does with the tables: RENAME TABLE needs ALTER and DROP on the old name, CREATE and INSERT on the new one; keys need ALTER. */
    public const array PRIVILEGES = ['ALTER', 'CREATE', 'DROP', 'INSERT'];

    /** The oldest database servers 4.0 supports (data model §0). */
    public const string MIN_MYSQL = '8.0';

    public const string MIN_MARIADB = '10.6';

    /** Where code that may name tables lives outside system/: add-ons, and custom layouts left from before 1.6. */
    public const array SCAN_FOLDERS = ['extensions', 'layout'];

    public const array SCAN_TYPES = ['php', 'phtml', 'inc', 'sql'];

    /** Folders of bundled libraries – they never name Kaleta's tables, and they can be large. */
    private const array SCAN_SKIP = ['vendor', 'node_modules', '.git'];

    public const int SCAN_MAX_FILES = 2000;

    public const int SCAN_MAX_BYTES = 20 * 1024 * 1024;

    /** A larger file is read only this far. */
    public const int SCAN_FILE_BYTES = 2 * 1024 * 1024;

    /** Entries of any kind walked at most (N314-3: a folder full of images or caches must not make the page slow). */
    public const int SCAN_MAX_ENTRIES = 20000;

    /** Matches counted in one file at most (N314-3: a generated 2 MB file must not fill the memory with matches). */
    public const int SCAN_MAX_MATCHES = 500;

    /** At most this many lines in a list. */
    public const int MAX_LISTED = 50;

    /**
     * The checks in the order System status shows them. key = stable name for get_health; stav = ok | varovani | info
     * (info is never a problem: something to know before 4.0); seznam = the files, tags or addresses behind it.
     *
     * @return list<array{key: string, nazev: string, stav: string, info: string, seznam: list<string>}>
     */
    public static function checks(App $app): array
    {
        $db = $app->db();
        $out = [];
        $add = function (string $key, string $name, string $state, string $info, array $list = []) use (&$out): void {
            $out[] = ['key' => $key, 'nazev' => $name, 'stav' => $state, 'info' => $info, 'seznam' => array_values(array_map('strval', $list))];
        };

        // the database server
        $version = (string) $db->value('SELECT VERSION()');
        [$server, $number, $supported] = self::databaseServer($version);
        $add('database_version', t('Database server'), $supported ? 'ok' : 'varovani', $supported
            ? t('%s %s – supported by Kaleta 4.0', $server, $number)
            : t('%s %s – Kaleta 4.0 needs MySQL %s or newer, or MariaDB %s or newer. Ask the hosting to upgrade the database server before you update.', $server, $number, self::MIN_MYSQL, self::MIN_MARIADB));

        // the rights of the database user
        $missing = null;
        try {
            $grants = array_map(fn (array $r): string => (string) reset($r), $db->all('SHOW GRANTS FOR CURRENT_USER'));
            $missing = self::missingPrivileges($grants, (string) $db->value('SELECT DATABASE()'));
        } catch (\Throwable) {
            // some hosts hide the grants – the check says so, the page stays
        }
        $add('database_privileges', t('Database user rights'), match (true) {
            $missing === null => 'info',
            $missing === [] => 'ok',
            default => 'varovani',
        }, match (true) {
            $missing === null => t('Could not check: the database server did not show the rights of the user. The update to Kaleta 4.0 renames tables and changes keys, so the user needs %s on this database – ask the hosting if you are not sure.', implode(', ', self::PRIVILEGES)),
            $missing === [] => t('the user may rename tables and change keys (%s)', implode(', ', self::PRIVILEGES)),
            default => t('The user lacks %s on this database. The update to Kaleta 4.0 renames tables and changes keys – ask the hosting to grant them.', implode(', ', $missing)),
        });

        // 3.15: which names the tables have – this release runs on both, so it is the version 4.0 can roll back to
        $add('table_names', t('Table names'), 'ok', $db->englishTables()
            ? t('the tables already have their English names (Kaleta 4.0 renamed them) – this version works with them too')
            : t('the tables have today’s names; Kaleta 4.0 renames them to the English ones, and this version already works with both'));

        // add-ons and custom layouts that write the old table names
        $scan = self::scan(KALETA_ROOT, self::SCAN_FOLDERS, $db->prefix);
        $log = LegacyNames::recent();
        $list = [];
        $physical = 0;
        foreach ($scan['files'] as $f) {
            $physical += $f['tables'];
            $list[] = $f['file'] . ' – ' . implode(', ', array_filter([
                $f['placeholders'] > 0 ? t('%d× an old placeholder', $f['placeholders']) : '',
                $f['tables'] > 0 ? t('%d× a table name with the prefix', $f['tables']) : '',
            ])) . ' (' . implode(', ', $f['names']) . ')';
        }
        foreach ($log as $entry) {
            $list[] = ($entry['addon'] !== '' ? t('add-on %s', $entry['addon']) . ': ' : '') . t('%s used %s while running (last on %s)', $entry['file'],
                implode(', ', array_map(fn (string $n): string => '{' . $n . '}', $entry['names'])), format_date($entry['last']));
        }
        $add('legacy_table_names', t('Add-ons and old table names'), $list === [] ? ($scan['complete'] ? 'ok' : 'info') : 'varovani', match (true) {
            $list === [] && $scan['complete'] => t('no add-on or custom layout writes the old Czech table names'),
            $list === [] => t('Nothing found in the first %d files – the add-ons are too large to check them all here.', $scan['seen']),
            $physical > 0 => t('Some add-ons write the table names with the prefix (e.g. %s). Kaleta 4.0 renames the tables, so these queries will stop working – ask the add-on’s developer to write the placeholders with the English names from system/sql/names.php ({news} instead of {novinky}).', $db->prefix . 'novinky'),
            default => t('Some add-ons write the old Czech placeholders (e.g. {novinky}). They keep working in Kaleta 4.0, but are planned to go – ask the add-on’s developer to write the English names from system/sql/names.php ({news} instead of {novinky}).'),
        } . ($list !== [] && !$scan['complete'] ? ' ' . t('Only the first %d files were checked.', $scan['seen']) : ''), array_slice($list, 0, self::MAX_LISTED));

        // tags used by news items in more than one language
        $tags = $db->all('SELECT t.nazev FROM {stitky} t JOIN {novinky_stitky} ns ON ns.ids = t.ids JOIN {novinky} n ON n.idc = ns.idc
            GROUP BY t.ids, t.nazev HAVING COUNT(DISTINCT n.jazyk) > 1 ORDER BY t.nazev LIMIT ' . (self::MAX_LISTED + 1));
        $tagCount = count($tags) > self::MAX_LISTED ? (int) $db->value('SELECT COUNT(*) FROM (SELECT ns.ids FROM {novinky_stitky} ns JOIN {novinky} n ON n.idc = ns.idc
            GROUP BY ns.ids HAVING COUNT(DISTINCT n.jazyk) > 1) AS shared') : count($tags);
        $add('tags_across_languages', t('Tags in several languages'), $tagCount === 0 ? 'ok' : 'info', $tagCount === 0
            ? t('no tag is used by news items in more than one language')
            : t('%d tag(s) are used by news items in more than one language. They get one tag per language as soon as addresses are unique per language version (Settings → General, see Addresses in every language above).', $tagCount),
            array_slice(array_column($tags, 'nazev'), 0, self::MAX_LISTED));

        // redirects whose old addresses collide once compared exactly
        try {
            $pending = RedirectSources::pending($db);
            $collisions = RedirectSources::collisions($db);
            $groups = $collisions === [] ? 0 : RedirectSources::collisionCount($db);
            $add('redirect_sources', t('Old addresses of redirects'), $groups > 0 ? 'varovani' : ($pending > 0 ? 'info' : 'ok'), ($groups > 0
                ? t('%d group(s) of redirects have old addresses that counted as one address before Kaleta 3.19 – they differ only in letter case, accents, percent-encoding or slashes. Redirects now compare the old address exactly: delete the extra ones in Redirects, or merge them into one.', $groups)
                : t('every redirect is looked up by its exact old address'))
                . ($pending > 0 ? ' ' . t('%d redirect(s) are still being prepared by the background jobs.', $pending) : ''),
                array_map(fn (array $g): string => implode(' = ', array_map(fn (string $s): string => '/' . $s, $g)), $collisions));
        } catch (\Throwable) {
            // migration 0094 has not run yet (it runs on the next visit) – System status must still open
            $add('redirect_sources', t('Old addresses of redirects'), 'info', t('Could not check yet – the database update of 3.14 has not run. Open this page again in a minute.'));
        }

        // slugs shared by versions in different languages
        $perLanguage = Slug::perLanguage($db);
        $shared = 0;
        foreach (['stranky', 'novinky', 'kategorie'] as $table) {
            $shared += (int) $db->value('SELECT COUNT(*) FROM (SELECT seo_link FROM {' . $table . '} GROUP BY seo_link HAVING COUNT(DISTINCT jazyk) > 1) AS shared');
        }
        // 3.19: on for every site that could switch (migration 0106); one that could not is a warning on System status above
        $add('shared_slugs', t('Addresses shared by language versions'), $shared === 0 ? 'ok' : 'info', match (true) {
            $shared === 0 && !$perLanguage => t('every page, news item and category has an address of its own, in every language – the update to 3.19 could not switch on the same address in every language yet (see Addresses in every language above)'),
            $shared === 0 => t('“The same address in every language” is on, and no address is shared yet'),
            $perLanguage => t('%d address(es) are shared by versions in different languages – allowed, “The same address in every language” is on, as for every site since Kaleta 3.19', $shared),
            default => t('%d address(es) are shared by versions in different languages although “The same address in every language” is off – Kaleta 4.0 allows it, nothing to do', $shared),
        });

        // 3.15 (N42): the key of the encrypted secrets – in config.php, not in the database (Core\Vault); never the key itself
        $vault = Vault::state($app->settings());
        $add('vault_key', t('Encryption key'), ['config' => 'ok', 'database' => 'info'][$vault] ?? 'varovani', Vault::describe($vault));

        return $out;
    }

    /**
     * Checks whose warning stops the update to 4.0 (3.15): with an unsupported database server or without the rights to
     * rename tables the update cannot succeed. Every other warning (old table names in an add-on…) only asks for care.
     */
    public const array BLOCKERS = ['database_version', 'database_privileges'];

    /**
     * The names of the checks that stop the update to 4.0 now – empty when it may go ahead.
     *
     * @param list<array{key: string, nazev: string, stav: string, info: string, seznam: list<string>}> $checks
     * @return list<string>
     */
    public static function blockers(array $checks): array
    {
        return array_values(array_map(fn (array $c): string => $c['nazev'], array_filter($checks, fn (array $c): bool => $c['stav'] === 'varovani' && in_array($c['key'], self::BLOCKERS, true))));
    }

    /** The worst state: varovani when a check warns, ok otherwise (info is never a problem). */
    public static function summary(array $checks): string
    {
        return in_array('varovani', array_column($checks, 'stav'), true) ? 'varovani' : 'ok';
    }

    /**
     * The server and version from SELECT VERSION() and whether 4.0 supports it: "8.0.36" → MySQL, "10.6.12-MariaDB-log"
     * → MariaDB; the "5.5.5-" some servers put in front of a MariaDB version is skipped.
     *
     * @return array{0: string, 1: string, 2: bool} [MySQL | MariaDB, version, supported]
     */
    public static function databaseServer(string $version): array
    {
        $mariaDb = stripos($version, 'mariadb') !== false;
        $number = preg_match('/^(?:5\.5\.5-)?(\d+\.\d+(?:\.\d+)?)/', trim($version), $m) === 1 ? $m[1] : trim($version);

        return [$mariaDb ? 'MariaDB' : 'MySQL', $number, preg_match('/^\d/', $number) === 1 && version_compare($number, $mariaDb ? self::MIN_MARIADB : self::MIN_MYSQL, '>=')];
    }

    /**
     * Which of PRIVILEGES the user lacks on the schema, from the lines of SHOW GRANTS FOR CURRENT_USER: grants on *.* and
     * on the schema count, also through a pattern (`shop\_%`.*), and ALL [PRIVILEGES] covers every one. null = it cannot be
     * told: no grant line could be read, or something is missing while a role is granted (its rights are not listed).
     *
     * @param list<string> $grants
     * @return list<string>|null
     */
    public static function missingPrivileges(array $grants, string $schema): ?array
    {
        $have = [];
        $read = false;
        $roles = false;
        foreach ($grants as $line) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', trim($line), $m) !== 1) {
                $roles = $roles || preg_match('/^GRANT\s+\S+\s+TO\s/i', trim($line)) === 1; // GRANT `role`@`%` TO …
                continue;
            }
            $read = true;
            if (preg_match('/^(`(?:[^`]|``)+`|[^.\s`]+)\.(`(?:[^`]|``)+`|[^.\s`]+)$/', $m[2], $object) !== 1) {
                continue; // ON PROCEDURE …, ON FUNCTION …
            }
            $unquote = fn (string $name): string => str_starts_with($name, '`') ? str_replace('``', '`', substr($name, 1, -1)) : $name;
            if ($unquote($object[2]) !== '*' || !self::schemaMatches($unquote($object[1]), $schema)) {
                continue; // a grant on single tables or on another database
            }
            $privileges = array_map(fn (string $p): string => strtoupper(trim((string) preg_replace('/\s+/', ' ', $p))),
                explode(',', (string) preg_replace('/\([^)]*\)/', '', $m[1]))); // SELECT (column) has commas of its own
            if (array_intersect($privileges, ['ALL', 'ALL PRIVILEGES']) !== []) {
                return [];
            }
            $have = [...$have, ...$privileges];
        }
        $missing = array_values(array_diff(self::PRIVILEGES, $have));
        if (!$read || ($missing !== [] && $roles)) {
            return null;
        }

        return $missing;
    }

    /** Whether a grant's database (`*`, a name, or a pattern with % and _; \_ and \% are literal) covers the schema. */
    public static function schemaMatches(string $grant, string $schema): bool
    {
        if ($grant === '*') {
            return true;
        }
        $pattern = '';
        for ($i = 0, $n = strlen($grant); $i < $n; $i++) {
            $c = $grant[$i];
            $pattern .= match (true) {
                $c === '\\' && $i + 1 < $n => preg_quote($grant[++$i], '/'),
                $c === '%' => '.*',
                $c === '_' => '.',
                default => preg_quote($c, '/'),
            };
        }

        return preg_match('/^' . $pattern . '$/iDs', $schema) === 1;
    }

    /**
     * Files in the folders (under $root) that write a legacy table name: an old placeholder ({novinky}) or the table with
     * the site's prefix (ka_novinky) – only names of tables that 4.0 renames (Names::legacy). Read-only and bounded: at
     * most $maxFiles files of SCAN_TYPES and $maxBytes in all, each read up to SCAN_FILE_BYTES; complete = everything
     * was read.
     *
     * @param list<string> $folders
     * @return array{files: list<array{file: string, placeholders: int, tables: int, names: list<string>}>, complete: bool, seen: int}
     */
    public static function scan(string $root, array $folders, string $prefix, int $maxFiles = self::SCAN_MAX_FILES, int $maxBytes = self::SCAN_MAX_BYTES): array
    {
        $names = array_keys(Names::legacy());
        usort($names, fn (string $a, string $b): int => strlen($b) <=> strlen($a)); // novinky_stitky before novinky
        $alternatives = implode('|', array_map(fn (string $n): string => preg_quote($n, '/'), $names));
        // without a prefix a bare word like "stranky" would match too much – then only placeholders are looked for
        $regex = '/\{(' . $alternatives . ')\}' . ($prefix !== '' ? '|\b' . preg_quote($prefix, '/') . '(' . $alternatives . ')\b' : '') . '/';
        $root = rtrim($root, '/');
        $paths = [];
        $walked = 0;
        $complete = true;
        foreach ($folders as $folder) {
            if (!is_dir($root . '/' . $folder)) {
                continue;
            }
            // N314-3: a folder PHP may not open is skipped (CATCH_GET_CHILD) and an unreadable top folder ends this folder
            // only – System status and get_health must open whatever the add-on folders look like
            try {
                $walk = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS),
                    fn (\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), self::SCAN_SKIP, true)),
                ), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
                foreach ($walk as $file) {
                    if (++$walked > self::SCAN_MAX_ENTRIES || count($paths) >= $maxFiles + 1) {
                        $complete = false;
                        break 2;
                    }
                    if ($file instanceof \SplFileInfo && $file->isFile() && in_array(strtolower($file->getExtension()), self::SCAN_TYPES, true)) {
                        $paths[] = $file->getPathname();
                    }
                }
            } catch (\UnexpectedValueException|\RuntimeException) {
                $complete = false;
            }
        }
        sort($paths);
        $complete = $complete && count($paths) <= $maxFiles;
        $files = [];
        $bytes = 0;
        $seen = 0;
        foreach (array_slice($paths, 0, $maxFiles) as $path) {
            $size = (int) @filesize($path);
            if ($bytes + min($size, self::SCAN_FILE_BYTES) > $maxBytes) {
                $complete = false;
                break;
            }
            $seen++;
            $bytes += min($size, self::SCAN_FILE_BYTES);
            $complete = $complete && $size <= self::SCAN_FILE_BYTES;
            $text = (string) @file_get_contents($path, false, null, 0, self::SCAN_FILE_BYTES);
            $found = ['placeholders' => 0, 'tables' => 0, 'names' => []];
            $offset = 0;
            // one match at a time, at most SCAN_MAX_MATCHES (N314-3) – never every match of a large file at once
            while ($found['placeholders'] + $found['tables'] < self::SCAN_MAX_MATCHES && preg_match($regex, $text, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $m[0][1] + max(1, strlen($m[0][0]));
                $placeholder = ($m[1][0] ?? '') !== ''; // a placeholder match always fills group 1, a table name never does
                $found[$placeholder ? 'placeholders' : 'tables']++;
                if (count($found['names']) < 5) {
                    $found['names'][$placeholder ? '{' . $m[1][0] . '}' : $prefix . $m[2][0]] = true;
                }
            }
            if ($found['placeholders'] + $found['tables'] === 0) {
                continue;
            }
            $files[] = ['file' => substr($path, strlen($root) + 1), 'placeholders' => $found['placeholders'], 'tables' => $found['tables'],
                'names' => array_keys($found['names'])];
        }

        return ['files' => $files, 'complete' => $complete, 'seen' => $seen];
    }
}
