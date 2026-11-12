<?php

declare(strict_types=1);

use Kaleta\Core\StarterPages;

/* ---------- 3.33 review M2: a hidden imported home page leaves the starter home page in place ---------- */
$i333mWaiting = StarterPages::summary(['nahrazeno' => [], 'ponechano' => [], 'domov' => ['/', 'Úvod', '/uvod', 12, '/home']]);
check('3.33 review M2: the import result names the waiting home page, the imported page and what to do', [
    array_diff_key($i333mWaiting['home_waiting'] ?? [], ['what_to_do' => 1]), str_contains($i333mWaiting['home_waiting']['what_to_do'] ?? '', 'update_settings home_page'),
    array_key_exists('home_waiting', StarterPages::summary(['nahrazeno' => [], 'ponechano' => []])), StarterPages::homeWaiting([]),
], [['title' => 'Úvod', 'address' => '/uvod', 'page_id' => 12, 'imported_address' => '/home'], true, false, null]);
check('[source-pin] 3.33 review M2: both importers pass the visibility of the imported page – the website import always hidden', [
    str_contains((string) file_get_contents(KALETA_ROOT . '/system/src/Core/WebImport.php'), 'self::keptStarters($state), $state[\'vzory\'], self::replacedStarters($state), false);'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/system/src/Core/WpImport.php'), '(bool) $articleStatus[\'visible\']); // 3.33 review M2'),
], [true, true]);

/* ---------- 3.33 review L3: a starter page that has translations counts as edited ---------- */
check('[source-pin] 3.33 review L3: both rules leave out a starter page with a translation', substr_count((string) file_get_contents(KALETA_ROOT . '/system/src/Core/StarterPages.php'),
    'NOT EXISTS (SELECT 1 FROM {stranky} t WHERE t.preklad_z = s.ids)'), 2);
