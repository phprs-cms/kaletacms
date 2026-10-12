<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Firewall of the public site (2.8): blocked addresses and networks, blocked countries, a limit of requests per minute,
 * and a 24-hour block for addresses that probe for other systems (/wp-login.php, /.env, /xmlrpc.php…).
 *
 *  - Off by default (firewall_enabled). It guards the public site, /mcp and OAuth – never admin.php, so the owner cannot
 *    lock themselves out; sign-in has its own lockout. Addresses of the local network are never blocked.
 *  - The visitor's address is REMOTE_ADDR. Behind Cloudflare (firewall_proxy = cloudflare) it is CF-Connecting-IP, but only
 *    when the request really comes from a Cloudflare address – otherwise anyone could choose their own address. The country
 *    is known only there (CF-IPCountry) or when the hosting sends GEOIP_COUNTRY_CODE; without it country blocking does nothing.
 *  - Probing is counted only for addresses that end in 404 (Front\Kernel::notFound), never for pages that exist.
 *  - The limits count in small files under storage/cache/firewall (no database write per request); the clean-up job deletes them.
 *  - Every refused request is logged (ka_firewall_log, 30 days, at most LOG_PER_HOUR rows an hour).
 */
final class Firewall
{
    /** Cloudflare's published address ranges (https://www.cloudflare.com/ips/). */
    public const array CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20',
        '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** Probes for other systems from one address in an hour before it is blocked for a day. */
    public const int PROBES = 5;
    public const int PROBE_BLOCK_HOURS = 24;
    public const int LOG_DAYS = 30;
    private const int LOG_PER_HOUR = 2000;

    /**
     * Addresses of other systems that only probing bots ask for. Narrower than NotFound::BOTS on purpose: a missing image,
     * a stylesheet or an old /wp-content/uploads/ link of a moved site never counts – a real visitor would be blocked.
     */
    public const string PROBE_PATHS = '#(^|/)\.(env|git|svn|hg|aws|ssh|htpasswd|ds_store)|\.(env|sql|bak|old|ini|sh|aspx?|jsp|cgi)$|^(wp-(login|admin|includes|config|signup|cron)|xmlrpc\.php|wordpress/|wp/wp-|cgi-bin/|phpmyadmin|pma/|myadmin|adminer|phpinfo|vendor/|actuator|boaform|hnap1|owa/|autodiscover|telescope|_profiler|server-status)#i';

    /** A refusal for this request, or null when it may go on. */
    public static function check(App $app): ?Response
    {
        if (($ip = self::guardedIp($app)) === null) {
            return null;
        }
        $s = $app->settings();
        $r = $app->request;
        $db = $app->db();
        $path = trim($r->path(), '/');
        $reason = match (true) {
            $db->value('SELECT 1 FROM {firewall_blocks} WHERE ip = ? AND until > ?', [$ip, Clock::now()]) !== null => 'temporary',
            self::inList($ip, self::parseList($s->get('firewall_ips'))[0]) => 'list',
            self::countryBlocked(self::country($r->serverValues(), $s->get('firewall_proxy')), $s->get('firewall_countries')) => 'country',
            // the Claude connection signs in on its own and may send many calls at once while it builds; sites report to a console
            $s->int('firewall_rate') > 0 && preg_match('#^(mcp|oauth|\.well-known/|fleet/)#', $path) !== 1 && self::count($ip, 'rate', 60) > $s->int('firewall_rate') => 'rate',
            default => null,
        };

        return $reason === null ? null : self::refuse($db, $ip, $reason, $path);
    }

    /**
     * Called when an address ends in 404: the fifth probe for another system (PROBE_PATHS) from one address in an hour
     * blocks it for PROBE_BLOCK_HOURS. Returns the refusal for this request, or null for the normal 404 page.
     */
    public static function notFound(App $app, string $path): ?Response
    {
        if (!$app->settings()->bool('firewall_probes') || preg_match(self::PROBE_PATHS, $path) !== 1 || ($ip = self::guardedIp($app)) === null) {
            return null;
        }
        if (self::count($ip, 'probe', 3600) < self::PROBES) {
            return null;
        }
        $db = $app->db();
        $db->run('INSERT INTO {firewall_blocks} (ip, until, reason, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE until = VALUES(until), reason = VALUES(reason)',
            [$ip, Clock::at('+' . self::PROBE_BLOCK_HOURS . ' hours'), 'probe', Clock::now()]);
        Events::record($db, 'firewall.blocked', 'warning', t('%s was blocked for %d hours after probing for other systems (%s).', $ip, self::PROBE_BLOCK_HOURS, '/' . mb_substr($path, 0, 120)), ['reason' => 'probe']);

        return self::refuse($db, $ip, 'probe', $path);
    }

    /** The visitor's address when the firewall guards this request (on, not a local address), otherwise null. */
    private static function guardedIp(App $app): ?string
    {
        if (!$app->settings()->bool('firewall_enabled')) {
            return null;
        }
        $ip = self::visitorIp($app->request->serverValues(), $app->settings()->get('firewall_proxy'));
        // the automated tests connect from 127.0.0.1 (KALETA_FIREWALL_LOCAL=1); never set on a real site
        if ($ip === '' || (self::isLocal($ip) && getenv('KALETA_FIREWALL_LOCAL') !== '1')) {
            return null;
        }

        return $ip;
    }

