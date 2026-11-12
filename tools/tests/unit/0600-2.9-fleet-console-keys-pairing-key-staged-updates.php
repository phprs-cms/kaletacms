<?php

declare(strict_types=1);

use Kaleta\Core\Firewall;

/* ---------- 2.9: fleet console – keys, pairing key, staged updates, attention ---------- */
$fleetPair = sodium_crypto_sign_keypair();
$fleetPub = base64_encode(sodium_crypto_sign_publickey($fleetPair));
$fleetSig = base64_encode(sodium_crypto_sign_detached('{"a":1}', sodium_crypto_sign_secretkey($fleetPair)));
check('2.9: Fleet\Keys – a signature fits only its message and key', [Kaleta\Fleet\Keys::verify('{"a":1}', $fleetSig, $fleetPub), Kaleta\Fleet\Keys::verify('{"a":2}', $fleetSig, $fleetPub),
    Kaleta\Fleet\Keys::verify('{"a":1}', $fleetSig, base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))), Kaleta\Fleet\Keys::verify('{"a":1}', 'junk', $fleetPub),
    Kaleta\Fleet\Keys::isPublicKey($fleetPub), Kaleta\Fleet\Keys::isPublicKey('abc'), strlen(Kaleta\Fleet\Keys::fingerprint($fleetPub))], [true, false, false, false, true, false, 8]);
check('2.9 / 3.3.2: Fleet\Http – https only; plain http (also to local addresses) just in the automated tests (KALETA_FLEET_LOCAL)', array_map(Kaleta\Fleet\Http::allowedUrl(...),
    ['https://console.example.com', 'http://console.example.com', 'http://127.0.0.1:8312', 'http://web.test', 'ftp://example.com', 'https://user:pw@example.com', 'javascript:alert(1)']),
    [true, false, false, false, false, false, false]);
check('3.3.2: Fleet\Http pins public addresses only – loopback, private, link-local and NAT64 are refused; the uptime check of a refused address is 0 without a request', [
    array_map(fn (string $url): ?array => Kaleta\Fleet\Http::pin($url), ['https://127.0.0.1:8443/', 'https://10.0.0.5/admin', 'https://[::1]/', 'https://169.254.169.254/', 'https://[64:ff9b::a00:1]/', 'https://93.184.216.34/x']),
    Kaleta\Fleet\Http::statuses(['http://10.0.0.5:8080/admin', 'gopher://example.com/']), Kaleta\Fleet\Http::allowedUrl('http://example.com/', true)],
    [[null, null, null, null, null, ['93.184.216.34', 443, '93.184.216.34', 'https://93.184.216.34/x']], [0, 0], true]);
$fleetKey = Kaleta\Fleet\Link::makeKey('https://console.example.com/', str_repeat('ab', 16), $fleetPub, 'Agency console');
check('2.9: Fleet\Link – the pairing key carries the console address, the one-time code and the console key', [
    Kaleta\Fleet\Link::parseKey(" \n" . chunk_split($fleetKey, 40, "\n")), Kaleta\Fleet\Link::parseKey('kaleta-console:junk'), Kaleta\Fleet\Link::parseKey(str_repeat('ab', 16)),
    Kaleta\Fleet\Link::parseKey(Kaleta\Fleet\Link::makeKey('http://console.example.com', str_repeat('ab', 16), $fleetPub, 'x'))],
    [['url' => 'https://console.example.com', 'code' => str_repeat('ab', 16), 'key' => $fleetPub, 'name' => 'Agency console'], null, null, null]);
$fleetNow = 1_800_000_000;
$fleetSite = fn (int $id, string $version, string $ring = 'normal', array $more = []): array => $more + ['id' => $id, 'version' => $version, 'ring' => $ring, 'manage_updates' => true,
    'update_allowed' => '', 'status' => 'ok', 'up' => true, 'version_since' => $fleetNow - 50 * 3600, 'update_problem' => false];
