<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SearchIndex;

/* ---------- 3.53 A2, A3: the stored search text of pages and collection items (Core\SearchIndex) ---------- */

$a3Build = json_encode(['v' => 1, 'deti' => [
    ['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h1', 'obsah' => ['text' => 'Rekonstrukce koupelen']],
    ['id' => 'b', 'typ' => 'text', 'obsah' => ['html' => '<p>Obklady <b>a</b>&nbsp;dlažby v&nbsp;Brně</p>']],
]], JSON_UNESCAPED_UNICODE);
check('3.53 A3: the text of a builder page – title, SEO title, description and the published build as text (never the older text column); a text page – its text', [
    SearchIndex::text('stranky', ['titulek' => 'Koupelny', 'seo_titulek' => 'Koupelny na klíč', 'popis' => 'Celé Brno', 'text' => '<p>stará verze</p>', 'stavba' => $a3Build]),
    SearchIndex::text('stranky', ['titulek' => 'Kontakt', 'seo_titulek' => '', 'popis' => '', 'text' => '<h2>Adresa</h2><p>Údolní&nbsp;5, Brno</p>', 'stavba' => null]),
    // a build without any text (only a divider): the page is its build – the text column Publisher::page left is older; broken JSON = a text page
    SearchIndex::text('stranky', ['titulek' => 'Prázdná', 'text' => '<p>Původní text</p>', 'stavba' => json_encode(['v' => 1, 'deti' => [['id' => 'c', 'typ' => 'oddelovac', 'obsah' => []]]])]),
    SearchIndex::text('stranky', ['titulek' => 'Rozbitá', 'text' => '<p>Záloha</p>', 'stavba' => '{"v":1,"deti":']),
], [
    'koupelny koupelny na klic cele brno rekonstrukce koupelen obklady a dlazby v brne',
    'kontakt adresa udolni 5 brno',
    'prazdna',
    'rozbita zaloha',
]);

$a3Fields = [['klic' => 'podnadpis', 'typ' => 'text'], ['klic' => 'obsah', 'typ' => 'html'], ['klic' => 'foto', 'typ' => 'obrazek'], ['klic' => 'web', 'typ' => 'odkaz'],
    ['klic' => 'cena', 'typ' => 'cislo'], ['klic' => 'barva', 'typ' => 'volba'], ['klic' => 'parametry', 'typ' => 'parametry'], ['klic' => 'varianty', 'typ' => 'varianty'],
    ['klic' => 'soubor', 'typ' => 'soubor'], ['klic' => 'misto', 'typ' => 'poloha'], ['klic' => 'den', 'typ' => 'datum'], ['klic' => 'souvisi', 'typ' => 'polozka'], ['klic' => 'poznamka', 'typ' => 'radky']];
$a3Data = json_encode(['podnadpis' => 'Lehký stan', 'obsah' => '<p>Pro <em>dva</em> lidi</p>', 'foto' => '/media/stan-media.jpg', 'web' => 'https://example.com/odkaz-pryc',
    'cena' => '4990', 'barva' => 'Zelená', 'parametry' => "Hmotnost: 2 kg\nVýška: 110 cm", 'varianty' => "Malý | ST-100 | 4990\nVelký | ST-200 | 5990",
    'soubor' => '/media/navod-pryc.pdf', 'misto' => '49.19,16.60', 'den' => '2026-05-01', 'souvisi' => 'karimatka-pryc', 'poznamka' => "Druhý\nřádek", 'neznamy' => 'mimo pole'], JSON_UNESCAPED_UNICODE);
check('3.53 A3: the text of an item – name, description and the values of SEARCH_TYPES fields only: a variant code is in, an image path, a link, a number, a date, a file, a place and an item link are not', [
    SearchIndex::text('kolekce_polozky', ['nazev' => 'Stan Horizont', 'popis' => 'Na hory', 'data' => $a3Data], $a3Fields),
    SearchIndex::text('kolekce_polozky', ['nazev' => 'Stan', 'popis' => 'Na hory', 'data' => '{"podnadpis": "rozbitý'], $a3Fields),
    SearchIndex::text('kolekce_polozky', ['nazev' => 'Stan', 'popis' => '', 'data' => $a3Data], null),
    SearchIndex::SEARCH_TYPES === array_values(array_diff(array_keys(Kaleta\Builder\Collections::FIELD_TYPES), ['obrazek', 'odkaz', 'cislo', 'datum', 'termin', 'soubor', 'poloha', 'polozka'])),
], [
    'stan horizont na hory lehky stan pro dva lidi zelena hmotnost 2 kg vyska 110 cm maly st 100 4990 velky st 200 5990 druhy radek',
    'stan na hory',
    'stan',
    true,
]);

$a3Long = SearchIndex::text('stranky', ['titulek' => 'Dlouhá', 'text' => str_repeat('<p>Žluťoučký kůň úpěl ďábelské ódy a ' . str_repeat('ü', 3) . 'č</p>', 6000), 'stavba' => null]);
check('3.53 A3: a long text is cut at 200 KB, never inside a character; a news item as Search::index made it since 1.x', [
    strlen($a3Long) <= SearchIndex::MAX_BYTES, strlen($a3Long) > SearchIndex::MAX_BYTES - 10, mb_check_encoding($a3Long, 'UTF-8'), SearchIndex::MAX_BYTES,
    SearchIndex::text('novinky', ['titulek' => 'Nový web', 't_slova' => 'web, Kaleta', 'uvod' => '<p>Úvod</p>', 'text' => '<p>Text článku</p>']),
], [true, true, true, 204800, 'novy web web kaleta uvod text clanku']);

