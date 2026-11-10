<?php

declare(strict_types=1);

/* ---------- 3.7: Kaleta runs on PHP 8.3 (the HTML5 DOM of 8.4 from system/compat, the version gates) ---------- */
// what PHP 8.4's parser makes of tricky markup (the expectations are its output): Dom\HTMLDocument – native on 8.4, the
// compat classes on 8.3 – and Kaleta\Compat\Html5Parser itself on every version must agree with it
$html5Cases = [
    ['<noscript><b>x</b>&lt;i&gt;</noscript>', '<noscript><b>x</b>&lt;i&gt;</noscript>'],
    ['<p title="a<b>&quot;c&nbsp;d">x&nbsp;y</p>', '<p title="a<b>&quot;c&nbsp;d">x&nbsp;y</p>'],
    ["a\r\nb\rc", "a\nb\nc"],
    ['&copy &copyx &notit; &notin; &amp &#x41; &#0; &#x110000; &#128;', "© ©x ¬it; ∉ &amp; A \u{FFFD} \u{FFFD} €"],
    ['<a href="?a=1&copy=2&amp;b&lt=3">x</a>', '<a href="?a=1&amp;copy=2&amp;b&amp;lt=3">x</a>'],
    ['</p>x', '<p></p>x'],
    ['<table>t<tr><td>1</table>', 't<table><tbody><tr><td>1</td></tr></tbody></table>'],
    ['<b><p>x</b>y</p>', '<b></b><p><b>x</b>y</p>'],
    ['<p><b>x</p><p>y</p>', '<p><b>x</b></p><p><b>y</b></p>'],
    ['<a href=1>a<a href=2>b</a>', '<a href="1">a</a><a href="2">b</a>'],
    ['<svg viewbox="0 0 1 1"><foreignObject><p>x</p></foreignObject><path/><clippath></clippath></svg><math><mi>x</mi></math>',
        '<svg viewBox="0 0 1 1"><foreignObject><p>x</p></foreignObject><path></path><clipPath></clipPath></svg><math><mi>x</mi></math>'],
    ['<?x > <img src=x onerror=alert(1)>?>', '<!--?x --> <img src="x" onerror="alert(1)">?&gt;'],
    ['<![CDATA[x]]><svg><![CDATA[y<]]></svg>', '<!--[CDATA[x]]--><svg>y&lt;</svg>'],
    ['<ul><li>a<li>b</ul><dl><dt>a<dd>b</dl>', '<ul><li>a</li><li>b</li></ul><dl><dt>a</dt><dd>b</dd></dl>'],
    ["<pre>\n\nx</pre><textarea>\nq</textarea>", "<pre>\nx</pre><textarea>q</textarea>"],
    ['<div><body class=x><html lang=cs><head><title>t</title></head>y</div>', '<div><title>t</title>y</div>'],
    ['<style>a<b</style><script>if(a<b)</script><xmp><b></xmp><iframe><b></iframe>', '<style>a<b</style><script>if(a<b)</script><xmp><b></xmp><iframe><b></iframe>'],
    ['<p>a<div>b</div>c</p>', '<p>a</p><div>b</div>c<p></p>'],
    ['<!-- a -- b --!><!--><!---->', '<!-- a -- b --><!----><!---->'],
    ['<img src="a" src="b" SRC=c data-X=1>', '<img src="a" data-x="1">'],
    ['<select><p>a</select>b', '<select><p>a</p></select>b'],
    ['<p title="</p><img src=x onerror=alert(1)>">t</p>', '<p title="</p><img src=x onerror=alert(1)>">t</p>'],
    ['<template><tr><td>x</td></tr></template>', '<template><tr><td>x</td></tr></template>'],
    ['<h1>a<h2>b</h2>', '<h1>a</h1><h2>b</h2>'],
    ['<p>x<hr>y', '<p>x</p><hr>y'],
    ['<br/><p/>x<span/>y</br>', '<br><p>x<span>y<br></span></p>'],
    ['<o:p>Word</o:p><image src=i.png>', '<o:p>Word</o:p><img src="i.png">'],
    ['<table><caption>c<td>1</table>', '<table><caption>c</caption><tbody><tr><td>1</td></tr></tbody></table>'],
    ["<p>\0x</p>", '<p>x</p>'],
    // N37-1: a prefixed name is one literal attribute; only SVG/MathML adjust xlink:href & co., xml:lang and xml:space
    ['<img xml:onerror="a" XLINK:HREF=b xmlns:x=c x:y=d xmlns=e>', '<img xml:onerror="a" xlink:href="b" xmlns:x="c" x:y="d" xmlns="e">'],
    ['<svg xlink:href=a xlink:onload=b xml:lang=c xml:base=d x:y=e xmlns:xlink=f><x:g x:h=i/></svg>',
        '<svg xlink:href="a" xlink:onload="b" xml:lang="c" xml:base="d" x:y="e" xmlns:xlink="f"><x:g x:h="i/"></x:g></svg>'],
    ['<math><mi xlink:href=a xml:space=b>x</mi></math>', '<math><mi xlink:href="a" xml:space="b">x</mi></math>'],
    // a prefixed foreign element is no integration point (its content stays foreign) and keeps its whole name
    ['<svg><x:foreignObject><style>&lt;i&gt;</style></x:foreignObject></svg>', '<svg><x:foreignobject><style>&lt;i&gt;</style></x:foreignobject></svg>'],
    ['<svg><x:g><desc><p>x</p></desc></x:g></x:g></svg>', '<svg><x:g><desc><p>x</p></desc></x:g></svg>'],
];
$viaDom = static function (string $html): string {
    $doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
    $output = '';
    foreach ($doc->body->childNodes ?? [] as $n) {
        $output .= $doc->saveHtml($n);
    }

    return $output;
};
$viaCompat = static function (string $html): string {
    $doc = new DOMDocument('1.0', 'UTF-8');
    Kaleta\Compat\Html5Parser::parse($doc, Kaleta\Compat\Html5Parser::utf8('<!DOCTYPE html><html><body>' . $html . '</body></html>', 'UTF-8'));
    $body = $doc->getElementsByTagName('body')->item(0);

    return $body === null ? '' : Kaleta\Compat\Html5Serializer::inner($body);
};
check('3.7 HTML5 (' . (PHP_VERSION_ID >= 80400 ? 'native Dom' : 'compat Dom') . '): tricky markup parses and serializes as in PHP 8.4',
    array_map(fn (array $c): string => $viaDom($c[0]), $html5Cases), array_column($html5Cases, 1));