$allowed = fn (array $site, array $sites, bool $security = false, int $firstSeen = 0): string => Kaleta\Fleet\Console::allowedVersion($site, $sites, '2.9.0', $security, $firstSeen, $fleetNow);
$canaryNew = $fleetSite(1, '2.9.0', 'canary');
check('2.9: staged updates – canaries first, the rest after 48 hours without problems, security releases at once', [
    $allowed($fleetSite(2, '2.8.0', 'normal', ['manage_updates' => false]), [$canaryNew]),
    $allowed($fleetSite(2, '2.9.0'), [$canaryNew]),
    $allowed($fleetSite(1, '2.8.0', 'canary'), []),
    $allowed($fleetSite(2, '2.8.0'), [$canaryNew]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['version_since' => $fleetNow - 10 * 3600])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['status' => 'error'])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['up' => false])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['update_problem' => true])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.8.0', 'canary')]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.8.0', 'canary')], true),
    $allowed($fleetSite(2, '2.8.0', 'normal', ['update_allowed' => '2.9.0']), [$fleetSite(1, '2.8.0', 'canary')]),
    $allowed($fleetSite(2, '2.8.0'), [], false, $fleetNow - 10 * 3600),
    $allowed($fleetSite(2, '2.8.0'), [], false, $fleetNow - 49 * 3600),
], ['', '', '2.9.0', '2.9.0', '', '', '', '', '', '2.9.0', '2.9.0', '', '2.9.0']);
$fleetRow = fn (array $more, array $beat = []): array => $more + ['up' => 1, 'last_seen' => date('Y-m-d H:i:s', $fleetNow - 600), 'status' => 'ok', 'heartbeat' => (string) json_encode($beat + ['last_backup' => $fleetNow - 3600, 'cron_last_run' => $fleetNow - 300])];
check('2.9: attention – what is wrong with a site, weighted', [
    Kaleta\Fleet\Console::attention($fleetRow([]), $fleetNow),
    Kaleta\Fleet\Console::attention($fleetRow(['up' => 0, 'status' => 'error']), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow(['last_seen' => date('Y-m-d H:i:s', $fleetNow - 30 * 3600)]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow(['last_seen' => null, 'heartbeat' => null]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], ['last_backup' => $fleetNow - 9 * 86400, 'enquiries_unanswered' => 2, 'jobs_failing' => ['mail'], 'update_problem' => 'Version 2.9.0 did not work']), $fleetNow)['reasons'],
], [['score' => 0, 'reasons' => []], ['down', 'errors'], ['not_reporting'], ['no_heartbeat'], ['update_failed', 'jobs_failing', 'no_backup', 'enquiries']]);
$fleetClean = Kaleta\Fleet\Console::clean(['version' => '2.9.0', 'name' => str_repeat('x', 400), 'secret' => 'drop me', 'problems' => [['check' => 'Mail', 'status' => 'warning', 'detail' => ['deep' => ['deeper' => ['deepest' => 1]]]]], 'visits_7_days' => 12.7]);
check('2.9: a heartbeat keeps only the known keys with sane values', [isset($fleetClean['secret']), mb_strlen($fleetClean['name']), $fleetClean['version'], $fleetClean['visits_7_days'], $fleetClean['problems'][0]['check']],
    [false, 150, '2.9.0', 12, 'Mail']); // 3.17: the name fits ka_fleet_sites.name (150)
// 3.16: what waits for people – request titles cleaned and masked, the new parts bounded, new reasons for attention
check('3.16: Fleet\Heartbeat::cleanTitle – plain text, one line, e-mail addresses masked, at most 120 characters', [
    Kaleta\Fleet\Heartbeat::cleanTitle("<b>Call</b> back jan.novak@firma.cz\n about <i>prices</i>"),
    Kaleta\Fleet\Heartbeat::cleanTitle('Forward to "info@example.com", or (petra+web@sub.example.co.uk) & anna@local'),
    mb_strlen(Kaleta\Fleet\Heartbeat::cleanTitle(str_repeat('Long title ', 30))), str_contains(Kaleta\Fleet\Heartbeat::cleanTitle(str_repeat('a', 130) . 'x@y.cz'), '@')],
    ['Call back [e-mail] about prices', 'Forward to "[e-mail]", or ([e-mail]) & [e-mail]', 120, false]);
