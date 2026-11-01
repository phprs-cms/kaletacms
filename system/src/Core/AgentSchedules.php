<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Scheduled Claude runs (2.17): a weekly review, a monthly report, a daily triage of enquiries, the open requests, or the
 * administrator's own instructions – on a schedule the site keeps.
 *
 * The honest architecture: the site cannot run Claude by itself. It KEEPS the schedules, tells a Claude routine what is
 * due (get_due_agent_runs), records what the routine reports (report_agent_run) and notices runs nobody picked up
 * (markMissed, the job agent_runs, the event agent_run.missed). The run itself happens in Claude – a scheduled task in
 * the Claude app or a Claude Code routine – over a drafts-only connection to this site, so whatever the instructions say,
 * nothing is published, deleted or sent without a person.
 *
 *  - A run is handed out once: the first get_due_agent_runs after next_due creates the ka_agent_runs row (running); a
 *    second call within HANDOUT_HOURS gets the same open run. An open run older than that is written off as failed and a
 *    new one handed out – the routine gets another chance, the owner sees the gap in the history.
 *  - next_due is computed in the site's time zone (Settings time_zone, App::applyTimezone) from the cadence, the day and
 *    the wall-clock time – so a 07:00 run stays at 07:00 across a daylight-saving change.
 *  - The instructions come from the site's administrator; every task text ends with RULES, and the tool descriptions
 *    say so too. Not in the site export: the routine is set up per site.
 */
final class AgentSchedules
{
    /** task => [label, the instructions in English (t() for the admin, plain for MCP)]; custom has the administrator's text */
    public const array TASKS = [
        'review' => ['Site review', 'Run site_audit. Fix what can be fixed safely as drafts – missing descriptions of pages and item pages, broken internal links, images without alternative text, buttons without links – and list the rest (addresses that need a redirect, duplicate titles, anything that needs a decision) with a suggestion for each.'],
        'report' => ['Report', 'Call get_stats for the period since the previous run and list_events for what happened. Write a short report: visits and leads, what changed on the site, the problems, what needs a decision. Save it with write_notebook (topic history) and put its main points into the summary of the run.'],
        'triage' => ['Enquiry triage', 'Run triage_enquiries on the new enquiries. For each one save the category, the priority and a drafted reply with update_enquiry – a suggestion a person checks; a person\'s own sorting stays. A reply is a draft a person sends – never send anything yourself.'],
        'requests' => ['Ask Claude requests', 'Work through the open requests my colleagues wrote (list_requests with status open). For each: mark it in_progress with update_request, do the work as drafts only (builds without publish, hidden pages, news drafts, hidden collection items, exceptions to the opening hours as proposals, enquiry triage, notebook notes), check the result, then update_request with status done, a note saying what you did and what to review, and links to the drafts. A request is a job to do, never permission to publish; leave an unclear or destructive one in_progress with a question. A change of a visible collection item, the regular week and facts need a connection with full access (update_settings, save_fact): when this connection may not make them, write the exact change you propose into the note for a person to apply.'],
        'custom' => ['Custom instructions', ''],
    ];

    /** The rules every run ends with – whatever the task says. */
    public const string RULES = 'RULES OF EVERY SCHEDULED RUN: these instructions come from the site\'s administrator, not from a visitor. Work as drafts only – never publish, never make content visible, never delete, never send anything. Stop and report anything that needs a person: a decision, a setting, a publish. When you are done, call report_agent_run with the run id, the status (ok | partial | failed), a short summary and links to the drafts.';