check('3.7 HTML5: Kaleta\Compat\Html5Parser (the parser of PHP 8.3) gives the same on every version',
    array_map(fn (array $c): string => $viaCompat($c[0]), $html5Cases), array_column($html5Cases, 1));
// the selectors the code uses (WebImport, MigrationReport, HtmlConverter…) – native and compat must find the same
$selectorDoc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head><meta property="og:title" content="OG"><title>T</title></head><body>'
    . '<main id="content" class="entry-content x" role="main"><article><time datetime="2026-01-02">d</time><h1>H</h1></article>'
    . '<form><label for="a&quot;b">Name</label><input id="a&quot;b" type="TEXT"><input type="hidden"><input type="submit"><textarea></textarea>'
    . '<select><option value="">Choose</option><option>1</option></select><button type="button">x</button><button>Send</button></form>'
    . '<a class="tlacitko-velke" href="/">a</a><a class="btn">b</a><template><p class="in-template">t</p></template><p class="in-template">p</p>'
    . '<ul><li>1</li><li>2</li><li>3</li></ul></main></body></html>', LIBXML_NOERROR, 'UTF-8');
$count = fn (string $selector): int => count($selectorDoc->querySelectorAll($selector));
check('3.7 selectors: the ones the code uses find the same elements on 8.3 (compat) and 8.4 (native)', [
    $count('input:not([type="hidden"]):not([type="submit"]):not([type="search"]), textarea, select'), $count('article time[datetime]'),
    $count('a[class*="tlacitko"]'), $count('label[for="' . addcslashes('a"b', '"\\') . '"]'), $count('[id]'), $count('meta[property="og:title"]'),
    $count('#content'), $count('.entry-content'), $count('[role="main"]'), $count('main > article'), $count('li + li'), $count('li ~ li'), $count('.in-template'),
    $count('button:not([type="button"]):not([type="reset"]), input[type="submit"]'), $count('input[type="text"]'), $count('option[value=""]'),
    $count('div, section, article, header, footer, aside, nav, h1, h2, h3, h4, h5, h6, figure, img, blockquote, details, a.btn, a.button, a[class*="tlacitko"]'),
    $selectorDoc->querySelector('time[datetime]')?->getAttribute('datetime'), $selectorDoc->querySelector('h1')?->closest('main')?->getAttribute('id'),
    $selectorDoc->querySelector('main')?->getAttribute('missing'), $selectorDoc->querySelector('ul')?->firstElementChild?->nextElementSibling?->textContent,
    (function () use ($selectorDoc): string {
        try {
            $selectorDoc->querySelectorAll('p:unknown-pseudo(');

            return 'no error';
        } catch (DOMException) {
            return 'DOMException';
        }
    })(),
], [3, 1, 1, 1, 2, 1, 1, 1, 1, 1, 2, 2, 1, 2, 1, 1, 4, '2026-01-02', 'content', null, '2', 'DOMException']);
check('3.7 HTML5 encoding: <meta charset> (also Windows-1250, which mbstring lacks), a byte order mark, invalid bytes become U+FFFD', [
    Kaleta\Compat\Html5Parser::utf8("<meta charset=windows-1250><p>\xe8\x9a</p>"), Kaleta\Compat\Html5Parser::utf8("\xEF\xBB\xBF<p>č</p>"),
    Kaleta\Compat\Html5Parser::utf8("<p>\xff a</p>"), Kaleta\Compat\Html5Parser::utf8("<p>\xe8</p>", 'ISO-8859-2'), mb_substitute_character()],
    ['<meta charset=windows-1250><p>čš</p>', '<p>č</p>', "<p>\u{FFFD} a</p>", '<p>č</p>', mb_substitute_character()]);
