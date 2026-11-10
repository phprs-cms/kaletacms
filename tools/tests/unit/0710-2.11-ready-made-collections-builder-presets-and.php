<?php

declare(strict_types=1);

/* ---------- 2.11: ready-made collections (Builder\Presets) and the date-time, file and location fields ---------- */
$presets = Kaleta\Builder\Presets::all();
$presetProblems = [];
foreach ($presets as $presetKey => $p) {
    $keys = array_column($p['fields'], 0);
    $sanitized = array_column(Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]] + (isset($f[3]['preset']) ? ['kolekce' => 'x'] : []), $p['fields'])), 'klic');
    if ($keys !== $sanitized || count($keys) > 30) {
        $presetProblems[] = $presetKey . ': field keys change when sanitized';
    }
    foreach ($p['fields'] as $f) {
        if (!isset(Kaleta\Builder\Collections::FIELD_TYPES[$f[2]]) || ($f[2] === 'polozka' && !isset($presets[$f[3]['preset'] ?? '']))) {
            $presetProblems[] = $presetKey . ': ' . $f[0] . ' has an unknown type or linked preset';
        }
    }
    if (is_array($p['schema']) && Kaleta\Builder\CollectionSchema::sanitize($p['schema'], Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]], $p['fields'])))['pole'] != $p['schema']['pole']) {
        $presetProblems[] = $presetKey . ': the schema maps a field that does not exist';
    }
    if (trim((string) $p['claude']) === '' || trim((string) $p['description']) === '') {
        $presetProblems[] = $presetKey . ': no description or instructions for Claude';
    }
}
check('2.11 Presets: every preset is valid (keys survive sanitizing, known types, schema maps existing fields, described)', [isset($presets['people']), $presetProblems], [true, []]);
check('2.11 Presets::field – only in a collection made from the preset, and only while the field is there with its type', [
    Kaleta\Builder\Presets::field(['preset' => 'people', 'pole' => [['klic' => 'phone', 'typ' => 'text']]], 'people', 'phone', ['text']),
    Kaleta\Builder\Presets::field(['preset' => 'people', 'pole' => [['klic' => 'phone', 'typ' => 'cislo']]], 'people', 'phone', ['text']),
    Kaleta\Builder\Presets::field(['preset' => '', 'pole' => [['klic' => 'phone', 'typ' => 'text']]], 'people', 'phone', ['text'])], ['phone', null, null]);
check('2.11 cleanDateTime: date and time, a whole day, the T of an input, midnight = the whole day, nonsense', array_map(Kaleta\Builder\Collections::cleanDateTime(...),
    ['2026-10-24 18:30', '2026-10-24', '2026-10-24T09:05', '2026-10-24T00:00', '2026-10-24 9:05:00', '', '2026-02-30 10:00', '2026-10-24 25:00', 'tomorrow']),
    ['2026-10-24 18:30', '2026-10-24', '2026-10-24 09:05', '2026-10-24', '2026-10-24 09:05', '', null, null, null]);
check('2.11 cleanLocation: latitude, longitude – rounded, also with a semicolon; out of range and text are not valid', array_map(Kaleta\Builder\Collections::cleanLocation(...),
    ['50.0875, 14.4214', '50.08754321;14.42139876', '-33.9,18.42', '', '91, 10', '50, 181', 'Praha']), ['50.0875, 14.4214', '50.087543, 14.421399', '-33.9, 18.42', '', null, null, null]);
$fileFields = [['klic' => 'start', 'popisek' => 'Start', 'typ' => 'termin'], ['klic' => 'sheet', 'popisek' => 'Datasheet', 'typ' => 'soubor'], ['klic' => 'place', 'popisek' => 'Place', 'typ' => 'poloha']];
$fileErrors = [];
check('2.11 sanitizeData: a file from Media or https, never a path out of it', [Kaleta\Builder\Collections::sanitizeData($fileFields, ['start' => '2026-11-02T17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19,16.61'], $fileErrors),
    Kaleta\Builder\Collections::sanitizeData($fileFields, ['sheet' => '/media/../config.php'], $fileErrors), Kaleta\Builder\Collections::sanitizeData($fileFields, ['sheet' => 'javascript:alert(1)'], $fileErrors)['sheet']],
    [['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19, 16.61'], ['start' => '', 'sheet' => '', 'place' => ''], '']);
$fileValues = Kaleta\Builder\Collections::values(['seo_link' => 'akce', 'detail' => 1, 'pole' => $fileFields], ['nazev' => 'Den otevřených dveří', 'seo_link' => 'den', 'datum' => '2026-10-02 10:00:00',
    'data' => ['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/Cen%C3%ADk%202026.pdf', 'place' => '49.19, 16.61']], fn (string $p): string => '/' . $p);
check('2.11 values: {{start}} for visitors and {{start_iso}}, {{sheet}} a link and {{sheet_name}}', [$fileValues['start'][0], $fileValues['start_iso'][0], $fileValues['sheet'], $fileValues['sheet_name'][0], $fileValues['place'][0]],
    [format_date('2026-11-02 17:00', true), '2026-11-02 17:00', ['/media/docs/Cen%C3%ADk%202026.pdf', 'odkaz'], 'Ceník 2026.pdf', '49.19, 16.61']);

