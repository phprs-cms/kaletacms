<?php

declare(strict_types=1);

/* ---------- numbers by language ---------- */
check('pocet: česky mezera jako oddělovač tisíců', Kaleta\Core\Language::runWith('cs', fn () => format_count(1234567)), "1\u{00A0}234\u{00A0}567");
check('pocet: anglicky čárka a desetinná tečka', Kaleta\Core\Language::runWith('en', fn () => format_count(12345.678, 2)), '12,345.68');
// 3.14.1: a number field for visitors – the separators of the site language, the decimals as stored; {{price_raw}} as stored
$num3141 = fn (string $lang): array => Kaleta\Core\Language::runWith($lang, fn (): array => array_map(Kaleta\Builder\Collections::displayNumber(...), ['1490', '4.20', '4,5', '-12000', '', 'on request', '1 490']));
check('3.14.1 Collections::displayNumber – 1 490 / 1,490 / 1.490, decimals kept (4.20), text and empty left as they are', [$num3141('cs'), $num3141('en'), $num3141('de')], [
    ["1\u{00A0}490", '4,20', '4,5', "-12\u{00A0}000", '', 'on request', "1\u{00A0}490"],
    ['1,490', '4.20', '4.5', '-12,000', '', 'on request', '1,490'],
    ['1.490', '4,20', '4,5', '-12.000', '', 'on request', '1.490']]);
$coll3141 = ['seo_link' => 'products', 'detail' => false, 'preset' => '', 'pole' => [['klic' => 'price', 'typ' => 'cislo', 'popisek' => 'Price']]];
$val3141 = Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Builder\Collections::values($coll3141, ['nazev' => 'Oak table', 'seo_link' => 'oak', 'datum' => '2026-10-18 10:00:00', 'data' => ['price' => '1490.50']], fn (string $p): string => $p));
check('3.14.1 Collections::values – {{price}} for visitors, {{price_raw}} as stored', [$val3141['price'], $val3141['price_raw']], [['1,490.50', 'text'], ['1490.50', 'text']]);
check('Soubory::velikost: anglicky desetinná tečka', Kaleta\Core\Language::runWith('en', fn () => Kaleta\Core\Files::size(3 * 1048576 + 524288)), '3.5 MB');

