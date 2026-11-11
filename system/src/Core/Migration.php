<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Database structure updates.
 *
 * The files system/sql/migrace/NNNN-description.sql run in ascending order, the number of the last one applied
 * is in ka_nastaveni (db_version). A new installation gets the complete schema.sql and the highest number right away.
 *
 * A data migration (2.0) is NNNN-description.php returning function (Db $db, Settings $settings): void, written to run
 * again safely. The code of a release before 2.0 that runs an update request knows only the .sql files and moves db_version
 * past a PHP migration without running it; so every PHP migration runs once by its name (data_migrations, 2.2) –
 * whatever db_version says – on the first request of the new code.
 *
 * 3.15 (the bridge to 4.0): the files name the tables as they were (`ka_novinky`). On a database whose tables 4.0 already
 * renamed they are written with the English names (statements(), $english), so a migration of this release that has not
 * run yet still finds its tables. A database that 4.0 migrated and that was rolled back to 3.15 has a higher db_version
 * than any file here: nothing runs again, nothing is undone.
 *
 * 3.34 (review M3): a data migration with long work (0120) moves in bounded runs: when time runs out it puts its name into
 * the setting data_migrations_unfinished, and every request runs it again until it takes its name out – also after the
 * update request of an older release marked it done. Such a continuation does not wait for the lock: a request that finds
 * another run busy goes on at once.
 */
final class Migration
{
    private const string FOLDER = KALETA_SYSTEM . '/sql/migrace';

    /**
     * How long one run of a resumable data migration may work (0120 reads it): the full budget from cron and the
     * administration, VISITOR_SECONDS when a visitor's page request carries it (3.34 third review F2 – Front\Kernel).
     */
    public static float $seconds = 15.0;

    /** The budget of a data migration's continuation inside a visitor's page request. */
    public const float VISITOR_SECONDS = 2.0;

    /** MySQL errors that mean "this change is already in the database": a table, column, index or foreign key exists, a dropped column or index is missing. */
    private const array ALREADY_APPLIED = [1050, 1060, 1061, 1022, 1826, 1091];

    /** The PHP data migrations (NNNN-description.php without the extension); tools/unit-tests.php checks it against the files. */
    public const array DATA = ['0034-modal-popups', '0073-feature-defaults', '0074-imported-content-recheck', '0084-media-htaccess', '0089-arabic-search-index', '0098-media-htaccess-case', '0106-addresses-per-language', '0120-applications-move'];

    /**
     * Is there anything to apply: a newer structure, or a data migration that has not run on this site yet? The public site
     * compares with KALETA_DB_VERSION (no file lookup on every visit), the admin with the files themselves (latest()).
     */
    public static function pending(Settings $settings, ?int $latest = null): bool
    {
        return $settings->int('db_version') < ($latest ?? KALETA_DB_VERSION) || array_diff(self::DATA, explode(',', $settings->get('data_migrations'))) !== []
            || $settings->get('data_migrations_unfinished') !== '';
    }

    /**
     * Only the continuation of an unfinished data migration is left (3.34 review M3): no lock wait, nothing else to apply.
     * Read from the database, not through Settings – its cache must not be filled before the structure migrations run (an
     * older data migration reads keys that an .sql migration renames).
     */
    public static function onlyUnfinished(Db $db): bool
    {
        $values = $db->pairs("SELECT promenna, hodnota FROM {nastaveni} WHERE promenna IN ('data_migrations_unfinished', 'db_version', 'data_migrations')");

        return ($values['data_migrations_unfinished'] ?? '') !== '' && (int) ($values['db_version'] ?? 0) >= KALETA_DB_VERSION
            && array_diff(self::DATA, explode(',', (string) ($values['data_migrations'] ?? ''))) === [];
    }

    /** @return array<int, string> number => file, ascending */
    public static function files(): array
    {
        $files = [];
        foreach ([...(glob(self::FOLDER . '/[0-9][0-9][0-9][0-9]-*.sql') ?: []), ...(glob(self::FOLDER . '/[0-9][0-9][0-9][0-9]-*.php') ?: [])] as $file) {
            $files[(int) substr(basename($file), 0, 4)] = $file;
        }
        ksort($files);

        return $files;
    }

    public static function latest(): int
    {
        return max(1, ...array_keys(self::files()));
    }

    /** Migrations for the public site: an error is written to the log (at most once per hour) and the site keeps running. */
    public static function safe(Db $db, Settings $settings): void
    {
        try {
            self::apply($db, $settings);
        } catch (\Throwable $e) {
            self::writeError($e);
        }
    }

    public static function writeError(\Throwable $e): void
    {
        $htmlTag = KALETA_ROOT . '/storage/cache/migrace-chyba';
        if (is_file($htmlTag) && time() - (int) filemtime($htmlTag) < 3600) {
            return;
        }
        @touch($htmlTag);
        ErrorLog::write('The database migration failed: ' . ErrorLog::clean($e->getMessage()) . ' · ' . ErrorLog::context());
    }

