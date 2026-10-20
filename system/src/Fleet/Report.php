<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\Db;
use Kaleta\Core\Language;
use Kaleta\Core\MonthlyReport;
use Kaleta\Core\ReportPage;
use Kaleta\Core\Settings;
use Kaleta\Core\View;

/**
 * The monthly fleet report per customer (3.17, Fleet → Reports, MCP fleet_report): on the 1st the console builds, from its
 * own history (Fleet\History), one report per customer (Fleet\Customers by id – the inbox's customer filter; 0 = the
 * sites without one; the name as it was is stored with it, so a renamed customer keeps its archive) for the month
 * before – per site the uptime, the down periods, the versions installed, how often it needed attention and why, and the
 * site's own monthly report headline counts when its heartbeat carried them (report_summary: visits, enquiries, requests
 * handled, changes by people and by Claude, updates, backups).
 *
 * A summary of what happened to the sites – not an invoice: no hours, no prices, no work log; and never personal data
 * (MonthlyReport::scrub at build and on every read). Archived in ka_fleet_reports like the site's monthly report
 * (Core\ReportArchive): a month is stored once, a rebuild from the history still kept is the administrator's explicit
 * action, KEEP_MONTHS are kept. Printed with the same A4 page as the site's report (views/admin/settings/report_print.php,
 * the console's agency branding). Moments inside the JSON are ISO 8601 with the offset.
 */
final class Report
{
    public const int SCHEMA = 1;

    public const int KEEP_MONTHS = 24;

    /** On the 1st the report waits until this hour, so the sites have archived their own month and reported it. */
    public const int HOUR = 6;

    /**
     * The data of one customer's month ($customerId 0 = the sites without a customer, $customer its name now).
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @return array<string, mixed>
     */
    public static function build(Db $db, \DateTimeImmutable $monthStart, int $customerId, string $customer, array $sites, int $now): array
    {
        $month = $monthStart->format('Y-m');
        $next = $monthStart->modify('first day of next month');
        $from = $monthStart->format('Y-m-d H:i:s');
        $to = $next->format('Y-m-d H:i:s');
        $out = [];
        foreach ($sites as $site) {
            $id = (int) $site['id'];
            $days = History::days($db, $id, $monthStart->format('Y-m-d'), $next->modify('-1 day')->format('Y-m-d'));
            $out[] = self::siteData($site, $days, History::incidents($db, $id, $from, $to, $now), History::versions($db, $id, $from, $to), self::summaryFor($db, $site, $month));
        }

        return MonthlyReport::scrub(['schema' => self::SCHEMA, 'month' => $month, 'customer_id' => $customerId, 'customer' => $customer, 'generated_at' => date(DATE_ATOM, $now),
            'sites' => $out, 'totals' => self::totals($out)]);
    }

    /**
     * One site's month. Pure.
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     * @param array<string, array<string, mixed>> $days its rows of ka_fleet_history in the month
     * @param list<array{started_at: string, ended_at: ?string, status: int, minutes: int}> $incidents
     * @param list<array{date: string, version: string}> $versions
     * @param array<string, mixed>|null $summary the site's report_summary of that month
     * @return array<string, mixed>
     */
    public static function siteData(array $site, array $days, array $incidents, array $versions, ?array $summary): array
    {
        $iso = static fn (?string $moment): ?string => $moment === null ? null : (($m = Clock::fromDb($moment)) !== null ? $m->format(DATE_ATOM) : null);
        $recorded = array_values(array_filter(array_map(static fn (array $d): string => (string) $d['version'], $days), static fn (string $v): bool => $v !== ''));

        return [
            'id' => (int) $site['id'], 'name' => (string) $site['name'], 'url' => (string) $site['url'],
            'days_recorded' => count($days),
            'uptime' => History::uptime($days),
            'incidents' => array_map(static fn (array $i): array => ['start' => $iso($i['started_at']), 'end' => $iso($i['ended_at']), 'minutes' => $i['minutes'], 'status' => $i['status']], $incidents),
            'downtime_minutes' => array_sum(array_column($incidents, 'minutes')),
            'version_start' => $recorded[0] ?? (string) $site['version'],
            'version_end' => $recorded !== [] ? $recorded[count($recorded) - 1] : (string) $site['version'],
            'updates' => array_map(static fn (array $v): array => ['date' => $iso($v['date']), 'version' => $v['version']], $versions),
            'attention' => History::attention($days),
            'summary' => $summary !== null && ($summary = History::summary($summary)) !== null ? array_diff_key($summary, ['month' => true]) : null,
        ];
    }

