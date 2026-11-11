<?php

declare(strict_types=1);

use Kaleta\Core\MigrationReport;

/* ---------- 3.33 part A (M5, M6): an honest migration report ---------- */
check('3.33 M5: an old address on an untouched sample page is an error, a changed title a note', [MigrationReport::PROBLEMS['sample_page'], MigrationReport::PROBLEMS['title_changed'],
    MigrationReport::describe('sample_page') !== 'sample_page', MigrationReport::describe('title_changed') !== 'title_changed'], ['error', 'info', true, true]);
check('3.33 M5 sameTitle: the site name around the old <title> does not count, letter case and spaces neither; a real change does', [
    MigrationReport::sameTitle('Bespoke kitchens – Northwind Joinery', 'Bespoke kitchens'), MigrationReport::sameTitle('Kontakt | Topení Dub', 'kontakt'),
    MigrationReport::sameTitle('Services | Acme', 'Our services'), MigrationReport::sameTitle('', 'Anything'), MigrationReport::sameTitle('A &amp; B', 'A & B'),
], [true, true, false, true, true]);
$i333Rows = [];
foreach (['/', '/a', '/b', '/c', '/d'] as $i => $old) {
    $i333Rows[] = ['stara' => $old, 'stav' => 'ok', 'problemy' => $i === 4 ? ['form_missing'] : ($i === 3 ? ['not_read'] : ['form_missing']),
        'formulare' => $i === 3 ? [] : ($i === 4 ? ['email,message', 'email'] : ['email'])];
}
check('3.33 M5 siteWideForms: a form on most old pages read (at least three) is the site\'s own; pages not read do not count', [
    MigrationReport::siteWideForms($i333Rows), MigrationReport::siteWideForms(array_slice($i333Rows, 0, 2)),
], [['email' => 4], []]);
check('3.33 M5: form_missing stays only on a page with a form of its own; a page with just the site-wide form loses it', [
    MigrationReport::withoutSiteWideForm($i333Rows[1], ['email'])['problemy'], MigrationReport::withoutSiteWideForm($i333Rows[4], ['email'])['problemy'],
    MigrationReport::withoutSiteWideForm($i333Rows[1], [])['problemy'],
], [[], ['form_missing'], ['form_missing']]);
check('3.33 M6: an unreachable old site is said plainly in the notes of the import and the report', [
    count(Kaleta\Core\WebImport::notes(['nedostupny' => 'The old site is not responding.'])), str_contains(implode(' ', Kaleta\Core\WebImport::notes(['nedostupny' => 'x'])), 'could not be reached'),
    Kaleta\Core\WebImport::notes([]),
], [1, true, []]);