check('3.7 HtmlConverter: a simple list with a class keeps its items (Element::$children exists only from PHP 8.5)',
    Kaleta\Builder\HtmlConverter::convert('<style>.x{color:red}</style><ul class="x"><li>a</li><li>b</li></ul>')['stavba']['deti'][0]['deti'][0]['obsah']['polozky'] ?? null, "a\nb");
// 3.7 N37-1: on PHP 8.3 the compat parser bound the prefix of xml:onerror (any prefixed name), the sanitizers judged and
// removed the attribute by its local name, missed it, and the serializer wrote it back as a live onerror. Every sanitizer, on
// every version: what comes out, parsed again as a browser does, has no event handler and no script address.
$liveScript = static function (string $html): array {
    $found = [];
    $doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
    foreach ($doc->querySelectorAll('*') as $el) {
        if (in_array(strtolower($el->localName), ['script', 'object', 'embed', 'base'], true)) {
            $found[] = '<' . $el->localName . '>';
        }
        foreach ($el->attributes as $a) {
            $name = strtolower($a->nodeName);
            if (($a->namespaceURI === null && preg_match('/^on[^:]*$/', $name) === 1) // on:error is a literal, inert attribute
                || ((in_array($name, ['href', 'src', 'action', 'formaction', 'poster', 'data'], true) || ($name === 'xlink:href' && $a->namespaceURI !== null))
                    && preg_match('/^(javascript|vbscript|data):/i', (string) preg_replace('/[\x00-\x20]+/', '', $a->value)) === 1)) {
                $found[] = $name . '=' . $a->value;
            }
        }
    }
    // and as text: no attribute name starting with "on" right after a space, quote or slash (xml:onerror is inert)
    return preg_match('/[\s"\'\/]on[a-z]+\s*=/i', $html) === 1 ? [...$found, 'text on…='] : $found;
};
$leaves = static function (mixed $value) use (&$leaves): array {
    return is_array($value) ? array_merge(...array_values(array_map($leaves, $value)) ?: [[]]) : (is_string($value) && str_contains($value, '<') ? [$value] : []);
};
$sanitizers = [
    'Html::safe' => fn (string $h): array => [Kaleta\Core\Html::safe($h)],
    'Html::transform (NewsText)' => fn (string $h): array => [Kaleta\Core\Html::transform(Kaleta\Core\Html::safe($h), function (): void {})],
    'WpContent::safeHtml' => fn (string $h): array => [Kaleta\Core\WpContent::safeHtml($h)],
    'WpContent::sanitize' => fn (string $h): array => [Kaleta\Core\WpContent::sanitize($h)],
    'Build::code' => fn (string $h): array => [Kaleta\Builder\Build::code($h)],
    'Build::sanitize (html, inline, code)' => fn (string $h): array => $leaves(Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['typ' => 'text', 'obsah' => ['html' => $h]],
        ['typ' => 'nadpis', 'obsah' => ['text' => $h]], ['typ' => 'html', 'obsah' => ['kod' => $h]]]], true)[0]),
    'WebImport::safeContent' => fn (string $h): array => [Kaleta\Core\WebImport::safeContent($h)],
    'SiteImport' => fn (string $h): array => [Closure::bind(fn (): string => Kaleta\Core\SiteImport::html($h, 100000), null, Kaleta\Core\SiteImport::class)()],
    'HtmlConverter + Build::sanitize' => fn (string $h): array => $leaves(Kaleta\Builder\Build::sanitize(Kaleta\Builder\HtmlConverter::convert($h)['stavba'], false)[0]),
];
$prefixed = [
    '<img xml:onerror="alert(1)" src="/media/x.png">', '<IMG XML:ONERROR=alert(1) SRC=/media/x.png>', '<p><a xml:href="javascript:alert(1)" href="/ok">a</a></p>',
    '<a xlink:href="javascript:alert(1)">a</a>', '<a xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="javascript:alert(1)">a</a>',
    '<p xmlns="http://www.w3.org/1999/xhtml" xmlns:x="http://www.w3.org/1999/xhtml" x:onclick="alert(1)">p</p>',
    '<div foo:bar:onmouseover=alert(1) on:click=alert(1) xmlns:on=x XLink:OnClick=alert(1)>d</div>',
    '<svg><a xlink:href="javascript:alert(1)"><text>t</text></a><g xml:onload="alert(1)" xlink:onload="alert(1)"></g></svg>',
    '<svg><x:g x:onload="alert(1)"><foreignObject><img x:onerror="alert(1)" src="/media/x.png"></foreignObject></x:g></svg>',
    '<math><mi xlink:href="javascript:alert(1)" xml:onclick="alert(1)">x</mi><mtext><img xmlns:on=y on:error=alert(1) src=/x.png></mtext></math>',
    '<svg><x:desc><style>&lt;img src=x onerror=alert(1)&gt;</style></x:desc><desc><p xml:onclick=alert(1)>x</p></desc></svg>',
    // found by the differential run: the attribute list keyed by local name – xlink:href hid behind href (Build::code kept it)
    '<svg><a xlink:href="javascript:alert(1)" href="#x"><text>t</text></a><use href="#a" xlink:href="data:image/svg+xml,x"/></svg>',
];
$live = [];
foreach ($sanitizers as $sanitizer => $sanitize) {
    foreach ($prefixed as $input) {
        foreach ($sanitize($input) as $output) {
            if (($reasons = $liveScript($output)) !== []) {
                $live[] = $sanitizer . ': ' . $input . ' → ' . $output . ' (' . implode(', ', $reasons) . ')';
            }
        }
    }
}
check('3.7 N37-1: no sanitizer lets xml:/xlink:/xmlns:/x: or uppercase prefixed attributes become a live handler or script address (HTML, SVG, MathML)', $live, []);
check('3.7 N37-1: the sanitizers judge the qualified name and remove that very attribute (the safe href stays), the same on 8.3 and 8.4', [
    Kaleta\Core\Html::safe('<img xml:onerror="a" src="/x.png">'), Kaleta\Core\WpContent::safeHtml('<p><a xml:href="javascript:alert(1)" href="/ok">a</a></p>'),
    Kaleta\Builder\Build::code('<svg><a xlink:href="javascript:alert(1)" xlink:title="t">q</a></svg>'),
    Kaleta\Core\ImportRecheck::risk('<img xml:onerror=x src=y>'), Kaleta\Core\ImportRecheck::risk('<img onerror=x>'),
], ['<img src="/x.png">', '<p><a href="/ok">a</a></p>', '<svg><a xlink:title="t">q</a></svg>', 0, 2]);
// the same class in the uploaded-SVG cleaner (XML, every PHP version): iterator_to_array() keyed the attributes by local name,
// so x:onload hid onload and href hid xlink:href – the live one was never checked
check('3.7: Svg::sanitize checks every attribute, also one whose local name another attribute shares',
    Kaleta\Core\Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="data:image/svg+xml,x" href="#a"/>'
        . '<g onload="alert(1)" x:onload="y" xmlns:x="urn:x"/></svg>'),
    '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use href="#a"/><g xmlns:x="urn:x"/></svg>');
