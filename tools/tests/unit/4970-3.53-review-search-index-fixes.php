<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SearchIndex;
use Kaleta\Core\SiteSearch;

/* ---------- 3.53 review: Latin next to CJK, the last pair of a CJK word, elision, the occurrences switch, a stuck index ---------- */

// Chinese and Japanese write a Latin word or a number without spaces around it: the index and the query cut them apart
$r970Text = SearchIndex::text('stranky', ['titulek' => '新闻', 'text' => '<p>使用WordPress搭建网站，最新のiPhone15を発売。价格100元</p>']);
$r970Find = fn (string $q, string $language = 'zh'): bool => SiteSearch::rank(SiteSearch::words($q, $language), [['title' => '新闻', 'normal' => $r970Text]], true) !== [];
check('3.53 review: a Latin word or a number inside Chinese or Japanese text is a word of the index – "wordpress", "iphone", "iphone15" and "100" find the page, CJK words still do', [
    $r970Text, $r970Find('wordpress'), $r970Find('iPhone'), $r970Find('iphone15'), $r970Find('100'), $r970Find('网站'), $r970Find('搭建网站'), $r970Find('WordPress搭建')],
    ['新闻 使用 wordpress 搭建网站 最新の iphone15 を発売 价格 100 元', true, true, true, true, true, true, true]);
check('3.53 review: Hangul is cut off a Latin word or a digit (갤럭시S24, 3월), never off another Hangul letter; Latin and Cyrillic text is unchanged', [
    Search::normalize('갤럭시S24 출시 3월'), SiteSearch::rank(SiteSearch::words('s24', 'ko'), [['title' => '', 'normal' => Search::normalize('갤럭시S24 출시')]], true) !== [],
    Search::normalize('Kaleta CMS 3.53 – Москва'), Search::normalize('A股')],
    ['갤럭시 s24 출시 3 월', true, 'kaleta cms 3 53 москва', 'a 股']);

// bigramWords: a word of n characters has n - 1 overlapping pairs (at most BIGRAMS)
check('3.53 review: bigramWords – a 3-character word has 2 pairs, a 4-character word 3 (the last pair too), a 6-character word BIGRAMS; "北京大学" finds a page with "大学" only', [
    SiteSearch::bigramWords(SiteSearch::words('北京大', 'zh'))[0]['terms'] ?? null, SiteSearch::bigramWords(SiteSearch::words('北京大学', 'zh'))[0]['terms'] ?? null,
    count(SiteSearch::bigramWords(SiteSearch::words('北京大学医院', 'zh'))[0]['terms'] ?? []),
    SiteSearch::rank(SiteSearch::bigramWords(SiteSearch::words('北京大学', 'zh')) ?? [], [['title' => '大学', 'normal' => '大学 招生']], false) !== []],
    [['北京', '京大'], ['北京', '京大', '大学'], SiteSearch::BIGRAMS, true]);

// an apostrophe ends a part: the elided article and the possessive s are too short to be words
$r970Rank = fn (string $q, string $language): array => array_column(SiteSearch::rank(SiteSearch::words($q, $language), [
    ['title' => 'Hôtel du Parc', 'text' => 'Notre hôtel au centre'], ['title' => 'Women', 'text' => 'Women in tech'], ['title' => 'Emploi', 'text' => 'Offres d’emploi']], true), 'title');
check('3.53 review: "l\'hôtel", "d’emploi", "women\'s" search "hotel", "emploi", "women" – the plain word is found; "R&B" and "e-mail" stay phrases', [
    SiteSearch::words("l'hôtel", 'fr'), array_column(SiteSearch::words('d’emploi women\'s rockʼn\'roll', 'en'), 'word'),
    $r970Rank("l'hôtel", 'fr'), $r970Rank('women\'s', 'en'), $r970Rank('d’emploi', 'fr'), array_column(SiteSearch::words('R&B e-mail', 'en'), 'word')],
    [[['word' => 'hotel', 'terms' => ['hotel']]], ['emploi', 'women', 'rock', 'roll'], ['Hôtel du Parc'], ['Women'], ['Emploi'], ['r b', 'e mail']]);

// the occurrences ordering is a parameter (its default the constant tools/bench-search.php turns off)
check('3.53 review: indexSql – without the occurrences ordering the scan orders by "0", the conditions stay', [
    SiteSearch::indexSql(SiteSearch::words('media', 'cs'), 'h', byOccurrences: false)['occurrences'],
    SiteSearch::indexSql(SiteSearch::words('media', 'cs'), 'h', byOccurrences: false)['conditions'] === SiteSearch::indexSql(SiteSearch::words('media', 'cs'), 'h')['conditions']],
    [['0', []], true]);

// stuck(): "waiting:done" of the last run with time – a burst of new rows the job is working on is no warning
$r970Settings = new Kaleta\Core\Settings(new Kaleta\Core\Db('sqlite::memory:', '', ''));
$r970Stuck = function (string $value) use ($r970Settings): bool {
    (new ReflectionProperty($r970Settings, 'values'))->setValue($r970Settings, ['db_version' => '141', SearchIndex::WAITING => $value]);

    return SearchIndex::stuck($r970Settings);
};
check('3.53 review: stuck only when rows wait and the last run filled or rewrote none – 4900 new rows while a run filled 100 are work, not a fault', [
    $r970Stuck('4900:100'), $r970Stuck('0:4900'), $r970Stuck('0:0'), $r970Stuck('12:0'), $r970Stuck(''), $r970Stuck(':40')],
    [false, false, false, true, false, false]);
