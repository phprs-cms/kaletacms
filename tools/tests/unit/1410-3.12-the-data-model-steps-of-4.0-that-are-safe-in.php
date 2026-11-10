<?php

declare(strict_types=1);

/* ---------- 3.12: the data model steps of 4.0 that are safe in 3.x (docs/design/4.0-data-model.md §1.2, §1.3, §6) ---------- */
// §1.2: lists sort in the site language; Czech sends the same SQL as before, a missing collation falls back
$both312 = ['utf8mb4_czech_ci', 'utf8mb4_slovak_ci', 'utf8mb4_polish_ci', 'utf8mb4_german2_ci', 'utf8mb4_danish_ci', 'utf8mb4_swedish_ci', 'utf8mb4_estonian_ci', 'utf8mb4_hungarian_ci',
    'utf8mb4_icelandic_ci', 'utf8mb4_latvian_ci', 'utf8mb4_lithuanian_ci', 'utf8mb4_romanian_ci', 'utf8mb4_slovenian_ci', 'utf8mb4_croatian_ci', 'utf8mb4_turkish_ci', 'utf8mb4_spanish_ci',
    'utf8mb4_vietnamese_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci']; // legacy UCA names MySQL 8 and MariaDB 10.6 both have
check('3.12 §1.2: every collation the sort helper names exists on MySQL 8 and on MariaDB 10.6', array_values(array_diff(array_merge(Kaleta\Core\Collation::FALLBACK, ...array_values(Kaleta\Core\Collation::LANGUAGES)), $both312)), []);
check('3.12 §1.2: Collation::choose – Czech needs no COLLATE, regional tags follow their language, others are language-neutral, a missing one falls back', [
    Kaleta\Core\Collation::choose('cs', $both312), Kaleta\Core\Collation::choose('sk', $both312), Kaleta\Core\Collation::choose('de-at', $both312), Kaleta\Core\Collation::choose('en-gb', $both312),
    Kaleta\Core\Collation::choose('ar', $both312), Kaleta\Core\Collation::choose('pt-br', $both312), Kaleta\Core\Collation::choose('sr', $both312), Kaleta\Core\Collation::choose('fi', $both312),
    Kaleta\Core\Collation::choose('pl', ['utf8mb4_unicode_ci']), Kaleta\Core\Collation::choose('en', ['utf8mb4_general_ci']), Kaleta\Core\Collation::choose('en', [])],
    ['', 'utf8mb4_slovak_ci', 'utf8mb4_german2_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4_croatian_ci', 'utf8mb4_swedish_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci', '']);
