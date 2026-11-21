<?php

declare(strict_types=1);

use Kaleta\Core\Assignment;
use Kaleta\Core\Booking;
use Kaleta\Core\Extensions;
use Kaleta\Core\InternalFileLinks;
use Kaleta\Mcp\Tools;

/* ---------- 3.49 B: one absence (an absence of the linked account closes booking times) ---------- */
// Core\Booking::calendar() turns each absence of a person's linked account into an off range of whole days of the site's
// clock (absenceRange) – the free-time calculation (free(), pure) then leaves those days empty. Read, never copied.
check('3.49 B: an absence is an off range from 00:00 of its first day to 00:00 of the day after its last – over a month, a year and a change of summer time', [
    Booking::absenceRange('2026-11-24', '2026-11-24'), Booking::absenceRange('2026-11-30', '2026-12-31'), Booking::absenceRange('2026-03-28', '2026-03-29'), Booking::absenceRange('2028-02-28', '2028-02-28')],
    [['2026-11-24 00:00:00', '2026-11-25 00:00:00'], ['2026-11-30 00:00:00', '2027-01-01 00:00:00'], ['2026-03-28 00:00:00', '2026-03-30 00:00:00'], ['2028-02-28 00:00:00', '2028-02-29 00:00:00']]);

$b449Free = static function (string $day, array $off, string $now, string $zone = 'Europe/Prague', array $ranges = [['09:00', '12:00']], int $lead = 0): array {
    return Booking::free($ranges, [], $off, 60, 0, 60, $day, new DateTimeImmutable($now, new DateTimeZone($zone)), $lead, 60);
};
$b449Tomorrow = [Booking::absenceRange('2026-11-24', '2026-11-24')];
check('3.49 B: a one-day absence – that day has no free time, the day before and after are unchanged, a person without it is untouched', [
    $b449Free('2026-11-24', $b449Tomorrow, '2026-11-23 08:00'), $b449Free('2026-11-23', $b449Tomorrow, '2026-11-23 08:00'), $b449Free('2026-11-25', $b449Tomorrow, '2026-11-23 08:00'),
    $b449Free('2026-11-24', [], '2026-11-23 08:00')],
    [[], ['09:00', '10:00', '11:00'], ['09:00', '10:00', '11:00'], ['09:00', '10:00', '11:00']]);
check('3.49 B day edges: an absence that starts today closes the times still to come today (without it 11:00 is free at 10:30)', [
    $b449Free('2026-11-23', [Booking::absenceRange('2026-11-23', '2026-11-26')], '2026-11-23 10:30'), $b449Free('2026-11-23', [], '2026-11-23 10:30')],
    [[], ['11:00']]);
check('3.49 B day edges: the days are the site\'s clock – the same absence in New York closes the New York day, not a day of the server', [
    $b449Free('2026-11-24', $b449Tomorrow, '2026-11-23 22:30', 'America/New_York'), $b449Free('2026-11-25', $b449Tomorrow, '2026-11-23 22:30', 'America/New_York'),
    $b449Free('2026-11-23', $b449Tomorrow, '2026-11-23 07:00', 'America/New_York')],
    [[], ['09:00', '10:00', '11:00'], ['09:00', '10:00', '11:00']]);
$b449Day = [['00:00', '24:00']];
$b449Spring = $b449Free('2026-03-29', [], '2026-03-01 08:00', 'Europe/Prague', $b449Day);
check('3.49 B day edges: the day summer time starts – an absence the day before or after leaves its 23 hours free, an absence on it closes them all', [
    count($b449Spring), $b449Free('2026-03-29', [Booking::absenceRange('2026-03-28', '2026-03-28')], '2026-03-01 08:00', 'Europe/Prague', $b449Day) === $b449Spring,
    $b449Free('2026-03-29', [Booking::absenceRange('2026-03-30', '2026-03-30')], '2026-03-01 08:00', 'Europe/Prague', $b449Day) === $b449Spring,
    $b449Free('2026-03-29', [Booking::absenceRange('2026-03-29', '2026-03-29')], '2026-03-01 08:00', 'Europe/Prague', $b449Day),
    count($b449Free('2026-10-25', [Booking::absenceRange('2026-10-24', '2026-10-24')], '2026-10-01 08:00', 'Europe/Prague', $b449Day))],
    [23, true, true, [], 24]);

// the absence days come as days only – between() answers [first day, last day] per account, never the reason or the deputy
check('3.49 B: Absences::between and the booking helpers – a list of days per account; nothing for no account', [
    Kaleta\Core\Absences::between(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''), [], '2026-11-01', '2026-11-30'),
    Kaleta\Core\Absences::between(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''), [0, -3], '2026-11-01', '2026-11-30'),
    (new ReflectionMethod(Kaleta\Core\Absences::class, 'between'))->getReturnType()?->getName(), method_exists(Booking::class, 'duringAbsence'), method_exists(Booking::class, 'linkByEmail')],
    [[], [], 'array', true, true]);

