<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Absences (3.42, table ka_absences, migration 0132): when an account is away – from and to (days of the site's clock, both
 * included), the deputy who stands in and a reason from a fixed list (REASONS – never free text, so never health data).
 * Each person adds their own in My account; administrators add anybody's in Users.
 *
 * "The right contact automatically" – one rule, documented in docs/TEAM.md and used wherever Kaleta routes something to a
 * responsible person (Core\Inbox for every bell and its e-mail, Core\Departments::assignEnquiry for new enquiries):
 *  - standIn(): while a person is away TODAY, their stand-in is the deputy of that absence – when the deputy is an active
 *    account, not away themself and allowed to open the thing; otherwise whom the person reports to (ka_uzivatele.reports_to)
 *    under the same conditions; otherwise nobody. One step only, never a chain of deputies.
 *  - A notification for an away person still reaches them (their bell, their e-mail choice) and a copy reaches the stand-in,
 *    marked "standing in for <name>" – never only the away person. A new item that would be assigned to an away person goes to
 *    the stand-in. Who OWNS an existing record never changes.
 * Never in a heartbeat, the fleet console or a report (counts at most).
 */
final class Absences
{
    /** The reasons (English labels, t() in the admin; the MCP boundary passes the key). */
    public const array REASONS = ['holiday' => 'Holiday', 'trip' => 'Business trip', 'training' => 'Training', 'day_off' => 'Day off', 'away' => 'Away'];

    /** The longest absence, in days. */
    public const int MAX_DAYS = 366;

    /** Ended absences are kept this many days (the org chart and Users show only the current and future ones). */
    public const int KEEP_DAYS = 30;

    /** @var array<string, array<int, array{id: int, user_id: int, starts_on: string, ends_on: string, deputy_id: int|null, reason: string}>> day => user id => absence, for one request */
    private static array $today = [];

    /**
     * The absences of everyone on a day (today by default), by user id – one query per day and request. The longest one
     * when a person has two overlapping ones.
     *
     * @return array<int, array{id: int, user_id: int, starts_on: string, ends_on: string, deputy_id: int|null, reason: string}>
     */
    public static function on(Db $db, ?string $day = null): array
    {
        $day ??= Clock::today();
        if (isset(self::$today[$day])) {
            return self::$today[$day];
        }
        try {
            $rows = $db->all('SELECT id, user_id, starts_on, ends_on, deputy_id, reason FROM {absences} WHERE ends_on >= ? AND starts_on <= ? ORDER BY ends_on, id', [$day, $day]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = self::row($r); // ordered by the end: the one that lasts longest wins
        }

        return self::$today[$day] = $out;
    }

    /** The absence of a person today, or null. @return array{id: int, user_id: int, starts_on: string, ends_on: string, deputy_id: int|null, reason: string}|null */
    public static function absentToday(Db $db, int $userId): ?array
    {
        return self::on($db)[$userId] ?? null;
    }

    /**
     * Who stands in for a person today: null when the person is not away, or when neither the deputy nor whom they report to
     * can (an active account, not away today, not the person, and $mayOpen allows them – e.g. Assignment::allowed for the
     * section of the thing). 'for_name' is the away person's name (Assignment::label), 'until' the last day of the absence.
     *
     * @param (\Closure(int): bool)|null $mayOpen
     * @return array{id: int, for: int, for_name: string, until: string, via: string}|null  via = deputy | reports_to
     */
    public static function standIn(Db $db, int $userId, ?\Closure $mayOpen = null): ?array
    {
        $absence = self::absentToday($db, $userId);
        if ($absence === null) {
            return null;
        }
        try {
            $reportsTo = $db->value('SELECT reports_to FROM {uzivatele} WHERE idu = ?', [$userId]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            $reportsTo = null;
        }
        foreach (['deputy' => $absence['deputy_id'], 'reports_to' => $reportsTo !== null ? (int) $reportsTo : null] as $via => $candidate) {
            if ($candidate === null || $candidate <= 0 || $candidate === $userId || self::absentToday($db, $candidate) !== null
                || $db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$candidate]) === null
                || ($mayOpen !== null && !$mayOpen($candidate))) {
                continue;
            }

            return ['id' => $candidate, 'for' => $userId, 'for_name' => Assignment::label($db, $userId, false), 'until' => $absence['ends_on'], 'via' => $via];
        }

        return null;
    }

