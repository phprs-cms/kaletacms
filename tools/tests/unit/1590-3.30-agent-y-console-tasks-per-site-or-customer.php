<?php

declare(strict_types=1);

/* ---------- 3.30 (agent Y): console tasks per site or customer (Fleet\ConsoleTasks over Core\Tasks); the fleet plan for Claude (Fleet\Plan, MCP fleet_plan, prompt fleet_fix) ---------- */
check('3.30: console tasks are tasks about fleet_site:<id> or fleet_customer:<id> – the target, the bell, the section "fleet" (administrators of the console)', [
    array_map(Kaleta\Core\Tasks::cleanTarget(...), ['fleet_site:3', ' fleet_customer:12 ', 'fleet_site:0', 'fleet_sites:3', 'fleet:3', 'fleet_site:3x', 'site:3']),
    Kaleta\Core\Inbox::parse('fleet_site:3'), Kaleta\Core\Inbox::parse('fleet_customer:2'), Kaleta\Core\Inbox::parse('fleet_customer:0'),
    [Kaleta\Core\Assignment::KINDS['fleet_site'] ?? null, Kaleta\Core\Assignment::KINDS['fleet_customer'] ?? null, Kaleta\Core\Inbox::TARGETS['fleet_site'] ?? null],
    Kaleta\Core\Language::runWith('en', fn (): array => [Kaleta\Core\Inbox::where(['kind' => 'fleet_site', 'id' => 3, 'title' => 'Lindner']), Kaleta\Core\Inbox::where(['kind' => 'fleet_customer', 'id' => 2, 'title' => 'Fysiomed'])], 'admin-'),
    [Kaleta\Fleet\ConsoleTasks::site(3), Kaleta\Fleet\ConsoleTasks::customer(2), Kaleta\Fleet\ConsoleTasks::path('fleet_site', 3), Kaleta\Fleet\ConsoleTasks::path('fleet_customer', 2)],
    Kaleta\Core\Tasks::personal('fleet_site:3'), Kaleta\Fleet\ConsoleTasks::KINDS === array_keys(array_intersect_key(Kaleta\Core\Assignment::KINDS, array_flip(['fleet_site', 'fleet_customer'])))],
    [['fleet_site:3', 'fleet_customer:12', null, null, null, null, null], ['fleet_site', 3], ['fleet_customer', 2], null, ['fleet', 'fleet', 'fleet_site'],
        ['Site “Lindner” in the fleet console', 'Console customer “Fysiomed”'],
        ['fleet_site:3', 'fleet_customer:2', 'admin.php?module=fleet&action=detail&id=3#tasks', 'admin.php?module=fleet&action=customer_detail&id=2#tasks'], false, true]);
$y330Src = fn (string $f): string => (string) file_get_contents(KALETA_SYSTEM . '/src/' . $f);
check('[source-pin] 3.30: a console task follows its record – a removed site and a forgotten customer take theirs, a merge moves them, the sweep checks the console tables; only on a console', [
    str_contains($y330Src('Fleet/Console.php'), 'Tasks::forgetTargets($db, [ConsoleTasks::site($id)])'),
    str_contains($y330Src('Fleet/Customers.php'), 'ConsoleTasks::moveCustomer($db, $id, (int) $other)'),
    str_contains($y330Src('Fleet/Customers.php'), 'Tasks::forgetTargets($db, array_map(ConsoleTasks::customer(...), $gone))') && str_contains($y330Src('Fleet/Customers.php'), 'ConsoleTasks::customersWithOpenTasks($db)'),
    str_contains($y330Src('Core/Tasks.php'), "'fleet_site' => ['fleet_sites', 'id'], 'fleet_customer' => ['fleet_customers', 'id']"),
    str_contains($y330Src('Core/Tasks.php'), "(str_starts_with(\$target, 'fleet_') && !Extensions::isEnabled(\$app->settings(), 'fleet'))"),
    (new ReflectionClass(Kaleta\Admin\Modules\Fleet::class))->hasMethod('actionTask'), (new ReflectionClass(Kaleta\Admin\Modules\Fleet::class))->hasMethod('actionCustomerDetail'),
    is_file(KALETA_SYSTEM . '/views/admin/fleet/customer_detail.php')],
    [true, true, true, true, true, true, true, true]);
// Today: an overdue console task is an amber item of kind "task" ranked among the checks by its weight; a task due today, a later one,
// one about a removed site or a forgotten customer is not on Today
$y330Sites = [['id' => 3, 'name' => 'Lindner', 'url' => 'https://lindner.example/', 'customer_id' => 7, 'public_key' => 'secretkey330', 'note' => 'calls on Mondays', 'heartbeat' => '{"claude_connection":{"state":"active"}}'],
    ['id' => 4, 'name' => '', 'url' => 'https://fysio.example/sub', 'customer_id' => null, 'public_key' => 'secretkey331', 'note' => '', 'heartbeat' => '']];
