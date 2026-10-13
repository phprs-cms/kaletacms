<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The deprecation log of the old table names (3.14, "Ready for 4.0"): which add-on file still writes a Czech placeholder
 * ({novinky}) or a Czech table name (insert('novinky', …)) that 4.0 renames. The placeholders keep working through 4.x
 * (docs/design/4.0-data-model.md §4), but the owner should know which add-ons to ask about before the update.
 *
 * Kept cheap because Db::sql() runs for every query:
 *  - nothing is looked at until Extension\Registry loads an add-on ($watching) – without add-ons no code outside system/
 *    runs a query at all (custom PHP layouts are not used since 1.6);
 *  - only a legacy name calls note(), which reads a short backtrace once per query and remembers per request what it saw;
 *  - Kaleta's own code (system/, tools/, the entry scripts in the root) is never logged, even while it still uses the
 *    Czech placeholders itself;
 *  - one small JSON file, written at most once per file, name and day: storage/log/legacy-table-names.json (outside the
 *    page cache, which Cache::clear() empties on every save).
 */
final class LegacyNames
{
    public const string FILE = KALETA_ROOT . '/storage/log/legacy-table-names.json';

    /** Entries not seen for this many days are dropped: the add-on was changed or removed. */
    public const int KEEP_DAYS = 30;

    /** At most this many files are remembered, so the file stays small whatever an add-on does. */
    public const int MAX_FILES = 200;

    /** Set by Extension\Registry::boot() when add-ons are switched on. */
    public static bool $watching = false;

    /** @var array<string, string|false> caller file => its path relative to the root, false = Kaleta's own code */
    private static array $callers = [];

    /** @var array<string, true> "file|name" already handled in this request */
    private static array $seen = [];

    /** @var array<string, array{names: array<string, string>}>|null the log as read in this request */
    private static ?array $log = null;

    /**
     * Called by Db for legacy names it resolved: finds the code that asked (the first frame outside Db.php) and logs it
     * when it is not Kaleta's own. Never throws – a query must not fail because of its log.
     *
     * @param list<string> $names legacy names, e.g. ['novinky']
     */
    public static function note(array $names): void
    {
        try {
            $file = '';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
                $path = $frame['file'] ?? '';
                if ($path !== '' && $path !== __FILE__ && $path !== __DIR__ . '/Db.php') {
                    $file = $path;
                    break;
                }
            }
            $relative = self::$callers[$file] ??= self::external($file, KALETA_ROOT);
            if ($relative === false) {
                return;
            }
            foreach (array_unique($names) as $name) {
                if (!isset(self::$seen[$relative . '|' . $name])) {
                    self::$seen[$relative . '|' . $name] = true;
                    self::record($relative, $name, date('Y-m-d'));
                }
            }
        } catch (\Throwable) {
            // the log is a hint for the owner, never a reason for a query to fail
        }
    }

    /**
     * The path of a file relative to the root when it is not Kaleta's own code (an add-on in extensions/, a layout
     * in layout/), false otherwise: system/, tools/, a file in the root itself (index.php, admin.php), or code that is not
     * a file under the root (the command line, eval) – nothing an owner could ask a developer about.
     */
    public static function external(string $file, string $root): string|false
    {
        $root = rtrim($root, '/') . '/';
        if ($file === '' || !str_starts_with($file, $root)) {
            return false;
        }
        $relative = substr($file, strlen($root));

        return !str_contains($relative, '/') || str_starts_with($relative, 'system/') || str_starts_with($relative, 'tools/') ? false : $relative;
    }

    /** Remembers that the file used the name today; a name already remembered for today is not written again. */
    public static function record(string $relative, string $name, string $day, string $file = self::FILE): void
    {
        $log = self::read($file);
        if (($log[$relative]['names'][$name] ?? '') === $day) {
            return;
        }
        $log[$relative]['names'][$name] = $day;
        $oldest = date('Y-m-d', (int) strtotime($day . ' -' . self::KEEP_DAYS . ' days'));
        foreach ($log as $path => $entry) {
            $entry['names'] = array_filter($entry['names'], fn (string $seen): bool => $seen >= $oldest);
            if ($entry['names'] === []) {
                unset($log[$path]);
            } else {
                $log[$path] = $entry;
            }
        }
        if (count($log) > self::MAX_FILES) {
            uasort($log, fn (array $a, array $b): int => max($b['names']) <=> max($a['names']));
            $log = array_slice($log, 0, self::MAX_FILES, true);
        }
        if ($file === self::FILE) {
            self::$log = $log;
        }
        if (is_dir(dirname($file))) {
            @file_put_contents($file, (string) json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        }
    }

    /**
     * What the log holds, the most recent first: each file with the add-on it belongs to, the legacy names it used and the
     * day it last used one.
     *
     * @return list<array{file: string, addon: string, names: list<string>, last: string}>
     */
    public static function recent(string $file = self::FILE): array
    {
        $oldest = date('Y-m-d', strtotime('-' . self::KEEP_DAYS . ' days'));
        $out = [];
        foreach (self::read($file) as $path => $entry) {
            $names = array_filter($entry['names'], fn (string $seen): bool => $seen >= $oldest);
            if ($names !== []) {
                ksort($names);
                $out[] = ['file' => (string) $path, 'addon' => self::addon((string) $path), 'names' => array_map('strval', array_keys($names)), 'last' => max($names)];
            }
        }
        usort($out, fn (array $a, array $b): int => [$b['last'], $a['file']] <=> [$a['last'], $b['file']]);

        return $out;
    }

    /** The add-on a file belongs to (extensions/<slug>/…), '' for a file elsewhere (a layout). */
    public static function addon(string $relative): string
    {
        return preg_match('#^extensions/([^/]+)/#', $relative, $m) === 1 ? $m[1] : '';
    }

    /** Forgets what this request read and saw (tests). */
    public static function reset(): void
    {
        self::$callers = [];
        self::$seen = [];
        self::$log = null;
    }

    /** @return array<string, array{names: array<string, string>}> */
    private static function read(string $file): array
    {
        if (self::$log !== null && $file === self::FILE) {
            return self::$log;
        }
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $log = [];
        foreach (is_array($data) ? $data : [] as $path => $entry) {
            $names = is_array($entry['names'] ?? null) ? array_filter($entry['names'], fn ($d, $n): bool => is_string($d) && is_string($n) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) === 1, ARRAY_FILTER_USE_BOTH) : [];
            if (is_string($path) && $names !== []) {
                $log[$path] = ['names' => $names];
            }
        }
        if ($file === self::FILE) {
            self::$log = $log;
        }

        return $log;
    }
}
