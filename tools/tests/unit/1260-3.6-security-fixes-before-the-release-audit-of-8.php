<?php

declare(strict_types=1);

/* ---------- 3.6 security fixes before the release (audit of 8 Oct 2026) ---------- */
// N36-3: a Windows-1250 CSV from Excel is converted (mbstring has no Windows-1250 – the import threw a ValueError, error 500)
check('3.6 N36-3: a Windows-1250 CSV becomes UTF-8, UTF-8 stays as it is', [
    Kaleta\Admin\Modules\Redirects::toUtf8("stara;/nova-str\xe1nka;301\n\x8e\x9a\xe8\xf8"), Kaleta\Admin\Modules\Redirects::toUtf8("/a;/b-ž;301")],
    ["stara;/nova-stránka;301\nŽščř", "/a;/b-ž;301"]);
// N36-4: a menu link that looks like a path never leaves the site: "//host" becomes the explicit https address, "/\\host" and
// backslashes are refused
check('3.6 N36-4: menu links – no protocol-relative or backslash paths', [
    array_map(fn (string $u): bool => Kaleta\Core\Menu::isValidUrl($u), ['/kontakt', '/', '//evil.example', '/\\evil.example', '/a\\b', 'https://x.cz/a', 'https://x.cz\\@evil', '#top']),
    array_column(Kaleta\Core\Menu::sanitize([['typ' => 'odkaz', 'text' => 'CDN', 'url' => '//cdn.example/x'], ['typ' => 'odkaz', 'text' => 'Bad', 'url' => '/\\evil.example']]), 'url'),
], [[true, true, false, false, false, true, false, true], ['https://cdn.example/x']]);
check('3.7: a batch call weighs its rows for the hourly change limit, any other call one', [
    Kaleta\Core\Guardrails::weight('save_redirects', ['redirects' => [[], [], []]]), Kaleta\Core\Guardrails::weight('save_collection_items', ['items' => array_fill(0, 200, [])]),
    Kaleta\Core\Guardrails::weight('save_redirects', []), Kaleta\Core\Guardrails::weight('uprav_stranku', ['id' => 1])], [3, 200, 1, 1]);

