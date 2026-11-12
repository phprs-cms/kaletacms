<?php

declare(strict_types=1);

/* ---------- 3.30 (agent X): the console's inventory, expiry calendar and update rings (Fleet\Inventory, Fleet\Calendar, Fleet\Rollout) ---------- */
$x330Now = 1_800_000_000; // Friday 15 January 2027, 08:00 UTC
$x330Clean = fn (string $key, mixed $v): mixed => Kaleta\Fleet\Console::clean([$key => $v])[$key];
check('3.30 Console::clean – the inventory parts: enums and versions by pattern, short names only (unique, at most 40, never the whistleblowing channel), a real day', [
    $x330Clean('db_server', ['family' => 'mariadb', 'version' => '10.11.6', 'host' => 'db.client.example']), $x330Clean('db_server', ['family' => 'postgres', 'version' => '16.1']),
    $x330Clean('db_server', ['family' => 'mysql', 'version' => '8.4.3-log']), $x330Clean('db_server', 'mysql 8.4'),
    $x330Clean('web_server', 'nginx'), $x330Clean('web_server', 'nginx/1.25.3'), $x330Clean('web_server', ['apache']),
    $x330Clean('features', ['bookings', 'whistleblowing', 'Bookings', 'x;y', 'novinky', 'novinky', 7]), count($x330Clean('addons', array_map(fn (int $i): string => 'addon-' . $i, range(1, 90)))),
    $x330Clean('addons', ['seo-tools', '../evil', 'my_addon', 'whistleblow-plus']), $x330Clean('languages', ['cs', 'en', 'EN', 'zh-hant', '<x>', 'en']), $x330Clean('languages', 'cs'),
    $x330Clean('recovery_key_since', '2026-03-01'), $x330Clean('recovery_key_since', '2026-02-30'), $x330Clean('recovery_key_since', '1999-12-31'), $x330Clean('recovery_key_since', 20260301),
], [['family' => 'mariadb', 'version' => '10.11.6'], null, ['family' => 'mysql', 'version' => null], null, 'nginx', null, null, ['bookings', 'novinky'], 40,
    ['seo-tools'], ['cs', 'en', 'zh-hant'], null, '2026-03-01', null, null, null]);
check('3.30 Inventory – the site\'s side: the database family and version, the web server family only (never a version or a host)', [
    Kaleta\Fleet\Inventory::databaseServer('10.11.6-MariaDB-log'), Kaleta\Fleet\Inventory::databaseServer('5.5.5-10.6.12-MariaDB'), Kaleta\Fleet\Inventory::databaseServer('8.4.3'),
    Kaleta\Fleet\Inventory::databaseServer('weird'),
    array_map(fn (array $c): string => Kaleta\Fleet\Inventory::webServer(...$c), [['Apache/2.4.58 (Unix) OpenSSL', 'fpm-fcgi'], ['LiteSpeed', 'litespeed'], ['', 'cli'], ['', 'apache2handler'],
        ['PHP 8.4.1 Development Server', 'cli-server'], ['Microsoft-IIS/10.0', 'cgi-fcgi'], ['nginx/1.25.3', 'fpm-fcgi'], ['Caddy', 'fpm-fcgi'], ['MyServer 1.0', 'fpm-fcgi'], ['', 'litespeed']]),
    Kaleta\Fleet\Heartbeat::webServer($c329Settings(['fleet_server_hint' => 'nginx']), '', 'cli'), Kaleta\Fleet\Heartbeat::webServer($c329Settings(['fleet_server_hint' => 'nginx']), 'nginx/1.25', 'fpm-fcgi'),
    Kaleta\Fleet\Heartbeat::webServer($c329Settings(['fleet_server_hint' => '<script>']), '', 'cli')],
    [['family' => 'mariadb', 'version' => '10.11.6'], ['family' => 'mariadb', 'version' => '10.6.12'], ['family' => 'mysql', 'version' => '8.4.3'], ['family' => 'other', 'version' => null],
        ['apache', 'litespeed', 'unknown', 'apache', 'php', 'iis', 'nginx', 'caddy', 'other', 'litespeed'], 'nginx', 'nginx', 'unknown']);
$x330Beat = ['version' => '3.30.0', 'php' => '8.4.2', 'update_channel' => 'latest', 'db_server' => ['family' => 'mariadb', 'version' => '10.11.6'], 'web_server' => 'nginx',
    'features' => ['novinky', 'bookings'], 'addons' => ['=cmd'], 'languages' => ['cs', 'en'], 'offsite' => ['status' => 'ok', 'at' => '2027-01-14', 'recovery_key' => 'confirmed'], 'recovery_key_since' => '2026-07-01'];
$x330Old = ['version' => '3.28.0', 'php' => '8.3.14', 'update_channel' => 'stable'];
$x330Site = fn (int $id, string $name, array $beat, array $more = []): array => $more + ['id' => $id, 'name' => $name, 'url' => 'https://' . strtolower($name) . '.example', 'customer_id' => null,
    'version' => (string) ($beat['version'] ?? ''), 'ring' => 'normal', 'last_seen' => date('Y-m-d H:i:s', $x330Now - 600), 'heartbeat' => (string) json_encode($beat)];