    /**
     * The current and future absences of a person (or of everyone, $userId 0), by the first day.
     *
     * @return list<array{id: int, user_id: int, starts_on: string, ends_on: string, deputy_id: int|null, reason: string}>
     */
    public static function upcoming(Db $db, int $userId = 0): array
    {
        try {
            $rows = $userId > 0
                ? $db->all('SELECT id, user_id, starts_on, ends_on, deputy_id, reason FROM {absences} WHERE user_id = ? AND ends_on >= ? ORDER BY starts_on, id LIMIT 50', [$userId, Clock::today()])
                : $db->all('SELECT id, user_id, starts_on, ends_on, deputy_id, reason FROM {absences} WHERE ends_on >= ? ORDER BY starts_on, id LIMIT 500', [Clock::today()]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return array_map(self::row(...), $rows);
    }

    /**
     * Adds an absence. Returns its id, or the English error to show: a day that is not a date, the end before the start or
     * already past, longer than MAX_DAYS, a deputy who is the person or not an active account, an unknown reason.
     */
    public static function add(Db $db, int $userId, string $from, string $to, ?int $deputyId, string $reason, int $byUserId): int|string
    {
        $error = self::refusal($from, $to, Clock::today());
        if ($error !== null) {
            return $error;
        }
        if (!isset(self::REASONS[$reason])) {
            return 'Choose the reason from the list.';
        }
        $deputyId = $deputyId !== null && $deputyId > 0 ? $deputyId : null;
        if ($deputyId === $userId) {
            return 'Nobody can stand in for themself – choose someone else or nobody.';
        }
        if ($deputyId !== null && $db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$deputyId]) === null) {
            return 'The deputy must be an active account.';
        }
        $id = $db->insert('absences', ['user_id' => $userId, 'starts_on' => $from, 'ends_on' => $to, 'deputy_id' => $deputyId, 'reason' => $reason,
            'created_by' => $byUserId > 0 ? $byUserId : null, 'created_at' => Clock::now()]);
        self::forget();

        return $id;
    }

    /**
     * 3.47 (N166): the bell about a new absence – the deputy learns they were named ('deputy_named'), and the person learns
     * that an administrator recorded an absence for them ('absence_added'). The excerpt is the person's name and the days
     * (Y-m-d – the same in every language), never the reason. Nobody is told of their own act (Inbox drops actor = user), so
     * a person naming themselves nothing is told; whoever adds their own absence gets no bell, their deputy does – the
     * tripwire for a session that is not theirs. Never throws (Inbox::notify).
     */
    public static function announce(App $app, int $absenceId, int $byUserId): void
    {
        $db = $app->db();
        try {
            $row = $db->one('SELECT id, user_id, starts_on, ends_on, deputy_id, reason FROM {absences} WHERE id = ?', [$absenceId]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return;
        }
        if ($row === null) {
            return;
        }
        $a = self::row($row);
        $excerpt = self::announcement(Assignment::label($db, $a['user_id'], false), $a['starts_on'], $a['ends_on']);
        if ($a['deputy_id'] !== null) {
            Inbox::notify($app, $a['deputy_id'], 'deputy_named', '', $byUserId, $excerpt);
        }
        if ($byUserId !== $a['user_id']) {
            Inbox::notify($app, $a['user_id'], 'absence_added', '', $byUserId, $excerpt);
        }
    }

    /** 3.47 (N166): the excerpt of the bell about an absence – the name and the days, never the reason. Pure. */
    public static function announcement(string $name, string $from, string $to): string
    {
        return ($name !== '' ? $name . ': ' : '') . $from . ' – ' . $to;
    }

    /**
     * Why the days cannot be an absence (English), or null when they can: both dates, the end not before the start nor
     * before today, at most MAX_DAYS days. Pure.
     */
    public static function refusal(string $from, string $to, string $today): ?string
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($a === false || $b === false || $a->format('Y-m-d') !== $from || $b->format('Y-m-d') !== $to) {
            return 'Enter the first and the last day of the absence.';
        }
        if ($to < $from) {
            return 'The last day cannot be before the first one.';
        }
        if ($to < $today) {
            return 'The absence has already ended.';
        }
        if ((int) $a->diff($b)->days + 1 > self::MAX_DAYS) {
            return 'An absence can be at most a year long.';
        }

        return null;
    }

    /** Removes an absence of a person ($userId 0 = of anyone – administrators). Returns whether it existed. */
    public static function remove(Db $db, int $id, int $userId = 0): bool
    {
        $done = $userId > 0 ? $db->run('DELETE FROM {absences} WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0
            : $db->run('DELETE FROM {absences} WHERE id = ?', [$id])->rowCount() > 0;
        self::forget();

        return $done;
    }

    /** The daily clean-up (Scheduler 'trash'): absences that ended more than KEEP_DAYS ago, and those of removed accounts. */
    public static function prune(Db $db): int
    {
        try {
            return $db->run('DELETE FROM {absences} WHERE ends_on < ?', [Clock::day('-' . self::KEEP_DAYS . ' days')])->rowCount()
                + $db->run('DELETE FROM {absences} WHERE NOT EXISTS (SELECT 1 FROM {uzivatele} u WHERE u.idu = user_id)')->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /** The reason as the admin shows it (translated). */
    public static function reasonLabel(string $reason): string
    {
        return t(self::REASONS[$reason] ?? self::REASONS['away']);
    }

    /** Forgets the absences read in this request (after a change, and in tests). */
    public static function forget(): void
    {
        self::$today = [];
    }

    /** @param array<string, mixed> $r @return array{id: int, user_id: int, starts_on: string, ends_on: string, deputy_id: int|null, reason: string} */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'starts_on' => (string) $r['starts_on'], 'ends_on' => (string) $r['ends_on'],
            'deputy_id' => $r['deputy_id'] !== null ? (int) $r['deputy_id'] : null, 'reason' => isset(self::REASONS[(string) $r['reason']]) ? (string) $r['reason'] : 'away'];
    }
}
