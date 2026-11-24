<?php

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\RecordSearch;
use Kaleta\Core\Search;
use Kaleta\Core\SearchIndex;

/* ---------- 3.53 B4: the Pages list, the palette, MCP list_pages and the lists of items search the index ---------- */

$b950C = ' COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?';

// pages: the title, address and SEO title in the collation, the text by its index ("strasse" finds "Straße"); a page not
// indexed yet (NULL) by its text, as 3.52; before migration 0141 the 3.52 search of the four columns
check('3.53 B4: SearchIndex::pageWords – the title columns, the index by the normal form, the text only while the index is NULL; not ready = the 3.52 columns', [
    SearchIndex::pageWords('Straße', true), SearchIndex::pageWords('Straße', false), SearchIndex::pageWords('', true)],
    [['((titulek' . $b950C . ' OR seo_link' . $b950C . ' OR seo_titulek' . $b950C . ' OR (hledani LIKE ? OR (hledani IS NULL AND ((text' . $b950C . '))))))',
        ['%Straße%', '%Straße%', '%Straße%', '%strasse%', '%Straße%']],
        Db::likeWords(['titulek', 'seo_link', 'seo_titulek', 'text'], 'Straße'), ['1 = 1', []]]);

// items: never a JSON key once indexed – likeJson only for an item without an index; the alias of the palette
[$b950Item, $b950ItemParams] = SearchIndex::itemWords('Łódź', true, 'p.');
check('3.53 B4: SearchIndex::itemWords – the name and address, the index ("lodz"), the JSON of the fields only while p.hledani IS NULL', [
    str_starts_with($b950Item, '((p.nazev' . $b950C . ' OR p.seo_link' . $b950C . ' OR (p.hledani LIKE ? OR (p.hledani IS NULL AND (((p.data' . $b950C),
    $b950ItemParams[2], substr_count($b950Item, 'p.data'), SearchIndex::itemWords('Łódź', false, 'p.') === Db::likeWords(['p.nazev', 'p.seo_link'], 'Łódź', ['p.data'])],
    [true, '%lodz%', 2, true]);

// the rules of newsWords hold for every index: a phrase, one plain character no filter (and no fallback), a word without a normal form only the titles
check('3.53 B4: indexWords – "R&B" a phrase in the index and the text while NULL; "v" no filter; "—" only the title columns; newsWords unchanged', [
    SearchIndex::pageWords('R&B', true)[1], str_contains(SearchIndex::pageWords('dům v', true)[0], '1 = 1'), SearchIndex::pageWords('—', true)[1],
    Search::newsWords('Dvořák', 'c.') === Search::indexWords('Dvořák', 'c.', ['titulek', 'seo_link'])],
    [['%R&B%', '%R&B%', '%R&B%', '% r b%', '%R&B%'], true, ['%—%', '%—%', '%—%'], true]);

// the palette reads the exact name first and a name starting with the search next, before the list's own order
$b950First = new ReflectionMethod(RecordSearch::class, 'first');
check('3.53 B4: the palette ORDER BY – the same name first, then its start, both in the search collation; % and _ of the search are literal', [
    $b950First->invoke(null, 'titulek', 'Ceník 50%')],
    [['titulek COLLATE ' . Db::SEARCH_COLLATION . ' = ? DESC, titulek COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ? DESC, ', ['Ceník 50%', 'Ceník 50\%%']]]);

check('3.53 B4: RecordSearch::rank – the same words in any order are the same name, in every script and Latin alphabet; every word starting a word is 2', [
    RecordSearch::rank('Jan Novák', 'novak jan'), RecordSearch::rank('Центр Москва', 'москва центр'), RecordSearch::rank('Łódź – centrum', 'lodz'),
    RecordSearch::rank('Hotel Straße Berlin', 'berl stras'), RecordSearch::rank('Straßenbau', 'bau'), RecordSearch::rank('Jan Novák', 'novak novak jan')],
    [0, 0, 1, 2, 3, 0]);