$x330Rows = array_map(fn (array $s): array => Kaleta\Fleet\Inventory::row($s, [3 => 'Acme Group']), [$x330Site(1, 'Alpha', $x330Beat, ['customer_id' => 3]), $x330Site(2, 'Beta', $x330Old, ['ring' => 'canary']),
    $x330Site(3, 'Gamma', array_replace($x330Beat, ['php' => '8.3.9', 'db_server' => ['family' => 'mysql', 'version' => '8.4.3'], 'web_server' => 'apache', 'features' => ['novinky']]))]);
$x330Names = fn (array $rows): array => array_column($rows, 'site');
$x330Csv = Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Fleet\Inventory::csv([...$x330Rows, ['site' => '=HYPERLINK("https://evil.example")'] + $x330Rows[1]]), 'admin-');
check('3.30 Inventory – a row per site (a site before 3.30 shows its version, channel and PHP), the filters, the free text, the options and a CSV safe in a spreadsheet', [
    array_intersect_key($x330Rows[1], array_flip(['version', 'channel', 'php', 'php_minor', 'db', 'server', 'features', 'ring', 'known'])), $x330Rows[0]['customer'], $x330Rows[0]['db'],
    $x330Names(Kaleta\Fleet\Inventory::filter($x330Rows, ['php' => '8.3'])), $x330Names(Kaleta\Fleet\Inventory::filter($x330Rows, ['feature' => 'bookings'])),
    $x330Names(Kaleta\Fleet\Inventory::filter($x330Rows, ['customer' => -1])), $x330Names(Kaleta\Fleet\Inventory::filter($x330Rows, ['customer' => 3, 'server' => 'nginx'])),
    $x330Names(Kaleta\Fleet\Inventory::filter($x330Rows, [], 'MariaDB alpha')), $x330Names(Kaleta\Fleet\Inventory::filter($x330Rows, ['db' => 'mysql 8.4', 'php' => '8.4'])),
    Kaleta\Fleet\Inventory::options($x330Rows)['php'], Kaleta\Fleet\Inventory::options($x330Rows)['feature'], array_keys(Kaleta\Fleet\Inventory::options($x330Rows)['version']),
    str_starts_with($x330Csv, "\xEF\xBB\xBFSite;Address;Customer;Version"), str_contains($x330Csv, "\n\"'=HYPERLINK(\"\"https://evil.example\"\")\";"), str_contains($x330Csv, 'MariaDB 10.11.6'), substr_count($x330Csv, "\n")],
    [['version' => '3.28.0', 'channel' => 'stable', 'php' => '8.3.14', 'php_minor' => '8.3', 'db' => null, 'server' => null, 'features' => null, 'ring' => 'pilot', 'known' => false], 'Acme Group', 'mariadb 10.11',
        ['Beta', 'Gamma'], ['Alpha'], ['Beta', 'Gamma'], ['Alpha'], ['Alpha'], [], ['8.4' => 1, '8.3' => 2], ['bookings' => 1, 'novinky' => 2], ['3.30.0', '3.28.0'], true, true, true, 5]);

// update rings and maintenance windows: the decision table (UTC unless said otherwise)
$x330Utc = new DateTimeZone('UTC');
check('3.30 Rollout – rings (canary is pilot), windows from the form and in words', [
    array_map(Kaleta\Fleet\Rollout::ring(...), ['canary', 'pilot', 'careful', 'normal', '', 'evil', null]),
    Kaleta\Fleet\Rollout::parseWindow('12345|22:00-05:00'), Kaleta\Fleet\Rollout::parseWindow('8|22:00-05:00'), Kaleta\Fleet\Rollout::parseWindow('1|24:00-05:00'), Kaleta\Fleet\Rollout::parseWindow(''),
    Kaleta\Fleet\Rollout::parseWindow(null), Kaleta\Fleet\Rollout::windowFromInput(['5', '1', '1', '9', 'x'], '22:00', '5:00'), Kaleta\Fleet\Rollout::windowFromInput([], '22:00', '05:00'),
    Kaleta\Fleet\Rollout::windowFromInput(range(1, 7), '03:00', '03:00'), Kaleta\Fleet\Rollout::windowFromInput(['6', '7'], '10:00', '10:00'), Kaleta\Fleet\Rollout::windowFromInput(['1'], '25:00', '05:00'),
    Kaleta\Core\Language::runWith('en', fn (): array => array_map(Kaleta\Fleet\Rollout::describe(...), ['12345|22:00-05:00', '67|00:00-00:00', '135|08:00-09:30', '', '12|01:00-02:00']), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Fleet\Rollout::describe('12345|22:00-05:00'), 'admin-')],
    [['pilot', 'pilot', 'careful', 'normal', 'normal', 'normal', 'normal'], ['days' => [1, 2, 3, 4, 5], 'from' => 1320, 'to' => 300], null, null, null, null, '15|22:00-05:00', '', '', '67|00:00-00:00', '',
        ['Mon–Fri 22:00–05:00', 'Sat, Sun all day', 'Mon, Wed, Fri 08:00–09:30', 'Any time', 'Mon, Tue 01:00–02:00'], 'Po–Pá 22:00–05:00']);
