<?php

declare(strict_types=1);

use Kaleta\Core\Routes;

/* ---------- 3.7 security review (N37-2, N37-5, N37-6, N37-10) ---------- */
// N37-2: one check for every path that creates or renames a page – the English system words, the Czech ones, language codes
check('3.7 N37-2: Pages::slugReserved refuses the system words of both languages and language codes, never a subpage or an ordinary slug', array_map(
    fn (string $s): bool => Kaleta\Admin\Modules\Pages::slugReserved($s, null), ['tasks', 'subscription', 'form', 'consent', 'conversion', 'odber', 'ulohy', 'formular', 'news', 'en', 'o-nas', 'form-2', 'sluzby/form', '']),
    [true, true, true, true, true, true, true, true, true, true, false, false, false, false]);
$pagesSource37 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pages.php') . file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/PageTools.php')
    . file_get_contents(KALETA_SYSTEM . '/src/Core/WebImport.php') . file_get_contents(KALETA_SYSTEM . '/src/Import/Batch.php') . file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Bookings.php');
check('[source-pin] 3.7 N37-2: no page writer checks RESERVED_SLUGS on its own any more (they all ask Pages::slugReserved)', substr_count($pagesSource37, 'in_array($seo, Pages::RESERVED_SLUGS') + substr_count($pagesSource37, 'in_array($url, Pages::RESERVED_SLUGS')
    + substr_count($pagesSource37, 'in_array($a, Pages::RESERVED_SLUGS') + substr_count($pagesSource37, "in_array(\$data['seo_link'], self::RESERVED_SLUGS"), 0);
// N37-5: the installer's cron line comes from the same helper as System status
$installerSource37 = (string) file_get_contents(KALETA_SYSTEM . '/src/Install/Installer.php');
check('[source-pin] 3.7 N37-5: the installer takes the cron path from Routes::publicSystemPath, never a hard-coded /tasks',
    [str_contains($installerSource37, "Routes::publicSystemPath('ulohy', \$db)"), str_contains($installerSource37, "'/tasks?token='"), Routes::publicSystemPath('ulohy', null)], [true, false, 'tasks']);
// N37-6: the English system words never follow url_slash (only a page that holds the word does – that needs the database)
check('3.7 N37-6: the English system words are no page-like URLs, so url_slash never redirects them', [
    array_map(fn (string $p): bool => Routes::pageLike($p), ['/tasks', '/tasks.html', '/subscription/', '/form', '/consent.html', '/conversion', '/ulohy.html', '/formx', '/tasks-2', '/o-nas', '/sluzby/form']),
    Routes::slashRedirect('/tasks', '/tasks.html?token=x', 'bez'), Routes::slashRedirect('/form', '/form/', 'bez'), Routes::slashRedirect('/o-nas', '/o-nas/', 'bez')],
    [[false, false, false, false, false, false, false, true, true, true, true], null, null, '/o-nas']);
// N37-10: an address, a key or a token with a trailing newline never passes ($ without D matches before a final "\n")
$siteImportSlug = new ReflectionMethod(Kaleta\Core\SiteImport::class, 'slug');
check('3.7 N37-10: slug and identifier patterns refuse a trailing newline', [
    preg_match(Kaleta\Builder\CollectionCategories::SLUG_PATTERN, "imp\n"), preg_match(Kaleta\Builder\Collections::ITEM_LINK_PATTERN, "stul\n"), preg_match(Kaleta\Builder\Collections::KEY_PATTERN, "cena\n"),
    preg_match(Kaleta\Builder\Build::CLASS_PATTERN, "karta\n"), preg_match(Kaleta\Builder\Popups::ADDRESS_PATTERN, "akce\n"), preg_match(Kaleta\Core\Facts::KEY_PATTERN, "phone\n"),
    Routes::systemSlugError("blog\n") !== null, $siteImportSlug->invoke(null, "imp\n", 'Import', 160), $siteImportSlug->invoke(null, 'imp', 'Import', 160)],
    [0, 0, 0, 0, 0, 0, true, 'import', 'imp']);
// … and no anchored pattern with a slug or identifier character class anywhere in system/ forgets the D modifier again; the
// exceptions parse multi-line or header text, refuse what matches (D would let a value through), or are not this code's own
$withoutD = [];
$allowedWithoutD = ['system/bootstrap.php:', 'system/src/Core/Facts.php:13', 'system/src/Core/Facts.php:16', 'system/src/Core/Outbound.php:', 'system/src/Core/Assistant.php:', 'system/src/Core/WpTypes.php:',
    'system/src/Core/Html.php:', 'system/src/Core/WebImport.php:', 'system/src/Core/RedirectRules.php:', 'system/src/Front/Cache.php:', 'system/src/Front/Company.php:'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM, FilesystemIterator::SKIP_DOTS)) as $file37) {
    $path37 = $file37->getPathname();
    if (!str_ends_with($path37, '.php') || preg_match('#/(compat|Compat|jazyky)/#', $path37)) {
        continue;
    }
    foreach (token_get_all((string) file_get_contents($path37)) as $t37) {
        if (is_array($t37) && $t37[0] === T_CONSTANT_ENCAPSED_STRING && $t37[1][0] === "'" && preg_match('/^\'([\/#~])\^(.*)\1([a-zA-Z]*)\'\z/s', $t37[1], $m37)
            && str_ends_with($m37[2], '$') && !str_contains($m37[3], 'D') && preg_match('/\[[^\]]*(a-z|a-f|A-Z)/', $m37[2])) {
            $where37 = 'system/' . substr($path37, strlen(KALETA_SYSTEM) + 1) . ':' . $t37[2];
            if (array_filter($allowedWithoutD, fn (string $a): bool => str_starts_with($where37, $a)) === []) {
                $withoutD[] = $where37 . ' ' . $t37[1];
            }
        }
    }
}
check('3.7 N37-10: every anchored slug or identifier pattern in system/ uses the D modifier', $withoutD, []);

