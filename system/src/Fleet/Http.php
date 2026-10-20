<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

/**
 * Signed JSON between a site and its console (2.9). The body is signed as it is sent (header X-Kaleta-Signature), so the
 * other side checks exactly the bytes it received.
 *
 * Since 3.3.2 (N37) every request of the fleet – pairing, heartbeats, the kit and the console's uptime checks – goes only
 * to a public address: the host is resolved once, every address it resolves to must be public (Core\ImageDownloader's
 * rules), and the connection is pinned to it; redirects are never followed and an answer is cut at MAX_BYTES. The signed
 * requests use https only; plain http and local addresses are allowed just in the automated tests (KALETA_FLEET_LOCAL=1).
 */
final class Http
{
    public const string HEADER = 'X-Kaleta-Signature';

    /** The largest answer read from the other side. */
    public const int MAX_BYTES = 2_000_000;

    /** For tests: replaces the network – gets the URL, the body and the headers, returns [status, body, signature]. */
    public static ?\Closure $transport = null;

    /** The automated tests run a console and its sites on 127.0.0.1 over plain http; never set on a real site. */
    private static function localTests(): bool
    {
        return getenv('KALETA_FLEET_LOCAL') === '1';
    }

    /** Only https (plain http too when $plainHttp, for the uptime check of a site), no user name; tests also local http. */
    public static function allowedUrl(string $url, bool $plainHttp = false): bool
    {
        // Outbound::url: http(s), no user name, and a host curl reads exactly as it is checked – no percent sign (3.3.3, N52)
        $target = \Kaleta\Core\Outbound::url($url);
        if ($target === null || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return false;
        }

        return $target['scheme'] === 'https' || $plainHttp || self::localTests();
    }

    /**
     * The address to connect to for a URL: [host, port, ip, url] with the normalized host (Outbound::host), a public IP of
     * it and the URL rewritten with that host – the one to request (3.3.3, N52); or null (every address the host resolves
     * to must be public – one internal address is enough to refuse it).
     *
     * @return array{0: string, 1: int, 2: string, 3: string}|null
     */
    public static function pin(string $url): ?array
    {
        $target = \Kaleta\Core\Outbound::url($url);
        if ($target === null) {
            return null;
        }
        [$host, $port] = [$target['host'], $target['port']];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return \Kaleta\Core\ImageDownloader::isPublicIp($host) || self::localTests() ? [$host, $port, $host, $target['url']] : null;
        }
        $addresses = \Kaleta\Core\Outbound::addresses($host);
        foreach ($addresses as $ip) {
            if (!\Kaleta\Core\ImageDownloader::isPublicIp($ip) && !self::localTests()) {
                return null;
            }
        }