$fleetNew = Kaleta\Fleet\Console::clean([
    'requests' => ['new' => 2, 'in_progress' => -5, 'done_to_review' => 1.9, 'oldest_waiting_hours' => 50, 'secret' => 'x', 'items' => array_merge(
        [['id' => 7, 'title' => 'Write to boss@firma.cz', 'status' => 'new', 'created_at' => '2026-10-19T08:00:00+02:00', 'updated_at' => 'yesterday', 'text' => 'the whole text']],
        [['id' => 8, 'title' => 'Bad status', 'status' => 'hacked']], array_fill(0, 30, ['id' => 9, 'title' => 'More', 'status' => 'done']))],
    'pending_review' => ['page_drafts' => 3, 'invented_kind' => 5, 'news_drafts' => 'many'],
    'security_7_days' => ['firewall' => ['probe' => 4, 'BAD KEY' => 1], 'failed_sign_ins' => 60, 'locked_accounts' => 1, 'events' => ['security.token_reuse' => 1, 'login.ip' => 3], 'ips' => ['1.2.3.4']],
    'domain_watch' => ['checked' => 1, 'certificate' => ['expires' => '2027-01-01', 'days' => 70, 'error' => false, 'host' => 'example.cz'], 'domain' => null, 'mail' => ['spf' => true, 'dmarc' => false, 'record' => 'v=spf1']],
    'updates' => ['behind_days' => 3, 'major_available' => '4.0.0; DROP'], 'report_month' => '2026-09-01', 'enquiries_new_not_spam' => 3, 'bookings_pending' => '2',
]);
check('3.16: a heartbeat keeps the new parts only in their shape and bounds', [
    [$fleetNew['requests']['new'], $fleetNew['requests']['in_progress'], $fleetNew['requests']['done_to_review'], isset($fleetNew['requests']['secret']), count($fleetNew['requests']['items'])],
    $fleetNew['requests']['items'][0], $fleetNew['pending_review'], $fleetNew['security_7_days'],
    [$fleetNew['domain_watch']['certificate'], $fleetNew['domain_watch']['domain'], $fleetNew['domain_watch']['mail']],
    [$fleetNew['updates'], $fleetNew['report_month'], $fleetNew['enquiries_new_not_spam'], $fleetNew['bookings_pending'], $fleetNew['agent_runs']],
], [[2, 0, 1, false, 19], ['id' => 7, 'title' => 'Write to [e-mail]', 'status' => 'new', 'created_at' => '2026-10-19T08:00:00+02:00', 'updated_at' => null], ['page_drafts' => 3],
    ['firewall' => ['probe' => 4], 'failed_sign_ins' => 60, 'locked_accounts' => 1, 'events' => ['security.token_reuse' => 1]],
    [['expires' => '2027-01-01', 'days' => 70, 'error' => false], null, ['error' => null, 'spf' => true, 'dmarc' => false, 'dkim' => null, 'spf_covers_smtp' => null]],
    [['behind_days' => 3, 'major_available' => null], null, 3, null, null]]);
check('3.16: Fleet\Heartbeat::domainWatch – dates and found/not found only, never the records, hosts or error texts', [
    Kaleta\Fleet\Heartbeat::domainWatch(['checked' => 5, 'site_host' => 'www.example.cz', 'report_email' => 'info@example.cz', 'tls' => ['host' => 'www.example.cz', 'expires' => 1_800_000_000, 'days' => 9, 'error' => null],
        'domain' => ['name' => 'example.cz', 'expires' => null, 'days' => null, 'error' => 'HTTP 500 from rdap.example'], 'mail' => ['domain' => 'example.cz', 'spf' => 'v=spf1 a mx ~all', 'dmarc' => null, 'dkim' => 'google', 'spf_covers_smtp' => null, 'error' => null]]),
    Kaleta\Fleet\Heartbeat::domainWatch(null), Kaleta\Fleet\Heartbeat::domainWatch(['checked' => 5, 'demo' => true])],
    [['checked' => 5, 'local' => false, 'certificate' => ['expires' => date('Y-m-d', 1_800_000_000), 'days' => 9, 'error' => false], 'domain' => ['expires' => null, 'days' => null, 'error' => true],
        'mail' => ['error' => false, 'spf' => true, 'dmarc' => false, 'dkim' => true, 'spf_covers_smtp' => null]], null, null]);
