<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;
use Kaleta\Core\UptimeStrip;

/**
 * A paired site's own uptime (3.26.1). Only a console measures uptime (Console::checkUptime into Fleet\History), so the
 * site asks its console for its days: POST /fleet/uptime, signed by the site's key like the kit (Kit::receive); the
 * console answers – signed by its own key – with the checks and the failed checks of each day of this month and the one
 * before, and only for the site whose key signed the request. The site checks the console's signature, keeps the days in
 * the setting fleet_uptime_days (JSON {checked, received, days: [{date, checks, failed}]}, merged by the date, KEEP_MONTHS
 * months) and its monthly report reads them (Core\MonthlyReport 'uptime', drawn by Core\UptimeStrip).
 *
 * Refreshed once a day after a confirmed heartbeat (Link::send) and before the report archive stores a new month
 * (Core\ReportArchive::runIfDue), so the last day of the month is in its report. A console before 3.26.1 has no such
 * address: the site keeps what it has, tries again the next day and its report has no uptime – the heartbeat is never
 * affected, and the console's heartbeat answer is unchanged for sites before 3.26.1. Counts per day only, nothing personal.
 */
final class Uptime
{
    public const string SETTING = 'fleet_uptime_days';

    /** The site asks again after this many hours (a heartbeat goes every hour). */
    public const int REFRESH_HOURS = 20;

    /** How many days an answer may carry: two months and a little more. */
    public const int MAX_DAYS = 70;

    /** The months kept on the site: the current one and the two before (the report of the last month may be rebuilt later). */
    public const int KEEP_MONTHS = 3;

    /** Checks of one day at most: every 5 minutes is 288, a check by hand adds one. */
    private const int MAX_CHECKS = 100_000;

    /* ---------- the console ---------- */

    /** POST /fleet/uptime – the days of the site that signed the request, signed by the console. */
    public static function answer(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        $db = $app->db();
        $site = is_array($d) ? $db->one('SELECT id, public_key FROM {fleet_sites} WHERE id = ?', [(int) ($d['site_id'] ?? 0)]) : null;
        if ($site === null) {
            return Response::json(['error' => 'This console does not know the site.'], 404);
        }
        if (($d['action'] ?? '') !== 'uptime' || !Keys::verify($body, $signature, (string) $site['public_key']) || abs(time() - (int) ($d['ts'] ?? 0)) > Link::MAX_SKEW) {
            return Response::json(['error' => 'The signature or the time of the request is not valid.'], 403);
        }
        $now = time();
        $from = date('Y-m-01', (int) strtotime(date('Y-m-01', $now) . ' -1 month'));

        return Console::signed($app->settings(), ['ok' => true, 'days' => self::fromHistory(History::days($db, (int) $site['id'], $from, date('Y-m-d', $now)))]);
    }

    /**
     * The days as the console sends them: the date, the checks and the failed checks. Pure.
     *
     * @param array<string, array<string, mixed>> $rows rows of ka_fleet_history keyed by the day (History::days)
     * @return list<array{date: string, checks: int, failed: int}>
     */
    public static function fromHistory(array $rows): array
    {
        $out = [];
        foreach ($rows as $day => $row) {
            $failed = max(0, (int) ($row['checks_failed'] ?? 0));
            $out[] = ['date' => (string) $day, 'checks' => max(0, (int) ($row['checks_ok'] ?? 0)) + $failed, 'failed' => $failed];
        }

        return array_slice($out, -self::MAX_DAYS);
    }

    /* ---------- the member site ---------- */

    /**
     * After a confirmed heartbeat: the days, when they are due (or $force – the administrator's "Send now"). Never throws.
     * Returns a suffix for the job's result.
     */
    public static function afterHeartbeat(App $app, bool $force = false): string
    {
        try {
            return self::refresh($app, $force);
        } catch (\Throwable $e) {
            return ', uptime failed (' . mb_substr($e->getMessage(), 0, 100) . ')';
        }
    }

    /** Asks the console for the days when they are due (due()) or $force. Returns a suffix for the job's result. */
    public static function refresh(App $app, bool $force = false): string
    {
        $s = $app->settings();
        if (!Link::isPaired($s) || (!$force && !self::due(self::stored($s), time()))) {
            return '';
        }

        return self::fetch($app);
    }

    /**
     * Whether the site should ask again: nothing stored, the last question older than REFRESH_HOURS, or asked before the
     * current month began (the month just ended needs its last day). Pure.
     *
     * @param array{checked: int, received: ?int, days: list<array{date: string, checks: int, failed: int}>}|null $stored
     */
    public static function due(?array $stored, int $now): bool
    {
        if ($stored === null) {
            return true;
        }
        $monthStart = (int) strtotime(date('Y-m-01 00:00:00', $now));

        return $stored['checked'] <= $now - self::REFRESH_HOURS * 3600 || $stored['checked'] < $monthStart;
    }

