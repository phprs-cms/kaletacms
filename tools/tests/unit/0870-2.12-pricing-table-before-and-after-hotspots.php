<?php

declare(strict_types=1);

/* ---------- 2.12: pricing table, before and after, hotspots, timeline ---------- */
$f9App = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
$f9Context = new Kaleta\Builder\Context($f9App);
$f9Editor = new Kaleta\Builder\Context($f9App, true);
[$f9Build, $f9Errors] = Kaleta\Builder\Build::sanitize(['deti' => [
    ['typ' => 'cenik', 'id' => 'cen1', 'obsah' => ['plany' => [
        ['nazev' => 'Basic', 'cena' => '9', 'obdobi' => '/ month', 'popis' => 'Start', 'funkce' => "One\n- Two\n  \n-", 'tlacitko' => 'Choose', 'odkaz' => 'javascript:alert(1)', 'zvyraznit' => false, 'stitek' => 'Most popular'],
        ['nazev' => 'Pro', 'cena' => '29', 'funkce' => 'One', 'tlacitko' => 'Choose', 'odkaz' => '/order', 'zvyraznit' => true, 'stitek' => 'Most popular'],
        ['nazev' => '', 'cena' => '0'],
    ]]],
    ['typ' => 'hotspoty', 'id' => 'hs1', 'obsah' => ['src' => '/media/2026/plan.jpg', 'alt' => 'Plan', 'body' => [['x' => 250, 'y' => -5, 'nazev' => 'Entrance', 'popis' => "Line 1\nLine & 2"], ['x' => 20, 'y' => 30, 'nazev' => '', 'popis' => 'hidden']]]],
    ['typ' => 'pred_po', 'id' => 'pp1', 'obsah' => ['obrazek_pred' => '/media/2026/a.jpg', 'obrazek_po' => 'http://x.cz/b.jpg', 'delic' => 130]],
    ['typ' => 'casova_osa', 'id' => 'to1', 'obsah' => ['udalosti' => [['datum' => '2020', 'nazev' => 'Start', 'obsah' => '<p onclick="x">Text</p>'], ['datum' => '', 'nazev' => '', 'obsah' => '<p>skip</p>'], ['datum' => 'Today', 'nazev' => '', 'obsah' => '']]]],
]]);
[$f9Pricing, $f9Hotspots, $f9BeforeAfter, $f9Timeline] = $f9Build['deti'];
check('2.12 sanitize: a plan link is checked like a button, the hotspot position is clamped to 0–100, the divider too, timeline HTML is cleaned, item defaults filled in', [
    $f9Pricing['obsah']['plany'][0]['odkaz'], $f9Pricing['obsah']['plany'][1]['odkaz'], $f9Pricing['obsah']['plany'][1]['obdobi'], array_keys($f9Errors),
    $f9Hotspots['obsah']['body'][0]['x'], $f9Hotspots['obsah']['body'][0]['y'], $f9BeforeAfter['obsah']['delic'], $f9BeforeAfter['obsah']['obrazek_po'], $f9Timeline['obsah']['udalosti'][0]['obsah'], $f9Timeline['obsah']['udalosti'][0]['src']],
    ['', '/order', '', ['deti[0].obsah.plany.odkaz', 'deti[2].obsah.obrazek_po'], 100, 0, 100, '', '<p>Text</p>', '']);
check('2.12 PricingTable::features – a line starting with "-" is not included, blank lines and a bare dash are skipped', Kaleta\Builder\Elements\PricingTable::features("One\n- Two\n  \n-\n -Three "), [[true, 'One'], [false, 'Two'], [false, 'Three']]);
$f9Html = Kaleta\Builder\Elements\PricingTable::render($f9Pricing, '', '', $f9Context);
check('2.12 pricing table: two cards (an unnamed plan is left out), the highlighted one with its class, label and primary button, the other with an outline button, an excluded feature crossed out with a text for screen readers, a discarded link falls back to #', [
    substr_count($f9Html, '<article class="ka-cenik-plan'), substr_count($f9Html, 'ka-cenik-plan--zvyrazneny'), substr_count($f9Html, '<p class="ka-cenik-stitek">Most popular</p>'),
    str_contains($f9Html, '<li class="ka-cenik-ne"><span class="ka-cenik-sr">' . t('Not included:') . ' </span>Two</li>'), str_contains($f9Html, '<li><span class="ka-cenik-sr">' . t('Included:') . ' </span>One</li>'),
    str_contains($f9Html, '<a class="ka-tlacitko ka-tlacitko--obrys" href="#">Choose</a>'), str_contains($f9Html, '<a class="ka-tlacitko ka-tlacitko--primarni" href="/order">Choose</a>'),
    str_contains($f9Html, '<p class="ka-cenik-cena"><strong>9</strong> <span>/ month</span></p>'), str_contains($f9Html, '<p class="ka-cenik-cena"><strong>29</strong></p>'), isset($f9Context->types['tlacitko']), str_starts_with($f9Html, '<div class="ka-cenik">')],
    [2, 1, 1, true, true, true, true, true, true, true, true]);
