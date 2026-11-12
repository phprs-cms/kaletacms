<?php

declare(strict_types=1);

/* ---------- 2.11 F1: six more ready-made collections ---------- */
$f1Presets = Kaleta\Builder\Presets::all();
check('2.11 presets: services, references, price_list, faq, machines and courses in order after people, with their pages and structured data', array_map(fn (string $k): array => [
    $f1Presets[$k]['order'], (bool) $f1Presets[$k]['detail'], (string) ($f1Presets[$k]['schema']['typ'] ?? ''), is_callable($f1Presets[$k]['template'])], ['services', 'references', 'price_list', 'faq', 'machines', 'courses']),
    [[20, true, 'Service', true], [30, true, '', true], [40, false, '', false], [50, false, 'FAQPage', false], [70, true, 'Product', true], [80, true, 'Event', true]]);
check('2.11 presets: the lists – a price list and FAQ filter by category, courses show the upcoming ones by start and end sorted by the start', [
    $f1Presets['price_list']['list'], $f1Presets['faq']['list']['filtr_pole'], $f1Presets['courses']['list']],
    [['razeni' => 'poradi', 'filtr_pole' => 'category', 'filtry' => true], 'category', ['obdobi' => 'nadchazejici', 'obdobi_od' => 'start', 'obdobi_do' => 'end', 'razeni' => 'pole', 'razeni_pole' => 'start']]);
check('2.11 presets: a reference links to the services preset, the schema maps price_from, sku and the event dates', [$f1Presets['references']['fields'][5][3]['preset'], $f1Presets['services']['schema']['pole'],
    $f1Presets['machines']['schema']['pole'], $f1Presets['courses']['schema']['pole']], ['services', ['price' => 'price_from'], ['sku' => 'model'], ['startDate' => 'start', 'endDate' => 'end', 'location' => 'place', 'price' => 'price']]);
$f1Fields = fn (string $k): array => Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]] + (isset($f[3]['preset']) ? ['kolekce' => 'sluzby'] : []), $f1Presets[$k]['fields']));
$f1Template = fn (string $k): string => Kaleta\Builder\Build::toJson(Kaleta\Builder\Presets::itemTemplate($f1Presets[$k], $f1Fields($k)));
check('2.11 presets: the item templates survive sanitizing and show the fields', [
    str_contains($f1Template('services'), '{{summary}}') && str_contains($f1Template('services'), '{{price_from}} {{price_note}}'),
    str_contains($f1Template('references'), '{{service_url}}') && str_contains($f1Template('references'), '"odkaz":"{{link}}"'),
    str_contains($f1Template('machines'), '{{datasheet_name}}') && str_contains($f1Template('machines'), '{{parameters}}'),
    str_contains($f1Template('courses'), '<strong>{{when}}</strong>') && str_contains($f1Template('courses'), '{{capacity}}') && str_contains($f1Template('courses'), '"typ":"formular"')], [true, true, true, true]);
$f1List = Kaleta\Builder\Build::toJson(Kaleta\Builder\Presets::listPage($f1Presets['courses'], 'Kurzy', 'kurzy', $f1Fields('courses')));
check('2.11 presets: the list page of courses lists the upcoming ones with the start and the place on the card', [str_contains($f1List, '"obdobi":"nadchazejici"'), str_contains($f1List, '"razeni_pole":"start"'), str_contains($f1List, '<p>{{start}}</p>'), str_contains($f1List, '<p>{{place}}</p>')], [true, true, true, true]);
// 3.9.2: create_collection preset jobs over MCP on a Czech site gave a half-English item page – the preset texts were only in the
// admin dictionary, while an MCP request has the site dictionary loaded – and the applicant's name was "Název" (the name of a thing)
$presetSiteTexts = static function (string $key, array $p): array {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)+)'/u", (string) file_get_contents(KALETA_SYSTEM . '/presets/' . $key . '.php'), $m);

    return array_values(array_unique([$p['name'], ...array_map('stripslashes', $m[1]), ...array_column($p['fields'], 1), ...array_column((array) $p['extra_pages'], 'name')]));
};
$jobsCs = Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Builder\Build::toJson(Kaleta\Builder\Presets::itemTemplate($presets['jobs'], Kaleta\Builder\Collections::sanitizeFields(array_map(
    fn (array $f): array => ['klic' => $f[0], 'popisek' => t($f[1]), 'typ' => $f[2]] + (isset($f[3]['preset']) ? ['kolekce' => 'tym'] : []), $presets['jobs']['fields'])))));
check('[source-pin] 3.9.2 presets: the jobs item template built in a Czech site (as over MCP) has no English text and asks for "Jméno a příjmení"', [
    array_values(array_filter($presetSiteTexts('jobs', $presets['jobs']), fn (string $text): bool => str_contains($jobsCs, $text))),
    str_contains($jobsCs, '"popisek":"Jméno a příjmení"'), str_contains($jobsCs, 'Název'), str_contains($jobsCs, '"tlacitko":"Odeslat žádost"')], [[], true, false, true]);
// every text a preset writes into the site (name, field labels, the item template, extra pages) is in every site dictionary;
// a Czech source text (a key of en.php) passes through in Czech
$presetUntranslated = [];
$sourceCzech = require KALETA_SYSTEM . '/jazyky/en.php';
foreach ([...(glob(KALETA_SYSTEM . '/jazyky/[a-z][a-z].php') ?: []), KALETA_SYSTEM . '/jazyky/zh-hant.php'] as $file) {
    $code = basename($file, '.php');
    $dictionary = $code === 'en' ? [] : require $file;
    foreach ($code === 'en' ? [] : $presets as $presetKey => $p) {
        foreach ($presetSiteTexts($presetKey, $p) as $text) {
            if (!isset($dictionary[$text]) && !($code === 'cs' && isset($sourceCzech[$text]))) {
                $presetUntranslated[] = $code . ': ' . $presetKey . ': ' . $text;
            }
        }
    }
}
check('3.9.2 presets: what a preset writes into the site is translated in every site dictionary', array_values(array_unique($presetUntranslated)), []);

