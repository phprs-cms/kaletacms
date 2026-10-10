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
 */
final class Migration
{
    private const string FOLDER = KALETA_SYSTEM . '/sql/migrace';

    /** MySQL errors that mean "this change is already in the database": a table, column, index or foreign key exists, a dropped column or index is missing. */
    private const array ALREADY_APPLIED = [1050, 1060, 1061, 1022, 1826, 1091];

    /** The PHP data migrations (NNNN-description.php without the extension); tools/unit-tests.php checks it against the files. */
    public const array DATA = ['0034-modal-popups', '0073-feature-defaults', '0074-imported-content-recheck', '0084-media-htaccess'];

    /**
     * Is there anything to apply: a newer structure, or a data migration that has not run on this site yet? The public site
     * compares with KALETA_DB_VERSION (no file lookup on every visit), the admin with the files themselves (latest()).
     */
    public static function pending(Settings $settings, ?int $latest = null): bool
    {
        return $settings->int('db_version') < ($latest ?? KALETA_DB_VERSION) || array_diff(self::DATA, explode(',', $settings->get('data_migrations'))) !== [];
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
        @file_put_contents(KALETA_ROOT . '/storage/log/chyby.log', sprintf("[%s] The database migration failed: %s\n", date('c'), $e->getMessage()), FILE_APPEND | LOCK_EX);
    }

    /** @return list<string> names of the migrations just applied */
    public static function apply(Db $db, Settings $settings): array
    {
        // lock: both the admin and the site run migrations, two concurrent requests must not apply the same change twice
        $lock = 'kaleta_migrace_' . $db->prefix;
        if ((int) $db->value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
            return [];
        }
        $applied = [];
        try {
            $version = max(1, (int) $db->value("SELECT MAX(CAST(hodnota AS UNSIGNED)) FROM {nastaveni} WHERE promenna IN ('db_version', 'verze_db')"));
            foreach (self::files() as $number => $file) {
                if ($number <= $version) {
                    continue;
                }
                if (str_ends_with($file, '.php')) {
                    $settings->set('db_version', (string) $number); // runs below with the others that have not run yet
                    continue;
                }
                foreach (self::statements((string) file_get_contents($file), $db->prefix) as $sql) {
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
            foreach (self::files() as $number => $file) {
                if (str_ends_with($file, '.php') && !in_array(basename($file, '.php'), $done, true)) {
                    (require $file)($db, $settings);
                    $done[] = basename($file, '.php');
                    $settings->set('data_migrations', implode(',', $done));
                    $applied[] = basename($file, '.php');
                }
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
     * Splits an SQL script into statements and replaces the prefix "ka_" with the installation's prefix.
     *
     * @return list<string>
     */
    public static function statements(string $sql, string $prefix): array
    {
        // constraint names must be unique in the database - they get the prefix too
        $sql = preg_replace('/\b((?:CONSTRAINT|DROP FOREIGN KEY)\s+)fk_/', '$1' . $prefix . 'fk_', $sql) ?? $sql;
        $sql = preg_replace('/\bka_(?=[a-z])/', $prefix, $sql) ?? $sql;
        // a statement ends with a semicolon at the end of a line; only a comment may follow the semicolon
        $statements = preg_split('/;[ \t]*(--[^\n]*)?(\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map(trim(...), $statements), function (string $statement): bool {
            return trim((string) preg_replace('/^\s*--.*$/m', '', $statement)) !== '';
        }));
    }
}
