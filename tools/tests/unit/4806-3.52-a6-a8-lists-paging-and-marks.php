<?php

declare(strict_types=1);

/* ---------- 3.52 A6, A8: one paging for admin lists, the searched words marked, a snippet around the match ---------- */

check('3.52 A8: mark_words() – escaped first, then marked; case and diacritics do not matter, every word of the search, in any order', [
    mark_words('Řehoř Dvořák <b>', 'dvorak REHOR'), mark_words('<i>x</i>', '<i'), mark_words('Straße 5', 'strasse'), mark_words('a & b', ''), mark_words('', 'x'),
    mark_words('abcabc', 'bc'), mark_words('50% off', '%')],
    ['<mark>Řehoř</mark> <mark>Dvořák</mark> &lt;b&gt;', '<mark>&lt;i</mark>&gt;x&lt;/i&gt;', '<mark>Straße</mark> 5', 'a &amp; b', '', 'a<mark>bc</mark>a<mark>bc</mark>', '50<mark>%</mark> off']);
check('3.52 A8: search_marks() – at most 6 words, like Db::likeWords; overlapping words mark one run', [
    count(search_marks('a b c d e f g', 'a b c d e f g')), mark_words('kolekce', 'kol lek')],
    [6, '<mark>kolek</mark>ce']);

$a806Long = str_repeat('slovo ', 50) . 'Dvořák na konci ' . str_repeat('x', 200);
$a806Snippet = mark_snippet($a806Long, 'dvorak', 60);
check('3.52 A8: mark_snippet() – a window around the first match with an ellipsis on both sides; without a match the beginning; a short text whole', [
    str_starts_with($a806Snippet, '…'), str_ends_with($a806Snippet, '…'), str_contains($a806Snippet, '<mark>Dvořák</mark>'), mb_strlen(strip_tags($a806Snippet)) <= 62,
    mark_snippet('abc def', 'zzz', 5), mark_snippet('krátký <text>', 'TEXT'), mark_snippet(str_repeat('y', 100) . ' konec', 'konec', 20)],
    [true, true, true, true, 'abc d…', 'krátký &lt;<mark>text</mark>&gt;', '…' . str_repeat('y', 14) . ' <mark>konec</mark>']);

// the shared paging (views/admin/paging.php): the window of ±5, the first and last page always, a gap between, page 1 without ?page
$a806View = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$a806Paging = fn (int $page, int $pages): string => $a806View->render('admin/paging', ['page' => $page, 'pages' => $pages, 'link' => fn (int $s): string => '/l?page=' . $s]);
preg_match_all('/>\[?(\d+)\]?</', $a806Paging(10, 30), $a806Numbers);
check('3.52 A8: paging – nothing for one page; pages 1, 5–15 and 30 around page 10, two gaps, the current page marked for screen readers', [
    $a806Paging(1, 1), $a806Numbers[1], substr_count($a806Paging(10, 30), 'strankovani-mezera'), str_contains($a806Paging(10, 30), '<strong aria-current="page">[10]</strong>'),
    substr_count($a806Paging(1, 3), 'strankovani-mezera')],
    ['', ['1', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '30'], 2, true, 0]);
$a806Module = new class {
    /** @param array<string, scalar> $params */
    public function url(string $action = '', array $params = []): string { return $action . '?' . http_build_query($params); }
};
check('3.52 A8: paging with $module and $query – the filter kept, empty values and page 1 left out, 0 kept (Media: Uncategorized is section 0)',
    array_values(array_map('html_entity_decode', (preg_match_all('/href="([^"]*)"/', $a806View->render('admin/paging', ['module' => $a806Module, 'action' => 'items',
        'query' => ['id' => 4, 'search' => 'a b', 'status' => '', 'category' => null, 'section' => 0], 'page' => 2, 'pages' => 3]), $a806Links) ? $a806Links[1] : []))),
    ['items?id=4&search=a+b&section=0', 'items?id=4&search=a+b&section=0&page=3']);

// A6: the admin list of items and MCP list_collection_items search the same way
check('3.52 A6: Collections::itemSearch() – the name, the address and the fields (JSON), as Db::likeWords (the smoke test runs it in the admin and MCP)', [
    Kaleta\Builder\Collections::itemSearch('dvorak'), Kaleta\Builder\Collections::itemSearch('')],
    [Kaleta\Core\Db::likeWords(['nazev', 'seo_link'], 'dvorak', ['data']), ['1 = 1', []]]);

// A8: no list builds its own numbered paging any more – the shared view does (Prices keeps its own: a page link warns of unsaved prices)
$a806Own = [];
foreach (glob(KALETA_SYSTEM . '/views/admin/{*,*/*}.php', GLOB_BRACE) ?: [] as $a806File) {
    if (str_contains((string) file_get_contents($a806File), '<p class="strankovani">') && !str_ends_with($a806File, 'quotes/prices.php')) {
        $a806Own[] = basename(dirname($a806File)) . '/' . basename($a806File);
    }
}
check('3.52 A8: every numbered list paging goes through views/admin/paging.php', $a806Own, []);
