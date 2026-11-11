<?php

declare(strict_types=1);

/* ---------- 3.33 (part B, M4): the URL importer keeps forms, accordions, video, maps, files and the SEO fields of any builder ---------- */
// the elements of a build, flat, with what identifies them (no sections, containers, headings or text)
$b333Flat = function (array $nodes) use (&$b333Flat): array {
    $out = [];
    foreach ($nodes as $n) {
        $o = $n['obsah'] ?? [];
        $detail = match ($n['typ']) {
            'faq' => count($o['polozky']) . ':' . $o['polozky'][0]['otazka'],
            'zalozky' => implode('/', array_column($o['karty'], 'nazev')),
            'video' => $o['url'],
            'mapa' => $o['adresa'],
            'vlozeni' => $o['adresa'],
            'pocitadlo' => $o['pred'] . $o['cislo'] . $o['za'] . ' ' . $o['popisek'],
            'formular' => implode(',', array_column($o['pole'], 'popisek')) . ' [' . $o['tlacitko'] . ']',
            default => null,
        };
        if ($detail !== null) {
            $out[] = $n['typ'] . '(' . $detail . ')';
        }
        $out = array_merge($out, $b333Flat($n['deti'] ?? []));
    }

    return $out;
};
$b333Import = function (string $file) use ($b333Flat): array {
    $page = Kaleta\Core\WebImport::extract((string) file_get_contents(KALETA_ROOT . '/tools/fixtures/builder-pages/' . $file), 'http://127.0.0.1:9471/' . basename($file, '.html') . '/');
    [$build] = Kaleta\Builder\Build::sanitize(Kaleta\Builder\HtmlConverter::convert('<h1>' . e($page['titulek']) . '</h1>' . $page['obsah'], false, $page['prvky'])['stavba'], false);

    return ['page' => $page, 'build' => $build, 'flat' => $b333Flat($build['deti']), 'kinds' => array_column($page['prvky'], 'kind')];
};
$b333 = array_map($b333Import, ['elementor' => 'elementor.html', 'divi' => 'divi.html', 'wpbakery' => 'wpbakery.html', 'gutenberg' => 'gutenberg.html',
    'breakdance' => 'breakdance.html', 'squarespace' => 'squarespace.html', 'webflow' => 'webflow.html', 'wix' => 'wix.html']);
check('3.33 M4: Elementor – the counter (its number from data-to-value, the page shows 0), the accordion (role=tab inside the tablist, an answer hidden until opened), the video of data-settings, the map frame and the form', $b333['elementor']['flat'], [
    'pocitadlo(1200+ kitchens fitted)', 'faq(2:How long does a kitchen take?)', 'video(https://www.youtube.com/watch?v=XHOmBV4js_E)', 'mapa(Mill Lane 3, Oxford)',
    'formular(Your name,E-mail,Your kitchen [Ask for a visit])']);
check('3.33 M4: Divi – tabs by the order of the tab list and the panels, toggles, the number counter with its % sign, the video, the map by its data attributes and the contact form', $b333['divi']['flat'], [
    'zalozky(Studio/Office)', 'faq(2:Do I need planning permission?)', 'pocitadlo(98% would recommend us)', 'video(https://www.youtube.com/watch?v=dQw4w9WgXcQ)',
    'mapa(51.752, -1.2577)', 'formular(Name,Email Address,Message [Submit])']);
check('3.33 M4: WPBakery – the accordion, the tabs (#links to their panels), Vimeo, the Google Maps embed code and Contact Form 7 with a choice field', $b333['wpbakery']['flat'], [
    'faq(2:What does the guarantee cover?)', 'zalozky(Slate/Tile)', 'video(https://vimeo.com/76979871)', 'mapa(51.5074, -0.1278)', 'formular(Your name,Your phone,Roof type [Request a quote])']);
