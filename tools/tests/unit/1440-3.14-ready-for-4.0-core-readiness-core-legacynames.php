<?php

declare(strict_types=1);

/* ---------- 3.14: Ready for 4.0 (Core\Readiness, Core\LegacyNames, Core\RedirectSources) ---------- */
check('3.14 Names::isLegacy – only the Czech names of tables 4.0 renames', [Kaleta\Core\Names::isLegacy('novinky'), Kaleta\Core\Names::isLegacy('novinky_stitky'), Kaleta\Core\Names::isLegacy('news'),
    Kaleta\Core\Names::isLegacy('events'), Kaleta\Core\Names::isLegacy('media'), Kaleta\Core\Names::legacy()['presmerovani'] ?? null], [true, true, false, false, false, 'redirects']);
check('3.14 LegacyNames::external – add-ons and layouts are logged; system/, tools/, root scripts, other places and the command line are not', [
    Kaleta\Core\LegacyNames::external('/srv/site/extensions/shop/Extension.php', '/srv/site'), Kaleta\Core\LegacyNames::external('/srv/site/layout/old/base.php', '/srv/site/'),
    Kaleta\Core\LegacyNames::external('/srv/site/system/src/Core/Db.php', '/srv/site'), Kaleta\Core\LegacyNames::external('/srv/site/tools/test.php', '/srv/site'),
    Kaleta\Core\LegacyNames::external('/srv/site/index.php', '/srv/site'), Kaleta\Core\LegacyNames::external('/srv/other/x.php', '/srv/site'),
    Kaleta\Core\LegacyNames::external('Command line code', '/srv/site'), Kaleta\Core\LegacyNames::addon('extensions/shop/src/Orders.php'), Kaleta\Core\LegacyNames::addon('layout/old/base.php')],
    ['extensions/shop/Extension.php', 'layout/old/base.php', false, false, false, false, false, 'shop', '']);
$legacyLog314 = sys_get_temp_dir() . '/kaleta-legacy-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($legacyLog314, json_encode(['extensions/gone/Old.php' => ['names' => ['stranky' => date('Y-m-d', strtotime('-40 days'))]], 'bad' => 'x']));
Kaleta\Core\LegacyNames::record('extensions/shop/Extension.php', 'novinky', date('Y-m-d'), $legacyLog314);
$legacyMtime314 = (string) file_get_contents($legacyLog314);
Kaleta\Core\LegacyNames::record('extensions/shop/Extension.php', 'novinky', date('Y-m-d'), $legacyLog314); // the same day again: nothing to write
Kaleta\Core\LegacyNames::record('extensions/shop/Extension.php', 'stranky', date('Y-m-d', strtotime('-1 day')), $legacyLog314);
$legacyRecent314 = Kaleta\Core\LegacyNames::recent($legacyLog314);
check('3.14 LegacyNames::record and recent – one entry per file, names with the last day, old and broken entries dropped, add-on named', [
    count($legacyRecent314), $legacyRecent314[0]['file'] ?? null, $legacyRecent314[0]['addon'] ?? null, $legacyRecent314[0]['names'] ?? null, $legacyRecent314[0]['last'] ?? null,
    str_contains((string) file_get_contents($legacyLog314), 'gone'), $legacyMtime314 !== ''],
    [1, 'extensions/shop/Extension.php', 'shop', ['novinky', 'stranky'], date('Y-m-d'), false, true]);
@unlink($legacyLog314);
// Kaleta's own code is never logged, even with add-ons switched on, and the SQL stays the same
$watching314 = Kaleta\Core\LegacyNames::$watching;
Kaleta\Core\LegacyNames::$watching = true;
$before314 = is_file(Kaleta\Core\LegacyNames::FILE) ? (string) file_get_contents(Kaleta\Core\LegacyNames::FILE) : null;
$sql314 = [$db310->sql('SELECT * FROM {novinky} JOIN {stranky}'), $db310->sql('SELECT * FROM {news}')];
Kaleta\Core\LegacyNames::$watching = $watching314;
check('3.14 Db::sql with add-ons switched on: the same SQL, and a query from tools/ is not logged as an add-on', [$sql314,
    (is_file(Kaleta\Core\LegacyNames::FILE) ? (string) file_get_contents(Kaleta\Core\LegacyNames::FILE) : null) === $before314],
    [['SELECT * FROM `ka_novinky` JOIN `ka_stranky`', 'SELECT * FROM `ka_novinky`'], true]);
