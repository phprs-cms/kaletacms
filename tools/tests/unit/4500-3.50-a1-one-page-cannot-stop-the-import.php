<?php

declare(strict_types=1);

/* ---------- 3.50 A1 (N124): one page can no longer stop the import – linear recognisers, a cap of panel buttons, one merge per CSS key ---------- */
use Kaleta\Builder\HtmlWidgets as A350Widgets;
use Kaleta\Core\WebImport as A350Web;

$a350Element = function (string $html, string $selector = 'body > *'): Dom\Element {
    $el = Dom\HTMLDocument::createFromString('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR)->querySelector($selector);
    assert($el instanceof Dom\Element);

    return $el;
};
$a350Pairs = fn (int $from, int $to): string => implode('', array_map(fn (int $n): string => '<button aria-controls="p' . $n . '">Question ' . $n . '</button><div id="p' . $n . '"><p>Answer ' . $n . '</p></div>', range($from, $to)));

// (c) a container with more than MAX_TRIGGERS panel buttons is neither an accordion nor tabs; at the cap it still is one
check('3.50 N124 (c): 200 question buttons are an accordion (its first 30 items), 201 are not – and tooManyTriggers() says why', [
    count(A350Widgets::accordion($a350Element('<div>' . $a350Pairs(1, 200) . '</div>')) ?? []),
    A350Widgets::accordion($a350Element('<div>' . $a350Pairs(1, 201) . '</div>')),
    A350Widgets::kind($a350Element('<div>' . $a350Pairs(1, 201) . '</div>')),
    A350Widgets::tooManyTriggers($a350Element('<div>' . $a350Pairs(1, 200) . '</div>')), A350Widgets::tooManyTriggers($a350Element('<div>' . $a350Pairs(1, 201) . '</div>')),
    A350Widgets::tabs($a350Element('<div><div role="tablist">' . implode('', array_map(fn (int $n): string => '<button role="tab" aria-controls="t' . $n . '">T' . $n . '</button>', range(1, 201))) . '</div>'
        . implode('', array_map(fn (int $n): string => '<div role="tabpanel" id="t' . $n . '">Panel ' . $n . '</div>', range(1, 201))) . '</div>'))],
    [30, null, null, false, true, null]);

// (a) the parts are compared by set, with the same answers as before: an item inside a part, a part inside an item, the
// container that is one item itself; the element that holds the widget and nothing else
check('3.50 N124 (a): parts found by details, aria-controls, aria-expanded and item classes – nested ones are taken once, a question twice for one panel once', [
    A350Widgets::accordion($a350Element('<div><details><summary>Q1</summary><p>A1 <span class="faq-item"><b>inner</b> text</span></p></details>'
        . '<button aria-controls="x">Q2</button><button aria-controls="x">Q2 mobile</button><div id="x"><p>A2</p></div>'
        . '<h3><button aria-expanded="false">Q3</button></h3><div><p>A3</p></div></div>')),
    A350Widgets::accordion($a350Element('<div class="faq-item"><h3>Only item?</h3><div class="faq-answer"><p>Yes.</p></div></div>')),
    A350Widgets::accordion($a350Element('<div><details><summary>Q</summary><p>A</p></details><p>Text outside the widget.</p></div>'))],
    [[['Q1', '<p>A1 <span class="faq-item"><b>inner</b> text</span></p>'], ['Q2', '<p>A2</p>'], ['Q3', '<p>A3</p>']], [['Only item?', '<p>Yes.</p>']], null]);

// (b) one id map per call: the first element with an id in document order answers, as querySelector() did
check('3.50 N124 (b): two panels with the same id – the first in document order is the answer (tabs and accordion alike)', [
    A350Widgets::accordion($a350Element('<div><button aria-controls="dup">Q</button><div id="dup">First <span id="dup">inner</span></div></div>')),
    A350Widgets::tabs($a350Element('<div><div role="tablist"><button role="tab" aria-controls="a">A</button><button role="tab" aria-controls="b">B</button></div>'
        . '<div role="tabpanel" id="a">One</div><div role="tabpanel" id="b">Two <span id="a">copy</span></div></div>'))],
    [[['Q', 'First <span id="dup">inner</span>']], [['A', 'One'], ['B', 'Two <span id="a">copy</span>']]]);
check('3.50 N124 (b): the same answers as before when the ids are unique', [
    A350Widgets::tabs($a350Element('<div><div role="tablist"><button role="tab" aria-controls="a">A</button><button role="tab" aria-controls="b">B</button></div>'
        . '<div role="tabpanel" id="a">One</div><div role="tabpanel" id="b">Two</div></div>'))],
    [[['A', 'One'], ['B', 'Two']]]);

// the audit's page: 1,500 question and answer pairs in 150 nested <div>s, each level with its own text – before 3.50 every
// level compared each button with every part found (minutes); now the outer levels are over the cap and the rest is linear
$a350Html = '<!doctype html><html><body><main><h1>Many questions</h1><p>' . str_repeat('An introduction long enough to be the content. ', 3) . '</p>';
for ($a350Level = 0; $a350Level < 150; $a350Level++) {
    $a350Html .= '<div class="level"><p>Text of level ' . $a350Level . '</p>' . $a350Pairs($a350Level * 10 + 1, $a350Level * 10 + 10);
}
$a350Html .= str_repeat('</div>', 150) . '</main></body></html>';
$a350Before = A350Widgets::lookups();
$a350Page = A350Web::extract($a350Html, 'https://old.example/faq/');
$a350Lookups = A350Widgets::lookups() - $a350Before;
check('3.50 N124: the audit\'s page (1,500 pairs in 150 nested divs) stays under 60,000 look-ups – a counter, not a clock – and the over-cap part is reported once', [
    $a350Lookups > 0 && $a350Lookups < 60_000, $a350Page['prvky'], $a350Page['pretizeno'] ?? null, str_contains($a350Page['obsah'], 'Answer 1500')],
    [true, [], 1, true]);
$a350Before = A350Widgets::lookups();
A350Web::extract($a350Html, 'https://old.example/faq/');
check('3.50 N124: reading the same page twice costs the same – the counter grows by the work done, nothing is remembered between pages', A350Widgets::lookups() - $a350Before, $a350Lookups);
check('3.50 N124: an ordinary FAQ page reports no crowded part', A350Web::extract('<main><h1>FAQ</h1><p>' . str_repeat('Intro text of the page. ', 5) . '</p><div>' . $a350Pairs(1, 5) . '</div></main>', 'https://old.example/faq/')['pretizeno'] ?? null, 0);

// (d) OldPage::index – a reset rule with hundreds of selectors gets its declarations once per key, at most 200 parts of it
$a350Index = (new ReflectionMethod(Kaleta\Core\OldPage::class, 'index'))->getClosure(null);
$a350Reset = implode(', ', array_map(fn (int $n): string => '.c' . $n . ', .c' . $n . ' p', range(1, 150)));
$a350Indexed = $a350Index([[$a350Reset, ['margin' => '0']], ['.c1, .c1', ['color' => 'red']], ['h1, .hero .button, a:hover', ['font-weight' => '700']]]);
check('3.50 N124 (d): one merge per key and rule – the same declarations as before; the 201st part of a rule is not indexed', [
    $a350Indexed['.c1'], $a350Indexed['.c100'] ?? null, isset($a350Indexed['.c101']), $a350Indexed['p'] ?? null, $a350Indexed['h1'], $a350Indexed['.button'], isset($a350Indexed['a']),
    Kaleta\Core\OldPage::SELECTORS_PER_RULE],
    [['margin' => '0', 'color' => 'red'], ['margin' => '0'], false, ['margin' => '0'], ['font-weight' => '700'], ['font-weight' => '700'], false, 200]);
check('3.50 N124 (e): two batches may end on one page; the third skips it with this reason', [A350Web::PAGE_ATTEMPTS, A350Web::STOPPED_TWICE !== '' && t(A350Web::STOPPED_TWICE) !== ''], [2, true]);
