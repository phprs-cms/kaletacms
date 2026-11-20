<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The counts of job applications per month and position (3.40, table ka_application_counts, design B4/B10): how many came
 * and how many reached an interview, an offer, hired or rejected – never a person, a name or an address, so the yearly
 * statistics survive the retention that deletes the applications. Core\Applications bumps them when an application
 * arrives (received) and when its status changes (setStatus – each status once per application and month, the rule of the
 * monthly report's history count); migration 0129 filled them from the applications kept then.
 *
 * Read by the statistics tab of Applications (lastMonths, byPosition) and the monthly report (month). The fleet heartbeat
 * never carries them.
 */
final class ApplicationCounts
{
    /** The statuses that are counted when an application reaches them (with received). */
    public const array STATUSES = ['interview', 'offer', 'hired', 'rejected'];

    /** The columns of a month: received and the counted statuses. */
    public const array COLUMNS = ['received', 'interview', 'offer', 'hired', 'rejected'];

    /** Months the statistics tab shows. */
    public const int MONTHS = 12;

    /**
     * One more in a column of a month and position (the month of $at; job 0 = no position). Never fails what counts – a
     * database error before migration 0129 is swallowed.
     */
    public static function bump(Db $db, string $column, ?int $jobItemId, string $jobTitle, ?string $at = null): void
    {
        if (!in_array($column, self::COLUMNS, true)) {
            return;
        }
        $month = substr($at ?? Clock::now(), 0, 7);
        try {
            // portable upsert: the row first (a duplicate is ignored), then the column – no reference to the target table in ON DUPLICATE KEY
            $db->run('INSERT IGNORE INTO {application_counts} (month, job_item_id, job_title) VALUES (?, ?, ?)', [$month, max(0, (int) $jobItemId), mb_substr($jobTitle, 0, 200)]);
            $db->run('UPDATE {application_counts} SET ' . $column . ' = ' . $column . ' + 1' . ($jobTitle !== '' ? ', job_title = ?' : '') . ' WHERE month = ? AND job_item_id = ?',
                [...($jobTitle !== '' ? [mb_substr($jobTitle, 0, 200)] : []), $month, max(0, (int) $jobItemId)]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0129
        }
    }

    /**
     * Should a status change count (each counted status once per application and month)? $history is the application's
     * history JSON before the change. Pure.
     */
    public static function counts(string $status, mixed $history, string $now): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $from = substr($now, 0, 7) . '-01 00:00:00';
        $to = (new \DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d H:i:s');

        return !in_array($status, self::movesIn(is_string($history) ? $history : '', $from, $to), true);
    }

    /**
     * The months from $first to $last (Y-m), oldest first. Pure.
     *
     * @return list<string>
     */
    public static function monthsBetween(string $first, string $last): array
    {
        $out = [];
        $at = new \DateTimeImmutable($first . '-01');
        $end = new \DateTimeImmutable($last . '-01');
        while ($at <= $end && count($out) < 240) {
            $out[] = $at->format('Y-m');
            $at = $at->modify('+1 month');
        }

        return $out;
    }

    /**
     * The last MONTHS months up to the month of $today, oldest first: month => the columns (0 when nothing came), plus the
     * totals under 'total'. Null before migration 0129.
     *
     * @return array{months: array<string, array<string, int>>, total: array<string, int>}|null
     */
    public static function lastMonths(Db $db, string $today, int $months = self::MONTHS): ?array
    {
        $last = substr($today, 0, 7);
        $first = (new \DateTimeImmutable($last . '-01'))->modify('-' . (max(1, $months) - 1) . ' months')->format('Y-m');
        $zero = array_fill_keys(self::COLUMNS, 0);
        $out = array_fill_keys(self::monthsBetween($first, $last), $zero);
        try {
            $rows = $db->all('SELECT month, SUM(received) AS received, SUM(interview) AS interview, SUM(offer) AS offer, SUM(hired) AS hired, SUM(rejected) AS rejected'
                . ' FROM {application_counts} WHERE month >= ? AND month <= ? GROUP BY month', [$first, $last]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null;
        }
        foreach ($rows as $r) {
            if (isset($out[$r['month']])) {
                foreach (self::COLUMNS as $c) {
                    $out[$r['month']][$c] = (int) $r[$c];
                }
            }
        }
        $total = $zero;
        foreach ($out as $row) {
            foreach (self::COLUMNS as $c) {
                $total[$c] += $row[$c];
            }
        }

        return ['months' => $out, 'total' => $total];
    }

    /**
     * Per position over the last MONTHS months, the most received first: job_item_id (0 = no position), job_title and the
     * columns. Empty before migration 0129.
     *
     * @return list<array<string, int|string>>
     */
    public static function byPosition(Db $db, string $today, int $months = self::MONTHS, int $limit = 50): array
    {
        $last = substr($today, 0, 7);
        $first = (new \DateTimeImmutable($last . '-01'))->modify('-' . (max(1, $months) - 1) . ' months')->format('Y-m');
        try {
            $rows = $db->all('SELECT job_item_id, MAX(job_title) AS job_title, SUM(received) AS received, SUM(interview) AS interview, SUM(offer) AS offer, SUM(hired) AS hired, SUM(rejected) AS rejected'
                . ' FROM {application_counts} WHERE month >= ? AND month <= ? GROUP BY job_item_id ORDER BY received DESC, hired DESC, job_item_id LIMIT ' . max(1, $limit), [$first, $last]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return array_map(fn (array $r): array => ['job_item_id' => (int) $r['job_item_id'], 'job_title' => (string) $r['job_title']]
            + array_combine(self::COLUMNS, array_map(fn (string $c): int => (int) $r[$c], self::COLUMNS)), $rows);
    }

    /**
     * One month for the monthly report ($from = the first day of a month, $to = the first day of the next): the columns and
     * the top five positions by received; null when the table has nothing for that month (the report then counts from the
     * applications themselves, as before 3.40) or before migration 0129.
     *
     * @return array{received: int, interview: int, offer: int, hired: int, rejected: int, positions: list<array{title: string, n: int}>}|null
     */
    public static function month(Db $db, string $from, string $to): ?array
    {
        $month = substr($from, 0, 7);
        if (substr($from, 7) !== '-01 00:00:00' || (new \DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d H:i:s') !== $to) {
            return null; // not one whole month
        }
        try {
            $rows = $db->all('SELECT job_title, received, interview, offer, hired, rejected FROM {application_counts} WHERE month = ?', [$month]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null;
        }
        if ($rows === []) {
            return null;
        }
        $out = array_fill_keys(self::COLUMNS, 0);
        $positions = [];
        foreach ($rows as $r) {
            foreach (self::COLUMNS as $c) {
                $out[$c] += (int) $r[$c];
            }
            if ((string) $r['job_title'] !== '' && (int) $r['received'] > 0) {
                $positions[(string) $r['job_title']] = ($positions[(string) $r['job_title']] ?? 0) + (int) $r['received'];
            }
        }
        uksort($positions, fn (string $a, string $b): int => [$positions[$b], $a] <=> [$positions[$a], $b]);

        return $out + ['positions' => array_map(fn (string $title, int $n): array => ['title' => $title, 'n' => $n], array_keys(array_slice($positions, 0, 5, true)), array_slice($positions, 0, 5, true))];
    }

    /* ---------- moved from Core\Applications (3.47) ---------- */

    /**
     * The month's recruitment for the monthly report (B10): received (and the top five positions by count), invited to an
     * interview, hired, rejected (status changes in the month, from the history), and how many undecided still wait for
     * the first reply. Counts and position titles only; null when nothing happened or the table is missing.
     *
     * @return array{received: int, positions: list<array{title: string, n: int}>, interview: int, hired: int, rejected: int, waiting: int}|null
     */
    public static function monthCounts(Db $db, string $from, string $to): ?array
    {
        $counted = self::month($db, $from, $to); // 3.40: the counts survive the retention
        if ($counted !== null) {
            try {
                $waiting = (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE status = 'new' AND replies IS NULL AND received_at < ?", [$to]);
            } catch (\PDOException $e) {
                Db::rethrowUnlessMissing($e);
                $waiting = 0;
            }
            if ($counted['received'] + $counted['interview'] + $counted['hired'] + $counted['rejected'] + $waiting === 0) {
                return null;
            }

            return ['received' => $counted['received'], 'positions' => $counted['positions'], 'interview' => $counted['interview'], 'hired' => $counted['hired'],
                'rejected' => $counted['rejected'], 'waiting' => $waiting];
        }
        try {
            $received = (int) $db->value('SELECT COUNT(*) FROM {applications} WHERE received_at >= ? AND received_at < ?', [$from, $to]);
            $positions = array_map(fn (array $r): array => ['title' => (string) $r['title'], 'n' => (int) $r['n']],
                $db->all("SELECT job_title AS title, COUNT(*) AS n FROM {applications} WHERE received_at >= ? AND received_at < ? AND job_title <> '' GROUP BY job_title ORDER BY n DESC, job_title LIMIT 5", [$from, $to]));
            $moves = array_fill_keys(['interview', 'hired', 'rejected'], 0);
            foreach ($db->all('SELECT history FROM {applications} WHERE status_at >= ? AND history IS NOT NULL', [$from]) as $r) {
                foreach (self::movesIn((string) $r['history'], $from, $to) as $status) {
                    if (isset($moves[$status])) {
                        $moves[$status]++;
                    }
                }
            }
            $waiting = (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE status = 'new' AND replies IS NULL AND received_at < ?", [$to]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null;
        }
        if ($received + array_sum($moves) + $waiting === 0) {
            return null;
        }

        return ['received' => $received, 'positions' => $positions] + $moves + ['waiting' => $waiting];
    }

    /**
     * The day job applications started to be counted apart from enquiries (3.34 B), when it falls between $from and $to –
     * the first move of applications out of the enquiries (event applications.moved) or the first application that came
     * straight in, whichever is earlier; null otherwise (the monthly report then compares enquiries as before).
     */
    public static function countingChanged(Db $db, string $from, string $to): ?string
    {
        try {
            $moved = $db->value("SELECT MIN(created_at) FROM {events} WHERE type = 'applications.moved'");
            $first = $db->value('SELECT MIN(received_at) FROM {applications} WHERE source_enquiry_id IS NULL');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null;
        }
        $start = min(array_filter([is_string($moved) ? $moved : null, is_string($first) ? $first : null]) ?: ['']);

        return $start !== '' && $start >= $from && $start < $to ? substr($start, 0, 10) : null;
    }

    /**
     * The statuses an application reached between $from and $to (each once), from its history JSON. Pure.
     *
     * @return list<string>
     */
    public static function movesIn(string $history, string $from, string $to): array
    {
        $list = json_decode($history, true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $change) {
            if (is_array($change) && is_string($change['at'] ?? null) && is_string($change['to'] ?? null) && $change['at'] >= $from && $change['at'] < $to) {
                $out[$change['to']] = true;
            }
        }

        return array_keys($out);
    }
}
