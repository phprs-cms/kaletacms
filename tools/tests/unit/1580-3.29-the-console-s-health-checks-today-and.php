<?php

declare(strict_types=1);

/* ---------- 3.29: the console's health checks, Today and workload (Fleet\Checks, the heartbeat parts behind them) ---------- */
$c329Now = 1_800_000_000;
$c329Day = fn (int $days): string => date('Y-m-d', $c329Now + $days * 86400);
$c329Clean = fn (string $key, mixed $v): mixed => Kaleta\Fleet\Console::clean([$key => $v])[$key];
check('3.29 Console::clean – the new parts: enum values only, counts clamped, real days 1970–2100, known hand-over keys, a wrong shape dropped on its own', [
    $c329Clean('offsite', ['status' => 'paused', 'at' => '2026-11-02', 'recovery_key' => 'unconfirmed', 'host' => 'ftp.client.example', 'error' => 'Login failed for jan']),
    $c329Clean('offsite', ['status' => 'ok', 'at' => '2026-02-30', 'recovery_key' => 'stolen']), $c329Clean('offsite', ['status' => 'OK']), $c329Clean('offsite', 'ok'),
    $c329Clean('offsite', ['status' => 'ok', 'at' => '9999-12-31']), $c329Clean('offsite', ['status' => 'ok', 'at' => '1969-12-31']),
    $c329Clean('mail_setup', ['mode' => 'smtp', 'tested' => 'yes', 'tested_at' => "2026-11-01\n<b>", 'host' => 'smtp.client.example']),
    $c329Clean('mail_setup', ['mode' => 'sendmail']),
    $c329Clean('twofa', ['required' => 'admins', 'admins_without' => 9_223_372_036_854_775_807]), $c329Clean('twofa', ['required' => 'admins', 'admins_without' => -5]),
    $c329Clean('twofa', ['required' => 'spravci']),
    $c329Clean('cookie_scan', ['at' => '2026-11-03', 'error' => 1, 'cookies' => 1e30, 'names' => ['_ga']]),
    $c329Clean('privacy_page', 'published'), $c329Clean('privacy_page', '<script>'), $c329Clean('privacy_page', ['published']),
    $c329Clean('accessibility_statement', 'draft'), $c329Clean('accessibility_statement', 'hidden'),
    $c329Clean('handover', ['done' => 50, 'total' => 16, 'failing' => ['smtp', 'smtp', 'jan@client.example', ['x'], 'notebook', 'Two_step']]),
    $c329Clean('handover', ['done' => 1, 'total' => 1e300]), $c329Clean('handover', ['done' => 3, 'total' => 99_999]),
    $c329Clean('workload', ['enquiries_this_week' => 4, 'enquiries_last_week' => '7', 'unanswered_over_24h' => -3, 'median_first_reply_hours' => 1e30, 'tasks_open' => 5_000_000,
        'tasks_overdue' => 2.0, 'customers_this_month' => null, 'names' => ['Jan']]),
], [
    ['status' => 'paused', 'at' => '2026-11-02', 'recovery_key' => 'unconfirmed'],
    ['status' => 'ok', 'at' => null, 'recovery_key' => null], null, null,
    ['status' => 'ok', 'at' => null, 'recovery_key' => null], ['status' => 'ok', 'at' => null, 'recovery_key' => null],
    ['mode' => 'smtp', 'tested' => false, 'tested_at' => null], null,
    ['required' => 'admins', 'admins_without' => 1_000_000], ['required' => 'admins', 'admins_without' => 0], null,
    ['at' => '2026-11-03', 'error' => false, 'cookies' => 0],
    'published', null, null, 'draft', null,
    ['done' => 16, 'total' => 16, 'failing' => ['smtp', 'notebook']],
    null, ['done' => 3, 'total' => 36, 'failing' => []],
    ['enquiries_this_week' => 4, 'enquiries_last_week' => null, 'unanswered_over_24h' => 0, 'median_first_reply_hours' => null, 'tasks_open' => 1_000_000, 'tasks_overdue' => 2, 'customers_this_month' => null],
]);
check('3.29 Console::storedBeat – a site before 3.29 has none of the new parts; unknown keys never get in', [
    array_values(array_intersect(Kaleta\Fleet\Checks::PARTS, array_keys(Kaleta\Fleet\Console::storedBeat(['heartbeat' => '{"version":"3.28.0","last_backup":1}'])))),
    array_key_exists('path', Kaleta\Fleet\Console::storedBeat(['heartbeat' => '{"version":"3.29.0","path":"https://evil.example","links":{"two_step":"javascript:x"}}']))],
    [[], false]);