$x330In = fn (string $w, string $at, string $zone = 'UTC'): bool => Kaleta\Fleet\Rollout::inWindow(Kaleta\Fleet\Rollout::parseWindow($w), (new DateTimeImmutable($at, new DateTimeZone($zone)))->getTimestamp(), new DateTimeZone($zone));
$x330Next = fn (string $w, string $at, string $zone = 'UTC'): ?string => ($t = Kaleta\Fleet\Rollout::nextOpening(Kaleta\Fleet\Rollout::parseWindow($w), (new DateTimeImmutable($at, new DateTimeZone($zone)))->getTimestamp(), new DateTimeZone($zone))) !== null
    ? (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i T') : null;
check('3.30 Rollout – windows past midnight belong to the day they start; the whole day; the clocks going forward and back (Europe/Prague)', [
    $x330In('1|22:00-05:00', '2027-01-18 23:00'), $x330In('1|22:00-05:00', '2027-01-19 03:00'), $x330In('1|22:00-05:00', '2027-01-19 05:00'), $x330In('1|22:00-05:00', '2027-01-17 23:00'),
    $x330In('1|22:00-05:00', '2027-01-18 21:59'), $x330In('6|00:00-00:00', '2027-01-16 23:59'), $x330In('6|00:00-00:00', '2027-01-17 00:00'), $x330In('', '2027-01-17 00:00'),
    $x330Next('1|22:00-05:00', '2027-01-15 08:00'), $x330Next('1|22:00-05:00', '2027-01-19 01:00'), $x330Next('', '2027-01-15 08:00'),
    // the night of 28 March 2027 skips 02:00–03:00: a window 02:00–04:00 opens at 03:00; 02:30 is 03:30 that night
    $x330Next('7|02:00-04:00', '2027-03-27 12:00', 'Europe/Prague'), $x330In('7|02:00-04:00', '2027-03-28 03:30', 'Europe/Prague'), $x330In('7|02:00-04:00', '2027-03-28 04:00', 'Europe/Prague'),
    // the night of 25 October 2026 repeats 02:00–03:00: the window 02:00–03:00 lasts two hours, both 02:30s are in it
    Kaleta\Fleet\Rollout::inWindow(Kaleta\Fleet\Rollout::parseWindow('7|02:00-03:00'), (new DateTimeImmutable('2026-10-25 00:30:00 UTC'))->getTimestamp(), new DateTimeZone('Europe/Prague')),
    Kaleta\Fleet\Rollout::inWindow(Kaleta\Fleet\Rollout::parseWindow('7|02:00-03:00'), (new DateTimeImmutable('2026-10-25 01:30:00 UTC'))->getTimestamp(), new DateTimeZone('Europe/Prague')),
    Kaleta\Fleet\Rollout::inWindow(Kaleta\Fleet\Rollout::parseWindow('7|02:00-03:00'), (new DateTimeImmutable('2026-10-25 02:00:00 UTC'))->getTimestamp(), new DateTimeZone('Europe/Prague')),
    $x330In('7|22:00-02:00', '2026-10-25 23:30', 'Europe/Prague')],
    [true, true, false, false, false, true, false, true, '2027-01-18 22:00 UTC', '2027-01-19 01:00 UTC', '2027-01-15 08:00 UTC', '2027-03-28 03:00 CEST', true, false, true, true, false, true]);
$x330Row = fn (int $id, string $version, string $ring = 'normal', array $more = []): array => $more + ['id' => $id, 'version' => $version, 'ring' => $ring, 'manage_updates' => true,
    'update_allowed' => '', 'status' => 'ok', 'up' => true, 'version_since' => $x330Now - 50 * 3600, 'update_problem' => false];
$x330Delays = ['pilot' => 0, 'normal' => 48, 'careful' => 168];
$x330Decide = function (array $site, array $sites, array $more = []) use ($x330Now, $x330Delays, $x330Utc): string {
    $d = Kaleta\Fleet\Rollout::decide($site, $sites, '3.30.0', $more['security'] ?? false, $more['first_seen'] ?? $x330Now - 3600, $x330Now, ($more['delays'] ?? []) + $x330Delays,
        Kaleta\Fleet\Rollout::parseWindow($more['window'] ?? ''), $more['zone'] ?? $x330Utc);

    return $d['state'] . ':' . $d['version'] . ':' . ($d['state'] === 'waiting' && $d['from'] !== null ? gmdate('D H:i', $d['from']) . '+' . intdiv($d['from'] - $x330Now, 3600) . 'h' : '');
};
$x330Pilot = $x330Row(1, '3.30.0', 'pilot');
check('3.30 Rollout::decide – the decision table: rings and their delays, the pilots\' health, windows (also past midnight), security releases and a version allowed by hand at once', [
    $x330Decide($x330Row(2, '3.29.0', 'normal', ['manage_updates' => false]), []),
    $x330Decide($x330Row(2, '3.30.0'), []),
    $x330Decide($x330Row(2, '3.29.0'), [], ['security' => true, 'window' => '1|02:00-03:00']),
    $x330Decide($x330Row(2, '3.29.0', 'careful', ['update_allowed' => '3.30.0']), [], ['window' => '1|02:00-03:00']),
    $x330Decide($x330Row(1, '3.29.0', 'pilot'), []),
    $x330Decide($x330Row(1, '3.29.0', 'canary'), [], ['window' => '6|22:00-05:00']),
    $x330Decide($x330Row(1, '3.29.0', 'pilot'), [], ['delays' => ['pilot' => 6]]),
    $x330Decide($x330Row(2, '3.29.0'), [], ['first_seen' => $x330Now - 12 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [], ['first_seen' => $x330Now - 49 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [], ['first_seen' => 0]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Pilot]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.30.0', 'pilot', ['version_since' => $x330Now - 10 * 3600])]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.30.0', 'pilot', ['status' => 'error'])]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot')]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot', ['manage_updates' => false])], ['first_seen' => $x330Now - 49 * 3600]),
    $x330Decide($x330Row(3, '3.29.0', 'careful'), [$x330Pilot]),
    $x330Decide($x330Row(3, '3.29.0', 'careful'), [$x330Pilot], ['delays' => ['careful' => 0]]),
    $x330Decide($x330Row(3, '3.29.0', 'careful'), [], ['first_seen' => $x330Now - 200 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Pilot], ['window' => '1|22:00-05:00']),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Pilot], ['window' => '5|07:00-09:00']),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Pilot], ['window' => '4|22:00-09:00']),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.30.0', 'pilot', ['version_since' => $x330Now - 40 * 3600])], ['window' => '12345|22:00-05:00']),
], ['self::', 'updated::', 'allowed:3.30.0:', 'allowed:3.30.0:', 'allowed:3.30.0:', 'waiting::Sat 22:00+38h', 'waiting::Fri 13:00+5h', 'waiting::Sat 20:00+36h', 'allowed:3.30.0:', 'blocked::',
    'allowed:3.30.0:', 'waiting::Sat 22:00+38h', 'blocked::', 'blocked::', 'allowed:3.30.0:', 'waiting::Wed 06:00+118h', 'allowed:3.30.0:', 'allowed:3.30.0:', 'waiting::Mon 22:00+86h',
    'allowed:3.30.0:', 'allowed:3.30.0:', 'waiting::Fri 22:00+14h']);
