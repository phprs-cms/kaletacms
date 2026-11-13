<?php

declare(strict_types=1);

use Kaleta\Core\Clock;
use Kaleta\Core\Deals;
use Kaleta\Core\TeamSummary;
use Kaleta\Mcp\Catalog;

/* ---------- 3.41 A: the team summary (step 7, counts only) and the deal card (days in the stage, the next step) ---------- */
Clock::freeze('2026-11-12 10:30:00');
check('3.41 A: the periods – the last N days end now, this month from its first day, last month the whole calendar month; an unknown one is 30 days', [
    TeamSummary::range('7_days'), TeamSummary::range('30_days'), TeamSummary::range('this_month'), TeamSummary::range('last_month'), TeamSummary::range('90_days'),
    TeamSummary::period('bogus'), TeamSummary::period(['7_days']), TeamSummary::period(null), TeamSummary::period('last_month')],
    [['2026-11-06 00:00:00', '2026-11-12 10:30:00'], ['2026-10-14 00:00:00', '2026-11-12 10:30:00'], ['2026-11-01 00:00:00', '2026-11-12 10:30:00'],
        ['2026-10-01 00:00:00', '2026-11-01 00:00:00'], ['2026-08-15 00:00:00', '2026-11-12 10:30:00'], '30_days', '30_days', '30_days', 'last_month']);
Clock::freeze('2026-01-15 08:00:00');
check('3.41 A: last month in January is December of the year before', TeamSummary::range('last_month'), ['2025-12-01 00:00:00', '2026-01-01 00:00:00']);
Clock::freeze(null);

check('3.41 A: a section is counted only for whoever may open it – tasks always; the spam condition is the one of Enquiries (Core never names Admin)', [
    TeamSummary::sectionsFor([]), TeamSummary::sectionsFor(['enquiries' => 'x', 'pipeline' => 'x']), TeamSummary::sectionsFor(['enquiries' => 'x', 'pipeline' => 'x', 'applications' => 'x', 'quotes' => 'x', 'pages' => 'x']),
    TeamSummary::NOT_SPAM === Kaleta\Admin\Modules\Enquiries::NOT_SPAM],
    [['tasks'], ['enquiries', 'tasks', 'deals'], ['enquiries', 'tasks', 'deals', 'applications', 'quotes'], true]);

$a341People = [3 => ['tasks_done' => 2, 'tasks_overdue' => 1, 'deals_won' => 1], 0 => ['deals_won' => 1, 'quotes_accepted' => 4], 7 => ['tasks_done' => 1]];
check('3.41 A: the totals and a person\'s row hold every count of the viewer\'s sections (0 when nothing), nothing of another section', [
    TeamSummary::totals($a341People, ['tasks', 'deals']), TeamSummary::row($a341People[7], ['tasks']), TeamSummary::totals([], ['applications'])],
    [['tasks_open' => 0, 'tasks_overdue' => 1, 'tasks_done' => 3, 'deals_moved' => 0, 'deals_won' => 2, 'deals_lost' => 0], ['tasks_open' => 0, 'tasks_overdue' => 0, 'tasks_done' => 1],
        ['applications_handled' => 0]]);

check('3.41 A: applications handled – one per application and person with a status change inside the period, whoever it was (0 = Kaleta itself)', TeamSummary::changesBy([
    ['id' => 1, 'history' => json_encode([['at' => '2026-11-02 10:00:00', 'by' => 5, 'from' => 'new', 'to' => 'reviewed'], ['at' => '2026-11-03 10:00:00', 'by' => 5, 'from' => 'reviewed', 'to' => 'interview']])],
    ['id' => 2, 'history' => json_encode([['at' => '2026-10-02 10:00:00', 'by' => 5], ['at' => '2026-11-04 09:00:00', 'by' => 8], ['at' => '2026-11-05 09:00:00', 'by' => null]])],
    ['id' => 3, 'history' => 'not json'], ['id' => 4, 'history' => null]], '2026-11-01 00:00:00', '2026-11-12 00:00:00'), [5 => 1, 8 => 1, 0 => 1]);

