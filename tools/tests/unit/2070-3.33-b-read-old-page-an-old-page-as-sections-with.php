<?php

declare(strict_types=1);

/* ---------- 3.33 (part B): read_old_page – an old page as cleaned sections with its colours and fonts, for rebuilding its layout ---------- */
$o333Css = ['https://old.example/css/site.css' => ':root{--brand:#1F4E3D;--accent:var(--brand)} body{font-family:"Inter",Arial,sans-serif;color:#222;background-color:#fff;font-size:17px}'
    . ' h1,h2{font-family:\'Playfair Display\',serif;color:rgb(31,78,61)} a{color:var(--accent)} .hero{background:#F4EFE6 url("/img/hero.jpg") center/cover;color:#fff}'
    . ' .btn-primary{background-color:hsl(43,53%,54%);color:#fff;border-radius:6px} .card:hover{color:red} @media (max-width:700px){.hero{padding:1rem}}'
    . ' @font-face{font-family:"Brand Sans";src:url(x.woff2)} /* .secret{color:#123456} */ .muted{color:rgba(0,0,0,0)}'];
$o333Html = '<!doctype html><html lang="en-GB"><head><title>Kitchens – Old Oak</title><meta name="description" content="Kitchens by hand.">'
    . '<meta property="og:image" content="/img/share.jpg"><link rel="stylesheet" href="/css/site.css"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;family=Playfair+Display:wght@700&amp;display=swap">'
    . '<style>.footer{background-color:#121212}</style></head><body><header class="top"><nav><a href="/">Home</a></nav></header>'
    . '<main><div class="wrap"><div class="inner">'
    . '<section class="hero" id="intro" data-elementor-id="5" onclick="evil()"><h1>Kitchens</h1><p>Made by hand.</p><a class="btn-primary" href="/contact/">Ask us</a><img data-src="/img/k.jpg" src="data:image/gif;base64,R0" alt="A kitchen"><script>track()</script></section>'
    . '<section class="cards"><div class="card" style="background-color:#eee"><h2>Oak</h2><svg viewBox="0 0 2 2"><path d="M0 0"/></svg><a href="javascript:alert(1)">x</a></div></section>'
    . '</div></div></main><footer class="footer"><p>Old Oak 1990</p></footer></body></html>';
$o333 = Kaleta\Core\OldPage::analyse($o333Html, 'https://old.example/kitchens/', $o333Css, 1);
$o333Sections = array_map(fn (array $s): string => $s['role'] . ':' . $s['tag'] . ':' . ($s['classes'] ?? ''), $o333['sections']);
check('3.33 read_old_page: the sections – the header, each block of the content (wrappers with one child gone through), the footer – with the page\'s title, description, language and share image', [
    $o333Sections, $o333['title'], $o333['description'], $o333['language'], $o333['share_image']],
    [['header:header:top', 'section:section:hero', 'section:section:cards', 'footer:footer:footer'], 'Kitchens – Old Oak', 'Kitchens by hand.', 'en-GB', 'https://old.example/img/share.jpg']);
$o333Hero = $o333['sections'][1];
check('3.33 read_old_page: a section\'s HTML is cleaned – no scripts, event handlers, unknown data attributes, javascript: links or icon paths; lazy images and links absolute; classes and ids kept', [
    str_contains($o333Hero['html'], '<script'), str_contains($o333Hero['html'], 'onclick'), str_contains($o333Hero['html'], 'data-elementor-id'),
    str_contains($o333Hero['html'], 'src="https://old.example/img/k.jpg"'), str_contains($o333Hero['html'], 'href="https://old.example/contact/"'),
    str_contains($o333Hero['html'], 'class="hero" id="intro"'), str_contains($o333['sections'][2]['html'], 'javascript:'), str_contains($o333['sections'][2]['html'], '<path'),
    str_contains($o333['sections'][2]['html'], 'style="background-color:#eee"'), $o333Hero['heading']],
    [false, false, false, true, true, true, false, false, true, 'Kitchens']);
