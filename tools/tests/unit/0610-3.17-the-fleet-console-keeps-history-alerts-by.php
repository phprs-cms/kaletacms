<?php

declare(strict_types=1);

use Kaleta\Core\ReportSummary;

/* ---------- 3.17: the fleet console keeps history, alerts by rules, a weekly digest and a monthly report per customer ---------- */
use Kaleta\Fleet\AlertRules;
use Kaleta\Fleet\Digest;
use Kaleta\Fleet\History;
use Kaleta\Fleet\Report;
$day317 = fn (int $ok, int $failed, int $ms = 0, int $answered = 0, ?int $attention = null, string $reasons = '', string $version = '3.17.0'): array => ['checks_ok' => $ok, 'checks_failed' => $failed,
    'response_ms_total' => $ms, 'response_count' => $answered, 'attention' => $attention, 'reasons' => $reasons, 'version' => $version];
check('3.17: History::uptime – the share of answered checks (rounded down, never 100 % with a failure), the average response of the answered ones; nothing measured = null', [
    History::uptime([$day317(287, 1, 287 * 200, 287), $day317(288, 0, 288 * 300, 288)]), History::uptime([$day317(1, 2)]), History::uptime([]), History::uptime([$day317(9999, 1)])['percent']],
    [['percent' => 99.82, 'checks' => 576, 'failed' => 1, 'response_ms' => 250], ['percent' => 33.33, 'checks' => 3, 'failed' => 2, 'response_ms' => null], ['percent' => null, 'checks' => 0, 'failed' => 0, 'response_ms' => null], 99.99]);
$bars317 = History::bars(['2026-10-18' => $day317(10, 0, 1000, 10), '2026-10-20' => $day317(1, 1)], '2026-10-20', 4);
check('3.17: History::bars – one bar a day, oldest first, a day without checks is null; History::minutes clips a period to the month, an open one counts until now', [
    array_column($bars317, 'day'), array_column($bars317, 'percent'), $bars317[2]['response_ms'],
    History::minutes('2026-09-30 23:00:00', '2026-10-01 01:30:00', (int) strtotime('2026-10-01 00:00:00'), (int) strtotime('2026-11-01 00:00:00'), (int) strtotime('2026-10-20 12:00:00')),
    History::minutes('2026-10-31 23:50:00', null, (int) strtotime('2026-10-01 00:00:00'), (int) strtotime('2026-11-01 00:00:00'), (int) strtotime('2026-11-01 08:00:00')),
    History::minutes('2026-10-05 10:00:00', null, (int) strtotime('2026-10-01 00:00:00'), (int) strtotime('2026-11-01 00:00:00'), (int) strtotime('2026-10-05 10:45:00')),
    History::minutes('2026-09-01 10:00:00', '2026-09-01 11:00:00', (int) strtotime('2026-10-01 00:00:00'), (int) strtotime('2026-11-01 00:00:00'), (int) strtotime('2026-10-20 12:00:00'))],
    [['2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20'], [null, 100.0, null, 50.0], null, 90, 10, 45, 0]);
check('3.17: History keeps 400 days – the oldest day kept; the attention of a month by days and reasons (unknown reasons dropped, days without a record not counted)', [
    History::KEEP_DAYS, History::oldestKept((int) strtotime('2026-10-20 12:00:00')),
    History::attention([$day317(1, 0, attention: 0), $day317(1, 0, attention: 40, reasons: 'jobs_failing,no_cron'), $day317(1, 0, attention: 45, reasons: 'jobs_failing,nonsense'), $day317(1, 0)])],
    [400, '2025-09-15', ['days' => 2, 'recorded' => 3, 'reasons' => ['jobs_failing' => 2, 'no_cron' => 1]]]);
$beat317 = ['last_backup' => $fleetNow - 3600, 'cron_last_run' => $fleetNow - 300, 'enquiries_new_not_spam' => 3, 'requests' => ['new' => 2, 'in_progress' => 1, 'done_to_review' => 4, 'oldest_waiting_hours' => 50, 'items' => null],
    'pending_review' => ['page_drafts' => 2, 'news_drafts' => 1], 'audit' => ['description' => 4, 'link' => 1], 'security_7_days' => ['failed_sign_ins' => 7], 'problems' => [['check' => 'Mail']],
    'report_summary' => ['month' => '2026-09', 'visits' => 1200, 'enquiries' => 14, 'requests_handled' => 5, 'changes_people' => 30, 'changes_claude' => 12, 'updates' => 1, 'backups' => 30, 'secret' => 'x']];
