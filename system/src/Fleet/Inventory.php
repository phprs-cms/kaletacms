<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

/**
 * The inventory of the fleet console (3.30): one row per site with its version, update channel, PHP, database server,
 * a hint of the web server, the features (Core\Extensions keys) and add-ons it has on, its languages and its last report –
 * filtered by any of them ("which sites still run PHP 8.3", "which have Bookings on") and exported to CSV.
 *
 * What the site tells about itself for it (Fleet\Heartbeat, 3.30 – each bounded here by clean(), like Fleet\Checks::clean):
 *  - db_server: the family (mysql | mariadb | other) and its version x.y(.z) from SELECT VERSION();
 *  - web_server: the family of the web server software (WEB_SERVERS) – never a host name, an address or a version;
 *  - features, addons, languages: lists of short system names (pattern, at most MAX_LIST);
 *  - recovery_key_since: the day an administrator confirmed saving the backup recovery key (Core\RecoveryKey), for the
 *    expiry calendar.
 * A site before 3.30 sends none of them: its version, channel and PHP still show, the rest says "update the site to see".
 * Everything here is pure; the screens (Admin\Modules\Fleet inventory) and the MCP tool fleet_inventory pass the rows.
 */
final class Inventory
{
    /** The heartbeat keys of 3.30 this class bounds (Console::clean hands them here). */
    public const array PARTS = ['db_server', 'web_server', 'features', 'addons', 'languages', 'recovery_key_since'];

    /** The families of web server software a site may name (its own SERVER_SOFTWARE or PHP's SAPI). */
    public const array WEB_SERVERS = ['apache', 'nginx', 'litespeed', 'iis', 'caddy', 'php', 'other', 'unknown'];

    /** The families of database server. */
    public const array DB_FAMILIES = ['mysql', 'mariadb', 'other'];

    /** How many names a list part carries at most. */
    public const int MAX_LIST = 40;

    /** The filters of the inventory: column => the row's key it compares. */
    public const array FILTERS = ['customer' => 'customer_id', 'version' => 'version', 'channel' => 'channel', 'php' => 'php_minor', 'db' => 'db', 'server' => 'server',
        'feature' => 'features', 'addon' => 'addons', 'language' => 'languages'];

