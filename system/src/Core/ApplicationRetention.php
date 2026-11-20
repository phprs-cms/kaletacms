<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * How long job applications are kept (3.34, split out of Core\Applications in 3.47): the day an application goes
 * (deletionDate, expired), the warning to its responsible person (dueForWarning), the confirmation an administrator gives
 * for applications moved from Enquiries (confirmedUpTo, confirmRetention, adoptRetention, awaitingConfirmation) and the
 * daily clean-up itself (purge). Core\Applications keeps a forward for every public member.
 */
final class ApplicationRetention
{
    /** Days before an undecided application is deleted when its responsible person is told (once). */
    public const int WARN_DAYS = 7;
    /* ---------- retention ---------- */

    /**
     * The marker (a setting) an administrator sets by saving Applications → Settings (3.34 review H1) – 3.34 second review
     * N8: the highest application id confirmed then. A moved application (source_enquiry_id) above it waits for the next
     * confirmation; a later move by anyone gates only the rows it moved.
     */
    public const string RETENTION_CONFIRMED = 'applications_retention_confirmed';

    /** The highest application id whose retention an administrator confirmed (N8); 0 = none. */
    public static function confirmedUpTo(Settings $s): int
    {
        $value = $s->get(self::RETENTION_CONFIRMED);

        return ctype_digit($value) ? (int) $value : 0;
    }

    /** An administrator saved Applications → Settings: every application there now is confirmed (N8). */
    public static function confirmRetention(App $app): void
    {
        $app->settings()->set(self::RETENTION_CONFIRMED, (string) (int) $app->db()->value('SELECT MAX(id) FROM {applications}'));
    }

