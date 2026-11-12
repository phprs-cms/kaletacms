<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Tasks and follow-ups (3.27, table ka_tasks, migration 0113): "call back on Monday", "review the price page by 1 Dec".
 * One person each, optionally about an enquiry, a page or a collection item (target 'enquiry:<idp>' | 'page:<ids>' |
 * 'item:<idp>'; 3.28 also a customer 'contact:<id>' or a company 'company:<id>', Core\Contacts; 3.30 on a fleet console a
 * site 'fleet_site:<id>' or a console customer 'fleet_customer:<id>', Fleet\ConsoleTasks), with a due moment and an
 * optional reminder. Deliberately small: no subtasks, dependencies, time tracking
 * or recurrence.
 *
 *  - Who: anyone creates a task for themselves; a task for someone else about a record needs the record's section for both
 *    (mayAssign – Core\Handover::mayOpen, the rule of the responsible person). A task without a record may go to any active
 *    colleague. The assignee, the creator and administrators change, complete and delete it (mayChange); an administrator
 *    sees everyone's (team()).
 *  - When: due_at and remind_at are moments on the site's clock. A date without a time is DEFAULT_TIME on that day; the
 *    groups of My work go by the day of due_at (group()): overdue (an earlier day), today, upcoming (UPCOMING_DAYS), later
 *    (further or no date).
 *  - The background job "tasks" (run(), every 5 minutes) notifies the assignee once (notified_at) when remind_at – or due_at
 *    without a reminder – has passed: Core\Inbox kind task_due, the bell and the e-mail as each person chose.
 *  - A task can be handed to Claude (toRequest): it becomes a request (Core\Requests) and is done, keeping request_id.
 *  - It follows its record (forgetTargets, anonymiseTargets, sweep): deleted with an enquiry or a page or item deleted for
 *    good, the title blanked when the enquiry is anonymised (a task title may name the customer). Never journaled
 *    automatically (AgentJournal::PERSONAL_TABLES); a change made by Claude to a task not about an enquiry is journaled here,
 *    so undo_agent_session takes it back.
 *
 * Messages of \DomainException are translated (t()); MCP calls them inside Language::runWith('en').
 */
final class Tasks
{
    /** The longest title. */
    public const int TITLE = 200;

    /** The time of day a due date or a reminder given without one gets. */
    public const string DEFAULT_TIME = '09:00';

    /** "Upcoming" = due within this many days after today. */
    public const int UPCOMING_DAYS = 7;

    /** What forUser() lists. open = all open ones; the groups of My work; done = the latest done ones. */
    public const array FILTERS = ['open', 'overdue', 'today', 'upcoming', 'later', 'done'];

    /** Snooze choices of My work: key => days (the due day moves that many days from today, the reminder goes). */
    public const array SNOOZE = ['tomorrow' => 1, '3days' => 3];

    /** The reminder job notifies at most this many tasks per run (the rest at the next one). */
    public const int BATCH = 200;

    /** The columns every reader returns. */
    private const string COLUMNS = 'id, title, target, assignee_id, created_by, due_at, remind_at, notified_at, done_at, done_by, request_id, created_at, updated_at';