$f9Html = Kaleta\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Context);
check('2.12 before and after: without the after image nothing for visitors, a notice in the editor', [$f9Html, str_contains(Kaleta\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Editor), t('Choose the before and after images in the Content panel.'))], ['', true]);
$f9BeforeAfter['obsah']['obrazek_po'] = '/media/2026/b.jpg';
$f9Html = Kaleta\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Context);
check('2.12 before and after: two figures with their labels, the range control with the divider position, the data hook for web.js, enabled only in the editor', [
    substr_count($f9Html, '<figure class="ka-pred-po-'), substr_count($f9Html, '<figcaption>'), str_contains($f9Html, '<figure class="ka-pred-po-pred"><img src="/media/2026/a.jpg" alt="" loading="lazy"><figcaption>' . t('Before') . '</figcaption></figure>'),
    str_contains($f9Html, '<input type="range" class="ka-pred-po-ovladac" min="0" max="100" value="100" aria-label="' . t('Compare before and after') . '">'),
    str_starts_with($f9Html, '<div class="ka-pred-po" data-pred-po style="--ka-delic:100%">'), str_contains(Kaleta\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Editor), ' data-pred-po data-zapnuto ')],
    [2, 2, true, true, true, true]);
$f9Html = Kaleta\Builder\Elements\Hotspots::render($f9Hotspots, '', '', $f9Context);
check('2.12 hotspots: one point (the unnamed one is left out) as a details popover placed at the clamped position, opening to the left, the number hidden from screen readers and the label read instead, the text with line breaks escaped, and the list under the image', [
    substr_count($f9Html, '<details'), str_contains($f9Html, '<details class="ka-hotspoty-bod ka-hotspoty-bod--vlevo" name="hs-hs1" style="--x:100%;--y:0%">'),
    str_contains($f9Html, '<summary><span aria-hidden="true">1</span><span class="ka-hotspoty-sr">Entrance</span></summary>'),
    str_contains($f9Html, '<div class="ka-hotspoty-popis"><strong>Entrance</strong><p>Line 1<br />' . "\n" . 'Line &amp; 2</p></div>'),
    str_contains($f9Html, '<ol class="ka-hotspoty-seznam"><li><strong>Entrance</strong> – Line 1<br />'), str_contains($f9Html, '<img src="/media/2026/plan.jpg" alt="Plan" loading="lazy">')],
    [1, true, true, true, true, true]);
$f9Hotspots['obsah']['body'][0] = ['x' => 10, 'y' => 90, 'nazev' => 'Low', 'popis' => ''];
check('2.12 hotspots: a point low down opens upwards, a point on the left opens to the right; without a text only the label', [
    str_contains(Kaleta\Builder\Elements\Hotspots::render($f9Hotspots, '', '', $f9Context), '<details class="ka-hotspoty-bod ka-hotspoty-bod--nahoru" name="hs-hs1" style="--x:10%;--y:90%"><summary><span aria-hidden="true">1</span><span class="ka-hotspoty-sr">Low</span></summary><div class="ka-hotspoty-popis"><strong>Low</strong></div></details>'),
    Kaleta\Builder\Elements\Hotspots::render(['id' => 'hs2', 'obsah' => ['src' => '', 'alt' => '', 'body' => []]], '', '', $f9Context)], [true, '']);
