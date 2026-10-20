<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Db;

/**
 * What the fleet console remembers (3.17): the console stores only a site's LAST heartbeat, so the trends, the alerts that
 * look back, the weekly digest and the monthly fleet report read this history instead.
 *
 *  - ka_fleet_history: one row per site and day. Every uptime check (Console::checkUptime, every 5 minutes) adds to the
 *    day's counts – answered or not, and the response time; the hourly job fleet_history writes the day's last status,
 *    version, attention score and reasons and what waited from the stored heartbeat (snapshotRow). Counts only.
 *  - ka_fleet_incidents: the periods a site did not answer – opened when the console marks it down, closed when it answers.
 *  - Versions installed come from the events fleet.site_updated (kept half a year, enough for the month just ended).
 * KEEP_DAYS are kept. The console never asks a site for any of it: the site reports, the console checks its home page.
 */
final class History
{
    public const int KEEP_DAYS = 400;

    /** How many days the trend on a site's page shows. */
    public const int TREND_DAYS = 90;

    /** The site's monthly report headline counts the heartbeat may carry (report_summary) – counts only. */
    public const array SUMMARY_COUNTS = ['visits', 'enquiries', 'requests_handled', 'changes_people', 'changes_claude', 'updates', 'backups'];

    /** One uptime check added to the site's day (Console::checkUptime). $ms is the response time of an answer, null without one. */
    public static function recordCheck(Db $db, int $siteId, bool $up, ?int $ms, string $day): void
    {
        $answered = $ms !== null ? 1 : 0;
        $ms ??= 0;
        $db->run('INSERT INTO {fleet_history} (site_id, day, checks_ok, checks_failed, response_ms_total, response_count) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE checks_ok = checks_ok + ?, checks_failed = checks_failed + ?, response_ms_total = response_ms_total + ?, response_count = response_count + ?',
            [$siteId, $day, $up ? 1 : 0, $up ? 0 : 1, $ms, $answered, $up ? 1 : 0, $up ? 0 : 1, $ms, $answered]);
    }

    /** A site went down: the period starts with its first failed check. */
    public static function openIncident(Db $db, int $siteId, string $startedAt, int $status): void
    {
        if ($db->value('SELECT 1 FROM {fleet_incidents} WHERE site_id = ? AND ended_at IS NULL', [$siteId]) === null) {
            $db->insert('fleet_incidents', ['site_id' => $siteId, 'started_at' => $startedAt, 'status' => max(0, min(999, $status))]);
        }
    }

    /** A site answers again: its open period ends. */
    public static function closeIncident(Db $db, int $siteId, string $endedAt): void
    {
        $db->run('UPDATE {fleet_incidents} SET ended_at = ? WHERE site_id = ? AND ended_at IS NULL', [$endedAt, $siteId]);
    }

    /** Everything the console remembers about a site that left it. */
    public static function forget(Db $db, int $siteId): void
    {
        $db->run('DELETE FROM {fleet_history} WHERE site_id = ?', [$siteId]);
        $db->run('DELETE FROM {fleet_incidents} WHERE site_id = ?', [$siteId]);
    }

    /** The hourly job (Core\Scheduler fleet_history): today's row of every site from its stored heartbeat, older rows deleted. */
    public static function run(App $app): string
    {
        $db = $app->db();
        $now = time();
        $sites = Console::sites($db);
        foreach ($sites as $site) {
            self::snapshot($db, $site, $now);
        }
        $pruned = self::prune($db, $now);

        return 'recorded ' . count($sites) . ($pruned > 0 ? ', removed ' . $pruned : '');
    }

