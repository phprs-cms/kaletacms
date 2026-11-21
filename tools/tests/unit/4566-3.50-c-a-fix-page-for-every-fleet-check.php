<?php

declare(strict_types=1);

/* ---------- 3.50 C (CPO 11): a fix page for every Fleet check – read-only, on the console, plain links ---------- */
use Kaleta\Admin\Modules\Fleet as C350Fleet;
use Kaleta\Fleet\Checks as C350FixChecks;

$c350FixNow = 1_800_000_000;
$c350FixSite = fn (int $id, string $name, array $beat, array $more = []): array => $more + ['id' => $id, 'name' => $name, 'url' => 'https://s' . $id . '.example/', 'customer_id' => null,
    'up' => 1, 'up_status' => 200, 'up_changed' => null, 'last_seen' => date('Y-m-d H:i:s', $c350FixNow - 600), 'heartbeat' => (string) json_encode($beat + ['version' => '3.49.0', 'cron_last_run' => $c350FixNow - 60])];
$c350FixSites = [
    $c350FixSite(1, 'Zulu', ['last_backup' => $c350FixNow - 3600]),
    $c350FixSite(2, 'Alpha', ['last_backup' => $c350FixNow - 20 * 86400]),
    $c350FixSite(3, 'Bravo', []),
    $c350FixSite(4, 'Charlie', ['last_backup' => $c350FixNow - 30 * 86400]),
    $c350FixSite(5, 'Echo', ['last_backup' => $c350FixNow - 7200]),
];
$c350Fix = C350FixChecks::fixList($c350FixSites, 'local_backup', $c350FixNow);
check('3.50 C (CPO 11): every check has a meaning and a way to fix it; the fix page is a view of the console\'s Sites (fleet&action=fix&check=<id>), an unknown check lists nothing', [
    array_keys(C350FixChecks::HELP) === array_keys(C350FixChecks::CHECKS), array_keys(array_filter(C350FixChecks::HELP, fn (array $h): bool => count($h) !== 2 || $h[0] === '' || $h[1] === '')),
    C350Fleet::sectionOf('fix'), (new ReflectionClass(C350Fleet::class))->hasMethod('actionFix'), C350FixChecks::fixList($c350FixSites, 'nonsense', $c350FixNow), is_file(KALETA_SYSTEM . '/views/admin/fleet/fix.php'),
], [true, [], 'sites', true, [], true]);
check('3.50 C (CPO 11): the sites of one check – the ones to fix first (the oldest problem first), then the unknown ones, the fixed ones last with a tick from the stored heartbeat; each to fix with the plain link to its own screen', [
    array_column($c350Fix, 'site_id'), array_column($c350Fix, 'state'), array_column($c350Fix, 'done'), $c350Fix[0]['link'], $c350Fix[0]['site'],
    array_column(C350FixChecks::fixList($c350FixSites, 'uptime', $c350FixNow), 'state'),
], [[4, 2, 3, 5, 1], ['bad', 'bad', 'bad', 'ok', 'ok'], [false, false, false, true, true], 'https://s4.example/admin.php?module=settings&tab=backups', 'Charlie', ['ok', 'ok', 'ok', 'ok', 'ok']]);
check('3.50 C (CPO 11): the texts of the fix page in cs, de and es, German without a form of address', (function (): array {
    $keys = ['What it checks', 'How to fix it', '%d to fix, %d fixed, %d not known', 'Sites and the state of this check', 'Fixed', 'Back to the health of the sites', 'how to fix it',
        'How to fix it on every site', 'A column heading opens the page that says how to fix the check and which sites fail it.',
        'Each link opens the screen of the site\'s own administration – sign in there as usual. The tick appears when the site\'s next report shows the check fixed.',
        ...array_merge(...array_values(C350FixChecks::HELP))];
    $out = [];
    foreach (['cs', 'de', 'es'] as $code) {
        $dictionary = require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php';
        $out[$code] = array_values(array_filter($keys, fn (string $k): bool => !isset($dictionary[$k])));
    }
    $german = require KALETA_SYSTEM . '/jazyky/admin-de.php';
    $out['sie'] = array_values(array_filter($keys, fn (string $k): bool => preg_match('/\b(Sie|Ihr|Ihre|Ihren|Ihnen)\b/u', (string) ($german[$k] ?? '')) === 1));

    return $out;
})(), ['cs' => [], 'de' => [], 'es' => [], 'sie' => []]);
