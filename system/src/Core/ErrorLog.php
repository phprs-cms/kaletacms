<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The application error log, storage/log/chyby.log (T1). One entry = one line that starts with "[<date('c')>] " – System
 * status counts the entries of the last 24 hours by that prefix and Settings → System status shows the last lines.
 *
 * A PHP deprecation is logged, never thrown (Q1): a host that upgrades PHP must not turn every page that calls a newly
 * deprecated function into an error page until the site installs an update. Each deprecation is logged once a day.
 */
final class ErrorLog
{
    /** The prefix of a deprecation entry; System status counts these apart from errors. */
    public const string DEPRECATION = 'PHP deprecation: ';

    /** The log file; a test points it elsewhere. */
    public static ?string $path = null;

    /** @var array<string, true> deprecations already logged in this request */
    private static array $seen = [];

    public static function file(): string
    {
        return self::$path ?? KALETA_ROOT . '/storage/log/chyby.log';
    }

    /** Appends one entry (line breaks inside it become spaces, so an entry stays one line). */
    public static function write(string $text): void
    {
        $line = '[' . date('c') . '] ' . trim((string) preg_replace('/[\r\n]+\s*/', ' ', $text)) . "\n";
        @file_put_contents(self::file(), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Logs a deprecation once a day (the same message from the same place): the first time today it is written, later
     * occurrences – in this request or another – are not. Returns whether it was written.
     */
    public static function deprecation(string $message, string $file, int $line): bool
    {
        $entry = self::DEPRECATION . $message . ' in ' . self::shortPath($file) . ':' . $line;
        $key = date('Y-m-d') . $entry;
        if (isset(self::$seen[$key])) {
            return false;
        }
        self::$seen[$key] = true;
        $today = '[' . date('Y-m-d');
        foreach (explode("\n", self::tail(64 * 1024)) as $row) {
            if (str_starts_with($row, $today) && str_contains($row, '] ' . $entry)) {
                return false;
            }
        }
        self::write($entry);

        return true;
    }

    /** The end of the log (at most $bytes), '' without a log. */
    public static function tail(int $bytes): string
    {
        $file = self::file();
        clearstatcache(true, $file);
        if (!is_file($file) || ($size = (int) filesize($file)) === 0) {
            return '';
        }
        $f = @fopen($file, 'rb');
        if ($f === false) {
            return '';
        }
        fseek($f, -min($size, $bytes), SEEK_END);
        $end = (string) stream_get_contents($f);
        fclose($f);

        return $end;
    }

    /** A path inside the site without the folder it is installed in. */
    public static function shortPath(string $file): string
    {
        return str_starts_with($file, KALETA_ROOT . '/') ? substr($file, strlen(KALETA_ROOT) + 1) : $file;
    }

    /** Is this entry (a log line) a deprecation rather than an error? */
    public static function isDeprecation(string $row): bool
    {
        return str_contains(substr($row, 0, 40 + strlen(self::DEPRECATION)), '] ' . self::DEPRECATION);
    }
}
