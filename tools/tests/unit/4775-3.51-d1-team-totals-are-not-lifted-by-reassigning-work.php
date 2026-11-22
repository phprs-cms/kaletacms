<?php

declare(strict_types=1);

/* ---------- 3.51 D1 (SECURITY-3, N148): work the viewer put on someone else does not lift the team-total threshold ---------- */
use Kaleta\Core\TeamSummary as D775Summary;

// an App over an in-memory database with the four tables the planted counts read (and the settings of the default stages)
$d775App = function (array $rows): Kaleta\Core\App {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    foreach ([
        'CREATE TABLE ka_tasks (id INTEGER PRIMARY KEY, assignee_id INTEGER NULL, created_by INTEGER NULL, assigned_by INTEGER NULL, due_at TEXT NULL, done_at TEXT NULL)',
        "CREATE TABLE ka_poptavky (idp INTEGER PRIMARY KEY, stav INTEGER DEFAULT 0, kategorie TEXT DEFAULT '', prirazeno INTEGER NULL, assigned_by INTEGER NULL)",
        'CREATE TABLE ka_deals (id INTEGER PRIMARY KEY, owner_id INTEGER NULL, assigned_by INTEGER NULL, stage TEXT, closed_at TEXT NULL)',
        'CREATE TABLE ka_deal_stage_history (id INTEGER PRIMARY KEY, deal_id INTEGER, from_stage TEXT NULL, to_stage TEXT, at TEXT, user_id INTEGER NULL)',
        'CREATE TABLE ka_quotes (id INTEGER PRIMARY KEY, owner_id INTEGER NULL, assigned_by INTEGER NULL, status TEXT, decided_at TEXT NULL, decided_by INTEGER NULL)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    foreach ($rows as $sql) {
        $pdo->exec($sql);
    }
    $settings = new Kaleta\Core\Settings($db);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($settings, ['pipeline_stages' => '']);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'lazyRead'))->setValue($settings, array_fill_keys((new ReflectionMethod(Kaleta\Core\Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));
    $app = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\App::class, 'db'))->setValue($app, $db);
    (new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($app, $settings);

    return $app;
};

// the viewer (1) and colleague A (2) did the counted work; B (3) has only what one row below puts on them; 4 is a third person
// (an administrator, say). Each scenario is one row of B's; the viewer must never see the total when the viewer planted it.
$d775Won = Kaleta\Core\Deals::parseStages('')[array_search('won', array_column(Kaleta\Core\Deals::parseStages(''), 'kind'), true)]['key'];
$d775Lost = Kaleta\Core\Deals::parseStages('')[array_search('lost', array_column(Kaleta\Core\Deals::parseStages(''), 'kind'), true)]['key'];
$d775At = date('Y-m-d H:i:s', time() - 86400);
[$d775From, $d775To] = ['2000-01-01 00:00:00', '2100-01-01 00:00:00'];
$d775Sections = ['enquiries', 'tasks', 'deals', 'quotes'];
$d775Collected = [1 => ['tasks_open' => 1, 'enquiries_open' => 1, 'deals_won' => 1, 'deals_lost' => 1, 'quotes_accepted' => 1],
    2 => ['tasks_open' => 1, 'enquiries_open' => 1, 'deals_won' => 1, 'deals_lost' => 1, 'quotes_accepted' => 1],
    3 => ['tasks_open' => 1, 'enquiries_open' => 1, 'deals_won' => 1, 'deals_lost' => 1, 'quotes_accepted' => 1]];
$d775Shown = function (array $rows, int $viewer = 1) use ($d775App, $d775Collected, $d775Sections, $d775From, $d775To): array {
    $app = $d775App($rows);
    $visible = D775Summary::visibleTotals(D775Summary::withoutPlanted($app, $d775Collected, $d775Sections, $viewer, $d775From, $d775To), $d775Collected, $d775Sections, $viewer);

    return array_keys($visible ?? []);
};
$d775All = ['enquiries_open', 'tasks_open', 'deals_won', 'deals_lost', 'quotes_accepted'];
$d775Others = fn (string $without): array => array_values(array_diff($d775All, [$without]));

check('3.51 D1: the reviewer\'s scenario – a task reassigned to B, an enquiry given to B, a changed deal owner, a colleague\'s deal won, a quote marked accepted by the viewer: that count stays hidden from the viewer', [
    $d775Shown(['INSERT INTO ka_tasks (assignee_id, created_by, assigned_by) VALUES (3, 2, 1)']),
    $d775Shown(['INSERT INTO ka_poptavky (stav, prirazeno, assigned_by) VALUES (1, 3, 1)']),
    $d775Shown(["INSERT INTO ka_deals (id, owner_id, assigned_by, stage, closed_at) VALUES (9, 3, 1, '$d775Won', '$d775At')"]),
    $d775Shown(["INSERT INTO ka_deals (id, owner_id, assigned_by, stage, closed_at) VALUES (9, 3, 3, '$d775Won', '$d775At')",
        "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES (9, 'x', '$d775Won', '$d775At', 1)"]),
    $d775Shown(["INSERT INTO ka_deals (id, owner_id, assigned_by, stage, closed_at) VALUES (9, 3, NULL, '$d775Lost', '$d775At')",
        "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES (9, 'x', '$d775Lost', '$d775At', 1)"]),
    $d775Shown(["INSERT INTO ka_quotes (owner_id, assigned_by, status, decided_at, decided_by) VALUES (3, 3, 'accepted', '$d775At', 1)"]),
    $d775Shown(["INSERT INTO ka_quotes (owner_id, assigned_by, status, decided_at, decided_by) VALUES (3, 1, 'accepted', '$d775At', NULL)"])],
    [$d775Others('tasks_open'), $d775Others('enquiries_open'), $d775Others('deals_won'), $d775Others('deals_won'), $d775Others('deals_lost'), $d775Others('quotes_accepted'), $d775Others('quotes_accepted')]);