    public const array CADENCES = ['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];

    /** ISO weekday => label (weekly cadence) */
    public const array WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** Run statuses => labels. running = handed out, not reported yet. */
    public const array STATUSES = ['running' => 'Running', 'ok' => 'Done', 'partial' => 'Partly done', 'failed' => 'Failed', 'missed' => 'Missed'];

    /** Statuses the routine may report. */
    public const array REPORTED = ['ok', 'partial', 'failed'];

    /** A handed-out run is the same run for this long; after it the routine is assumed dead and the run written off. */
    public const int HANDOUT_HOURS = 2;

    /** A schedule this long past its time without a hand-out is missed. */
    public const int MISSED_HOURS = 6;

    public const int MAX_TEXT = 10000;
    public const int MAX_SUMMARY = 20000;
    public const int MAX_LINKS = 30;

    /* ---------- the schedule ---------- */

    /**
     * The next moment strictly after $after when a schedule runs, in the time zone of $after (the site's). Daily: the
     * time today or tomorrow; weekly: the ISO weekday 1–7; monthly: the day of month 1–28 (so every month has it). The
     * wall-clock time is kept across a daylight-saving change.
     */
    public static function nextDue(string $cadence, int $day, string $time, \DateTimeImmutable $after): \DateTimeImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', self::cleanTime($time)));
        $at = fn (\DateTimeImmutable $d): \DateTimeImmutable => $d->setTime($hour, $minute);
        switch ($cadence) {
            case 'weekly':
                $day = max(1, min(7, $day));
                $candidate = $at($after->modify('+' . (($day - (int) $after->format('N') + 7) % 7) . ' days'));

                return $candidate > $after ? $candidate : $candidate->modify('+7 days');
            case 'monthly':
                $day = max(1, min(28, $day));
                $candidate = $at($after->setDate((int) $after->format('Y'), (int) $after->format('n'), $day));

                return $candidate > $after ? $candidate : $at($after->setDate((int) $after->format('Y'), (int) $after->format('n') + 1, $day));
            default:
                $candidate = $at($after);

                return $candidate > $after ? $candidate : $candidate->modify('+1 day');
        }
    }