check('3.33 M4: Gutenberg – details blocks, the Yoast FAQ block, the YouTube embed, the video file, the OpenStreetMap frame; WPForms draws its fields with a script', [$b333['gutenberg']['flat'], in_array('unreadable_form', $b333['gutenberg']['kinds'], true)], [[
    'faq(2:Does it hurt?)', 'faq(1:Can I pay in instalments?)', 'video(https://www.youtube.com/watch?v=dQw4w9WgXcQ)', 'video(http://127.0.0.1:9471/files/clinic-tour.mp4)', 'mapa(51.5052, -0.1255)'], true]);
check('3.33 M4: Breakdance – the accordion (aria-hidden panels, svg icons), the tabs, the counter, the youtube-nocookie video, the map and the form', $b333['breakdance']['flat'], [
    'faq(2:Do I need a referral?)', 'zalozky(Sport/Back pain)', "pocitadlo(15\u{A0}years of practice)", 'video(https://www.youtube.com/watch?v=dQw4w9WgXcQ)', 'mapa(Station Road 10, Leeds)',
    'formular(Name,Email,Where does it hurt? [Book an appointment])']);
check('3.33 M4: Squarespace, Webflow and Wix exports – accordions, tabs, counters, players a script draws (data-html, an embedly frame, wix-video), maps and forms; the Squarespace form is drawn by a script', [
    $b333['squarespace']['flat'], in_array('unreadable_form', $b333['squarespace']['kinds'], true), $b333['webflow']['flat'], $b333['wix']['flat']], [
    ['faq(2:When should we book?)', 'video(https://vimeo.com/76979871)', 'mapa(Farm Lane, Brighton)'], true,
    ['faq(2:How long does a brand take?)', 'zalozky(Identity/Packaging)', 'pocitadlo(140 brands launched)', 'pocitadlo(12 awards)', 'video(https://www.youtube.com/watch?v=dQw4w9WgXcQ)', 'formular(Name,Email address [Start a project])'],
    ['faq(2:Do I need my own mat?)', 'video(https://www.youtube.com/watch?v=dQw4w9WgXcQ)', 'mapa(53.4808, -2.2426)', 'formular(First name,Email [Join a class])']]);
check('3.33 M4: every builder – the old <title> becomes the SEO title, og:image the share image (absolute), the text keeps the page and never the header, footer or navigation', array_map(fn (array $r): string => implode('|', [
    $r['page']['seo_titulek'] !== '' && $r['page']['seo_titulek'] !== $r['page']['titulek'], str_ends_with($r['page']['obrazek'], '/img/share.png'),
    mb_strlen(strip_tags($r['page']['obsah'])) > 80, (bool) preg_match('/Northwind Joinery 1984|Oakfield<|>Home<|Slate &amp; Sons<\/footer|Riverside Dental<\/footer|Petal &amp; Stem<\/footer|Fold Studio<\/footer/', $r['page']['obsah'])]), $b333),
    array_fill_keys(array_keys($b333), '1|1|1|'));
check('3.33 M4: the answers and the placeholder – an answer hidden until opened is kept, an icon\'s text is not, the build gets no Custom HTML, and a form drawn by a script is a visible placeholder', [
    str_contains(json_encode($b333['elementor']['build'], JSON_UNESCAPED_UNICODE), 'any brand you choose'), str_contains(json_encode($b333['breakdance']['build']), '"Open'),
    str_contains(json_encode($b333['squarespace']['build']), '"typ":"html"'), str_contains(json_encode($b333['squarespace']['build'], JSON_UNESCAPED_UNICODE), 'Form from the old site')], [true, false, false, true]);

// how the page keeps its widgets: a marker in the sanitized content, the HTML as written in the list, the text of each for the text column
$b333Page = Kaleta\Core\WebImport::extract('<html><head><title>Kitchens – Old Oak</title><meta property="og:image" content="//cdn.old.example/s.jpg"></head><body><main>'
    . '<p>Some text about kitchens, long enough to be the main content of the page and to be kept as it is.</p><kaleta-widget title="0"></kaleta-widget>'
    . '<div class="faq-item"><h3 class="faq-question">Q?</h3><div class="faq-answer" aria-hidden="true"><p>A.</p></div></div>'
    . '<p><a href="#prices">Prices</a> <a href="/files/list.pdf">List</a> <a href="/about/">About</a></p>'
    . '<div class="newsletter-signup"><form><input type="email" name="e" placeholder="E-mail"><button>Join</button></form></div><form role="search"><input type="search" name="q"></form></main></body></html>', 'https://old.example/kitchens/');
