<?php

declare(strict_types=1);

use Kaleta\Core\ServerRules;
use Kaleta\Core\TeamSummary;

/* ---------- 3.41 review: the findings of the security review of 3.41 (M1, L1–L8) ---------- */

// L1: a site address that redirects is followed once on the same host; a redirect elsewhere is inconclusive, never "refused"
$r341Https = ServerRules::check('http://w.example/', fn (string $url): array => str_starts_with($url, 'http://')
    ? [301, '', str_replace('http://', 'https://', $url)] : [403, 'Forbidden']);
$r341Open = ServerRules::check('http://w.example/', fn (string $url): array => str_starts_with($url, 'http://')
    ? [301, '', str_replace('http://w.', 'https://www.w.', $url)] : [200, 'kaleta-canary CREATE TABLE']);
$r341Away = ServerRules::check('https://w.example/', fn (string $url): array => [302, '', 'https://elsewhere.example/x']);
check('3.41 review L1: http → https on the same host is followed (closed / open); a redirect to another host is a "redirect", not closed', [
    $r341Https['open'], $r341Https['redirect'] ?? '', $r341Open['open'], $r341Away['open'], $r341Away['redirect'] ?? '',
], [[], '', ['storage/cache/kaleta-canary.txt', 'system/sql/schema.sql'], [], 'https://elsewhere.example/x']);
check('3.41 review L1: sameHostRedirect resolves a relative Location and refuses another host or scheme', [
    ServerRules::sameHostRedirect('http://w.example/a', 301, '/b'),
    ServerRules::sameHostRedirect('http://w.example/a', 301, 'https://evil.example/a'),
    ServerRules::sameHostRedirect('http://w.example/a', 301, 'ftp://w.example/a'),
    ServerRules::sameHostRedirect('http://w.example/a', 200, 'https://w.example/a'),
], ['http://w.example/b', null, null, null]);

// L3: the team's totals for someone who is not an administrator, and in the monthly report, only from three people
$r341Collected = [0 => ['tasks_open' => 4], 3 => ['tasks_done' => 2], 5 => ['deals_won' => 1], 8 => ['quotes_sent' => 1]];
check('3.41 review L3: people() counts the people (never "nobody responsible") with a count above 0 in the sections shown', [
    TeamSummary::people($r341Collected, ['tasks', 'deals', 'quotes']), TeamSummary::people($r341Collected, ['tasks', 'deals']),
    TeamSummary::people([3 => ['tasks_done' => 0]], ['tasks']), TeamSummary::MIN_PEOPLE,
], [3, 2, 0, 3]);
$r341Hidden = ['period' => '7_days', 'from' => 'a', 'to' => 'b', 'sections' => ['tasks'], 'team' => null, 'you' => ['tasks_open' => 1, 'tasks_overdue' => 0, 'tasks_done' => 0], 'people' => null];
check('3.41 review L3: MCP says why the totals are missing; the report block of one or two people has the note and no totals', [
    TeamSummary::compact($r341Hidden),
    Kaleta\Core\Language::runWith('en', fn (): array => TeamSummary::reportPairs(['people' => 2], fn (mixed $v): string => (string) $v), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): array => TeamSummary::reportPairs(['people' => 2], fn (mixed $v): string => (string) $v), 'admin-'),
], [
    ['period' => '7_days', 'from' => 'a', 'to' => 'b', 'team' => null, 'you' => ['tasks_open' => 1, 'tasks_overdue' => 0, 'tasks_done' => 0], 'team_note' => 'Team totals appear from three people.'],
    [['People who did counted work', '2'], ['Team totals appear from three people.', '']],
    [['Lidé se započítanou prací', '2'], ['Součty týmu se zobrazují od tří lidí.', '']],
]);

// L4: a refused update source says exactly why – another port, a name that does not resolve, an internal address
check('3.41 review L4: Updater::refusal names the port, the unresolved name or the private network', Kaleta\Core\Language::runWith('en', fn (): array => [
    Kaleta\Core\Updater::refusal('https://mirror.example:8443/aktualizace.json', false),
    Kaleta\Core\Updater::refusal('https://nowhere.example/aktualizace.json', true),
    Kaleta\Core\Updater::refusal('https://10.0.0.5/aktualizace.json', false),
], 'admin-'), [
    'The update source must use the standard https port 443 – mirror.example uses port 8443. A custom update source must be an https address on a public server.',
    'The name of the update source does not resolve (nowhere.example).',
    'The update source 10.0.0.5 leads to an address in a private or internal network. A custom update source must be an https address on a public server.',
]);

// L6 (N115): every new list is the 3.35 format – 64-bit codes, HMAC-SHA256 under the list's own salt, never a plain sha256;
// only a list made before 3.35 still compares plain sha256, until it is replaced
[$r341Codes, $r341Stored] = Kaleta\Core\Totp::backupCodes();
$r341List = json_decode($r341Stored, true);
check('3.41 review L6: a new backup-code list is v2 with a salt and no plain sha256 of any code; a legacy list still verifies', [
    $r341List['v'] ?? null, strlen((string) ($r341List['salt'] ?? '')), count($r341Codes), preg_match('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/', $r341Codes[0]),
    array_intersect(array_map(fn (string $c): string => hash('sha256', $c), $r341Codes), (array) ($r341List['codes'] ?? [])),
    array_intersect(array_map(fn (string $c): string => hash('sha256', Kaleta\Core\BackupCodes::normalise($c)), $r341Codes), (array) ($r341List['codes'] ?? [])),
    Kaleta\Core\BackupCodes::consume((string) json_encode([hash('sha256', 'abcde12345')]), 'ABCDE12345 '),
], [2, 32, 8, 1, [], [], '[]']);