$snap317 = History::snapshotRow($fleetRow(['version' => '3.17.0'], $beat317), $fleetNow);
$bare317 = History::snapshotRow($fleetRow(['version' => '3.15.0'], []), $fleetNow);
check('3.17: History::snapshotRow – the day\'s state from the stored heartbeat: attention and reasons, what waits, counts only; a site that sends nothing has nulls, not zeros', [
    array_intersect_key($snap317, array_flip(['status', 'version', 'attention', 'reasons', 'enquiries_new', 'requests_open', 'waiting_total', 'audit_total', 'failed_sign_ins', 'problems'])),
    json_decode((string) $snap317['report_summary'], true), [$bare317['enquiries_new'], $bare317['requests_open'], $bare317['waiting_total'], $bare317['failed_sign_ins'], $bare317['report_summary']]],
    [['status' => 'ok', 'version' => '3.17.0', 'attention' => 17, 'reasons' => 'enquiries,audit,requests_waiting', 'enquiries_new' => 3, 'requests_open' => 3, 'waiting_total' => 3, 'audit_total' => 5, 'failed_sign_ins' => 7, 'problems' => 1],
        ['month' => '2026-09', 'visits' => 1200, 'enquiries' => 14, 'requests_handled' => 5, 'changes_people' => 30, 'changes_claude' => 12, 'updates' => 1, 'backups' => 30], [null, null, null, null, null]]);
$stored317 = ['month' => '2026-09', 'data' => ['month' => '2026-09', 'stats' => ['visits' => 120], 'enquiries' => ['total' => 3, 'forms' => [['form' => 'Kontakt jan@visitor.example', 'n' => 3]]],
    'requests' => ['done_count' => 2, 'done' => [['title' => 'Call jan@client.example']]], 'work' => ['areas' => ['pages' => ['people' => 4, 'claude' => 6], 'look' => ['people' => 1, 'claude' => 0]]],
    'updates' => [['type' => 'update.applied'], ['type' => 'update.failed']], 'backups' => ['created' => 31]]];
check('3.17: the heartbeat\'s report_summary – the headline counts of the latest archived report only (no titles, no forms); the console keeps only a real month and the known counts', [
    Kaleta\Fleet\Heartbeat::reportSummary($stored317), Kaleta\Fleet\Heartbeat::reportSummary(null),
    Kaleta\Fleet\Heartbeat::reportSummary(['month' => '2026-09', 'data' => ['backups' => ['created' => 2]]]),
    Kaleta\Fleet\Console::clean(['report_summary' => ['month' => '2026-09', 'visits' => 5, 'enquiries' => 'many', 'title' => 'Call jan@client.example', 'backups' => 1e30]])['report_summary'],
    Kaleta\Fleet\Console::clean(['report_summary' => ['month' => '2026-13', 'visits' => 5]])['report_summary'], Kaleta\Fleet\Console::clean([])['report_summary']],
    [['month' => '2026-09', 'visits' => 120, 'enquiries' => 3, 'requests_handled' => 2, 'changes_people' => 5, 'changes_claude' => 6, 'updates' => 1, 'backups' => 31], null,
        ['month' => '2026-09', 'visits' => null, 'enquiries' => null, 'requests_handled' => null, 'changes_people' => null, 'changes_claude' => null, 'updates' => 0, 'backups' => 2],
        ['month' => '2026-09', 'visits' => 5, 'enquiries' => null, 'requests_handled' => null, 'changes_people' => null, 'changes_claude' => null, 'updates' => null, 'backups' => null], null, null]);
check('3.17: Console::customerIdOf – the customer id of a site row (Fleet\\Customers), 0 for none; the uptime check measures the time to the first byte', [
    Kaleta\Fleet\Console::customerIdOf(['id' => 1]), Kaleta\Fleet\Console::customerIdOf(['customer_id' => 5]), Kaleta\Fleet\Console::customerIdOf(['customer_id' => '7']), Kaleta\Fleet\Console::customerIdOf(['customer_id' => null]),
    Kaleta\Fleet\Console::customerIdOf(['customer_id' => -3]),
    Kaleta\Fleet\Http::milliseconds(0.2345), Kaleta\Fleet\Http::milliseconds(-1.0), Kaleta\Fleet\Http::milliseconds(999.0), Kaleta\Fleet\Http::checks(['http://10.0.0.5:8080/'])],
    [0, 5, 7, 0, 0, 235, 0, 60000, [['status' => 0, 'ms' => null]]]);
