<?php

declare(strict_types=1);

use Kaleta\Core\StarterPages;

/* ---------- 3.33 review H1: a page recognised only by the legacy rule is replaced only when chosen ---------- */
$i333hChoices = [
    ['old' => '/', 'page_id' => 1, 'title' => 'Úvod', 'address' => '/uvod', 'replace' => false, 'why' => 'legacy', 'how' => StarterPages::LEGACY],
    ['old' => '/kontakt', 'page_id' => 4, 'title' => 'Kontakt', 'address' => '/kontakt', 'replace' => false, 'why' => 'legacy', 'how' => StarterPages::LEGACY],
    ['old' => '/sluzby', 'page_id' => 3, 'title' => 'Služby', 'address' => '/sluzby', 'replace' => false, 'why' => 'edited', 'how' => StarterPages::RECORDED],
    ['old' => '/o-nas', 'page_id' => 2, 'title' => 'O nás', 'address' => '/o-nas', 'replace' => true, 'why' => '', 'how' => StarterPages::RECORDED],
];
check('3.33 review H1 replace_starter_pages: only legacy pages, each named by its old or its own address – "*", true, a recorded or an edited page do not count', [
    StarterPages::replaceFromAddresses($i333hChoices, ['kontakt/']), StarterPages::replaceFromAddresses($i333hChoices, ['/uvod', '/kontakt']),
    StarterPages::replaceFromAddresses($i333hChoices, ['*']), StarterPages::replaceFromAddresses($i333hChoices, true),
    StarterPages::replaceFromAddresses($i333hChoices, ['/sluzby', '/o-nas']), StarterPages::replaceFromAddresses($i333hChoices, '/kontakt'),
], [[4], [1, 4], [], [], [], []]);
check('3.33 review H1: the import result says why a legacy page was kept', StarterPages::summary(['ponechano' => [['/kontakt', 'Kontakt', '/kontakt', 'legacy']]])['kept'][0]['why'],
    'recognised only by the titles of an older installation, so it is replaced only when chosen (replace_starter_pages) – the imported page got a number');
$i333hSource = (string) file_get_contents(KALETA_ROOT . '/system/src/Core/StarterPages.php');
check('[source-pin] 3.33 review H1: the legacy rule proves the installer\'s row – shared stamp after the first account, no versions, no import map, no imported Kaleta export', [
    str_contains($i333hSource, 'SELECT potvrzeno FROM {uzivatele} ORDER BY idu LIMIT 1'), str_contains($i333hSource, '{stranky_revize} v WHERE v.ids = s.ids'),
    str_contains($i333hSource, "{import_mapa} m WHERE m.typ = 'stranka' AND m.nase_id = s.ids"), str_contains($i333hSource, "akce = 'import of a Kaleta export'"),
    str_contains($i333hSource, "count(array_unique(array_column(\$rows, 'zmeneno'))) === 1"),
], [true, true, true, true, true]);
check('[source-pin] 3.33 review H1: "Start from an export" and the Kaleta import write an empty record (no sample pages, no legacy rule)', [
    str_contains((string) file_get_contents(KALETA_ROOT . '/system/src/Install/Installer.php'), "StarterPages::SETTING => '{}'"),
    substr_count((string) file_get_contents(KALETA_ROOT . '/system/src/Core/SiteImport.php'), "set(StarterPages::SETTING, '{}')"),
], [true, 2]);
