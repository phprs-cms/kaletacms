<?php

declare(strict_types=1);

use Kaleta\Core\SiteSearch;

/* ---------- 3.52 B5: what visitors search for on the site (Statistics, MCP get_stats) ---------- */

check('3.52 B5: SiteSearch::recordedQuery() – lower case, spaces collapsed, at most 60 characters; never an @ or 6+ digits; one letter is no query', [
    SiteSearch::recordedQuery('  Rekonstrukce   KOUPELEN '), SiteSearch::recordedQuery("Služby\tv Brně"), mb_strlen((string) SiteSearch::recordedQuery(str_repeat('ab ', 40))),
    SiteSearch::recordedQuery('jan.novak@example.cz'), SiteSearch::recordedQuery('777 123 456'), SiteSearch::recordedQuery('PSČ 602 00'), SiteSearch::recordedQuery('x'),
    SiteSearch::recordedQuery('ISO 9001'), SiteSearch::recordedQuery('5G')],
    ['rekonstrukce koupelen', 'služby v brně', 59, null, null, 'psč 602 00', null, 'iso 9001', '5g']);

// the table, the recording rules, Statistics and get_stats are behaviour of the installed site: smoke 4840
check('3.52 B5: ka_search_queries has its English name in the map, its days are civil dates (a time zone change never moves them), KALETA_DB_VERSION 141 (3.53: migration 0141)', [
    \Kaleta\Core\Names::map()['search_queries'][0] ?? null, \Kaleta\Core\TimeZoneConversion::CIVIL['search_queries'] ?? null, KALETA_DB_VERSION, \Kaleta\Core\Migration::latest()],
    ['search_queries', ['day'], 141, 141]);

check('3.52 B5: get_stats marks site_search as visitors\' text; no code searches with MATCH any more (the FULLTEXT ft_clanky is dropped by migration 0140)', [
    in_array('site_search', \Kaleta\Mcp\Untrusted::TOOLS['get_stats'], true),
    (bool) preg_match('/\bMATCH\s*\(/', implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), glob(KALETA_SYSTEM . '/src/{,*/,*/*/}*.php', GLOB_BRACE) ?: [])))],
    [true, false]);

// SiteSearch::queries() on an in-memory SQLite database with the table: summed over the days of the period, the most searched
// first, the ones that found nothing by how often; the language only for an additional language version
$b5Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$b5Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($b5Db, 'pdo'))->setValue($b5Db, $b5Pdo);
$b5Pdo->exec("CREATE TABLE ka_search_queries (day TEXT, language TEXT NOT NULL DEFAULT '', query TEXT, searches INTEGER, without_result INTEGER, PRIMARY KEY (day, language, query))");
$b5Pdo->exec("INSERT INTO ka_search_queries VALUES ('2026-11-01', '', 'altány', 3, 0), ('2026-11-02', '', 'altány', 2, 1), ('2026-11-02', '', 'pergola', 4, 4),
    ('2026-11-02', 'en', 'gazebo', 1, 0), ('2026-10-01', '', 'staré', 9, 9)");
check('3.52 B5: SiteSearch::queries() – the queries of the period summed and ordered, those without result apart, the language only for another version', SiteSearch::queries($b5Db, '2026-11-01'), [
    'terms' => [['query' => 'altány', 'searches' => 5, 'without_result' => 1], ['query' => 'pergola', 'searches' => 4, 'without_result' => 4], ['query' => 'gazebo', 'language' => 'en', 'searches' => 1, 'without_result' => 0]],
    'without_result' => [['query' => 'pergola', 'searches' => 4, 'without_result' => 4], ['query' => 'altány', 'searches' => 5, 'without_result' => 1]],
]);

// 3.53 B6: SiteSearch::tally() keeps at most DAY_QUERIES distinct queries a day and language; a known query is still counted,
// another day or language version has its own budget
$b6Insert = $b5Pdo->prepare("INSERT INTO ka_search_queries VALUES ('2026-11-20', '', ?, 1, 0)");
for ($b6 = 1; $b6 < SiteSearch::DAY_QUERIES; $b6++) {
    $b6Insert->execute(['dotaz ' . $b6]);
}
check('3.53 B6: SiteSearch::tally() – a new query fits up to DAY_QUERIES rows, the next new one is refused, a known one still counts (also "without result"), another language and day are separate', [
    SiteSearch::DAY_QUERIES,
    SiteSearch::tally($b5Db, '2026-11-20', '', 'poslední', false),
    SiteSearch::tally($b5Db, '2026-11-20', '', 'navíc', true),
    SiteSearch::tally($b5Db, '2026-11-20', '', 'dotaz 7', true),
    SiteSearch::tally($b5Db, '2026-11-20', 'en', 'extra', false),
    SiteSearch::tally($b5Db, '2026-11-21', '', 'navíc', false),
    (int) $b5Pdo->query("SELECT COUNT(*) FROM ka_search_queries WHERE day = '2026-11-20' AND language = ''")->fetchColumn(),
    $b5Pdo->query("SELECT searches || '/' || without_result FROM ka_search_queries WHERE day = '2026-11-20' AND query = 'dotaz 7'")->fetchColumn(),
    $b5Pdo->query("SELECT COUNT(*) FROM ka_search_queries WHERE query = 'navíc' AND day = '2026-11-20'")->fetchColumn()],
    [2000, true, false, true, true, true, 2000, '2/1', 0]);
