<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;

/**
 * The console screens of 3.29 over Fleet\Checks: the Today view (what needs attention, filtered, with a summary), the
 * health matrix (a row per site, a column per check, the worst sites first) and the workload table (sortable). The admin
 * screens (Admin\Modules\Fleet: today, health, workload) and the MCP tools (fleet_today, fleet_health) share it, so the
 * screen and Claude see the same thing. Pure apart from context() – the rows of ka_fleet_sites and the time come in.
 *
 * Every link comes from Checks::link (the site's known address and a fixed admin path); nothing here reads a path or an
 * address the site sent, and the console never calls into a site.
 */
final class Health
{
    /** Short column headers of the matrix (English, through t()); the full label (Checks::CHECKS) is the title. */
    public const array SHORT = [
        'uptime' => 'Online', 'reporting' => 'Reporting', 'updates' => 'Updates', 'offsite_backup' => 'Off-site', 'local_backup' => 'Backup',
        'certificate' => 'TLS', 'domain' => 'Domain', 'mail_records' => 'SPF', 'smtp' => 'SMTP', 'two_step' => '2FA', 'auto_updates' => 'Auto',
        'cookie_scan' => 'Cookies', 'privacy_policy' => 'Privacy', 'accessibility_statement' => 'Access.', 'handover' => 'Hand-over',
        'claude_connection' => 'Claude', 'jobs' => 'Jobs', 'cron' => 'Cron',
    ];

    /** The state symbols of the matrix chips – a shape for each state, so the state never rests on the colour alone. */
    public const array SYMBOLS = ['ok' => '✓', 'warn' => '!', 'bad' => '✕', 'unknown' => '?'];

    /** The state names (English, through t()) – the accessible text of a chip and the legend. */
    public const array STATE_LABELS = ['ok' => 'OK', 'warn' => 'Warning', 'bad' => 'Needs action', 'unknown' => 'Unknown'];

    /** Severity filter of Today. */
    public const array SEVERITIES = ['bad', 'warn'];

    /** The sort orders of the matrix: the worst first (default), by name, grouped by customer. */
    public const array MATRIX_SORTS = ['worst', 'name', 'customer'];

    /** How many Today items the MCP tool returns at most. */
    public const int MCP_LIMIT = 200;

    /**
     * The context of Checks: the newest version when it is a security release (an update waiting for it is bad at once).
     *
     * @return array{security_version?: string}
     */
    public static function context(App $app): array
    {
        [$version, $security] = Console::latest($app);

        return $security ? ['security_version' => $version] : [];
    }

    /** A site that sends a heartbeat without any of the 3.29 parts (Checks::PARTS) – it shows "update the site to see". */
    public static function needsUpdate(array $site): bool
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
        if (!is_array($beat) || $beat === []) {
            return false;
        }
        $clean = array_filter(Console::clean($beat), static fn (mixed $v): bool => $v !== null);