$fleetDay = fn (int $days): string => date('Y-m-d', $fleetNow + $days * 86400 + 3600);
check('3.16: attention – requests waiting over 48 h, bookings to confirm, certificate < 14 days, domain < 30 days, many failed sign-ins; enquiries without spam', [
    Kaleta\Fleet\Console::attention($fleetRow([], ['requests' => ['oldest_waiting_hours' => 49], 'bookings_pending' => 1, 'domain_watch' => ['certificate' => ['expires' => $fleetDay(10)], 'domain' => ['expires' => $fleetDay(20)]],
        'security_7_days' => ['failed_sign_ins' => 50]]), $fleetNow),
    Kaleta\Fleet\Console::attention($fleetRow([], ['requests' => ['oldest_waiting_hours' => 48], 'bookings_pending' => 0, 'domain_watch' => ['certificate' => ['expires' => $fleetDay(20), 'days' => 3], 'domain' => ['days' => 40]],
        'security_7_days' => ['failed_sign_ins' => 49, 'events' => ['security.account_suspended' => 2]], 'enquiries_unanswered' => 4, 'enquiries_new_not_spam' => 0]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], ['security_7_days' => ['events' => ['security.token_reuse' => 1]], 'enquiries_unanswered' => 4]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::enquiriesWaiting(['enquiries_unanswered' => 4]), Kaleta\Fleet\Console::enquiriesWaiting(['enquiries_unanswered' => 4, 'enquiries_new_not_spam' => 1]), Kaleta\Fleet\Console::enquiriesWaiting([]),
], [['score' => 95, 'reasons' => ['requests_waiting', 'bookings_to_confirm', 'certificate_expiring', 'domain_expiring', 'sign_in_attacks']], [], ['enquiries', 'sign_in_attacks'], 4, 1, null]);
// 3.16 audit fixes (N316-3, -5, -8): nothing of the whistleblowing channel, a lying site cannot break its own console page
check('3.16 N316-3: whistleblowing events and the job never leave the site, and the console drops them from a 3.15 site', [
    Kaleta\Fleet\Heartbeat::withoutWhistleblowing(['whistleblowing.due' => 1, 'WHISTLEBLOWING.received' => 2, 'backup.failed' => 1]),
    array_intersect_key(Kaleta\Fleet\Console::clean(['problems_7_days' => ['whistleblowing.due' => 1, 'mail.failed' => 2], 'jobs_failing' => ['whistleblowing', 'backup', ['x']],
        'problems' => [['check' => 'Problems in the last 7 days', 'detail' => 'whistleblowing.due ×1, mail.failed ×2'], ['check' => 'Background jobs', 'detail' => 'Whistleblowing: due deadlines failed 3× in a row: x']]]),
        ['problems_7_days' => 1, 'jobs_failing' => 1, 'problems' => 1])],
    [['backup.failed' => 1], ['problems' => [['group' => '', 'check' => 'Problems in the last 7 days', 'status' => 'warning', 'detail' => 'mail.failed ×2']], 'jobs_failing' => ['backup'], 'problems_7_days' => ['mail.failed' => 2]]]);
$hostile316 = Kaleta\Fleet\Console::clean(['visits_7_days' => 1e30, 'enquiries_7_days' => -1e300,
    'requests' => ['new' => 1e30, 'in_progress' => 3.7, 'done_to_review' => 9.2e18, 'items' => [['id' => 1e30, 'title' => 'x', 'status' => 'new'], ['id' => 4, 'title' => 'ok', 'status' => 'new', 'created_at' => '2026-02-30T10:00:00+01:00', 'updated_at' => '2026-10-19T25:00:00Z']]],
    'domain_watch' => ['checked' => 1_760_000_000, 'certificate' => ['expires' => '2026-13-45', 'days' => 5], 'domain' => ['expires' => '9999-99-99'], 'mail' => null],
    'agent_runs' => ['runs_7_days' => ['ok' => 2.0, 'failed' => 1e20], 'next_due' => '2026-10-21T07:00:00+02:00', 'next_task' => 'triage', 'sessions_7_days' => 1]]);
check('3.16 N316-5: a hostile heartbeat – huge floats refused value by value, impossible dates dropped, checked kept as a Unix time', [
    $hostile316['visits_7_days'], $hostile316['enquiries_7_days'], [$hostile316['requests']['new'], $hostile316['requests']['in_progress'], $hostile316['requests']['done_to_review']],
    array_map(static fn (array $i): array => [$i['id'], $i['created_at'], $i['updated_at']], $hostile316['requests']['items']),
    [$hostile316['domain_watch']['checked'], $hostile316['domain_watch']['certificate'], $hostile316['domain_watch']['domain']['expires']],
    $hostile316['agent_runs']['runs_7_days'], $hostile316['agent_runs']['next_due']],
    [null, null, [0, 3, 0], [[4, null, null]], [1_760_000_000, ['expires' => null, 'days' => 5, 'error' => false], null], ['ok' => 2], '2026-10-21T07:00:00+02:00']);
check('3.16 N316-5: Console::date and daysLeft accept only real dates – a stored "2026-13-45" never reaches DateTimeImmutable', [
    Kaleta\Fleet\Console::date('2026-13-45'), Kaleta\Fleet\Console::date('2026-02-29'), Kaleta\Fleet\Console::date('2028-02-29')?->format('Y-m-d'), Kaleta\Fleet\Console::date(['x']),
    Kaleta\Fleet\Console::daysLeft(['expires' => '9999-99-99', 'days' => 7], $fleetNow), Kaleta\Fleet\Console::daysLeft(['expires' => '2026-13-45'], $fleetNow),
    Kaleta\Fleet\Console::clean(['domain_watch' => ['checked' => 99_999_999_999]])['domain_watch']['checked']],
    [null, null, '2028-02-29', null, 7, null, null]);
$labels316 = [...array_values(Kaleta\Core\ReportPage::FIREWALL), ...array_values(Kaleta\Core\ReportPage::SECURITY), ...array_values(Kaleta\Core\AgentSchedules::STATUSES),
    ...array_column(Kaleta\Core\AgentSchedules::TASKS, 0), ...array_column(Kaleta\Core\Scheduler::JOBS, 2), 'Newsletter', 'firewall: %s'];
$missing316 = [];
foreach (['cs', 'de', 'es'] as $code316) {
    $dictionary316 = require KALETA_SYSTEM . '/jazyky/admin-' . $code316 . '.php';
    $missing316 = [...$missing316, ...array_map(static fn (string $k): string => $code316 . ': ' . $k, array_diff($labels316, array_keys($dictionary316), ['Menu']))];
}
check('3.16 N316-8: the machine names the console detail shows (firewall reasons, security events, run results and tasks, jobs) have their labels in every admin language', $missing316, []);
$detail316 = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/fleet/detail.php');
check('[source-pin] 3.16 N316-5/-8: the console detail reads dates through Console::date and names machine keys through the label maps', [str_contains($detail316, 'new DateTimeImmutable($part'), str_contains($detail316, 'Console::date('),
    str_contains($detail316, 'ReportPage::FIREWALL'), str_contains($detail316, 'AgentSchedules::STATUSES'), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/Heartbeat.php'), 'TODO')], [false, true, true, true, false]);
// 3.17: the inbox of the console (Fleet\Inbox) – built from stored heartbeats, a 3.15 heartbeat without the 3.16 parts too
$inboxIso = fn (int $hoursAgo): string => date(DATE_ATOM, $fleetNow - $hoursAgo * 3600);
$inboxSites = [
    ['id' => 1, 'name' => 'Alpha', 'url' => 'https://alpha.example', 'customer_id' => 5, 'up' => 1, 'up_changed' => null, 'status' => 'warning', 'last_seen' => date('Y-m-d H:i:s', $fleetNow - 600),
        'heartbeat' => (string) json_encode(['last_backup' => $fleetNow - 3600, 'update_problem' => 'Version 3.17.0 did not install',
        'requests' => ['new' => 1, 'in_progress' => 1, 'done_to_review' => 1, 'oldest_waiting_hours' => 72, 'items' => [
            ['id' => 7, 'title' => 'Price list – write to jana@example.cz', 'status' => 'new', 'created_at' => $inboxIso(72), 'updated_at' => $inboxIso(72)],
            ['id' => 8, 'title' => 'New team photo', 'status' => 'in_progress', 'created_at' => $inboxIso(2), 'updated_at' => $inboxIso(1)],
            ['id' => 9, 'title' => 'Opening hours', 'status' => 'done', 'created_at' => $inboxIso(30), 'updated_at' => $inboxIso(5)]]],
        'pending_review' => ['requests_done' => 1, 'page_drafts' => 2, 'staged_media' => 3, 'look_draft' => 0], 'enquiries_new_not_spam' => 3, 'enquiries_unanswered' => 9, 'bookings_pending' => 1,
        'security_7_days' => ['failed_sign_ins' => 80, 'events' => []], 'domain_watch' => ['local' => false, 'certificate' => ['expires' => $fleetDay(5)], 'domain' => ['expires' => $fleetDay(25)]],
        'audit' => ['links' => 2, 'titles' => 1]])],
    // a site on 3.15: no requests, review, bookings or security – only the enquiries it always sent; silent for 30 hours
    ['id' => 2, 'name' => 'Beta', 'url' => 'https://beta.example/', 'customer_id' => null, 'up' => 1, 'up_changed' => null, 'status' => 'ok', 'last_seen' => date('Y-m-d H:i:s', $fleetNow - 30 * 3600),
        'heartbeat' => (string) json_encode(['last_backup' => $fleetNow - 3600, 'enquiries_unanswered' => 4])],
    ['id' => 3, 'name' => '', 'url' => 'https://gamma.example', 'customer_id' => 5, 'up' => 0, 'up_changed' => date('Y-m-d H:i:s', $fleetNow - 1800), 'status' => '', 'last_seen' => null, 'heartbeat' => null],
];
$inbox = Kaleta\Fleet\Inbox::build($inboxSites, [5 => 'Novák s.r.o.'], $fleetNow);
$inboxKey = static fn (array $r): string => $r['site_id'] . ':' . $r['what'] . ($r['title'] !== null ? ':' . $r['title'] : '') . ($r['count'] !== null ? '=' . $r['count'] : '') . ':' . $r['urgency'] . ($r['stale'] ? ':stale' : '');
check('3.17: Fleet\Inbox – one list from the stored heartbeats, the most urgent and the oldest first, a 3.15 heartbeat and a site without one too', array_map($inboxKey, $inbox), [
    '1:request:Price list – write to [e-mail]:urgent', '2:site_silent:urgent', '3:site_down:urgent', '1:update_failed:urgent', '1:sign_in_attacks=80:urgent', '1:certificate_expiring:urgent',
    '1:request:New team photo:normal', '1:domain_expiring:normal', '1:enquiries=3:normal', '2:enquiries=4:normal:stale', '1:bookings=1:normal', '1:staged_media=3:normal',
    '1:request:Opening hours:low', '1:page_drafts=2:low', '1:audit=3:low']);
check('3.17: Fleet\Inbox – plain links to the right screen of each site\'s administration, the customer, nothing with an @', [
    $inbox[0]['link'], $inbox[1]['link'], $inbox[2]['link'], $inbox[2]['site'], $inbox[0]['customer'], $inbox[9]['link'], $inbox[11]['link'], $inbox[13]['link'],
    str_contains((string) json_encode($inbox, JSON_UNESCAPED_UNICODE), '@'), $inbox[5]['days'], Kaleta\Fleet\Inbox::label($inbox[11])],
    ['https://alpha.example/admin.php?module=requests&action=detail&id=7', 'https://beta.example/', 'https://gamma.example/', 'https://gamma.example', 'Novák s.r.o.',
        'https://beta.example/admin.php?module=enquiries', 'https://alpha.example/admin.php?module=media&staged=1', 'https://alpha.example/admin.php?module=pages', false, 4, 'Files from Claude waiting for approval']);
$inboxAlpha = Kaleta\Fleet\Inbox::filter($inbox, ['customer' => 5, 'urgent' => true]);
check('3.17: Fleet\Inbox – filters by kind, site, customer and urgency; counts per kind; what waits on each site', [
    count(Kaleta\Fleet\Inbox::filter($inbox, ['kind' => 'enquiries'])), count(Kaleta\Fleet\Inbox::filter($inbox, ['site' => 2])), array_unique(array_column($inboxAlpha, 'urgency')), count($inboxAlpha),
    array_filter(Kaleta\Fleet\Inbox::counts($inbox)), Kaleta\Fleet\Inbox::waitingBySite($inbox), Kaleta\Fleet\Inbox::build([], [], $fleetNow)],
    [2, 2, ['urgent'], 5, ['site' => 2, 'updates' => 1, 'security' => 1, 'expiry' => 2, 'requests' => 3, 'enquiries' => 2, 'bookings' => 1, 'review' => 2, 'audit' => 1],
        [1 => 17, 2 => 5, 3 => 1], []]);
check('3.17: Fleet\Customers – a customer name is one trimmed line, a note plain text', [Kaleta\Fleet\Customers::clean("  Novák\n <b>s.r.o.</b> "), mb_strlen(Kaleta\Fleet\Customers::clean(str_repeat('x', 300))),
    Kaleta\Fleet\Customers::cleanNote("<i>Calls</i> on Mondays\r\nonly")], ['Novák s.r.o.', 150, "Calls on Mondays\nonly"]);
$inboxView = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/fleet/inbox.php');
check('[source-pin] 3.17: the inbox links out plainly – a new tab without an opener, no token or sign-in in the link', [str_contains($inboxView, 'target="_blank" rel="noopener"'), (bool) preg_match('/token|password|login/i', (string) json_encode(array_column($inbox, 'link')))], [true, false]);