$y330Customers = [7 => 'Lindner GmbH', 8 => 'Forgotten'];
$y330Task = fn (int $id, string $target, ?string $due, string $title = 'Renew the domain'): array => ['id' => $id, 'title' => $title, 'target' => $target, 'assignee_id' => 1, 'created_by' => 1,
    'due_at' => $due, 'remind_at' => null, 'notified_at' => null, 'done_at' => null, 'done_by' => null, 'request_id' => null, 'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00',
    'group' => Kaleta\Core\Tasks::group($due, '2026-11-03')];
$y330Tasks = [$y330Task(1, 'fleet_site:3', '2026-11-01 09:00:00'), $y330Task(2, 'fleet_customer:7', '2026-10-30 09:00:00', 'Check prices before January'), $y330Task(3, 'fleet_site:3', '2026-11-03 09:00:00', 'Today'),
    $y330Task(4, 'fleet_site:4', null, 'Some day'), $y330Task(5, 'fleet_site:99', '2026-10-01 09:00:00', 'Removed site'), $y330Task(6, 'fleet_customer:9', '2026-10-01 09:00:00', 'Forgotten customer')];
$y330Today = Kaleta\Fleet\ConsoleTasks::todayItems($y330Tasks, $y330Sites, $y330Customers, '2026-11-03');
$y330Check = fn (int $site, string $state, int $weight, ?int $since): array => ['site_id' => $site, 'site' => 'Site ' . $site, 'url' => '', 'customer_id' => null, 'check' => 'x', 'label' => 'x', 'state' => $state, 'detail' => '',
    'path' => '', 'link' => '', 'weight' => $weight, 'since' => $since];
$y330Ranked = Kaleta\Fleet\ConsoleTasks::rank([$y330Check(9, 'warn', 20, 100), ...$y330Today, $y330Check(8, 'bad', 10, 100), $y330Check(7, 'warn', 40, 100)]);
check('3.30 Today: overdue console tasks only, amber, kind "task", with the site (or the customer) and the console link; ranked by weight among the checks', [
    array_map(fn (array $i): array => [$i['kind'], $i['task_id'], $i['site_id'], $i['site'], $i['customer_id'], $i['state'], $i['check'], $i['link']], $y330Today),
    array_map(fn (array $i): string => ($i['kind'] ?? 'check') . ':' . ($i['task_id'] ?? $i['site_id']), $y330Ranked),
    count(Kaleta\Fleet\Health::filter($y330Ranked, 7, 'warn')), count(Kaleta\Fleet\Health::filter($y330Ranked, 0, 'bad'))],
    [[['task', 1, 3, 'Lindner', 7, 'warn', 'task', 'admin.php?module=fleet&action=detail&id=3#tasks'], ['task', 2, 0, 'Lindner GmbH', 7, 'warn', 'task', 'admin.php?module=fleet&action=customer_detail&id=7#tasks']],
        ['check:8', 'check:7', 'task:2', 'task:1', 'check:9'], 2, 1]);
// the plan: per site the items and tasks, the site's own connector (its /mcp, from the known address), the order; nothing secret
$y330Items = [['check' => 'certificate', 'label' => 'Certificate', 'detail' => 'expires in 10 days', 'link' => 'https://lindner.example/admin.php?module=status'] + $y330Check(3, 'warn', 45, 100),
    ['check' => 'offsite_backup', 'label' => 'Off-site backup', 'link' => 'https://lindner.example/admin.php?module=settings&tab=backups'] + $y330Check(3, 'bad', 60, 100),
    ['check' => 'cron', 'label' => 'Cron'] + $y330Check(4, 'warn', 20, 100)];