$c329Beat = ['version' => '3.29.0', 'last_backup' => $c329Now - 3600, 'cron_last_run' => $c329Now - 300, 'auto_updates' => true, 'jobs_failing' => [], 'update_available' => null,
    'domain_watch' => ['checked' => $c329Now, 'local' => false, 'certificate' => ['expires' => $c329Day(60), 'days' => 60, 'error' => false], 'domain' => ['expires' => $c329Day(300), 'days' => 300, 'error' => false],
        'mail' => ['error' => false, 'spf' => true, 'dmarc' => true, 'dkim' => true, 'spf_covers_smtp' => true]],
    'offsite' => ['status' => 'ok', 'at' => $c329Day(-1), 'recovery_key' => 'confirmed'], 'mail_setup' => ['mode' => 'smtp', 'tested' => true, 'tested_at' => $c329Day(-3)],
    'twofa' => ['required' => 'admins', 'admins_without' => 0], 'cookie_scan' => ['at' => $c329Day(0), 'error' => false, 'cookies' => 2], 'privacy_page' => 'published',
    'accessibility_statement' => 'published', 'handover' => ['done' => 16, 'total' => 16, 'failing' => []], 'claude_connection' => ['state' => 'active', 'pickup' => 'automatic'],
    'workload' => ['enquiries_this_week' => 5, 'enquiries_last_week' => 3, 'unanswered_over_24h' => 1, 'median_first_reply_hours' => 4, 'tasks_open' => 6, 'tasks_overdue' => 2, 'customers_this_month' => 3]];
$c329Up = ['up' => 1, 'up_status' => 200, 'last_seen' => date('Y-m-d H:i:s', $c329Now - 600)];
$c329States = fn (array $beat, array $up = []): array => array_column(Kaleta\Fleet\Checks::forSite($beat, $up + $c329Up, $c329Now), 'state', 'id');
check('3.29 Checks::forSite – a healthy 3.29 site is ok in every check, in the order of CHECKS, each with its fixed path', [
    array_unique($c329States($c329Beat)), array_keys($c329States($c329Beat)) === array_keys(Kaleta\Fleet\Checks::CHECKS),
    array_column(Kaleta\Fleet\Checks::forSite($c329Beat, $c329Up, $c329Now), 'path', 'id') === array_map(fn (array $c): string => $c[3], Kaleta\Fleet\Checks::CHECKS)],
    [['uptime' => 'ok'], true, true]);
$c329Bad = array_replace($c329Beat, ['update_problem' => 'Version 3.29.1 rolled back', 'last_backup' => $c329Now - 10 * 86400, 'cron_last_run' => $c329Now - 30 * 3600, 'auto_updates' => false,
    'jobs_failing' => ['backup', 'cookie_scan'], 'offsite' => ['status' => 'paused', 'at' => $c329Day(-2), 'recovery_key' => 'unconfirmed'], 'mail_setup' => ['mode' => 'php', 'tested' => false],
    'twofa' => ['required' => 'none', 'admins_without' => 2], 'cookie_scan' => ['at' => $c329Day(-20), 'error' => false, 'cookies' => 0], 'privacy_page' => 'none',
    'accessibility_statement' => 'draft', 'handover' => ['done' => 10, 'total' => 16, 'failing' => ['smtp']],
    'requests' => ['new' => 2, 'in_progress' => 0, 'done_to_review' => 0, 'oldest_waiting_hours' => 5], 'claude_connection' => ['state' => 'none'],
    'domain_watch' => ['checked' => $c329Now, 'local' => false, 'certificate' => ['expires' => $c329Day(3)], 'domain' => ['expires' => $c329Day(20)], 'mail' => ['error' => false, 'spf' => true, 'dmarc' => false]]]);