        return array_intersect_key($clean, array_flip(Checks::PARTS)) === [];
    }

    /**
     * Today's items narrowed by customer (id, 0 = all; -1 = the sites without one) and severity ('' = both).
     *
     * @param list<array<string, mixed>> $items Checks::today
     * @return list<array<string, mixed>>
     */
    public static function filter(array $items, int $customer, string $severity): array
    {
        return array_values(array_filter($items, static fn (array $i): bool => ($customer === 0 || ($customer < 0 ? $i['customer_id'] === null : $i['customer_id'] === $customer))
            && ($severity === '' || $i['state'] === $severity)));
    }

    /**
     * The summary over the sites (the header of Today): red and amber items, reporting sites with nothing red or amber, sites not
     * reporting (no report yet, or none for more than Console::SILENT_HOURS), sites to update (before 3.29).
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param list<array<string, mixed>> $items Checks::today over the same sites
     * @param array{security_version?: string} $context
     * @return array{sites: int, bad: int, warn: int, ok_sites: int, not_reporting: int, to_update: int}
     */
    public static function summary(array $sites, array $items, int $now, array $context = []): array
    {
        $attention = [];
        $bad = 0;
        foreach ($items as $item) {
            $attention[(int) $item['site_id']] = true;
            $bad += $item['state'] === 'bad' ? 1 : 0;
        }
        $notReporting = 0;
        $toUpdate = 0;
        $ok = 0;
        foreach ($sites as $site) {
            foreach (Checks::forRow($site, $now, $context) as $check) {
                if ($check['id'] === 'reporting') {
                    $silent = in_array($check['state'], ['bad', 'unknown'], true);
                    $notReporting += $silent ? 1 : 0;
                    $ok += !$silent && !isset($attention[(int) ($site['id'] ?? 0)]) ? 1 : 0;
                }
            }
            $toUpdate += self::needsUpdate($site) ? 1 : 0;
        }

        return ['sites' => count($sites), 'bad' => $bad, 'warn' => count($items) - $bad, 'ok_sites' => $ok,
            'not_reporting' => $notReporting, 'to_update' => $toUpdate];
    }

    /**
     * The matrix: a row per site with each check's state, detail and fix link, the counts of its states, sorted – the worst
     * first (bad, then warn, then unknown, then the name), by name, or by customer (and the worst within it).
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param array<int, string> $customers id => name (Customers::names)
     * @param array{security_version?: string} $context
     * @return list<array<string, mixed>> site_id, site, url, customer_id, customer, update_needed, checks (id => state, detail, label, link), bad, warn, unknown
     */
    public static function matrix(array $sites, int $now, array $context = [], string $sort = 'worst', array $customers = []): array
    {
        $rows = [];
        foreach ($sites as $site) {
            $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
            $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
            $customer = Console::customerIdOf($site);
            $row = ['site_id' => (int) ($site['id'] ?? 0), 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => $customer > 0 ? $customer : null,
                'customer' => $customers[$customer] ?? null, 'update_needed' => self::needsUpdate($site), 'checks' => [], 'bad' => 0, 'warn' => 0, 'unknown' => 0];
            foreach (Checks::forRow($site, $now, $context) as $check) {
                $row['checks'][$check['id']] = ['state' => $check['state'], 'detail' => $check['detail'], 'label' => $check['label'], 'link' => Checks::link($url, $check['id'])];
                $row['bad'] += $check['state'] === 'bad' ? 1 : 0;
                $row['warn'] += $check['state'] === 'warn' ? 1 : 0;
                $row['unknown'] += $check['state'] === 'unknown' ? 1 : 0;
            }
            $rows[] = $row;
        }
        $worst = static fn (array $a, array $b): int => [$b['bad'], $b['warn'], $b['unknown']] <=> [$a['bad'], $a['warn'], $a['unknown']];
        $byName = static fn (array $a, array $b): int => [mb_strtolower($a['site']), $a['site_id']] <=> [mb_strtolower($b['site']), $b['site_id']];
        usort($rows, match ($sort) {
            'name' => $byName,
            'customer' => static fn (array $a, array $b): int => [$a['customer'] === null ? 1 : 0, mb_strtolower($a['customer'] ?? '')] <=> [$b['customer'] === null ? 1 : 0, mb_strtolower($b['customer'] ?? '')]
                ?: ($worst($a, $b) ?: $byName($a, $b)),
            default => static fn (array $a, array $b): int => $worst($a, $b) ?: $byName($a, $b),
        });

        return $rows;
    }

    /**
     * The workload rows sorted by one count (Checks::WORKLOAD) or the site's name; the sites without the count last.
     *
     * @param list<array<string, mixed>> $rows Checks::workload()['sites']
     * @return list<array<string, mixed>>
     */
    public static function sortWorkload(array $rows, string $by, string $dir): array
    {
        $sign = $dir === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($by, $sign): int {
            $name = [mb_strtolower((string) $a['site']), $a['site_id']] <=> [mb_strtolower((string) $b['site']), $b['site_id']];
            if (!in_array($by, Checks::WORKLOAD, true)) {
                return $sign === 1 ? $name : -$name;
            }
            if ($a[$by] === null || $b[$by] === null) {
                return [$a[$by] === null ? 1 : 0] <=> [$b[$by] === null ? 1 : 0] ?: $name;
            }

            return $sign * ($a[$by] <=> $b[$by]) ?: $name;
        });

        return $rows;
    }

    /** "%d h" under two days, "%d days" from then; '–' without a start. */
    public static function age(?int $since, int $now): string
    {
        if ($since === null) {
            return '–';
        }
        $hours = max(0, intdiv($now - $since, 3600));

        return $hours < 48 ? t('%d h', $hours) : t('%d days', intdiv($hours, 24));
    }
}
