<?php

declare(strict_types=1);

/* ---------- 3.29.1: say why the visits dropped – the switch to browser-confirmed counting marked, never a drop ---------- */
use Kaleta\Front\Stats as Stats3291;
check('3.29.1 Stats::spansSwitch – a span reaches across the switch when the day is after its first day and not after its last; no valid day, no switch', [
    Stats3291::spansSwitch('2026-11-01', '2026-10-19', '2026-11-01'), Stats3291::spansSwitch('2026-11-01', '2026-10-19', '2026-10-31'),
    Stats3291::spansSwitch('2026-11-01', '2026-11-01', '2026-11-14'), Stats3291::spansSwitch('2026-11-01', '2026-10-31', '2026-11-14'),
    Stats3291::spansSwitch(null, '2026-10-01', '2026-11-30'), Stats3291::spansSwitch('', '2026-10-01', '2026-11-30'), Stats3291::spansSwitch('2026-11-01 00:00', '2026-10-01', '2026-11-30')],
    [true, false, false, true, false, false, false]);
// the alert and arrow rule: a rolling comparison ending on a day is across the switch for the 14 days from it, the switch day included
check('3.29.1 Stats::recentSwitch – the 14 days from the switch (the day itself included), not the day before nor the 15th day; across a month end', [
    Stats3291::SWITCH_DAYS, Stats3291::recentSwitch('2026-11-01', '2026-10-31'), Stats3291::recentSwitch('2026-11-01', '2026-11-01'), Stats3291::recentSwitch('2026-11-01', '2026-11-14'),
    Stats3291::recentSwitch('2026-11-01', '2026-11-15'), Stats3291::recentSwitch('2026-10-25', '2026-11-07'), Stats3291::recentSwitch('2026-10-25', '2026-11-08'),
    Stats3291::recentSwitch(null, '2026-11-03'), Stats3291::recentSwitch('2026-11-01', 'not a day')],
    [14, false, true, true, false, true, false, false, false]);
$beat3291 = fn (mixed $since): array => Kaleta\Fleet\Console::clean(['version' => '3.29.1', 'visits_7_days' => 40, 'stats_confirmed_since' => $since]);
check('3.29.1 Console::clean – stats_confirmed_since is a real calendar day between 1970 and 2100, anything else is dropped on its own', [
    $beat3291('2026-10-31')['stats_confirmed_since'], $beat3291('2100-12-31')['stats_confirmed_since'], $beat3291('9999-12-31')['stats_confirmed_since'], $beat3291('1969-12-31')['stats_confirmed_since'],
    $beat3291('2026-02-30')['stats_confirmed_since'], $beat3291('2026-10-31T00:00:00+01:00')['stats_confirmed_since'], $beat3291(['2026-10-31'])['stats_confirmed_since'], $beat3291(20261031)['stats_confirmed_since'],
    $beat3291('<b>2026-10-31</b>')['stats_confirmed_since'], $beat3291('9999-99-99')['visits_7_days'],
    array_key_exists('stats_confirmed_since', Kaleta\Fleet\Console::storedBeat(['heartbeat' => '{"version":"3.29.0","visits_7_days":5}']))],
    ['2026-10-31', '2100-12-31', null, null, null, null, null, null, null, 40, false]);
$now3291 = (int) strtotime('2026-11-05 12:00:00');
check('3.29.1 Console::countingChanged – the mark while the 7-day visits reach across the switch (14 days), none before 3.29.1, after them or for a bad day', [
    Kaleta\Fleet\Console::countingChanged($beat3291('2026-11-01'), $now3291), Kaleta\Fleet\Console::countingChanged($beat3291('2026-10-23'), $now3291),
    Kaleta\Fleet\Console::countingChanged($beat3291('2026-10-22'), $now3291), Kaleta\Fleet\Console::countingChanged($beat3291('2026-11-06'), $now3291),
    Kaleta\Fleet\Console::countingChanged(['stats_confirmed_since' => '2026-13-01'], $now3291), Kaleta\Fleet\Console::countingChanged(['visits_7_days' => 3], $now3291)],
    ['2026-11-01', '2026-10-23', null, null, null, null]);
