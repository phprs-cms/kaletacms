<?php

declare(strict_types=1);

use Kaleta\Core\Language;
use Kaleta\Core\Search;
use Kaleta\Core\SiteSearch;

/* ---------- 3.53 B1–B3: punctuation words, one CJK character and long CJK words, word forms in more languages ---------- */

// B1: a word with several pieces in its normal form is a phrase from the start of a word; one plain character no filter
$b940News = fn (string $q): array => Search::newsWords($q, '');
check('3.53 B1: newsWords – "R&B", "AT&T", "a.s.", "e-mail" are phrases from the start of a word; Chinese and Cyrillic reach the index; "v" no filter', [
    $b940News('R&B')[1][2], $b940News('AT&T')[1][2], $b940News('a.s.')[1][2], $b940News('e-mail')[1][2], str_contains($b940News('R&B')[0], "CONCAT(' ', hledani) LIKE ?"),
    $b940News('北京')[1][2], $b940News('Москва')[1][2], str_contains($b940News('v')[0], '1 = 1'), str_contains($b940News('½')[0], '1 = 1'), $b940News('C++')[1]],
    ['% r b%', '% at t%', '% a s%', '% e mail%', true, '%北京%', '%москва%', true, false, ['%C++%', '%C++%']]);
check('3.53 B1: no query of letters lets every news item through – only one plain character does', array_map(fn (string $q): bool => str_contains($b940News($q)[0], '1 = 1'),
    ['Москва', 'Αθήνα', '北京', '茶', '서울', 'R&B', 'e-mail', 'dům', 'v', '5']), [false, false, false, false, false, false, false, false, true, true]);

check('3.53 B1: the site search – a part with a one-letter piece is one phrase, "C++" a title word, "dům v Praze" as before', [
    SiteSearch::words('R&B e-mail s.r.o.', 'cs'), SiteSearch::words('C# C++', 'en'), array_column(SiteSearch::words('dům v Praze Wi-Fi', 'cs'), 'word')],
    [[['word' => 'r b', 'terms' => ['r b']], ['word' => 'e mail', 'terms' => ['e mail']], ['word' => 's r o', 'terms' => ['s r o']]],
        [['word' => 'c#', 'terms' => ['c#'], 'title' => true], ['word' => 'c++', 'terms' => ['c++'], 'title' => true]], ['dum', 'praze', 'wi', 'fi']]);
$b940Rank = fn (string $q, array $candidates): array => array_column(SiteSearch::rank(SiteSearch::words($q, 'en'), $candidates, true), 'title');
check('3.53 B1: "R&B" finds R&B, not "sektor bank" or "for business"; "C++" finds the title with C++ only; the phrase is marked in the snippet', [
    $b940Rank('R&B', [['title' => 'Banks', 'text' => 'Sektor bank for business'], ['title' => 'Night', 'text' => 'Soul and R&B tonight']]),
    $b940Rank('C++', [['title' => 'C basics', 'text' => 'c c c'], ['title' => 'Kurz C++', 'text' => '']]),
    SiteSearch::snippetHtml(SiteSearch::snippet('Soul a R&B, bez e-mailu.', SiteSearch::words('R&B', 'cs')))],
    [['Night'], ['Kurz C++'], 'Soul a <mark>R</mark>&amp;<mark>B</mark>, bez e-mailu.']);
check('3.53 B1: the scan – a title word is a collation LIKE on the title and adds no occurrence; without a title it matches nothing, never "1 = 1"', [
    SiteSearch::indexSql(SiteSearch::words('C++', 'en'), 'h', null, 't')['conditions'][0][1], SiteSearch::indexSql(SiteSearch::words('C++', 'en'), 'h')['conditions'][0][0],
    SiteSearch::indexSql(SiteSearch::words('C++', 'en'), 'h')['occurrences'][0]],
    [['%c++%'], '(0 = 1)', '0']);

// B2: one Han / Hangul character is a word, one Latin letter not; a long Chinese word by its pairs, only in the partial pass
check('3.53 B2: "茶" and "물" are words, "a" is not; "iPhone手机" gives both parts', [
    array_column(SiteSearch::words('茶', 'zh'), 'word'), array_column(SiteSearch::words('물', 'ko'), 'word'), SiteSearch::words('a', 'cs'),
    array_column(SiteSearch::words('iPhone手机', 'zh'), 'word'), Search::longEnough('茶'), Search::longEnough('a')],
    [['茶'], ['물'], [], ['iphone', '手机'], true, false]);
