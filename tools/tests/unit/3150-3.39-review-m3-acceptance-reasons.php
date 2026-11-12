<?php

declare(strict_types=1);

use Kaleta\Fleet\Acceptances;
use Kaleta\Fleet\Checks;

/* ---------- 3.39 review M3: an accepted state holds only for the same specific problem; outages and expiry never muted ---------- */

$m3Now = 1_800_000_000;
$m3Today = date('Y-m-d', $m3Now);
$m3Site = fn (array $beat, array $more = []): array => $more + ['id' => 7, 'name' => 'M3', 'url' => 'https://m3.example', 'customer_id' => null, 'up' => 1, 'up_status' => 200,
    'up_changed' => null, 'last_seen' => date('Y-m-d H:i:s', $m3Now - 600), 'heartbeat' => (string) json_encode($beat + ['version' => '3.39.0', 'last_backup' => $m3Now - 3600,
        'cron_last_run' => $m3Now - 600, 'auto_updates' => true, 'offsite' => ['status' => 'off', 'at' => null, 'recovery_key' => 'none'],
        'mail_setup' => ['mode' => 'smtp', 'tested' => true, 'tested_at' => date('Y-m-d', $m3Now)], 'twofa' => ['required' => 'admins', 'admins_without' => 0],
        'cookie_scan' => ['at' => date('Y-m-d', $m3Now), 'error' => false, 'cookies' => 0], 'privacy_page' => 'published', 'accessibility_statement' => 'published',
        'handover' => ['done' => 1, 'total' => 1, 'failing' => []]])];
$m3Check = function (array $site, string $id) use ($m3Now): array {
    foreach (Checks::forRow($site, $m3Now) as $c) {
        if ($c['id'] === $id) {
            return $c;
        }
    }

    return [];
};
$m3Acc = fn (string $check, string $state, string $reason): array => ['site_id' => 7, 'check' => $check, 'state' => $state, 'reason' => $reason, 'note' => 'n', 'until' => '2100-01-01', 'by' => null, 'at' => ''];
// the same red colour, another problem: off → failing, overdue → the update failed, one job → another job too
$m3Off = $m3Check($m3Site([]), 'offsite_backup');
$m3Failing = $m3Check($m3Site(['offsite' => ['status' => 'failing', 'at' => date('Y-m-d', $m3Now), 'recovery_key' => 'confirmed']]), 'offsite_backup');
$m3Overdue = $m3Check($m3Site(['update_available' => '3.40.0', 'updates' => ['behind_days' => 40]]), 'updates');
$m3UpdateFailed = $m3Check($m3Site(['update_available' => '3.40.0', 'updates' => ['behind_days' => 40], 'update_problem' => 'x']), 'updates');
$m3OneJob = $m3Check($m3Site(['jobs_failing' => ['backup']]), 'jobs');
$m3TwoJobs = $m3Check($m3Site(['jobs_failing' => ['backup', 'security']]), 'jobs');
check('3.39 review M3: every amber or red check carries a specific reason – the same colour, another problem gives another reason; an ok check has none of its own', [
    [$m3Off['state'], $m3Off['reason']], [$m3Failing['state'], $m3Failing['reason']], [$m3Overdue['state'], $m3Overdue['reason']], [$m3UpdateFailed['state'], $m3UpdateFailed['reason']],
    $m3OneJob['state'] === $m3TwoJobs['state'] && $m3OneJob['reason'] !== $m3TwoJobs['reason'], $m3Check($m3Site([]), 'cron')['reason'],
    $m3Check($m3Site(['update_available' => '3.40.1']), 'updates')['reason'], array_filter(array_map(fn (array $c): bool => strlen($c['reason']) > Acceptances::REASON_LENGTH, Checks::forRow($m3Site(['jobs_failing' => array_map(fn (int $i): string => 'job' . $i, range(1, 40))]), $m3Now)))],
    [['bad', 'off'], ['bad', 'failing'], ['bad', 'overdue'], ['bad', 'update_failed'], true, 'ok', 'available', []]);
