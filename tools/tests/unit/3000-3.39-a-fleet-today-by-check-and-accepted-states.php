<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Fleet;
use Kaleta\Fleet\Acceptances;
use Kaleta\Fleet\AlertRules;
use Kaleta\Fleet\Checks;
use Kaleta\Fleet\Digest;
use Kaleta\Fleet\Health;

/* ---------- 3.39 (A): the console's Today grouped by check, accepting a state on the console, six sections instead of twelve tabs ---------- */
$a339Now = 1_800_000_000;
$a339Today = date('Y-m-d', $a339Now);
$a339Parts = ['offsite' => ['status' => 'off', 'at' => null, 'recovery_key' => 'none'], 'mail_setup' => ['mode' => 'smtp', 'tested' => true, 'tested_at' => date('Y-m-d', $a339Now)],
    'twofa' => ['required' => 'admins', 'admins_without' => 0], 'cookie_scan' => ['at' => null, 'error' => false, 'cookies' => 0], 'privacy_page' => 'published',
    'accessibility_statement' => 'published', 'handover' => ['done' => 16, 'total' => 16, 'failing' => []]];
$a339Site = fn (int $id, array $more = [], array $beat = []): array => $more + ['id' => $id, 'name' => 'Site ' . $id, 'url' => 'https://a339-' . $id . '.example', 'customer_id' => null,
    'up' => 1, 'up_status' => 200, 'up_changed' => null, 'last_seen' => date('Y-m-d H:i:s', $a339Now - 600),
    'heartbeat' => (string) json_encode($beat + $a339Parts + ['version' => '3.39.0', 'last_backup' => $a339Now - 3600, 'cron_last_run' => $a339Now - 600, 'auto_updates' => true])];
$a339Sites = [$a339Site(1), $a339Site(2), $a339Site(3, ['up' => 0, 'up_status' => 503, 'up_changed' => date('Y-m-d H:i:s', $a339Now - 7200)])];
$a339Items = Kaleta\Core\Language::runWith('en', fn (): array => Checks::today($a339Sites, $a339Now), 'admin-');
$a339Groups = Kaleta\Core\Language::runWith('en', fn (): array => Health::groups($a339Items), 'admin-');
$a339ByCheck = array_column($a339Groups, null, 'check');
check('3.39 Health::groups – one group per check, the red groups first (the outage on top), each counting its sites and red and amber items, the items kept in rank order', [
    array_column($a339Groups, 'check')[0], $a339ByCheck['uptime']['sites'] ?? null, $a339ByCheck['offsite_backup']['sites'] ?? null, $a339ByCheck['offsite_backup']['state'] ?? null,
    $a339ByCheck['cookie_scan']['state'] ?? null, $a339ByCheck['cookie_scan']['sites'] ?? null,
    array_search('cookie_scan', array_column($a339Groups, 'check'), true) > array_search('offsite_backup', array_column($a339Groups, 'check'), true),
    count($a339Items) === array_sum(array_map(fn (array $g): int => count($g['items']), $a339Groups)), $a339ByCheck['offsite_backup']['label'] ?? null],
    ['uptime', 1, 3, 'bad', 'warn', 3, true, true, 'Off-site backup']);
check('3.39 Health::groups – an overdue console task groups as "task" with its own label, a held rollout as one whole-fleet group; a customer\'s task counts as a place of its own', (function () use ($a339Items): array {
    $tasks = [['kind' => 'task', 'site_id' => 1, 'site' => 'Site 1', 'customer_id' => null, 'check' => 'task', 'label' => 'Renew', 'state' => 'warn', 'weight' => 35, 'since' => 1],
        ['kind' => 'task', 'site_id' => 0, 'site' => 'Acme', 'customer_id' => 4, 'check' => 'task', 'label' => 'Prices', 'state' => 'warn', 'weight' => 35, 'since' => 2]];
    $groups = array_column(Kaleta\Core\Language::runWith('en', fn (): array => Health::groups([...$a339Items, ...$tasks]), 'admin-'), null, 'check');

    return [$groups['task']['label'] ?? null, $groups['task']['sites'] ?? null, count($groups['task']['items'] ?? [])];
})(), ['Overdue tasks', 2, 2]);

