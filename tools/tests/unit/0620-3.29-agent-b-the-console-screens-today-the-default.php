<?php

declare(strict_types=1);

/* ---------- 3.29 (agent B): the console screens – Today (the default tab), the health matrix, the workload; MCP fleet_today and fleet_health ---------- */
$b329Fleet = new ReflectionClass(Kaleta\Admin\Modules\Fleet::class);
check('[source-pin] 3.29: the fleet console opens on Today; the inbox, the health matrix and the workload are tabs of their own, each an action of the module', [
    // 3.39: the tabs are sections (Fleet::SECTIONS) – Today first with the inbox as its view, the matrix and the workload views of Sites
    array_key_first(Kaleta\Admin\Modules\Fleet::SECTIONS) === '' && array_keys(Kaleta\Admin\Modules\Fleet::SECTIONS[''][1]) === ['', 'inbox']
        && isset(Kaleta\Admin\Modules\Fleet::SECTIONS['sites'][1]['health'], Kaleta\Admin\Modules\Fleet::SECTIONS['sites'][1]['workload']),
    array_map(fn (string $m): bool => $b329Fleet->hasMethod($m), ['actionList', 'actionInbox', 'actionHealth', 'actionWorkload']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Fleet.php'), "return \$this->view('today', 'Fleet'"),
    array_map(fn (string $f): bool => is_file(KALETA_SYSTEM . '/views/admin/fleet/' . $f . '.php'), ['today', 'health', 'workload', 'inbox']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/Digest.php'), "/admin.php?module=fleet&action=inbox&customer=")],
    [true, [true, true, true, true], true, [true, true, true, true], true]);
check('3.29: the screens never clear the page cache (the fleet section is site-neutral); fleet_today and fleet_health are read tools of the fleet extension, outside the essentials, English on both sides of the Translator, with a title', [
    in_array('fleet', Kaleta\Admin\Kernel::SITE_NEUTRAL_MODULES, true),
    array_map(fn (string $t): array => [Kaleta\Mcp\Catalog::TOOLS[$t] ?? null, in_array($t, Kaleta\Mcp\Catalog::ESSENTIALS, true), in_array($t, Kaleta\Mcp\Catalog::WORK, true),
        Kaleta\Mcp\Translator::czech($t), isset(Kaleta\Mcp\Catalog::TITLES[$t])], ['fleet_today', 'fleet_health'])],
    [true, [[['read', 'fleet'], false, false, 'fleet_today', true], [['read', 'fleet'], false, false, 'fleet_health', true]]]);
check('3.29: every check has a short column header, every state a symbol and a name – the same lists as Fleet\Checks', [
    array_keys(Kaleta\Fleet\Health::SHORT) === array_keys(Kaleta\Fleet\Checks::CHECKS), array_keys(Kaleta\Fleet\Health::SYMBOLS) === Kaleta\Fleet\Checks::STATES,
    array_keys(Kaleta\Fleet\Health::STATE_LABELS) === Kaleta\Fleet\Checks::STATES, count(array_unique(Kaleta\Fleet\Health::SYMBOLS)), max(array_map('mb_strlen', Kaleta\Fleet\Health::SHORT)) <= 9],
    [true, true, true, 4, true]);
$b329Now = 1_800_000_000;
$b329Seen = date('Y-m-d H:i:s', $b329Now - 600);
$b329Parts = ['offsite' => ['status' => 'ok', 'at' => date('Y-m-d', $b329Now - 86400), 'recovery_key' => 'confirmed'], 'mail_setup' => ['mode' => 'smtp', 'tested' => true, 'tested_at' => date('Y-m-d', $b329Now)],
    'twofa' => ['required' => 'admins', 'admins_without' => 0], 'cookie_scan' => ['at' => date('Y-m-d', $b329Now), 'error' => false, 'cookies' => 1], 'privacy_page' => 'published',
    'accessibility_statement' => 'published', 'handover' => ['done' => 16, 'total' => 16, 'failing' => []]];
$b329Row = fn (int $id, string $name, array $beat, array $more = []): array => $more + ['id' => $id, 'name' => $name, 'url' => 'https://site' . $id . '.example', 'customer_id' => null,
    'up' => 1, 'up_status' => 200, 'up_changed' => null, 'last_seen' => $b329Seen, 'heartbeat' => (string) json_encode($beat)];
$b329Sites = [
    $b329Row(1, 'Alpha', ['version' => '3.29.0', 'last_backup' => $b329Now - 3600, 'cron_last_run' => $b329Now - 60, 'auto_updates' => true, 'jobs_failing' => []] + $b329Parts
        + ['workload' => ['enquiries_this_week' => 5, 'enquiries_last_week' => 2, 'unanswered_over_24h' => 1, 'median_first_reply_hours' => 3, 'tasks_open' => 4, 'tasks_overdue' => 1, 'customers_this_month' => 2]], ['customer_id' => 7]),
    $b329Row(2, 'Beta', ['version' => '3.29.0', 'last_backup' => $b329Now - 20 * 86400, 'cron_last_run' => $b329Now - 60, 'auto_updates' => true, 'jobs_failing' => ['backup']] + array_replace($b329Parts, ['privacy_page' => 'none'])
        + ['workload' => ['enquiries_this_week' => 9, 'enquiries_last_week' => 1, 'unanswered_over_24h' => 4, 'median_first_reply_hours' => 7, 'tasks_open' => 0, 'tasks_overdue' => 0, 'customers_this_month' => 0]],
        ['up' => 0, 'up_status' => 503, 'customer_id' => 7]),
    $b329Row(3, 'Gamma (3.17)', ['version' => '3.17.0', 'last_backup' => $b329Now - 3600, 'cron_last_run' => $b329Now - 60]),
    $b329Row(4, 'Delta new', [], ['last_seen' => null, 'up' => null]),
];
$b329Matrix = Kaleta\Fleet\Health::matrix($b329Sites, $b329Now, [], 'worst', [7 => 'Acme']);
$b329Today = Kaleta\Fleet\Checks::today($b329Sites, $b329Now);
check('3.29 Health::matrix – the worst site first, every check in the order of Checks with a console-built link; a site before 3.29 is unknown "update the site to see"', [
    array_column($b329Matrix, 'site_id'), $b329Matrix[0]['customer'], array_keys($b329Matrix[0]['checks']) === array_keys(Kaleta\Fleet\Checks::CHECKS),
    $b329Matrix[0]['checks']['privacy_policy']['state'], $b329Matrix[0]['checks']['privacy_policy']['link'], $b329Matrix[0]['checks']['uptime']['link'],
    array_column($b329Matrix, 'update_needed', 'site_id'), array_count_values(array_column(array_values(array_filter($b329Matrix, fn (array $r): bool => $r['site_id'] === 3))[0]['checks'], 'state'))['unknown'] >= 7,
    array_column(Kaleta\Fleet\Health::matrix($b329Sites, $b329Now, [], 'name'), 'site_id'), array_column(Kaleta\Fleet\Health::matrix($b329Sites, $b329Now, [], 'customer', [7 => 'Acme']), 'site_id')],
    [[2, 4, 3, 1], 'Acme', true, 'bad', 'https://site2.example/admin.php?module=settings&tab=cookies', 'https://site2.example/', [2 => false, 4 => false, 3 => true, 1 => false], true,
        [1, 2, 4, 3], [2, 1, 4, 3]]);
check('3.29 Health::summary and filter – red and amber items, the reporting sites with nothing red or amber, not reporting, to update; by customer and severity', [
    Kaleta\Fleet\Health::summary($b329Sites, $b329Today, $b329Now),
    array_unique(array_column(Kaleta\Fleet\Health::filter($b329Today, 7, ''), 'site_id')), array_unique(array_column(Kaleta\Fleet\Health::filter($b329Today, 7, 'bad'), 'state')),
    count(Kaleta\Fleet\Health::filter($b329Today, 0, 'warn')) + count(Kaleta\Fleet\Health::filter($b329Today, 0, 'bad')) === count($b329Today)],
    [['sites' => 4, 'bad' => count(Kaleta\Fleet\Health::filter($b329Today, 0, 'bad')), 'warn' => count(Kaleta\Fleet\Health::filter($b329Today, 0, 'warn')), 'ok_sites' => 2, 'not_reporting' => 1, 'to_update' => 1],
        [2], ['bad'], true]);
$b329Work = Kaleta\Fleet\Checks::workload($b329Sites);
check('3.29 Health::sortWorkload – by a count (the sites without it last, either way) or by name; the totals count only the sites that send it', [
    array_column(Kaleta\Fleet\Health::sortWorkload($b329Work['sites'], 'unanswered_over_24h', 'desc'), 'site_id'), array_column(Kaleta\Fleet\Health::sortWorkload($b329Work['sites'], 'unanswered_over_24h', 'asc'), 'site_id'),
    array_column(Kaleta\Fleet\Health::sortWorkload($b329Work['sites'], 'site', 'asc'), 'site_id'), array_column(Kaleta\Fleet\Health::sortWorkload($b329Work['sites'], 'nonsense', 'desc'), 'site_id'),
    $b329Work['totals']['enquiries_this_week'], $b329Work['totals']['median_first_reply_hours'], $b329Work['reporting'], Kaleta\Fleet\Health::age(null, $b329Now), Kaleta\Fleet\Health::age($b329Now - 3 * 86400, $b329Now)],
    [[2, 1, 4, 3], [1, 2, 4, 3], [1, 2, 4, 3], [3, 4, 2, 1], 14, 5, 2, '–', t('%d days', 3)]);
$b329Keys = ['Needs action', 'Summary of the sites', 'Everything is green on the %d sites.', 'Workload', 'Unanswered over 24 h', 'Median hours to the first reply', 'Severity', 'without a customer',
    'Health of the sites: a row per site, a column per check', 'Support the development of Kaleta CMS', ...array_values(Kaleta\Fleet\Health::SHORT), ...array_values(Kaleta\Fleet\Health::STATE_LABELS)];
$b329Cs = require KALETA_SYSTEM . '/jazyky/admin-cs.php';
check('[source-pin] 3.29: the new texts of the screens are in Czech, German and Spanish; the support link says what the owner asked for in each language', [
    array_values(array_filter($b329Keys, fn (string $k): bool => !isset($b329Cs[$k]) && !in_array($k, ['TLS', 'SPF', 'SMTP', '2FA', 'Auto', 'Cookies', 'Claude', 'OK', 'Online'], true))),
    array_values(array_filter($b329Keys, fn (string $k): bool => !isset((require KALETA_SYSTEM . '/jazyky/admin-de.php')[$k]) && isset($b329Cs[$k]))),
    array_values(array_filter($b329Keys, fn (string $k): bool => !isset((require KALETA_SYSTEM . '/jazyky/admin-es.php')[$k]) && isset($b329Cs[$k]))),
    $b329Cs['Support the development of Kaleta CMS'], (require KALETA_SYSTEM . '/jazyky/admin-de.php')['Support the development of Kaleta CMS'],
    (require KALETA_SYSTEM . '/jazyky/admin-de-du.php')['Support the development of Kaleta CMS'], (require KALETA_SYSTEM . '/jazyky/admin-es.php')['Support the development of Kaleta CMS'],
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php'), 'href="https://buymeacoffee.com/Kaletacms" target="_blank" rel="noopener"><svg') && str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php'), "t('Support the development of Kaleta CMS')"),
    isset($b329Cs['Support Kaleta'])],
    [[], [], [], 'Podpořte vývoj Kaleta CMS', 'Entwicklung von Kaleta CMS unterstützen', 'Unterstütze die Entwicklung von Kaleta CMS', 'Apoyar el desarrollo de Kaleta CMS', true, false]);