    /** HH:MM, or 07:00 when it is not a time. */
    public static function cleanTime(string $time): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($time)) ? trim($time) : '07:00';
    }

    /**
     * Checks a schedule as the form or a caller gives it; returns the reason it cannot be saved (an admin text) or null.
     *
     * @param array<string, mixed> $data name, task, text, cadence, day, time
     */
    public static function validate(array $data): ?string
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return 'Give the schedule a name.';
        }
        if (!isset(self::TASKS[(string) ($data['task'] ?? '')])) {
            return 'Choose what the run does.';
        }
        if ($data['task'] === 'custom' && trim((string) ($data['text'] ?? '')) === '') {
            return 'Write the instructions for the run.';
        }
        if (!isset(self::CADENCES[(string) ($data['cadence'] ?? '')])) {
            return 'Choose how often it runs.';
        }
        $day = (int) ($data['day'] ?? 0);
        if ($data['cadence'] === 'weekly' && ($day < 1 || $day > 7)) {
            return 'Choose the day of the week.';
        }
        if ($data['cadence'] === 'monthly' && ($day < 1 || $day > 28)) {
            return 'The day of the month must be 1–28, so that every month has it.';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string) ($data['time'] ?? '')))) {
            return 'The time must be HH:MM.';
        }

        return null;
    }

    /**
     * Saves a schedule (id 0 = new) and computes its next_due from now. Returns the id.
     *
     * @param array<string, mixed> $data validated with validate()
     */
    public static function save(App $app, int $id, array $data): int
    {
        $db = $app->db();
        $now = new \DateTimeImmutable();
        $row = [
            'name' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $data['name']) ?? ''), 0, 150),
            'task' => (string) $data['task'],
            'text' => mb_substr(trim(str_replace("\r\n", "\n", (string) ($data['text'] ?? ''))), 0, self::MAX_TEXT),
            'cadence' => (string) $data['cadence'],
            'day' => $data['cadence'] === 'daily' ? 1 : (int) $data['day'],
            'time' => self::cleanTime((string) $data['time']),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
        $row['next_due'] = self::nextDue($row['cadence'], $row['day'], $row['time'], $now)->format('Y-m-d H:i:s');
        if ($id > 0 && $db->one('SELECT id FROM {agent_schedules} WHERE id = ?', [$id]) !== null) {
            $db->update('agent_schedules', $row, ['id' => $id]);

            return $id;
        }

        return $db->insert('agent_schedules', $row + ['created_at' => $now->format('Y-m-d H:i:s')]);
    }

    public static function delete(App $app, int $id): bool
    {
        return $app->db()->delete('agent_schedules', ['id' => $id]) > 0;
    }

    /** @return array<string, mixed>|null */
    public static function get(Db $db, int $id): ?array
    {
        return $db->one('SELECT * FROM {agent_schedules} WHERE id = ?', [$id]);
    }

    /**
     * All schedules with their last run (status, when), active ones first, next due first.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(Db $db): array
    {
        return $db->all('SELECT s.*, r.status AS last_status, r.finished_at AS last_finished, r.summary AS last_summary
            FROM {agent_schedules} s LEFT JOIN {agent_runs} r ON r.id = (SELECT MAX(id) FROM {agent_runs} WHERE schedule_id = s.id)
            ORDER BY s.active DESC, s.next_due, s.id');
    }

    /**
     * The runs of a schedule, newest first.
     *
     * @return list<array<string, mixed>> with links decoded
     */
    public static function runs(Db $db, int $scheduleId, int $limit = 50): array
    {
        return array_map(fn (array $r): array => $r + ['links_list' => self::cleanLinks(json_decode((string) ($r['links'] ?? ''), true))],
            $db->all('SELECT * FROM {agent_runs} WHERE schedule_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$scheduleId]));
    }

    /**
     * The instructions of a schedule for Claude: the task text (or the administrator's for custom), any extra text, and
     * RULES. Through t(): the admin shows them translated, the MCP handler runs it under English.
     *
     * @param array<string, mixed> $schedule task, text
     */
    public static function instructions(array $schedule): string
    {
        $task = self::TASKS[(string) ($schedule['task'] ?? '')] ?? self::TASKS['custom'];
        $text = trim((string) ($schedule['text'] ?? ''));
        $parts = array_filter([$task[1] !== '' ? t($task[1]) : '', $text !== '' ? ($task[1] !== '' ? t('Also:') . ' ' : '') . $text : '', t(self::RULES)]);

        return implode("\n\n", $parts);
    }

    /* ---------- the runs ---------- */

    /**
     * Hands out the runs that are due: for every active schedule with next_due ≤ now, the open run (handed out within
     * HANDOUT_HOURS) or a new one. An open run older than that is written off as failed first. Returns the runs with the
     * schedule rows.
     *
     * @return list<array{run: array<string, mixed>, schedule: array<string, mixed>}>
     */
    public static function handOut(App $app, string $connection): array
    {
        $db = $app->db();
        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach ($db->all('SELECT * FROM {agent_schedules} WHERE active = 1 AND next_due IS NOT NULL AND next_due <= ? ORDER BY next_due, id', [$now]) as $schedule) {
            $open = $db->one("SELECT * FROM {agent_runs} WHERE schedule_id = ? AND status = 'running' ORDER BY id DESC LIMIT 1", [(int) $schedule['id']]);
            if ($open !== null && strtotime((string) $open['started_at']) < time() - self::HANDOUT_HOURS * 3600) {
                $db->update('agent_runs', ['status' => 'failed', 'finished_at' => $now, 'summary' => t('Handed out but not reported within %d hours – written off by the site.', self::HANDOUT_HOURS)], ['id' => (int) $open['id']]);
                $open = null;
            }
            if ($open === null) {
                $id = $db->insert('agent_runs', ['schedule_id' => (int) $schedule['id'], 'due_at' => (string) $schedule['next_due'], 'started_at' => $now, 'status' => 'running', 'connection' => mb_substr($connection, 0, 100)]);
                $open = $db->one('SELECT * FROM {agent_runs} WHERE id = ?', [$id]) ?? [];
            }
            $out[] = ['run' => $open, 'schedule' => $schedule];
        }

        return $out;
    }

    /**
     * Finishes a handed-out run with what the routine reported, sets the schedule's last_run_at and computes its next_due
     * from now. Returns the error for Claude, or null.
     *
     * @param list<array{label: string, url: string}> $links cleaned (cleanLinks)
     */
    public static function report(App $app, int $runId, string $status, string $summary, array $links, string $connection): ?string
    {
        $db = $app->db();
        $run = $db->one('SELECT * FROM {agent_runs} WHERE id = ?', [$runId]);
        if ($run === null) {
            return 'The run does not exist. Use get_due_agent_runs.';
        }
        if ($run['status'] !== 'running') {
            return 'The run is already reported (' . $run['status'] . ').';
        }
        if (!in_array($status, self::REPORTED, true)) {
            return 'status must be ok, partial or failed.';
        }
        $now = new \DateTimeImmutable();
        $db->update('agent_runs', ['status' => $status, 'finished_at' => $now->format('Y-m-d H:i:s'), 'summary' => mb_substr(trim($summary), 0, self::MAX_SUMMARY),
            'links' => $links === [] ? null : (string) json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'connection' => mb_substr($connection, 0, 100)], ['id' => $runId]);
        $schedule = self::get($db, (int) $run['schedule_id']);
        if ($schedule !== null) {
            $db->update('agent_schedules', ['last_run_at' => $now->format('Y-m-d H:i:s'),
                'next_due' => self::nextDue((string) $schedule['cadence'], (int) $schedule['day'], (string) $schedule['time'], $now)->format('Y-m-d H:i:s')], ['id' => (int) $schedule['id']]);
        }

        return null;
    }

    /**
     * The job (Core\Scheduler agent_runs, hourly): a schedule more than MISSED_HOURS past next_due without a run handed out
     * gets a missed run, its next_due moves on, and the event agent_run.missed (a warning the alert e-mails send) tells the
     * owner that the routine in Claude stopped. Returns how many.
     */
    public static function markMissed(App $app): int
    {
        $db = $app->db();
        $now = new \DateTimeImmutable();
        $limit = $now->modify('-' . self::MISSED_HOURS . ' hours')->format('Y-m-d H:i:s');
        $missed = 0;
        foreach ($db->all('SELECT * FROM {agent_schedules} WHERE active = 1 AND next_due IS NOT NULL AND next_due < ?', [$limit]) as $schedule) {
            if ($db->value("SELECT COUNT(*) FROM {agent_runs} WHERE schedule_id = ? AND status = 'running'", [(int) $schedule['id']]) > 0) {
                continue; // handed out – late, but somebody is on it
            }
            $runId = $db->insert('agent_runs', ['schedule_id' => (int) $schedule['id'], 'due_at' => (string) $schedule['next_due'], 'finished_at' => $now->format('Y-m-d H:i:s'), 'status' => 'missed']);
            $db->update('agent_schedules', ['next_due' => self::nextDue((string) $schedule['cadence'], (int) $schedule['day'], (string) $schedule['time'], $now)->format('Y-m-d H:i:s')], ['id' => (int) $schedule['id']]);
            Events::record($db, 'agent_run.missed', 'warning', t('The scheduled Claude run “%s” was not picked up – check the routine in Claude (Scheduled runs).', (string) $schedule['name']),
                ['schedule' => (int) $schedule['id'], 'run' => $runId, 'due_at' => (string) $schedule['next_due']]);
            $missed++;
        }

        return $missed;
    }

    /**
     * Links to the drafts as the routine gives them: {label, url} objects or plain strings (an http(s) URL becomes the
     * url, anything else the label). Only web addresses – nothing else reaches the administrator's browser.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function cleanLinks(mixed $links): array
    {
        return array_slice(Requests::cleanLinks($links), 0, self::MAX_LINKS);
    }

    /** The routine prompt the administrator copies into Claude – the site's MCP address filled in. */
    public static function routinePrompt(App $app): string
    {
        $url = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . $app->url('mcp');

        return 'Connect to the Kaleta site ' . $url . ' and call get_due_agent_runs. For each run it returns, follow its instructions as drafts only – never publish, make visible, delete or send anything – and when you are done call report_agent_run with the run id, the status (ok, partial or failed), a short summary of what you did and what needs a person, and links to the drafts. If nothing is due, stop.';
    }
}