    /** The names of the database families as shown. */
    public const array DB_LABELS = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'other' => 'Other'];

    /** The labels of the web server families (English, through t()). */
    public const array SERVER_LABELS = ['apache' => 'Apache', 'nginx' => 'nginx', 'litespeed' => 'LiteSpeed', 'iis' => 'IIS', 'caddy' => 'Caddy', 'php' => 'PHP built-in server',
        'other' => 'Other', 'unknown' => 'Unknown'];

    /**
     * Bounds one 3.30 part of a heartbeat: enum values only, versions by pattern, lists of short names (unique, at most
     * MAX_LIST), a real calendar day; anything else is null on its own.
     */
    public static function clean(string $key, mixed $v): mixed
    {
        switch ($key) {
            case 'web_server':
                return is_string($v) && in_array($v, self::WEB_SERVERS, true) ? $v : null;
            case 'db_server':
                if (!is_array($v) || !is_string($v['family'] ?? null) || !in_array($v['family'], self::DB_FAMILIES, true)) {
                    return null;
                }
                $version = $v['version'] ?? null;

                return ['family' => $v['family'], 'version' => is_string($version) && preg_match('/^\d{1,3}\.\d{1,3}(\.\d{1,4})?$/D', $version) === 1 ? $version : null];
            case 'features':
                return self::names($v, '/^[a-z][a-z_]{0,29}$/D');
            case 'addons':
                return self::names($v, '/^[a-z0-9][a-z0-9-]{0,39}$/D');
            case 'languages':
                return self::names($v, '/^[a-z]{2,3}(-[a-z]{2,4})?$/D');
            case 'recovery_key_since':
                $date = Console::date($v);

                return $date !== null && (int) $date->format('Y') >= 2000 && (int) $date->format('Y') <= 2100 ? $date->format('Y-m-d') : null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function names(mixed $v, string $pattern): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        $out = [];
        foreach (array_slice($v, 0, self::MAX_LIST * 2) as $name) {
            if (is_string($name) && preg_match($pattern, $name) === 1 && !in_array($name, $out, true) && count($out) < self::MAX_LIST) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The site's side: the database server from SELECT VERSION() – "8.4.3" → mysql, "10.11.6-MariaDB-log" → mariadb (the
     * "5.5.5-" some servers put in front skipped, Core\Readiness::databaseServer). Pure.
     *
     * @return array{family: string, version: ?string}
     */
    public static function databaseServer(string $version): array
    {
        [$name, $number] = \Kaleta\Core\Readiness::databaseServer($version);
        $number = preg_match('/^\d{1,3}\.\d{1,3}(\.\d{1,4})?$/D', $number) === 1 ? $number : null;

        return ['family' => $number === null ? 'other' : strtolower($name), 'version' => $number];
    }

    /**
     * The site's side: the family of its web server from SERVER_SOFTWARE, else PHP's SAPI; 'unknown' when the heartbeat is
     * built where neither tells (a command-line cron). Only the family – never the version or a host. Pure.
     */
    public static function webServer(string $software, string $sapi): string
    {
        $software = strtolower($software);
        foreach (['openlitespeed' => 'litespeed', 'litespeed' => 'litespeed', 'nginx' => 'nginx', 'apache' => 'apache', 'microsoft-iis' => 'iis', 'caddy' => 'caddy', 'development server' => 'php'] as $needle => $family) {
            if (str_contains($software, $needle)) {
                return $family;
            }
        }

        return match (true) {
            $sapi === 'apache2handler' => 'apache',
            $sapi === 'litespeed' => 'litespeed',
            $sapi === 'cli-server' => 'php',
            $software !== '' => 'other',
            default => 'unknown',
        };
    }

    /**
     * One inventory row of a site (a row of ka_fleet_sites): what it reported last, each bounded again; null where the
     * site does not send it (before 3.30), with known = whether it sends the 3.30 parts.
     *
     * @param array<string, mixed> $site
     * @param array<int, string> $customers id => name
     * @return array<string, mixed>
     */
    public static function row(array $site, array $customers = []): array
    {
        $beat = Console::storedBeat($site);
        $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
        $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
        $customer = Console::customerIdOf($site);
        $php = is_string($beat['php'] ?? null) ? $beat['php'] : null;
        $db = is_array($beat['db_server'] ?? null) ? $beat['db_server'] : null;
        $version = (string) ($site['version'] ?? '') !== '' ? (string) $site['version'] : (is_string($beat['version'] ?? null) ? $beat['version'] : '');

        return [
            'site_id' => (int) ($site['id'] ?? 0), 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => $customer > 0 ? $customer : null,
            'customer' => $customer > 0 ? ($customers[$customer] ?? null) : null,
            'version' => $version, 'channel' => is_string($beat['update_channel'] ?? null) ? $beat['update_channel'] : null,
            'php' => $php, 'php_minor' => $php !== null && preg_match('/^(\d+\.\d+)/', $php, $m) === 1 ? $m[1] : null,
            'db' => $db !== null ? $db['family'] . ($db['version'] !== null && preg_match('/^(\d+\.\d+)/', $db['version'], $m) === 1 ? ' ' . $m[1] : '') : null,
            'db_family' => $db['family'] ?? null, 'db_version' => $db['version'] ?? null,
            'server' => is_string($beat['web_server'] ?? null) ? $beat['web_server'] : null,
            'features' => $beat['features'] ?? null, 'addons' => $beat['addons'] ?? null, 'languages' => $beat['languages'] ?? null,
            'ring' => Rollout::ring($site['ring'] ?? ''), 'last_seen' => is_string($site['last_seen'] ?? null) ? $site['last_seen'] : null,
            'known' => array_intersect_key($beat, array_flip(self::PARTS)) !== [],
        ];
    }

    /**
     * The rows narrowed by the filters (FILTERS, each '' = any; customer -1 = the sites without one) and a free text (the
     * site's name, address, customer, versions and names – any word of it, case-insensitive). A list column matches when
     * the site has the value. Pure.
     *
     * @param list<array<string, mixed>> $rows row()
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, array $filters, string $query = ''): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn (string $w): bool => $w !== ''));

        return array_values(array_filter($rows, static function (array $r) use ($filters, $words): bool {
            foreach (self::FILTERS as $filter => $key) {
                $want = $filters[$filter] ?? '';
                if ($want === '' || $want === 0 || $want === null) {
                    continue;
                }
                if ($filter === 'customer') {
                    if ((int) $want === -1 ? $r['customer_id'] !== null : $r['customer_id'] !== (int) $want) {
                        return false;
                    }
                } elseif (is_array($r[$key]) ? !in_array((string) $want, $r[$key], true) : (string) $r[$key] !== (string) $want) {
                    return false;
                }
            }
            if ($words !== []) {
                $text = mb_strtolower(implode(' ', [$r['site'], $r['url'], (string) $r['customer'], $r['version'], (string) $r['channel'], (string) $r['php'], (string) $r['db'],
                    (string) $r['server'], implode(' ', $r['features'] ?? []), implode(' ', $r['addons'] ?? []), implode(' ', $r['languages'] ?? [])]));
                foreach ($words as $word) {
                    if (!str_contains($text, $word)) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /**
     * The values each filter offers – what the sites report, sorted (versions newest first), with how many sites have each.
     * Pure.
     *
     * @param list<array<string, mixed>> $rows row()
     * @return array<string, array<string, int>> filter => value => sites
     */
    public static function options(array $rows): array
    {
        $out = [];
        foreach (self::FILTERS as $filter => $key) {
            if ($filter === 'customer') {
                continue;
            }
            $values = [];
            foreach ($rows as $r) {
                foreach (is_array($r[$key]) ? $r[$key] : ($r[$key] !== null && $r[$key] !== '' ? [$r[$key]] : []) as $value) {
                    $values[(string) $value] = ($values[(string) $value] ?? 0) + 1;
                }
            }
            if (in_array($filter, ['version', 'php', 'db'], true)) {
                uksort($values, static fn (string $a, string $b): int => version_compare(preg_replace('/^\D+/', '', $b) ?? $b, preg_replace('/^\D+/', '', $a) ?? $a) ?: strcmp($a, $b));
            } else {
                ksort($values, SORT_STRING);
            }
            $out[$filter] = $values;
        }

        return $out;
    }

    /**
     * The rows as CSV (UTF-8 with BOM, semicolons – opens in Excel), the headers in the current language. A cell starting
     * with = + - @ would run as a formula in a spreadsheet: it gets a leading apostrophe. Pure apart from the dictionaries.
     *
     * @param list<array<string, mixed>> $rows row()
     */
    public static function csv(array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        if ($f === false) {
            return '';
        }
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Site'), t('Address'), t('Customer'), t('Version'), t('Channel'), 'PHP', t('Database'), t('Web server'), t('Features'), t('Add-ons'), t('Languages'),
            t('Ring'), t('Last report')], ';', '"', '');
        $list = static fn (mixed $v): string => is_array($v) ? implode(', ', $v) : '';
        foreach ($rows as $r) {
            $cells = [$r['site'], $r['url'], (string) $r['customer'], $r['version'], (string) $r['channel'], (string) $r['php'],
                $r['db_family'] !== null ? trim(t(self::DB_LABELS[$r['db_family']]) . ' ' . ($r['db_version'] ?? '')) : '',
                (string) $r['server'], $list($r['features']), $list($r['addons']), $list($r['languages']), $r['ring'], (string) $r['last_seen']];
            fputcsv($f, array_map(static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v, $cells), ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);

        return $csv;
    }
}
