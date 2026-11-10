<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The application error log, storage/log/chyby.log (T1). One entry = one line that starts with "[<date('c')>] " – System
 * status counts the entries of the last 24 hours by that prefix and Settings → System status shows the last lines.
 *
 * A PHP deprecation is logged, never thrown (Q1): a host that upgrades PHP must not turn every page that calls a newly
 * deprecated function into an error page until the site installs an update. Each deprecation is logged once a day.
 *
 * An uncaught exception (Q13) carries the request method and path (never the query, which can hold tokens), the signed-in
 * user's number, the Kaleta version and the first FRAMES stack frames (file, line, function – never arguments). E-mail
 * addresses and token-like strings in a message are masked. The log is capped: past MAX_BYTES it moves to chyby.log.1
 * (the previous one is dropped), so a site in an error loop cannot fill its disk.
 */
final class ErrorLog
{
    /** The prefix of a deprecation entry; System status counts these apart from errors. */
    public const string DEPRECATION = 'PHP deprecation: ';

    /** Past this size the log is rotated to <log>.1 before the next entry. */
    public const int MAX_BYTES = 1048576;

    /** Stack frames kept with an exception. */
    public const int FRAMES = 8;

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
        self::rotate();
        @file_put_contents(self::file(), $line, FILE_APPEND | LOCK_EX);
    }

    /** Moves a log over MAX_BYTES to <log>.1 (replacing the older one). */
    public static function rotate(): void
    {
        $file = self::file();
        clearstatcache(true, $file);
        if (is_file($file) && (int) filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.1');
        }
    }

    /** Both files of the log, the current one first (the ones that exist). @return list<string> */
    public static function files(): array
    {
        return array_values(array_filter([self::file(), self::file() . '.1'], is_file(...)));
    }

    /** The entry of an uncaught exception: what, where, which request, who, which version, how it got there. */
    public static function exception(\Throwable $e): string
    {
        return sprintf('%s: %s in %s:%d', $e::class, self::clean($e->getMessage()), self::shortPath($e->getFile()), $e->getLine())
            . ' · ' . self::context() . ' · trace: ' . self::trace($e);
    }

    /** "GET /path · user 3 · Kaleta 3.31.2" – the path without its query, the user only when signed in. */
    public static function context(string $sapi = PHP_SAPI): string
    {
        if ($sapi === 'cli') {
            $request = 'cli';
        } else {
            $method = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_SERVER['REQUEST_METHOD'] ?? '')) ?? '');
            $path = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?#');
            $request = substr($method, 0, 10) . ' ' . mb_strimwidth(self::clean(rawurldecode($path)), 0, 200, '…');
        }
        $user = session_status() === PHP_SESSION_ACTIVE ? (int) ($_SESSION['idu'] ?? 0) : 0;

        return $request . ($user > 0 ? ' · user ' . $user : '') . ' · Kaleta ' . (defined('KALETA_VERSION') ? (string) constant('KALETA_VERSION') : '?');
    }

    /** The first FRAMES frames as "#0 file:line Class->function()", without arguments. */
    public static function trace(\Throwable $e): string
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, self::FRAMES) as $i => $f) {
            $where = isset($f['file']) ? self::shortPath((string) $f['file']) . ':' . (int) ($f['line'] ?? 0) : '[internal]';
            $frames[] = '#' . $i . ' ' . $where . ' ' . (isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') : '') . $f['function'] . '()';
        }
        $more = count($e->getTrace()) - self::FRAMES;

        return ($frames === [] ? '-' : implode(' | ', $frames)) . ($more > 0 ? ' | … ' . $more . ' more' : '');
    }

    /**
     * No personal data or secrets in the log: e-mail addresses and token-like strings (20+ letters and digits that mix
     * both, as keys, tokens and hashes do) are masked; a long message is shortened.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[a-z]{2,}/iu', '[e-mail]', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9])(?=[A-Za-z0-9_-]*[0-9])(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{20,}(?![A-Za-z0-9])/', '[redacted]', $text);

        return mb_strimwidth($text, 0, 500, '…');
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