    /** Asks the console, keeps what a valid answer brings. A console without the address (before 3.26.1) changes nothing. */
    public static function fetch(App $app): string
    {
        $s = $app->settings();
        $now = time();
        $answer = Http::post($s->get('fleet_console_url') . '/fleet/uptime', ['action' => 'uptime', 'site_id' => $s->int('fleet_site_id'), 'ts' => $now],
            fn (string $body): string => Keys::sign($s, $body), 10);
        $days = self::received($answer, $s->get('fleet_console_key'));
        $stored = self::stored($s);
        $keep = date('Y-m-01', (int) strtotime(date('Y-m-01', $now) . ' -' . (self::KEEP_MONTHS - 1) . ' months'));
        $s->set(self::SETTING, (string) json_encode([
            'checked' => $now,
            'received' => $days !== null ? $now : ($stored['received'] ?? null),
            'days' => self::merge($stored['days'] ?? [], $days ?? [], $keep),
        ], JSON_UNESCAPED_SLASHES));

        return $days !== null ? ', uptime ' . count($days) . ' days' : ', uptime not offered (' . ($answer['status'] > 0 ? 'HTTP ' . $answer['status'] : 'no answer') . ')';
    }

    /**
     * The days of the console's answer when it is valid: status 200, signed by the console's key, a list of days.
     * Null for anything else (an older console answers 404). Pure.
     *
     * @param array{status: int, body: string, json: ?array<string, mixed>, signature: string} $answer Http::post()
     * @return list<array{date: string, checks: int, failed: int}>|null
     */
    public static function received(array $answer, string $consoleKey): ?array
    {
        if ($answer['status'] !== 200 || !Keys::verify($answer['body'], $answer['signature'], $consoleKey) || !is_array($answer['json']['days'] ?? null)) {
            return null;
        }

        return self::clean($answer['json']['days']);
    }

    /**
     * Only well-formed days: a real date, whole counts in bounds, failed not more than the checks; one entry per date (the
     * last wins), sorted, at most MAX_DAYS. Pure.
     *
     * @return list<array{date: string, checks: int, failed: int}>
     */
    public static function clean(mixed $days): array
    {
        if (!is_array($days)) {
            return [];
        }
        $out = [];
        foreach (array_slice($days, -self::MAX_DAYS * 2) as $day) {
            $date = is_array($day) ? ($day['date'] ?? null) : null;
            if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                || !is_int($day['checks'] ?? null) || !is_int($day['failed'] ?? null)) {
                continue;
            }
            $checks = max(0, min(self::MAX_CHECKS, $day['checks']));
            $out[$date] = ['date' => $date, 'checks' => $checks, 'failed' => max(0, min($checks, $day['failed']))];
        }
        ksort($out);

        return array_slice(array_values($out), -self::MAX_DAYS);
    }

    /**
     * The stored days with the new ones over them (by the date), without the days before $keepFrom. Pure.
     *
     * @param list<array{date: string, checks: int, failed: int}> $old
     * @param list<array{date: string, checks: int, failed: int}> $new
     * @return list<array{date: string, checks: int, failed: int}>
     */
    public static function merge(array $old, array $new, string $keepFrom): array
    {
        $out = [];
        foreach ([...$old, ...$new] as $day) {
            if ($day['date'] >= $keepFrom) {
                $out[$day['date']] = $day;
            }
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * What the site keeps, null before the first question (or after the pairing ended).
     *
     * @return array{checked: int, received: ?int, days: list<array{date: string, checks: int, failed: int}>}|null
     */
    public static function stored(Settings $s): ?array
    {
        $data = json_decode($s->get(self::SETTING), true);
        if (!is_array($data) || !is_int($data['checked'] ?? null)) {
            return null;
        }

        return ['checked' => $data['checked'], 'received' => is_int($data['received'] ?? null) ? $data['received'] : null, 'days' => self::clean($data['days'] ?? [])];
    }

    /**
     * A month's uptime for the site's monthly report, null when the site has no measured day of it from a console.
     *
     * @return array{percent: ?float, checks: int, failed: int, days: list<array{date: string, state: string, percent: ?float}>}|null
     */
    public static function month(Settings $s, string $month): ?array
    {
        return self::forMonth(self::stored($s)['days'] ?? [], $month, date('Y-m-d'));
    }

    /**
     * A month's uptime from the stored days: every day of the month up to $today (a day without data is "none"), the share
     * of answered checks and the counts; null when no day of the month was measured. Pure.
     *
     * @param list<array{date: string, checks: int, failed: int}> $days
     * @return array{percent: ?float, checks: int, failed: int, days: list<array{date: string, state: string, percent: ?float}>}|null
     */
    public static function forMonth(array $days, string $month, string $today): ?array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) {
            return null;
        }
        $byDate = array_column($days, null, 'date');
        $checks = $failed = 0;
        $out = [];
        $last = (int) (new \DateTimeImmutable($month . '-01'))->format('t');
        for ($i = 1; $i <= $last; $i++) {
            $date = $month . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if ($date > $today) {
                break;
            }
            $day = $byDate[$date] ?? ['checks' => 0, 'failed' => 0];
            $checks += (int) $day['checks'];
            $failed += (int) $day['failed'];
            $out[] = UptimeStrip::day($date, (int) $day['checks'], (int) $day['failed']);
        }
        if ($checks === 0) {
            return null;
        }

        return ['percent' => UptimeStrip::percent($checks, $failed), 'checks' => $checks, 'failed' => $failed, 'days' => $out];
    }

    /**
     * A stored report's data with the uptime key: as it is when the report has one (also null), otherwise filled from the
     * days the site keeps – a report archived before 3.26.1 gains it additively wherever it is read.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function fill(Settings $s, array $data): array
    {
        if (array_key_exists('uptime', $data)) {
            return $data;
        }
        try {
            $data['uptime'] = is_string($data['month'] ?? null) ? self::month($s, $data['month']) : null;
        } catch (\Throwable) {
            $data['uptime'] = null;
        }

        return $data;
    }
}
