<?php

declare(strict_types=1);

/* ---------- 3.50 C: Fleet words (U45) and what an acceptance may hide (N147) ---------- */
use Kaleta\Fleet\Acceptances as C350Acceptances;
use Kaleta\Fleet\Checks as C350Checks;
use Kaleta\Fleet\Health as C350Health;
use Kaleta\Fleet\Rollout as C350Rollout;

$c350Now = 1_800_000_000;
$c350Today = date('Y-m-d', $c350Now);
$c350Site = fn (array $beat, array $more = []): array => $more + ['id' => 7, 'name' => 'Seven', 'url' => 'https://seven.example', 'customer_id' => null, 'up' => 1, 'up_status' => 200,
    'up_changed' => null, 'last_seen' => date('Y-m-d H:i:s', $c350Now - 600), 'heartbeat' => (string) json_encode($beat + ['version' => '3.49.0', 'last_backup' => $c350Now - 3600, 'cron_last_run' => $c350Now - 60])];
$c350Check = function (array $site, string $id, array $context = []) use ($c350Now): array {
    foreach (C350Checks::forRow($site, $c350Now, $context) as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }

    return [];
};
check('3.50 C (U45): a site without an answer is "Site is down"; the column of the accessibility statement is "A11y" (the full label is its title); a site that decides itself "Updates on its own"', [
    $c350Check($c350Site([], ['up' => 0, 'up_status' => 0]), 'uptime')['detail'], $c350Check($c350Site([], ['up' => 0, 'up_status' => 503]), 'uptime')['detail'],
    C350Health::SHORT['accessibility_statement'], C350Checks::CHECKS['accessibility_statement'][0], C350Rollout::STATE_LABELS['self'],
    array_map(fn (string $code): array => array_values(array_filter(['Site is down', 'A11y', 'Updates on its own', 'The last update failed and security update %s waits'],
        fn (string $k): bool => !isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')[$k]))), ['cs', 'de', 'es']),
], ['Site is down', 'Answers with HTTP 503', 'A11y', 'Accessibility statement', 'Updates on its own', [[], [], []]]);

// N147 (a): a failed update accepted on the console must not hide a security release that arrives later
$c350Failed = $c350Check($c350Site(['update_available' => '3.50.1', 'update_problem' => 'x']), 'updates');
$c350Both = $c350Check($c350Site(['update_available' => '3.50.1', 'update_problem' => 'x']), 'updates', ['security_version' => '3.50.1']);
$c350Accepted = ['site_id' => 7, 'check' => 'updates', 'state' => 'bad', 'reason' => 'update_failed', 'note' => 'n', 'until' => '2100-01-01', 'by' => null, 'at' => date('Y-m-d H:i:s', $c350Now)];
check('3.50 C (N147): with a failed update and a waiting security release the security arm comes first – reason security:<version>, both said; an acceptance of "the update failed" ends', [
    [$c350Failed['state'], $c350Failed['reason']], [$c350Both['state'], $c350Both['reason'], $c350Both['detail']],
    C350Acceptances::holds($c350Accepted, 'bad', $c350Today, $c350Failed['reason']), C350Acceptances::holds($c350Accepted, 'bad', $c350Today, $c350Both['reason']),
    C350Acceptances::stale([$c350Site(['update_available' => '3.50.1', 'update_problem' => 'x'])], ['7:updates' => $c350Accepted], $c350Now, ['security_version' => '3.50.1']),
], [['bad', 'update_failed'], ['bad', 'security:3.50.1', 'The last update failed and security update 3.50.1 waits'], true, false, ['7:updates']]);

// N147 (b): "no report" holds at most 7 days from accepted_at, whatever last day was given
$c350Silent = $c350Site([], ['last_seen' => date('Y-m-d H:i:s', $c350Now - 5 * 86400)]);
$c350Reporting = $c350Check($c350Silent, 'reporting');
$c350Acc = fn (string $at, string $until = '2100-01-01'): array => ['site_id' => 7, 'check' => 'reporting', 'state' => $c350Reporting['state'], 'reason' => $c350Reporting['reason'], 'note' => 'n',
    'until' => $until, 'by' => null, 'at' => $at];
$c350Day = fn (int $days): string => date('Y-m-d H:i:s', $c350Now - $days * 86400);
check('3.50 C (N147): an accepted "no report" holds at most 7 days from the day it was accepted – a later last day is cut, an earlier one stays; other checks keep their own last day; the job drops it on the 8th day', [
    $c350Reporting['state'], C350Acceptances::lastDay($c350Acc('2026-01-10 12:00:00')), C350Acceptances::lastDay($c350Acc('2026-01-10 12:00:00', '2026-01-12')),
    C350Acceptances::lastDay($c350Acc('')), C350Acceptances::lastDay(['check' => 'updates', 'until' => '2100-01-01', 'at' => '2026-01-10 12:00:00']),
    C350Acceptances::holds($c350Acc($c350Day(7)), $c350Reporting['state'], $c350Today, $c350Reporting['reason']),
    C350Acceptances::holds($c350Acc($c350Day(8)), $c350Reporting['state'], $c350Today, $c350Reporting['reason']),
    C350Acceptances::stale([$c350Silent], ['7:reporting' => $c350Acc($c350Day(3))], $c350Now), C350Acceptances::stale([$c350Silent], ['7:reporting' => $c350Acc($c350Day(8))], $c350Now),
    C350Acceptances::lastDay(['check' => 'reporting', 'until' => '2100-01-01', 'at' => '2026-03-01']), array_keys(C350Acceptances::SHORT),
], ['bad', '2026-01-17', '2026-01-12', '', '2100-01-01', true, false, [], ['7:reporting'], '2026-03-08', ['reporting']]);