$offline312 = new Kaleta\Core\Db('mysql:host=127.0.0.1;port=1;dbname=none', 'none', ''); // never connects: Czech asks the server nothing
check('3.12 §1.2: a Czech site sorts with the very same SQL (no COLLATE, no question to the server)', [$offline312->sortText('titulek', 'cs'), $offline312->sortText('p.nazev', 'cs')], ['titulek', 'p.nazev']);
// the alphabetical lists people read go through the helper; ORDER BY a name without it is only allowed for identifiers
$plainSort312 = [];
foreach (['Core/Booking.php', 'Core/Menu.php', 'Admin/Modules/Pages.php', 'Admin/Modules/Tags.php', 'Admin/Modules/Categories.php', 'Admin/Modules/Collections.php', 'Admin/Modules/Users.php', 'Admin/Modules/Roles.php',
    'Admin/Modules/Media.php', 'Builder/Collections.php', 'Builder/Components.php', 'Builder/Popups.php', 'Front/Seo.php', 'Front/Kernel.php', 'Mcp/Handlers/PageTools.php', 'Mcp/Handlers/CollectionTools.php'] as $file) {
    if (preg_match_all('/ORDER BY[^\'"]*?(?<![.\w])(?:[a-z]\.)?(nazev|titulek|jmeno|name)\b(?!\'?\))/', (string) file_get_contents(KALETA_SYSTEM . '/src/' . $file), $m) > 0) {
        $plainSort312[] = $file . ': ' . implode(', ', $m[0]);
    }
}
check('3.12 §1.2: lists of names sort through Db::sortText', $plainSort312, []);
// §1.3: tokens and hashes in utf8mb4_bin – migration 0092 and schema.sql say the same for every column
preg_match_all('/MODIFY (\w+) ([A-Z]+\(\d+\) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin (?:NOT )?NULL(?: DEFAULT \'\')?)/', (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0092-token-collation.sql'), $m312, PREG_SET_ORDER);
$schema312 = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
check('3.12 §1.3: the 20 token and hash columns of migration 0092 are utf8mb4_bin in schema.sql too', [count($m312), array_values(array_filter(array_map(
    fn (array $c): string => preg_match('/\n\s+' . $c[1] . '\s+' . str_replace(' ', '\s+', preg_quote($c[2], '/')) . '[\s,]/', $schema312) === 1 ? '' : $c[1], $m312)))], [20, []]);
// §6: every DATETIME and DATE column is either a moment (converted with the time zone) or a civil date or time (kept)
$dateColumns312 = [];
foreach (preg_split('/(?=CREATE TABLE )/', $schema312) ?: [] as $tableSql) {
    if (preg_match('/^CREATE TABLE ka_(\w+)/', $tableSql, $t) === 1 && preg_match_all('/\n\s+`?(\w+)`?\s+(?:DATETIME|DATE)\b/', $tableSql, $c) > 0) {
        foreach ($c[1] as $column) {
            $dateColumns312[] = $t[1] . '.' . $column;
        }
    }
}
$classified312 = [];
foreach ([Kaleta\Core\TimeZoneConversion::INSTANTS, Kaleta\Core\TimeZoneConversion::CIVIL] as $list) {
    foreach ($list as $table => $columns) {
        foreach ($columns as $column) {
            $classified312[] = $table . '.' . $column;
        }
    }
}
sort($dateColumns312);
$sortedClassified312 = $classified312;
sort($sortedClassified312);
check('3.12 §6: every DATETIME and DATE column of schema.sql is classified once – instant (converted) or civil (kept)',
    [array_values(array_diff($dateColumns312, $classified312)), array_values(array_diff($classified312, $dateColumns312)), count($classified312) === count(array_unique($classified312))], [[], [], true]);
$prague312 = new DateTimeZone('Europe/Prague');
$london312 = new DateTimeZone('Europe/London');
check('3.12 §6: TimeZoneConversion::convert – by the offset at each value\'s own date, the repeated autumn hour counted, empty and impossible values left alone', [
    Kaleta\Core\TimeZoneConversion::convert('2026-10-11 12:00:00', $prague312, $london312), Kaleta\Core\TimeZoneConversion::convert('2026-01-15 12:00:00', $prague312, new DateTimeZone('America/New_York')),
    Kaleta\Core\TimeZoneConversion::convert('2026-10-25 02:30:00', $prague312, $london312), Kaleta\Core\TimeZoneConversion::convert('2026-10-25 03:30:00', $prague312, $london312),
    Kaleta\Core\TimeZoneConversion::convert('2026-07-01 08:00:00', $london312, $prague312), Kaleta\Core\TimeZoneConversion::convert(null, $prague312, $london312),
    Kaleta\Core\TimeZoneConversion::convert('0000-00-00 00:00:00', $prague312, $london312), Kaleta\Core\TimeZoneConversion::convert('2026-03-29 02:30:00', $prague312, $london312),
    Kaleta\Core\TimeZoneConversion::convert('2026-02-30 10:00:00', $prague312, $london312)],
    [['2026-10-11 11:00:00', false, false], ['2026-01-15 06:00:00', false, false], ['2026-10-25 01:30:00', true, false], ['2026-10-25 02:30:00', false, false], ['2026-07-01 09:00:00', false, false], null, null, null, null]);
// 3.12.1 (N312-2): a "never" date moved east, or the first DATETIME moved west, has no value on the new clock – left and counted
check('3.12.1 N312-2: a value that would leave the DATETIME range is left as it is (counted), the edges that stay inside convert', [
    Kaleta\Core\TimeZoneConversion::convert('9999-12-31 23:30:00', new DateTimeZone('Asia/Tokyo'), new DateTimeZone('Pacific/Kiritimati')),
    Kaleta\Core\TimeZoneConversion::convert('1000-01-01 00:30:00', $prague312, $london312),
    Kaleta\Core\TimeZoneConversion::convert('9999-12-31 23:30:00', $prague312, $london312), Kaleta\Core\TimeZoneConversion::convert('1000-01-01 05:00:00', $prague312, $london312)],
    [[null, false, true], [null, false, true], ['9999-12-31 22:30:00', false, false], ['1000-01-01 04:01:01', false, false]]); // the year 1000 runs on local mean time in the zone database
check('3.12 §6: the background job that converts saved dates runs first (the others wait for it)', array_key_first(Kaleta\Core\Scheduler::JOBS), 'time_zone');
