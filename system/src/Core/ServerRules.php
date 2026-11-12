<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.41 (N120): does the web server keep storage/ and system/ away from visitors? Apache reads the .htaccess files Kaleta
 * ships; nginx, Caddy or another server needs the rules of system/nginx.example.conf, and without them the error log,
 * exports and backups in storage/ and the files of system/ are served to anyone who knows their fixed names.
 *
 * The daily cron job (Scheduler 'server_rules') writes a small canary file into storage/cache/ and asks the site for it and
 * for system/sql/schema.sql over the site's own address; System status reports a file that came back. Only from cron: a
 * request to itself would wait on a server with a single worker (the built-in PHP server, some hostings).
 */
final class ServerRules
{
    /** The canary file the check asks for (relative to the site root) and what it holds. */
    public const string CANARY = 'storage/cache/kaleta-canary.txt';
    private const string CANARY_TEXT = 'kaleta-canary';

    /** What must never come back: path => text that proves the file itself was served (not a 404 page that answers 200). */
    public const array PROBES = [self::CANARY => self::CANARY_TEXT, 'system/sql/schema.sql' => 'CREATE TABLE'];

    /**
     * The daily job: writes the canary file, asks the site at $base (its address with a trailing slash) and remembers the
     * answer in the setting server_rules for System status.
     *
     * @return array{at: int, open: list<string>, error: string}
     */
    public static function run(Settings $s, string $base): array
    {
        if (!is_file(KALETA_ROOT . '/' . self::CANARY) && is_dir(dirname(KALETA_ROOT . '/' . self::CANARY))) {
            @file_put_contents(KALETA_ROOT . '/' . self::CANARY, self::CANARY_TEXT);
        }
        $result = self::check($base);
        $s->set('server_rules', (string) json_encode($result, JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /**
     * Asks for every file of PROBES under $base. $fetch (tests) answers [status, body] for a URL; the default asks with a
     * short timeout and follows no redirect.
     *
     * @param \Closure(string): array{0: int, 1: string}|null $fetch
     * @return array{at: int, open: list<string>, error: string}
     */
    public static function check(string $base, ?\Closure $fetch = null): array
    {
        $result = ['at' => time(), 'open' => [], 'error' => ''];
        $fetch ??= static function (string $url): array {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0,
                'header' => 'User-Agent: Kaleta-server-check/' . KALETA_VERSION . "\r\n"]]), 0, 100_000);
            $status = 0;
            foreach (last_response_headers($http_response_header ?? null) as $line) { // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }
            }

            return [$status, is_string($body) ? $body : ''];
        };
        foreach (self::PROBES as $path => $proof) {
            [$status, $body] = $fetch($base . $path);
            if ($status === 0) {
                $result['error'] = 'the site did not answer at ' . $base;
                break; // the server cannot reach itself (a firewall, a single worker) – nothing to say about the rules
            }
            if (self::served($status, $body, $proof)) {
                $result['open'][] = $path;
            }
        }

        return $result;
    }

    /** Pure: did the server hand out the file itself? Only a 200 with the file's own text counts (a soft 404 page does not). */
    public static function served(int $status, string $body, string $proof): bool
    {
        return $status === 200 && str_contains($body, $proof);
    }

    /** @return array{at?: int, open?: list<string>, error?: string} the last check ([] = never ran) */
    public static function last(Settings $s): array
    {
        $last = json_decode($s->get('server_rules'), true);

        return is_array($last) ? $last : [];
    }
}