check('3.33 read_old_page: the look of a section – its background colour and image, text colour, the colours and fonts inside it, from its classes and inline styles', [
    $o333Hero['background'], $o333Hero['background_image'], $o333Hero['foreground'], $o333Hero['colors'], $o333Hero['fonts'], $o333['sections'][3]['background']],
    ['#f4efe6', 'https://old.example/img/hero.jpg', '#ffffff', ['#1f4e3d', '#f4efe6', '#ffffff', '#c8a54c'], ['Playfair Display'], '#121212']);
check('3.33 read_old_page: the page – palette by use (comments, @font-face and a transparent colour not counted; var() filled in), fonts with Google Fonts, the base look, the custom properties, the stylesheets', [
    array_column($o333['colors'], 'value'), $o333['fonts'], $o333['base'], $o333['css_variables'], $o333['stylesheets']],
    [['#1f4e3d', '#ffffff', '#121212', '#222222', '#f4efe6', '#c8a54c', '#eeeeee'],
     [['family' => 'Inter', 'uses' => 1, 'source' => 'Google Fonts'], ['family' => 'Playfair Display', 'uses' => 1, 'source' => 'Google Fonts']],
     ['body' => ['foreground' => '#222222', 'background' => '#ffffff', 'font' => 'Inter', 'size' => '17px'], 'headings' => ['foreground' => '#1f4e3d', 'font' => 'Playfair Display'],
      'links' => ['foreground' => '#1f4e3d'], 'buttons' => ['foreground' => '#ffffff', 'background' => '#c8a54c', 'radius' => '6px']],
     ['--brand' => '#1F4E3D', '--accent' => '#1F4E3D'], ['read' => 1, 'not_read' => 1]]);
check('3.33 read_old_page: colours of every notation; stylesheet links absolute and once; one section alone, a long section cut at a tag', [
    Kaleta\Core\OldPage::colors('#ABC'), Kaleta\Core\OldPage::colors('rgb(10 20 30 / 50%) rgba(1,2,3,0)'), Kaleta\Core\OldPage::colors('hsl(0, 100%, 50%)'), Kaleta\Core\OldPage::colors('white'),
    Kaleta\Core\OldPage::stylesheetLinks('<link rel="stylesheet" href="a.css"><link rel="preload stylesheet" href="/a.css"><link rel="icon" href="/f.ico">', 'https://old.example/x/'),
    array_column(Kaleta\Core\OldPage::analyse($o333Html, 'https://old.example/kitchens/', [], 0, 2)['sections'], 'index'),
    ($o333Long = Kaleta\Core\OldPage::analyse('<main><section><p>' . str_repeat('<b>long text</b> ', 2000) . '</p></section><section><p>Next</p></section></main>', 'https://old.example/')['sections'][0])['truncated'],
    strlen($o333Long['html']) <= Kaleta\Core\OldPage::SECTION_BYTES && str_ends_with($o333Long['html'], '>')],
    [['#aabbcc'], ['#0a141e'], ['#ff0000'], ['#ffffff'], ['https://old.example/x/a.css', 'https://old.example/a.css'], [2], true, true]);
check('3.33 read_old_page: MCP – a read-only, open-world, non-essential tool in English only, for administrators whose connection may use import_website; reading goes through ImageDownloader with robots.txt and an untrusted marker', [
    Kaleta\Mcp\Catalog::TOOLS['read_old_page'], in_array('read_old_page', Kaleta\Mcp\Catalog::OPEN_WORLD, true), in_array('read_old_page', Kaleta\Mcp\Catalog::ESSENTIALS, true),
    Kaleta\Mcp\Translator::czech('read_old_page'), Kaleta\Mcp\Catalog::TITLES['read_old_page'],
    (bool) preg_match("/function toolReadOldPage.*isAdmin\\(\\).*Catalog::allows\\(.*'import_website'\\).*new \\\\Kaleta\\\\Core\\\\OldPage\\(new ImageDownloader\\(\\\$url, true\\)\\).*'untrusted' =>/s", (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/MigrationTools.php')),
    (bool) preg_match('/WebImport::robots\(WebImport::politeDownload.*WebImport::politeDownload\(\$this->downloader, \$url, \$robots, false, true/s', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/OldPage.php'))],
    [['read', ''], true, false, 'read_old_page', 'Read an old page with its look', true, true]);
