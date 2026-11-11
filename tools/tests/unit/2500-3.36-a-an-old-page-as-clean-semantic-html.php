<?php

declare(strict_types=1);

use Kaleta\Builder\HtmlConverter;
use Kaleta\Core\OldPage;
use Kaleta\Core\SemanticHtml;

/* ---------- 3.36 (part A): read_old_page format semantic – any builder's page as clean section HTML for build_from_html ---------- */
$s336Section = function (string $html, string $tag = 'section'): array {
    $doc = Kaleta\Core\HtmlLimits::document('<!doctype html><html><body>' . $html . '</body></html>');

    return SemanticHtml::section($doc->body->firstElementChild, 'https://old.example.com/services/', $tag);
};
$s336Types = function (string $html): string {
    $types = [];
    $walk = function (array $nodes) use (&$walk, &$types): void {
        foreach ($nodes as $n) {
            $types[] = $n['typ'];
            $walk($n['deti'] ?? []);
        }
    };
    $walk(HtmlConverter::convert($html)['stavba']['deti']);

    return implode(',', $types);
};

$s336Soup = $s336Section('<div class="bde-section-1-1 x" data-node-id="1" style="padding:80px"><div class="section-container"><div class="bde-columns"><div class="bde-column" data-x="1">'
    . '<div class="bde-heading-wrap"><h2 class="bde-heading" style="color:red" data-node="2">Our <span class="hl">services</span></h2></div>'
    . '<div class="bde-text"><p class="lead" onclick="x()">We <strong>build</strong> kitchens.</p></div></div>'
    . '<div class="bde-column"><div><figure class="bde-image"><img src="data:image/gif;base64,R0" data-src="/img/kitchen.jpg" alt="A kitchen" width="800" height="600"></figure></div>'
    . '<img src="/px.gif" width="1" height="1" alt=""></div></div>'
    . '<div class="bde-button-wrap"><a class="bde-button btn-primary" href="/contact/" data-x="y">Ask for a visit</a> <a class="button-outline" href="javascript:alert(1)">Nothing</a></div>'
    . '<script>track()</script><span class="sr-only">Skip</span><svg><path d="M0"/></svg></div></div>');
check('3.36 semantic: the wrapper soup goes – a wrapper with one child is the child, columns stay one plain <div>; no class, style, data or event attribute, script, icon or screen-reader copy; lazy image, absolute addresses, the tracking pixel and a javascript: link out, a button link marked as one',
    $s336Soup['html'],
    '<section><div><div><h2>Our services</h2><p>We <strong>build</strong> kitchens.</p></div><figure><img src="https://old.example.com/img/kitchen.jpg" alt="A kitchen" width="800" height="600"></figure></div>'
    . '<div><a class="button" href="https://old.example.com/contact/">Ask for a visit</a><p>Nothing</p></div></section>');

$s336Widgets = $s336Section('<section id="faq" class="elementor-section"><div class="elementor-accordion"><div class="elementor-accordion-item">'
    . '<div class="elementor-tab-title" role="button" aria-controls="a1" aria-expanded="false"><span class="icon">+</span>Do you deliver?</div><div id="a1" class="elementor-tab-content" hidden><p>Within <b>fifty</b> miles.</p></div></div></div>'
    . '<div class="stats"><div class="stat"><strong class="num">250+</strong><span>projects</span></div></div>'
    . '<div class="video-wrap"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="Tour"></iframe></div>'
    . '<iframe src="https://tracker.example/frame"></iframe>'
    . '<div class="map"><iframe data-src="https://maps.google.com/maps?q=Prague&amp;output=embed"></iframe></div>'
    . '<form class="wpcf7-form" action="/x"><input type="hidden" name="_wpnonce" value="1"><p><label for="n">Name</label><input id="n" name="name" required class="x"></p>'
    . '<p class="hp"><input name="honeypot"></p><select name="topic"><option value="">Choose</option><option>Kitchens</option></select><button type="submit" class="btn">Send</button></form>'
    . '<form role="search"><input type="search" name="s"></form><div class="hbspt-form"></div></section>');
check('3.36 semantic: the widgets in the shape build_from_html converts – the accordion as <details>, the counter with data-counter, the video and map frames, the form with its fields (no hidden, honeypot or search fields); a script form and another frame listed only; the in-page anchor kept',
    [$s336Widgets['html'], $s336Widgets['widgets']],
    ['<section id="faq"><div><details><summary>Do you deliver?</summary><p>Within <b>fifty</b> miles.</p></details></div><div>'
        . '<strong data-counter="250">250</strong>+<p>projects</p></div><iframe src="https://www.youtube.com/watch?v=dQw4w9WgXcQ" title="Tour"></iframe>'
        . '<iframe src="https://maps.google.com/maps?q=Prague&amp;output=embed"></iframe><form><div><label for="n">Name</label><input name="name" id="n" required></div>'
        . '<select name="topic"><option value="">Choose</option><option>Kitchens</option></select><button type="submit">Send</button></form></section>',
        ['accordion', 'counter', 'video', 'frame', 'map', 'form', 'unreadable_form']]);