    /**
     * The name of the database lock every run of a migration takes (apply(), and Core\Slug::enableEverywhere, which runs
     * migration 0106 again from Settings → General – N319-3).
     */
    public static function lockName(Db $db): string
    {
        return 'kaleta_migrace_' . $db->prefix;
    }

    /** @return list<string> names of the migrations just applied */
    public static function apply(Db $db, Settings $settings): array
    {
        // lock: both the admin and the site run migrations, two concurrent requests must not apply the same change twice
        $lock = self::lockName($db);
        if ((int) $db->value('SELECT GET_LOCK(?, ' . (self::onlyUnfinished($db) ? 0 : 15) . ')', [$lock]) !== 1) {
            return [];
        }
        $applied = [];
        try {
            $english = $db->detectTableNames(); // 3.15: the names the tables have now (one query, only when something is pending)
            $version = max(1, (int) $db->value("SELECT MAX(CAST(hodnota AS UNSIGNED)) FROM {nastaveni} WHERE promenna IN ('db_version', 'verze_db')"));
            foreach (self::files() as $number => $file) {
                if ($number <= $version) {
                    continue;
                }
                if (str_ends_with($file, '.php')) {
                    $settings->set('db_version', (string) $number); // runs below with the others that have not run yet
                    continue;
                }
                foreach (self::statements((string) file_get_contents($file), $db->prefix, $english) as $sql) {
                    try {
                        $db->pdo()->exec($sql);
                    } catch (\PDOException $e) {
                        // a migration interrupted halfway (outage, time limit) completes on the next attempt: changes that already
                        // happened (a table, column, index or foreign key exists / is missing) are skipped instead of a permanent error 500
                        if (!in_array((int) ($e->errorInfo[1] ?? 0), self::ALREADY_APPLIED, true)) {
                            throw $e;
                        }
                    }
                }
                $settings->set('db_version', (string) $number);
                $applied[] = basename($file, '.sql');
            }
            // data migrations by name: one that an older release skipped runs now (a site from 1.9 straight to 2.2)
            $done = array_filter(explode(',', $settings->get('data_migrations')));
            $unfinished = array_filter(explode(',', $settings->get('data_migrations_unfinished')));
            foreach (self::files() as $number => $file) {
                $name = basename($file, '.php');
                if (str_ends_with($file, '.php') && (!in_array($name, $done, true) || in_array($name, $unfinished, true))) {
                    (require $file)($db, $settings); // an unfinished one runs again (3.34 review M3) – it keeps the list itself
                    if (!in_array($name, $done, true)) {
                        $done[] = $name;
                        $settings->set('data_migrations', implode(',', $done));
                        $applied[] = $name;
                    }
                }
            }
            // a name nobody ships any more must not keep every request running migrations
            $known = array_map(fn (string $f): string => basename($f, '.php'), self::files());
            if (($stale = array_diff($unfinished, $known)) !== []) {
                $settings->set('data_migrations_unfinished', implode(',', array_diff(array_filter(explode(',', $settings->get('data_migrations_unfinished'))), $stale)));
            }
            // settings keys of 1.4.0 and older (verze_db…) written again by the release that ran the update, after migration
            // 0026 had renamed them – only once 0026 is in (older data migrations still read the old keys)
            if ((int) $db->value("SELECT MAX(CAST(hodnota AS UNSIGNED)) FROM {nastaveni} WHERE promenna IN ('db_version', 'verze_db')") >= 26) {
                OldSettingsKeys::adopt($db);
            }
        } finally {
            $db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        return $applied;
    }

    /**
     * Splits an SQL script into statements and replaces the prefix "ka_" with the installation's prefix. $english (3.15): the
     * database's tables carry their English names (4.0 renamed them) – `ka_novinky` is written as `<prefix>news`.
     *
     * @return list<string>
     */
    public static function statements(string $sql, string $prefix, bool $english = false): array
    {
        // constraint names must be unique in the database - they get the prefix too
        $sql = preg_replace('/\b((?:CONSTRAINT|DROP FOREIGN KEY)\s+)fk_/', '$1' . $prefix . 'fk_', $sql) ?? $sql;
        $sql = $english ? preg_replace_callback('/\bka_([a-z][a-z0-9_]*)/', fn (array $m): string => $prefix . Names::physical($m[1], true), $sql) ?? $sql
            : preg_replace('/\bka_(?=[a-z])/', $prefix, $sql) ?? $sql;
        // a statement ends with a semicolon at the end of a line; only a comment may follow the semicolon
        $statements = preg_split('/;[ \t]*(--[^\n]*)?(\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map(trim(...), $statements), function (string $statement): bool {
            return trim((string) preg_replace('/^\s*--.*$/m', '', $statement)) !== '';
        }));
    }
}