// N330-1/2: a pilot already past the release counts as running it; a pilot that stopped reporting while healthy on an
// older version (or never reported since its pairing) is left out – no site waits for it forever; down, an error or a
// failed update still hold the others back; Console::row carries the last report (or the pairing)
check('3.30 review N330-1/2 – a newer pilot passes the gate; a silent healthy pilot is left out, a silent broken one still holds back', [
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.31.0', 'pilot')]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot', ['last_seen' => $x330Now - 30 * 3600])], ['first_seen' => $x330Now - 49 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot', ['last_seen' => $x330Now - 30 * 3600])]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot', ['last_seen' => $x330Now - 2 * 3600])], ['first_seen' => $x330Now - 49 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot', ['last_seen' => $x330Now - 30 * 3600, 'up' => false])], ['first_seen' => $x330Now - 49 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot', ['last_seen' => $x330Now - 30 * 3600, 'update_problem' => true])], ['first_seen' => $x330Now - 49 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.29.0', 'pilot', ['last_seen' => $x330Now - 30 * 3600, 'status' => 'error'])], ['first_seen' => $x330Now - 49 * 3600]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '', 'pilot', ['last_seen' => $x330Now - 30 * 3600]), $x330Pilot]),
    $x330Decide($x330Row(2, '3.29.0'), [$x330Row(1, '3.30.0', 'pilot', ['last_seen' => $x330Now - 30 * 3600, 'status' => 'error'])]),
    Kaleta\Fleet\Console::row(['id' => 1, 'version' => '', 'ring' => 'pilot', 'manage_updates' => 1, 'update_allowed' => '', 'status' => '', 'up' => null, 'version_since' => null,
        'heartbeat' => null, 'last_seen' => null, 'paired_at' => date('Y-m-d H:i:s', $x330Now - 40 * 3600)])['last_seen'] === $x330Now - 40 * 3600,
], ['allowed:3.30.0:', 'allowed:3.30.0:', 'waiting::Sun 07:00+47h', 'blocked::', 'blocked::', 'blocked::', 'blocked::', 'allowed:3.30.0:', 'blocked::', true]);
check('3.30 review N330-3 – how long a window is open; under two hours is too short for the hourly heartbeat', [
    array_map(fn (string $w): int => Kaleta\Fleet\Rollout::windowMinutes((array) Kaleta\Fleet\Rollout::parseWindow($w)), ['1|22:00-05:00', '1|03:00-03:30', '67|00:00-00:00', '1|23:30-00:30', '1|01:00-03:00']),
    array_map(Kaleta\Fleet\Rollout::longEnough(...), ['', '1|22:00-05:00', '1|03:00-03:30', '67|00:00-00:00', '1|23:30-00:30', '1|01:00-03:00', 'nonsense'])],
    [[420, 30, 1440, 60, 120], [true, true, false, true, false, true, true]]);
