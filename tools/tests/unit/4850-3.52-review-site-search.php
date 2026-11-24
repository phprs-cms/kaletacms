<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SiteSearch;

/* ---------- 3.52 review: the site search – letters, scripts, snippets, retention, the icon's popover ---------- */

// 3.53 (A5): the search reads the version 2 index – Cyrillic is an ordinary word, only Chinese and kana are raw, and a
// token of two scripts gives both parts
check('3.52 review, 3.53 A5: a Chinese word is raw, a Cyrillic word an ordinary one; Latin words as before; "北京hotel" gives both', [
    SiteSearch::words('北京 Москва hotel C++ Straße', 'de'), array_column(SiteSearch::words('北京hotel', 'zh'), 'word')],
    [[['word' => '北京', 'terms' => ['北京'], 'raw' => true], ['word' => 'москва', 'terms' => ['москва']], ['word' => 'hotel', 'terms' => ['hotel']], ['word' => 'strasse', 'terms' => ['strasse', 'strass']]], ['北京', 'hotel']]);

check('3.52 review: a raw word is found anywhere in the title or the text (Chinese has no spaces), the title first', [
    array_column(SiteSearch::rank(SiteSearch::words('北京', 'zh'), [['title' => '上海', 'text' => '我们在北京有办公室'], ['title' => '北京市政府', 'text' => ''], ['title' => '上海', 'text' => '']], true), 'title'),
    array_column(SiteSearch::rank(SiteSearch::words('Москва', 'ru'), [['title' => 'Офис', 'text' => 'Москва, центр'], ['title' => 'Paris', 'text' => '']], true), 'title')],
    [['北京市政府', '上海'], ['Офис']]);

// 3.53 (A5): no FOLDED list any more – the index is folded by Search::fold, so ß ł ø æ meet their plain query in SQL too
check('3.52 review, 3.53 A5: ß, ł, ø, æ and Arabic taa marbuta match their normalized query; the FOLDED branch is gone', [
    array_map(fn (string $q, string $text): bool => SiteSearch::rank(SiteSearch::words($q, 'de'), [['title' => '', 'text' => $text]], true) !== [],
        ['strasse', 'Straße', 'uslugi', 'aeble', 'oresund', 'مدرسه'], ['Straße 5', 'Die Strasse', 'Nasze usługi', 'Æble', 'Øresund', 'مدرسة']),
    defined(SiteSearch::class . '::FOLDED'), Search::fold('ßłøæ')],
    [[true, true, true, true, true, true], false, 'ssloae']);
// "strasse" finds "Hauptstraße" only at a word start: the text word is "hauptstrasse" – a word starting with "strasse" is needed
check('3.52 review: the start of a word still counts – "strasse" finds "Straße 5", "hauptstrasse" finds "Hauptstraße"', [
    SiteSearch::rank(SiteSearch::words('strasse', 'de'), [['title' => '', 'text' => 'Straße 5']], true) !== [],
    SiteSearch::rank(SiteSearch::words('hauptstrasse', 'de'), [['title' => '', 'text' => 'Hauptstraße 5']], true) !== []],
    [true, true]);

$r352Text = str_repeat('y ', 10) . 'alfa ' . str_repeat('x ', 27) . 'omega konec';
check('3.52 review: the snippet prints the window it chose – both words 28 apart, fewer words before the first; Chinese and Cyrillic marked', [
    SiteSearch::snippetHtml(SiteSearch::snippet($r352Text, SiteSearch::words('alfa omega', 'cs'))),
    SiteSearch::snippetHtml(SiteSearch::snippet('我们在北京有办公室。', SiteSearch::words('北京', 'zh'))),
    SiteSearch::snippetHtml(SiteSearch::snippet('Наш офис: Москва, центр.', SiteSearch::words('москва', 'ru')))],
    ['…y <mark>alfa</mark>' . str_repeat(' x', 27) . ' <mark>omega</mark>…', '我们在<mark>北京</mark>有办公室。', 'Наш офис: <mark>Москва</mark>, центр.']);

check('3.52 review: Search::newsWords – a word normalizing to one letter ("C++" → "c") is looked for in the title and the address only', [
    Search::newsWords('C++')[1], count(Search::newsWords('stavby')[1])],
    [['%C++%', '%C++%'], 3]);

check('3.52 review: Search::newsWords – a plain one-letter word ("dům v Praze") narrows nothing, so the phrase in the text still matches', [
    str_contains(Search::newsWords('dům v Praze')[0], 'OR 1 = 1)'), SiteSearch::FALLBACK_PAGES],
    [true, 500]);

check('3.52 review: searched terms older than KEEP_DAYS go with the daily clean-up, listed in Retention (smoke 4850 runs it)', [
    SiteSearch::KEEP_DAYS, Kaleta\Core\Retention::FIXED['search_queries'][1] ?? null,
    array_key_exists('search_queries', Kaleta\Core\Notifications::cleanups(new Kaleta\Core\App([])))],
    [400, 400, true]);

check('3.52 review: the icon\'s popover hangs from the other edge of the icon, or from the edge of the screen, when it would leave it', [
    str_contains(Kaleta\Builder\Elements\Search::baseCss(), 'position-try-fallbacks: flip-inline, --ka-hledani-okraj;'),
    str_contains(Kaleta\Builder\Elements\Search::baseCss(), '@position-try --ka-hledani-okraj {')],
    [true, true]);