check('3.36 semantic: build_from_html turns that HTML into the elements, with nothing left out', [$s336Types($s336Widgets['html']), HtmlConverter::convert($s336Widgets['html'])['hlaseni'] === [] ? '' : 'notes'],
    ['sekce,faq,pocitadlo,video,mapa,formular', 'notes']); // the notes say what became which element

$s336Tabs = $s336Section('<div class="et_pb_tabs"><ul class="et_pb_tabs_controls"><li><a href="#">Studio</a></li><li><a href="#">Office</a></li></ul>'
    . '<div class="et_pb_all_tabs"><div class="et_pb_tab"><p>Quiet.</p></div><div class="et_pb_tab"><p>Busy.</p></div></div></div>');
$s336Menu = $s336Section('<header class="site-header"><div class="logo"><a href="/"><img src="/logo.png" alt="Old Oak"></a></div><nav class="menu"><a href="/">Home</a> <a href="/about/">About</a></nav></header>', 'header');
check('3.36 semantic: tabs as role=tab/tabpanel (they convert to Tabs), a menu of links side by side as a list, the header tag kept for the header section',
    [$s336Types($s336Tabs['html']), str_contains($s336Tabs['html'], 'role="tabpanel"'), $s336Menu['html']],
    ['sekce,zalozky', true, '<header><p><a href="https://old.example.com/"><img src="https://old.example.com/logo.png" alt="Old Oak"></a></p><ul><li><a href="https://old.example.com/">Home</a></li><li><a href="https://old.example.com/about/">About</a></li></ul></header>']);

$s336Single = $s336Section('<h1 class="wp-block-post-title">Dental implants</h1>');
$s336Para = $s336Section('<div><p class="has-text-align-center"><a class="wp-block-button__link" href="/book/">Book now</a></p><p>Text with <a href="/x/">a link</a>.</p></div>');
check('3.36 semantic: a top-level block that is not a wrapper is itself the content; a paragraph holding only a button link is the link (a Button), an inline link stays in its text',
    [$s336Single['html'], $s336Para['html'], $s336Types($s336Para['html'])],
    ['<section><h1>Dental implants</h1></section>', '<section><a class="button" href="https://old.example.com/book/">Book now</a><p>Text with <a href="https://old.example.com/x/">a link</a>.</p></section>', 'sekce,tlacitko,text']);

/* every builder fixture: the semantic sections convert to the same widgets the importer finds, far smaller than the page's markup */
$s336Fixtures = [];
foreach (['breakdance', 'elementor', 'divi', 'wpbakery', 'gutenberg', 'squarespace', 'webflow', 'wix'] as $s336Builder) {
    $s336Page = (string) file_get_contents(KALETA_ROOT . '/tools/fixtures/builder-pages/' . $s336Builder . '.html');
    $s336Look = OldPage::analyse($s336Page, 'https://old.example.com/page/');
    $s336Sem = OldPage::analyse($s336Page, 'https://old.example.com/page/', [], 0, null, OldPage::SEMANTIC);
    $s336Html = implode('', array_column($s336Sem['sections'], 'html'));
    $s336Kinds = array_values(array_intersect(explode(',', $s336Types($s336Html)), ['faq', 'zalozky', 'pocitadlo', 'video', 'mapa', 'formular']));
    $s336Fixtures[$s336Builder] = [count($s336Look['sections']) === count($s336Sem['sections']), ($s336Sem['format'] ?? '') === 'semantic', !isset($s336Look['format']),
        strlen($s336Html) < strlen(implode('', array_column($s336Look['sections'], 'html'))), !preg_match('/\s(?:class="(?!button)|style=|data-(?!counter=))/', $s336Html), implode(',', array_unique($s336Kinds))];
}
check('3.36 semantic: every builder fixture – the same sections, format said only when asked, smaller than the cleaned markup, no class (but the button mark), style or data attribute, and build_from_html finds its widgets',
    $s336Fixtures, [
        'breakdance' => [true, true, true, true, true, 'faq,zalozky,pocitadlo,video,mapa,formular'],
        'elementor' => [true, true, true, true, true, 'pocitadlo,faq,video,mapa,formular'],
        'divi' => [true, true, true, true, true, 'zalozky,faq,pocitadlo,video,mapa,formular'],
        'wpbakery' => [true, true, true, true, true, 'faq,zalozky,video,mapa,formular'],
        'gutenberg' => [true, true, true, true, true, 'faq,video,mapa'],
        'squarespace' => [true, true, true, true, true, 'faq,video,mapa'],
        'webflow' => [true, true, true, true, true, 'faq,zalozky,pocitadlo,video,formular'],
        'wix' => [true, true, true, true, true, 'faq,video,mapa,formular'],
    ]);

$s336Defs = array_column(Kaleta\Mcp\Tools::definitions(), null, 'name');
check('3.36 semantic: MCP read_old_page takes format (look by default | semantic), additively; the description says what semantic gives',
    [array_keys($s336Defs['read_old_page']['inputSchema']['properties'] ?? []), str_contains($s336Defs['read_old_page']['description'] ?? '', 'format semantic')],
    [['url', 'section', 'format'], true]);