check('3.14 Readiness::databaseServer – MySQL 8.0+ and MariaDB 10.6+, the 5.5.5- prefix skipped', array_map(Kaleta\Core\Readiness::databaseServer(...),
    ['8.0.36', '5.7.44-log', '10.6.12-MariaDB-log', '5.5.5-10.5.22-MariaDB', '11.4.2-MariaDB-ubu2404', '26.7.0', '8.4.3']),
    [['MySQL', '8.0.36', true], ['MySQL', '5.7.44', false], ['MariaDB', '10.6.12', true], ['MariaDB', '10.5.22', false], ['MariaDB', '11.4.2', true], ['MySQL', '26.7.0', true], ['MySQL', '8.4.3', true]]);
$to314 = ' TO `shop`@`localhost`';
check('3.14 Readiness::missingPrivileges – ALL, a list, a pattern, table grants, column lists, roles and nothing readable', [
    Kaleta\Core\Readiness::missingPrivileges(['GRANT ALL PRIVILEGES ON *.*' . $to314 . ' WITH GRANT OPTION'], 'shop'),
    Kaleta\Core\Readiness::missingPrivileges(['GRANT USAGE ON *.*' . $to314, 'GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.*' . $to314], 'shop'),
    Kaleta\Core\Readiness::missingPrivileges(['GRANT USAGE ON *.*' . $to314, 'GRANT ALL ON `shop\\_%`.*' . $to314], 'shop_live'),
    Kaleta\Core\Readiness::missingPrivileges(['GRANT USAGE ON *.*' . $to314, 'GRANT ALL ON `shop\\_%`.*' . $to314], 'shopxlive'),
    Kaleta\Core\Readiness::missingPrivileges(['GRANT USAGE ON *.*' . $to314, 'GRANT ALL ON `shop`.`ka_novinky`' . $to314], 'shop'),
    Kaleta\Core\Readiness::missingPrivileges(['GRANT SELECT (a, b), INSERT, ALTER, CREATE, DROP ON shop.*' . $to314], 'shop'),
    Kaleta\Core\Readiness::missingPrivileges(['GRANT USAGE ON *.*' . $to314, 'GRANT `app_role`@`%`' . $to314], 'shop'),
    Kaleta\Core\Readiness::missingPrivileges([], 'shop'), Kaleta\Core\Readiness::missingPrivileges(['nonsense'], 'shop'),
    Kaleta\Core\Readiness::schemaMatches('sh_p%', 'shopping'), Kaleta\Core\Readiness::schemaMatches('Shop', 'shop')],
    [[], ['ALTER', 'CREATE', 'DROP'], [], ['ALTER', 'CREATE', 'DROP', 'INSERT'], ['ALTER', 'CREATE', 'DROP', 'INSERT'], [], null, null, null, true, true]);
