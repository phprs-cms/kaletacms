<?php

declare(strict_types=1);

use Kaleta\Core\MoveStatus;

/* ---------- 3.33 part A (M7): the Move block – where a move stands, counted from what the importers and the report know ---------- */
$i333Report = [
    ['stara' => '/', 'stav' => 'sample', 'problemy' => ['sample_page'], 'formulare' => ['email']],
    ['stara' => '/a', 'stav' => 'hidden', 'problemy' => ['hidden', 'form_missing'], 'formulare' => ['email']],
    ['stara' => '/b', 'stav' => 'ok', 'problemy' => ['form_missing', 'title_changed'], 'formulare' => ['email', 'jmeno,zprava']],
    ['stara' => '/c', 'stav' => 'missing', 'problemy' => ['missing'], 'formulare' => ['email']],
    'junk',
];
check('3.33 M7 MoveStatus::counts: errors and warnings as the report counts them, the site-wide form once, the sample pages', MoveStatus::counts($i333Report),
    ['errors' => 3, 'warnings' => 1, 'on_sample_pages' => 1, 'forms_missing' => 1, 'site_wide_forms' => 1, 'clean' => false]);
check('3.33 M7 MoveStatus::counts: a report without problems is clean', MoveStatus::counts([['stara' => '/', 'stav' => 'ok', 'problemy' => [], 'formulare' => []]])['clean'], true);
$i333Audit = (string) file_get_contents(KALETA_ROOT . '/system/views/admin/audit/list.php');
check('3.33 M7: Site audit draws the Move block before the hand-over checklist', strpos($i333Audit, 'id="move"') !== false && strpos($i333Audit, 'id="move"') < strpos($i333Audit, 'id="predani-nadpis"'), true);