    private static function refuse(Db $db, string $ip, string $reason, string $path): Response
    {
        if ((int) $db->value('SELECT COUNT(*) FROM {firewall_log} WHERE created_at > ?', [Clock::at('-1 hour')]) < self::LOG_PER_HOUR) {
            $db->insert('firewall_log', ['created_at' => date('Y-m-d H:i:s'), 'ip' => $ip, 'reason' => $reason, 'path' => '/' . mb_substr($path, 0, 254)]);
        }

        return $reason === 'rate'
            ? new Response("Too many requests.\n", 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '60', 'Cache-Control' => 'no-store'])
            : new Response("Access denied.\n", 403, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * The visitor's address: REMOTE_ADDR, or behind Cloudflare the address it passes on – only when the request comes from Cloudflare.
     *
     * @param array<string, mixed> $server
     */
    public static function visitorIp(array $server, string $proxy): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($proxy === 'cloudflare' && isset($server['HTTP_CF_CONNECTING_IP']) && self::inList($remote, self::CLOUDFLARE)) {
            $ip = trim((string) $server['HTTP_CF_CONNECTING_IP']);

            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $remote;
        }

        return $remote;
    }

    /**
     * What the sign-in limits count by (3.3.3, N54): the visitor's address behind the configured proxy (visitorIp), an
     * IPv6 address by its /64 (Antispam::network) – so visitors behind Cloudflare do not share one counter, and one
     * IPv6 network does not get a fresh counter for every address it owns.
     */
    public static function visitorKey(Request $request, Settings $settings): string
    {
        return Antispam::network(self::visitorIp($request->serverValues(), $settings->get('firewall_proxy')));
    }

    /** @param array<string, mixed> $server */
    public static function country(array $server, string $proxy): string
    {
        $code = $proxy === 'cloudflare' && self::inList((string) ($server['REMOTE_ADDR'] ?? ''), self::CLOUDFLARE)
            ? (string) ($server['HTTP_CF_IPCOUNTRY'] ?? '') : (string) ($server['GEOIP_COUNTRY_CODE'] ?? '');

        return preg_match('/^[A-Z]{2}$/D', strtoupper($code)) === 1 && !in_array(strtoupper($code), ['XX', 'T1'], true) ? strtoupper($code) : '';
    }

    public static function countryBlocked(string $country, string $setting): bool
    {
        return $country !== '' && in_array($country, self::countries($setting), true);
    }

    /** @return list<string> ISO country codes from the setting ("RU, CN" or one per line) */
    public static function countries(string $setting): array
    {
        preg_match_all('/\b[A-Za-z]{2}\b/', $setting, $m);

        return array_values(array_unique(array_map('strtoupper', $m[0])));
    }

    /**
     * The manual list: one address or network per line, a comment after #. Returns [valid entries, invalid lines].
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function parseList(string $text): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) { // CR/LF only – any bytes, never a failed split (3.11, N311-1)
            $entry = trim(explode('#', $line, 2)[0]);
            if ($entry === '') {
                continue;
            }
            self::isValidEntry($entry) ? $valid[] = $entry : $invalid[] = $entry;
        }

        return [$valid, $invalid];
    }

    public static function isValidEntry(string $entry): bool
    {
        [$address, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits >= (strlen($packed) === 4 ? 8 : 16) && (int) $bits <= strlen($packed) * 8);
    }

    /** @param list<string> $entries addresses and networks (CIDR) */
    public static function inList(string $ip, array $entries): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($entries as $entry) {
            [$address, $bits] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];
            $network = @inet_pton($address);
            if ($network === false || strlen($network) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function isLocal(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Counts a request of an address in the current window and returns the count: one small file per address and window,
     * a byte appended per request (no database write). $add false only reads the count.
     */
    public static function count(string $ip, string $kind, int $window, bool $add = true): int
    {
        $folder = KALETA_ROOT . '/storage/cache/firewall';
        if (!is_dir($folder) && !@mkdir($folder, 0775, true) && !is_dir($folder)) {
            return 0;
        }
        $file = $folder . '/' . $kind . '-' . intdiv(time(), $window) . '-' . substr(hash('sha256', $ip), 0, 24);
        if ($add) {
            @file_put_contents($file, '.', FILE_APPEND | LOCK_EX);
        }
        clearstatcache(true, $file);

        return (int) @filesize($file);
    }

    /** The clean-up job: old counters, old log rows and expired blocks. */
    public static function cleanUp(Db $db): void
    {
        foreach (glob(KALETA_ROOT . '/storage/cache/firewall/*') ?: [] as $file) {
            if (filemtime($file) < time() - 7200) {
                @unlink($file);
            }
        }
        $db->run('DELETE FROM {firewall_log} WHERE created_at < ?', [Clock::at('-' . self::LOG_DAYS . ' days')]);
        $db->run('DELETE FROM {firewall_blocks} WHERE until < ?', [Clock::now()]);
    }
}
