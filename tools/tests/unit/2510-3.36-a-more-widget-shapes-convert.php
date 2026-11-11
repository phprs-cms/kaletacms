<?php

declare(strict_types=1);

use Kaleta\Builder\HtmlConverter;
use Kaleta\Builder\HtmlWidgets;

/* ---------- 3.36 (part A): build_from_html recognises more of what move reports listed as not converted – by meaning, for any builder ---------- */
$w336Types = function (string $html): string {
    $types = [];
    $walk = function (array $nodes) use (&$walk, &$types): void {
        foreach ($nodes as $n) {
            $types[] = $n['typ'] . ($n['typ'] === 'faq' ? count($n['obsah']['polozky'] ?? []) : '') . ($n['typ'] === 'pocitadlo' ? '=' . ($n['obsah']['cislo'] ?? '') . '|' . ($n['obsah']['za'] ?? '') . '|' . ($n['obsah']['popisek'] ?? '') : '');
            $walk($n['deti'] ?? []);
        }
    };
    $walk(HtmlConverter::convert($html)['stavba']['deti']);

    return implode(',', $types);
};

check('3.36 accordion: a button with aria-expanded and no aria-controls opens the element after it (also after a heading that holds only the button); a submenu, a menu toggle and anything in a navigation stay what they are', [
    $w336Types('<section><div class="faq"><div class="item"><button aria-expanded="false">What is it?</button><div class="panel" hidden><p>An answer.</p></div></div>'
        . '<div class="item"><button aria-expanded="false">How much?</button><div class="panel" hidden><p>Little.</p></div></div></div></section>'),
    $w336Types('<section><div><h3><button aria-expanded="true">Q1</button></h3><div><p>A1</p></div><h3><button aria-expanded="false">Q2</button></h3><div><p>A2</p></div></div></section>'),
    str_contains($w336Types('<section><div class="menu"><ul><li><button aria-expanded="false">Services</button><ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></li></ul></div></section>'), 'faq'),
    str_contains($w336Types('<section><div><button aria-expanded="false" aria-haspopup="true">Menu</button><div><p>Open</p></div></div></section>'), 'faq'),
    str_contains($w336Types('<section><div><nav><button aria-expanded="false">Menu</button><div><p>Open</p></div></nav></div></section>'), 'faq'),
], ['sekce,faq2', 'sekce,faq2', false, false, false]);

check('3.36 accordion: a definition list of questions becomes an FAQ when it says so (class or id) or every term asks; a plain list of terms stays text', [
    $w336Types('<section><h2>FAQ</h2><dl class="faq"><dt>Delivery</dt><dd>Within a week.</dd><dt>Payment</dt><dd><p>By card.</p></dd></dl></section>'),
    $w336Types('<section><dl><dt>Do you deliver?</dt><dd>Yes.</dd><dt>Can I pay later?</dt><dd>Yes.</dd><dd>Within 30 days.</dd></dl></section>'),
    $w336Types('<section><dl><dt>Address</dt><dd>Main 1</dd><dt>Phone</dt><dd>123</dd></dl></section>'),
], ['sekce,nadpis,faq2', 'sekce,faq2', 'sekce,text']);

check('3.36 counter: a plain figure block – the number alone in an element whose class or parent says stat, number, kpi – with its sign and label; never a phone number, a price or a date', [
    $w336Types('<section><div class="stats"><div class="stat"><strong>250+</strong><span>projects</span></div><div class="stat"><strong>15</strong><span>years</span></div></div></section>'),
    $w336Types('<section><div><p class="number">98 %</p><p>Happy clients</p></div></section>'),
    $w336Types('<section><div class="contact-number"><span class="number">777 123 456</span><p>Call us</p></div></section>'),
    $w336Types('<section><div class="price-box"><span class="price-number">990</span><p>per month</p></div></section>'),
    $w336Types('<section><div class="stat"><span class="date">2024</span><p>Founded</p></div></section>'),
], ["sekce,pocitadlo=250|+|projects,pocitadlo=15||years", "sekce,pocitadlo=98|\u{A0}%|Happy clients", 'sekce,text,text', 'sekce,text,text', 'sekce,text,text']);

$w336Unknown = HtmlConverter::convert('<section><iframe src="https://frame.mapy.cz/s/abcdef"></iframe></section>');
$w336Admin = HtmlConverter::convert('<section><iframe src="https://frame.mapy.cz/s/abcdef"></iframe></section>', true);
check('3.36 map: the Mapy.com embed of today (center=lng,lat) is a Map; a map frame whose place cannot be read says to add a Map element with the address (an administrator keeps the frame as Custom HTML too)', [
    HtmlWidgets::mapOfUrl('https://mapy.com/fnc/v1/showmap?mapset=basic&center=14.4212,50.0874&zoom=16&marker=true'),
    $w336Types('<section><iframe src="https://mapy.cz/fnc/v1/showmap?center=14.42,50.08&amp;zoom=15"></iframe></section>'),
    [$w336Unknown['stavba']['deti'][0]['deti'] ?? null, $w336Unknown['hlaseni']],
    [array_column($w336Admin['stavba']['deti'][0]['deti'] ?? [], 'typ'), count(array_filter($w336Admin['hlaseni'], fn (string $m): bool => str_contains($m, 'add a Map element')))],
    [HtmlWidgets::isMapFrame('https://www.google.com/maps/d/embed?mid=1abc'), HtmlWidgets::isMapFrame('https://www.openstreetmap.org/export/embed.html'), HtmlWidgets::isMapFrame('https://www.youtube.com/embed/x'), HtmlWidgets::isMapFrame('https://google.com/search')],
], [['50.0874, 14.4212', '15'], 'sekce,mapa', [[], ['A map frame without a readable place (frame.mapy.cz) – add a Map element with the address instead.']], [['html'], 1], [true, true, false, false]]);