$scan314 = sys_get_temp_dir() . '/kaleta-scan-' . bin2hex(random_bytes(4));
foreach (['extensions/shop/vendor/lib', 'extensions/shop/src', 'extensions/clean', 'layout/old'] as $folder314) {
    mkdir($scan314 . '/' . $folder314, 0777, true);
}
file_put_contents($scan314 . '/extensions/shop/Extension.php', '<?php $db->all("SELECT * FROM {novinky} n JOIN {news} x"); $db->one("SELECT * FROM {novinky}"); $db->all("SELECT * FROM ka_novinky_stitky, ka_newsletters, ka_events");');
file_put_contents($scan314 . '/extensions/shop/src/install.sql', 'CREATE TABLE IF NOT EXISTS {ext_shop_x} (id INT); INSERT INTO {stranky} SELECT 1;');
file_put_contents($scan314 . '/extensions/shop/vendor/lib/Big.php', '<?php // {novinky} in a bundled library is not looked at');
file_put_contents($scan314 . '/extensions/shop/notes.txt', '{novinky}');
file_put_contents($scan314 . '/extensions/clean/Extension.php', '<?php $db->all("SELECT * FROM {news} JOIN {pages} JOIN {events}"); // stranky, novinky as words');
file_put_contents($scan314 . '/layout/old/base.php', '<?php // {kategorie}');
$scanned314 = Kaleta\Core\Readiness::scan($scan314, ['extensions', 'layout', 'missing'], 'ka_');
$bounded314 = Kaleta\Core\Readiness::scan($scan314, ['extensions'], 'ka_', 2);
check('3.14 Readiness::scan – old placeholders and prefixed names of renamed tables per file; English names, other tables, other file types and vendor/ are left out; bounded', [
    $scanned314['files'], $scanned314['complete'], [$bounded314['complete'], $bounded314['seen']], count(Kaleta\Core\Readiness::scan($scan314, ['extensions'], '')['files'][0]['names'] ?? [])],
    [[['file' => 'extensions/shop/Extension.php', 'placeholders' => 2, 'tables' => 1, 'names' => ['{novinky}', 'ka_novinky_stitky']],
        ['file' => 'extensions/shop/src/install.sql', 'placeholders' => 1, 'tables' => 0, 'names' => ['{stranky}']],
        ['file' => 'layout/old/base.php', 'placeholders' => 1, 'tables' => 0, 'names' => ['{kategorie}']]], true, [false, 2], 1]);
// N314-3: a folder PHP may not open neither stops the page nor hides what the other folders hold
mkdir($scan314 . '/extensions/locked/inner', 0777, true);
chmod($scan314 . '/extensions/locked', 0);
try {
    $locked314 = Kaleta\Core\Readiness::scan($scan314, ['extensions', 'layout'], 'ka_');
} catch (\Throwable $e) {
    $locked314 = ['files' => [], 'error' => $e::class];
}
chmod($scan314 . '/extensions/locked', 0777);
check('3.14 Readiness::scan (N314-3) – an unreadable folder is skipped, the rest is still found', [isset($locked314['error']), count($locked314['files'])], [false, 3]);
exec('rm -rf ' . escapeshellarg($scan314));
check('3.14 RedirectSources::normalize – the stored form in lower case: slashes, percent-encoding and case folded, accents and the language prefix kept', array_map(Kaleta\Core\RedirectSources::normalize(...),
    ['/Stara-Adresa/', 'ko%C4%8Dka', 'EN/Stary', '/?p=12', 'Café', 'https://old.example.com/Blog/Post/', 'blog/*']),
    ['stara-adresa', 'kočka', 'en/stary', '?p=12', 'café', 'blog/post', 'blog/*']);
check('3.14 the background job that normalises redirect sources is listed', [Kaleta\Core\Scheduler::JOBS['redirect_sources'][0] ?? null, Kaleta\Core\Scheduler::JOBS['redirect_sources'][1] ?? null], [0, 'any']);
// 3.15: the update to 4.0 waits only for the blocking checks (database server, rights); other warnings ask for care
$checks315 = [['key' => 'database_version', 'nazev' => 'Database server', 'stav' => 'ok', 'info' => '', 'seznam' => []],
    ['key' => 'database_privileges', 'nazev' => 'Database user rights', 'stav' => 'varovani', 'info' => '', 'seznam' => []],
    ['key' => 'legacy_table_names', 'nazev' => 'Add-ons and old table names', 'stav' => 'varovani', 'info' => '', 'seznam' => []],
    ['key' => 'shared_slugs', 'nazev' => 'Addresses shared by language versions', 'stav' => 'info', 'info' => '', 'seznam' => []]];
check('3.15 Readiness::blockers – only a warning of the database server or its rights stops the 4.0 update', [Kaleta\Core\Readiness::blockers($checks315),
    Kaleta\Core\Readiness::blockers(array_map(fn (array $c): array => ['stav' => $c['key'] === 'database_privileges' ? 'ok' : $c['stav']] + $c, $checks315))],
    [['Database user rights'], []]);