$x330AdminSections = [];
foreach (Kaleta\Admin\Kernel::MODULES as $x330Class) {
    if ($x330Class::ADMIN_ONLY && in_array($x330Class::IDENT, Kaleta\Core\Assignment::KINDS, true)) {
        $x330AdminSections[] = $x330Class::IDENT;
    }
}
check('3.30 review N330-4 – Assignment::ADMIN_SECTIONS are exactly the sections of Assignment::KINDS that only administrators open', [array_values(array_unique($x330AdminSections))],
    [Kaleta\Core\Assignment::ADMIN_SECTIONS]);
check('3.30 review N330-5 – a security release the console installed on itself before any site asked is still a security release', [
    Kaleta\Fleet\Console::isSecurityRelease('3.30.1', ['verze' => '3.30.1', 'bezpecnostni' => true], null),
    Kaleta\Fleet\Console::isSecurityRelease('3.30.1', ['verze' => '3.30.1'], ['manifest' => ['verze' => '3.30.1', 'bezpecnostni' => true]]),
    Kaleta\Fleet\Console::isSecurityRelease('3.30.1', null, ['manifest' => ['verze' => '3.30.1', 'bezpecnostni' => true]]),
    Kaleta\Fleet\Console::isSecurityRelease('3.30.1', null, ['manifest' => ['verze' => '3.30.0', 'bezpecnostni' => true]]),
    Kaleta\Fleet\Console::isSecurityRelease('3.30.1', null, ['manifest' => ['verze' => '3.30.1']]),
    Kaleta\Fleet\Console::isSecurityRelease('3.30.1', null, ['manifest' => null]),
    Kaleta\Fleet\Console::isSecurityRelease('3.30.1', null, 'nonsense')],
    [true, false, true, false, false, false, false]);
$x330Sites = [
    ['id' => 1, 'name' => 'Pilot site', 'url' => 'https://pilot.example', 'ring' => 'canary', 'version' => '3.30.0', 'manage_updates' => 1, 'update_allowed' => '', 'status' => 'ok', 'up' => 1,
        'version_since' => date('Y-m-d H:i:s', $x330Now - 50 * 3600), 'heartbeat' => '{}', 'customer_id' => null, 'update_window' => null],
    ['id' => 2, 'name' => 'Night', 'url' => 'https://night.example', 'ring' => 'normal', 'version' => '3.29.0', 'manage_updates' => 1, 'update_allowed' => '', 'status' => 'ok', 'up' => 1,
        'version_since' => null, 'heartbeat' => '{}', 'customer_id' => 4, 'update_window' => null],
    ['id' => 3, 'name' => 'Failed', 'url' => 'https://failed.example', 'ring' => 'careful', 'version' => '3.29.0', 'manage_updates' => 1, 'update_allowed' => '', 'status' => 'ok', 'up' => 1,
        'version_since' => null, 'heartbeat' => '{"update_problem":"Version 3.30.0 rolled back"}', 'customer_id' => 4, 'update_window' => ''],
    ['id' => 4, 'name' => 'Self', 'url' => 'https://self.example', 'ring' => 'normal', 'version' => '3.28.0', 'manage_updates' => 0, 'update_allowed' => '', 'status' => 'ok', 'up' => 1,
        'version_since' => null, 'heartbeat' => '{}', 'customer_id' => null, 'update_window' => null],
];
$x330Overview = Kaleta\Fleet\Rollout::overview($x330Sites, [4 => '1|22:00-05:00'], '3.30.0', false, $x330Now - 3600, $x330Now, $x330Delays, $x330Utc);
check('3.30 Rollout::overview – per site its ring, state and the moment it may update; per ring the counts; a site\'s own window over its customer\'s; Console::allowedVersion keeps 2.9\'s answer', [
    array_map(fn (array $r): string => $r['site_id'] . ':' . $r['ring'] . ':' . $r['state'] . ':' . ($r['from'] !== null ? gmdate('D H:i', $r['from']) : '') . ':' . $r['window'], $x330Overview['sites']),
    array_map(fn (array $ring): array => array_filter($ring), $x330Overview['rings']), $x330Overview['next']['normal'] !== null,
    Kaleta\Fleet\Rollout::windowOf(['update_window' => null, 'customer_id' => 4], [4 => '1|22:00-05:00']), Kaleta\Fleet\Rollout::windowOf(['update_window' => '', 'customer_id' => 4], [4 => '1|22:00-05:00']),
    Kaleta\Fleet\Console::allowedVersion($x330Row(2, '3.29.0'), [$x330Row(1, '3.30.0', 'canary', ['version_since' => $x330Now - 10 * 3600])], '3.30.0', false, 0, $x330Now)],
    [['1:pilot:updated::', '2:normal:waiting:Mon 22:00:1|22:00-05:00', '4:normal:self::', '3:careful:failed::'],
        ['pilot' => ['updated' => 1], 'normal' => ['waiting' => 1, 'self' => 1], 'careful' => ['failed' => 1]], true, '1|22:00-05:00', '', '']);
