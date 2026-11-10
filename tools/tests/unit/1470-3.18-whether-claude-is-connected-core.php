<?php

declare(strict_types=1);

/* ---------- 3.18: whether Claude is connected (Core\ClaudeConnection) ---------- */
$cc = Kaleta\Core\ClaudeConnection::class;
$ccNow = 1_800_000_000;
$ccAt = fn (string $modifier): string => date('Y-m-d H:i:s', (int) strtotime($modifier, $ccNow));
$ccFacts = fn (array $more = []): array => $more + ['enabled' => true, 'connections' => 1, 'last_call' => $ccAt('-1 hour'), 'expires' => $ccAt('+300 days'), 'schedules' => [], 'waiting' => 0, 'oldest_waiting' => null, 'first_used' => ''];
$ccPick = fn (array $facts, string ...$keys): array => array_intersect_key($cc::evaluate($facts, $ccNow), array_flip($keys));
check('3.18 ClaudeConnection::evaluate – off, none, active (recent), idle after 7 days or never; pickup only with a connection', [
    $ccPick($ccFacts(['enabled' => false]), 'state', 'pickup', 'expires'),
    $ccPick($ccFacts(['connections' => 0, 'waiting' => 2, 'oldest_waiting' => $ccAt('-3 days')]), 'state', 'pickup', 'expires', 'waiting', 'oldest_waiting'),
    $ccPick($ccFacts(), 'state', 'recent', 'pickup'),
    $ccPick($ccFacts(['last_call' => $ccAt('-2 days')]), 'state', 'recent'),
    $ccPick($ccFacts(['last_call' => $ccAt('-8 days')]), 'state', 'recent'),
    $ccPick($ccFacts(['last_call' => null]), 'state', 'last_call', 'ever'),
    $ccPick($ccFacts(['last_call' => null, 'first_used' => '2026-01-01 10:00:00']), 'ever'),
], [
    ['state' => 'off', 'pickup' => null, 'expires' => null],
    ['state' => 'none', 'pickup' => null, 'expires' => null, 'waiting' => 2, 'oldest_waiting' => $ccAt('-3 days')],
    ['state' => 'active', 'recent' => true, 'pickup' => 'manual'],
    ['state' => 'active', 'recent' => false],
    ['state' => 'idle', 'recent' => false],
    ['state' => 'idle', 'last_call' => null, 'ever' => false],
    ['ever' => true],
]);
$ccRun = fn (?string $status, ?string $at, string $due): array => ['next_due' => $due, 'last_status' => $status, 'last_at' => $at];
check('3.18 ClaudeConnection::evaluate – pickup: automatic while a requests run runs, stalled after a missed or failed run or 6+ hours overdue, one healthy schedule is enough; expiring within 14 days', [
    $ccPick($ccFacts(['schedules' => [$ccRun('ok', $ccAt('-1 day'), $ccAt('+1 day'))]]), 'pickup', 'stalled_since'),
    $ccPick($ccFacts(['schedules' => [$ccRun(null, null, $ccAt('-1 hour'))]]), 'pickup'),
    $ccPick($ccFacts(['schedules' => [$ccRun('missed', $ccAt('-2 hours'), $ccAt('+1 day'))]]), 'pickup', 'stalled_since'),
    $ccPick($ccFacts(['schedules' => [$ccRun('failed', $ccAt('-5 hours'), $ccAt('+1 day'))]]), 'pickup', 'stalled_since'),
    $ccPick($ccFacts(['schedules' => [$ccRun('ok', $ccAt('-8 days'), $ccAt('-7 hours'))]]), 'pickup', 'stalled_since'),
    $ccPick($ccFacts(['schedules' => [$ccRun('missed', $ccAt('-2 hours'), $ccAt('+1 day')), $ccRun('partial', $ccAt('-1 day'), $ccAt('+6 days'))]]), 'pickup', 'stalled_since'),
    $ccPick($ccFacts(['connections' => 0, 'schedules' => [$ccRun('missed', $ccAt('-2 hours'), $ccAt('+1 day'))]]), 'state', 'pickup'),
    $ccPick($ccFacts(['expires' => $ccAt('+10 days')]), 'expires', 'expiring'),
    $ccPick($ccFacts(['expires' => $ccAt('+20 days')]), 'expiring'),
    $ccPick($ccFacts(['expires' => null]), 'expires', 'expiring'),
], [
    ['pickup' => 'automatic', 'stalled_since' => null],
    ['pickup' => 'automatic'],
    ['pickup' => 'stalled', 'stalled_since' => $ccAt('-2 hours')],
    ['pickup' => 'stalled', 'stalled_since' => $ccAt('-5 hours')],
    ['pickup' => 'stalled', 'stalled_since' => $ccAt('-7 hours')],
    ['pickup' => 'automatic', 'stalled_since' => null],
    ['state' => 'none', 'pickup' => null],
    ['expires' => $ccAt('+10 days'), 'expiring' => true],
    ['expiring' => false],
    ['expires' => null, 'expiring' => false],
]);
$ccStatus = fn (array $more = []): array => $cc::evaluate($ccFacts($more), $ccNow);
check('3.18 ClaudeConnection – reachable (idle or active), blocked (no connection or a stalled run), the Connect card only without a used connection', [
    array_map(fn (array $f): bool => $cc::reachable($ccStatus($f)), [['enabled' => false], ['connections' => 0], ['last_call' => null], []]),
    array_map(fn (array $f): bool => $cc::blocked($ccStatus($f)), [['connections' => 0], [], ['schedules' => [$ccRun('missed', $ccAt('-1 hour'), $ccAt('+1 day'))]]]),
    array_map(fn (array $f): bool => $cc::needsConnecting($ccStatus($f)), [['connections' => 0, 'first_used' => '2026-01-01 10:00:00'], ['last_call' => null], ['last_call' => null, 'first_used' => '2026-01-01 10:00:00'], []]),
], [[false, false, true, true], [true, false, true], [true, true, false, false]]);
check('3.18 ClaudeConnection::summary – the heartbeat and get_health shape: ISO 8601 moments, nothing else', [
    array_keys($cc::summary($ccStatus())), $cc::summary($ccStatus(['last_call' => null, 'expires' => null, 'enabled' => false])),
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) $cc::summary($ccStatus())['last_call'])],
    [['state', 'last_call', 'pickup', 'expires'], ['state' => 'off', 'last_call' => null, 'pickup' => null, 'expires' => null], true]);
