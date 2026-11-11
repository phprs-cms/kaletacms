<?php

declare(strict_types=1);

/* ---------- 3.33 (part B, X4): build_from_html recognises a map, a counter and common accordion and tab markup ---------- */
$walk333 = function (array $nodes) use (&$walk333): array {
    $out = [];
    foreach ($nodes as $n) {
        $out[] = $n;
        $out = array_merge($out, $walk333($n['deti'] ?? []));
    }

    return $out;
};
// the element types of a conversion, flat, without sections and containers
$c333Types = fn (array $conversion): array => array_values(array_filter(array_map(fn (array $p): string => $p['typ'], $walk333($conversion['stavba']['deti'])),
    fn (string $t): bool => !in_array($t, ['sekce', 'kontejner'], true)));
$c333Admin = Kaleta\Builder\HtmlConverter::convert('<section class="contact"><h2>Visit us</h2><iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2560!2d14.4378!3d50.0755!2m3" width="600" height="450"></iframe>'
    . '<div class="stat"><p data-counter="250">250+</p><p>completed projects</p></div></section>'
    . '<section><div class="faq"><button aria-expanded="false" aria-controls="a1">How long?</button><div id="a1" hidden><p>Six weeks.</p></div>'
    . '<button aria-expanded="false" aria-controls="a2">Where?</button><div id="a2" hidden><p>In the valley.</p></div></div>'
    . '<div class="tabs"><div role="tablist"><button role="tab" aria-controls="t1">Oak</button><button role="tab" aria-controls="t2">Ash</button></div>'
    . '<div role="tabpanel" id="t1"><p>Warm.</p></div><div role="tabpanel" id="t2" hidden><p>Light.</p></div></div></section>', true);
$c333Nodes = $walk333($c333Admin['stavba']['deti']);
$c333Of = fn (string $type): array => array_values(array_filter($c333Nodes, fn (array $p): bool => $p['typ'] === $type))[0]['obsah'] ?? [];
check('3.33 X4: build_from_html as an administrator – a map frame is a Map element (not Custom HTML), the data-counter paragraph a Counter, the ARIA accordion an FAQ, role=tab markup Tabs', [
    $c333Types($c333Admin), $c333Of('mapa'), array_intersect_key($c333Of('pocitadlo'), array_flip(['cislo', 'pred', 'za', 'popisek'])),
    array_column($c333Of('faq')['polozky'] ?? [], 'otazka'), array_column($c333Of('zalozky')['karty'] ?? [], 'nazev')],
    [['nadpis', 'mapa', 'pocitadlo', 'faq', 'zalozky'], ['adresa' => '50.0755, 14.4378', 'priblizeni' => '15'], ['cislo' => '250', 'pred' => '', 'za' => '+', 'popisek' => 'completed projects'],
     ['How long?', 'Where?'], ['Oak', 'Ash']]);
check('3.33 X4: the notes say what was recognised (and an iframe that is no widget still goes to Custom HTML for an administrator)', [
    count(array_filter($c333Admin['hlaseni'], fn (string $h): bool => (bool) preg_match('/^(An accordion became an FAQ element \(2 questions|Tabs became a Tabs element \(2 tabs|A map became a Map element \(50\.0755, 14\.4378\)|A counter became a Counter element \(250\))/', $h))),
    $c333Types(Kaleta\Builder\HtmlConverter::convert('<iframe src="https://example.com/widget"></iframe>', true)),
    $c333Types(Kaleta\Builder\HtmlConverter::convert('<iframe src="https://example.com/widget"></iframe>', false))],
    [4, ['html'], []]);
check('3.33 X4: counters of many shapes – a decimal, thousands, a prefix, a row of two is two counters, a lone number without a counter hint stays text', [
    array_map(fn (string $html): string => implode('|', array_map(fn (array $p): string => $p['typ'] === 'pocitadlo' ? $p['obsah']['pred'] . $p['obsah']['cislo'] . $p['obsah']['za'] . '/' . $p['obsah']['popisek'] : $p['typ'],
        array_values(array_filter($walk333(Kaleta\Builder\HtmlConverter::convert($html)['stavba']['deti']), fn (array $p): bool => !in_array($p['typ'], ['sekce', 'kontejner'], true))))), [
        '<div class="rating"><span class="counter">4.9</span><span class="label">average rating</span></div>',
        '<div class="kpi"><strong class="count-up">1,200</strong> <span>kitchens</span></div>',
        '<div class="stat"><span data-count="30">0</span> <small class="caption">years</small></div>',
        '<div class="stats"><div class="stat"><b data-counter="5">5</b><p>awards</p></div><div class="stat"><b data-counter="9">9</b><p>teams</p></div></div>',
        '<p>Since 1990 we have built 300 kitchens.</p>',
    ])],
    [['4.9/average rating', '1200/kitchens', '30/years', '5/awards|9/teams', 'text']]);
check('3.33 X4: build_from_html tells Claude in its description and in the migrate_site runbook which markup becomes which element', [
    str_contains((string) json_encode(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions())), 'Accordions, tabs (role=tab), map and video frames, a form and data-counter numbers become their elements.'),
    str_contains(Kaleta\Mcp\Prompts::get('migrate_site', ['old_url' => 'https://old.example'])['messages'][0]['content']['text'], 'become the FAQ, Tabs, Map, Video, Form and Counter')],
    [true, true]);
