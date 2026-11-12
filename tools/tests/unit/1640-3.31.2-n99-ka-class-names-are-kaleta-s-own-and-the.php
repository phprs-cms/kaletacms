<?php

declare(strict_types=1);

/* ---------- 3.31.2 (N99): "ka-" class names are Kaleta's own, and the collection list is only ever fetched from its own page ---------- */
// every class the public scripts look up is a "ka-" class or one the page frame writes itself; a "ka-" name is refused as a
// user class in every path that reads Build::CLASS_PATTERN (builder, MCP, imports, shared classes, kits)
preg_match_all('/\.(ka-[a-z0-9-]*[a-z0-9])/', $frontScripts['image/web.js'] . $frontScripts['image/vitals.js'], $n99Hooks);
$n99Hooks = array_values(array_unique($n99Hooks[1]));
check('[source-pin] 3.31.2 N99: every .ka-… class hook of the public scripts is refused as a user class; ordinary names stay', [
    count($n99Hooks) > 10, in_array('ka-kolekce-strany', $n99Hooks, true), in_array('ka-kolekce-filtry', $n99Hooks, true),
    array_values(array_filter($n99Hooks, fn (string $c): bool => preg_match(Kaleta\Builder\Build::CLASS_PATTERN, $c) === 1)),
    array_map(fn (string $c): int => (int) preg_match(Kaleta\Builder\Build::CLASS_PATTERN, $c), ['karta', 'karta--zvyraznena', 'kabel', 'kafe-menu', 'ka', 'ka-', 'ka-x', 'ka-kolekce-strany', 'ka-tlacitko--primarni']),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), substr(Kaleta\Builder\Build::CLASS_PATTERN, 1, -2) . '/.test(name)')],
    [true, true, true, [], [1, 1, 1, 1, 1, 0, 0, 0, 0], true]);
[$n99Build, $n99Errors] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'text', 'obsah' => ['html' => '<p><a href="https://evil.example/p">More</a></p>'], 'tridy' => ['ka-kolekce-strany', 'karta', 'ka-kolekce-filtry']]]], false);
// a build stored before 3.31.2 (never sanitized again): the class is left out when the page is rendered, the rest stays
$n99Stored = $n25Html(['deti' => [['id' => 'n99t', 'typ' => 'text', 'znacka' => 'div', 'obsah' => ['html' => '<p><a href="https://evil.example/p">More</a></p>'], 'tridy' => ['ka-kolekce-strany', 'karta']]]]);
check('3.31.2 N99: a "ka-" class is left out of a saved build with a note, and a build stored with one renders without it', [
    $n99Build['deti'][0]['tridy'] ?? [], isset($n99Errors['deti[0].tridy']), Kaleta\Mcp\Translator::message((string) ($n99Errors['deti[0].tridy'] ?? '')),
    str_contains($n99Stored, 'ka-kolekce-strany'), (bool) preg_match('/class="[^"]*\bkarta\b[^"]*"/', $n99Stored), str_contains($n99Stored, 'href="https://evil.example/p"'),
    Kaleta\Builder\Build::userClasses(['ka-pobocky-seznam', 'karta', 'karta', 5, 'Bad Name']), Kaleta\Builder\Build::userClasses('ka-x')],
    [['karta'], true, 'Class names starting with "ka-" are reserved for Kaleta – left out.', false, true, true, ['karta'], []]);
// no bundled section, starter or blueprint uses a "ka-" class on an element (they would lose it)
$n99Bundled = [];
foreach (Kaleta\Builder\Library::CLASSES as $n99Name => $unused) {
    if (preg_match(Kaleta\Builder\Build::CLASS_PATTERN, (string) $n99Name) !== 1) {
        $n99Bundled[] = (string) $n99Name;
    }
}
check('3.31.2 N99: the classes of the section library are all valid user classes', $n99Bundled, []);
$n99Js = $frontScripts['image/web.js'];
$n99Swap = substr($n99Js, (int) strpos($n99Js, 'function swapList('), 1400);
check('3.31.2 N99: web.js fetches a list only from its own page (same origin and path), checks where the answer came from and that it is HTML, and leaves any other link to the browser', [
    str_contains($n99Js, "return u.origin === location.origin && u.pathname.replace(/\\/+$/, '') === location.pathname.replace(/\\/+$/, '');"),
    (bool) preg_match('/^function swapList\(wrapper, url, push\) \{\s+if \(!ownPage\(url\)\) \{ location\.href = url; return; \}/', $n99Swap),
    str_contains($n99Swap, "!ownPage(r.url) || !/^text\\/html\\b/i.test(r.headers.get('Content-Type') || '')"),
    str_contains($n99Js, "if (!ownPage(link.href)) { return; }"), substr_count($n99Js, 'swapList(')],
    [true, true, true, true, 3]);

