<?php

declare(strict_types=1);

/* ---------- 2.10: links between collections, redirect of hidden items ---------- */
$linkFields = Kaleta\Builder\Collections::sanitizeFields([['popisek' => 'Pobočka', 'typ' => 'polozka', 'kolekce' => 'pobocky'], ['popisek' => 'Bez kolekce', 'typ' => 'polozka'], ['popisek' => 'Role', 'typ' => 'text', 'kolekce' => 'x']]);
check('2.10: an item link remembers its collection; without one it is a short text', $linkFields,
    [['klic' => 'pobocka', 'popisek' => 'Pobočka', 'typ' => 'polozka', 'kolekce' => 'pobocky'], ['klic' => 'bez_kolekce', 'popisek' => 'Bez kolekce', 'typ' => 'text'], ['klic' => 'role', 'popisek' => 'Role', 'typ' => 'text']]);
$linkErrors = [];
check('2.10: an item link stores the address of the item', [Kaleta\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'praha-centrum'], $linkErrors), Kaleta\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'Praha <b>'], $linkErrors), $linkErrors],
    [['pobocka' => 'praha-centrum', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => '', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => 'Pobočka']]);
$linkValues = Kaleta\Builder\Collections::values(['seo_link' => 'lide', 'detail' => 1, 'pole' => $linkFields], ['nazev' => 'Jana', 'seo_link' => 'jana', 'datum' => '2026-10-02 10:00:00', 'data' => ['pobocka' => 'praha-centrum']], fn (string $p): string => '/' . $p);
check('2.10: {{field}}, {{field_url}} and {{field_seo}} of an item link (without a database only the address)', [$linkValues['pobocka'], $linkValues['pobocka_url'], $linkValues['pobocka_seo']],
    [['', 'text'], ['', 'odkaz'], ['praha-centrum', 'text']]);
// 3.9.2: {{obrazek}} without such a field = the item page's image, else the first image field; a field named obrazek stays itself
$imageFields = [['klic' => 'perex', 'popisek' => 'Perex', 'typ' => 'text'], ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek']];
$imageItem = ['nazev' => 'Byt', 'seo_link' => 'byt', 'datum' => '2026-10-02 10:00:00', 'obrazek' => 'media/2026/10/hero.jpg', 'data' => ['foto' => 'media/2026/10/foto.jpg']];
check('3.9.2: {{obrazek}} of an item – its page image, else its first image field, a field of that name wins', [
    Kaleta\Builder\Collections::values(['seo_link' => 'r', 'detail' => 1, 'pole' => $imageFields], $imageItem, fn (string $p): string => '/' . $p)['obrazek'],
    Kaleta\Builder\Collections::values(['seo_link' => 'r', 'detail' => 1, 'pole' => $imageFields], ['obrazek' => ''] + $imageItem, fn (string $p): string => '/' . $p)['obrazek'],
    Kaleta\Builder\Collections::values(['seo_link' => 'r', 'detail' => 1, 'pole' => [['klic' => 'obrazek', 'popisek' => 'Obrázek', 'typ' => 'text']]], ['data' => ['obrazek' => 'x']] + $imageItem, fn (string $p): string => '/' . $p)['obrazek'],
    Kaleta\Builder\Collections::sample(['pole' => $imageFields])['obrazek'],
], [['media/2026/10/hero.jpg', 'obrazek'], ['media/2026/10/foto.jpg', 'obrazek'], ['x', 'text'], ['', 'obrazek']]);
check('2.10: where hidden items redirect – a path on the site or https', array_map(Kaleta\Builder\Collections::cleanRedirect(...), ['', '/tym', 'https://example.com/team', 'javascript:alert(1)', 'tym', '/a b']),
    ['', '/tym', 'https://example.com/team', null, null, null]);
check('2.10: Hours::rangesText – hours for people from ranges or their text, nothing from nonsense (the door sign)', [Kaleta\Core\Hours::rangesText([['08:00', '12:00'], ['13:30', '17:00']]), Kaleta\Core\Hours::rangesText('9-12'), Kaleta\Core\Hours::rangesText('morning')],
    ['8:00–12:00, 13:30–17:00', '9:00–12:00', '']);
check('2.10: HoursSign – formats, a standalone view with print CSS, a Print button only on screen and no outside resource', [Kaleta\Core\HoursSign::FORMATS,
    (fn (string $v): array => [str_contains($v, '@page'), str_contains($v, '@media print'), str_contains($v, 'data-tisk'), preg_match('/(href|src)="https?:/', $v) === 0, str_contains($v, '<!DOCTYPE html>')])((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/hours_sign.php'))],
    [['a4', 'a5'], [true, true, true, true, true]]);
