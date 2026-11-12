<?php

declare(strict_types=1);

/* ---------- 2.11: branches (LocalBusiness) and the Store locator element ---------- */
check('2.11 Hours::specification – opening hours as text to OpeningHoursSpecification; nothing from a line that does not parse or from an empty text', [
    Kaleta\Core\Hours::specification("Mo-Fr 9-17\nSa 9:00-12:00"), Kaleta\Core\Hours::specification("Mo-Fr 9-17\nby appointment"), Kaleta\Core\Hours::specification('')], [
    [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '17:00'],
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '09:00', 'closes' => '12:00']], [], []]);
$branchFields = [['klic' => 'address', 'popisek' => 'Address', 'typ' => 'text'], ['klic' => 'location', 'popisek' => 'Location', 'typ' => 'poloha'], ['klic' => 'phone', 'popisek' => 'Phone', 'typ' => 'text'],
    ['klic' => 'email', 'popisek' => 'E-mail', 'typ' => 'text'], ['klic' => 'hours', 'popisek' => 'Opening hours', 'typ' => 'radky']];
$branchCollection = ['seo_link' => 'pobocky', 'detail' => 1, 'pole' => $branchFields,
    'schema_org' => json_encode(['typ' => 'LocalBusiness', 'pole' => ['address' => 'address', 'telephone' => 'phone', 'email' => 'email', 'geo' => 'location', 'openingHours' => 'hours']])];
$branch = fn (array $data): ?array => Kaleta\Builder\CollectionSchema::forItem($branchCollection, ['nazev' => 'Brno', 'data' => $data], 'https://example.com/pobocky/brno', 'Our Brno store', '', 'https://example.com#firma');
$brnoNode = $branch(['address' => 'Náměstí Svobody 1, Brno', 'location' => '49.1951, 16.6068', 'phone' => '+420 123 456 789', 'email' => 'brno@example.com', 'hours' => 'Mo-Fr 9-17']);
check('2.11 CollectionSchema: a branch is a LocalBusiness of the company with its address, contacts, geo and opening hours', [
    $brnoNode['@type'], $brnoNode['address'], $brnoNode['telephone'], $brnoNode['geo'], count($brnoNode['openingHoursSpecification']), $brnoNode['parentOrganization'], isset($brnoNode['provider'])],
    ['LocalBusiness', 'Náměstí Svobody 1, Brno', '+420 123 456 789', ['@type' => 'GeoCoordinates', 'latitude' => 49.1951, 'longitude' => 16.6068], 1, ['@id' => 'https://example.com#firma'], false]);
$sparseNode = $branch(['address' => 'Somewhere 1', 'location' => 'in the centre', 'hours' => 'always open']);
check('2.11 CollectionSchema: no geo from text that is not a location and no hours that do not parse – rather left out than guessed', [isset($sparseNode['geo']), isset($sparseNode['openingHoursSpecification']), $sparseNode['address']], [false, false, 'Somewhere 1']);
check('2.11 CollectionSchema::geo', [Kaleta\Builder\CollectionSchema::geo('50.0875;14.4214'), Kaleta\Builder\CollectionSchema::geo(''), Kaleta\Builder\CollectionSchema::geo('Praha')],
    [['@type' => 'GeoCoordinates', 'latitude' => 50.0875, 'longitude' => 14.4214], null, null]);
check('2.11 Presets: branches – LocalBusiness mapped to the location and hours fields, created before the team', [
    $presets['branches']['schema']['typ'], $presets['branches']['schema']['pole']['geo'], $presets['branches']['schema']['pole']['openingHours'], $presets['branches']['order'] < 100,
    Kaleta\Builder\Presets::field(['preset' => 'branches', 'pole' => [['klic' => 'location', 'typ' => 'poloha']]], 'branches', 'location', ['poloha'])], ['LocalBusiness', 'location', 'hours', true, 'location']);
$branchTemplate = Kaleta\Builder\Presets::itemTemplate($presets['branches'], Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]], $presets['branches']['fields'])));
$branchTemplateJson = Kaleta\Builder\Build::toJson($branchTemplate);
check('2.11 Presets: the branch item template shows the photo, contacts, hours and a click-to-load map of the address', [
    str_contains($branchTemplateJson, '{{photo}}'), str_contains($branchTemplateJson, '<p>{{hours}}</p>'), str_contains($branchTemplateJson, '"typ":"mapa"'), str_contains($branchTemplateJson, '"adresa":"{{address}}"')], [true, true, true, true]);
check('2.11 StoreLocator::telHref – digits and one leading plus, nothing from text', array_map(Kaleta\Builder\Elements\StoreLocator::telHref(...), ['+420 123 456 789', '(0049) 30 / 123-45', 'call us', '']),
    ['tel:+420123456789', 'tel:00493012345', '', '']);
check('2.11 StoreLocator::coordinates and directionsUrl – the address first, then the coordinates, otherwise nothing', [
    Kaleta\Builder\Elements\StoreLocator::coordinates('49.1951, 16.6068'), Kaleta\Builder\Elements\StoreLocator::coordinates('nowhere'),
    Kaleta\Builder\Elements\StoreLocator::directionsUrl('Náměstí Svobody 1, Brno', ['49.1951', '16.6068']), Kaleta\Builder\Elements\StoreLocator::directionsUrl('', ['49.1951', '16.6068']), Kaleta\Builder\Elements\StoreLocator::directionsUrl('', null)],
    [['49.1951', '16.6068'], null, 'https://www.google.com/maps/search/?api=1&query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody%201%2C%20Brno', 'https://www.google.com/maps/search/?api=1&query=49.1951%2C16.6068', '']);
check('[source-pin] 2.11 Store locator: a Dynamic element with an English name, its texts for the script in the site dictionaries, Leaflet vendored', [
    Kaleta\Builder\Elements\StoreLocator::GROUP, Kaleta\Mcp\Vocabulary::TYPES['pobocky'], Kaleta\Mcp\Vocabulary::CONTENT['pole_poloha'],
    isset((require KALETA_SYSTEM . '/jazyky/cs.php')['Nearest to me']), isset((require KALETA_SYSTEM . '/jazyky/de.php')['Location access was refused – the list stays in its usual order.']),
    is_file(KALETA_ROOT . '/image/vendor/leaflet/leaflet.js') && is_file(KALETA_ROOT . '/image/vendor/leaflet/leaflet.css') && is_file(KALETA_ROOT . '/image/vendor/leaflet/images/marker-icon.png') && is_file(KALETA_ROOT . '/image/vendor/leaflet/LICENSE'),
    preg_match('/Leaflet 1\.9\.4/', (string) file_get_contents(KALETA_ROOT . '/image/vendor/leaflet/leaflet.js')) === 1],
    ['Dynamic', 'store_locator', 'location_field', true, true, true, true]);