$f9Html = Kaleta\Builder\Elements\Timeline::render($f9Timeline, '', '', $f9Context);
check('2.12 timeline: an ordered list, an item without a date and title is left out, a date alone stays, the date, heading and cleaned text in the card', [
    str_starts_with($f9Html, '<ol class="ka-casova-osa">'), substr_count($f9Html, '<li class="ka-casova-osa-polozka">'),
    str_contains($f9Html, '<li class="ka-casova-osa-polozka"><div class="ka-casova-osa-karta"><span class="ka-casova-osa-datum">2020</span><h3>Start</h3><p>Text</p></div></li>'),
    str_contains($f9Html, '<span class="ka-casova-osa-datum">Today</span></div></li></ol>'), Kaleta\Builder\Elements\Timeline::render(['id' => 'to2', 'obsah' => ['udalosti' => []]], '', '', $f9Context)], [true, 2, true, true, '']);
$f9Text = Kaleta\Builder\Build::asText($f9Build);
check('2.12 Build::asText – plans with prices and features, points and milestones are page content for search and the .md version', [
    str_contains($f9Text, "<h3>Basic</h3><p>9 / month</p><p>Start</p><ul><li>One</li><li>Two (" . t('not included') . ")</li></ul>\n<h3>Pro</h3><p>29</p><ul><li>One</li></ul>"),
    str_contains($f9Text, '<ol><li>Entrance – Line 1' . "\n" . 'Line &amp; 2</li></ol>'), str_contains($f9Text, "<h3>2020 – Start</h3><p>Text</p>\n<h3>Today</h3>")], [true, true, true]);
$f9English = Kaleta\Mcp\Vocabulary::elementToEnglish($f9Hotspots);
check('2.12 Vocabulary: the new elements and their items in English and back', [
    Kaleta\Mcp\Vocabulary::TYPES['cenik'], Kaleta\Mcp\Vocabulary::TYPES['pred_po'], Kaleta\Mcp\Vocabulary::TYPES['casova_osa'], $f9English['type'], array_keys($f9English['content']), array_keys($f9English['content']['points'][0]),
    Kaleta\Mcp\Vocabulary::elementToCzech($f9English) === $f9Hotspots,
    Kaleta\Mcp\Vocabulary::elementToCzech(['type' => 'pricing_table', 'content' => ['plans' => [['name' => 'A', 'price' => '1', 'period' => '/ m', 'features' => 'x', 'button_text' => 'Go', 'link' => '/a', 'highlighted' => true, 'badge' => 'Top']]]]),
    Kaleta\Mcp\Vocabulary::elementToCzech(['type' => 'before_after', 'content' => ['before_image' => 'media/a.jpg', 'after_label' => 'After', 'divider_position' => 30]])],
    ['pricing_table', 'before_after', 'timeline', 'hotspots', ['src', 'alt', 'points'], ['x', 'y', 'name', 'description'], true,
        ['typ' => 'cenik', 'obsah' => ['plany' => [['nazev' => 'A', 'cena' => '1', 'obdobi' => '/ m', 'funkce' => 'x', 'tlacitko' => 'Go', 'odkaz' => '/a', 'zvyraznit' => true, 'stitek' => 'Top']]]],
        ['typ' => 'pred_po', 'obsah' => ['obrazek_pred' => 'media/a.jpg', 'popisek_po' => 'After', 'delic' => 30]]]);
$f9Schema = array_column(Kaleta\Builder\Build::schema(true, 'en')['prvky'], null, 'typ');
check('2.12 schema: the four elements are content blocks with a sensible default in the page language, the before-and-after hook cannot be a custom attribute', [
    array_map(fn (string $t): string => $f9Schema[$t]['skupina'], ['cenik', 'pred_po', 'hotspoty', 'casova_osa']), array_column($f9Schema['cenik']['vlastnosti']['plany']['vychozi'], 'nazev'), $f9Schema['cenik']['vlastnosti']['plany']['vychozi'][1]['zvyraznit'],
    $f9Schema['pred_po']['vlastnosti']['popisek_pred']['vychozi'], count($f9Schema['hotspoty']['vlastnosti']['body']['vychozi']), $f9Schema['casova_osa']['vlastnosti']['udalosti']['vychozi'][2]['datum'],
    preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-pred-po'), preg_match('/data-\(vlozit\|[^)]*pred-po/', (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php'))],
    [['Content', 'Content', 'Content', 'Content'], ['Basic', 'Standard', 'Premium'], true, 'Before', 2, 'Today', 0, 1]);

