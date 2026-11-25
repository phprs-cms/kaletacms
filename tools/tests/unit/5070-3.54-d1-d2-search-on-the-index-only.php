<?php

declare(strict_types=1);

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Search;
use Kaleta\Core\SearchIndex;
use Kaleta\Core\Settings;
use Kaleta\Core\SiteSearch;

/* ---------- 3.54 D1–D3: the site search, the admin lists and MCP lists read the search text only ---------- */

// an App whose settings are given in memory: the database is never reached (port 1)
$d354App = function (array $values): App {
    $app = new App(['db' => ['host' => '127.0.0.1', 'port' => 1, 'name' => 'none', 'user' => 'none', 'password' => '', 'prefix' => 'ka_']]);
    $settings = new Settings(new Db('mysql:host=127.0.0.1;port=1;dbname=none', 'none', ''));
    (new ReflectionProperty(Settings::class, 'values'))->setValue($settings, $values);
    (new ReflectionProperty(App::class, 'settings'))->setValue($app, $settings);

    return $app;
};
$d354Scopes = new ReflectionMethod(SiteSearch::class, 'scopes');

// D1: the fallback and the news alternative of a re-index are gone – no cap of rows not indexed yet, no news cursor
check('3.54 D1: SiteSearch has no 3.52-style fallback and no news re-index alternative (constants, methods, the $more parameter)', [
    defined(SiteSearch::class . '::FALLBACK_PAGES'), defined(SiteSearch::class . '::FALLBACK_ITEMS'), method_exists(SiteSearch::class, 'newsReindexing'),
    array_map(fn (ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(SiteSearch::class, 'indexSql'))->getParameters())],
    [false, false, false, ['words', 'h', 'title', 'byOccurrences']]);

// D1: one definition of what is searched – the scan and waiting() read the same scopes; before migration 0141 no page or item
$d354Ready = $d354App(['db_version' => (string) SearchIndex::DB_VERSION, 'extensions' => 'novinky']);
$d354Old = $d354App(['db_version' => (string) (SearchIndex::DB_VERSION - 1), 'extensions' => '-']);
$d354AllScopes = $d354Scopes->invoke(null, $d354Ready, 'en');
$d354Sql = implode(' ', array_map(fn (array $s): string => $s['from'] . ' ' . $s['column'], $d354AllScopes));
check('3.54 D1: scopes() – pages, items and news (news only with the extension), visible rows of the language version, never a raw text, JSON or hledani IS NULL', [
    array_keys($d354AllScopes), array_keys($d354Scopes->invoke(null, $d354Old, '')), array_column($d354AllScopes, 'column'),
    preg_match('/\b(text|data|uvod)\b|IS NULL\b(?<!smazano IS NULL)/', str_replace(['smazano IS NULL', 'heslo_hash IS NULL'], '', $d354Sql)),
    substr_count($d354Sql, 'jazyk = ?'), $d354AllScopes['page']['params'], count($d354AllScopes['news']['params'])],
    [['page', 'collection_item', 'news'], [], ['hledani', 'p.hledani', 'c.hledani'], 0, 3, ['en'], 2]);

// D1: the scan SQL of every word reads the search text only – no raw column, no JSON, no "IS NULL" branch, no "1 = 1"
$d354Scan = SiteSearch::indexSql(SiteSearch::words('Dvořák книга 北京 R&B C++', 'cs'), 'p.hledani', 'p.nazev');
$d354ScanSql = implode(' ', array_column($d354Scan['conditions'], 0)) . ' ' . $d354Scan['occurrences'][0];
check('3.54 D1: indexSql – every condition on the search text (a "C++" word on the title as written), never text/data/JSON, hledani IS NULL or "1 = 1"', [
    count($d354Scan['conditions']), preg_match('/\b(text|data|uvod|JSON_\w+)\b/', $d354ScanSql), str_contains($d354ScanSql, 'IS NULL'), str_contains($d354ScanSql, '1 = 1'),
    $d354Scan['conditions'][4][0]],
    [5, 0, false, false, '(p.nazev COLLATE ' . Db::SEARCH_COLLATION . ' LIKE ?)']);

// D2: the admin search of pages and items – the columns and the index, never the raw text or JSON, never hledani IS NULL
$d354Lists = [SearchIndex::pageWords('Dvořák Łódź 北京 R&B', true), SearchIndex::itemWords('Dvořák Łódź', true, 'p.'), Search::indexWords('Dvořák', 'x.', ['a'])];
$d354ListSql = implode(' ', array_column($d354Lists, 0));
check('3.54 D2: pageWords / itemWords / indexWords – no raw text or JSON, no hledani IS NULL; before migration 0141 the columns only (never hledani)', [
    preg_match('/\b(text|data)\b/', $d354ListSql), str_contains($d354ListSql, 'IS NULL'),
    str_contains(SearchIndex::pageWords('Dvořák', false)[0] . SearchIndex::itemWords('Dvořák', false)[0], 'hledani'),
    SearchIndex::pageWords('Dvořák', false) === Db::likeWords(SearchIndex::PAGE_COLUMNS, 'Dvořák'),
    (new ReflectionMethod(Search::class, 'indexWords'))->getNumberOfParameters()],
    [0, false, false, true, 4]);

// D3: the indexing flag before migration 0141 – pages and items are not searched at all, so the search says so (no query)
check('3.54 D3: SiteSearch::waiting() is true on a database before migration 0141 (pages and items not searchable), decided without a query', [
    SiteSearch::waiting($d354Old, ''), SiteSearch::HEAL_SECONDS, SearchIndex::BATCH_SECONDS, SearchIndex::SOON_SECONDS],
    [true, 0.3, 2.0, 1.0]);

// D3: with a news re-index of a newer Search::VERSION running (stored version below it) the flag is up – without a query
$d354Reindex = $d354App(['db_version' => (string) SearchIndex::DB_VERSION, 'extensions' => 'novinky', SearchIndex::STATE => (Search::VERSION - 1) . ':0']);
check('3.54 D3: waiting() is true while the news re-index runs (the news extension on); the flag sits in find()\'s result for an empty query too', [
    SiteSearch::waiting($d354Reindex, ''), SiteSearch::find($d354Reindex, ' ', '')],
    [true, ['results' => [], 'total' => 0, 'partial' => false, 'indexing' => false, 'words' => []]]);
