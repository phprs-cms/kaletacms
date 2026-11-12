<?php

declare(strict_types=1);

/* ---------- 3.5 "First hour": the first image loads at once, SVG logos have a size, countdown and cookie bar markup ---------- */
$leadApp = new Kaleta\Core\App([]);
// UXP-08: the first image of the page's first section (here inside a container) loads at once while its loading is left
// automatic – also in builds saved before 3.5 (false = automatic, true = at once); an explicit choice wins, the first image
// decides alone, and site parts (the header) never get it
$leadImages = static function (mixed $first, string $source = 'stranka:7') use ($leadApp): string {
    [$build] = Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [
        ['id' => 's1', 'typ' => 'sekce', 'deti' => [['id' => 'k1', 'typ' => 'kontejner', 'deti' => [['id' => 'i1', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/01/a.jpg', 'alt' => 'A']]]],
            ['id' => 'i2', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/01/b.jpg', 'alt' => 'B']]]],
        ['id' => 's2', 'typ' => 'sekce', 'deti' => [['id' => 'i3', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/01/c.jpg', 'alt' => 'C']]]],
    ]]);
    $build['deti'][0]['deti'][0]['deti'][0]['obsah']['priorita'] = $first; // as stored, without the sanitizer (older builds hold booleans)
    $k = new Kaleta\Builder\Context($leadApp);
    $k->source = $source;
    preg_match_all('/<img[^>]*alt="([ABC])"[^>]*>/', Kaleta\Builder\Build::html($build, $k), $found, PREG_SET_ORDER);

    return implode(' ', array_map(fn (array $m): string => $m[1] . (str_contains($m[0], ' fetchpriority="high"') && !str_contains($m[0], 'loading=') ? ':high' : (str_contains($m[0], ' loading="lazy"') ? ':lazy' : ':?')), $found));
};
check('3.5 UXP-08: the first image of the first section loads at once unless its editor chose otherwise; older builds and site parts', [
    $leadImages(''), $leadImages(false), $leadImages(true), $leadImages('1'), $leadImages('0'), $leadImages('', 'kolekce:3'), $leadImages('', 'cast:hlavicka:'),
], ['A:high B:lazy C:lazy', 'A:high B:lazy C:lazy', 'A:high B:lazy C:lazy', 'A:high B:lazy C:lazy', 'A:lazy B:lazy C:lazy', 'A:high B:lazy C:lazy', 'A:lazy B:lazy C:lazy']);
check('3.5 UXP-08: the loading choice – booleans of older builds sanitize to it, over MCP auto|high|lazy', [
    array_map(fn (mixed $v): mixed => Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'obrazek', 'obsah' => ['priorita' => $v]]]])[0]['deti'][0]['obsah']['priorita'], [true, false, '0', 'nonsense']),
    Kaleta\Mcp\Vocabulary::contentToEnglish('obrazek', ['priorita' => '1']), Kaleta\Mcp\Vocabulary::contentToEnglish('obrazek', ['priorita' => '']),
    array_map(fn (string $v): string => (string) Kaleta\Mcp\Vocabulary::contentToCzech(['priority' => $v])['priorita'], ['auto', 'high', 'lazy']),
], [['1', '', '0', ''], ['priority' => 'high'], ['priority' => 'auto'], ['', '1', '0']]);
// 3.6 UXA-13: the hero with an image leaves the loading automatic – inserted lower down its image is lazy, as the first section it loads at once
$heroBuild = Kaleta\Builder\Library::section('uvod-obrazek')['prvek'] ?? [];
$heroImage = null;
$findImage = function (array $p) use (&$findImage, &$heroImage): void { if (($p['typ'] ?? '') === 'obrazek') { $heroImage = $p; } foreach ($p['deti'] ?? [] as $c) { $findImage($c); } };
$findImage($heroBuild);
check('3.6 UXA-13: the image of the ready-made hero with an image loads automatically', [$heroImage !== null, $heroImage['obsah']['priorita'] ?? ''], [true, '']);
// UXP-15: an SVG logo gets width and height – its own size, or the viewBox's proportions (without a size Chrome draws it 0 px wide)
check('3.5 UXP-15: width and height of an SVG logo from its root element', [
    Kaleta\Front\ImageHtml::svgSize('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="280.8" height="71.4" viewBox="0 0 10 10"><path stroke-width="3"/></svg>'),
    Kaleta\Front\ImageHtml::svgSize('<svg viewBox="0 0 120 30" xmlns="http://www.w3.org/2000/svg">'), Kaleta\Front\ImageHtml::svgSize("<svg width='100%' viewBox='0,0,12,3'>"),
    Kaleta\Front\ImageHtml::svgSize('<svg width="10em" height="2em">'), Kaleta\Front\ImageHtml::svgSize('<html>'),
    Kaleta\Front\ImageHtml::logoSize('image/kaleta-logo.svg'), Kaleta\Front\ImageHtml::logoSize('/image/kaleta-logo.svg'), Kaleta\Front\ImageHtml::logoSize('media/../config.svg'),
    Kaleta\Front\ImageHtml::logoSize('https://example.com/logo.svg'), Kaleta\Front\ImageHtml::logoSize('media/2026/01/logo.png'),
], [' width="281" height="71"', ' width="352" height="88"', ' width="352" height="88"', '', '', ' width="281" height="71"', ' width="281" height="71"', '', '', '']);
// UXP-05: the timer role on a wrapper keeps the description list valid
$countdownHtml = Kaleta\Builder\Elements\Countdown::render(['obsah' => ['cil' => '2099-01-01 09:00', 'konec' => 'Now']], ' id="s-o1"', '', new Kaleta\Builder\Context($leadApp));
check('3.5 UXP-05: countdown – role="timer" on a <div> around the <dl>, the list draws no box of its own', [
    str_starts_with($countdownHtml, '<div id="s-o1" class="ka-odpocet" data-odpocet="2099-01-01T09:00'), str_contains($countdownHtml, 'role="timer" aria-live="off"><dl><div><dt>'),
    str_ends_with($countdownHtml, '</dl></div>'), (bool) preg_match('/<dl[^>]*role=/', $countdownHtml), str_contains(Kaleta\Builder\Elements\Countdown::baseCss(), '.ka-odpocet > dl { display: contents; }'),
], [true, true, true, false, true]);
// UXP-02/UXM-02/UXP-07: the cookie bar – every visitor language has the descriptive link text; the categories really stay hidden
// until Settings (display: grid used to override [hidden]); the toolbar sits above the bar
$cookieView = (string) file_get_contents(KALETA_SYSTEM . '/views/front/cookies.php');
check('[source-pin] 3.5: the cookie bar link text in every visitor language, categories behind Settings, the toolbar above the bar', [
    array_values(array_filter(['cs', 'de', 'es', 'fr', 'it', 'pl', 'sk'], fn (string $code): bool => !isset((require KALETA_SYSTEM . '/jazyky/' . $code . '.php')['More about cookies and privacy']))),
    str_contains($cookieView, "t('More information')"), str_contains($cookieView, '.cookies-volby:not([hidden]) { display: grid;'), str_contains($cookieView, '.cookies-volby { display'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/front/pristupnost.php'), 'max(56px, var(--ka-cookies-vyska, 0px))'),
], [[], false, true, false, true]);