$b940Long = SiteSearch::words('北京大学医院', 'zh');
check('3.53 B2: bigramWords – a raw word of 3+ characters becomes at most BIGRAMS overlapping pairs; a 2-character or Latin query has none', [
    $b940Long[0]['terms'], SiteSearch::bigramWords($b940Long)[0]['terms'] ?? null, SiteSearch::bigramWords(SiteSearch::words('北京 hotel', 'zh')), SiteSearch::BIGRAMS],
    [['北京大学医院'], ['北京', '京大', '大学', '学医'], null, 4]);
check('3.53 B2: the pairs find a text with part of the word (partial pass), the exact word alone does not; the pair is marked in the snippet', [
    SiteSearch::rank($b940Long, [['title' => '', 'text' => '我们在北京有办公室']], false) !== [],
    SiteSearch::rank(SiteSearch::bigramWords($b940Long), [['title' => '', 'text' => '我们在北京有办公室']], false) !== [],
    SiteSearch::snippetHtml(SiteSearch::snippet('我们在北京有办公室', SiteSearch::bigramWords($b940Long)))],
    [false, true, '我们在<mark>北京</mark>有办公室']);

// B3: word forms – the hit table of the plan: a query finds another form of the word in a text of its language
$b940Hits = [
    ['pl', 'usługami', 'Nasze usługi'], ['pl', 'książki', 'Nowa książka'], ['hu', 'szolgáltatások', 'Szolgáltatás'], ['hu', 'Budapesten', 'Budapest'],
    ['es', 'servicios', 'Nuestro servicio'], ['es', 'clientes', 'Cliente'], ['pt', 'informações', 'Mais informação'], ['pt-br', 'serviços', 'Serviço'],
    ['fr', 'entrées', 'Une entrée'], ['fr', 'chevaux', 'Le cheval'], ['it', 'informazioni', 'Informazione'], ['it', 'servizi', 'Servizio'],
    ['ru', 'новостей', 'Новости компании'], ['ru', 'Москве', 'Москва'], ['uk', 'послуги', 'Наша послуга'], ['cs', 'Москве', 'Москва'],
    ['de', 'Kindern', 'Kinder'], ['en', 'companies', 'Company'], ['cs', 'velkého', 'Velký dům'], ['sk', 'veľkého', 'Veľký dom'], ['cs', 'zahradního', 'Zahradní domek'],
];
check('3.53 B3: a query finds other forms of its word in pl, hu, es, pt, fr, it, ru, uk (and a Russian word on a Czech site), plus de, en, cs, sk additions',
    array_map(fn (array $h): string => $h[0] . ':' . $h[1] . '=' . (SiteSearch::rank(SiteSearch::words($h[1], $h[0]), [['title' => '', 'text' => $h[2]]], true) !== [] ? 'y' : 'n'), $b940Hits),
    array_map(fn (array $h): string => $h[0] . ':' . $h[1] . '=y', $b940Hits));
check('3.53 B3: the script decides – a Latin word on a Russian site and a Cyrillic word on a Serbian site stay; languages of NO_STEMMER stay', [
    Search::stem('hotels', 'ru'), Search::stem('москве', 'sr'), Search::stem('москве', 'cs'), Search::stem('huizen', 'nl'), Search::stem('αθηνας', 'el'), Search::stem('servicios', 'es')],
    ['hotels', 'москве', 'москв', 'huizen', 'αθηνας', 'servici']);

// B3: the invariant over every ending list – the stem is a prefix of the word with at least STEM_MIN letters, or the word
$b940Bad = [];
foreach (Search::ENDINGS as $b940Language => $b940Endings) {
    foreach ($b940Endings as $b940Ending) {
        foreach (['ab', 'abc', 'abcd', 'abcde', 'дом', 'книга'] as $b940Root) {
            $b940Word = $b940Root . $b940Ending;
            $b940Stem = Search::stem($b940Word, $b940Language);
            if (!str_starts_with($b940Word, $b940Stem) || ($b940Stem !== $b940Word && mb_strlen($b940Stem) < Search::STEM_MIN)) {
                $b940Bad[] = $b940Language . ':' . $b940Word . '→' . $b940Stem;
            }
        }
    }
}
check('3.53 B3: every stem is a prefix of its word and keeps at least STEM_MIN letters (all ending lists, Latin and Cyrillic roots)', $b940Bad, []);

$b940Codes = array_values(array_unique(array_map(Language::base(...), explode('|', Language::CODES))));
check('3.53 B3: every language of Language::CODES has endings or is in NO_STEMMER, never both; endings are in the normal form', [
    array_values(array_filter($b940Codes, fn (string $c): bool => !isset(Search::ENDINGS[$c]) && !in_array($c, Search::NO_STEMMER, true))),
    array_values(array_intersect(array_keys(Search::ENDINGS), Search::NO_STEMMER)),
    array_values(array_filter(array_merge(...array_values(Search::ENDINGS)), fn (string $e): bool => Search::normalize($e) !== $e))],
    [[], [], []]);