check('3.29 Checks::forSite – the mapping of each check to bad and warn', $c329States($c329Bad, ['up' => 0, 'up_status' => 503, 'last_seen' => date('Y-m-d H:i:s', $c329Now - 3 * 3600)]), [
    'uptime' => 'bad', 'reporting' => 'warn', 'updates' => 'bad', 'offsite_backup' => 'bad', 'local_backup' => 'bad', 'certificate' => 'bad', 'domain' => 'warn', 'mail_records' => 'warn',
    'smtp' => 'warn', 'two_step' => 'bad', 'auto_updates' => 'warn', 'cookie_scan' => 'warn', 'privacy_policy' => 'bad', 'accessibility_statement' => 'warn', 'handover' => 'warn',
    'claude_connection' => 'bad', 'jobs' => 'bad', 'cron' => 'bad']);
$c329Detail = fn (array $beat, string $id, array $up = [], array $context = []): string => array_column(Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Fleet\Checks::forSite($beat, $up + $c329Up, $c329Now, $context), 'admin-'), 'detail', 'id')[$id];
check('3.29 Checks – details are short system texts (English and Czech), a security release waiting is bad at once, the off-site states', [
    $c329Detail($c329Bad, 'offsite_backup'), $c329Detail($c329Bad, 'two_step'), $c329Detail($c329Bad, 'handover'), $c329Detail($c329Bad, 'jobs'),
    $c329Detail($c329Bad, 'uptime', ['up' => 0, 'up_status' => 503]), $c329Detail(array_replace($c329Beat, ['update_available' => '3.29.1']), 'updates'),
    $c329Detail(array_replace($c329Beat, ['update_available' => '3.29.1']), 'updates', [], ['security_version' => '3.29.1']),
    $c329States(array_replace($c329Beat, ['offsite' => ['status' => 'ok', 'at' => $c329Day(-12)]]))['offsite_backup'],
    $c329States(array_replace($c329Beat, ['offsite' => ['status' => 'failing', 'at' => $c329Day(-1)]]))['offsite_backup'],
    $c329States(array_replace($c329Beat, ['offsite' => ['status' => 'never']]))['offsite_backup'],
    $c329States(array_replace($c329Beat, ['offsite' => ['status' => 'off']]))['offsite_backup'],
    Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Fleet\Checks::forSite($c329Bad, $c329Up, $c329Now)[9]['detail'], 'admin-'),
], ['Paused – the recovery key is not saved', 'Not required – 2 administrators sign in without it', '10 of 16 done', '2 failing: backup, cookie_scan', 'Answers with HTTP 503',
    'Version 3.29.1 is available', 'Security update 3.29.1 waits', 'warn', 'bad', 'warn', 'bad', 'Nepovinné – správců bez něj: 2']);
// a site before 3.29 keeps working: what it sends is checked, the new checks say "update the site to see"; no heartbeat at all = no report yet
$c329Old = ['version' => '3.28.0', 'last_backup' => $c329Now - 3600, 'cron_last_run' => $c329Now - 300, 'auto_updates' => true, 'offsite_backup' => true, 'jobs_failing' => []];
check('3.29 Checks – an old heartbeat: the new checks unknown with "update the site to see", the others as before; no heartbeat: no report yet; never checked: not checked yet', [
    $c329States($c329Old), $c329Detail($c329Old, 'two_step'), $c329Detail($c329Old, 'handover'), $c329States(['version' => '3.28.0', 'offsite_backup' => false])['offsite_backup'],
    $c329Detail(['version' => '3.29.0', 'last_backup' => 1, 'privacy_page' => 'none'], 'two_step'), array_unique($c329States([], ['up' => null, 'last_seen' => null])), $c329Detail([], 'cron'),
    $c329States(['heartbeat' => 'x', 'version' => ['3.29'], 'twofa' => 'admins'])['two_step']],
    [['uptime' => 'ok', 'reporting' => 'ok', 'updates' => 'ok', 'offsite_backup' => 'unknown', 'local_backup' => 'ok', 'certificate' => 'unknown', 'domain' => 'unknown', 'mail_records' => 'unknown',
        'smtp' => 'unknown', 'two_step' => 'unknown', 'auto_updates' => 'ok', 'cookie_scan' => 'unknown', 'privacy_policy' => 'unknown', 'accessibility_statement' => 'unknown', 'handover' => 'unknown',
        'claude_connection' => 'unknown', 'jobs' => 'ok', 'cron' => 'ok'],
    'Update the site to see', 'Update the site to see', 'bad', 'Not reported', ['uptime' => 'unknown'], 'No report from the site yet', 'unknown']);

