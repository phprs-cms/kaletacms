<?php

declare(strict_types=1);

/* ---------- 2.11 F1: screen mode (Front\Screen) ---------- */
check('2.11 Screen::seconds – within 5–60, empty = 10', array_map(Kaleta\Front\Screen::seconds(...), ['', '3', '90', '20', 0]), [10, 5, 60, 20, 10]);
$f1Secret = str_repeat('ab', 16);
check('2.11 Screen::opens – only on, with a secret, and the right one', [Kaleta\Front\Screen::opens(true, $f1Secret, $f1Secret), Kaleta\Front\Screen::opens(false, $f1Secret, $f1Secret), Kaleta\Front\Screen::opens(true, $f1Secret, str_repeat('ba', 16)),
    Kaleta\Front\Screen::opens(true, '', ''), Kaleta\Front\Screen::opens(true, $f1Secret, 'ABAB')], [true, false, false, false, false]);
check('2.11 Screen::dateFields – the first date-and-time field is the start, the second the end', [Kaleta\Front\Screen::dateFields([['klic' => 'place', 'typ' => 'text'], ['klic' => 'start', 'typ' => 'termin'], ['klic' => 'end', 'typ' => 'termin']]),
    Kaleta\Front\Screen::dateFields([['klic' => 'when', 'typ' => 'termin']]), Kaleta\Front\Screen::dateFields([['klic' => 'day', 'typ' => 'datum']])], [['start', 'end'], ['when', ''], null]);
$f1Course = ['nazev' => 'Kurzy', 'preset' => 'courses', 'pole' => [['klic' => 'start', 'popisek' => 'Start', 'typ' => 'termin'], ['klic' => 'end', 'popisek' => 'End', 'typ' => 'termin'], ['klic' => 'place', 'popisek' => 'Place', 'typ' => 'text'],
    ['klic' => 'price', 'popisek' => 'Price', 'typ' => 'cislo'], ['klic' => 'description', 'popisek' => 'Description', 'typ' => 'html'], ['klic' => 'image', 'popisek' => 'Image', 'typ' => 'obrazek']]];
$f1Slide = Kaleta\Front\Screen::card($f1Course, ['nazev' => ['Welding basics', 'text'], 'start' => ['2. 11. 2026 09:00', 'text'], 'end' => ['2. 11. 2026 16:00', 'text'], 'place' => ['Brno', 'text'], 'price' => ['1900', 'cislo'],
    'description' => ['<p>Long</p>', 'html'], 'image' => ['media/2026/kurz.jpg', 'obrazek']]);
check('2.11 Screen::card – a preset collection shows its card fields: the start as the date, the place as a line, the first image', [$f1Slide['kind'], $f1Slide['label'], $f1Slide['title'], $f1Slide['date'], $f1Slide['lines'], $f1Slide['text'], $f1Slide['image']],
    ['item', 'Kurzy', 'Welding basics', '2. 11. 2026 09:00', ['Brno'], '', 'media/2026/kurz.jpg']);
$f1Plain = ['nazev' => 'Stroje', 'preset' => '', 'pole' => [['klic' => 'photo', 'popisek' => 'Photo', 'typ' => 'obrazek'], ['klic' => 'model', 'popisek' => 'Model', 'typ' => 'text'], ['klic' => 'weight', 'popisek' => 'Weight', 'typ' => 'cislo'],
    ['klic' => 'about', 'popisek' => 'About', 'typ' => 'radky'], ['klic' => 'sheet', 'popisek' => 'Sheet', 'typ' => 'soubor'], ['klic' => 'extra', 'popisek' => 'Extra', 'typ' => 'text']]];
$f1Slide = Kaleta\Front\Screen::card($f1Plain, ['nazev' => ['CNC', 'text'], 'model' => ['X-200', 'text'], 'weight' => ['1200', 'cislo'], 'about' => [str_repeat('word ', 80), 'radky'], 'sheet' => ['/media/x.pdf', 'odkaz'], 'extra' => ['not shown', 'text']]);
check('2.11 Screen::card – without a preset the first three short fields: a number with its label, a longer text shortened, the rest left out', [$f1Slide['lines'], mb_strlen($f1Slide['text']) <= 240, str_ends_with($f1Slide['text'], '…'), $f1Slide['image']],
    [['X-200', 'Weight: 1200'], true, true, '']);
check('2.11 Screen::plain – formatted text as one line', Kaleta\Front\Screen::plain("<p>Open&nbsp;day</p>\n<ul><li>at  9</li></ul>"), 'Open day at 9');
check('2.11 screen: the reserved address, the settings and their export without the secret', [in_array('screen', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true), Kaleta\Core\Settings::DEFAULTS['screen_seconds'], Kaleta\Core\Settings::DEFAULTS['screen_mode'],
    in_array('screen_collections', Kaleta\Core\SiteExport::SETTINGS, true), in_array('screen_secret', Kaleta\Core\SiteExport::SETTINGS, true)], [true, '10', '0', true, false]);