// 3.31.1: a rollout held back by the pilots for more than 3 days is one amber item of Today (counted from when the first held
// site would otherwise have been allowed), with the plain link to the Rollout tab; a security release or a short hold is none
$a3311Held = fn (int $firstSeen, bool $security = false, array $states = ['blocked', 'blocked']): ?array => Kaleta\Core\Language::runWith('en', fn (): ?array => Kaleta\Fleet\Rollout::heldItem(['version' => '3.32.0',
    'security' => $security, 'first_seen' => $firstSeen, 'sites' => [['ring' => 'pilot', 'state' => 'failed'], ['ring' => 'normal', 'state' => $states[0]], ['ring' => 'careful', 'state' => $states[1]]],
    'rings' => [], 'next' => []], $x330Delays, $x330Now), 'admin-');
$a3311Item = $a3311Held($x330Now - 6 * 86400);
$a3311Today = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/fleet/today.php');
check('[source-pin] 3.31.1 Rollout::heldItem – one item for the release (held since the first held site\'s ring delay), amber, linking to the Rollout tab; none under 3 days, for a security release or without held sites; a careful site counts from its own week; Today and fleet_today show it', [
    array_intersect_key($a3311Item ?? [], array_flip(['kind', 'site_id', 'check', 'state', 'label', 'detail', 'link', 'weight', 'held'])), ($a3311Item['since'] ?? 0) === $x330Now - 4 * 86400,
    $a3311Held($x330Now - 5 * 86400), $a3311Held($x330Now - 6 * 86400, true), $a3311Held($x330Now - 6 * 86400, false, ['waiting', 'allowed']), $a3311Held($x330Now - 6 * 86400, false, ['waiting', 'blocked']), $a3311Held($x330Now - 11 * 86400, false, ['waiting', 'blocked'])['held'] ?? 0,
    count(Kaleta\Fleet\ConsoleTasks::rank([['site' => 'B', 'site_id' => 2, 'state' => 'bad', 'weight' => 100, 'since' => 1], $a3311Item])),
    str_contains($a3311Today, "(\$i['kind'] ?? '') === 'rollout'"), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Fleet.php'), '$held = $customer === 0 ? $this->heldRollout($sites, $now) : null;'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/FleetTools.php'), 'Rollout::heldItem('),
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Update rollout held back'], (require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Version %s – sites held back by the pilot sites: %s']), ['cs', 'de', 'es'])],
    [['kind' => 'rollout', 'site_id' => 0, 'check' => 'rollout', 'label' => 'Update rollout held back', 'state' => 'warn', 'detail' => 'Version 3.32.0 – sites held back by the pilot sites: 2',
        'link' => 'admin.php?module=fleet&action=rollout', 'weight' => 50, 'held' => 2], true, null, null, null, null, 1, 2, true, true, true, [true, true, true]]);

// the expiry calendar
$x330Watch = fn (int $certDays, ?int $domainDays, array $more = []): array => ['domain_watch' => $more + ['checked' => $x330Now - 3600, 'local' => false,
    'certificate' => ['expires' => date('Y-m-d', $x330Now + $certDays * 86400), 'days' => $certDays, 'error' => false],
    'domain' => $domainDays === null ? ['expires' => null, 'days' => null, 'error' => true] : ['expires' => null, 'days' => $domainDays, 'error' => false]]];
$x330Agree = [];
foreach ([-1, 0, 6, 7, 13, 14, 29, 30, 31] as $d) {
    $checks = array_column(Kaleta\Fleet\Checks::forSite(['version' => '3.29.0', 'last_backup' => $c329Now] + ['domain_watch' => ['checked' => $c329Now, 'local' => false,
        'certificate' => ['expires' => $c329Day($d)], 'domain' => ['expires' => $c329Day($d)]]], $c329Up, $c329Now), 'state', 'id');
    $left = (int) Kaleta\Fleet\Console::daysLeft(['expires' => $c329Day($d)], $c329Now); // what the calendar counts
    $x330Agree[] = $checks['certificate'] === Kaleta\Fleet\Calendar::state('certificate', $left) && $checks['domain'] === Kaleta\Fleet\Calendar::state('domain', $left);
}
$x330Cal = [$x330Site(1, 'Alpha', $x330Watch(5, 400)), $x330Site(2, 'Beta', $x330Watch(20, null)), $x330Site(3, 'Local', $x330Watch(3, 3, ['local' => true])), $x330Site(4, 'Old', $x330Old)];
$x330Dates = Kaleta\Fleet\Calendar::dates($x330Cal, $x330Now);
check('3.30 Calendar – the same thresholds as the health checks; the dates of the sites (a date, or the day of the check + its days), none on a local address or after a failed check', [
    $x330Agree, array_map(fn (array $e): string => $e['site_id'] . ':' . $e['kind'] . ':' . $e['date'] . ':' . $e['days'] . ':' . $e['state'], $x330Dates), $x330Dates[0]['link']],
    [array_fill(0, 9, true), ['1:certificate:2027-01-20:4:bad', '2:certificate:2027-02-04:19:ok', '1:domain:2028-02-19:399:ok'], 'https://alpha.example/admin.php?module=status']);
$x330Due = fn (array $events, array $sent): array => array_map(fn (array $d): string => $d['event']['site_id'] . ':' . $d['event']['kind'] . ':' . $d['days_before'] . ':' . implode(',', $d['passed']),
    Kaleta\Fleet\Calendar::due($events, [30, 7], $sent));
