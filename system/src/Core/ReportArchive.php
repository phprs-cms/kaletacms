<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The archive of the monthly reports (3.16, table ka_monthly_reports): once the month has turned, the background job
 * report_archive builds the previous month (MonthlyReport::build) and keeps it – whether or not the report goes out by
 * e-mail – because what the report is built from is kept 30–180 days only (the change log and the events half a year, the
 * firewall log a month). A month is stored once; rebuilding it from the data still available is an explicit administrator
 * action (Settings → Reports). KEEP_MONTHS are kept, older rows are deleted. Nothing exists for the months before 3.16.
 *
 * The JSON is what Settings → Reports prints (Core\ReportPage), what the e-mail of that month is written from and
 * what Claude reads with get_monthly_report. Counts, titles and paths only, never personal data (MonthlyReport::scrub,
 * at build and again on every read). Only the job and the explicit rebuild write a row – the e-mail and its preview
 * build a missing month in memory (N316-7). Moments inside the JSON are ISO 8601 with the offset, so a change of the
 * time zone (Core\TimeZoneConversion) converts only the row's created_at and rebuilt_at.
 */
final class ReportArchive
{
    /** The version of the stored JSON (MonthlyReport::build): a renderer of a later release can tell older reports apart. */
    public const int SCHEMA = 1;

    /** How many months are kept: two years of reports to attach to invoices. */
    public const int KEEP_MONTHS = 24;

    /** A month as the archive and the admin address name it. */
    public const string MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * Every stored month, the newest first.
     *
     * @return list<array{month: string, created_at: string, rebuilt_at: ?string, schema_version: int}>
     */
    public static function months(Db $db): array
    {
        return array_map(static fn (array $r): array => ['month' => (string) $r['month'], 'created_at' => (string) $r['created_at'],
            'rebuilt_at' => $r['rebuilt_at'] === null ? null : (string) $r['rebuilt_at'], 'schema_version' => (int) $r['schema_version']],
            $db->all('SELECT month, created_at, rebuilt_at, schema_version FROM {monthly_reports} ORDER BY month DESC'));
    }

    /**
     * One stored month with its data, null when it is not stored.
     *
     * @return array{month: string, created_at: string, rebuilt_at: ?string, schema_version: int, data: array<string, mixed>}|null
     */
    public static function get(Db $db, string $month): ?array
    {
        if (preg_match(self::MONTH_PATTERN, $month) !== 1) {
            return null;
        }
        $row = $db->one('SELECT month, created_at, rebuilt_at, schema_version, data FROM {monthly_reports} WHERE month = ?', [$month]);
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);

        return ['month' => (string) $row['month'], 'created_at' => (string) $row['created_at'], 'rebuilt_at' => $row['rebuilt_at'] === null ? null : (string) $row['rebuilt_at'],
            'schema_version' => (int) $row['schema_version'], 'data' => is_array($data) ? MonthlyReport::scrub($data) : ['month' => $month]];
    }

    /** The oldest stored month ('' = none yet) – the admin says honestly from when reports exist. */
    public static function firstMonth(Db $db): string
    {
        return (string) ($db->value('SELECT MIN(month) FROM {monthly_reports}') ?? '');
    }

    /**
     * Builds a month and stores it. A month already stored stays as it is unless $rebuild (the administrator's explicit
     * "Rebuild from the data still available"). Returns the stored data.
     *
     * @return array<string, mixed>
     */
    public static function store(App $app, \DateTimeImmutable $monthStart, bool $rebuild = false): array
    {
        $db = $app->db();
        $month = $monthStart->format('Y-m');
        if (!$rebuild && ($stored = self::get($db, $month)) !== null) {
            return $stored['data'];
        }
        // the data are collected in the site's default language, like the e-mail – the health texts are stored as they read
        $data = Language::runWith(Language::defaults($app->settings()), fn (): array => MonthlyReport::build($app, $monthStart), 'admin-');
        $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $now = Clock::now();
        if ($rebuild) {
            $db->run('INSERT INTO {monthly_reports} (month, created_at, rebuilt_at, schema_version, data) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE rebuilt_at = VALUES(rebuilt_at), schema_version = VALUES(schema_version), data = VALUES(data)', [$month, $now, $now, self::SCHEMA, $json]);
        } else {
            // two runs at once (cron and a visit): the unique month keeps one row, the first one wins
            $db->run('INSERT IGNORE INTO {monthly_reports} (month, created_at, schema_version, data) VALUES (?, ?, ?, ?)', [$month, $now, self::SCHEMA, $json]);
        }

        return $data;
    }

    /** Deletes the months older than KEEP_MONTHS before the given moment. Returns how many rows went. */
    public static function prune(Db $db, \DateTimeImmutable $now): int
    {
        return $db->run('DELETE FROM {monthly_reports} WHERE month < ?', [self::oldestKept($now)])->rowCount();
    }

    /** The oldest month still kept at the given moment: the 24 months before the current one. */
    public static function oldestKept(\DateTimeImmutable $now): string
    {
        return $now->modify('first day of this month')->modify('-' . self::KEEP_MONTHS . ' months')->format('Y-m');
    }

    /** One run of the job (Core\Scheduler, hourly, also on visits): stores the previous month once and keeps 24 months. */
    public static function runIfDue(App $app): string
    {
        $db = $app->db();
        $now = new \DateTimeImmutable();
        $month = MonthlyReport::previousMonth($now);
        $pruned = self::prune($db, $now);
        $result = self::get($db, $month->format('Y-m')) !== null ? 'stored already' : (self::store($app, $month) !== [] ? 'stored ' . $month->format('Y-m') : 'nothing');

        return $result . ($pruned > 0 ? ', removed ' . $pruned : '');
    }
}