// 3.17 merge: the digest and the fleet report group by the customer id in the order of the inbox's customer filter
// (Customers::names), the sites without one – or of a customer that no longer exists – last
$groups317 = Report::byCustomer([['id' => 1, 'customer_id' => 9], ['id' => 2, 'customer_id' => null], ['id' => 3, 'customer_id' => 4], ['id' => 4, 'customer_id' => 9], ['id' => 5, 'customer_id' => 77]],
    [4 => 'Acme', 9 => 'Pekárna Novák']);
check('3.17 merge: Report::byCustomer – by customer id, the inbox filter\'s order, the sites without a customer last', [
    array_map(fn (array $g): array => [$g['id'], $g['name'], array_column($g['sites'], 'id')], $groups317), Report::byCustomer([], [4 => 'Acme'])],
    [[[4, 'Acme', [3]], [9, 'Pekárna Novák', [1, 4]], [0, '', [2, 5]]], []]);
// alert rules: the defaults keep the behaviour before 3.17 (down and silent), thresholds bounded, the quiet hours per site and kind
$rules317 = AlertRules::sanitize([]);
$all317 = AlertRules::sanitize(array_map(fn (array $k): array => ['on' => '1'], AlertRules::KINDS));
check('3.17: AlertRules – by default only "site down" and "site stopped reporting" are on, as before 3.17; a threshold stays within its bounds; unknown kinds are dropped', [
    array_keys(array_filter($rules317, fn (array $r): bool => $r['on'])), $rules317['certificate']['threshold'], $rules317['site_down']['threshold'],
    AlertRules::sanitize(['certificate' => ['on' => true, 'threshold' => '9999'], 'domain' => ['on' => '1', 'threshold' => 'x'], 'evil' => ['on' => true]]) === array_replace($rules317,
        ['certificate' => ['on' => true, 'threshold' => 90], 'domain' => ['on' => true, 'threshold' => 30]])],
    [['site_down', 'site_silent'], 14, null, true]);
$alertSite317 = fn (array $more, array $beat = []): array => $fleetRow($more + ['id' => 7, 'up_status' => 0, 'paired_at' => date('Y-m-d H:i:s', $fleetNow - 90 * 86400)], $beat);
$kinds317 = fn (array $site, array $rules): array => array_column(AlertRules::matches($site, $rules, $fleetNow), 'kind');
check('3.17: AlertRules::matches – each kind from the console\'s check or the stored heartbeat, at its threshold; nothing when a rule is off', [
    $kinds317($alertSite317(['up' => 0, 'up_status' => 503]), $rules317), AlertRules::matches($alertSite317(['up' => 0, 'up_status' => 503]), $rules317, $fleetNow)[0]['detail'],
    $kinds317($alertSite317(['last_seen' => date('Y-m-d H:i:s', $fleetNow - 27 * 3600)]), $rules317), $kinds317($alertSite317(['last_seen' => date('Y-m-d H:i:s', $fleetNow - 25 * 3600)]), $rules317),
    $kinds317($alertSite317([], ['domain_watch' => ['certificate' => ['days' => 5], 'domain' => ['days' => 29]], 'update_problem' => 'Version 3.17.1 did not work',
        'security_7_days' => ['failed_sign_ins' => 50], 'requests' => ['oldest_waiting_hours' => 49]]), $all317),
    $kinds317($alertSite317([], ['domain_watch' => ['certificate' => ['days' => 14], 'domain' => ['days' => 30]], 'security_7_days' => ['failed_sign_ins' => 49], 'requests' => ['oldest_waiting_hours' => 48]]), $all317),
    $kinds317($alertSite317(['up' => 0], ['update_problem' => 'x']), AlertRules::sanitize(['site_down' => ['on' => false]])),
    $kinds317($alertSite317([], ['domain_watch' => ['local' => true, 'certificate' => ['days' => 1]]]), $all317)],
    [['site_down'], 'HTTP 503', ['site_silent'], [], ['certificate', 'domain', 'update_failed', 'security_spike', 'requests_waiting'], [], [], []]);