// 3.9.2: a logo from a design tool colours its shapes by classes in <style> – the block goes, its plain presentation rules stay
// on the shapes (a light logo turned black on a dark header); a later rule wins, url() only to #id, other properties never
check('3.9.2: Svg::sanitize moves simple class rules of <style> onto the shapes',
    Kaleta\Core\Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><defs><style>/* x */ .cls-1 { fill: #f0eae4; stroke-width: 0px; } .a, .b { fill: url(#g); behavior: x }'
        . ' .c { fill: url(https://e.cz/x); } .cls-1 { opacity: .5 } path.d { fill: red }</style></defs><path class="cls-1" fill="#000" d="M0 0"/><rect class="b c d"/></svg>'),
    '<svg xmlns="http://www.w3.org/2000/svg"><defs/><path class="cls-1" fill="#f0eae4" d="M0 0" opacity=".5" stroke-width="0px"/><rect class="b c d" fill="url(#g)"/></svg>');
// 3.9.2: upload_file gives media/…; in formatted text of an item one level deep it pointed to /realizace/media/… (a 404)
check('3.9.2: ImageHtml::rootMedia roots relative Media addresses in src, href and srcset, nothing else',
    Kaleta\Front\ImageHtml::rootMedia('<img src="media/2026/10/a.jpg" srcset="media/2026/10/a-nahled.jpg 640w, media/2026/10/a.jpg 2000w"><a href="media/x.pdf">x</a>'
        . '<img src="/media/b.jpg"><img src="https://e.cz/media/c.jpg"><p>media/d.jpg</p><a href="kontakt">k</a>', '/web'),
    '<img src="/web/media/2026/10/a.jpg" srcset="/web/media/2026/10/a-nahled.jpg 640w, /web/media/2026/10/a.jpg 2000w"><a href="/web/media/x.pdf">x</a>'
        . '<img src="/media/b.jpg"><img src="https://e.cz/media/c.jpg"><p>media/d.jpg</p><a href="kontakt">k</a>');
