<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SiteSearch;
use Kaleta\Mcp\Catalog;

/* ---------- 3.52 B1–B3: one ranked site search, word forms, snippets ---------- */

check('3.52 B2: Search::stem() – cs/sk/en/de endings off the query word, the stem keeps at least 4 letters; digits and other languages stay', [
    Search::stem('stavby', 'cs'), Search::stem('koupelna', 'cs'), Search::stem('koupelnami', 'cs'), Search::stem('domech', 'cs'), Search::stem('auto', 'cs'),
    Search::stem('stavbou', 'sk'), Search::stem('services', 'en'), Search::stem('cars', 'en'), Search::stem('kunden', 'de'), Search::stem('5g', 'cs'),
    Search::stem('stavby', 'fr'), Search::stem('it', 'cs'), Search::stem('stavby', 'pt-br')],
    ['stavb', 'koupeln', 'koupeln', 'domech', 'auto', 'stavb', 'servic', 'cars', 'kund', '5g', 'stavby', 'it', 'stavby']);

check('3.52 B2: Search::terms() – the word, its stem and the mobile e of Czech and Slovak (koupelna–koupelen, služby–služeb, služeb–služby)', [
    Search::terms('koupelna', 'cs'), Search::terms('sluzby', 'cs'), Search::terms('sluzeb', 'cs'), Search::terms('stavby', 'cs'), Search::terms('test', 'cs'), Search::terms('services', 'en')],
    [['koupelna', 'koupeln', 'koupelen'], ['sluzby', 'sluzb', 'sluzeb'], ['sluzeb', 'sluzb'], ['stavby', 'stavb', 'staveb'], ['test'], ['services', 'servic']]);

// the pairs of the plan: a query finds the other form of the word
$b352Pairs = array_map(fn (array $pair): bool => SiteSearch::rank(SiteSearch::words($pair[0], 'cs'), [['title' => '', 'text' => $pair[1]]], true) !== [],
    [['koupelna', 'Rekonstrukce koupelen'], ['stavby', 'Hrubá stavba domu'], ['služeb', 'Naše služby'], ['služby', 'Ceník služeb'], ['koupelen', 'Nová koupelna']]);
check('3.52 B2: a query finds other forms of the word (koupelna → koupelen, stavby → stavba, služeb ↔ služby)', $b352Pairs, [true, true, true, true, true]);

check('3.52 B2: a word matches the start of a text word – "IT" finds IT, not "sit amet"; two letters are searched', [
    SiteSearch::rank(SiteSearch::words('IT', 'cs'), [['title' => 'Lorem', 'text' => 'Lorem ipsum dolor sit amet.']], true),
    array_column(SiteSearch::rank(SiteSearch::words('IT', 'cs'), [['title' => 'Lorem', 'text' => 'sit amet'], ['title' => 'IT služby', 'text' => 'Správa sítí.']], true), 'title'),
    SiteSearch::words('a', 'cs'), array_column(SiteSearch::words('AI 5G', 'cs'), 'word')],
    [[], ['IT služby'], [], ['ai', '5g']]);

$b352Candidates = [
    ['title' => 'Kontakt', 'path' => 'kontakt', 'text' => 'Napište nám, rekonstrukce koupelen do týdne.'],
    ['title' => 'Rekonstrukce koupelen', 'path' => 'koupelny', 'text' => 'Koupelny na klíč, rekonstrukce bytů.'],
    ['title' => 'Kuchyně', 'path' => 'kuchyne', 'text' => 'Kuchyně na míru.'],
];
check('3.52 B1: SiteSearch::rank() – the title outweighs the text, every word needed; with some words (fallback) the best partial match first', [
    array_column(SiteSearch::rank(SiteSearch::words('koupelna rekonstrukce', 'cs'), $b352Candidates, true), 'path'),
    SiteSearch::rank(SiteSearch::words('koupelna kuchyne', 'cs'), $b352Candidates, true),
    array_column(SiteSearch::rank(SiteSearch::words('koupelna kuchyne', 'cs'), $b352Candidates, false), 'path')],
    [['koupelny', 'kontakt'], [], ['kuchyne', 'koupelny', 'kontakt']]);

