<?php

declare(strict_types=1);

use Kaleta\Fleet\AlertRules;
use Kaleta\Fleet\Digest;
use Kaleta\Fleet\History;
use Kaleta\Fleet\Report;

/* ---------- 3.17 security fixes (N317-1, -2, -5, -6, -7): one paired site can no longer stop the console or write into its e-mails ---------- */
$hostile317 = Kaleta\Fleet\Console::clean([
    'audit' => ['link' => PHP_INT_MAX, 'title' => [1], 'menu' => -7, 'BAD KEY' => 3, 'description' => 2.0],
    'update_problem' => ['x'], 'enquiries_unanswered' => -1, 'enquiries_7_days' => PHP_INT_MAX, 'visits_7_days' => [1, 2], 'last_backup' => 1e300, 'cron_last_run' => -5,
    'kit_version' => '3', 'db_version' => 1e20, 'problems_7_days' => ['mail.failed' => [1], 'backup.failed' => PHP_INT_MAX, "bad\nkey" => 1],
    'jobs_failing' => ['backup', ['x'], 'Evil Job', 7], 'problems' => ['text', ['check' => ['x'], 'status' => 'hacked', 'detail' => "line\nbreak", 'extra' => 'drop']],
    'status' => ['ok'], 'php' => "8.4\n<b>", 'update_channel' => 'LATEST!', 'offsite_backup' => 'yes', 'auto_updates' => 1, 'claude' => [true],
    'update_available' => '9.9.9; rm', 'version' => str_repeat('1', 40), 'url' => 'https://acme.example@evil.example', 'name' => ['x'],
]);
check('3.17 N317-1: every key a heartbeat has carried since 2.9 is bounded on its own – counts 0…1,000,000, times 1970…2100, names by pattern, texts on one line, lists in their shape', [
    $hostile317['audit'], [$hostile317['update_problem'], $hostile317['enquiries_unanswered'], $hostile317['enquiries_7_days'], $hostile317['visits_7_days'], $hostile317['last_backup'],
        $hostile317['cron_last_run'], $hostile317['kit_version'], $hostile317['db_version']],
    $hostile317['problems_7_days'], $hostile317['jobs_failing'], $hostile317['problems'],
    [$hostile317['status'], $hostile317['php'], $hostile317['update_channel'], $hostile317['offsite_backup'], $hostile317['auto_updates'], $hostile317['claude'], $hostile317['update_available'],
        $hostile317['version'], $hostile317['url'], $hostile317['name']],
    array_intersect_key(Kaleta\Fleet\Console::clean(['version' => '3.17.0', 'php' => '8.4.13', 'update_channel' => 'stable', 'status' => 'warning', 'last_backup' => 1_760_000_000, 'update_available' => '3.17.1',
        'kit_version' => 2, 'url' => 'https://www.example.cz', 'offsite_backup' => false]), array_flip(['version', 'php', 'update_channel', 'status', 'last_backup', 'update_available', 'kit_version', 'url', 'offsite_backup']))],
    [['link' => 1_000_000, 'menu' => 0, 'description' => 2], [null, 0, 1_000_000, null, null, null, null, null],
        ['backup.failed' => 1_000_000], ['backup'], [['group' => '', 'check' => '', 'status' => 'warning', 'detail' => 'line break']],
        [null, null, null, null, true, null, null, null, null, null],
        ['url' => 'https://www.example.cz', 'version' => '3.17.0', 'php' => '8.4.13', 'status' => 'warning', 'last_backup' => 1_760_000_000, 'offsite_backup' => false, 'update_available' => '3.17.1',
            'update_channel' => 'stable', 'kit_version' => 2]]);
