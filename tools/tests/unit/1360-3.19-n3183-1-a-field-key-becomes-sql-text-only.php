<?php

declare(strict_types=1);

/* ---------- 3.19 (N3183-1): a field key becomes SQL text only after KEY_PATTERN ---------- */
check('3.19 (N3183-1): Collections::jsonField builds the JSON path only for a field key', array_map(fn (string $k): ?string => Kaleta\Builder\Collections::jsonField($k),
    ['stav', "x') OR 1=1 -- ", 'a-b', '9a', 'a.b', 'a[0]', '', str_repeat('a', 32)]),
    ["JSON_UNQUOTE(JSON_EXTRACT(data, '$.stav'))", null, null, null, null, null, null, null]);
$jsonSplices319 = [];
foreach (['Admin/Modules/Collections.php', 'Core/Calendar.php', 'Core/Documents.php', 'Front/Kernel.php'] as $file319) {
    if (preg_match_all('/JSON_EXTRACT\(data, \'\$\.\" \./', (string) file_get_contents(KALETA_SYSTEM . '/src/' . $file319)) > 0) {
        $jsonSplices319[] = $file319;
    }
}
check('3.19 (N3183-1): the item filters of the admin, the calendar job, iCalendar and document downloads build the path through jsonField', $jsonSplices319, []);