    /**
     * The moved applications that wait for the confirmation (above confirmedUpTo()): how many, and when the first of them
     * moved (moved_at) – System status notes it after UNCONFIRMED_DAYS (N8). 0 and null before migration 0119.
     *
     * @return array{count: int, since: ?string}
     */
    public static function unconfirmed(App $app): array
    {
        try {
            $row = $app->db()->one('SELECT COUNT(*) AS n, MIN(moved_at) AS since FROM {applications} WHERE source_enquiry_id IS NOT NULL AND id > ?', [self::confirmedUpTo($app->settings())]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return ['count' => 0, 'since' => null];
        }

        return ['count' => (int) ($row['n'] ?? 0), 'since' => is_string($row['since'] ?? null) ? $row['since'] : null];
    }

    /** Days moved applications may wait for the confirmation before System status notes it (N8). */
    public const int UNCONFIRMED_DAYS = 30;

    /**
     * The retention a site starts with when its job enquiries become applications (3.34 review H1 – an update never
     * shortens it): job_applications_months when it was set, otherwise the site's enquiries_months (0 = keep stays keep).
     * Pure.
     */
    public static function startingRetention(int $jobMonths, int $enquiryMonths): int
    {
        return max(0, min(120, $jobMonths > 0 ? $jobMonths : $enquiryMonths));
    }

    /**
     * Applications are moving in from Enquiries (migration 0120 – it does the same itself –, the move by form, one enquiry):
     * a site that never stored applications_months starts at startingRetention() instead of the default; the rows just
     * moved wait for an administrator's confirmation – N8: only they (new ids lie above the confirmed one; a confirmation
     * that reaches them – ids used again after a restore – is lowered below the first of them).
     *
     * @param list<int> $movedIds
     */
    public static function adoptRetention(Db $db, Settings $s, array $movedIds = []): void
    {
        if ($db->value("SELECT 1 FROM {nastaveni} WHERE promenna = 'applications_months'") === null) {
            $s->set('applications_months', (string) self::startingRetention($s->int('job_applications_months'), $s->int('enquiries_months')));
        }
        $first = $movedIds === [] ? null : min($movedIds);
        if ($first !== null && self::confirmedUpTo($s) >= $first) {
            $s->set(self::RETENTION_CONFIRMED, (string) max(0, $first - 1));
        }
    }

    /**
     * What waits for the confirmation (the notice on the settings tab and the list): how many applications moved from
     * Enquiries the clean-up would delete today, and the day before which they had their last activity (or decision);
     * null when the retention is off or nothing moved and unconfirmed (N8) is past it.
     *
     * @return array{count: int, before: string, months: int}|null
     */
    public static function awaitingConfirmation(App $app): ?array
    {
        $s = $app->settings();
        $months = $s->int('applications_months');
        if ($months <= 0) {
            return null;
        }
        $today = Clock::today();
        $before = (new \DateTimeImmutable($today))->modify('-' . $months . ' months')->format('Y-m-d');
        try {
            $rows = $app->db()->all('SELECT id, decided_at, last_activity_at, pool_until FROM {applications} WHERE source_enquiry_id IS NOT NULL AND id > ? AND COALESCE(decided_at, last_activity_at) < ?',
                [self::confirmedUpTo($s), $before . ' 23:59:59']);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0119
        }
        $count = count(self::expired($rows, $months, $today));

        return $count > 0 ? ['count' => $count, 'before' => $before, 'months' => $months] : null;
    }

    /**
     * The day an application will be deleted (Y-m-d), null when the retention is off (0 months): a decided one
     * applications_months after its decision, an undecided one after that long without activity – and never before
     * pool_until. Pure.
     *
     * @param array<string, mixed> $row decided_at, last_activity_at, pool_until
     */
    public static function deletionDate(array $row, int $months): ?string
    {
        if ($months <= 0) {
            return null;
        }
        $from = is_string($row['decided_at'] ?? null) && $row['decided_at'] !== '' ? (string) $row['decided_at'] : (string) ($row['last_activity_at'] ?? '');
        if ($from === '') {
            return null;
        }
        $day = (new \DateTimeImmutable(substr($from, 0, 10)))->modify('+' . $months . ' months')->format('Y-m-d');
        $pool = is_string($row['pool_until'] ?? null) ? substr((string) $row['pool_until'], 0, 10) : '';

        return $pool !== '' && $pool > $day ? (new \DateTimeImmutable($pool))->modify('+1 day')->format('Y-m-d') : $day;
    }

    /**
     * The ids of the applications past their retention on $today (deletionDate() on or before it). Pure.
     *
     * @param list<array<string, mixed>> $rows id, decided_at, last_activity_at, pool_until
     * @return list<int>
     */
    public static function expired(array $rows, int $months, string $today): array
    {
        $out = [];
        foreach ($rows as $r) {
            $day = self::deletionDate($r, $months);
            if ($day !== null && $day <= $today) {
                $out[] = (int) $r['id'];
            }
        }

        return $out;
    }

    /**
     * The undecided applications with a responsible person that will be deleted within WARN_DAYS and were not announced
     * yet. Pure.
     *
     * 3.40 (B8, B15 "the reminder refined"): $warned maps an id to the deletion day it was announced for (the column
     * deletion_warned_on) – an application whose deletion moved (a note, a reply, a renewed consent) and comes close again
     * is announced again for its new day; true = announced for an unknown day (a deletion_due bell entry from 3.34), never
     * again. $hired: a hired application is announced too, so its responsible person takes what belongs in the personnel
     * file first (B8 "Hired people"); a rejected one never is.
     *
     * @param list<array<string, mixed>> $rows id, decided_at, last_activity_at, pool_until, assignee_id (status with $hired)
     * @param array<int, true|string> $warned ids already announced => true or the day announced
     * @return list<array{id: int, assignee: int, day: string}>
     */
    public static function dueForWarning(array $rows, int $months, string $today, array $warned, bool $hired = false): array
    {
        $limit = (new \DateTimeImmutable($today))->modify('+' . self::WARN_DAYS . ' days')->format('Y-m-d');
        $out = [];
        foreach ($rows as $r) {
            $day = self::deletionDate($r, $months);
            $announced = $warned[(int) $r['id']] ?? null;
            $open = ($r['decided_at'] ?? null) === null || ($hired && ($r['status'] ?? '') === 'hired');
            if ($day !== null && $day > $today && $day <= $limit && $open && (int) ($r['assignee_id'] ?? 0) > 0 && ($announced === null || (is_string($announced) && $announced !== $day))) {
                $out[] = ['id' => (int) $r['id'], 'assignee' => (int) $r['assignee_id'], 'day' => $day];
            }
        }

        return $out;
    }

    /**
     * The daily clean-up (Notifications::purgePersonalData): seals moved files, warns the responsible people of the
     * undecided applications that go within a week, deletes those past their retention with their files, comments, tasks
     * and notifications, and records the count (change log, event applications.purged – as before 3.34). Runs while the
     * feature is off too: stored applications keep their retention. An application moved from Enquiries (source_enquiry_id)
     * is neither warned about nor deleted until an administrator saved Applications → Settings after it moved
     * (RETENTION_CONFIRMED – the highest id confirmed, N8).
     * Returns how many were deleted.
     */
    public static function purge(App $app): int
    {
        $db = $app->db();
        try {
            $db->value('SELECT 1 FROM {applications} LIMIT 1');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0119
        }
        ApplicationFiles::sealPending($app);
        ApplicationFiles::resealHeld($app); // M5: lists held under the database key while the Vault key was missing
        Applications::forgetTwins($app); // F4: enquiry twins a restored backup brought back go, before a deleted application lets one surface
        $months = $app->settings()->int('applications_months');
        if ($months <= 0) {
            return 0;
        }
        $today = Clock::today();
        // 3.34 review H1: what moved in from Enquiries waits until an administrator confirmed the retention (Settings tab)
        // N8: a moved application only up to the highest id an administrator confirmed
        $warnedColumn = self::hasWarnedColumn($db);
        $rows = $db->all('SELECT id, status, decided_at, last_activity_at, pool_until, assignee_id' . $warnedColumn . ' FROM {applications} WHERE COALESCE(decided_at, last_activity_at) < ?'
            . ' AND (source_enquiry_id IS NULL OR id <= ' . self::confirmedUpTo($app->settings()) . ')',
            [(new \DateTimeImmutable($today))->modify('-' . $months . ' months')->modify('+' . (self::WARN_DAYS + 1) . ' days')->format('Y-m-d')]);
        // 3.40: the day announced (deletion_warned_on); a bell entry of 3.34 without it counts as announced for good
        $warned = array_fill_keys(array_map(fn (array $r): int => (int) substr((string) $r['target'], 12), $db->all("SELECT target FROM {notifications} WHERE kind = 'deletion_due' AND target LIKE 'application:%'")), true);
        foreach ($rows as $r) {
            if (is_string($r['deletion_warned_on'] ?? null)) {
                $warned[(int) $r['id']] = substr($r['deletion_warned_on'], 0, 10);
            }
        }
        foreach (self::dueForWarning($rows, $months, $today, $warned, true) as $due) {
            if (Assignment::allowed($db, 'application', $due['assignee'])) { // review low (a): only someone who may open Applications
                Inbox::notify($app, $due['assignee'], 'deletion_due', Applications::target($due['id']), null, format_date($due['day']));
                if ($warnedColumn !== '') {
                    $db->update('applications', ['deletion_warned_on' => $due['day']], ['id' => $due['id']]); // announced once per deletion day
                }
            }
        }
        $expired = self::expired($rows, $months, $today);
        if ($expired !== []) {
            // M5: an application whose CV list cannot be opened (the encryption key) and whose files cannot be located waits –
            // deleting it would orphan its CVs; 3.34 third review F5: one with attachment_paths goes (delete() finds its files, N7)
            $locked = array_map('intval', array_column(array_filter($db->all('SELECT id, attachments, attachment_paths FROM {applications} WHERE id IN (' . implode(',', $expired) . ')'),
                fn (array $r): bool => ApplicationFiles::rowFiles($app->settings(), $r) === null), 'id'));
            $expired = array_values(array_diff($expired, $locked));
        }
        if ($expired === []) {
            return 0;
        }
        $count = Applications::delete($app, $expired, false);
        ChangeLog::write($app, Applications::SECTION, 'purge', sprintf('%d past the retention of %d months', $count, $months));
        Events::record($db, 'applications.purged', 'info', t('%d job applications older than %d months were deleted, including the CVs.', $count, $months), ['count' => $count, 'months' => $months]);

        return $count;
    }

    /** ', deletion_warned_on' once migration 0129 added the column, '' before (the clean-up of an update in progress). */
    private static function hasWarnedColumn(Db $db): string
    {
        try {
            $db->value('SELECT deletion_warned_on FROM {applications} LIMIT 1');

            return ', deletion_warned_on';
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return '';
        }
    }
}
