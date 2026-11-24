<?php

declare(strict_types=1);

/* ---------- 3.52 A1: one search for admin lists and MCP – words in any order, without regard to diacritics (Db::likeWords) ---------- */
use Kaleta\Core\Db as A800Db;

$a800C = ' COLLATE utf8mb4_unicode_ci LIKE ?';
check('3.52 A1: likeWords() – every word in one of the columns (AND of ORs), extra spaces ignored, each column compared in utf8mb4_unicode_ci', [
    A800Db::likeWords(['a', 'b'], '  novak   jan '), A800Db::likeWords(['a'], ''), A800Db::likeWords(['a'], " \t "), A800Db::SEARCH_COLLATION],
    [['((a' . $a800C . ' OR b' . $a800C . ') AND (a' . $a800C . ' OR b' . $a800C . '))', ['%novak%', '%novak%', '%jan%', '%jan%']], ['1 = 1', []], ['1 = 1', []], 'utf8mb4_unicode_ci']);
check('3.52 A1: likeWords() – %, _ and \\ are literal; at most 6 words (or the given number) and 100 characters', [
    A800Db::likeWords(['a'], '50%_\\')[1], substr_count(A800Db::likeWords(['a'], 'a b c d e f g h')[0], ' AND ') + 1,
    count(A800Db::likeWords(['a'], 'a b c', maxWords: 2)[1]), strlen(A800Db::likeWords(['a'], str_repeat('x', 150))[1][0])],
    [['%50\%\_\\\\%'], 6, 2, 102]);
check('3.52 A1: likeJson() – the word as stored with the letters themselves (with and without escaped slashes) and with \\uXXXX; a quote as \\"', A800Db::likeJson('data', 'Dvořák/x"'),
    ['(data' . $a800C . ' OR data' . $a800C . ' OR data' . $a800C . ')', ['%Dvořák/x\\\\"%', '%Dvořák\\\\/x\\\\"%', '%Dvo\\\\u0159\\\\u00e1k\\\\/x\\\\"%']]);
check('3.52 A1: likeJson() – a plain word gives one pattern; broken UTF-8 never becomes a pattern that matches everything', [
    A800Db::likeJson('d', 'novak'), in_array('%%', A800Db::likeJson('d', "\xC3")[1], true)],
    [['(d' . $a800C . ')', ['%novak%']], false]);
check('3.52 A1: likeWords() – JSON columns and one more alternative per word ($more; null = none for that word)', A800Db::likeWords(['a'], 'x yy', ['j'],
    fn (string $w): ?array => strlen($w) > 1 ? ['n LIKE ?', ['%' . $w . '%']] : null),
    ['((a' . $a800C . ' OR (j' . $a800C . ')) AND (a' . $a800C . ' OR (j' . $a800C . ') OR n LIKE ?))', ['%x%', '%x%', '%yy%', '%yy%', '%yy%']]);

// A9: a customer by the digits of a phone number
check('3.52 A9: phoneDigits() – a word of digits, + and - with at least 4 digits is compared by its digits; anything else is not', [
    Kaleta\Core\Contacts::phoneDigits('777-123'), Kaleta\Core\Contacts::phoneDigits('+420'), Kaleta\Core\Contacts::phoneDigits('123'), Kaleta\Core\Contacts::phoneDigits('Jan42345')],
    [["REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '+', '') LIKE ?", ['%777123%']], null, null, null]);

// A7: news by a word of the intro or text – the index column hledani holds normalised text, so the word is normalised too
[$a800NewsSql, $a800NewsParams] = Kaleta\Core\Search::newsWords('Dvořák', 'c.');
check('3.52 A7: newsWords() – the headline and the address in the search collation, the index (intro, text, keywords) by the normalised word', [
    $a800NewsSql, $a800NewsParams, Kaleta\Core\Search::newsWords('—')[1]],
    ['((c.titulek' . $a800C . ' OR c.seo_link' . $a800C . ' OR c.hledani LIKE ?))', ['%Dvořák%', '%Dvořák%', '%dvorak%'], ['%—%', '%—%']]);

// the same columns in the admin list and its MCP tool (A1: admin and MCP together)
check('3.52 A1/A9: the searched columns are shared – pages (with the SEO title; 3.53: the text by its index, Core\SearchIndex), Media (with the caption), enquiries (with the topic and page)', [
    Kaleta\Core\SearchIndex::PAGE_COLUMNS, Kaleta\Admin\Modules\Media::SEARCH_COLUMNS, Kaleta\Admin\Modules\Enquiries::SEARCH_COLUMNS],
    [['titulek', 'seo_link', 'seo_titulek'], ['o.nazev', 'o.popis', 'o.obr_poloha'], ['email', 'formular', 'poznamka', 'tema', 'stranka']]);
