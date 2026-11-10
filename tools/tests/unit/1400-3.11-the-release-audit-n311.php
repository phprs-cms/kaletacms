<?php

declare(strict_types=1);

/* ---------- 3.11: the release audit (N311) ---------- */
$robots311 = Kaleta\Core\WebImport::robots("User-agent: *\n# caf\xE9 \x85 ancien commentaire\nDisallow: /private\nCrawl-delay: 2\n");
check('3.11 N311-1: robots.txt with a non-UTF-8 byte keeps its rules (CR/LF split, never fails open)',
    [$robots311['disallow'], $robots311['delay'], Kaleta\Core\Firewall::parseList("203.0.113.7 # caf\xE9\n198.51.100.0/24")[0]],
    [['/private'], 2.0, ['203.0.113.7', '198.51.100.0/24']]);
$rtlMail311 = Kaleta\Core\Mail::rightToLeft("رابط: https://example.test/\u{202E}gpj.exe", 'ar');
check('3.11 N311-4: bidi controls from a visitor are dropped from the HTML part of an RTL e-mail',
    [str_contains($rtlMail311, "\u{202E}"), str_contains($rtlMail311, 'https://example.test/gpj.exe'), Kaleta\Core\Mail::rightToLeft("\xFF bad", 'ar') !== ''], [false, true, true]);