$x330Sent = fn (array $keys): array => array_fill_keys($keys, true);
check('3.30 Calendar::due – each date once per threshold; a date found past several thresholds is reminded once, at the smallest, which marks the larger ones too', [
    $x330Due($x330Dates, []), $x330Due($x330Dates, $x330Sent(['1:certificate:2027-01-20:7', '1:certificate:2027-01-20:30'])),
    $x330Due($x330Dates, $x330Sent(['2:certificate:2027-02-04:30'])), $x330Due($x330Dates, $x330Sent(['1:certificate:2027-01-20:30'])),
    $x330Due([['site_id' => 9, 'kind' => 'domain', 'date' => '2027-01-10', 'days' => -5]], []), Kaleta\Fleet\Calendar::due($x330Dates, [], [])],
    [['1:certificate:7:30,7', '2:certificate:30:30'], ['2:certificate:30:30'], ['1:certificate:7:30,7'], ['1:certificate:7:30,7', '2:certificate:30:30'], ['9:domain:7:30,7'], []]);
// 3.31.1: certificates are reminded 14 and 7 days before (fleet_certificate_reminders), domains keep fleet_expiry_reminders;
// a date reminded within a threshold already (an old 30-day reminder sent 12 days before) is not reminded again at it
$a3311ByKind = ['certificate' => [14, 7], 'domain' => [30, 7]];
$a3311Due = fn (array $sent): array => array_map(fn (array $d): string => $d['event']['site_id'] . ':' . $d['event']['kind'] . ':' . $d['days_before'] . ':' . implode(',', $d['passed']),
    Kaleta\Fleet\Calendar::dueByKind([...$x330Dates, ['site_id' => 9, 'kind' => 'domain', 'date' => '2027-02-04', 'days' => 19]], $a3311ByKind, $sent));
$a3311Old = [['site_id' => '1', 'kind' => 'certificate', 'expires' => '2027-01-20', 'days_before' => '30', 'sent_at' => '2027-01-08 09:00:00'],
    ['site_id' => '2', 'kind' => 'certificate', 'expires' => '2027-02-04', 'days_before' => '30', 'sent_at' => '2027-01-05 09:00:00']];
check('3.31.1 Calendar: the reminder days by kind – certificates 14 and 7 (a 19-day certificate waits, a 19-day domain is reminded), the old 30-day certificate reminders cover what they reached', [
    Kaleta\Core\Settings::DEFAULTS['fleet_certificate_reminders'], Kaleta\Core\Settings::DEFAULTS['fleet_expiry_reminders'], Kaleta\Fleet\Calendar::REMINDER_SETTINGS,
    $a3311Due([]), Kaleta\Fleet\Calendar::covered($a3311Old, $a3311ByKind), $a3311Due(Kaleta\Fleet\Calendar::covered($a3311Old, $a3311ByKind)),
    Kaleta\Fleet\Calendar::covered([['site_id' => 1, 'kind' => 'domain', 'expires' => 'x', 'sent_at' => '2027-01-01']], $a3311ByKind)],
    ['14,7', '30,7', ['certificate' => 'fleet_certificate_reminders', 'domain' => 'fleet_expiry_reminders'],
        ['1:certificate:7:14,7', '9:domain:30:30'], ['1:certificate:2027-01-20:14' => true], ['1:certificate:7:14,7', '9:domain:30:30'], []]);
check('3.30 Calendar – the reminder days, the month grid from Monday, the recovery keys', [
    array_map(Kaleta\Fleet\Calendar::parseReminders(...), ['30, 7', '7,30,30', '', ' 0 ', '400', '1,2,3,4,5,6', 'x', '-1']),
    count(Kaleta\Fleet\Calendar::month($x330Dates, '2026-11', $x330Now)['weeks']), Kaleta\Fleet\Calendar::month($x330Dates, '2026-11', $x330Now)['weeks'][0][0]['date'],
    Kaleta\Fleet\Calendar::month($x330Dates, 'nope', $x330Now)['month'], array_map(fn (array $d): int => count($d['events']), Kaleta\Fleet\Calendar::month($x330Dates, '2027-01', $x330Now)['weeks'][3]),
    array_map(fn (array $k): string => $k['site_id'] . ':' . $k['state'] . ':' . ($k['since'] ?? '-'), Kaleta\Fleet\Calendar::keys([$x330Site(1, 'Alpha', $x330Beat),
        $x330Site(2, 'Beta', ['version' => '3.29.0', 'offsite' => ['status' => 'paused', 'recovery_key' => 'unconfirmed']]), $x330Site(3, 'Gamma', ['version' => '3.29.0', 'offsite' => ['status' => 'ok', 'recovery_key' => 'none']]),
        $x330Site(4, 'Off', ['version' => '3.29.0', 'offsite' => ['status' => 'off', 'recovery_key' => 'none']]), $x330Site(5, 'Old', ['version' => '3.28.0', 'offsite_backup' => true]),
        $x330Site(6, 'OldOff', ['version' => '3.28.0', 'offsite_backup' => false])], $x330Now))],
    [[[30, 7], [30, 7], [], [0], null, null, null, null], 6, '2026-10-26', '2027-01', [0, 0, 1, 0, 0, 0, 0], ['2:bad:-', '3:warn:-', '5:unknown:-', '1:ok:2026-07-01']]);