check('3.51 D1: the same work put there by a third person (or an administrator), by a rule or before 3.51 (assigned_by NULL), or on the viewer themselves – every total is shown', [
    $d775Shown(['INSERT INTO ka_tasks (assignee_id, created_by, assigned_by) VALUES (3, 1, 4)']), // created by the viewer, reassigned by someone else
    $d775Shown(['INSERT INTO ka_poptavky (stav, prirazeno, assigned_by) VALUES (1, 3, NULL)']),
    $d775Shown(["INSERT INTO ka_deals (id, owner_id, assigned_by, stage, closed_at) VALUES (9, 3, 4, '$d775Won', '$d775At')",
        "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES (9, 'x', '$d775Won', '$d775At', 4)"]),
    $d775Shown(["INSERT INTO ka_quotes (owner_id, assigned_by, status, decided_at, decided_by) VALUES (3, 4, 'accepted', '$d775At', NULL)"]),
    $d775Shown(['INSERT INTO ka_tasks (assignee_id, created_by, assigned_by) VALUES (1, 1, 1)']),
    $d775Shown(['INSERT INTO ka_tasks (assignee_id, created_by, assigned_by) VALUES (3, 1, 1)'], 4)], // the viewer is the third person: not their planting
    [$d775All, $d775All, $d775All, $d775All, $d775All, $d775All]);

check('3.51 D1: what does not lift a count is not taken away – a done task, a resolved or spam enquiry, a deal moved into its stage before it closed, a quote accepted outside the period', [
    $d775Shown(["INSERT INTO ka_tasks (assignee_id, created_by, assigned_by, done_at) VALUES (3, 1, 1, '$d775At')"]),
    $d775Shown(['INSERT INTO ka_poptavky (stav, prirazeno, assigned_by) VALUES (2, 3, 1)', "INSERT INTO ka_poptavky (stav, kategorie, prirazeno, assigned_by) VALUES (1, 'spam', 3, 1)"]),
    $d775Shown(["INSERT INTO ka_deals (id, owner_id, assigned_by, stage, closed_at) VALUES (9, 3, 3, '$d775Won', '$d775At')",
        "INSERT INTO ka_deal_stage_history (deal_id, from_stage, to_stage, at, user_id) VALUES (9, 'x', '$d775Won', '2001-01-01 00:00:00', 1)"]),
    (function () use ($d775App, $d775Collected, $d775Sections): array {
        $app = $d775App(["INSERT INTO ka_quotes (owner_id, assigned_by, status, decided_at, decided_by) VALUES (3, 1, 'accepted', '2001-01-01 00:00:00', 1)"]);
        return array_keys(D775Summary::visibleTotals(D775Summary::withoutPlanted($app, $d775Collected, $d775Sections, 1, '2026-01-01 00:00:00', '2100-01-01 00:00:00'), $d775Collected, $d775Sections, 1) ?? []);
    })()],
    [$d775All, $d775All, $d775All, $d775All]);

// the totals shown never change, only who counts for the threshold; a section the viewer cannot open is not read
$d775App2 = $d775App(['INSERT INTO ka_tasks (assignee_id, created_by, assigned_by) VALUES (3, 1, 1)', 'INSERT INTO ka_poptavky (stav, prirazeno, assigned_by) VALUES (1, 3, 1)']);
check('3.51 D1: withoutPlanted only lowers B\'s row for the counts of the sections asked for; the totals (and so an administrator\'s view) stay; viewer 0 changes nothing', [
    D775Summary::withoutPlanted($d775App2, $d775Collected, ['tasks'], 1, $d775From, $d775To)[3],
    D775Summary::withoutPlanted($d775App2, $d775Collected, $d775Sections, 0)[3],
    D775Summary::visibleTotals(D775Summary::withoutPlanted($d775App2, $d775Collected, $d775Sections, 1, $d775From, $d775To), $d775Collected, $d775Sections, 1)['deals_won'] ?? null,
    D775Summary::totals($d775Collected, ['tasks'])],
    [['tasks_open' => 0, 'enquiries_open' => 1, 'deals_won' => 1, 'deals_lost' => 1, 'quotes_accepted' => 1], $d775Collected[3], 3, ['tasks_open' => 3, 'tasks_overdue' => 0, 'tasks_done' => 0]]);
