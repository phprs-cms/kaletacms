<?php

declare(strict_types=1);

/* ---------- plain_text(): stored HTML as one line of text (meta and og description, JSON-LD, feeds, llms.txt) ---------- */
check('plain_text: entity jako znaky, bez značek', plain_text('<p>Výstavba zahájena v&nbsp;květnu 2023&nbsp;máme <b>R&amp;D</b>&hellip;</p><p>Druhý</p>'), 'Výstavba zahájena v květnu 2023 máme R&D… Druhý');
check('plain_text: zkrácení', plain_text(str_repeat('slovo&nbsp;', 60), 20), 'slovo slovo slovo s…');
$nbspIntro = '<p>Jsme rádi, že jsme v&nbsp;prosinci 2022&nbsp;získali územní rozhodnutí.</p><p>R&amp;D &lt;nově&gt;</p>';
check('plain_text: meta description z úvodu novinky se escapuje jednou (žádné &amp;nbsp;)', e(plain_text($nbspIntro, 300)), 'Jsme rádi, že jsme v prosinci 2022 získali územní rozhodnutí. R&amp;D &lt;nově&gt;');
check('plain_text: popis v JSON-LD je text bez entit a bez značek', json_encode(['description' => plain_text($nbspIntro, 300)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), '{"description":"Jsme rádi, že jsme v prosinci 2022 získali územní rozhodnutí. R&D \\u003Cnově\\u003E"}');
check('plain_text: popisy webu jdou přes plain_text, ne přes holé strip_tags', array_filter([
    'Kernel' => preg_match('/strip_tags\(\$(newsItem|category|tag)\b|strip_tags\(\(string\) \$tag/', (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php')),
    'Seo' => preg_match("/trim\\(strip_tags\\(\\\$c\\['uvod'\\]\\)\\)/", (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Seo.php')),
]), []);

