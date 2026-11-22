<?php

declare(strict_types=1);

/* ---------- 3.51 B7: links of a newsletter carry the campaign name only from five recipients ---------- */
use Kaleta\Core\Mailing as B734Mailing;

$b734Origin = 'https://firma.example';
check('3.51 B7: below TAG_MIN recipients no utm_campaign at all (source and medium stay); from it the subject slug and -n<id>; an anchor stays last; a query gets &', [
    B734Mailing::campaignUrl($b734Origin, 'https://firma.example/novinky/jaro', 'Jarní novinky', 12, 4),
    B734Mailing::campaignUrl($b734Origin, 'https://firma.example/novinky/jaro', 'Jarní novinky', 12, 5),
    B734Mailing::campaignUrl($b734Origin, 'https://firma.example/kontakt?x=1#mapa', 'Jarní novinky', 12, 300),
    B734Mailing::TAG_MIN],
    ['https://firma.example/novinky/jaro?utm_source=newsletter&utm_medium=email',
        'https://firma.example/novinky/jaro?utm_source=newsletter&utm_medium=email&utm_campaign=jarni-novinky-n12',
        'https://firma.example/kontakt?x=1&utm_source=newsletter&utm_medium=email&utm_campaign=jarni-novinky-n12#mapa', 5]);
check('3.51 B7: foreign links and links that already carry utm are untouched; a look-alike host is foreign', [
    B734Mailing::campaignUrl($b734Origin, 'https://jiny.example/stranka', 'Jaro', 3, 50),
    B734Mailing::campaignUrl($b734Origin, 'https://firma.example/a?utm_source=x', 'Jaro', 3, 50),
    B734Mailing::campaignUrl($b734Origin, 'https://firma.example.evil.example/a', 'Jaro', 3, 50)],
    ['https://jiny.example/stranka', 'https://firma.example/a?utm_source=x', 'https://firma.example.evil.example/a']);
