<?php

declare(strict_types=1);

/* ---------- 2.17: scheduled Claude runs (Core\AgentSchedules) – next_due in the site's time zone ---------- */
$prague = new DateTimeZone('Europe/Prague');
$due = fn (string $cadence, int $day, string $time, string $after): string => Kaleta\Core\AgentSchedules::nextDue($cadence, $day, $time, new DateTimeImmutable($after, $prague))->format('Y-m-d H:i P');
check('2.17 nextDue daily: later today, otherwise tomorrow – across a month end and a year end', [$due('daily', 1, '08:00', '2026-01-31 07:59'), $due('daily', 1, '08:00', '2026-01-31 08:00'), $due('daily', 1, '08:00', '2026-12-31 09:00')],
    ['2026-01-31 08:00 +01:00', '2026-02-01 08:00 +01:00', '2027-01-01 08:00 +01:00']);
check('2.17 nextDue weekly: the ISO weekday this week when still ahead, otherwise next week – across a month end', [$due('weekly', 1, '07:00', '2026-02-27 10:00'), $due('weekly', 1, '07:00', '2026-03-02 06:00'), $due('weekly', 1, '07:00', '2026-03-02 07:00'), $due('weekly', 7, '18:30', '2026-03-02 07:00')],
    ['2026-03-02 07:00 +01:00', '2026-03-02 07:00 +01:00', '2026-03-09 07:00 +01:00', '2026-03-08 18:30 +01:00']);
check('2.17 nextDue monthly: the day of month (1–28) this month when still ahead, otherwise next month – February, December', [$due('monthly', 28, '06:00', '2026-02-28 05:00'), $due('monthly', 28, '06:00', '2026-02-28 06:00'), $due('monthly', 1, '09:00', '2026-12-15 12:00'), $due('monthly', 15, '09:00', '2026-01-31 12:00')],
    ['2026-02-28 06:00 +01:00', '2026-03-28 06:00 +01:00', '2027-01-01 09:00 +01:00', '2026-02-15 09:00 +01:00']);
check('2.17 nextDue keeps the wall-clock time across the daylight-saving changes of 2026 (29 March, 25 October)', [$due('daily', 1, '07:00', '2026-03-28 07:00'), $due('weekly', 7, '07:00', '2026-10-24 12:00'), $due('monthly', 25, '07:00', '2026-10-24 12:00'), $due('daily', 1, '7:00', '2026-03-28 07:30')],
    ['2026-03-29 07:00 +02:00', '2026-10-25 07:00 +01:00', '2026-10-25 07:00 +01:00', '2026-03-29 07:00 +02:00']);
// under English, as the MCP handler runs it – the admin dictionary in the test is Czech and would translate the task texts
$instructions = fn (string $task, string $text = ''): string => Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\AgentSchedules::instructions(['task' => $task, 'text' => $text]));
check('2.17 every task text ends with the rules (drafts only, never publish, stop for a person); the administrator\'s text is the task for custom and an addition for the others',
    [...array_map(fn (string $task): bool => str_contains($instructions($task), 'never publish') && str_contains($instructions($task), 'report_agent_run'), array_keys(Kaleta\Core\AgentSchedules::TASKS)),
        str_starts_with($instructions('custom', 'Check the prices.'), 'Check the prices.'), str_contains($instructions('review', 'Only Services.'), 'Also: Only Services.'), str_contains($instructions('review'), 'site_audit')],
    [true, true, true, true, true, true, true, true]);
check('2.17 validate: the day fits the cadence, the time is HH:MM, custom needs a text; the missed run is a known warning the alerts send',
    [Kaleta\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'weekly', 'day' => 1, 'time' => '07:00']), Kaleta\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'monthly', 'day' => 31, 'time' => '07:00']) !== null,
        Kaleta\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'custom', 'text' => '', 'cadence' => 'daily', 'day' => 1, 'time' => '07:00']) !== null, Kaleta\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'daily', 'day' => 1, 'time' => '7:00']) !== null,
        isset(Kaleta\Core\Events::TYPES['agent_run.missed']), in_array('agent_run.missed', Kaleta\Core\Alerts::WARNINGS, true), isset(Kaleta\Core\Scheduler::JOBS['agent_runs'])],
    [null, true, true, true, true, true, true]);