$found317 = [['site' => 7, 'kind' => 'site_down', 'detail' => 'no answer'], ['site' => 7, 'kind' => 'certificate', 'detail' => '5 days left'], ['site' => 8, 'kind' => 'site_down', 'detail' => 'no answer']];
check('3.17: AlertRules::quiet – the same site and kind is not sent again within the quiet hours, a lasting state is sent again after them; other sites and kinds go out', [
    AlertRules::quiet($found317, ['7:site_down' => $fleetNow - 3600], 24, $fleetNow), AlertRules::quiet($found317, ['7:site_down' => $fleetNow - 25 * 3600, '8:site_down' => $fleetNow - 60], 24, $fleetNow)],
    [[$found317[1], $found317[2]], [$found317[0], $found317[1]]]);
$alertMail317 = AlertRules::message($reportSettings(['site_name' => 'Agency console']), $found317, [7 => ['name' => 'Bakery', 'url' => 'https://bakery.example'], 8 => ['name' => '', 'url' => 'https://shop.example']], 'https://console.example');
check('3.17: the alert e-mail – grouped by site, plain links to the console, the kinds and details in words, nothing with "@"; recipients fall back to the alert address, then the site e-mail', [
    $alertMail317[0], str_contains($alertMail317[1], "Bakery – https://bakery.example\n  – Site down: no answer\n  – Certificate ends soon: 5 days left\n  https://console.example/admin.php?module=fleet&action=detail&id=7"),
    str_contains($alertMail317[1], 'https://console.example/admin.php?module=fleet&action=alerts'), str_contains($alertMail317[1] . $alertMail317[0], '@'), str_contains($alertMail317[1], '<'),
    AlertRules::recipients($reportSettings(['fleet_alert_recipients' => "a@agency.example, nonsense\nb@agency.example", 'alerts_email' => 'alerts@agency.example'])),
    AlertRules::recipients($reportSettings(['alerts_email' => 'alerts@agency.example', 'site_email' => 'site@agency.example'])), AlertRules::recipients($reportSettings(['site_email' => 'site@agency.example']))],
    ['Agency console: 3 alert(s) about your sites', true, true, false, false, ['a@agency.example', 'b@agency.example'], ['alerts@agency.example'], ['site@agency.example']]);
check('3.17: Core\Alerts leaves the console\'s "down" and "stopped reporting" to the alert rules (no double e-mail); its other errors and warnings stay', array_column(Kaleta\Core\Alerts::worth([
    ['id' => 1, 'created_at' => '', 'type' => 'fleet.site_down', 'severity' => 'error', 'message' => '', 'data' => []],
    ['id' => 2, 'created_at' => '', 'type' => 'fleet.site_silent', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 3, 'created_at' => '', 'type' => 'backup.failed', 'severity' => 'error', 'message' => '', 'data' => []],
    ['id' => 4, 'created_at' => '', 'type' => 'fleet.kit_refused', 'severity' => 'error', 'message' => '', 'data' => []]]), 'id'), [3, 4]);
// the weekly digest: Monday morning, once a week, off by default; request titles only when the owner turns them on
$digestOn317 = $reportSettings(['fleet_digest' => '1', 'fleet_digest_sent' => '2026-W42']);
check('3.17: Digest::due – off by default; on: Monday from 7:00, once an ISO week', [Digest::due($reportSettings([]), new DateTimeImmutable('2026-10-19 08:00')),
    Digest::due($digestOn317, new DateTimeImmutable('2026-10-19 06:59')), Digest::due($digestOn317, new DateTimeImmutable('2026-10-19 07:00')), Digest::due($digestOn317, new DateTimeImmutable('2026-10-20 08:00')),
    Digest::due($reportSettings(['fleet_digest' => '1', 'fleet_digest_sent' => '2026-W43']), new DateTimeImmutable('2026-10-19 09:00')), Kaleta\Core\Settings::DEFAULTS['fleet_digest'], Kaleta\Core\Settings::DEFAULTS['fleet_digest_titles']],
    [false, false, true, false, false, '0', '0']);