// Today: bad before warn, weight, the oldest first; links only from the site's known address and the fixed paths
$c329Row = fn (int $id, string $name, string $url, array $beat, array $more = []): array => $more + ['id' => $id, 'name' => $name, 'url' => $url, 'customer_id' => null, 'up' => 1,
    'up_changed' => null, 'last_seen' => date('Y-m-d H:i:s', $c329Now - 600), 'heartbeat' => (string) json_encode($beat)];
$c329Sites = [
    $c329Row(1, 'Acme', 'https://acme.example', array_replace($c329Beat, ['privacy_page' => 'none', 'twofa' => ['required' => 'none', 'admins_without' => 0], 'path' => 'https://evil.example/'])),
    $c329Row(2, "Beta\nCLICK https://evil.example", 'https://beta.example', array_replace($c329Beat, ['privacy_page' => 'none', 'last_backup' => $c329Now - 20 * 86400]), ['customer_id' => 4]),
    $c329Row(3, 'Down', 'javascript:alert(1)', $c329Beat, ['up' => 0, 'up_changed' => date('Y-m-d H:i:s', $c329Now - 7200)]),
    $c329Row(4, 'Old', 'https://old.example', $c329Old),
];
$c329Today = Kaleta\Fleet\Checks::today($c329Sites, $c329Now);
check('3.29 Checks::today – ranked (bad, weight, oldest first, name), warn and bad only, links built by the console from the known address, never from the heartbeat', [
    array_map(fn (array $i): string => $i['state'] . ':' . $i['site_id'] . ':' . $i['check'], $c329Today),
    array_column($c329Today, 'link'), $c329Today[1]['site'], $c329Today[1]['customer_id'], in_array('unknown', array_column($c329Today, 'state'), true),
    Kaleta\Fleet\Checks::link('https://acme.example@evil.example', 'two_step'), Kaleta\Fleet\Checks::link('https://acme.example/', 'nope'), Kaleta\Fleet\Checks::link('https://acme.example/', 'uptime')],
    [['bad:3:uptime', 'bad:2:local_backup', 'bad:1:privacy_policy', 'bad:2:privacy_policy', 'warn:1:two_step'],
        ['', 'https://beta.example/admin.php?module=settings&tab=backups', 'https://acme.example/admin.php?module=settings&tab=cookies', 'https://beta.example/admin.php?module=settings&tab=cookies',
            'https://acme.example/admin.php?module=settings&tab=security'],
        'Beta CLICK https://evil.example', 4, false, '', '', 'https://acme.example/']);
$c329Work = Kaleta\Fleet\Checks::workload([...$c329Sites, $c329Row(5, 'Busy', 'https://busy.example', array_replace($c329Beat, ['workload' => ['enquiries_this_week' => 1e30, 'median_first_reply_hours' => 10, 'tasks_open' => 4, 'evil' => 1]]))]);
check('3.29 Checks::workload – totals over the sites that send each count, the median of the sites\' medians, a site before 3.29 is not known', [
    $c329Work['totals'], $c329Work['reporting'], array_column($c329Work['sites'], 'known', 'site_id'), array_key_exists('evil', $c329Work['sites'][4])],
    [['enquiries_this_week' => 15, 'enquiries_last_week' => 9, 'unanswered_over_24h' => 3, 'median_first_reply_hours' => 4, 'tasks_open' => 22, 'tasks_overdue' => 6, 'customers_this_month' => 9],
        4, [1 => true, 2 => true, 3 => true, 4 => false, 5 => true], false]);

