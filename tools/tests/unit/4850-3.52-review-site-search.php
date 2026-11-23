<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SiteSearch;

/* ---------- 3.52 review: the site search – letters, scripts, snippets, retention, the icon's popover ---------- */

check('3.52 review: a word of letters normalize() drops (Chinese, Cyrillic) stays as typed, in lower case, raw; Latin words as before', [
    SiteSearch::words('北京 Москва hotel C++ Straße', 'de'), array_column(SiteSearch::words('北京hotel', 'zh'), 'word')],
    [[['word' => '北京', 'terms' => ['北京'], 'raw' => true], ['word' => 'москва', 'terms' => ['москва'], 'raw' => true], ['word' => 'hotel', 'terms' => ['hotel']], ['word' => 'strasse', 'terms' => ['strasse', 'strass']]], ['hotel']]);

check('3.52 review: a raw word is found anywhere in the title or the text (Chinese has no spaces), the title first', [
    array_column(SiteSearch::rank(SiteSearch::words('北京', 'zh'), [['title' => '上海', 'text' => '我们在北京有办公室'], ['title' => '北京市政府', 'text' => ''], ['title' => '上海', 'text' => '']], true), 'title'),
    array_column(SiteSearch::rank(SiteSearch::words('Москва', 'ru'), [['title' => 'Офис', 'text' => 'Москва, центр'], ['title' => 'Paris', 'text' => '']], true), 'title')],
    [['北京市政府', '上海'], ['Офис']]);

check('3.52 review: ß, ł, æ and Arabic taa marbuta match their normalized query in PHP – FOLDED names the first letter each becomes', [
    array_map(fn (string $q, string $text): bool => SiteSearch::rank(SiteSearch::words($q, 'de'), [['title' => '', 'text' => $text]], true) !== [],
        ['strasse', 'Straße', 'uslugi', 'aeble', 'مدرسه'], ['Straße 5', 'Die Strasse', 'Nasze usługi', 'Æble', 'مدرسة']),
    array_filter(SiteSearch::FOLDED, fn (string $ascii, string $letter): bool => !str_starts_with(Search::normalize($letter), $ascii), ARRAY_FILTER_USE_BOTH)],
    [[true, true, true, true, true], []]);
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

check('3.52 review: searched terms older than KEEP_DAYS go with the daily clean-up, listed in Retention (smoke 4850 runs it)', [
    SiteSearch::KEEP_DAYS, Kaleta\Core\Retention::FIXED['search_queries'][1] ?? null,
    array_key_exists('search_queries', Kaleta\Core\Notifications::cleanups(new Kaleta\Core\App([])))],
    [400, 400, true]);

check('3.52 review: the icon\'s popover hangs from the other edge of the icon, or from the edge of the screen, when it would leave it', [
    str_contains(Kaleta\Builder\Elements\Search::baseCss(), 'position-try-fallbacks: flip-inline, --ka-hledani-okraj;'),
    str_contains(Kaleta\Builder\Elements\Search::baseCss(), '@position-try --ka-hledani-okraj {')],
    [true, true]);
