<?php

declare(strict_types=1);

use Kaleta\Core\WpImport;

/* ---------- 3.33 review L7: a WordPress import analysed on 3.32 and confirmed on 3.33 replaces nothing ---------- */
$i333wFresh = WpImport::newState('topeni-dub.xml');
$i333wOld = $i333wFresh;
unset($i333wOld['prehled']['cile_stranek']);
check('[source-pin] 3.33 review L7: a 3.33 analysis always records where the pages land; an older state without it shows and replaces no starter page', [
    WpImport::previewedStarters($i333wFresh), WpImport::previewedStarters($i333wOld), WpImport::previewedStarters([]),
    str_contains((string) file_get_contents(KALETA_ROOT . '/system/src/Core/WpImport.php'), '$starter = !self::previewedStarters($state) ? null : StarterPages::makeRoom('),
], [true, false, false, true]);

/* ---------- 3.33 review L8: read_old_page is listed only for connections that may call it ---------- */
check('3.33 review L8: read_old_page needs what import_website needs – a full connection sees it, drafts-only and read-only do not (3.35 N104: migration_report too)', [
    Kaleta\Mcp\Catalog::allows('full', 'read_old_page'), Kaleta\Mcp\Catalog::allows('drafts', 'read_old_page'), Kaleta\Mcp\Catalog::allows('read', 'read_old_page'),
    Kaleta\Mcp\Catalog::allows('read', 'migration_report'), Kaleta\Mcp\Catalog::allows('drafts', 'list_pages'),
], [true, false, false, false, true]);