    /**
     * The totals of a customer's month. Pure.
     *
     * @param list<array<string, mixed>> $sites siteData() of each site
     * @return array<string, mixed>
     */
    public static function totals(array $sites): array
    {
        $checks = array_sum(array_map(static fn (array $s): int => (int) $s['uptime']['checks'], $sites));
        $failed = array_sum(array_map(static fn (array $s): int => (int) $s['uptime']['failed'], $sites));
        $summed = static function (string $key) use ($sites): ?int {
            $values = array_filter(array_map(static fn (array $s): mixed => is_array($s['summary']) ? $s['summary'][$key] ?? null : null, $sites), 'is_int');

            return $values === [] ? null : (int) array_sum($values);
        };

        return ['sites' => count($sites), 'uptime_percent' => $checks > 0 ? floor(10000 * ($checks - $failed) / $checks) / 100 : null,
            'incidents' => array_sum(array_map(static fn (array $s): int => count($s['incidents']), $sites)), 'downtime_minutes' => array_sum(array_column($sites, 'downtime_minutes')),
            'updates' => array_sum(array_map(static fn (array $s): int => count($s['updates']), $sites)),
            'visits' => $summed('visits'), 'enquiries' => $summed('enquiries'), 'requests_handled' => $summed('requests_handled')];
    }

    /**
     * The site's own report headline counts for a month: the latest the console kept from its heartbeats (ka_fleet_history,
     * the days after the month), or the one in its stored heartbeat; null when none reached the console.
     *
     * @param array<string, mixed> $site
     * @return array<string, mixed>|null
     */
    private static function summaryFor(Db $db, array $site, string $month): ?array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
        $current = History::summary(is_array($beat) ? $beat['report_summary'] ?? null : null);
        if ($current !== null && $current['month'] === $month) {
            return $current;
        }
        $kept = $db->value('SELECT report_summary FROM {fleet_history} WHERE site_id = ? AND day >= ? AND report_summary LIKE ? ORDER BY day DESC LIMIT 1',
            [(int) $site['id'], $month . '-01', '%"month":"' . $month . '"%']);
        $kept = History::summary(json_decode((string) $kept, true));