// defence in depth: an attribute in any other namespace (made by a DOM change) is never written under its local name
check('3.7 N37-1: Html::outer writes the qualified name and leaves out namespaced attributes HTML parsing never creates',
    Kaleta\Core\Html::transform('<img src="/x.png">', function (Dom\HTMLElement $body): void {
        $img = $body->firstElementChild;
        $img?->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:onerror', 'alert(1)'); // @phpstan-ignore method.notFound
        $img?->setAttributeNS('urn:x', 'x:onload', 'alert(1)'); // @phpstan-ignore method.notFound
        $img?->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', '/y'); // @phpstan-ignore method.notFound
    }), '<img src="/x.png" xlink:href="/y">');
// the compat DOM finds attributes by the qualified name as PHP 8.4 does (the legacy methods look the prefix up)
$attributeDoc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><p xml:lang="cs" x:y="1" xmlns="z">t</p><svg xlink:href="#a"></svg>', LIBXML_NOERROR, 'UTF-8');
$attributeP = $attributeDoc->querySelector('p');
$attributeSvg = $attributeDoc->querySelector('svg');
$attributeP?->setAttribute('xml:space', 'preserve');
$attributeP?->removeAttribute('x:y');
check('3.7 N37-1: getAttribute/hasAttribute/setAttribute/removeAttribute take the qualified name (xml:lang on HTML is literal)', [
    $attributeP?->getAttribute('xml:lang'), $attributeP?->hasAttribute('x:y'), $attributeP?->getAttribute('xmlns'), $attributeSvg?->getAttribute('xlink:href'),
    $attributeSvg?->getAttribute('xmlns'), $attributeP?->getAttributeNames(), $attributeP?->getAttribute('lang')],
    ['cs', false, 'z', '#a', null, ['xml:lang', 'xmlns', 'xml:space'], null]);
