<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Database backups to storage/zalohy/ (not accessible from the web). Without mysqldump - works on shared hosting too.
 */
final class Backup
{
    public const string FOLDER = KALETA_ROOT . '/storage/zalohy';
    private const int KEEP = 10;

    /**
     * The public demo (3.3.2, N24): a web request never makes, restores or hands out a backup – a dump holds the site's
     * secret key and password hashes. The demo's own snapshot and reset run from the command line and may.
     */
    public static function refusedInDemo(): bool
    {
        return Demo::active() && PHP_SAPI !== 'cli';
    }

    /** @return string name of the created file */
    public static function create(Db $db, string $reason = 'rucni'): string
    {
        if (self::refusedInDemo()) {
            throw new \RuntimeException(Demo::refusal());
        }
        if (!is_dir(self::FOLDER) && !mkdir(self::FOLDER, 0775, true)) {
            throw new \RuntimeException('The folder storage/zalohy cannot be created - check the write permissions.');
        }
        $gz = function_exists('gzopen');
        $file = 'kaleta-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]/', '', $reason) . '-' . bin2hex(random_bytes(4)) . '.sql' . ($gz ? '.gz' : '');
        $path = self::FOLDER . '/' . $file;
        $f = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
        $write = fn (string $s) => $gz ? gzwrite($f, $s) : fwrite($f, $s);

        $pdo = $db->pdo();
        $write("-- Kaleta " . KALETA_VERSION . " - database backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = $db->run('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ? ORDER BY table_name', [addcslashes($db->prefix, '_%') . '%'])->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            // temporary data is not backed up
            $structureOnly = in_array(Names::table(substr($table, strlen($db->prefix))), ['stat_navstevnici', 'kontrola_ip'], true); // also under the English names (3.15)
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(\PDO::FETCH_NUM)[1];
            $write("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            if ($structureOnly) {
                continue;
            }
            $rows = $pdo->query('SELECT * FROM `' . $table . '`', \PDO::FETCH_NUM);
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = '(' . implode(',', array_map(fn ($h): string => $h === null ? 'NULL' : (is_int($h) || is_float($h) ? (string) $h : $pdo->quote((string) $h)), $row)) . ')';
                if (count($batch) >= 200) {
                    $write("INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $write("INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        $gz ? gzclose($f) : fclose($f);

        foreach (array_slice(self::listAll(), self::KEEP) as $old) {
            @unlink(self::FOLDER . '/' . $old['soubor']);
        }

        return $file;
    }

    /**
     * Restores the database from a backup created by this class. A statement in the backup always ends with a semicolon at
     * the end of a line (values are written by PDO::quote, line breaks in texts are encoded in them), so it can be read line
     * by line without loading the whole file into memory.
     *
     * @return int number of executed statements
     * @throws \RuntimeException
     */
    public static function restore(Db $db, string $file): int
    {
        if (self::refusedInDemo()) {
            throw new \RuntimeException(Demo::refusal());
        }
        $path = self::path($file);
        if ($path === null) {
            throw new \RuntimeException('Backup does not exist.');
        }
        $gz = str_ends_with($path, '.gz');
        if ($gz && !function_exists('gzopen')) {
            throw new \RuntimeException('The server cannot read compressed backups (zlib is missing).');
        }
        // the first pass only checks (header, only tables of this installation, a complete last statement) – the database is
        // touched only when the whole file is OK; a damaged or foreign backup thus does not leave the database half restored
        $count = self::walk($path, $gz, $db->prefix, null);
        @set_time_limit(300);
        $pdo = $db->pdo();
        try {
            self::walk($path, $gz, $db->prefix, function (string $statement) use ($pdo, $db): mixed {
                // 3.15: a table restored under one name replaces its twin under the other – a backup from before 4.0 restored
                // over the renamed tables (the rollback) leaves no English copy behind, and the other way round
                $twin = self::twin($statement, $db->prefix);
                if ($twin !== null) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $twin . '`');
                }

                return $pdo->exec($statement);
            });
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $db->detectTableNames(); // the restored tables decide which names the site uses now
            // N315-12: tables the backup did not have (an older backup) or did not reach (a restore that failed half-way)
            // still carry the other naming scheme – they are renamed to the one the site uses now; a table under both names
            // stays as it is (nothing is dropped) and the event says which
            $both = self::alignTableNames($db);
            if ($both !== []) {
                Events::record($db, 'backup.tables_both_names', 'warning', t('After the restore, these tables exist under both their old and their English name: %s. Nothing was deleted – ask your developer which copy to keep.', implode(', ', $both)), ['tables' => $both]);
            }
        }

        return $count;
    }

    /**
     * After a restore (3.15, N315-12): every table of the map that exists only under the naming scheme the site does not use
     * now (Db::detectTableNames – the settings table decides) is renamed to the one it uses, in one RENAME TABLE. A table
     * under both names is left as it is (nothing is dropped – which copy holds the data is for a person to say) and is
     * returned, so the caller can report it; Status lists tables a migration would recreate empty.
     *
     * @return list<string> tables found under both names
     */
    public static function alignTableNames(Db $db): array
    {
        $english = $db->englishTables();
        $existing = array_fill_keys(array_map('strval', array_column($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?',
            [addcslashes($db->prefix, '%_\\') . '%']), 't')), true);
        [$renames, $both] = self::alignment($existing, $db->prefix, $english);
        if ($renames !== []) {
            $db->pdo()->exec('RENAME TABLE ' . implode(', ', array_map(fn (string $from, string $to): string => '`' . $from . '` TO `' . $to . '`', array_keys($renames), $renames)));
        }