// a heartbeat stored before this release kept anything: the console's pages, jobs and rules read it through the bounds
$stored317 = function (string $json) use ($fleetNow, $all317): string {
    $site = ['id' => 9, 'name' => 'Hostile', 'url' => 'https://hostile.example', 'customer_id' => null, 'up' => 1, 'up_changed' => null, 'up_status' => 0, 'status' => 'ok', 'version' => '3.17.0',
        'ring' => 'normal', 'manage_updates' => 1, 'update_allowed' => '', 'version_since' => null,
        'last_seen' => date('Y-m-d H:i:s', $fleetNow - 600), 'paired_at' => date('Y-m-d H:i:s', $fleetNow - 86400), 'heartbeat' => $json];
    set_error_handler(static function (int $no, string $message): never {
        throw new ErrorException($message, 0, $no); // as the console's own error handler (App) does
    });
    try {
        $row = History::snapshotRow($site, $fleetNow);
        $columns = array_filter(array_intersect_key($row, array_flip(['attention', 'enquiries_new', 'requests_open', 'waiting_total', 'audit_total', 'failed_sign_ins', 'problems'])), fn (mixed $n): bool => $n !== null);
        $badColumns = array_filter($columns, fn (mixed $n): bool => !is_int($n) || $n < 0 || $n > 4_294_967_295);
        $badCounts = array_filter(array_map(fn (array $r): mixed => $r['count'], Kaleta\Fleet\Inbox::build([$site], [], $fleetNow)), fn (mixed $n): bool => $n !== null && (!is_int($n) || $n < 0 || $n > 1_000_000));
        Kaleta\Fleet\Console::attention($site, $fleetNow);
        AlertRules::matches($site, $all317, $fleetNow);
        Kaleta\Fleet\Console::row($site);

        return $badColumns === [] && $badCounts === [] ? 'ok' : 'out of range: ' . json_encode([$badColumns, $badCounts]);
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
};
$storedPayloads317 = [
    '{"audit":{"x":9223372036854775807,"y":1}}', '{"audit":{"x":[1]}}', '{"update_problem":["x"]}', '{"enquiries_unanswered":-1,"audit":{"x":-7}}', '{"enquiries_unanswered":9223372036854775807}',
    '{"last_backup":1e300,"cron_last_run":"yesterday","bookings_pending":1e300,"enquiries_new_not_spam":[3]}',
    '{"requests":{"new":1e300,"in_progress":[1],"oldest_waiting_hours":"many","items":[{"id":1,"status":["x"]},{"id":[2],"status":"new"},"x"]},"pending_review":{"page_drafts":9223372036854775807,"x":[1]}}',
    '{"security_7_days":"x","domain_watch":{"certificate":"x","domain":{"days":9223372036854775807}},"problems":"x","jobs_failing":[["x"]]}',
    '{"security_7_days":{"failed_sign_ins":1e300,"events":"x"},"requests":"x","domain_watch":"x"}', '[1,2,3]', 'not json',
];
check('3.17 N317-1: a hostile stored heartbeat – the inbox, the attention, the day\'s row (INT UNSIGNED columns) and the alert rules neither throw nor leave the bounds', array_map($stored317, $storedPayloads317),
    array_fill(0, count($storedPayloads317), 'ok'));
$hostileSite317 = ['id' => 9, 'name' => 'Hostile', 'url' => 'https://hostile.example', 'customer_id' => null, 'up' => 1, 'up_changed' => null, 'up_status' => 0, 'status' => 'ok',
    'last_seen' => date('Y-m-d H:i:s', $fleetNow - 600), 'paired_at' => date('Y-m-d H:i:s', $fleetNow - 86400),
    'heartbeat' => '{"last_backup":' . ($fleetNow - 3600) . ',"cron_last_run":' . ($fleetNow - 60) . ',"audit":{"x":9223372036854775807,"y":1},"update_problem":["x"],"enquiries_unanswered":-1}'];
$hostileInbox317 = array_column(Kaleta\Fleet\Inbox::forSite($hostileSite317, [], $fleetNow), 'count', 'what');
check('3.17 N317-1: what the hostile report still shows – the audit as a bounded count, the failed update, no enquiries; the update rule fires without the site\'s text', [
    $hostileInbox317, Kaleta\Fleet\Console::attention($hostileSite317, $fleetNow)['reasons'], AlertRules::matches($hostileSite317, $all317, $fleetNow), History::snapshotRow($hostileSite317, $fleetNow)['audit_total']],
    [['update_failed' => null, 'audit' => 1_000_000], ['update_failed', 'audit'], [['kind' => 'update_failed', 'detail' => '']], 1_000_000]);
$shown317 = Kaleta\Fleet\Console::storedBeat(['heartbeat' => '{"update_problem":["x"],"update_available":["9"],"php":{"x":1},"requests":{"new":1e300,"items":[{"id":[1],"status":"new"},{"id":4,"title":["t"],"status":"new"}]},"audit":{"x":[1],"link":2},"problems":"x","last_backup":1e300}']);
check('3.17 N317-1: the site\'s page and get_site show a stored heartbeat cleaned again – the shapes of a new one, the keys the site did not send left out', [
    $shown317, Kaleta\Fleet\Console::storedBeat(['heartbeat' => 'not json']), Kaleta\Fleet\Console::storedBeat(['heartbeat' => null])],
    [['audit' => ['link' => 2], 'requests' => ['new' => 0, 'in_progress' => 0, 'done_to_review' => 0, 'oldest_waiting_hours' => null,
        'items' => [['id' => 4, 'title' => '', 'status' => 'new', 'created_at' => null, 'updated_at' => null]]]], [], []]);
$jobs317 = (string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/History.php') . (string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/AlertRules.php') . (string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/Report.php');
check('3.17 N317-1: the history, alert and report jobs isolate each site (customer) – a failure is logged and the others go on', substr_count($jobs317, 'catch (\Throwable $e)'), 3);
// N317-2: what a site sends reaches the console's e-mails on one line
$evilName317 = "Acme\r\nACTION REQUIRED: sign in at https://evil.example\u{2028}now\u{202E}";
$evilMail317 = AlertRules::message($reportSettings(['site_name' => 'Agency console']), [['site' => 9, 'kind' => 'update_failed', 'detail' => "failed\nSIGN IN at https://evil.example"]],
    [9 => ['name' => $evilName317, 'url' => 'https://acme.example']], 'https://console.example');
$evilDigest317 = Digest::compose(['from' => '2026-10-12', 'to' => '2026-10-18', 'customers' => [['id' => 0, 'name' => '', 'sites' => [['id' => 9, 'name' => $evilName317, 'url' => 'https://acme.example',
    'reasons' => [], 'enquiries' => null, 'requests' => null, 'request_titles' => ["Title\nSIGN IN at https://evil.example"], 'review' => null, 'bookings' => null,
    'uptime' => ['percent' => null, 'checks' => 0, 'failed' => 0, 'response_ms' => null], 'incidents' => [], 'versions' => []]]]]], $reportSettings(['site_name' => 'Agency console']), 'https://console.example', true);
$ownLines317 = fn (string $text): array => array_values(preg_grep('/^(ACTION|SIGN|now)/', explode("\n", $text)) ?: []);
check('3.17 N317-2: a site\'s name and update message are one line – at arrival and again in the alert and the digest e-mail, so a site cannot write lines of its own', [
    Kaleta\Fleet\Console::clean(['name' => "Acme\n\nACTION\u{2028}REQUIRED\t \u{202E}x\u{0085}y"])['name'], Kaleta\Fleet\Console::clean(['update_problem' => "Version 3.17.1\r\nSign in at https://evil.example"])['update_problem'],
    Kaleta\Fleet\Console::clean(['update_problem' => " \n "])['update_problem'], mb_strlen(Kaleta\Fleet\Console::clean(['update_problem' => str_repeat('x', 500)])['update_problem']),
    Kaleta\Fleet\Console::oneLine("Name\u{00A0}with\x00null", 150), Kaleta\Fleet\Console::oneLine(['x'], 150), Kaleta\Fleet\Console::oneLine("\xC3\x28bad", 150),
    $ownLines317($evilMail317[1]), str_contains($evilMail317[1], "Acme ACTION REQUIRED: sign in at https://evil.example now – https://acme.example\n  – Update failed: failed SIGN IN at https://evil.example\n"),
    $ownLines317($evilDigest317[1]), str_contains($evilDigest317[1], "Acme ACTION REQUIRED: sign in at https://evil.example now – https://acme.example\n"), str_contains($evilDigest317[1], "    · Title SIGN IN at https://evil.example\n")],
    ['Acme ACTION REQUIRED x y', 'Version 3.17.1 Sign in at https://evil.example', null, 200, 'Name with null', '', '?(bad', [], true, [], true, true]);
// N317-5: a site's address has no user information, the inbox and the digest link only such an address
check('3.17 N317-5: the console keeps and links a site address without user information ("https://acme.example@evil.example" opens evil.example)', [
    Kaleta\Fleet\Console::url('https://acme.example@evil.example'), Kaleta\Fleet\Console::url('https://user:pw@acme.example/'), Kaleta\Fleet\Console::url('https://acme.example/path'),
    Kaleta\Fleet\Console::url('http://127.0.0.1:8201'), Kaleta\Fleet\Console::url('https://acme.example/"onmouseover=x'), Kaleta\Fleet\Console::url('javascript:alert(1)'), Kaleta\Fleet\Console::url('https://acme.example\\@evil.example'),
    Kaleta\Fleet\Console::url('https://' . str_repeat('a', 260) . '.example'), Kaleta\Fleet\Console::clean(['url' => 'https://acme.example@evil.example'])['url'],
    array_column(Kaleta\Fleet\Inbox::forSite(['id' => 3, 'name' => 'Acme', 'url' => 'https://acme.example@evil.example', 'up' => 0, 'up_changed' => null, 'last_seen' => date('Y-m-d H:i:s', $fleetNow)], [], $fleetNow), 'link'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/Console.php'), "'fleet.site_url_changed'"), isset(Kaleta\Core\Events::TYPES['fleet.site_url_changed'])],
    [null, null, 'https://acme.example/path', 'http://127.0.0.1:8201', null, null, null, null, null, [''], true, true]);
// N317-6: a fleet report only of a month that has ended; N317-7: a site that flips its version lists at most 20 changes
$flap317 = array_map(fn (int $i): array => ['date' => sprintf('2026-10-01 %02d:%02d:00', intdiv($i, 60), $i % 60), 'version' => $i % 2 === 0 ? '3.17.0' : '3.17.1'], range(0, 99));
check('3.17 N317-6/-7: a fleet report only for a month that has ended (never the current or a future one); the versions of a period – repeats in a row once, the last 20', [
    array_map(fn (string $m): bool => Report::monthEnded($m, new DateTimeImmutable('2026-10-20 12:00')), ['2026-09', '2026-10', '2026-11', '2099-12', '2025-01', '2026-13', '2026-9', '']),
    count(History::latestVersions($flap317)), History::latestVersions($flap317)[19]['date'], History::VERSIONS_MAX,
    History::latestVersions([['date' => 'a', 'version' => '3.17.0'], ['date' => 'b', 'version' => '3.17.0'], ['date' => 'c', 'version' => '3.17.1'], ['date' => 'd', 'version' => '3.17.0']])],
    [[true, false, false, false, true, false, false, false], 20, '2026-10-01 01:39:00', 20, [['date' => 'a', 'version' => '3.17.0'], ['date' => 'c', 'version' => '3.17.1'], ['date' => 'd', 'version' => '3.17.0']]]);
// N317-4: the recipients form says the e-mails cover every site; the new texts are in every admin language
$texts317 = ['The alert and digest e-mails cover every site in the console, of every customer – add only your own team\'s addresses.', 'The site\'s address changed from %s to %s.',
    'The console can make a report only for a month that has ended and that it keeps the history of (%s).'];
$missing317 = [];
foreach (['cs', 'de', 'es'] as $code317) {
    $dictionary317 = require KALETA_SYSTEM . '/jazyky/admin-' . $code317 . '.php';
    $missing317 = [...$missing317, ...array_map(static fn (string $k): string => $code317 . ': ' . $k, array_diff($texts317, array_keys($dictionary317)))];
}
check('3.17 N317-4: the recipients of the alerts and the digest are told the e-mails cover every site; the new texts are translated', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/fleet/alerts.php'), "t('The alert and digest e-mails cover every site in the console"), $missing317,
    array_key_exists($texts317[0], require KALETA_SYSTEM . '/jazyky/admin-de-du.php')], [true, [], true]);
