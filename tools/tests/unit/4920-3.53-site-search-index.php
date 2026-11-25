<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SiteSearch;

/* ---------- 3.53 A5: the site search reads the stored index ---------- */

$a5Words = SiteSearch::words('media 北京 stavby', 'cs');
$a5Sql = SiteSearch::indexSql($a5Words, 'p.hledani');
$a5All = implode(' ', array_column($a5Sql['conditions'], 0)) . ' ' . $a5Sql['occurrences'][0];
check('3.53 A5: the scan reads only the search text – a word starts a word of it, a Chinese word is anywhere in it; no "1 = 1", no raw text or JSON', [
    array_column($a5Sql['conditions'], 0), array_column($a5Sql['conditions'], 1),
    str_contains($a5All, '1 = 1'), preg_match('/\b(text|data|titulek|popis)\b/', $a5All)],
    [["(CONCAT(' ', p.hledani) LIKE ?)", '(p.hledani LIKE ?)', "(CONCAT(' ', p.hledani) LIKE ? OR CONCAT(' ', p.hledani) LIKE ?)"],
        [['% medi%'], ['%北京%'], ['% stavb%', '% staveb%']], false, 0]);

check('3.53 A5: the occurrences per word – the first beginning counted in bytes with REPLACE (and at the very start), at most 20 a word, summed', [
    $a5Sql['occurrences'][1], substr_count($a5Sql['occurrences'][0], 'LEAST(20, '), str_contains($a5Sql['occurrences'][0], 'DIV 6 + (p.hledani LIKE ?))'), SiteSearch::ORDER_BY_OCCURRENCES],
    [[' medi', 'medi%', '北京', ' stavb', 'stavb%'], 3, true, true]);

// % and _ are no letters, so they never reach a LIKE pattern (they separate words); 3.54: a word's condition is the index alone
$a5More = SiteSearch::indexSql(SiteSearch::words('50_procent', 'cs'), 'c.hledani COLLATE utf8mb4_bin');
check('3.53 A5: a LIKE wildcard never reaches a pattern (it separates words); 3.54 D1: no alternative of a re-index joins the word', [
    $a5More['conditions'][0], (new ReflectionMethod(SiteSearch::class, 'indexSql'))->getNumberOfParameters()], [["(CONCAT(' ', c.hledani COLLATE utf8mb4_bin) LIKE ?)", ['% 50%']], 4]);

check('3.53 A5: rank() takes the search text as it is (normal) – a JSON key or a link in the raw text is not in it, so it never matches', [
    SiteSearch::rank(SiteSearch::words('media', 'cs'), [['title' => 'Kontakt', 'normal' => Kaleta\Core\SearchIndex::text('stranky', ['titulek' => 'Kontakt', 'text' => '<img src="/media/a.jpg"> <a href="/media/b">Napište nám</a>'])]], true),
    array_column(SiteSearch::rank(SiteSearch::words('media', 'cs'), [['title' => 'Kontakt', 'normal' => 'kontakt nase media'], ['title' => 'Media', 'normal' => 'media']], true), 'title')],
    [[], ['Media', 'Kontakt']]);

check('3.53 A5: a word of any script is searched – Cyrillic, Greek and Hangul as ordinary words, a Han run raw, one Han or Hangul character is a word', [
    array_column(SiteSearch::words('Новости Αθήνα 서울 茶 x', 'ru'), 'word'), array_map(fn (array $w): bool => isset($w['raw']), SiteSearch::words('Новости 茶 물', 'ru')),
    SiteSearch::rank(SiteSearch::words('новости', 'ru'), [['title' => 'Главная', 'normal' => Search::normalize('Наши Новости и события')]], true) !== []],
    [['новости', 'αθηνα', '서울', '茶'], [false, true, false], true]);

check('3.53 A5: Search::longEnough is the one gate – recordedQuery keeps one CJK character, never one Latin letter', [
    SiteSearch::recordedQuery('茶'), SiteSearch::recordedQuery('x'), SiteSearch::recordedQuery('ab')],
    ['茶', null, 'ab']);

check('3.53 A5: a single CJK character searches news in the admin and MCP through the index, never "every news item"', [
    str_contains(Search::newsWords('茶')[0], '1 = 1'), Search::newsWords('茶')[1][2] ?? null],
    [false, '%茶%']);

check('3.53 A5: the candidates and the scan guard are per kind; 3.54 D1: no caps of rows not indexed yet – they are not searched', [
    defined(SiteSearch::class . '::FALLBACK_PAGES'), defined(SiteSearch::class . '::FALLBACK_ITEMS'), SiteSearch::CANDIDATES, SiteSearch::MAX_ROWS,
    defined(SiteSearch::class . '::SCAN'), defined(SiteSearch::class . '::SCAN_PAGES')],
    [false, false, 300, 50000, false, false]);