        return isset($addresses[0]) ? [$host, $port, $addresses[0], $target['url']] : null;
    }

    /**
     * POSTs the payload as JSON, signed by $sign (null = unsigned). Returns the status (0 = no answer), the body, the decoded
     * JSON and the signature header of the answer.
     *
     * @param array<string, mixed> $payload
     * @param (\Closure(string): string)|null $sign
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: ?array<string, mixed>, signature: string, error: string}
     */
    public static function post(string $url, array $payload, ?\Closure $sign, int $timeout = 15, array $headers = []): array
    {
        if (\Kaleta\Core\Demo::active()) {
            return ['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => \Kaleta\Core\Demo::refusal()]; // 3.3.2 (N24): no requests from the public demo
        }
        if (!self::allowedUrl($url)) {
            return ['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'Only https addresses are allowed.'];
        }
        $pin = self::$transport === null ? self::pin($url) : ['', 0, '', $url];
        if ($pin === null) {
            return ['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'The address does not lead to a public server.'];
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Kaleta/' . KALETA_VERSION] + $headers;
        if ($sign !== null) {
            $headers[self::HEADER] = $sign($body);
        }
        [$status, $answer, $signature, $error] = self::$transport !== null ? [...(self::$transport)($url, $body, $headers), ''] : self::send($url, $pin, $body, $headers, $timeout);
        $json = json_decode((string) $answer, true);

        return ['status' => (int) $status, 'body' => (string) $answer, 'json' => is_array($json) ? $json : null, 'signature' => (string) $signature, 'error' => (string) $error];
    }

    /**
     * One POST pinned to the verified address, no redirects, the answer cut at MAX_BYTES.
     *
     * @param array{0: string, 1: int, 2: string, 3: string} $pin
     * @param array<string, string> $headers
     * @return array{0: int, 1: string, 2: string, 3: string}
     */
    private static function send(string $url, array $pin, string $body, array $headers, int $timeout): array
    {
        [$host, $port, $ip, $url] = $pin; // the URL with the normalized host (3.3.3, N52)
        $lines = array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers);
        $signature = '';
        if (function_exists('curl_init')) {
            $answer = '';
            $ch = curl_init($url);
            \Kaleta\Core\Outbound::pin($ch, $host, $port, $ip);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_MAXFILESIZE => self::MAX_BYTES,
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$signature): int {
                    if (stripos($line, self::HEADER . ':') === 0) {
                        $signature = trim(substr($line, strlen(self::HEADER) + 1));
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$answer): int {
                    $answer .= $chunk;

                    return strlen($answer) > self::MAX_BYTES ? -1 : strlen($chunk); // another value than the length stops curl
                }]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $ok === false ? (strlen($answer) > self::MAX_BYTES || curl_errno($ch) === CURLE_FILESIZE_EXCEEDED ? 'The answer is too large.' : curl_error($ch)) : '';

            return [$error === '' ? $status : 0, $error === '' ? $answer : '', $signature, $error];
        }
        // without curl: straight to the verified address, the host name in the Host header and in the certificate check
        $parts = parse_url($url);
        $target = $parts['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $answer = @file_get_contents($target, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 0, 'header' => implode("\r\n", [...$lines, 'Host: ' . $host . (isset($parts['port']) ? ':' . $port : '')]) . "\r\n", 'content' => $body],
            'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]), 0, self::MAX_BYTES);
        $status = 0;
        foreach (last_response_headers($http_response_header ?? null) as $line) { // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($line, self::HEADER . ':') === 0) {
                $signature = trim(substr($line, strlen(self::HEADER) + 1));
            }
        }

        return [$status, $answer === false ? '' : $answer, $signature, $answer === false ? 'No answer.' : ''];
    }

    /**
     * The HTTP status of a public address (0 = no answer or not allowed) – the console's uptime check (Fleet\Console): pinned
     * like post(), no redirects (any answer below 500 counts as up), at most 1 kB read.
     *
     * @param list<string> $urls
     * @return list<int>
     */
    public static function statuses(array $urls): array
    {
        return array_map(fn (array $check): int => $check['status'], self::checks($urls));
    }

    /**
     * statuses() with the response time (3.17, Fleet\History): the milliseconds until the first byte of the answer, null
     * when there was no answer. The console adds them up per site and day.
     *
     * @param list<string> $urls
     * @return list<array{status: int, ms: ?int}>
     */
    public static function checks(array $urls): array
    {
        $pins = array_map(fn (string $url): ?array => self::allowedUrl($url, true) ? self::pin($url) : null, $urls);
        if (!function_exists('curl_multi_init')) {
            return array_map(function (string $url, ?array $pin): array {
                if ($pin === null) {
                    return ['status' => 0, 'ms' => null];
                }
                $start = microtime(true);
                [$host, $port, $ip, $url] = $pin;
                $parts = parse_url($url);
                $target = $parts['scheme'] . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port . ($parts['path'] ?? '/');
                @file_get_contents($target, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                    'header' => 'Host: ' . $host . (isset($parts['port']) ? ':' . $port : '') . "\r\nUser-Agent: Kaleta-console/" . KALETA_VERSION . "\r\n"],
                    'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]), 0, 1024);
                $status = 0;
                foreach (last_response_headers($http_response_header ?? null) as $line) { // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = (int) $m[1];
                    }
                }

                return ['status' => $status, 'ms' => $status > 0 ? self::milliseconds(microtime(true) - $start) : null];
            }, $urls, $pins);
        }
        $multi = curl_multi_init();
        $handles = [];
        $out = array_fill(0, count($urls), ['status' => 0, 'ms' => null]);
        foreach ($urls as $i => $url) {
            if ($pins[$i] === null) {
                continue;
            }
            [$host, $port, $ip, $target] = $pins[$i];
            $ch = curl_init($target); // the URL with the normalized host (3.3.3, N52)
            \Kaleta\Core\Outbound::pin($ch, $host, $port, $ip);
            curl_setopt_array($ch, [CURLOPT_RANGE => '0-1023',
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'Kaleta-console/' . KALETA_VERSION,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_WRITEFUNCTION => fn ($ch, string $chunk): int => 0]); // the status is enough: stop at the first byte of the body
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);
        foreach ($handles as $i => $ch) {
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            // the time to the first byte: the transfer stops there on purpose (the write function above)
            $out[$i] = ['status' => $status, 'ms' => $status > 0 ? self::milliseconds((int) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME_T) / 1_000_000) : null];
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        return $out;
    }

    /** Seconds as whole milliseconds, at most a minute (a check never waits longer). */
    public static function milliseconds(float $seconds): int
    {
        return max(0, min(60_000, (int) round($seconds * 1000)));
    }
}