// the console bounds the key like every other (3.17): enum values only, real moments between 1970 and 2100, a wrong shape drops the part
$ccClean = fn (mixed $v): mixed => Kaleta\Fleet\Console::clean(['claude_connection' => $v])['claude_connection'];
check('3.18 Console::clean – claude_connection: known states and pickups only, moments bounded to 1970–2100, anything else dropped on its own', [
    $ccClean(['state' => 'active', 'last_call' => '2026-10-20T09:15:00+02:00', 'pickup' => 'automatic', 'expires' => '2027-10-20T09:15:00+02:00', 'token' => 'kaleta_secret', 'who' => 'admin']),
    $ccClean(['state' => 'idle', 'last_call' => '0001-01-01T00:00:00+00:00', 'pickup' => '<script>', 'expires' => '9999-12-31T23:59:59Z']),
    $ccClean(['state' => 'none', 'last_call' => ['x'], 'pickup' => null, 'expires' => '2026-02-30T10:00:00Z']),
    $ccClean(['state' => 'online', 'pickup' => 'automatic']), $ccClean(['state' => ['active']]), $ccClean('active'), $ccClean(null), $ccClean(['last_call' => '2026-10-20T09:15:00Z']),
    $ccClean(['state' => 'idle', 'last_call' => "2026-10-20T09:15:00+02:00\n<b>", 'pickup' => 'MANUAL', 'expires' => 1_800_000_000]),
    array_key_exists('claude_connection', Kaleta\Fleet\Console::storedBeat(['heartbeat' => '{"name":"Old site"}'])),
], [
    ['state' => 'active', 'last_call' => '2026-10-20T09:15:00+02:00', 'pickup' => 'automatic', 'expires' => '2027-10-20T09:15:00+02:00'],
    ['state' => 'idle', 'last_call' => null, 'pickup' => null, 'expires' => null],
    ['state' => 'none', 'last_call' => null, 'pickup' => null, 'expires' => null],
    null, null, null, null, null,
    ['state' => 'idle', 'last_call' => null, 'pickup' => null, 'expires' => null],
    false,
]);
$ccBeat = fn (array $claude, int $new = 2, int $hours = 60): array => ['requests' => ['new' => $new, 'in_progress' => 0, 'done_to_review' => 0, 'oldest_waiting_hours' => $hours], 'claude_connection' => $claude];
check('3.18 Console::attention – new requests waiting while Claude is not connected or the scheduled run stalls; nothing for a connected site, none waiting, a hostile value or a site before 3.18', [
    Kaleta\Fleet\Console::attention($fleetRow([], $ccBeat(['state' => 'none'], 2, 10)), $fleetNow),
    Kaleta\Fleet\Console::attention($fleetRow([], $ccBeat(['state' => 'idle', 'pickup' => 'stalled'], 1, 10)), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], $ccBeat(['state' => 'active', 'pickup' => 'automatic'], 3, 10)), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], $ccBeat(['state' => 'none'], 0, 10)), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], $ccBeat(['state' => ['none']], 2, 10)), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], ['requests' => ['new' => 'lots'], 'claude_connection' => ['state' => 'none']]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], ['requests' => ['new' => 2, 'oldest_waiting_hours' => 10]]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::claudeReason(['requests' => 'x', 'claude_connection' => 'none']),
], [['score' => 15, 'reasons' => ['claude_not_connected']], ['claude_run_stalled'], [], [], [], [], [], null]);
$ccInbox = fn (array $beat): array => array_map(fn (array $r): string => $r['kind'] . ':' . $r['what'] . ':' . $r['urgency'] . ':' . $r['link'],
    array_values(array_filter(Kaleta\Fleet\Inbox::forSite(['id' => 7, 'url' => 'https://acme.example', 'name' => 'Acme'] + $fleetRow([], $beat), [], $fleetNow), fn (array $r): bool => str_starts_with($r['what'], 'claude_'))));
check('3.18 Inbox – a row that leads to the site\'s Claude settings when requests wait and nothing picks them up; urgent past the requests threshold', [
    $ccInbox($ccBeat(['state' => 'none'], 2, 10)), $ccInbox($ccBeat(['state' => 'active', 'pickup' => 'stalled'], 1, 60)), $ccInbox($ccBeat(['state' => 'active', 'pickup' => 'manual'])),
    Kaleta\Fleet\Inbox::label(['what' => 'claude_not_connected']), isset(Kaleta\Fleet\Console::REASONS['claude_run_stalled'])],
    [['requests:claude_not_connected:normal:https://acme.example/admin.php?module=claude_settings'], ['requests:claude_run_stalled:urgent:https://acme.example/admin.php?module=claude_settings'], [],
        'Requests wait but Claude is not connected', true]);