    /**
     * Creates a task and returns its id. Fields: title (required), target (null = about nothing), assignee_id (default the
     * creator), due_at and remind_at (anything parseMoment() takes; null or '' = none). Notifies the assignee (kind
     * "assigned") when it is someone else.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function create(App $app, array $fields, int $byUserId): int
    {
        $db = $app->db();
        $title = self::cleanTitle($fields['title'] ?? '');
        if ($title === '') {
            throw new \DomainException(t('Give the task a title.'));
        }
        $target = self::cleanTarget($fields['target'] ?? null);
        $assignee = isset($fields['assignee_id']) && (int) $fields['assignee_id'] > 0 ? (int) $fields['assignee_id'] : $byUserId;
        self::checkAssign($app, $byUserId, $assignee, $target);
        $due = self::parseMoment((string) ($fields['due_at'] ?? ''));
        $remind = self::parseMoment((string) ($fields['remind_at'] ?? ''));
        $now = Clock::now();
        $id = $db->insert('tasks', ['title' => $title, 'target' => $target, 'assignee_id' => $assignee, 'created_by' => $byUserId > 0 ? $byUserId : null,
            'due_at' => $due, 'remind_at' => $remind, 'created_at' => $now, 'updated_at' => $now]);
        self::journalInserted($db, $id, $target);
        if ($assignee !== $byUserId) {
            Inbox::notify($app, $assignee, 'assigned', 'task:' . $id, $byUserId > 0 ? $byUserId : null);
        }

        return $id;
    }

    /**
     * Changes the given fields of a task (only the keys present: title, target, assignee_id, due_at, remind_at). A new due
     * moment or reminder notifies again when it passes. A new assignee is checked like create() and notified.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function update(App $app, int $id, array $fields, int $byUserId): void
    {
        $db = $app->db();
        $task = self::mine($app, $id, $byUserId);
        $data = [];
        if (array_key_exists('title', $fields)) {
            $data['title'] = self::cleanTitle($fields['title']);
            if ($data['title'] === '') {
                throw new \DomainException(t('Give the task a title.'));
            }
        }
        $target = array_key_exists('target', $fields) ? self::cleanTarget($fields['target']) : $task['target'];
        $assignee = array_key_exists('assignee_id', $fields) && (int) $fields['assignee_id'] > 0 ? (int) $fields['assignee_id'] : $task['assignee_id'];
        if ($target !== $task['target'] || $assignee !== $task['assignee_id']) {
            self::checkAssign($app, $byUserId, (int) $assignee, $target);
            $data['target'] = $target;
            $data['assignee_id'] = $assignee;
        }
        foreach (['due_at', 'remind_at'] as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = self::parseMoment((string) ($fields[$key] ?? ''));
                // a new moment notifies again – to the minute, as every form shows it (N327-4: the same moment saved again
                // from the admin's edit form never notifies a second time)
                if (($data[$key] === null) !== ($task[$key] === null) || substr((string) $data[$key], 0, 16) !== substr((string) $task[$key], 0, 16)) {
                    $data['notified_at'] = null;
                }
            }
        }
        if ($data === []) {
            return;
        }
        self::write($db, $task, $data + ['updated_at' => Clock::now()]);
        if (isset($data['assignee_id']) && $data['assignee_id'] !== $task['assignee_id'] && $data['assignee_id'] !== $byUserId) {
            Inbox::notify($app, (int) $data['assignee_id'], 'assigned', 'task:' . $id, $byUserId > 0 ? $byUserId : null);
        }
    }

    /**
     * Marks a task done ($done) or open again. Repeating it changes nothing.
     *
     * @throws \DomainException
     */
    public static function complete(App $app, int $id, int $byUserId, bool $done = true): void
    {
        $task = self::mine($app, $id, $byUserId);
        if (($task['done_at'] !== null) === $done) {
            return;
        }
        self::write($app->db(), $task, ($done ? ['done_at' => Clock::now(), 'done_by' => $byUserId > 0 ? $byUserId : null] : ['done_at' => null, 'done_by' => null])
            + ['updated_at' => Clock::now()]);
    }

    /**
     * Snoozes a task: the due day moves $days from today (keeping its time of day, DEFAULT_TIME when it had none), the
     * reminder goes and it notifies again on the new day.
     *
     * @throws \DomainException
     */
    public static function snooze(App $app, int $id, int $byUserId, int $days): void
    {
        $task = self::mine($app, $id, $byUserId);
        $time = $task['due_at'] !== null ? substr($task['due_at'], 11, 5) : self::DEFAULT_TIME;
        self::write($app->db(), $task, ['due_at' => Clock::day('+' . max(1, $days) . ' days') . ' ' . $time . ':00', 'remind_at' => null, 'notified_at' => null,
            'done_at' => null, 'done_by' => null, 'updated_at' => Clock::now()]);
    }

    /**
     * Deletes a task for good (and the notifications that point to it).
     *
     * @throws \DomainException
     */
    public static function delete(App $app, int $id, int $byUserId): void
    {
        $db = $app->db();
        $task = self::mine($app, $id, $byUserId);
        self::write($db, $task, null);
        Inbox::forgetTargets($db, ['task:' . $id]);
    }