check('3.52 B1: a fresh news item\'s bonus and the exact form break a tie; an equal score keeps the order given', [
    array_column(SiteSearch::rank(SiteSearch::words('stavba', 'cs'), [['title' => 'A', 'text' => 'stavby'], ['title' => 'B', 'text' => 'stavba']], true), 'title'),
    array_column(SiteSearch::rank(SiteSearch::words('stavba', 'cs'), [['title' => 'A', 'text' => 'stavba'], ['title' => 'B', 'text' => 'stavba', 'bonus' => 3]], true), 'title'),
    array_column(SiteSearch::rank(SiteSearch::words('stavba', 'cs'), [['title' => 'A', 'text' => 'stavba'], ['title' => 'B', 'text' => 'stavba']], true), 'title')],
    [['B', 'A'], ['B', 'A'], ['A', 'B']]);

// B3: the snippet – around the place with the most words, the matched words marked, everything escaped
$b352Long = str_repeat('Úvodní věta bez hledaného. ', 20) . 'Tady je <koupelna> & obklady. ' . str_repeat('Konec textu. ', 20);
$b352Snippet = SiteSearch::snippetHtml(SiteSearch::snippet($b352Long, SiteSearch::words('koupelny obklad', 'cs')));
check('3.52 B3: SiteSearch::snippet() – the window around the matches, … at both ends, <mark> on whole words, the text escaped', [
    str_starts_with($b352Snippet, '…'), str_ends_with($b352Snippet, '…'), str_contains($b352Snippet, '&lt;<mark>koupelna</mark>&gt; &amp; <mark>obklady</mark>'),
    substr_count($b352Snippet, '<mark>'), SiteSearch::snippetText(SiteSearch::snippet('Krátký text.', SiteSearch::words('nic', 'cs')))],
    [true, true, true, 2, 'Krátký text.']);
check('3.52 B3: a letter that normalizes to two (ß, æ) does not shift the snippet – the marked word is the one in the text', [
    SiteSearch::snippetHtml(SiteSearch::snippet('Die Straße und die Größe der Kunden.', SiteSearch::words('kunden', 'de'))),
    SiteSearch::snippetHtml(SiteSearch::snippet('Ærø – Straße, Kunden', SiteSearch::words('strasse', 'de')))],
    ['Die Straße und die Größe der <mark>Kunden</mark>.', 'Ærø – <mark>Straße</mark>, Kunden']);

// 3.53 (A5): one CJK character is a whole word – the form asks for one character and the server decides (Search::longEnough)
check('3.52 B2, 3.53 A5: the Search element lets the server decide the length (minlength 1); two characters or one CJK character search', [SiteSearch::MIN_LENGTH,
    str_contains(Kaleta\Builder\Elements\Search::render(['id' => 'x', 'obsah' => ['napoveda' => '', 'tlacitko' => 'Go']], '', '', new Kaleta\Builder\Context(new Kaleta\Core\App([]))), 'minlength="1"'),
    array_map(Kaleta\Core\Search::longEnough(...), ['a', '5', 'ab', '茶', '물', 'カ', ' a ', 'ж'])],
    [2, true, [false, false, true, true, true, true, false, false]]);

check('3.52 B1: the news index is filled by a background job, no longer on a search; the news repository has no search of its own', [
    isset(Kaleta\Core\Scheduler::JOBS['search_index'], Kaleta\Core\Scheduler::jobs()['search_index']), method_exists(Kaleta\Front\NewsRepository::class, 'search')],
    [true, false]);

check('3.52 B1: MCP search_site – read-only, English only, one find_tools away, results marked', [
    Catalog::TOOLS['search_site'] ?? null, Kaleta\Mcp\Translator::czech('search_site'), in_array('search_site', Catalog::ON_DEMAND, true),
    Kaleta\Mcp\Untrusted::TOOLS['search_site'] ?? null, method_exists(Kaleta\Mcp\Tools::class, Catalog::method('search_site'))],
    [['read', ''], 'search_site', true, ['results'], true]);
