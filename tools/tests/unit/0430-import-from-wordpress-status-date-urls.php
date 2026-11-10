<?php

declare(strict_types=1);

/* ---------- import from WordPress: status, date, URLs ---------- */
$wpStatuses = [];
foreach (['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'nesmysl'] as $wpS) {
    $wpM = Kaleta\Core\WpImport::articleStatus($wpS);
    $wpStatuses[$wpS] = $wpM === null ? 'vynechat' : ($wpM['visible'] ? 'vydany' : 'koncept');
}
check('WpImport::stavClanku', $wpStatuses, ['publish' => 'vydany', 'future' => 'vydany', 'draft' => 'koncept', 'pending' => 'koncept', 'private' => 'vynechat', 'trash' => 'vynechat', 'auto-draft' => 'vynechat', 'inherit' => 'vynechat', 'nesmysl' => 'vynechat']);
check('WpImport::stavClanku: příspěvek chráněný heslem se nezveřejní', Kaleta\Core\WpImport::articleStatus('publish', true), ['visible' => 0]);
check('WpImport::datum: místní čas starého webu', Kaleta\Core\WpImport::date(['datum' => '2026-05-12 09:30:00', 'datum_gmt' => '2026-05-12 07:30:00']), '2026-05-12 09:30:00');
check('WpImport::datum: koncept s nulovým datem dostane dnešek', Kaleta\Core\WpImport::date(['datum' => '0000-00-00 00:00:00', 'datum_gmt' => '0000-00-00 00:00:00', 'vydano' => ''], 1789000000), date('Y-m-d H:i:s', 1789000000));
$wpTaken = ['lavka', 'lavka-2'];
check('WpImport::volnaAdresa: obsazená adresa dostane číslo', Kaleta\Core\WpImport::availableSlug('lavka', fn (string $a): bool => in_array($a, $wpTaken, true)), 'lavka-3');
check('WpImport::volnaAdresa: volná zůstává', Kaleta\Core\WpImport::availableSlug('most', fn (string $a): bool => in_array($a, $wpTaken, true)), 'most');
check('WpImport::staraCesta', array_map(Kaleta\Core\WpImport::oldPath(...), ['https://stary.example/2026/05/lavka/', 'https://stary.example/?p=104', 'https://stary.example/blog/p%C5%99%C3%ADklad/', 'https://stary.example/' . str_repeat('x', 300)]), ['2026/05/lavka', '', 'blog/příklad', '']);
// 3.18.2: a raw emoji or accented letter in a sitemap address (farmakamenicany.sk) – parse_url() alone turned its bytes 0x80–0x9F into "_"
check('3.18.2 WpImport::oldPath – raw UTF-8 in the address stays intact', array_map(Kaleta\Core\WpImport::oldPath(...), ["https://stary.example/oslavila-10-rokov-\u{1F389}/", 'https://stary.example/gratulujeme-🏆/', 'https://stary.example/príklad/']),
    ["oslavila-10-rokov-\u{1F389}", 'gratulujeme-🏆', 'príklad']);
check('3.18.2 WebImport::normalize – raw UTF-8 becomes percent-encoding, not "_"', Kaleta\Core\WebImport::path(Kaleta\Core\WebImport::normalize("https://stary.example/oslavila-\u{1F389}/")), "oslavila-\u{1F389}");
check('WpImport::bezRozmeru', array_map(Kaleta\Core\WpImport::withoutSize(...), ['https://s.example/u/foto-300x200.jpg', 'https://s.example/u/foto-1024x683.JPG?ver=2', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']), ['https://s.example/u/foto.jpg', 'https://s.example/u/foto.JPG', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']);
check('WpImport::zdroj: doména starého webu, nejvýš 40 znaků', [Kaleta\Core\WpImport::source('https://WWW.Stary.example/blog'), Kaleta\Core\WpImport::source(''), strlen(Kaleta\Core\WpImport::source('https://' . str_repeat('a', 60) . '.example'))], ['wp:stary.example', 'wp', 40]);
check('ExportWebu::cesta: jen názvy exportů, nic mimo složku', [Kaleta\Core\SiteExport::path('../config.php'), Kaleta\Core\SiteExport::path('export-20260921-101500.zip/../../config.php'), Kaleta\Core\SiteExport::path('kaleta-20260918-130917-rucni-7d777965.sql.gz')], [null, null, null]);