    /** @param array<string, mixed> $site a row of ka_fleet_sites */
    public static function snapshot(Db $db, array $site, int $now): void
    {
        $row = self::snapshotRow($site, $now);
        $columns = array_keys($row);
        $db->run('INSERT INTO {fleet_history} (site_id, day, ' . implode(', ', $columns) . ') VALUES (?, ?' . str_repeat(', ?', count($columns)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn (string $c): string => $c . ' = ?', $columns)),
            [(int) $site['id'], date('Y-m-d', $now), ...array_values($row), ...array_values($row)]);
    }

    /**
     * The day's state of a site from its stored heartbeat: status, version, attention and what waited – pure, so it is
     * tested on its own. A count the site does not send stays null (an older site, a feature that is off).
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     * @return array<string, mixed>
     */
    public static function snapshotRow(array $site, int $now): array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
        $beat = is_array($beat) ? $beat : [];
        $attention = Console::attention($site, $now);
        $count = fn (mixed $n): ?int => is_int($n) ? max(0, $n) : null;
        $requests = is_array($beat['requests'] ?? null) ? $beat['requests'] : null;
        $pending = is_array($beat['pending_review'] ?? null) ? array_filter($beat['pending_review'], 'is_int') : null;
        $summary = self::summary($beat['report_summary'] ?? null);

        return [
            'status' => in_array($site['status'] ?? '', ['ok', 'warning', 'error'], true) ? (string) $site['status'] : '',
            'version' => mb_substr((string) ($site['version'] ?? ''), 0, 30),
            'attention' => min(65535, $attention['score']),
            'reasons' => mb_substr(implode(',', $attention['reasons']), 0, 255),
            'enquiries_new' => Console::enquiriesWaiting($beat),
            'requests_open' => $requests !== null ? $count(($requests['new'] ?? 0) + ($requests['in_progress'] ?? 0)) : null,
            'waiting_total' => $pending !== null ? (int) array_sum($pending) : null,
            'audit_total' => is_array($beat['audit'] ?? null) ? (int) array_sum(array_filter($beat['audit'], 'is_int')) : null,
            'failed_sign_ins' => $count($beat['security_7_days']['failed_sign_ins'] ?? null),
            'problems' => is_array($beat['problems'] ?? null) ? count($beat['problems']) : null,
            'report_summary' => $summary !== null ? (string) json_encode($summary) : null,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ];
    }

    /**
     * The site's monthly report headline counts as the console keeps them: the month and the known counts only.
     *
     * @return array<string, int|string|null>|null
     */
    public static function summary(mixed $value): ?array
    {
        if (!is_array($value) || !is_string($value['month'] ?? null) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value['month']) !== 1) {
            return null;
        }
        $out = ['month' => $value['month']];
        foreach (self::SUMMARY_COUNTS as $key) {
            $out[$key] = is_int($value[$key] ?? null) ? max(0, min(1_000_000_000, $value[$key])) : null;
        }

        return $out;
    }

    /** The oldest day kept at a moment ('Y-m-d'): KEEP_DAYS before its day. Pure. */
    public static function oldestKept(int $now): string
    {
        return (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d');
    }

    /** Deletes the days and the incidents older than KEEP_DAYS. Returns how many rows went. */
    public static function prune(Db $db, int $now): int
    {
        $oldest = self::oldestKept($now);

        return $db->run('DELETE FROM {fleet_history} WHERE day < ?', [$oldest])->rowCount()
            + $db->run('DELETE FROM {fleet_incidents} WHERE ended_at IS NOT NULL AND ended_at < ?', [$oldest . ' 00:00:00'])->rowCount();
    }

    /**
     * The days of one site from $from to $to (both 'Y-m-d', inclusive), keyed by the day.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function days(Db $db, int $siteId, string $from, string $to): array
    {
        $out = [];
        foreach ($db->all('SELECT * FROM {fleet_history} WHERE site_id = ? AND day >= ? AND day <= ? ORDER BY day', [$siteId, $from, $to]) as $row) {
            $out[(string) $row['day']] = $row;
        }

        return $out;
    }

    /**
     * Uptime of a set of days, from the checks the console made: the share answered, how many checks, how many failed and
     * the average response time. Pure. Null percent and time when there was no check.
     *
     * @param iterable<array<string, mixed>> $days rows of ka_fleet_history
     * @return array{percent: ?float, checks: int, failed: int, response_ms: ?int}
     */
    public static function uptime(iterable $days): array
    {
        $ok = $failed = $ms = $answered = 0;
        foreach ($days as $day) {
            $ok += (int) $day['checks_ok'];
            $failed += (int) $day['checks_failed'];
            $ms += (int) $day['response_ms_total'];
            $answered += (int) $day['response_count'];
        }
        $checks = $ok + $failed;

        return ['percent' => $checks > 0 ? floor(10000 * $ok / $checks) / 100 : null, 'checks' => $checks, 'failed' => $failed, 'response_ms' => $answered > 0 ? (int) round($ms / $answered) : null];
    }

    /**
     * The uptime of each of the $count days up to $lastDay (oldest first) – the bars of the trend. Pure.
     *
     * @param array<string, array<string, mixed>> $days rows keyed by the day (days())
     * @return list<array{day: string, percent: ?float, response_ms: ?int}>
     */
    public static function bars(array $days, string $lastDay, int $count): array
    {
        $end = new \DateTimeImmutable($lastDay);
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $day = $end->modify('-' . $i . ' days')->format('Y-m-d');
            $uptime = self::uptime(isset($days[$day]) ? [$days[$day]] : []);
            $out[] = ['day' => $day, 'percent' => $uptime['percent'], 'response_ms' => $uptime['response_ms']];
        }

        return $out;
    }

    /**
     * The periods a site was down that touch [$from, $to) ('Y-m-d H:i:s'), oldest first, with their minutes inside the
     * period (an open one counts until $now).
     *
     * @return list<array{started_at: string, ended_at: ?string, status: int, minutes: int}>
     */
    public static function incidents(Db $db, int $siteId, string $from, string $to, int $now): array
    {
        $rows = $db->all('SELECT started_at, ended_at, status FROM {fleet_incidents} WHERE site_id = ? AND started_at < ? AND (ended_at IS NULL OR ended_at >= ?) ORDER BY started_at, id',
            [$siteId, $to, $from]);

        return array_map(fn (array $r): array => ['started_at' => (string) $r['started_at'], 'ended_at' => $r['ended_at'] === null ? null : (string) $r['ended_at'], 'status' => (int) $r['status'],
            'minutes' => self::minutes((string) $r['started_at'], $r['ended_at'] === null ? null : (string) $r['ended_at'], (int) strtotime($from), (int) strtotime($to), $now)], $rows);
    }

    /** The minutes of a down period inside [$periodStart, $periodEnd); an open period counts until $now. Pure. */
    public static function minutes(string $startedAt, ?string $endedAt, int $periodStart, int $periodEnd, int $now): int
    {
        $start = max($periodStart, (int) strtotime($startedAt));
        $end = min($periodEnd, $endedAt === null ? $now : (int) strtotime($endedAt));

        return $end > $start ? intdiv($end - $start, 60) : 0;
    }

    /**
     * The versions a site reported in [$from, $to) – from the events fleet.site_updated (Console::heartbeat), oldest first.
     *
     * @return list<array{date: string, version: string}>
     */
    public static function versions(Db $db, int $siteId, string $from, string $to): array
    {
        $out = [];
        foreach ($db->all("SELECT created_at, data FROM {events} WHERE type = 'fleet.site_updated' AND JSON_EXTRACT(data, '$.site') = ? AND created_at >= ? AND created_at < ? ORDER BY id",
            [$siteId, $from, $to]) as $row) {
            $data = json_decode((string) $row['data'], true);
            $version = is_array($data) && is_string($data['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]{1,20})?$/D', $data['version']) === 1 ? $data['version'] : null;
            if ($version !== null) {
                $out[] = ['date' => (string) $row['created_at'], 'version' => $version];
            }
        }

        return $out;
    }

    /**
     * The attention of a set of days: on how many days a site needed attention (a score of at least "warnings"), of how
     * many recorded, and the reasons by the number of days. Pure.
     *
     * @param iterable<array<string, mixed>> $days rows of ka_fleet_history
     * @return array{days: int, recorded: int, reasons: array<string, int>}
     */
    public static function attention(iterable $days): array
    {
        $needing = $recorded = 0;
        $reasons = [];
        foreach ($days as $day) {
            if ($day['attention'] === null) {
                continue;
            }
            $recorded++;
            $needing += (int) $day['attention'] >= Console::REASONS['warnings'] ? 1 : 0;
            foreach (array_filter(explode(',', (string) $day['reasons'])) as $reason) {
                if (isset(Console::REASONS[$reason])) {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        }
        arsort($reasons);

        return ['days' => $needing, 'recorded' => $recorded, 'reasons' => $reasons];
    }

    /**
     * What a site's page shows (3.17): uptime and response this month and last month, the last TREND_DAYS days as bars,
     * the down periods and the versions in those days.
     *
     * @return array<string, mixed>
     */
    public static function trend(Db $db, int $siteId, int $now): array
    {
        $today = date('Y-m-d', $now);
        $from = date('Y-m-d', $now - (self::TREND_DAYS - 1) * 86400);
        $monthStart = date('Y-m-01', $now);
        $lastMonthStart = date('Y-m-01', (int) strtotime($monthStart . ' -1 month'));
        $earliest = min($from, $lastMonthStart);
        $days = self::days($db, $siteId, $earliest, $today);
        $inRange = fn (string $a, string $b): array => array_filter($days, fn (string $day): bool => $day >= $a && $day < $b, ARRAY_FILTER_USE_KEY);

        return [
            'this_month' => self::uptime($inRange($monthStart, '9999-12-31')),
            'last_month' => self::uptime($inRange($lastMonthStart, $monthStart)),
            'bars' => self::bars($days, $today, self::TREND_DAYS),
            'incidents' => array_reverse(self::incidents($db, $siteId, $from . ' 00:00:00', date('Y-m-d H:i:s', $now + 1), $now)),
            'versions' => array_reverse(self::versions($db, $siteId, $from . ' 00:00:00', date('Y-m-d H:i:s', $now + 1))),
            'recorded' => $days !== [],
        ];
    }
}
