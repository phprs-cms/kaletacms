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
     * Asks for every file of PROBES under $base. $fetch (tests) answers [status, body, Location] for a URL; the default asks
     * with a short timeout and follows no redirect itself. A redirect to the same host (http → https, the www. form of the
     * name) is followed once – a site address that redirects is common; any other redirect leaves the check inconclusive
     * ('redirect' = where it led), never "refused" (3.41 review L1: a 301 is not a refusal).
     *
     * @param \Closure(string): array{0: int, 1: string, 2?: string}|null $fetch
     * @return array{at: int, open: list<string>, error: string, redirect?: string}
     */
    public static function check(string $base, ?\Closure $fetch = null): array
    {
        $result = ['at' => time(), 'open' => [], 'error' => ''];
        $fetch ??= static function (string $url): array {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0,
                'header' => 'User-Agent: Kaleta-server-check/' . KALETA_VERSION . "\r\n"]]), 0, 100_000);
            $status = 0;
            $location = '';
            foreach (last_response_headers($http_response_header ?? null) as $line) { // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                } elseif (preg_match('#^Location:\s*(\S+)#i', $line, $m)) {
                    $location = $m[1];
                }
            }

            return [$status, is_string($body) ? $body : '', $location];
        };
        foreach (self::PROBES as $path => $proof) {
            $url = $base . $path;
            $answer = $fetch($url);
            $next = self::sameHostRedirect($url, $answer[0], $answer[2] ?? '');
            if ($next !== null) {
                $answer = $fetch($next);
            }
            [$status, $body] = $answer;
            if ($status >= 300 && $status < 400) {
                $result['redirect'] = self::absolute($next ?? $url, $answer[2] ?? '');
                break; // nothing to say about the rules – the address leads elsewhere
            }
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

    /**
     * Pure: where a redirect of $url leads, when it stays on the same host (or its www. form) – else null. Only http(s);
     * a relative Location is resolved against $url.
     */
    public static function sameHostRedirect(string $url, int $status, string $location): ?string
    {
        if ($status < 300 || $status >= 400 || $location === '') {
            return null;
        }
        $target = self::absolute($url, $location);
        $from = strtolower((string) parse_url($url, PHP_URL_HOST));
        $to = strtolower((string) parse_url($target, PHP_URL_HOST));
        $bare = static fn (string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;

        return in_array(parse_url($target, PHP_URL_SCHEME), ['http', 'https'], true) && $to !== '' && $bare($from) === $bare($to) ? $target : null;
    }

    /** Pure: a Location header made absolute against the URL that answered it ('' stays ''). */
    private static function absolute(string $url, string $location): string
    {
        if ($location === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $origin = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . (($port = parse_url($url, PHP_URL_PORT)) !== null ? ':' . $port : '');
        if (str_starts_with($location, '//')) {
            return parse_url($url, PHP_URL_SCHEME) . ':' . $location;
        }

        return $origin . (str_starts_with($location, '/') ? $location : '/' . $location);
    }

    /** Pure: did the server hand out the file itself? Only a 200 with the file's own text counts (a soft 404 page does not). */
    public static function served(int $status, string $body, string $proof): bool
    {
        return $status === 200 && str_contains($body, $proof);
    }

    /** @return array{at?: int, open?: list<string>, error?: string, redirect?: string} the last check ([] = never ran) */
    public static function last(Settings $s): array
    {
        $last = json_decode($s->get('server_rules'), true);

        return is_array($last) ? $last : [];
    }
}