check('3.33 M4: extract – one marker per widget (a marker the old page wrote is dropped), no newsletter or search form, anchors and file links stay, page links become paths, the share image absolute', [
    substr_count($b333Page['obsah'], '<kaleta-widget'), str_contains($b333Page['obsah'], '<kaleta-widget title="0"></kaleta-widget>'), array_column($b333Page['prvky'], 'kind'),
    str_contains($b333Page['obsah'], 'href="#prices"'), str_contains($b333Page['obsah'], 'href="https://old.example/files/list.pdf"') || str_contains($b333Page['obsah'], 'href="/files/list.pdf"'),
    str_contains($b333Page['obsah'], 'href="/about"'), $b333Page['seo_titulek'], $b333Page['obrazek']],
    [1, true, ['accordion'], true, true, true, 'Kitchens – Old Oak', 'https://cdn.old.example/s.jpg']);
check('3.33 M4: the text column gets each widget\'s text in place of its marker (the question and the answer); an unreadable form leaves nothing', [
    Kaleta\Core\WebImport::widgetText('<p>x</p><kaleta-widget title="0"></kaleta-widget><kaleta-widget title="1"></kaleta-widget><kaleta-widget title="7"></kaleta-widget>', [
        ['html' => '<details><summary>Q?</summary><p>A.</p></details>', 'kind' => 'accordion'], ['html' => '<div class="hbspt-form"></div>', 'kind' => 'unreadable_form']])],
    ['<p>x</p><h3>Q?</h3><p>A.</p>']);
check('3.33 M4: keptElements counts the kept kinds anywhere in the build; keptSummary names them in English and leaves out zeros', [
    Kaleta\Core\WebImport::keptElements($b333['divi']['build']),
    Kaleta\Core\WebImport::keptSummary(['zachovano' => ['formulare' => 2, 'faq' => 0, 'soubory' => 3, 'seo' => 1]]), Kaleta\Core\WebImport::keptSummary([])],
    [['zalozky' => 1, 'faq' => 1, 'pocitadla' => 1, 'videa' => 1, 'mapy' => 1, 'formulare' => 1], ['forms' => 2, 'files' => 3, 'pages_with_seo_fields' => 1], []]);
check('3.33 M4: an Elementor page keeps its blocks – "widget" is noise only at the start of a class (a WordPress sidebar), not inside elementor-widget', [
    str_contains(Kaleta\Core\WebImport::extract('<main><div class="elementor-widget elementor-widget-text-editor"><div class="elementor-widget-container"><p>Kept text of an Elementor page that is long enough to be the content of it.</p></div></div><div class="widget widget_text"><p>Sidebar text</p></div></main>', 'https://e.example/')['obsah'], 'Kept text'),
    str_contains(Kaleta\Core\WebImport::extract('<main><div class="elementor-widget"><p>Kept text of an Elementor page that is long enough to be the content of it.</p></div><div class="widget widget_text"><p>Sidebar text</p></div></main>', 'https://e.example/')['obsah'], 'Sidebar text')],
    [true, false]);
check('3.33 M4: WebImport – the widgets come out before the noise goes, files go into Media within the limits, the state counts what was kept and what needs a person', [
    preg_match('/takeWidgets\(\$root\);\s+\/\/ an element inside one removed earlier/', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/WebImport.php')),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/WebImport.php'), "self::politeDownload(\$this->downloader, \$url, (array) (\$state['robots'] ?? []), false, false)"),
    Kaleta\Core\WebImport::created(['vysledek' => ['stranky' => 1, 'obrazky' => 2], 'zachovano' => ['soubory' => 3]]),
    array_key_exists('zachovano', Kaleta\Core\WebImport::newState('https://old.example')), array_key_exists('pozor', Kaleta\Core\WebImport::newState('https://old.example'))],
    [1, true, 6, true, true]);
