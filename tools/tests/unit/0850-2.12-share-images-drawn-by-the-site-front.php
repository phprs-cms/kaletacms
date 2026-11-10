<?php

declare(strict_types=1);

/* ---------- 2.12: share images drawn by the site (Front\ShareImage) ---------- */
$ogChars = fn (string $s): int => mb_strlen($s) * 10; // a stand-in for GD: every character 10 px wide
check('2.12 ShareImage::wrap – words fill the line, a word wider than the line is broken by characters, no text = one empty line', [
    Kaleta\Front\ShareImage::wrap('Dřevěné schody na míru', $ogChars, 150), Kaleta\Front\ShareImage::wrap('Nejneobhospodařovávatelnějšími a', $ogChars, 100), Kaleta\Front\ShareImage::wrap('', $ogChars, 100)],
    [['Dřevěné schody', 'na míru'], ['Nejneobhos', 'podařováva', 'telnějšími', 'a'], ['']]);
$ogWidth = fn (string $s, int $size): int => (int) round(mb_strlen($s) * $size * 0.6); // 0.6 em per character
check('2.12 ShareImage::fit – a short title at the largest size, a long one goes down until three lines hold it', [
    Kaleta\Front\ShareImage::fit("Kontakt \n", $ogWidth, 1040, 3, 60, 34), Kaleta\Front\ShareImage::fit(str_repeat('slovo ', 16), $ogWidth, 1040, 3, 60, 34)],
    [[60, ['Kontakt']], [48, ['slovo slovo slovo slovo slovo slovo', 'slovo slovo slovo slovo slovo slovo', 'slovo slovo slovo slovo']]]);
[$ogSize, $ogLines] = Kaleta\Front\ShareImage::fit(str_repeat('slovo ', 60), $ogWidth, 1040, 3, 60, 34);
check('2.12 ShareImage::fit – what the smallest size cannot hold is cut, the last line ends with an ellipsis', [$ogSize, count($ogLines), str_ends_with($ogLines[2], 'slovo…'), $ogWidth($ogLines[2], 34) <= 1040], [34, 3, true, true]);
$ogBrief = ['v' => 1, 'title' => 'Kontakt', 'site' => 'Firma', 'colors' => ['#2b5be3', '#ffffff', '#16181d'], 'logo' => '', 'logo_time' => 0];
$ogHash = Kaleta\Front\ShareImage::hash($ogBrief, 'key-a');
check('2.12 ShareImage::hash – 32 hex characters; the same brief gives the same address, another title, colour or key a different one', [
    preg_match('/^[a-f0-9]{32}$/', $ogHash), $ogHash === Kaleta\Front\ShareImage::hash($ogBrief, 'key-a'), $ogHash === Kaleta\Front\ShareImage::hash(array_replace($ogBrief, ['title' => 'Kontakty']), 'key-a'),
    $ogHash === Kaleta\Front\ShareImage::hash(array_replace($ogBrief, ['colors' => ['#000000', '#ffffff', '#16181d']]), 'key-a'), $ogHash === Kaleta\Front\ShareImage::hash($ogBrief, 'key-b')], [1, true, false, false, false]);
check('2.12 ShareImage::matches – the site\'s own hash passes; a tampered, malformed or foreign-key one never draws', [
    Kaleta\Front\ShareImage::matches($ogHash, $ogBrief, 'key-a'), Kaleta\Front\ShareImage::matches(strrev($ogHash), $ogBrief, 'key-a'), Kaleta\Front\ShareImage::matches(substr($ogHash, 1) . '0', $ogBrief, 'key-a'),
    Kaleta\Front\ShareImage::matches('../' . $ogHash, $ogBrief, 'key-a'), Kaleta\Front\ShareImage::matches($ogHash, $ogBrief, 'key-b')], [true, false, false, false, false]);
check('2.12 share images: on by default, exported with the site, /og reserved, the Open Graph size', [Kaleta\Core\Settings::DEFAULTS['share_image_auto'], in_array('share_image_auto', Kaleta\Core\SiteExport::SETTINGS, true),
    in_array('og', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true), Kaleta\Front\ShareImage::WIDTH . '×' . Kaleta\Front\ShareImage::HEIGHT], ['1', true, true, '1200×630']);