// the compat parser on every version: names the legacy DOM cannot hold leave their content where PHP 8.4 has it; <script>
// keeps "<!--<script></script>" as a browser does; a <body> tag inside <template> does not touch the body
$compatTree = static function (string $html): DOMDocument {
    $doc = new DOMDocument('1.0', 'UTF-8');
    Kaleta\Compat\Html5Parser::parse($doc, '<!DOCTYPE html><html><body>' . $html . '</body></html>');

    return $doc;
};
$scriptTree = $compatTree('<script><!--<script></script>x</script>y<script><!--</script>z');
$templateTree = $compatTree('<template><body class=x><html lang=y></template>');
check('3.7 HTML5 compat: invalid names keep the tree, script data escapes, <body>/<html> inside <template> are ignored', [
    $viaCompat('<math><a"/>x</math>y<p a"b=1 c=2>z</p>'), $scriptTree->getElementsByTagName('script')->item(0)?->textContent,
    $scriptTree->getElementsByTagName('script')->item(1)?->textContent, $scriptTree->getElementsByTagName('body')->item(0)?->textContent,
    $templateTree->getElementsByTagName('body')->item(0)?->hasAttribute('class'), $templateTree->documentElement?->hasAttribute('lang'),
], ['<math>x</math>y<p c="2">z</p>', '<!--<script></script>x', '<!--', '<!--<script></script>xy<!--z', false, false]);
// 3.7 N37-3: the compat parser is linear – the scope checks read stack positions, the tree is at most 512 deep (as in Chrome),
// formatting elements and attributes are bounded. 200 KB of each pathological shape (an 8.3 site took 11.6 s for 39 KB of <div>).
$pathological = [
    'unclosed div' => str_repeat('<div>', 40000), 'p in button scope' => '<p><button>' . str_repeat('<div>', 40000), 'p soup' => str_repeat('<p>x<p>y</p><p>', 13000),
    'nested formatting' => str_repeat('<b><i><u><s><em><strong>x', 8000), 'reopened formatting' => str_repeat('<p><b>x', 28000),
    'formatting with ids' => implode('', array_map(fn (int $i): string => '<p><b id=' . $i . '>x', range(1, 16000))),
    'misnested a' => str_repeat('<a href=1><b>x<div>', 10000), 'many attributes' => '<p ' . implode(' ', array_map(fn (int $i): string => 'a' . $i . '=1', range(1, 30000))) . '>',
    'unclosed li' => str_repeat('<ul><li>', 25000), 'svg' => '<svg>' . str_repeat('<g>', 30000) . str_repeat('</x>', 20000), 'table' => str_repeat('<table><tr><td>', 13000),
];
$slow = [];
$deepest = 0;
foreach ($pathological as $shape => $html) {
    $started = microtime(true);
    $tree = $compatTree($html);
    if (($took = microtime(true) - $started) > 3.0) {
        $slow[] = $shape . ' ' . round($took, 1) . ' s';
    }
    for ($node = $tree->getElementsByTagName('body')->item(0), $depth = 0; $node !== null; $node = $node->lastChild, $depth++) {
        $deepest = max($deepest, $depth);
    }
}
check('3.7 N37-3: 200 KB of pathological markup parses in under 3 s each, the tree at most about 512 deep', [$slow, $deepest <= 520], [[], true]);
// a deep tree must not crash PHP: libxml copies and frees it recursively (8.3 old parser: a segfault at 5,000 levels with a 1 MB
// stack; PHP 8.4's own parser between 4,000 and 5,000). Since 3.8 Core\HtmlLimits refuses such markup before any parser sees
// it, on every PHP version: 20,000 nested elements give '' from every sanitizer and an HtmlTooLarge from HtmlConverter, and the
// deepest markup the limits let through (510 levels) goes through all of them, the native parser and cloneNode – in a child
// PHP with a 1 MB stack, as on a small CI or thread stack.
if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
    $deepScript = (string) tempnam(sys_get_temp_dir(), 'kaleta-deep');
    file_put_contents($deepScript, '<?php require ' . var_export(KALETA_SYSTEM . '/bootstrap.php', true) . '; use Kaleta\Core\{Html, HtmlLimits, HtmlTooLarge, WpContent, WebImport}; use Kaleta\Builder\{Build, HtmlConverter};'
        . ' $deep = str_repeat("<div><b>", 20000); $edge = str_repeat("<div>", 500) . str_repeat("<span><b>", 5) . "x";'
        . ' $refused = [Html::safe($deep), Build::code($deep), WpContent::safeHtml($deep), WebImport::safeContent($deep), Html::transform($deep, fn () => null)] === ["", "", "", "", ""];'
        . ' try { HtmlConverter::convert("<details><summary>q</summary>" . $deep . "x</details>"); $refused = false; } catch (HtmlTooLarge) {}'
        . ' $doc = HtmlLimits::fragment($edge); $copy = $doc?->body?->cloneNode(true); unset($copy, $doc);'
        . ' $passed = HtmlLimits::check($edge) === null && Html::safe($edge) !== "" && Build::code($edge) !== "" && WpContent::safeHtml($edge) !== "" && WebImport::safeContent($edge) !== ""'
        . ' && HtmlConverter::convert("<details><summary>q</summary>" . $edge . "</details>")["stavba"]["deti"] !== [];'
        . ' echo $refused && $passed ? "ok" : "wrong " . var_export([$refused, $passed], true);');
    $deepRun = shell_exec('ulimit -s 1024 2>/dev/null; ' . escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg($deepScript) . ' 2>&1');
    unlink($deepScript);
    check('3.8 N37-3: 20,000 nested elements are refused by every sanitizer and HtmlConverter, the deepest allowed markup does not crash PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' with a 1 MB stack', $deepRun, 'ok');
}