$x330Ics = Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Fleet\Calendar::ics([['site_id' => 7, 'site' => 'Smith; Sons, Ltd – a very long name of a client that needs folding in the feed', 'kind' => 'certificate',
    'date' => '2027-01-20', 'days' => 5, 'state' => 'bad', 'link' => '']], "Agency\nconsole", 'https://console.example', $x330Now), 'admin-');
check('3.30 Calendar::ics – CRLF lines folded at 75 octets, an all-day event, text escaped, no line break of a name gets through', [
    str_starts_with($x330Ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"), str_ends_with($x330Ics, "END:VCALENDAR\r\n"), max(array_map('strlen', explode("\r\n", $x330Ics))) <= 75,
    str_contains(str_replace("\r\n ", '', $x330Ics), 'SUMMARY:Certificate ends – Smith\; Sons\, Ltd'), str_contains($x330Ics, "DTSTART;VALUE=DATE:20270120\r\nDTEND;VALUE=DATE:20270121"),
    str_contains($x330Ics, 'X-WR-CALNAME:Agency console'), str_contains($x330Ics, 'UID:fleet-7-certificate-20270120@console.example'), substr_count($x330Ics, "\n") === substr_count($x330Ics, "\r\n")],
    [true, true, true, true, true, true, true, true]);

// the console's screens, jobs and tools
$x330Tabs = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/fleet/tabs.php');
$x330Fleet = new ReflectionClass(Kaleta\Admin\Modules\Fleet::class);
check('[source-pin] 3.30: three new tabs (Inventory, Calendar, Rollout), their actions, the reminder job only on a console, fleet_inventory and fleet_calendar are read tools of the fleet extension outside the essentials, English on both sides, with a title', [
    (bool) preg_match("/'inventory' => t\\('Inventory'\\), 'calendar' => t\\('Calendar'\\), 'rollout' => t\\('Rollout'\\)/", $x330Tabs),
    array_map(fn (string $m): bool => $x330Fleet->hasMethod($m), ['actionInventory', 'actionInventoryCsv', 'actionCalendar', 'actionCalendarSave', 'actionCalendarFeed', 'actionRollout', 'actionRolloutSave', 'actionWindow']),
    Kaleta\Core\Scheduler::JOBS['fleet_expiry'][0] ?? null, Kaleta\Core\Scheduler::applies('fleet_expiry', $c329Settings(['extensions' => 'fleet'])), Kaleta\Core\Scheduler::applies('fleet_expiry', $c329Settings(['extensions' => 'novinky'])),
    array_map(fn (string $t): array => [Kaleta\Mcp\Catalog::TOOLS[$t] ?? null, in_array($t, Kaleta\Mcp\Catalog::ESSENTIALS, true), Kaleta\Mcp\Translator::czech($t), isset(Kaleta\Mcp\Catalog::TITLES[$t])],
        ['fleet_inventory', 'fleet_calendar']),
    Kaleta\Core\Inbox::parse('fleet_site:7'), Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Inbox::what('fleet_expiry', '') . ' – ' . Kaleta\Core\Inbox::where(['kind' => 'fleet_site', 'id' => 7, 'title' => 'Acme']), 'admin-'),
    Kaleta\Core\Settings::DEFAULTS['fleet_expiry_reminders'], array_map(fn (string $k): string => Kaleta\Core\Settings::DEFAULTS[$k], Kaleta\Fleet\Rollout::SETTINGS)],
    [true, array_fill(0, 8, true), 3600, true, false, [[['read', 'fleet'], false, 'fleet_inventory', true], [['read', 'fleet'], false, 'fleet_calendar', true]], ['fleet_site', 7],
        'A date of a site comes up – Site “Acme” in the fleet console', '30,7', ['pilot' => '0', 'normal' => '48', 'careful' => '168']]);
$x330Files = ['src/Fleet/Rollout.php', 'src/Fleet/Inventory.php', 'src/Fleet/Calendar.php', 'views/admin/fleet/inventory.php', 'views/admin/fleet/calendar.php', 'views/admin/fleet/rollout.php',
    'views/admin/fleet/window.php', 'views/admin/fleet/detail.php'];
$x330Strings = [...Kaleta\Fleet\Rollout::RING_LABELS, ...Kaleta\Fleet\Rollout::STATE_LABELS, ...Kaleta\Fleet\Calendar::KINDS, 'PHP built-in server', Kaleta\Core\Scheduler::JOBS['fleet_expiry'][2]];
foreach ($x330Files as $file) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_SYSTEM . '/' . $file), $m);
    $x330Strings = [...$x330Strings, ...array_map('stripslashes', $m[1])];
}
$x330Strings = array_values(array_unique($x330Strings));
check('3.30: every text of the inventory, the calendar and the rollout is translated (cs, de, es)', [count($x330Strings) > 100,
    array_map(fn (string $code): array => array_values(array_diff($x330Strings, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [true, [[], [], []]]);