        return $kept !== null && $kept['month'] === $month ? $kept : null;
    }

    /**
     * The sites grouped by customer id – the customers in the order of the inbox's customer filter (Customers::names), the
     * sites without a customer (id 0, name '') last. The weekly digest groups the same way.
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param array<int, string> $customers id => name (Customers::names)
     * @return list<array{id: int, name: string, sites: list<array<string, mixed>>}>
     */
    public static function byCustomer(array $sites, array $customers): array
    {
        $groups = [];
        foreach ($sites as $site) {
            $id = Console::customerIdOf($site);
            $groups[isset($customers[$id]) ? $id : 0][] = $site;
        }
        $out = [];
        foreach ([...array_keys($customers), 0] as $id) {
            if (isset($groups[$id])) {
                $out[] = ['id' => $id, 'name' => $customers[$id] ?? '', 'sites' => $groups[$id]];
            }
        }

        return $out;
    }

    /**
     * Builds and stores a month for every customer. A stored one stays unless $rebuild. Returns how many were stored.
     */
    public static function store(App $app, \DateTimeImmutable $monthStart, bool $rebuild = false): int
    {
        $db = $app->db();
        $month = $monthStart->format('Y-m');
        $count = 0;
        foreach (self::byCustomer(Console::sites($db), Customers::names($db)) as ['id' => $customerId, 'name' => $customer, 'sites' => $sites]) {
            if (!$rebuild && self::get($db, $month, $customerId) !== null) {
                continue;
            }
            $data = Language::runWith(Language::defaults($app->settings()), fn (): array => self::build($db, $monthStart, $customerId, $customer, $sites, time()), 'admin-');
            $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $now = Clock::now();
            if ($rebuild) {
                $db->run('INSERT INTO {fleet_reports} (month, customer_id, customer, created_at, rebuilt_at, schema_version, data) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE customer = ?, rebuilt_at = ?, schema_version = ?, data = ?', [$month, $customerId, $customer, $now, $now, self::SCHEMA, $json, $customer, $now, self::SCHEMA, $json]);
            } else {
                $db->run('INSERT IGNORE INTO {fleet_reports} (month, customer_id, customer, created_at, schema_version, data) VALUES (?, ?, ?, ?, ?, ?)', [$month, $customerId, $customer, $now, self::SCHEMA, $json]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * One stored report of a customer (its id, 0 = the sites without a customer) with its data (scrubbed again), null when
     * there is none. customer is the name the customer had when the report was made.
     *
     * @return array{month: string, customer_id: int, customer: string, created_at: string, rebuilt_at: ?string, schema_version: int, data: array<string, mixed>}|null
     */
    public static function get(Db $db, string $month, int $customerId): ?array
    {
        if (preg_match(\Kaleta\Core\ReportArchive::MONTH_PATTERN, $month) !== 1) {
            return null;
        }
        $row = $db->one('SELECT month, customer_id, customer, created_at, rebuilt_at, schema_version, data FROM {fleet_reports} WHERE month = ? AND customer_id = ?', [$month, max(0, $customerId)]);
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        $customer = (string) $row['customer'];

        return ['month' => (string) $row['month'], 'customer_id' => (int) $row['customer_id'], 'customer' => $customer, 'created_at' => (string) $row['created_at'],
            'rebuilt_at' => $row['rebuilt_at'] === null ? null : (string) $row['rebuilt_at'], 'schema_version' => (int) $row['schema_version'],
            'data' => is_array($data) ? MonthlyReport::scrub($data) : ['month' => $month, 'customer_id' => (int) $row['customer_id'], 'customer' => $customer, 'sites' => []]];
    }

    /**
     * Every stored report, the newest month first; in a month the customers in the order of the inbox's customer filter
     * (by their name now), the sites without a customer last. customer = the name when it was made.
     *
     * @return list<array{month: string, customer_id: int, customer: string, created_at: string, rebuilt_at: ?string, sites: int}>
     */
    public static function all(Db $db): array
    {
        $rows = array_map(static fn (array $r): array => ['month' => (string) $r['month'], 'customer_id' => (int) $r['customer_id'], 'customer' => (string) $r['customer'],
            'created_at' => (string) $r['created_at'], 'rebuilt_at' => $r['rebuilt_at'] === null ? null : (string) $r['rebuilt_at'], 'sites' => (int) $r['sites']],
            $db->all("SELECT month, customer_id, customer, created_at, rebuilt_at, COALESCE(JSON_LENGTH(data, '$.sites'), 0) AS sites FROM {fleet_reports} ORDER BY month DESC, customer"));
        $order = array_flip(array_keys(Customers::names($db)));
        usort($rows, static fn (array $a, array $b): int => [$b['month'], $a['customer_id'] === 0 ? 1 : 0, $order[$a['customer_id']] ?? PHP_INT_MAX, $a['customer']]
            <=> [$a['month'], $b['customer_id'] === 0 ? 1 : 0, $order[$b['customer_id']] ?? PHP_INT_MAX, $b['customer']]);

        return $rows;
    }

    /**
     * The newest stored month of each customer (the Customers tab links to it).
     *
     * @return array<int, string> customer id => YYYY-MM
     */
    public static function latestByCustomer(Db $db): array
    {
        return array_map('strval', $db->pairs('SELECT customer_id, MAX(month) FROM {fleet_reports} GROUP BY customer_id'));
    }

    /** Deletes the months older than KEEP_MONTHS. */
    public static function prune(Db $db, \DateTimeImmutable $now): int
    {
        return $db->run('DELETE FROM {fleet_reports} WHERE month < ?', [$now->modify('first day of this month')->modify('-' . self::KEEP_MONTHS . ' months')->format('Y-m')])->rowCount();
    }

    /** The background job fleet_reports (hourly): the previous month once, from HOUR on the 1st; 24 months kept. */
    public static function runIfDue(App $app): string
    {
        $db = $app->db();
        $now = new \DateTimeImmutable();
        $pruned = self::prune($db, $now);
        $month = MonthlyReport::previousMonth($now);
        $suffix = $pruned > 0 ? ', removed ' . $pruned : '';
        if ((int) $now->format('j') === 1 && (int) $now->format('G') < self::HOUR) {
            return 'waiting for the sites' . $suffix;
        }
        if ($db->value('SELECT 1 FROM {fleet_reports} WHERE month = ? LIMIT 1', [$month->format('Y-m')]) !== null) {
            return 'stored already' . $suffix;
        }
        // a month the console has no history of (before 3.17, or before the first site) is not made up
        if ($db->value('SELECT 1 FROM {fleet_history} WHERE day >= ? AND day < ? LIMIT 1', [$month->format('Y-m-d'), $month->modify('first day of next month')->format('Y-m-d')]) === null) {
            return 'no history' . $suffix;
        }

        return 'stored ' . self::store($app, $month) . ' for ' . $month->format('Y-m') . $suffix;
    }

    /** The name of a customer in a report: '' is the sites without a customer. */
    public static function customerName(string $customer): string
    {
        return $customer !== '' ? $customer : t('Sites without a customer');
    }

    /**
     * The report as sections of blocks for the print page (the shape ReportPage::sections gives), in the current language.
     * Pure; every string scrubbed again.
     *
     * @param array<string, mixed> $data from build()
     * @return list<array{key: string, title: string, blocks: list<array<string, mixed>>}>
     */
    public static function sections(array $data): array
    {
        $data = MonthlyReport::scrub($data);
        $n = static fn (mixed $value): string => format_number((int) $value, 0);
        $percent = static fn (mixed $value): string => is_float($value) || is_int($value) ? format_number((float) $value, 2) . ' %' : t('not measured');
        $date = static fn (mixed $value, bool $time = true): string => ($moment = MonthlyReport::moment($value)) !== null ? format_date($moment, $time) : '';
        $duration = static fn (int $minutes): string => $minutes >= 120 ? t('%s hours', format_number($minutes / 60, 1)) : t('%d minutes', $minutes);
        $reasonLabels = require KALETA_SYSTEM . '/views/admin/fleet/reasons.php';
        $sites = array_values(array_filter((array) ($data['sites'] ?? []), 'is_array'));
        $totals = (array) ($data['totals'] ?? []);
        $out = [];
        $add = static function (string $key, string $title, array $blocks) use (&$out): void {
            $blocks = array_values(array_filter($blocks));
            if ($blocks !== []) {
                $out[] = ['key' => $key, 'title' => $title, 'blocks' => $blocks];
            }
        };

        $facts = [[t('Sites'), $n($totals['sites'] ?? count($sites))], [t('Uptime'), $percent($totals['uptime_percent'] ?? null)], [t('Outages'), $n($totals['incidents'] ?? 0)],
            [t('Updates installed'), $n($totals['updates'] ?? 0)]];
        foreach (['visits' => 'Visits', 'enquiries' => 'Enquiries received', 'requests_handled' => 'Requests handled'] as $key => $label) {
            if (is_int($totals[$key] ?? null)) {
                $facts[] = [t($label), $n($totals[$key])];
            }
        }
        $add('summary', t('At a glance'), [['type' => 'facts', 'items' => $facts]]);
        $add('sites', t('Sites'), [
            $sites === [] ? ['type' => 'note', 'text' => t('No site of this customer reported to the console this month.')]
                : ['type' => 'table', 'caption' => t('Sites this month'), 'head' => [t('Site'), t('Version'), t('Uptime'), t('Outages'), t('Days needing attention')],
                    'rows' => array_map(static fn (array $s): array => [(string) ($s['name'] !== '' ? $s['name'] : $s['url']), (string) $s['version_end'], $percent($s['uptime']['percent'] ?? null),
                        $n(count((array) $s['incidents'])), $n($s['attention']['days'] ?? 0)], $sites), 'numeric' => [2, 3, 4], 'short' => [1]],
        ]);
        foreach ($sites as $s) {
            $uptime = (array) $s['uptime'];
            $attention = (array) $s['attention'];
            $incidents = array_values(array_filter((array) $s['incidents'], 'is_array'));
            $reasons = array_map('intval', (array) ($attention['reasons'] ?? []));
            $summary = is_array($s['summary'] ?? null) ? $s['summary'] : null;
            $own = [];
            foreach (['visits' => 'Visits', 'enquiries' => 'Enquiries received', 'requests_handled' => 'Requests handled', 'changes_people' => 'Changes by people',
                'changes_claude' => 'Changes by Claude', 'updates' => 'Updates installed', 'backups' => 'Backups made'] as $key => $label) {
                if (is_int($summary[$key] ?? null)) {
                    $own[] = [t($label), $n($summary[$key])];
                }
            }
            $add('site-' . (int) $s['id'], (string) ($s['name'] !== '' ? $s['name'] : $s['url']), [
                ['type' => 'pairs', 'rows' => array_values(array_filter([
                    [t('Address'), (string) preg_replace('#^https?://#', '', (string) $s['url'])],
                    [t('Uptime'), $percent($uptime['percent'] ?? null) . ((int) ($uptime['checks'] ?? 0) > 0 ? ' · ' . t('%s checks', $n($uptime['checks'])) : '')],
                    ($uptime['response_ms'] ?? null) !== null ? [t('Average response'), t('%d ms', (int) $uptime['response_ms'])] : null,
                    [t('Outages'), $incidents === [] ? t('none') : $n(count($incidents)) . ' · ' . $duration((int) $s['downtime_minutes'])],
                    [t('Version'), (string) $s['version_start'] !== (string) $s['version_end'] ? $s['version_start'] . ' → ' . $s['version_end'] : (string) $s['version_end']],
                    [t('Days needing attention'), t('%d of %d days recorded', (int) ($attention['days'] ?? 0), (int) ($attention['recorded'] ?? 0))],
                ]))],
                $incidents === [] ? null : ['type' => 'table', 'caption' => t('Outages'), 'head' => [t('Start'), t('End'), t('Duration')],
                    'rows' => array_map(static fn (array $i): array => [$date($i['start']), $i['end'] !== null ? $date($i['end']) : t('still down at the end of the month'), $duration((int) $i['minutes'])], $incidents),
                    'numeric' => [2], 'short' => [0, 1]],
                (array) $s['updates'] === [] ? null : ['type' => 'table', 'caption' => t('Updates installed'), 'head' => [t('Date'), t('Version')],
                    'rows' => array_map(static fn (array $u): array => [$date($u['date']), (string) $u['version']], (array) $s['updates']), 'numeric' => [], 'short' => [0, 1]],
                $reasons === [] ? null : ['type' => 'table', 'caption' => t('What needed attention'), 'head' => [t('Reason'), t('Days')],
                    'rows' => array_map(static fn (string $r, int $days): array => [$reasonLabels[$r] ?? $r, $n($days)], array_keys($reasons), $reasons), 'numeric' => [1], 'short' => []],
                $own !== [] ? ['type' => 'table', 'caption' => t('The site\'s own monthly report'), 'head' => [t('What'), t('Count')], 'rows' => $own, 'numeric' => [1], 'short' => []]
                    : ['type' => 'note', 'text' => t('The site\'s own monthly report for this month has not reached the console (the site sends it from Kaleta 3.17 on).')],
                (int) ($s['days_recorded'] ?? 0) === 0 ? ['type' => 'note', 'text' => t('The console has no history of this site for this month.')] : null,
            ]);
        }

        return $out;
    }

    /**
     * The whole page as an HTML document – the site's monthly report page (Core\ReportPage) with the console's agency.
     *
     * @param array{month: string, customer: string, created_at: string, rebuilt_at: ?string, data: array<string, mixed>} $stored
     * @param array{script?: string, download?: string, back?: string} $links
     */
    public static function render(View $view, array $stored, Settings $s, string $consoleUrl, array $links = []): string
    {
        return Language::runWith(Language::defaults($s), function () use ($view, $stored, $s, $consoleUrl, $links): string {
            $base = ReportPage::viewData(['month' => $stored['month'], 'created_at' => $stored['created_at'], 'rebuilt_at' => $stored['rebuilt_at'], 'data' => []], $s, $consoleUrl);
            $customer = self::customerName($stored['customer']);
            $sites = count((array) ($stored['data']['sites'] ?? []));

            return $view->render('admin/settings/report_print', array_replace($base, [
                'title' => t('Fleet report – %s – %s', $base['monthName'], $customer),
                'siteName' => $customer,
                'brand' => $s->get('site_name'),
                'siteAddress' => $sites === 1 ? t('1 site') : t('%d sites', $sites),
                'kind' => t('Fleet report'),
                'footer' => t('A summary of what happened to these websites in %s: uptime, outages, updates and counts – no personal data.', $base['monthName']),
                'sections' => self::sections($stored['data']),
                'links' => $links,
                'rebuiltNote' => t('rebuilt %s from the history still kept', $base['rebuilt'] ?? ''),
            ]));
        }, 'admin-');
    }
}
