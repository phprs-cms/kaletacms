<?php

declare(strict_types=1);

use Kaleta\Core\WpImport;

/* ---------- 3.33 review L7: a WordPress import analysed on 3.32 and confirmed on 3.33 replaces nothing ---------- */
$i333wFresh = WpImport::newState('topeni-dub.xml');
$i333wOld = $i333wFresh;
unset($i333wOld['prehled']['cile_stranek']);
check('3.33 review L7: a 3.33 analysis always records where the pages land; an older state without it shows and replaces no starter page', [
    WpImport::previewedStarters($i333wFresh), WpImport::previewedStarters($i333wOld), WpImport::previewedStarters([]),
    str_contains((string) file_get_contents(KALETA_ROOT . '/system/src/Core/WpImport.php'), '$starter = !self::previewedStarters($state) ? null : StarterPages::makeRoom('),
], [true, false, false, true]);
