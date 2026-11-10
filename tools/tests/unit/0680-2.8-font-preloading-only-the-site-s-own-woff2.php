<?php

declare(strict_types=1);

/* ---------- 2.8: font preloading – only the site's own WOFF2 files that render text above the fold ---------- */
$fontsDs = ['vlastni_pisma' => [['nazev' => 'Firma Sans', 'soubor' => 'media/pisma/firma-sans.woff2', 'tucny' => 'media/pisma/firma-sans-bold.woff2'], ['nazev' => 'Firma Serif', 'soubor' => 'media/pisma/firma-serif.woff2', 'tucny' => ''],
    ['nazev' => 'Old', 'soubor' => 'media/pisma/old.woff', 'tucny' => '']]];
check('2.8: DesignSystem::fontPreloads – body = the regular file, headings = the bold file of the heading font', Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'vlastni-1', 'pismo_titulky' => 'vlastni-1'] + $fontsDs, '/web'),
    '<link rel="preload" href="/web/media/pisma/firma-sans.woff2" as="font" type="font/woff2" crossorigin>' . "\n" . '<link rel="preload" href="/web/media/pisma/firma-sans-bold.woff2" as="font" type="font/woff2" crossorigin>');
check('2.8: DesignSystem::fontPreloads – a heading font without a bold file preloads its only file; a system body font preloads nothing', Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'moderni', 'pismo_titulky' => 'vlastni-2'] + $fontsDs),
    '<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>');
check('2.8: DesignSystem::fontPreloads – the same file once, system fonts and WOFF (not WOFF2) never', [Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'vlastni-2', 'pismo_titulky' => 'vlastni-2'] + $fontsDs),
    Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'moderni', 'pismo_titulky' => 'klasicke'] + $fontsDs), Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'vlastni-3', 'pismo_titulky' => 'vlastni-3'] + $fontsDs)],
    ['<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>', '', '']);
$fontsCss = Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::sanitize(['pismo_text' => 'vlastni-1', 'pismo_titulky' => 'vlastni-2'] + $fontsDs), '/web');
check('2.8: every @font-face of the site\'s own fonts swaps in the fallback font while the file loads, and the preloaded files are the ones @font-face uses',
    [substr_count($fontsCss, '@font-face'), substr_count($fontsCss, 'font-display: swap'), str_contains($fontsCss, 'url("/web/media/pisma/firma-sans-bold.woff2")'), str_contains($fontsCss, 'url("/web/media/pisma/firma-serif.woff2")')], [4, 4, true, true]);