// CONTENT is the one list of the columns the text is made of (the rule that empties the index on a change reads it, A4):
// a change of a CONTENT column changes the text, a change of any other column does not
$a3Page = ['titulek' => 'Titulek', 'seo_titulek' => 'Seo', 'popis' => 'Popis', 'text' => '<p>Text</p>', 'stavba' => $a3Build, 'seo_link' => 'adresa', 'obrazek' => '/media/a.jpg', 'poradi' => 5];
$a3Item = ['nazev' => 'Nazev', 'popis' => 'Popis', 'data' => $a3Data, 'seo_link' => 'adresa', 'obrazek' => '/media/a.jpg', 'poradi' => 5];
$a3Changes = static function (string $kind, array $row, ?array $fields): array {
    $out = [];
    foreach ($row as $column => $value) {
        $changed = $row;
        $changed[$column] = $column === 'stavba' ? json_encode(['v' => 1, 'deti' => [['id' => 'z', 'typ' => 'text', 'obsah' => ['html' => '<p>Jiný obsah</p>']]]])
            : ($column === 'data' ? json_encode(['podnadpis' => 'Jiný']) : (is_int($value) ? $value + 1 : $value . ' jiný'));
        if (SearchIndex::text($kind, $changed, $fields) !== SearchIndex::text($kind, $row, $fields)) {
            $out[] = $column;
        }
    }

    return $out;
};
// a page with a build: its text column is not read (the build wins), so it is changed on a page without a build
check('3.53 A3: SearchIndex::CONTENT is exactly what text() reads – for pages and for items', [
    $a3Changes('stranky', $a3Page, null), $a3Changes('stranky', ['stavba' => null] + $a3Page, null), $a3Changes('kolekce_polozky', $a3Item, $a3Fields), array_keys(SearchIndex::CONTENT)],
    [['titulek', 'seo_titulek', 'popis', 'stavba'], ['stavba', 'titulek', 'seo_titulek', 'popis', 'text'], ['nazev', 'popis', 'data'], ['stranky', 'kolekce_polozky']]);
/* ---------- A2: schema and migration 0141 ---------- */

// the statements of migration 0141, its re-run and the structure equal to a clean install: tools/test-migrations.sh (m353)
check('3.53 A2: KALETA_DB_VERSION 141 is the version SearchIndex waits for; the column hledani has its English name search_text on pages and items', [
    KALETA_DB_VERSION, SearchIndex::DB_VERSION, \Kaleta\Core\Names::columns('stranky')['hledani'] ?? null, \Kaleta\Core\Names::columns('kolekce_polozky')['hledani'] ?? null,
], [141, 141, 'search_text', 'search_text']);

/* ---------- A3: the job, the state and the settings ---------- */

$a3Jobs = array_keys(Kaleta\Core\Scheduler::JOBS);
$a3Settings = new Kaleta\Core\Settings(new Kaleta\Core\Db('sqlite::memory:', '', ''));
(new ReflectionProperty($a3Settings, 'values'))->setValue($a3Settings, ['db_version' => '140', SearchIndex::STATE => '', SearchIndex::WAITING => '']);
$a3State = function (array $values) use ($a3Settings): array {
    (new ReflectionProperty($a3Settings, 'values'))->setValue($a3Settings, $values + ['db_version' => '141', SearchIndex::STATE => '', SearchIndex::WAITING => '']);

    return [SearchIndex::ready($a3Settings), SearchIndex::state($a3Settings), SearchIndex::stuck($a3Settings)];
};
check('3.53 A3: the job search_index runs after the mail and the webhooks, with its new label; ready() from db_version 141; the state "version:cursor" (\'\' = version 1); stuck only when the waiting rows did not fall between two runs', [
    array_search('search_index', $a3Jobs, true) > array_search('webhooks', $a3Jobs, true), array_search('mail', $a3Jobs, true) < array_search('webhooks', $a3Jobs, true),
    Kaleta\Core\Scheduler::JOBS['search_index'], Kaleta\Core\Scheduler::remaining() > 1e9,
    $a3State(['db_version' => '140']), $a3State([SearchIndex::STATE => '1:350', SearchIndex::WAITING => ':40']), $a3State([SearchIndex::STATE => Search::VERSION . ':0', SearchIndex::WAITING => '40:12']),
    $a3State([SearchIndex::WAITING => '12:12']), $a3State([SearchIndex::WAITING => '12:0']),
    Kaleta\Core\Settings::DEFAULTS[SearchIndex::STATE] ?? null, Kaleta\Core\Settings::DEFAULTS[SearchIndex::WAITING] ?? null,
    array_intersect([SearchIndex::STATE, SearchIndex::WAITING], array_merge(Kaleta\Core\Settings::keysWith('export'), Kaleta\Core\Settings::keysWith('mcp'), Kaleta\Core\SiteExport::SETTINGS)),
], [
    true, true, [0, 'any', 'Search index filled in'], true,
    [false, [1, 0], false], [true, [1, 350], false], [true, [2, 0], false], [true, [1, 0], true], [true, [1, 0], false],
    '', '', [],
]);

// on a database without the column (new files, the update not run yet) complete() and pending() answer "nothing yet"
$a3Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a3Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a3Db, 'pdo'))->setValue($a3Db, $a3Pdo);
check('3.53 A3: before migration 0141 the job, pending() and complete() do nothing and never fail; with no time at all complete() reads nothing', [
    $a3State(['db_version' => '140'])[0] ? '' : SearchIndex::run($a3Db, $a3Settings, 5.0),
    SearchIndex::pending($a3Db, $a3Settings),
    SearchIndex::complete($a3Db, 0.0),
], [
    'not yet: the database update of 3.53 has not run',
    ['pages' => 0, 'items' => 0, 'news' => 0],
    ['pages' => 0, 'items' => 0, 'news' => 0, 'failed' => 0, 'left' => true],
]);