// the site's side: pure helpers, no host, no name, no text of anything
$c329Settings = function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values + Kaleta\Core\Settings::DEFAULTS);

    return $s;
};
$c329Remote = ['remote_backup' => 'ftp', 'backup_host' => 'ftp.client.example'];
check('3.29 Heartbeat – off-site, mail, cookie scan, hand-over and the median reply time as states, days and counts', [
    Kaleta\Fleet\Heartbeat::offsite($c329Settings(['remote_backup' => 'vypnuto', 'remote_backup_status' => '2026-11-01 10:00|ok']), 'none'),
    Kaleta\Fleet\Heartbeat::offsite($c329Settings($c329Remote), 'none'),
    Kaleta\Fleet\Heartbeat::offsite($c329Settings($c329Remote + ['remote_backup_status' => '2026-11-02 03:00|ok']), 'confirmed'),
    Kaleta\Fleet\Heartbeat::offsite($c329Settings($c329Remote + ['remote_backup_status' => '2026-11-02 03:00|paused']), 'unconfirmed'),
    Kaleta\Fleet\Heartbeat::offsite($c329Settings($c329Remote + ['remote_backup_status' => '2026-11-02 03:00|530 Login incorrect for jan@client.example']), 'confirmed'),
    Kaleta\Fleet\Heartbeat::mailSetup($c329Settings(['mail_mode' => 'smtp', 'smtp_host' => 'smtp.client.example', 'smtp_last_ok' => '2026-11-01 09:00:00|smtp.client.example'])),
    Kaleta\Fleet\Heartbeat::mailSetup($c329Settings(['mail_mode' => 'smtp', 'smtp_host' => 'smtp.new.example', 'smtp_last_ok' => '2026-11-01 09:00:00|smtp.client.example'])),
    Kaleta\Fleet\Heartbeat::mailSetup($c329Settings(['mail_mode' => 'mail'])),
    Kaleta\Fleet\Heartbeat::cookieScan(['time' => mktime(12, 0, 0, 11, 2, 2026), 'pages' => 4, 'cookies' => ['_ga', 'PHPSESSID'], 'error' => '']), Kaleta\Fleet\Heartbeat::cookieScan([]),
    Kaleta\Fleet\Heartbeat::handover(['done' => 2, 'total' => 3, 'items' => [['key' => 'smtp', 'done' => false, 'label' => 'x', 'findings' => [['message' => 'jan@client.example']]], ['key' => 'company', 'done' => true], ['key' => 'notebook', 'done' => true]]]),
    Kaleta\Fleet\Heartbeat::medianFirstReply([
        ['datum' => '2026-11-01 08:00:00', 'replies' => '[{"at":"2026-11-01 10:00:00","text":"Hi Jan"},{"at":"2026-11-01 09:00:00"}]'],
        ['datum' => '2026-11-01 08:00:00', 'replies' => '[{"at":"2026-11-01 13:00:00"}]'], ['datum' => '2026-11-02 08:00:00', 'replies' => '[{"at":"2026-11-02 20:00:00"}]'],
        ['datum' => '2026-11-02 08:00:00', 'replies' => '[{"at":"2026-11-01 20:00:00"}]'], ['datum' => '2026-11-02 08:00:00', 'replies' => 'broken']]),
    Kaleta\Fleet\Heartbeat::medianFirstReply([]),
], [
    ['status' => 'off', 'at' => null, 'recovery_key' => 'none'], ['status' => 'never', 'at' => null, 'recovery_key' => 'none'],
    ['status' => 'ok', 'at' => '2026-11-02', 'recovery_key' => 'confirmed'], ['status' => 'paused', 'at' => '2026-11-02', 'recovery_key' => 'unconfirmed'],
    ['status' => 'failing', 'at' => '2026-11-02', 'recovery_key' => 'confirmed'],
    ['mode' => 'smtp', 'tested' => true, 'tested_at' => '2026-11-01'], ['mode' => 'smtp', 'tested' => false, 'tested_at' => null], ['mode' => 'php', 'tested' => false, 'tested_at' => null],
    ['at' => '2026-11-02', 'error' => false, 'cookies' => 2], ['at' => null, 'error' => false, 'cookies' => 0],
    ['done' => 2, 'total' => 3, 'failing' => ['smtp']], 5, null]);
// every key a hand-over row can have is one the console accepts (Core\Audit::handoverRows); every label and detail is translated
preg_match_all("/\\\$row\\('([a-z_]+)'/", (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Audit.php'), $c329Rows);
preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/Checks.php'), $c329Texts);
$c329Strings = array_values(array_unique([...array_map('stripslashes', $c329Texts[1]), ...array_map(fn (array $c): string => $c[0], Kaleta\Fleet\Checks::CHECKS)]));
check('3.29 Checks – the hand-over row keys match the site audit; every label and detail is translated (cs, de, es) and the address in de-du', [
    array_values(array_diff($c329Rows[1], Kaleta\Fleet\Checks::HANDOVER_ROWS)), array_values(array_diff(Kaleta\Fleet\Checks::HANDOVER_ROWS, $c329Rows[1])), count($c329Strings) > 60,
    array_map(fn (string $code): array => array_values(array_diff($c329Strings, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es']),
    isset((require KALETA_SYSTEM . '/jazyky/admin-de-du.php')['Update the site to see'])],
    [[], [], true, [[], [], []], true]);