        return $both;
    }

    /**
     * Which tables to rename after a restore, and which exist under both names (alignTableNames, testable without a database).
     *
     * @param array<string, true> $existing table names in the database, prefix included
     * @return array{0: array<string, string>, 1: list<string>} [old name => new name], tables under both names
     */
    public static function alignment(array $existing, string $prefix, bool $english): array
    {
        $renames = [];
        $both = [];
        foreach (Names::map() as $table => [$englishName]) {
            if ($englishName === $table) {
                continue;
            }
            [$wanted, $other] = $english ? [$prefix . $englishName, $prefix . $table] : [$prefix . $table, $prefix . $englishName];
            if (isset($existing[$other]) && isset($existing[$wanted])) {
                $both[] = $wanted;
            } elseif (isset($existing[$other])) {
                $renames[$other] = $wanted;
            }
        }

        return [$renames, $both];
    }

    /**
     * The other name of the table a DROP TABLE statement of a backup restores (3.15): `ka_news` for `ka_novinky` and back,
     * null for a table 4.0 does not rename (or any other statement).
     */
    public static function twin(string $statement, string $prefix): ?string
    {
        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`;/', $statement, $m) !== 1 || !str_starts_with($m[1], $prefix)) {
            return null;
        }
        $name = substr($m[1], strlen($prefix));
        $today = Names::table($name);
        $twin = $today === $name ? Names::english($name) : $today;

        return $twin !== $name ? $prefix . $twin : null;
    }

    /** @param (callable(string): mixed)|null $apply null = check only */
    private static function walk(string $path, bool $gz, string $prefix, ?callable $apply): int
    {
        $f = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        $first = (string) ($gz ? gzgets($f) : fgets($f));
        if (!str_starts_with($first, '-- Kaleta ')) {
            throw new \RuntimeException('The file is not a backup created by Kaleta.');
        }
        $statement = '';
        $count = 0;
        while (($row = $gz ? gzgets($f) : fgets($f)) !== false) {
            if ($statement === '' && (trim($row) === '' || str_starts_with($row, '--'))) {
                continue;
            }
            $statement .= $row;
            if (str_ends_with(rtrim($row), ';')) {
                // a backup can contain only tables of this installation
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $statement, $m) && !str_starts_with($m[2], $prefix)) {
                    throw new \RuntimeException(t('The backup contains a foreign table %s – the restore was stopped.', $m[2]));
                }
                if ($apply !== null) {
                    $apply($statement);
                }
                $statement = '';
                $count++;
            }
        }
        $gz ? gzclose($f) : fclose($f);
        if (trim($statement) !== '' || $count === 0) {
            throw new \RuntimeException('The backup is incomplete or damaged (the end of the file is missing) – the restore was stopped.');
        }

        return $count;
    }

    /** @return list<array{soubor:string, velikost:int, cas:int}> newest first */
    public static function listAll(): array
    {
        $backups = [];
        foreach (glob(self::FOLDER . '/kaleta-*.sql*') ?: [] as $path) {
            $backups[] = ['soubor' => basename($path), 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
        }
        usort($backups, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $backups;
    }

    /** Path to an existing backup by the name from the URL; null = invalid name. */
    public static function path(string $file): ?string
    {
        return preg_match('/^kaleta-[0-9a-z-]+\.sql(\.gz)?$/D', $file) && is_file(self::FOLDER . '/' . $file) ? self::FOLDER . '/' . $file : null;
    }

    /**
     * Automatic backup – called when an administrator works in the administration and from cron. Since 1.8 every day
     * something changed on the site (the change log, a new enquiry), otherwise once a week.
     */
    public static function createAutomatic(Db $db, Settings $settings): void
    {
        if (!$settings->bool('auto_backups')) {
            return;
        }
        $last = self::listAll()[0]['cas'] ?? 0;
        $age = time() - $last;
        $changed = fn (): bool => (int) $db->value('SELECT COUNT(*) FROM {protokol} WHERE cas > ?', [date('Y-m-d H:i:s', $last)]) > 0
            || (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE datum > ?', [date('Y-m-d H:i:s', $last)]) > 0;
        if ($age > 7 * 86400 || ($age > 86400 && $changed())) {
            try {
                $file = self::create($db, 'auto');
                Events::record($db, 'backup.created', 'info', t('Automatic backup %s', $file), ['file' => $file]);
            } catch (\Throwable $e) {
                // a backup must not bring down the administration; the system health page warns about a missing backup
                Events::record($db, 'backup.failed', 'error', mb_substr(t('The automatic backup failed: %s', $e->getMessage()), 0, 255));

                return;
            }
            try {
                RemoteBackup::upload($settings, (string) self::path($file)); // the result is shown by the system health page and the Backups tab
            } catch (\Throwable $e) {
                Events::record($db, 'backup.failed', 'error', mb_substr(t('The off-site copy of the backup failed: %s', $e->getMessage()), 0, 255), ['file' => $file]);
            }
        }
    }
}