$digestData317 = ['from' => '2026-10-12', 'to' => '2026-10-18', 'customers' => [
    ['id' => 12, 'name' => 'Pekárna Novák', 'sites' => [['id' => 8, 'name' => '', 'url' => 'https://shop.example', 'reasons' => [], 'enquiries' => 0, 'requests' => null, 'request_titles' => [], 'review' => null, 'bookings' => 1,
        'uptime' => ['percent' => null, 'checks' => 0, 'failed' => 0, 'response_ms' => null], 'incidents' => [], 'versions' => []]]],
    ['id' => 0, 'name' => '', 'sites' => [['id' => 7, 'name' => 'Bakery', 'url' => 'https://bakery.example', 'reasons' => ['down', 'enquiries'], 'enquiries' => 2, 'requests' => ['new' => 1, 'in_progress' => 0, 'done_to_review' => 3],
        'request_titles' => ['New price list', 'Call [e-mail] back'], 'review' => 2, 'bookings' => null, 'uptime' => ['percent' => 98.5, 'checks' => 2000, 'failed' => 30, 'response_ms' => 210],
        'incidents' => [['started_at' => '2026-10-14 10:00:00', 'ended_at' => '2026-10-14 12:30:00', 'status' => 0, 'minutes' => 150]], 'versions' => [['date' => '2026-10-15 03:00:00', 'version' => '3.17.0']]]]]]];
$digest317 = Digest::compose($digestData317, $reportSettings(['site_name' => 'Agency console']), 'https://console.example', false);
$digestTitles317 = Digest::compose($digestData317, $reportSettings(['site_name' => 'Agency console']), 'https://console.example', true);
check('3.17: the digest – by customer, what needs attention and what waits as counts, the week\'s outages, uptime and updates, plain links; request titles only when turned on; nothing with "@"', [
    $digest317[0], str_contains($digest317[1], '1 of 2 sites need attention.'), str_contains($digest317[1], '== Sites without a customer =='), str_contains($digest317[1], '== Pekárna Novák =='),
    str_contains($digest317[1], 'Needs attention: does not answer, enquiries waiting'), str_contains($digest317[1], 'Waiting: 2 enquiries waiting, 1 requests open, 3 done requests to review, 2 waiting for review'),
    str_contains($digest317[1], 'Uptime of the week: 98.50 % · 1 outages, 150 minutes down'), str_contains($digest317[1], 'Updated to 3.17.0'), str_contains($digest317[1], 'Waiting: 1 bookings to confirm'),
    str_contains($digest317[1], 'https://console.example/admin.php?module=fleet&action=detail&id=7'), str_contains($digest317[1], 'https://bakery.example/admin.php'),
    str_contains($digest317[1], 'New price list'), str_contains($digestTitles317[1], '    · New price list'), str_contains($digest317[1] . $digestTitles317[1], '@'),
    str_contains(Digest::compose($digestData317, $reportSettings(['site_language' => 'cs']), 'https://console.example', false)[1], 'Týden vašich webů'),
    str_contains($digest317[1], "== Pekárna Novák ==\nWhat waits on these sites: https://console.example/admin.php?module=fleet&action=inbox&customer=12\n"),
    strpos($digest317[1], '== Pekárna Novák ==') < strpos($digest317[1], '== Sites without a customer =='), substr_count($digest317[1], 'module=fleet&action=inbox&customer=')],
    ['Agency console: the week of your sites (12 Oct 2026 – 18 Oct 2026)', true, true, true, true, true, true, true, true, true, true, false, true, false, true, true, true, 1]);
// 3.17 merge: one source of the thresholds – the alert rules' defaults are the console's, the inbox follows the owner's
check('3.17 merge: the alert rules\' default thresholds are Console::THRESHOLDS (the attention and the inbox use them); a site\'s attention by them', [
    array_filter(array_map(fn (array $k): ?int => $k[1], AlertRules::KINDS), 'is_int'), AlertRules::thresholds($reportSettings([])),
    AlertRules::thresholds($reportSettings(['fleet_alert_rules' => '{"certificate":{"on":false,"threshold":30},"requests_waiting":{"on":true,"threshold":24}}']))['certificate'],
    Kaleta\Fleet\Heartbeat::REQUEST_WAIT_HOURS, Kaleta\Fleet\Console::SILENT_AFTER],
    [Kaleta\Fleet\Console::THRESHOLDS, Kaleta\Fleet\Console::THRESHOLDS, 30, 48, 26 * 3600]);