$y330Plan = Kaleta\Fleet\Plan::build($y330Sites, $y330Items, array_slice($y330Tasks, 0, 4), $y330Customers, 0, 'https://console.example/', '2026-11-03');
$y330Json = (string) json_encode($y330Plan);
check('3.30 fleet_plan: per site the items, the tasks, the connector and the order; the customer\'s tasks apart; worst site first; nothing secret', [
    array_map(fn (array $s): array => [$s['id'], $s['name'], $s['url'], $s['customer'] ?? null, $s['connector'], $s['connection'] ?? null, array_column($s['items'], 'check'), array_column($s['tasks'], 'id')], $y330Plan['sites']),
    $y330Plan['sites'][0]['order'], $y330Plan['sites'][0]['tasks'][0],
    array_map(fn (array $t): array => [$t['customer'], $t['id'], $t['overdue'], $t['site_ids']], $y330Plan['customer_tasks']), [$y330Plan['sites_listed'], $y330Plan['sites_clear'], $y330Plan['more_sites']],
    [str_contains($y330Json, 'secretkey33'), str_contains($y330Json, 'calls on Mondays'), str_contains($y330Json, 'heartbeat'), str_contains($y330Json, 'token')],
    Kaleta\Fleet\Plan::build($y330Sites, [], [], $y330Customers)['sites'], Kaleta\Fleet\Plan::build($y330Sites, [], array_slice($y330Tasks, 0, 2), $y330Customers, null)['customer_tasks'],
    count(Kaleta\Fleet\Plan::build($y330Sites, [], array_slice($y330Tasks, 0, 2), $y330Customers, 8)['customer_tasks']),
    array_map(Kaleta\Fleet\Plan::connector(...), ['https://lindner.example/', 'https://x.example/sub', 'javascript:alert(1)', '', null, 'https://a b.example'])],
    [[[3, 'Lindner', 'https://lindner.example', 'Lindner GmbH', 'https://lindner.example/mcp', 'active', ['certificate', 'offsite_backup'], [1, 3]],
        [4, 'https://fysio.example/sub', 'https://fysio.example/sub', null, 'https://fysio.example/sub/mcp', null, ['cron'], [4]]],
        ['Fix "Off-site backup" (red)', 'Task #1 "Renew the domain" (overdue)', 'Fix "Certificate" (amber)', 'Task #3 "Today" (due 2026-11-03)'],
        ['id' => 1, 'title' => 'Renew the domain', 'due' => '2026-11-01 09:00', 'overdue' => true, 'admin_url' => 'https://console.example/admin.php?module=fleet&action=detail&id=3#tasks'],
        [['Lindner GmbH', 2, true, [3]]], [2, 0, 0], [false, false, false, false], [], [], 0,
        ['https://lindner.example/mcp', 'https://x.example/sub/mcp', null, null, null, null]]);
check('[source-pin] 3.30: fleet_plan is a read tool of the fleet extension, outside the essentials, English on both sides, with a title; the prompt fleet_fix is listed only on a console', [
    Kaleta\Mcp\Catalog::TOOLS['fleet_plan'] ?? null, in_array('fleet_plan', Kaleta\Mcp\Catalog::ESSENTIALS, true), in_array('fleet_plan', Kaleta\Mcp\Catalog::WORK, true),
    Kaleta\Mcp\Translator::czech('fleet_plan'), isset(Kaleta\Mcp\Catalog::TITLES['fleet_plan']), Kaleta\Mcp\Tools::annotations('fleet_plan')['readOnlyHint'],
    Kaleta\Mcp\Prompts::FLEET_ONLY, in_array('fleet_fix', array_column(Kaleta\Mcp\Prompts::listAll(), 'name'), true),
    (bool) preg_match('/sites of the customer Acme – only the amber items\..*fleet_plan.*one site at a time.*own connection.*complete_task.*save_task/s',
        Kaleta\Mcp\Prompts::get('fleet_fix', ['customer' => 'Acme', 'severity' => 'amber'])['messages'][0]['content']['text']),
    str_contains(Kaleta\Mcp\Prompts::get('fleet_fix', [])['messages'][0]['content']['text'], 'Fix what needs attention on the sites. You are'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php'), "'prompts/list' => \$ok(['prompts' => Prompts::listAll(\$this->app)])")],
    [['read', 'fleet'], false, false, 'fleet_plan', true, true, ['fleet_fix'], true, true, true, true]);
preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", implode("\n", array_map(fn (string $f): string => (string) file_get_contents(KALETA_SYSTEM . '/views/admin/fleet/' . $f . '.php'), ['customer_detail', 'today', 'detail', 'list', 'customers'])), $y330Texts);
$y330Strings = array_values(array_diff(array_unique([...array_map('stripslashes', $y330Texts[1]), 'Site “%s”', 'Console customer “%s”', 'The customer is not in the console.']), ['Filtrovat']));
check('3.30: the console tasks, the customer screen and the connector hint are translated (cs, de, es); the hint addresses the reader in de-du', [
    array_map(fn (string $code): array => array_values(array_diff($y330Strings, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es']),
    count(array_filter(array_keys(require KALETA_SYSTEM . '/jazyky/admin-de-du.php'), fn (string $k): bool => str_starts_with($k, 'To let Claude work on this site')))],
    [[[], [], []], 1]);