// accepting a state: the same state until the last day holds; a worse state, another state or a passed day does not
$a339Acc = ['1:offsite_backup' => ['site_id' => 1, 'check' => 'offsite_backup', 'state' => 'bad', 'note' => 'The customer declined', 'until' => $a339Today, 'by' => 1, 'at' => '2027-01-01 10:00:00'],
    '2:cookie_scan' => ['site_id' => 2, 'check' => 'cookie_scan', 'state' => 'warn', 'note' => 'n', 'until' => '2000-01-01', 'by' => null, 'at' => '2000-01-01 10:00:00'],
    '3:offsite_backup' => ['site_id' => 3, 'check' => 'offsite_backup', 'state' => 'warn', 'note' => 'n', 'until' => '2100-01-01', 'by' => null, 'at' => '2027-01-01 10:00:00']];
[$a339Open, $a339Accepted] = Acceptances::split($a339Items, $a339Acc, $a339Today);
check('3.39 Acceptances::holds/split – the same state through its last day is accepted (with its acceptance), a passed day or a worse state (warn accepted, now red) is open again; a task never is accepted', [
    Acceptances::holds($a339Acc['1:offsite_backup'], 'bad', $a339Today), Acceptances::holds($a339Acc['1:offsite_backup'], 'bad', date('Y-m-d', $a339Now + 86400)),
    Acceptances::holds($a339Acc['3:offsite_backup'], 'bad', $a339Today), Acceptances::holds(null, 'warn', $a339Today),
    array_map(fn (array $i): string => $i['site_id'] . ':' . $i['check'], $a339Accepted), $a339Accepted[0]['accepted']['note'] ?? null, count($a339Open) + count($a339Accepted) === count($a339Items),
    Acceptances::split([['kind' => 'task', 'site_id' => 1, 'check' => 'offsite_backup', 'state' => 'bad']], $a339Acc, $a339Today)[1]],
    [true, false, false, false, ['1:offsite_backup'], 'The customer declined', true, []]);
check('3.39 Acceptances::active and stale – active only where the check is in the accepted state; stale = the day passed, the site gone, or the state changed (unknown keeps it)', [
    array_keys(Acceptances::active($a339Sites, $a339Acc, $a339Now)),
    Acceptances::stale($a339Sites, $a339Acc + ['9:cron' => ['site_id' => 9, 'check' => 'cron', 'state' => 'bad', 'note' => '', 'until' => '2100-01-01', 'by' => null, 'at' => '']], $a339Now),
    Acceptances::stale([$a339Site(1, [], ['offsite' => ['status' => 'ok', 'at' => date('Y-m-d', $a339Now), 'recovery_key' => 'confirmed']])], ['1:offsite_backup' => $a339Acc['1:offsite_backup']], $a339Now),
    Acceptances::stale([$a339Site(1, ['heartbeat' => ''])], ['1:offsite_backup' => $a339Acc['1:offsite_backup']], $a339Now)],
    [['1:offsite_backup'], ['2:cookie_scan', '3:offsite_backup', '9:cron'], ['1:offsite_backup'], []]);
check('3.39 Acceptances::until and note – empty = 30 days, a real day from today to a year ahead; anything else refused; the note on one line, bounded', [
    Acceptances::until('', '2027-01-31'), Acceptances::until('2027-02-28', '2027-01-31'), Acceptances::until('2027-01-31', '2027-01-31'), Acceptances::until('2027-01-30', '2027-01-31'),
    Acceptances::until('2028-02-01', '2027-01-31'), Acceptances::until('2027-02-30', '2027-01-31'), Acceptances::until('31. 1. 2027', '2027-01-31'), Acceptances::until("2027-02-01\n", '2027-01-31'),
    Acceptances::note("Customer\nsays no"), mb_strlen(Acceptances::note(str_repeat('x', 500))), Acceptances::DEFAULT_DAYS, Acceptances::MAX_DAYS, Acceptances::STATES],
    ['2027-03-02', '2027-02-28', '2027-01-31', null, null, null, null, '2027-02-01', 'Customer says no', Acceptances::NOTE_LENGTH, 30, 365, ['warn', 'bad']]);

// alerts, reminders and the digest follow the acceptances
$a339Active = ['3:uptime' => ['site_id' => 3, 'check' => 'uptime'], '3:certificate' => ['site_id' => 3, 'check' => 'certificate']];
check('3.39 AlertRules::unaccepted – an alert whose check is accepted for the site is not sent; the other site, and kinds without a check (security spike, requests), still go', [
    array_column(AlertRules::unaccepted([['kind' => 'site_down', 'detail' => ''], ['kind' => 'certificate', 'detail' => ''], ['kind' => 'domain', 'detail' => ''], ['kind' => 'security_spike', 'detail' => '']], 3, $a339Active), 'kind'),
    array_column(AlertRules::unaccepted([['kind' => 'site_down', 'detail' => '']], 4, $a339Active), 'kind'),
    array_diff(array_values(AlertRules::CHECK_OF), array_keys(Checks::CHECKS)), array_diff(array_keys(AlertRules::CHECK_OF), array_keys(AlertRules::KINDS))],
    [['domain', 'security_spike'], ['site_down'], [], []]);