$thresholdSite317 = ['id' => 4, 'name' => 'Delta', 'url' => 'https://delta.example', 'customer_id' => null, 'up' => 1, 'up_changed' => null, 'status' => 'ok', 'last_seen' => date('Y-m-d H:i:s', $fleetNow - 20 * 3600),
    'heartbeat' => (string) json_encode(['last_backup' => $fleetNow - 3600, 'domain_watch' => ['local' => false, 'certificate' => ['expires' => $fleetDay(20)], 'domain' => null],
        'security_7_days' => ['failed_sign_ins' => 30, 'events' => []], 'requests' => ['new' => 1, 'in_progress' => 0, 'done_to_review' => 0, 'oldest_waiting_hours' => 30, 'items' => null]])];
$thresholdRows317 = fn (array $t): array => array_map(fn (array $r): string => $r['what'] . ':' . $r['urgency'], Kaleta\Fleet\Inbox::build([$thresholdSite317], [], $fleetNow, $t));
$owner317 = AlertRules::thresholds($reportSettings(['fleet_alert_rules' => '{"certificate":{"on":false,"threshold":30},"security_spike":{"on":false,"threshold":25},"requests_waiting":{"on":true,"threshold":24},"site_silent":{"on":true,"threshold":12}}']));
check('3.17 merge: the inbox ranks by the alert rules\' thresholds – the defaults like before, the owner\'s when changed (also for a rule that is off)', [
    $thresholdRows317([]), $thresholdRows317(AlertRules::thresholds($reportSettings([]))), $thresholdRows317($owner317)],
    [['requests_open:normal'], ['requests_open:normal'], ['requests_open:urgent', 'site_silent:urgent', 'sign_in_attacks:urgent', 'certificate_expiring:normal']]);
// the monthly fleet report per customer: from the console's history, with and without the site's own report headline
$siteData317 = Report::siteData(['id' => 7, 'name' => 'Bakery jan@bakery.example', 'url' => 'https://bakery.example', 'version' => '3.17.1'],
    ['2026-09-01' => $day317(287, 1, 287 * 200, 287, 0, '', '3.16.0'), '2026-09-02' => $day317(280, 8, 280 * 400, 280, 100, 'down,errors', '3.17.0')],
    [['started_at' => '2026-09-02 10:00:00', 'ended_at' => '2026-09-02 10:40:00', 'status' => 503, 'minutes' => 40]], [['date' => '2026-09-02 03:00:00', 'version' => '3.17.0']],
    ['month' => '2026-09', 'visits' => 1200, 'enquiries' => 14, 'requests_handled' => 5, 'changes_people' => 30, 'changes_claude' => 12, 'updates' => 1, 'backups' => 30]);
$quiet317 = Report::siteData(['id' => 8, 'name' => '', 'url' => 'https://shop.example', 'version' => '3.16.2'], [], [], [], null);
$fleetData317 = Kaleta\Core\MonthlyReport::scrub(['schema' => 1, 'month' => '2026-09', 'customer' => 'Pekárna Novák', 'generated_at' => '2026-10-01T06:05:00+02:00', 'sites' => [$siteData317, $quiet317], 'totals' => Report::totals([$siteData317, $quiet317])]);
check('3.17: Report::siteData and totals – uptime, outages, the versions from the first to the last day, attention by days and reasons, the site\'s own counts; a site without history has none', [
    $siteData317['uptime'], $siteData317['downtime_minutes'], [$siteData317['version_start'], $siteData317['version_end']], $siteData317['attention'], $siteData317['summary']['visits'], isset($siteData317['summary']['month']),
    [$quiet317['days_recorded'], $quiet317['version_start'], $quiet317['summary'], $quiet317['uptime']['percent']], $fleetData317['totals']],
    [['percent' => 98.43, 'checks' => 576, 'failed' => 9, 'response_ms' => 299], 40, ['3.16.0', '3.17.0'], ['days' => 1, 'recorded' => 2, 'reasons' => ['down' => 1, 'errors' => 1]], 1200, false,
        [0, '3.16.2', null, null], ['sites' => 2, 'uptime_percent' => 98.43, 'incidents' => 1, 'downtime_minutes' => 40, 'updates' => 1, 'visits' => 1200, 'enquiries' => 14, 'requests_handled' => 5]]);
