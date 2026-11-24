<?php

declare(strict_types=1);

use Kaleta\Core\Search;
use Kaleta\Core\SiteSearch;

/* ---------- 3.53 A1: normalize() version 2 – every script, every Latin alphabet ---------- */

check('3.53 A1: the golden corpus of version 1 gives the same tokens – Czech HTML, Arabic with marks and letter forms, Latin with digits', [
    Search::normalize('<p>Nábřeží&nbsp;<b>Vltavy</b></p><h2>Proměna!</h2>'),
    Search::normalize('<p>الأَمْنُ والسلامة – Kinesio Tape (5 cm)</p>'),
    Search::normalize('Příliš žluťoučký kůň, 5 cm!'),
    Search::normalize('مَدْرَسَةٌ ٱلْإِسْلَامِيَّة آمِنَة عَلَى طـــويل'),
    Search::normalize('Grüße aus der Straße – Æble, Øresund, Łódź, Đakovo'),
    Search::VERSION],
    ['nabrezi vltavy promena', 'الامن والسلامه kinesio tape 5 cm', 'prilis zlutoucky kun 5 cm', 'مدرسه الاسلاميه امنه علي طويل', 'grusse aus der strasse aeble oresund lodz dakovo', 2]);

// defect D1 of the 3.53 maps: letters remove_diacritics() does not know split a word in two ("książka" → "ksi ka")
check('3.53 A1: Polish, Vietnamese, Romanian, Turkish, Latvian, Lithuanian, Icelandic, Maltese, Catalan and Esperanto words stay whole', array_map(fn (string $t): string => Search::normalize($t), [
    'Książka usług Łódź się', 'Tiếng Việt Nam', 'Științe Țara București Ştiinţe', 'İstanbul IĞDIR şeker', 'Rīga ģimene ķēķis ļoti ņemt ūdens',
    'Ėglė įmonė ųsai', 'Þórsmörk Ðað', 'Ħamrun Ġgantija ċirku', 'col·lecció', 'ĉiuĵaŭde ŝipo ĝardeno']), [
    'ksiazka uslug lodz sie', 'tieng viet nam', 'stiinte tara bucuresti stiinte', 'istanbul igdir seker', 'riga gimene kekis loti nemt udens',
    'egle imone usai', 'thorsmork dad', 'hamrun ggantija cirku', 'colleccio', 'ciujaude sipo gardeno']);

check('3.53 A1: a text written decomposed (NFD) gives the same tokens as the composed one (NFC); Turkish İ and Ğ in capitals', [
    Search::normalize("Na\u{0301}bre\u{030C}z\u{030C}i\u{0301} Vie\u{0302}\u{0323}t"), Search::normalize('Nábřeží Việt'),
    Search::fold('İSTANBUL'), Search::fold('IĞDIR'), Search::fold("I\u{0307}stanbul")],
    ['nabrezi viet', 'nabrezi viet', 'istanbul', 'igdir', 'istanbul']);

check('3.53 A1: no script is dropped – Cyrillic, Greek, Chinese, Korean and Hindi reach the index (Devanagari vowel signs stay)', [
    Search::normalize('Новости Київ Αθήνα 北京 서울 सेवा'), Search::normalize('日本語のテキスト、カタカナ'), Search::normalize('שלום עולם')],
    ['новости київ αθηνα 北京 서울 सेवा', '日本語のテキスト カタカナ', 'שלום עולם']);

check('3.53 A1: ё as е, Greek without accents and with one sigma; й, і, ї, є, ґ, ъ stay letters of their own (й is not и, also written decomposed)', [
    Search::normalize('Ёлка ёж'), Search::normalize('Ελληνικός λόγος ΆΈΉ'), Search::normalize('й и і ї є ґ ъ'), Search::normalize("и\u{0306}од") === Search::normalize('йод'),
    Search::normalize('йод') === Search::normalize('иод')],
    ['елка еж', 'ελληνικοσ λογοσ αεη', 'й и і ї є ґ ъ', true, false]);

check('3.53 A1: digits are \\p{Nd} only, superscripts become digits (m² → m2); full-width ASCII and half-width kana as typed elsewhere; ligatures', [
    Search::normalize('Plocha 50 m² a 3 m³'), Search::normalize('ＡＢＣ１２３ ｶﾞｷﾞ'), Search::normalize('½ ⅓ ① finance ﬁnance'), Search::normalize('٢٠٢٦ ۱۴۰۵ २०२६')],
    ['plocha 50 m2 a 3 m3', 'abc123 ガギ', 'finance finance', '٢٠٢٦ ۱۴۰۵ २०२६']);

check('3.53 A1: punctuation still separates – "C++", "R&B", "e-mail", "a.s." keep their letters as separate tokens', array_map(fn (string $t): string => Search::normalize($t), ['C++', 'R&B', 'e-mail', 'a.s.', '—']),
    ['c', 'r b', 'e mail', 'a s', '']);

// the marks in lists and snippets fold as the index does: "lodz" marks "Łódź", "stiinte" marks "Științe"
check('3.53 A1: search_marks() folds through Search::fold – a Polish, Romanian or Turkish word is marked by its plain query', [
    mark_words('Muzeum w Łodzi, Łódź', 'lodz'), mark_words('Științe aplicate', 'stiinte'), mark_words('İstanbul şehri', 'istanbul sehri')],
    ['Muzeum w <mark>Łodz</mark>i, <mark>Łódź</mark>', '<mark>Științe</mark> aplicate', '<mark>İstanbul</mark> <mark>şehri</mark>']);

// the site search reads the version 2 index (A5): Cyrillic is an ordinary word, Chinese stays raw, a mixed token gives both
check('3.53 A1, A5, B3: SiteSearch::words – a Polish word is one word, a Cyrillic word an ordinary one (with its Russian stem), Chinese raw', [
    array_column(SiteSearch::words('Książka usług', 'pl'), 'word'), SiteSearch::words('Москва 北京', 'ru'), array_column(SiteSearch::words('北京hotel', 'zh'), 'word')],
    [['ksiazka', 'uslug'], [['word' => 'москва', 'terms' => ['москва', 'москв']], ['word' => '北京', 'terms' => ['北京'], 'raw' => true]], ['北京', 'hotel']]);