$a341Summary = ['period' => '7_days', 'from' => 'a', 'to' => 'b', 'sections' => ['tasks'], 'team' => ['tasks_open' => 3, 'tasks_overdue' => 0, 'tasks_done' => 2], 'you' => ['tasks_open' => 1, 'tasks_overdue' => 0, 'tasks_done' => 0]];
check('3.41 A: MCP team_summary – people only for an administrator (null = none), each person with only the counts above 0 and no user for "nobody"', [
    TeamSummary::compact($a341Summary + ['people' => null]),
    TeamSummary::compact($a341Summary + ['people' => [['id' => 4, 'name' => 'Eva', 'counts' => ['tasks_open' => 1, 'tasks_overdue' => 0, 'tasks_done' => 2]], ['id' => 0, 'name' => 'Nobody responsible', 'counts' => ['tasks_open' => 2, 'tasks_overdue' => 0, 'tasks_done' => 0]]]])['people']],
    [['period' => '7_days', 'from' => 'a', 'to' => 'b', 'team' => ['tasks_open' => 3, 'tasks_overdue' => 0, 'tasks_done' => 2], 'you' => ['tasks_open' => 1, 'tasks_overdue' => 0, 'tasks_done' => 0]],
        [['user' => 4, 'name' => 'Eva', 'tasks_open' => 1, 'tasks_done' => 2], ['user' => null, 'name' => 'Nobody responsible', 'tasks_open' => 2]]]);

check('3.41 A: the report block – how many people first, then the counts of the period in order; a count of the moment (open, overdue) never; labels translated', [
    Kaleta\Core\Language::runWith('en', fn (): array => TeamSummary::reportPairs(['people' => 3, 'tasks_done' => 4, 'enquiries_answered' => 2, 'tasks_open' => 9, 'deals_won' => 1], fn (mixed $v): string => (string) $v), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): array => TeamSummary::reportPairs(['people' => 1], fn (mixed $v): string => (string) $v), 'admin-')],
    [[['People who did counted work', '3'], ['Enquiries answered', '2'], ['Tasks done', '4'], ['Deals won', '1']], [['Lidé se započítanou prací', '1'], ['Součty týmu se zobrazují od tří lidí.', '']]]); // 3.41 review L3: below three people a note, no totals

$a341Texts = [...array_values(TeamSummary::LABELS), ...array_values(TeamSummary::PERIODS), 'Nobody responsible', 'People who did counted work', 'Team summary', 'You', 'The whole team',
    'Nothing counted in this period yet.', 'Days in this stage: %d', 'In this stage since today', 'Next: %s', 'No next step', 'In this stage', 'Next step', 'since today',
    '1 day (since %s)', '%d days (since %s)', 'add a task', 'You see your own counts and the team’s totals.',
    'Only administrators see the counts of each person; the monthly report shows the team’s totals without names.',
    '%s – %s. Counts only – never hours or money. Open enquiries and open and overdue tasks are counted as they are now, everything else within the period.'];
check('3.41 A: every count has a label and a section; every text is in the cs, de and es dictionaries (the script text too)', [
    array_keys(TeamSummary::LABELS) === array_merge(...array_values(TeamSummary::COUNTS)), array_diff(TeamSummary::NOW, array_keys(TeamSummary::LABELS)),
    array_map(fn (string $code): array => array_values(array_diff($a341Texts, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es']),
    array_map(fn (string $code): bool => isset($scriptDictionary(KALETA_ROOT . '/image/jazyky/admin-' . $code . '.js')['In this stage since today']), ['cs', 'de', 'es'])],
    [true, [], [[], [], []], [true, true, true]]);

check('3.41 A: team_summary is a read tool of the work set – never the essentials – English only, with one parameter', [
    Catalog::access('team_summary'), in_array('team_summary', Catalog::WORK, true), in_array('team_summary', Catalog::ESSENTIALS, true), Catalog::setsOf('team_summary'),
    in_array('team_summary', Kaleta\Mcp\Translator::names(), true),
    array_keys((array) (array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), null, 'name')['team_summary']['inputSchema']['properties'] ?? []))],
    ['read', true, false, ['work'], true, ['period']]);

check('3.41 A: days in the stage – whole days on the site\'s calendar, 0 today, never below 0 (a moment in the future), 0 for nonsense', [
    Deals::daysSince('2026-11-12 08:00:00', '2026-11-12'), Deals::daysSince('2026-11-11 23:59:00', '2026-11-12'), Deals::daysSince('2026-10-31 12:00:00', '2026-11-12'),
    Deals::daysSince('2026-11-20 00:00:00', '2026-11-12'), Deals::daysSince('', '2026-11-12'), Deals::daysSince('2026-03-28 10:00:00', '2026-03-30')],
    [0, 1, 12, 0, 0, 2]);