$fleetSections317 = array_column(Kaleta\Core\Language::runWith('en', fn (): array => Report::sections($fleetData317), 'admin-'), null, 'key');
check('3.17: Report::sections – at a glance, the sites, one section per site with its outages, updates, reasons and its own report (or a note that it did not arrive); no "@", no hours or prices', [
    array_keys($fleetSections317), $fleetSections317['summary']['blocks'][0]['items'], $fleetSections317['sites']['blocks'][0]['rows'][0],
    array_column(array_filter($fleetSections317['site-7']['blocks'], fn (array $b): bool => $b['type'] === 'table'), 'caption'),
    $fleetSections317['site-8']['blocks'][1]['text'] ?? null, str_contains((string) json_encode($fleetSections317, JSON_UNESCAPED_UNICODE), '@'),
    preg_match('/\b(hours? worked|price|invoice|CZK|EUR)\b/i', (string) json_encode($fleetSections317))],
    [['summary', 'sites', 'site-7', 'site-8'], [['Sites', '2'], ['Uptime', '98.43 %'], ['Outages', '1'], ['Updates installed', '1'], ['Visits', '1200'], ['Enquiries received', '14'], ['Requests handled', '5']],
        ['Bakery …', '3.17.0', '98.43 %', '1', '1'], ['Outages', 'Updates installed', 'What needed attention', 'The site\'s own monthly report'],
        'The site\'s own monthly report for this month has not reached the console (the site sends it from Kaleta 3.17 on).', false, 0]);
$fleetPage317 = Report::render($view316, ['month' => '2026-09', 'customer' => 'Pekárna Novák', 'created_at' => '2026-10-01 06:05:00', 'rebuilt_at' => null, 'data' => $fleetData317],
    $reportSettings(['site_name' => 'Agency console', 'agency_name' => 'Studio Kaleta', 'agency_logo' => 'media/logo.svg']), 'https://console.example');
$fleetScreen317 = Report::render($view316, ['month' => '2026-09', 'customer' => '', 'created_at' => '2026-10-01 06:05:00', 'rebuilt_at' => '2026-10-02 09:00:00', 'data' => $fleetData317],
    $reportSettings(['site_name' => 'Agency console', 'site_language' => 'cs']), 'https://console.example', ['script' => '/image/tisk.js', 'download' => '/x', 'back' => '/y']);
check('3.17: the printable fleet report – the A4 page of the site report with the console\'s agency, the customer and the month; no script in the download, nothing personal; in the console\'s language on screen', [
    str_contains($fleetPage317, 'size: A4'), str_contains($fleetPage317, '<p class="druh">Fleet report</p>'), str_contains($fleetPage317, '<h1>September 2026</h1>'), str_contains($fleetPage317, 'Pekárna Novák <span>· 2 sites</span>'), str_contains($fleetPage317, 'A summary of what happened to these websites in September 2026'),
    str_contains($fleetPage317, 'https://console.example/media/logo.svg'), str_contains($fleetPage317, '<script'), preg_match('/@(?!page|media|bottom)/', $fleetPage317), str_contains($fleetPage317, 'Website report'),
    str_contains($fleetScreen317, 'data-tisk'), str_contains($fleetScreen317, 'Zpráva o webech'), str_contains($fleetScreen317, 'Weby bez zákazníka'), str_contains($fleetScreen317, 'z uchované historie'),
    str_contains($fleetScreen317, '<div class="agentura">Agency console</div>')],
    [true, true, true, true, true, true, false, 0, false, true, true, true, true, true]);
check('3.17: the jobs of the console run only on a console, after the uptime check; fleet_report is a read tool of the fleet extension, outside the essentials', [
    array_map(fn (string $job): bool => Kaleta\Core\Scheduler::applies($job, $reportSettings(['extensions' => '-'])), ['fleet_history', 'fleet_alerts', 'fleet_digest', 'fleet_reports']),
    array_search('fleet_alerts', array_keys(Kaleta\Core\Scheduler::JOBS), true) > array_search('fleet_uptime', array_keys(Kaleta\Core\Scheduler::JOBS), true),
    Kaleta\Mcp\Catalog::TOOLS['fleet_report'] ?? null, in_array('fleet_report', Kaleta\Mcp\Catalog::ESSENTIALS, true), isset(Kaleta\Core\Events::TYPES['fleet.alert_sent']), Report::KEEP_MONTHS],
    [[false, false, false, false], true, ['read', 'fleet'], false, true, 24]);
