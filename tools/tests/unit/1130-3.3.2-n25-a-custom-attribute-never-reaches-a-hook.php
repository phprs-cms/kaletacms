<?php

declare(strict_types=1);

/* ---------- 3.3.2 (N25): a custom attribute never reaches a hook of the site's scripts, and never overrides the element's own ---------- */
// every data-* attribute a script on the public page mentions must be refused as a custom attribute – a new hook in web.js fails here until it is reserved
$frontScripts = ['image/web.js' => (string) file_get_contents(KALETA_ROOT . '/image/web.js'), 'image/vitals.js' => (string) file_get_contents(KALETA_ROOT . '/image/vitals.js')];
foreach (glob(KALETA_SYSTEM . '/views/front/*.php') ?: [] as $frontView) {
    preg_match_all('#<script>(.*?)</script>#s', (string) file_get_contents($frontView), $inlineScripts);
    $frontScripts['views/front/' . basename($frontView)] = implode("\n", $inlineScripts[1]);
}
// image/editor.js runs on the page for editing in place: the hooks it looks up in the whole document
preg_match_all('/document\.querySelector(?:All)?\(\'([^\']*)\'\)/', (string) file_get_contents(KALETA_ROOT . '/image/editor.js'), $editorSelectors);
$frontScripts['image/editor.js'] = implode("\n", $editorSelectors[1]);
$unreserved = [];
$hidden = [];
foreach ($frontScripts as $script => $source) {
    preg_match_all('/data-[a-z0-9-]*[a-z0-9]/', $source, $hooks);
    foreach (array_unique($hooks[0]) as $hook) {
        if (preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, $hook)) {
            $unreserved[] = $script . ': ' . $hook;
        }
    }
    // a hook the scan above cannot see (dataset, a name put together) would slip through – write the full name in the script
    if (preg_match('/\.dataset\b|[\'"]data-[\'"]\s*\+|[\'"]data-[a-z0-9-]*-[\'"]\s*\+/', $source)) {
        $hidden[] = $script;
    }
}
check('3.3.2 N25: every data-* hook of the public scripts (web.js, vitals.js, inline scripts of the site views, editor.js on the page) is reserved; each is written in full', [
    count($frontScripts) > 5, $unreserved, $hidden], [true, [], []]);
[$n25Build, $n25Errors] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'pobocky', 'atributy' => ['data-leaflet' => 'https://evil.example/', 'data-atribuce' => '<img src=x onerror=alert(1)>',
    'data-kosik' => 'javascript:alert(1)', 'data-porovnani' => 'javascript:alert(2)', 'data-produkt' => '{}', 'data-popup' => '1', 'data-kolekce' => 'x', 'data-pobocky' => '', 'data-sledovat' => 'ok']]]], false);
check('3.3.2 N25: the hooks of the store locator, the basket, the comparison and the popups cannot be saved as custom attributes; a harmless one stays', [
    $n25Build['deti'][0]['atributy'] ?? [], isset($n25Errors['deti[0].atributy'])], [['data-sledovat' => 'ok'], true]);
$n25App = new Kaleta\Core\App([]);
$n25Html = fn (array $build): string => Kaleta\Builder\Build::html($build, new Kaleta\Builder\Context($n25App));
// a build stored before 3.3.2 (never sanitized again): the hook is left out, the element renders as before
$n25Stored = $n25Html(['deti' => [['id' => 'h1', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Hi'], 'atributy' => ['data-leaflet' => 'https://evil.example/', 'data-kosik' => 'javascript:alert(1)', 'onclick' => 'alert(1)', 'data-x' => 'y', 'title' => 'T']]]]);
[$n25Button] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'tlacitko', 'obsah' => ['text' => 'Go', 'odkaz' => 'https://example.com/', 'nove_okno' => true], 'atributy' => ['rel' => 'opener', 'title' => 'Mine']],
    ['typ' => 'nahoru', 'atributy' => ['aria-label' => 'Custom', 'data-x' => 'y']]]]);
$n25Own = $n25Html($n25Button);
check('3.3.2 N25: a stored build with a reserved hook renders without it (no error); the element\'s own attributes come before the custom ones, so the browser keeps the element\'s', [
    $n25Stored, (bool) preg_match('/<a class="ka-tlacitko ka-tlacitko--primarni" href="https:\/\/example\.com\/" target="_blank" rel="noopener" rel="opener" title="Mine">/', $n25Own),
    (bool) preg_match('/<a class="ka-nahoru" href="#" aria-label="[^"]+" aria-label="Custom" data-x="y">/', $n25Own)],
    ['<h2 data-x="y" title="T">Hi</h2>', true, true]);
$webJs = $frontScripts['image/web.js'];
check('[source-pin] 3.3.2 N25: web.js loads Leaflet only from the site\'s own copy, writes the map credit as text and makes basket and comparison links only from http(s) or site addresses', [
    (bool) preg_match('/leaflet\.origin === location\.origin && \/\\\\\/image\\\\\/vendor\\\\\/leaflet\\\\\/\$\/\.test\(leaflet\.pathname\)/', $webJs), str_contains($webJs, "attributionControl: false"), str_contains($webJs, 'link.textContent = credit;'),
    str_contains($webJs, 'attribution:'), str_contains($webJs, "safeUrl(form.getAttribute('data-kosik'))"), str_contains($webJs, "safeUrl(box.form.getAttribute('data-porovnani'))"),
    substr_count($webJs, 'A(basket.page)') + substr_count($webJs, 'A(compare.url'), Kaleta\Builder\Elements\StoreLocator::LEAFLET_PATH],
    [true, true, true, false, true, true, 0, 'image/vendor/leaflet/']);

