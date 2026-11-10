<?php

declare(strict_types=1);

/* ---------- query parameters are English (Kaleta writes only these; the former Czech names still work as aliases) ---------- */
$czechParams = 'nahled_klic|nahled_konec|nahled|stavba|polozka|varianta|vysledek|upravit|uprava|uprav|strana|sablona|cesta|hledat|razeni|soubor|preklad_z|sekce|pohled|komentar|odhlasit|potvrdit|tema|typ|klic|stav|jazyk|cast|umisteni|pole|nova|nepouzite|osoba|sluzba|kdo|kde|kategorie|clanek|heslo|idr|dni|nadrazena|odber|mnozstvi|rezervace|formular|chyba|produkt';
$czechFound = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/system', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $code = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/(?:request|\$r)->(?:get|getInt|has)\([\'"](' . $czechParams . ')[\'"]|[?&](' . $czechParams . ')=/', $code, $m)) {
            $czechFound[] = basename($file->getPathname()) . ': ' . implode(', ', array_filter(array_merge($m[1], $m[2])));
        }
    }
}
check('query parameters: no Czech parameter name in the PHP code or views (Kaleta writes only the English names)', $czechFound, []);
// the same for array keys that become parameters: ->url('action', ['key' => …]) and ->back(message, action, ['key' => …])
$czechKeys = 'polozka|idr|soubor|sekce|uprav|upravit|stav|tema|hledat|umisteni|jazyk|typ|varianta|nova|pohled|kdo|kde|strana|nadrazena|preklad_z|nepouzite|dni|kategorie|osoba|sluzba|razeni|sablona';
$czechKeyFound = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/system', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php' || !str_contains($file->getPathname(), '/Admin') && !str_contains($file->getPathname(), '/views/admin/')) {
        continue;
    }
    foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $n => $line) {
        if ((str_contains($line, '->url(') || str_contains($line, '->back(') || str_contains($line, "'parametry'")) && preg_match("/'(" . $czechKeys . ")'\\s*=>/", $line, $m)) {
            $czechKeyFound[] = basename($file->getPathname()) . ':' . ($n + 1) . ' ' . $m[1];
        }
    }
}
check('query parameters: no Czech key in the parameters of admin links (url(), back(), builder parameters)', $czechKeyFound, []);
// the former names are kept as aliases in Request: links already sent (confirm / unsubscribe, List-Unsubscribe, bookings, shared previews) never break
$legacyQuery = Kaleta\Core\Request::LEGACY_QUERY;
$legacyPairs = [];
$legacyBroken = [];
foreach ($legacyQuery as $new => $olds) {
    foreach ($olds as $old) {
        $legacyPairs[] = $old . '>' . $new;
        $old_ = new Kaleta\Core\Request([$old => '7'], [], []);
        $both = new Kaleta\Core\Request([$old => '7', $new => '9'], [], []);
        if ($old_->get($new) !== '7' || $old_->getInt($new) !== 7 || !$old_->has($new) || $both->get($new) !== '9' || (new Kaleta\Core\Request([], [], []))->has($new)) {
            $legacyBroken[] = $old . '>' . $new;
        }
    }
}
check('query parameters: every former Czech name still resolves to its English one (English wins when both are present)', $legacyBroken, []);
check('query parameters: the alias map lists every former Czech name of the guard above', array_values(array_diff(explode('|', $czechParams), array_map(fn (string $p): string => explode('>', $p)[0], $legacyPairs))), []);
$oldLink = fn (string $old, string $new): string => (new Kaleta\Core\Request([$old => 'v'], [], []))->get($new);
check('query parameters: old confirm, unsubscribe, preview, booking and subscription links read as the English names', [$oldLink('potvrdit', 'confirm'), $oldLink('odhlasit', 'unsubscribe'), $oldLink('nahled_klic', 'preview_key'), $oldLink('rezervace', 'booking'), $oldLink('odber', 'subscription')], array_fill(0, 5, 'v'));

