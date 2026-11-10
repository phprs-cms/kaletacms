<?php

declare(strict_types=1);

/* ---------- 3.3.3 (N50): fact tokens in addresses – the HTML is read tag by tag, a stray src=" hides nothing ---------- */
$n50Facts = $n26Cache->getValue()[Kaleta\Core\Language::siteColumn()];
$n26Cache->setValue(null, [Kaleta\Core\Language::siteColumn() => $n50Facts + ['email' => $n26Fact('email', 'email', 'info@example.com'), 'path' => $n26Fact('path', 'text', 'a b'),
    'evil' => $n26Fact('evil', 'text', 'x onmouseover=alert(1)')]]);
check('3.3.3 N50: the two strings of the report – a stray src=" in text and inside another attribute – leave no javascript: link', [
    $n26Fill('<p>Use src="</p><a href="{{fact.promo}}">x</a>'), $n26Fill('<a title="a src=" href="{{fact.promo}}">x</a>'),
    $n26Fill('<a data-x="src=" href="{{fact.promo}}">y</a>'), $n26Fill("<p>src='</p><img alt='src=' src='{{fact.promo}}'>")],
    ['<p>Use src="</p><a href="#">x</a>', '<a title="a src=" href="#">x</a>', '<a data-x="src=" href="#">y</a>', "<p>src='</p><img alt='src=' src=\"\">"]);
check('3.3.3 N50: what hides markup from a regular expression – a script, a style, a comment, an escaped script, a "<" in text – is read as the browser reads it', [
    $n26Fill('<script>var a = \'<a title="\';</script><a href="{{fact.promo}}">s</a>'), $n26Fill('<style>a[title="</style><a href="{{fact.promo}}">st</a>'),
    $n26Fill('<!-- <a title=" --><a href="{{fact.promo}}">c</a>'), $n26Fill('<script><!--<script>x="</script>"</script>--></script><a href="{{fact.promo}}">d</a>'),
    $n26Fill('a < b </ x> <a href="{{fact.promo}}">lt</a>'), $n26Fill('<svg><style><a href="{{fact.promo}}">sv</a></style></svg><svg><a xlink:href="{{fact.promo}}">x</a></svg>')],
    ['<script>var a = \'<a title="\';</script><a href="#">s</a>', '<style>a[title="</style><a href="#">st</a>', '<!-- <a title=" --><a href="#">c</a>',
     '<script><!--<script>x="</script>"</script>--></script><a href="#">d</a>', 'a < b </ x> <a href="#">lt</a>',
     '<svg><style><a href="#">sv</a></style></svg><svg><a xlink:href="#">x</a></svg>']);
check('3.3.3 N50: tel:, mailto: and https://… with a fact still work; srcset (N64) is checked address by address; a token in a tag or attribute name is left out and an unquoted value is quoted', [
    $n26Fill('<a href="tel:{{fact.phone}}">t</a><a href="mailto:{{fact.email}}">m</a><a href="https://x.example/{{fact.path}}?q={{fact.shop}}">h</a>'),
    $n26Fill('<img srcset="{{fact.promo}} 1x, /a.jpg 2x"><img srcset="/a.jpg 1x, https://cdn.example/{{fact.path}} 2x">'),
    $n26Fill('<a {{fact.promo}} href="/ok">n</a><img alt={{fact.evil}}><img alt="{{fact.evil}}">'),
    $n26Fill('<pre>{{fact.promo}}</pre><code><a title="</code>" href="{{fact.promo}}">x</a></code><a href="{{fact.promo}}">after</a>')],
    ['<a href="tel:+420 123 456 789">t</a><a href="mailto:info@example.com">m</a><a href="https://x.example/a b?q=https://shop.example/a?b=1&amp;c=2">h</a>',
     '<img srcset=""><img srcset="/a.jpg 1x, https://cdn.example/a b 2x">', '<a  href="/ok">n</a><img alt="x onmouseover=alert(1)"><img alt="x onmouseover=alert(1)">',
     '<pre>{{fact.promo}}</pre><code><a title="</code>" href="{{fact.promo}}">x</a></code><a href="#">after</a>']);
[$n50Build] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'text', 'obsah' => ['html' => '<p>Use src="</p><p><a href="{{fact.promo}}">x</a> <a title="a src=" href="{{fact.promo}}">y</a></p>']]]], false);
$n50Html = $n25Html($n50Build);
check('3.3.3 N50: a builder Text element saved by an editor with both strings renders no javascript: link', [(bool) preg_match('/href="\s*javascript:/i', $n50Html), substr_count($n50Html, 'href="#"')], [false, 2]);
check('3.3.3 N50: Facts::save refuses a text fact that starts with another scheme than http(s), mailto or tel; other text stays allowed', array_map(Kaleta\Core\Facts::startsWithScheme(...),
    ['javascript:alert(1)', ' JaVa' . "\t" . 'Script:alert(1)', "\x01javascript:x", 'javascript: alert(1)', 'data:text/html,x', 'vbscript:x', 'foo:bar', 'https://example.com/', 'mailto:a@b.cz', 'tel:+420',
     'Note: open daily', 'Open 8:00–16:00', 'Po–Pá 8:00', 'Pozn.: viz níže', 'Price 100 CZK', '']),
    [true, true, true, true, true, true, true, false, false, false, false, false, false, false, false, false]);
$n26Cache->setValue(null, [Kaleta\Core\Language::siteColumn() => []]); // no facts – the N38 builds below are filled without a database