    /**
     * One task, or null.
     *
     * @return array{id: int, title: string, target: ?string, assignee_id: ?int, created_by: ?int, due_at: ?string, remind_at: ?string, notified_at: ?string, done_at: ?string, done_by: ?int, request_id: ?int, created_at: string, updated_at: string, group: string}|null
     */
    public static function find(Db $db, int $id): ?array
    {
        try {
            $row = $db->one('SELECT ' . self::COLUMNS . ' FROM {tasks} WHERE id = ?', [$id]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0113
        }

        return $row === null ? null : self::row($row);
    }

    /**
     * The tasks of one person: open (all open, by due date, no date last), overdue, today, upcoming, later, or done (the
     * latest first). Each row as find() returns it.
     *
     * @return list<array<string, mixed>>
     */
    public static function forUser(Db $db, int $userId, string $filter, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $today = Clock::today() . ' 00:00:00';
        $tomorrow = Clock::day('+1 day') . ' 00:00:00';
        $after = Clock::day('+' . (self::UPCOMING_DAYS + 1) . ' days') . ' 00:00:00';
        [$where, $params] = match ($filter) {
            'overdue' => ['done_at IS NULL AND due_at < ?', [$today]],
            'today' => ['done_at IS NULL AND due_at >= ? AND due_at < ?', [$today, $tomorrow]],
            'upcoming' => ['done_at IS NULL AND due_at >= ? AND due_at < ?', [$tomorrow, $after]],
            'later' => ['done_at IS NULL AND (due_at IS NULL OR due_at >= ?)', [$after]],
            'done' => ['done_at IS NOT NULL', []],
            'open' => ['done_at IS NULL', []],
            default => throw new \InvalidArgumentException('Unknown filter of tasks.'),
        };
        $order = $filter === 'done' ? 'done_at DESC, id DESC' : 'due_at IS NULL, due_at, id';
        try {
            $rows = $db->all('SELECT ' . self::COLUMNS . ' FROM {tasks} WHERE assignee_id = ? AND ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit, [$userId, ...$params]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return array_map(self::row(...), $rows);
    }

    /**
     * The tasks about one record, open first (by due date), then the done ones.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTarget(Db $db, string $target): array
    {
        $target = self::cleanTarget($target);
        if ($target === null) {
            return [];
        }
        try {
            $rows = $db->all('SELECT ' . self::COLUMNS . ' FROM {tasks} WHERE target = ? ORDER BY done_at IS NOT NULL, due_at IS NULL, due_at, done_at DESC, id LIMIT 200', [$target]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return array_map(self::row(...), $rows);
    }

    /**
     * Open tasks per person (the Team tab of My work, administrators): assignee id (0 = nobody) => name, the counts of
     * overdue, today, upcoming, later and all open ones, and whether nobody active has them (unassigned – nobody, a removed
     * or a blocked account: they are in unassigned(), where an administrator gives them to someone). People with the most
     * overdue first.
     *
     * @return array<int, array{name: string, overdue: int, today: int, upcoming: int, later: int, open: int, unassigned: bool}>
     */
    public static function team(Db $db): array
    {
        $today = Clock::today() . ' 00:00:00';
        $tomorrow = Clock::day('+1 day') . ' 00:00:00';
        $after = Clock::day('+' . (self::UPCOMING_DAYS + 1) . ' days') . ' 00:00:00';
        try {
            $rows = $db->all('SELECT COALESCE(assignee_id, 0) AS who, SUM(due_at < ?) AS overdue, SUM(due_at >= ? AND due_at < ?) AS today, SUM(due_at >= ? AND due_at < ?) AS upcoming,'
                . ' SUM(due_at IS NULL OR due_at >= ?) AS later, COUNT(*) AS open FROM {tasks} WHERE done_at IS NULL GROUP BY COALESCE(assignee_id, 0)',
                [$today, $today, $tomorrow, $tomorrow, $after, $after]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $who = (int) $r['who'];
            $out[$who] = ['name' => $who > 0 ? Assignment::label($db, $who) : t('nobody'), 'overdue' => (int) $r['overdue'], 'today' => (int) $r['today'],
                'upcoming' => (int) $r['upcoming'], 'later' => (int) $r['later'], 'open' => (int) $r['open'], 'unassigned' => !self::hasActiveAssignee($db, $who)];
        }
        uasort($out, fn (array $a, array $b): int => [$b['overdue'], $b['today'], $b['open'], $a['name']] <=> [$a['overdue'], $a['today'], $a['open'], $b['name']]);

        return $out;
    }

    /**
     * The tasks nobody active has (3.27, so a leaver's work is never lost): handed to nobody (assignee_id NULL) or left on a
     * removed or a blocked account. Open ones by due date (no date last), or the latest done ones ($status 'done'). Each row
     * as find() returns it. The Team tab of My work lists the open ones for an administrator to give each to someone, MCP
     * list_tasks with filter assignee:none.
     *
     * @return list<array<string, mixed>>
     */
    public static function unassigned(Db $db, string $status = 'open', int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $done = $status === 'done';
        $columns = implode(', ', array_map(fn (string $c): string => 't.' . trim($c), explode(',', self::COLUMNS)));
        try {
            $rows = $db->all('SELECT ' . $columns . ' FROM {tasks} t LEFT JOIN {uzivatele} u ON u.idu = t.assignee_id AND u.blokovat = 0 WHERE u.idu IS NULL AND t.done_at IS '
                . ($done ? 'NOT NULL ORDER BY t.done_at DESC, t.id DESC' : 'NULL ORDER BY t.due_at IS NULL, t.due_at, t.id') . ' LIMIT ' . $limit);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return array_map(self::row(...), $rows);
    }

    /** Has an active account (not removed, not blocked) the tasks of this assignee id? Nobody (null, 0) has not. */
    public static function hasActiveAssignee(Db $db, ?int $assigneeId): bool
    {
        return $assigneeId !== null && $assigneeId > 0 && $db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$assigneeId]) !== null;
    }

    /**
     * How many open tasks of a person are overdue and due today – the dashboard tile and the My work badge (one query over
     * the key (assignee_id, done_at, due_at)).
     *
     * @return array{overdue: int, today: int}
     */
    public static function counts(Db $db, int $userId): array
    {
        try {
            $row = $db->one('SELECT SUM(due_at < ?) AS overdue, SUM(due_at >= ?) AS today FROM {tasks} WHERE assignee_id = ? AND done_at IS NULL AND due_at < ?',
                [Clock::today() . ' 00:00:00', Clock::today() . ' 00:00:00', $userId, Clock::day('+1 day') . ' 00:00:00']);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return ['overdue' => 0, 'today' => 0];
        }

        return ['overdue' => (int) ($row['overdue'] ?? 0), 'today' => (int) ($row['today'] ?? 0)];
    }

    /**
     * May $byUserId give a task about $target (null = nothing) to $assigneeId? The assignee must be an active account.
     * About a record: it must exist and the giver must be able to open its section; for someone else the assignee too.
     * For oneself, or without a record, nothing more.
     */
    public static function mayAssign(App $app, int $byUserId, int $assigneeId, ?string $target): bool
    {
        return self::assignRefusal($app, $byUserId, $assigneeId, $target) === null;
    }

    /** May this user change, complete or delete the task: its assignee, its creator or an administrator. */
    public static function mayChange(Db $db, array $task, int $userId): bool
    {
        return $userId > 0 && ((int) ($task['assignee_id'] ?? 0) === $userId || (int) ($task['created_by'] ?? 0) === $userId
            || $db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND admin = ? AND blokovat = 0', [$userId, Auth::ADMIN]) !== null);
    }

    /**
     * Hands a task to Claude: a request (Core\Requests::create – the administrators are told) with the task's title and a
     * link to its record; the task is then done and keeps the request's id. Needs the Requests section. Returns the id of
     * the request.
     *
     * @throws \DomainException
     */
    public static function toRequest(App $app, int $id, int $byUserId): int
    {
        $db = $app->db();
        $task = self::mine($app, $id, $byUserId);
        if ($task['request_id'] !== null) {
            throw new \DomainException(t('This task was already handed to Claude.'));
        }
        if ($db->value("SELECT 1 FROM {uzivatele} u WHERE idu = ? AND blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = 'requests'))",
            [$byUserId, Auth::ADMIN]) === null) {
            throw new \DomainException(t('Only someone with Ask Claude can hand a task to Claude.'));
        }
        $place = self::label($db, $task['target']);
        $about = '';
        $text = $task['title'];
        if ($place !== null) {
            $url = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . $app->url($place['url']);
            $about = $place['kind'] === 'enquiry' ? $url : $place['kind'] . ':' . $place['id'];
            $text .= "\n\n" . t('About: %s', Inbox::where($place)) . "\n" . $url;
        }
        $requestId = Requests::create($app, $byUserId, $task['title'], $text, $about, []);
        self::write($db, $task, ['request_id' => $requestId, 'done_at' => Clock::now(), 'done_by' => $byUserId, 'updated_at' => Clock::now()]);

        return $requestId;
    }

    /**
     * What a target is: kind, id, title (the form name of an enquiry – never its fields –, the title of a page, the name of
     * an item and its collection) and the admin URL (relative, admin.php?…). Null for none, an unknown or a missing record.
     *
     * @return array{kind: string, id: int, title: string, name?: string, collection?: string, url: string}|null
     */
    public static function label(Db $db, ?string $target): ?array
    {
        $target = self::cleanTarget($target);

        return $target !== null ? (self::labels($db, [$target])[$target] ?? null) : null;
    }

    /**
     * label() of many targets at once (one query per kind – Inbox::places): target => label; missing ones are left out.
     *
     * @param list<?string> $targets
     * @return array<string, array{kind: string, id: int, title: string, name?: string, collection?: string, url: string}>
     */
    public static function labels(Db $db, array $targets): array
    {
        $out = [];
        foreach (Inbox::places($db, array_values(array_filter(array_map(self::cleanTarget(...), $targets)))) as $target => $place) {
            $out[$target] = ['kind' => $place['kind'], 'id' => $place['id'], 'title' => $place['title']]
                + (isset($place['name']) ? ['name' => $place['name']] : []) // 3.32: RecordTitle::place on screens only, never in MCP
                + (isset($place['collection']) ? ['collection' => $place['collection']] : []) + ['url' => $place['path']];
        }

        return $out;
    }

    /**
     * Where the day of a due moment puts an open task: overdue (an earlier day), today, upcoming (within UPCOMING_DAYS) or
     * later (further, or no due date). Pure apart from the clock.
     */
    public static function group(?string $dueAt, ?string $today = null): string
    {
        $today ??= Clock::today();
        if ($dueAt === null || $dueAt === '') {
            return 'later';
        }
        $day = substr($dueAt, 0, 10);
        if ($day < $today) {
            return 'overdue';
        }
        if ($day === $today) {
            return 'today';
        }
        $last = (new \DateTimeImmutable($today . ' +' . self::UPCOMING_DAYS . ' days'))->format('Y-m-d');

        return $day <= $last ? 'upcoming' : 'later';
    }

    /**
     * A due moment or a reminder as a DATETIME on the site's clock; null for nothing ('' or 'none'). It takes
     * "YYYY-MM-DD" (at DEFAULT_TIME), "YYYY-MM-DD HH:MM", ISO 8601 with T and an optional offset (converted to the site's
     * zone), "today", "tomorrow" (at DEFAULT_TIME), "+3 days", "+1 week" (that day at DEFAULT_TIME) and "+2 hours",
     * "+30 minutes" (from now). Anything else is a \DomainException. To the minute – seconds are dropped (N327-4). $now only
     * for tests.
     */
    public static function parseMoment(string $value, ?\DateTimeImmutable $now = null): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'none' || $value === 'null') {
            return null;
        }
        $zone = Clock::zone();
        $now = ($now ?? new \DateTimeImmutable('now', $zone))->setTimezone($zone);
        $atDefault = fn (\DateTimeImmutable $day): string => $day->format('Y-m-d') . ' ' . self::DEFAULT_TIME . ':00';
        if ($value === 'today' || $value === 'tomorrow') {
            return $atDefault($value === 'today' ? $now : $now->modify('+1 day'));
        }
        if (preg_match('/^\+?\s*(\d{1,3})\s*(day|days|week|weeks|hour|hours|minute|minutes|min)$/D', $value, $m) === 1) {
            $n = (int) $m[1];

            return match ($m[2]) {
                'day', 'days' => $atDefault($now->modify('+' . $n . ' days')),
                'week', 'weeks' => $atDefault($now->modify('+' . ($n * 7) . ' days')),
                'hour', 'hours' => $now->modify('+' . $n . ' hours')->format('Y-m-d H:i:00'),
                default => $now->modify('+' . $n . ' minutes')->format('Y-m-d H:i:00'),
            };
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
            if ($day !== false && $day->format('Y-m-d') === $value) {
                return $atDefault($day);
            }
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})[t ](\d{2}:\d{2})(:\d{2})?(\.\d+)?(z|[+-]\d{2}:?\d{2})?$/D', $value, $m) === 1) {
            $m += [3 => '', 4 => '', 5 => '']; // optional groups at the end are left out of $m
            $offset = $m[5];
            $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1] . ' ' . $m[2] . ($m[3] !== '' ? $m[3] : ':00'), $offset !== '' ? new \DateTimeZone($offset === 'z' ? 'UTC' : $offset) : $zone);
            if ($moment !== false && $moment->format('Y-m-d') === $m[1]) {
                return substr(Clock::toDb($moment), 0, 16) . ':00';
            }
        }
        throw new \DomainException(t('The date “%s” is not understood – use YYYY-MM-DD, YYYY-MM-DD HH:MM, “tomorrow” or “+3 days”.', $value));
    }

    /**
     * A target as stored: 'enquiry:<id>', 'page:<id>', 'item:<id>', 'contact:<id>' or 'company:<id>' (3.28), 'fleet_site:<id>'
     * or 'fleet_customer:<id>' (3.30, a console), 'deal:<id>' (3.31), 'application:<id>' (3.34), 'quote:<id>' (3.40); null for anything else (an empty one too). Pure.
     */
    public static function cleanTarget(mixed $target): ?string
    {
        $target = is_string($target) ? trim($target) : '';

        return preg_match('/^(enquiry|page|item|contact|company|fleet_site|fleet_customer|deal|application|quote):[1-9]\d{0,9}$/D', $target) === 1 ? $target : null;
    }

    /** What a person is responsible for in tasks: their open ones (Core\Handover). */
    public static function openCount(Db $db, int $userId): int
    {
        try {
            return (int) $db->value('SELECT COUNT(*) FROM {tasks} WHERE assignee_id = ? AND done_at IS NULL', [$userId]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /**
     * Open tasks per person (the Users list): idu => count.
     *
     * @return array<int, int>
     */
    public static function openByAll(Db $db): array
    {
        try {
            return array_map('intval', $db->pairs('SELECT assignee_id, COUNT(*) FROM {tasks} WHERE assignee_id IS NOT NULL AND done_at IS NULL GROUP BY assignee_id'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
    }

    /**
     * The sections someone must be able to open to take a person's open tasks: those of their records (Module::IDENT).
     *
     * @return list<string>
     */
    public static function sectionsOf(Db $db, int $userId): array
    {
        try {
            $targets = array_column($db->all('SELECT DISTINCT target FROM {tasks} WHERE assignee_id = ? AND done_at IS NULL AND target IS NOT NULL', [$userId]), 'target');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
        $sections = [];
        foreach ($targets as $target) {
            $kind = explode(':', (string) $target)[0];
            if (isset(Assignment::KINDS[$kind])) {
                $sections[Assignment::KINDS[$kind]] = true;
            }
        }

        return array_keys($sections);
    }

    /** Hands a leaver's open tasks to another person (null = nobody). Returns how many moved. */
    public static function handOver(Db $db, int $from, ?int $to): int
    {
        try {
            return $db->run('UPDATE {tasks} SET assignee_id = ?, notified_at = NULL, updated_at = ? WHERE assignee_id = ? AND done_at IS NULL', [$to, Clock::now(), $from])->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /**
     * Deletes the tasks about the given records (an enquiry deleted, a page or an item deleted for good) and the
     * notifications that point to them. Returns how many tasks went.
     *
     * @param list<string> $targets
     */
    public static function forgetTargets(Db $db, array $targets): int
    {
        $targets = array_values(array_unique(array_filter(array_map(self::cleanTarget(...), $targets))));
        $deleted = 0;
        try {
            foreach (array_chunk($targets, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $ids = array_map('intval', array_column($db->all('SELECT id FROM {tasks} WHERE target IN (' . $in . ')', $chunk), 'id'));
                if ($ids !== []) {
                    Inbox::forgetTargets($db, array_map(fn (int $id): string => 'task:' . $id, $ids));
                    $deleted += $db->run('DELETE FROM {tasks} WHERE target IN (' . $in . ')', $chunk)->rowCount();
                }
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0113
        }

        return $deleted;
    }

    /**
     * Anonymised enquiries (Core\Privacy): their tasks keep who, when and whether done, the title goes (it may name the
     * customer), and so does the title quoted in their notifications. Returns how many tasks changed.
     *
     * @param list<string> $targets
     */
    public static function anonymiseTargets(Db $db, array $targets): int
    {
        $targets = array_values(array_unique(array_filter(array_map(self::cleanTarget(...), $targets))));
        $changed = 0;
        try {
            foreach (array_chunk($targets, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $ids = array_map('intval', array_column($db->all('SELECT id FROM {tasks} WHERE target IN (' . $in . ')', $chunk), 'id'));
                if ($ids !== []) {
                    Inbox::forgetTargets($db, array_map(fn (int $id): string => 'task:' . $id, $ids), true);
                    $changed += $db->run("UPDATE {tasks} SET title = '' WHERE target IN (" . $in . ')', $chunk)->rowCount();
                }
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0113
        }

        return $changed;
    }

    /**
     * Tasks whose record no longer exists (deleted by a path that did not call forgetTargets: a whole collection, retention,
     * an import into an empty site). A page or an item in the trash still exists. Returns how many went.
     */
    public static function sweep(Db $db): int
    {
        try {
            $targets = array_column($db->all('SELECT DISTINCT target FROM {tasks} WHERE target IS NOT NULL'), 'target');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
        $byKind = [];
        foreach ($targets as $target) {
            [$kind, $id] = explode(':', (string) $target) + ['', '0'];
            $byKind[$kind][] = (int) $id;
        }
        $gone = [];
        foreach (['enquiry' => ['poptavky', 'idp'], 'page' => ['stranky', 'ids'], 'item' => ['kolekce_polozky', 'idp'], 'contact' => ['contacts', 'id'], 'company' => ['companies', 'id'],
            'fleet_site' => ['fleet_sites', 'id'], 'fleet_customer' => ['fleet_customers', 'id'], 'deal' => ['deals', 'id'], 'application' => ['applications', 'id'],
            'quote' => ['quotes', 'id']] as $kind => [$table, $key]) { // 3.40: only whether the quote still exists – never a price
            foreach (array_chunk(array_values(array_unique(array_filter($byKind[$kind] ?? []))), 500) as $chunk) {
                try {
                    $existing = array_flip(array_map('intval', array_column($db->all('SELECT ' . $key . ' FROM {' . $table . '} WHERE ' . $key . ' IN (' . implode(',', $chunk) . ')'), $key)));
                } catch (\PDOException $e) {
                    Db::rethrowUnlessMissing($e);
                    continue; // the contacts tables before migration 0114, the deals before 0117, the applications before 0119
                }
                foreach ($chunk as $id) {
                    if (!isset($existing[$id])) {
                        $gone[] = $kind . ':' . $id;
                    }
                }
            }
        }

        return $gone === [] ? 0 : self::forgetTargets($db, $gone);
    }

    /**
     * The background job "tasks" (Core\Scheduler, every 5 minutes): every open task whose reminder – or due moment when it
     * has none – has passed and was not notified yet notifies its assignee once (Inbox kind task_due, the target of the task
     * or 'task:<id>', the creator as the actor, the title as the excerpt; the bell, and the e-mail as the person chose). The
     * marker is written first, so a failure never notifies twice. Then the tasks of records that are gone. $now for tests.
     */
    public static function run(App $app, ?string $now = null): string
    {
        $db = $app->db();
        $now ??= Clock::now();
        try {
            $due = $db->all('SELECT id, title, target, assignee_id, created_by FROM {tasks} WHERE done_at IS NULL AND notified_at IS NULL AND assignee_id IS NOT NULL'
                . ' AND COALESCE(remind_at, due_at) <= ? ORDER BY COALESCE(remind_at, due_at), id LIMIT ' . self::BATCH, [$now]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 'not installed';
        }
        $sent = 0;
        foreach ($due as $task) {
            if ($db->run('UPDATE {tasks} SET notified_at = ? WHERE id = ? AND notified_at IS NULL', [Clock::now(), (int) $task['id']])->rowCount() === 0) {
                continue; // another run took it
            }
            Inbox::notify($app, (int) $task['assignee_id'], 'task_due', $task['target'] !== null ? (string) $task['target'] : 'task:' . (int) $task['id'],
                $task['created_by'] !== null ? (int) $task['created_by'] : null, (string) $task['title']);
            $sent++;
        }

        return 'notified ' . $sent . ', swept ' . self::sweep($db);
    }

    /**
     * The open tasks about records of the given kinds (3.30: the console's sites and customers – Fleet\ConsoleTasks), by due
     * date (no date last). Each row as find() returns it.
     *
     * @param list<string> $kinds target kinds, e.g. ['fleet_site', 'fleet_customer']
     * @return list<array<string, mixed>>
     */
    public static function openAbout(Db $db, array $kinds, int $limit = 500): array
    {
        $kinds = array_values(array_filter($kinds, fn (string $k): bool => preg_match('/^[a-z_]+$/D', $k) === 1));
        if ($kinds === []) {
            return [];
        }
        $like = implode(' OR ', array_fill(0, count($kinds), 'target LIKE ?'));
        try {
            $rows = $db->all('SELECT ' . self::COLUMNS . ' FROM {tasks} WHERE done_at IS NULL AND (' . $like . ') ORDER BY due_at IS NULL, due_at, id LIMIT ' . max(1, min(2000, $limit)),
                array_map(fn (string $k): string => $k . ':%', $kinds)); // a target is kind:<digits> – no kind is another's prefix
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return array_map(self::row(...), $rows);
    }

    /**
     * A task the user may change, or a \DomainException.
     *
     * @return array<string, mixed>
     */
    private static function mine(App $app, int $id, int $byUserId): array
    {
        $task = self::find($app->db(), $id) ?? throw new \DomainException(t('The task does not exist.'));
        if (!self::mayChange($app->db(), $task, $byUserId)) {
            throw new \DomainException(t('Only the person the task is for, the one who created it, or an administrator can change it.'));
        }

        return $task;
    }

    /** create() and update(): the refusal of mayAssign as a \DomainException. */
    private static function checkAssign(App $app, int $byUserId, int $assigneeId, ?string $target): void
    {
        $refusal = self::assignRefusal($app, $byUserId, $assigneeId, $target);
        if ($refusal !== null) {
            throw new \DomainException($refusal);
        }
    }

    /** Why a task about $target may not go to $assigneeId (translated), or null when it may. */
    private static function assignRefusal(App $app, int $byUserId, int $assigneeId, ?string $target): ?string
    {
        $db = $app->db();
        if ($assigneeId <= 0 || $db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$assigneeId]) === null) {
            return t('This person does not exist or the account is blocked.');
        }
        if ($target === null) {
            return null;
        }
        // 3.30: a console task only while the console is on (the fleet extension)
        if (self::label($db, $target) === null || (str_starts_with($target, 'fleet_') && !Extensions::isEnabled($app->settings(), 'fleet'))) {
            return t('What the task is about does not exist (or is in the trash).');
        }
        $section = Assignment::KINDS[explode(':', $target)[0]];
        if (!Handover::mayOpen($db, $app->settings(), $byUserId, $section)) {
            return t('You cannot open what the task is about.');
        }
        if ($assigneeId !== $byUserId && !Handover::mayOpen($db, $app->settings(), $assigneeId, $section)) {
            return t('This person cannot open what the task is about – give it to someone who can.');
        }

        return null;
    }

    /** A title as stored: one line, at most TITLE characters. */
    private static function cleanTitle(mixed $title): string
    {
        $title = is_scalar($title) ? (string) $title : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) preg_replace('/\s+/u', ' ', $title))), 0, self::TITLE);
    }

    /**
     * Writes a change of a task ($data null = delete) and journals it for a Claude session when the task is not about an
     * enquiry or a contact (ka_tasks is not journaled by itself – AgentJournal::PERSONAL_TABLES).
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed>|null $data
     */
    private static function write(Db $db, array $task, ?array $data): void
    {
        $journal = $db->journal !== null && !self::personal($task['target']);
        $before = $journal ? $db->one('SELECT * FROM {tasks} WHERE id = ?', [$task['id']]) : null;
        if ($data === null) {
            $db->delete('tasks', ['id' => $task['id']]);
        } else {
            $db->update('tasks', $data, ['id' => $task['id']]);
        }
        if ($journal && $before !== null) {
            $db->journal?->record('tasks', [$before]);
        }
    }

    private static function journalInserted(Db $db, int $id, ?string $target): void
    {
        if ($db->journal !== null && !self::personal($target)) {
            $db->journal->inserted('tasks', [], $id);
        }
    }

    /** Is a task about a person (an enquiry, or a contact since 3.28, a deal since 3.31, a job application since 3.34 – it points to one)? Its changes never enter the undo journal. Pure. */
    public static function personal(?string $target): bool
    {
        return str_starts_with((string) $target, 'enquiry:') || str_starts_with((string) $target, 'contact:') || str_starts_with((string) $target, 'deal:')
            || str_starts_with((string) $target, 'application:') || str_starts_with((string) $target, 'quote:'); // 3.40: a quote names its customer
    }

    /**
     * A row with its numbers as numbers and the group of an open task (done for a done one).
     *
     * @param array<string, mixed> $r
     * @return array{id: int, title: string, target: ?string, assignee_id: ?int, created_by: ?int, due_at: ?string, remind_at: ?string, notified_at: ?string, done_at: ?string, done_by: ?int, request_id: ?int, created_at: string, updated_at: string, group: string}
     */
    private static function row(array $r): array
    {
        $int = fn (mixed $v): ?int => $v !== null ? (int) $v : null;
        $str = fn (mixed $v): ?string => $v !== null ? (string) $v : null;

        return ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'target' => $str($r['target']), 'assignee_id' => $int($r['assignee_id']), 'created_by' => $int($r['created_by']),
            'due_at' => $str($r['due_at']), 'remind_at' => $str($r['remind_at']), 'notified_at' => $str($r['notified_at']), 'done_at' => $str($r['done_at']),
            'done_by' => $int($r['done_by']), 'request_id' => $int($r['request_id']), 'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'],
            'group' => $r['done_at'] !== null ? 'done' : self::group($str($r['due_at']))];
    }
}