check('3.39 Digest::openReasons – a reason whose check is accepted leaves "needs attention"; every mapped reason and check exists', [
    Digest::openReasons(['down', 'no_backup', 'enquiries', 'update_available'], ['uptime', 'updates']),
    array_diff(array_keys(Digest::REASON_CHECKS), array_keys(Kaleta\Fleet\Console::REASONS)), array_diff(array_values(Digest::REASON_CHECKS), array_keys(Checks::CHECKS))],
    [['no_backup', 'enquiries'], [], []]);

// fewer tabs: six sections, every former tab a view with its address, the screens in their section
$a339Views = array_merge(...array_map(fn (array $s): array => array_keys($s[1]), array_values(Fleet::SECTIONS)));
check('3.39 Fleet::SECTIONS – six sections; every one of the twelve former tabs is still a view (its old address works), each action of the module in exactly one section', [
    count(Fleet::SECTIONS), array_diff(['', 'inbox', 'health', 'workload', 'sites', 'customers', 'reports', 'alerts', 'kit', 'inventory', 'calendar', 'rollout'],
        [...$a339Views, ...array_keys(array_filter(Fleet::SECTIONS, fn (array $s): bool => $s[1] === []))]),
    count($a339Views) === count(array_unique($a339Views)),
    array_map(fn (string $a): bool => (new ReflectionClass(Fleet::class))->hasMethod('action' . str_replace('_', '', ucwords($a === '' ? 'list' : $a, '_'))), [...$a339Views, ...array_keys(Fleet::SECTION_OF), 'accept', 'unaccept']),
    [Fleet::sectionOf(''), Fleet::sectionOf('inbox'), Fleet::sectionOf('health'), Fleet::sectionOf('detail'), Fleet::sectionOf('reports'), Fleet::sectionOf('kit'), Fleet::sectionOf('digest_preview'), Fleet::sectionOf('calendar'), Fleet::sectionOf('nonsense')]],
    [6, [], true, array_fill(0, count($a339Views) + count(Fleet::SECTION_OF) + 2, true), ['', '', 'sites', 'sites', 'customers', 'rollout', 'alerts', 'calendar', '']]);
check('3.39 the section and view names and the new texts of Today are translated (cs, de, es); the one sentence that addresses the reader has a du form', (function (): array {
    $keys = [...array_column(Fleet::SECTIONS, 0), ...array_merge(...array_map(fn (array $s): array => array_values($s[1]), array_values(Fleet::SECTIONS))),
        'Overdue tasks', 'Accept', 'Accepted', 'Withdraw', 'Accepted on the console', 'Accepted until %s: %s', 'Views of %s',
        'Accept this state on the console – it leaves Today and sends no alert until the day below or until its state changes. Nothing changes on the site.'];
    $out = [];
    foreach (['admin-cs', 'admin-de', 'admin-es'] as $file) {
        $dictionary = require KALETA_SYSTEM . '/jazyky/' . $file . '.php';
        $out[$file] = array_values(array_filter(array_unique($keys), fn (string $k): bool => !isset($dictionary[$k])));
    }
    $du = require KALETA_SYSTEM . '/jazyky/admin-de-du.php';
    $out['du'] = str_starts_with($du['Write why the state is accepted – the note is shown to everyone who opens the console. Nothing was saved.'] ?? '', 'Schreib,');

    return $out;
})(), ['admin-cs' => [], 'admin-de' => [], 'admin-es' => [], 'du' => true]);
check('3.39 the table of acceptances is in the name map and the time-zone lists (accepted_at an instant, ends_on a civil day)', [
    isset(Kaleta\Core\Names::map()['fleet_acceptances']), Kaleta\Core\TimeZoneConversion::INSTANTS['fleet_acceptances'] ?? null, Kaleta\Core\TimeZoneConversion::CIVIL['fleet_acceptances'] ?? null],
    [true, ['accepted_at'], ['ends_on']]);