check('3.39 review M3: an acceptance holds only for the reason it was given – a worse problem of the same colour is open again and the job fleet_alerts drops it; a newer security release too', [
    Acceptances::holds($m3Acc('offsite_backup', 'bad', 'off'), 'bad', $m3Today, $m3Off['reason']), Acceptances::holds($m3Acc('offsite_backup', 'bad', 'off'), 'bad', $m3Today, $m3Failing['reason']),
    Acceptances::holds($m3Acc('updates', 'bad', 'overdue'), 'bad', $m3Today, $m3UpdateFailed['reason']), Acceptances::holds($m3Acc('jobs', 'bad', $m3OneJob['reason']), 'bad', $m3Today, $m3TwoJobs['reason']),
    Acceptances::stale([$m3Site(['offsite' => ['status' => 'failing', 'at' => date('Y-m-d', $m3Now), 'recovery_key' => 'confirmed']])], ['7:offsite_backup' => $m3Acc('offsite_backup', 'bad', 'off')], $m3Now),
    Acceptances::stale([$m3Site([])], ['7:offsite_backup' => $m3Acc('offsite_backup', 'bad', 'off')], $m3Now),
    Acceptances::holds($m3Acc('updates', 'bad', 'security:3.40.1'), 'bad', $m3Today, 'security:3.40.2')],
    [true, false, false, false, ['7:offsite_backup'], [], false]);
// a certificate that ends within a week may be accepted, never once it has expired; an outage never
$m3Soon = $m3Check($m3Site(['domain_watch' => ['checked' => 1, 'certificate' => ['expires' => date('Y-m-d', $m3Now + 3 * 86400 + 3600), 'error' => false, 'host' => 'm3.example']]]), 'certificate');
$m3Expired = $m3Check($m3Site(['domain_watch' => ['checked' => 1, 'certificate' => ['expires' => date('Y-m-d', $m3Now - 2 * 86400), 'error' => false, 'host' => 'm3.example']]]), 'certificate');
$m3Down = $m3Check($m3Site([], ['up' => 0, 'up_status' => 503]), 'uptime');
check('3.39 review M3: a certificate ending within a week can be accepted, but the acceptance ends when it expires (never muted then, no reminder skipped); an outage is never accepted, and a stored one is dropped', [
    [$m3Soon['state'], Acceptances::acceptable('certificate', $m3Soon['state'], $m3Soon['reason'])], [$m3Expired['state'], $m3Expired['reason'], Acceptances::acceptable('certificate', 'bad', $m3Expired['reason'])],
    Acceptances::holds($m3Acc('certificate', 'bad', $m3Soon['reason']), 'bad', $m3Today, $m3Expired['reason']),
    Acceptances::holds($m3Acc('certificate', 'bad', Checks::EXPIRED), 'bad', $m3Today, Checks::EXPIRED), Acceptances::acceptable('uptime', 'bad', $m3Down['reason']),
    Acceptances::holds($m3Acc('uptime', 'bad', 'down'), 'bad', $m3Today, 'down'), Acceptances::stale([$m3Site([], ['up' => 0, 'up_status' => 503])], ['7:uptime' => $m3Acc('uptime', 'bad', 'down')], $m3Now),
    array_keys(Acceptances::active([$m3Site(['domain_watch' => ['checked' => 1, 'certificate' => ['expires' => date('Y-m-d', $m3Now - 2 * 86400), 'error' => false, 'host' => 'm3.example']]])], ['7:certificate' => $m3Acc('certificate', 'bad', 'expiring_soon')], $m3Now))],
    [['bad', true], ['bad', 'expired', false], false, false, false, false, ['7:uptime'], []]);
check('3.39 review M3: accept() refuses an outage or an expired certificate before touching the database', (function () use ($m3Today): array {
    $out = [];
    foreach ([['uptime', 'bad', 'down'], ['certificate', 'bad', Checks::EXPIRED], ['offsite_backup', 'bad', '']] as [$check, $state, $reason]) {
        try {
            Acceptances::accept(new Kaleta\Core\Db('mysql:host=127.0.0.1;port=1;dbname=none', 'none', ''), 7, $check, $state, 'n', $m3Today, null, $reason);
            $out[] = 'stored';
        } catch (InvalidArgumentException) {
            $out[] = 'refused';
        } catch (Throwable) {
            $out[] = 'tried to store';
        }
    }

    return $out;
})(), ['refused', 'refused', 'refused']);