/* ---------- 3.49 B: files on a record ---------- */
check('3.49 B: the link kinds are words of Assignment::KINDS with the same section, and their features exist', [
    array_values(array_diff(array_keys(InternalFileLinks::KINDS), array_keys(Assignment::KINDS))),
    array_map(fn (string $k): bool => InternalFileLinks::KINDS[$k][0] === Assignment::KINDS[$k], array_keys(InternalFileLinks::KINDS)),
    array_values(array_filter(array_column(InternalFileLinks::KINDS, 1), fn (string $f): bool => !isset(Extensions::CATALOG[$f]))), InternalFileLinks::PER_RECORD],
    [[], [true, true, true, true], [], 100]);
check('3.49 B: a target is kind:id of a known kind – anything else is refused before any query', [
    InternalFileLinks::parse('deal:12'), InternalFileLinks::parse(' contact : 7 '), InternalFileLinks::parse('enquiry:3'), InternalFileLinks::parse('deal:0'), InternalFileLinks::parse('deal:12;drop'),
    InternalFileLinks::parse(12), InternalFileLinks::logText(5, 'deal', 12)],
    [['deal', 12], ['contact', 7], null, null, null, null, '#5 → deal #12']);

// the guard: the three tables of the library only in Core\InternalFiles, the link table only in Core\InternalFileLinks
$b449Found = ['files' => [], 'links' => []];
foreach (['src', 'views'] as $b449Folder) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/' . $b449Folder, FilesystemIterator::SKIP_DOTS)) as $b449Path) {
        if (!str_ends_with((string) $b449Path, '.php')) {
            continue;
        }
        $b449Source = (string) file_get_contents((string) $b449Path);
        $b449Name = substr((string) $b449Path, strlen(KALETA_SYSTEM) + 1);
        if (preg_match('/\{internal_(?:folders|files|file_versions)\}|\bka_internal_(?:folders|files|file_versions)\b|->(?:insert|update|delete)\(\s*\'internal_(?:folders|files|file_versions)\'/', $b449Source) === 1) {
            $b449Found['files'][] = $b449Name;
        }
        if (preg_match('/\{internal_file_links\}|\bka_internal_file_links\b|->(?:insert|update|delete)\(\s*\'internal_file_links\'/', $b449Source) === 1) {
            $b449Found['links'][] = $b449Name;
        }
    }
}
check('3.49 B: the tables of internal files are named only by Core\InternalFiles, the link table only by Core\InternalFileLinks', $b449Found,
    ['files' => ['src/Core/InternalFiles.php'], 'links' => ['src/Core/InternalFileLinks.php']]);
check('3.49 B: the link table is in the name map and the time-zone conversion; never journaled for undo (with the other tables of internal files)', [
    Kaleta\Core\Names::english('internal_file_links'), in_array('internal_file_links', Kaleta\Core\AgentJournal::PERSONAL_TABLES, true),
    Kaleta\Core\AgentJournal::journaled('internal_file_links'), in_array('internal_file_links', Kaleta\Core\AgentJournal::TABLES, true),
    Kaleta\Core\TimeZoneConversion::INSTANTS['internal_file_links'] ?? null],
    ['internal_file_links', true, false, false, ['created_at']]);

// MCP: on-demand tools only (the work set has no room), and the description says links are live and not taken back by undo
$b449Defs = Tools::definitions();
$b449Save = array_values(array_filter($b449Defs, fn (array $d): bool => $d['name'] === 'save_internal_file'))[0] ?? [];
$b449List = array_values(array_filter($b449Defs, fn (array $d): bool => $d['name'] === 'list_internal_files'))[0] ?? [];
check('3.49 B: save_internal_file takes link and unlink, list_internal_files takes linked_to; links are live, refused for drafts only, never taken back by undo', [
    isset($b449Save['inputSchema']['properties']['link'], $b449Save['inputSchema']['properties']['unlink']), isset($b449List['inputSchema']['properties']['linked_to']),
    str_contains((string) ($b449Save['description'] ?? ''), 'undo_agent_session does not take it back'), str_contains((string) ($b449Save['description'] ?? ''), 'refused for drafts only'),
    in_array('save_internal_file', Kaleta\Mcp\Catalog::ON_DEMAND, true), in_array('list_internal_files', Kaleta\Mcp\Catalog::ON_DEMAND, true)],
    [true, true, true, true, true, true]);