// mark, don't alarm: a drop across the switch has no down arrow and no alert; a rise and a drop without the switch are shown as they are
[$tile3291, $plain3291, $rise3291, $noSwitch3291, $attention3291] = Kaleta\Core\Language::runWith('en', fn (): array => [
    Kaleta\Core\ReportSummary::tiles(['stats' => ['visits' => 600, 'previous_visits' => 1000, 'confirmed_since' => '2026-10-20']], null, ['totals' => ['all' => 0]])[0],
    Kaleta\Core\ReportSummary::visitsChange(600, 1000, Kaleta\Core\ReportSummary::countingChanged(['confirmed_since' => '2026-10-20'])),
    Kaleta\Core\ReportSummary::visitsChange(1200, 1000, '2026-10-20'), Kaleta\Core\ReportSummary::tiles(['stats' => ['visits' => 600, 'previous_visits' => 1000]], null, ['totals' => ['all' => 0]])[0],
    Kaleta\Fleet\Console::attention(['up' => 1, 'last_seen' => date('Y-m-d H:i:s'), 'status' => 'ok', 'heartbeat' => json_encode(['version' => '3.29.1', 'visits_7_days' => 0,
        'stats_confirmed_since' => date('Y-m-d', time() - 3 * 86400), 'last_backup' => time(), 'cron_last_run' => time()])], time())['reasons'],
], 'admin-');
check('3.29.1 ReportSummary – a drop of visits across the switch is "counting changed on …" in a neutral tone, never ▼; a rise or a drop without the switch as before; the console asks no attention for it', [
    $tile3291['tone'], str_contains($tile3291['change'], '▼'), $tile3291['change'] === Kaleta\Core\Language::runWith('en', fn (): string => 'counting changed on ' . format_date('2026-10-20'), 'admin-'),
    $plain3291 === ['change' => $tile3291['change'], 'tone' => 'same'], $rise3291['tone'], $noSwitch3291['tone'], str_starts_with($noSwitch3291['change'], '▼'),
    Kaleta\Core\ReportSummary::countingChanged(['confirmed_since' => '2026-10-20 x']), Kaleta\Core\ReportSummary::countingChanged(null), $attention3291],
    ['same', false, true, true, 'up', 'down', true, null, null, []]);
$site3291 = fn (?string $since): array => ['id' => 7, 'name' => 'Alpha', 'url' => 'https://alpha.example', 'version' => '3.29.1',
    'heartbeat' => json_encode(['version' => '3.29.1'] + ($since !== null ? ['stats_confirmed_since' => $since] : []))];
$report3291 = fn (?string $since, string $month): array => Kaleta\Fleet\Report::siteData($site3291($since), [], [], [], null, $month);
$sections3291 = Kaleta\Core\Language::runWith('en', fn (): string => json_encode(Kaleta\Fleet\Report::sections(['month' => '2026-10', 'sites' => [$report3291('2026-10-20', '2026-10')], 'totals' => []])), 'admin-');
check('3.29.1 Fleet\Report – a site whose switch falls in the report month carries counting_changed and its section says so; another month, none or a hostile day: nothing', [
    $report3291('2026-10-20', '2026-10')['counting_changed'] ?? null, $report3291('2026-10-01', '2026-10')['counting_changed'] ?? null, $report3291('2026-10-31', '2026-10')['counting_changed'] ?? null,
    array_key_exists('counting_changed', $report3291('2026-11-01', '2026-10')), array_key_exists('counting_changed', $report3291('2026-09-30', '2026-10')),
    array_key_exists('counting_changed', $report3291(null, '2026-10')), array_key_exists('counting_changed', $report3291('2026-10-99', '2026-10')),
    array_key_exists('counting_changed', Kaleta\Fleet\Report::siteData($site3291('2026-10-20'), [], [], [], null)),
    str_contains($sections3291, 'Counting of visits changed on')],
    ['2026-10-20', '2026-10-01', '2026-10-31', false, false, false, false, false, true]);
check('[source-pin] 3.29.1 – the new texts are in Czech, German and Spanish; the heartbeat sends the day only through Stats::confirmedSince', [
    array_map(fn (string $code): array => array_values(array_diff(['Counting of visits changed on %s – fewer bots, not fewer people.', 'counting changed on %s'], array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/Heartbeat.php'), "'stats_confirmed_since' => \\Kaleta\\Front\\Stats::enabled(\$s) ? self::part(fn (): ?string => \\Kaleta\\Front\\Stats::confirmedSince(\$s, \$db)) : null")],
    [[[], [], []], true]);

