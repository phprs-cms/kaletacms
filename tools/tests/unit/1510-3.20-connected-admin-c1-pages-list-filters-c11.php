<?php

declare(strict_types=1);

/* ---------- 3.20 "Connected admin" – C1 Pages list filters, C11 Edited and Language columns ---------- */
$pages320 = Kaleta\Admin\Modules\Pages::class;
$kernel320 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
check('3.20 (C1) Pages::statusCondition: English tab values, kos kept, everything else is "all pages outside the trash"', [
    $pages320::statusCondition('published'), $pages320::statusCondition('hidden'), $pages320::statusCondition('draft'), $pages320::statusCondition('kos'),
    $pages320::statusCondition(''), $pages320::statusCondition('koncept')],
    ['zobrazit = 1 AND smazano IS NULL', 'zobrazit = 0 AND smazano IS NULL', 'stavba_koncept IS NOT NULL AND smazano IS NULL', 'smazano IS NOT NULL', 'smazano IS NULL', 'smazano IS NULL']);
check('[source-pin] 3.20 (C1): the dashboard tiles count by the same conditions and lead to the matching tab, not the bare list', [
    substr_count($kernel320, "Modules\\Pages::STATUSES['published']), 'admin.php?module=pages&status=published'"),
    substr_count($kernel320, "Modules\\Pages::STATUSES['draft']), 'admin.php?module=pages&status=draft'"),
    preg_match("/'Published pages'[^\n]*'admin\.php\?module=pages'\]/", $kernel320)], [1, 1, 0]);
check('3.20 (C11) Pages::listOrder: last edited first, pages without a date after them in the order from before; the trash by deletion', [
    $pages320::listOrder(false, 'titulek'), $pages320::listOrder(true, 'titulek')], ['zmeneno IS NULL, zmeneno DESC, jazyk, poradi, titulek', 'smazano DESC']);
$edited320 = $pages320::edited(7);
check('3.20 (C11) Pages::edited: the stamp of a person\'s edit – when, and who (0 = nobody → NULL)', [
    array_keys($edited320), $edited320['zmenil'], $pages320::edited(0)['zmenil'], preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $edited320['zmeneno'])], [['zmeneno', 'zmenil'], 7, null, 1]);
$migration107 = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0107-page-last-editor.sql');
check('[source-pin] 3.20 (C11): migration 0107 adds zmenil as the schema does, fills it from the history only where empty; the installer stamps zmeneno and First steps count a person\'s edit', [
    KALETA_DB_VERSION >= 107, str_contains($migration107, 'ADD COLUMN zmenil INT UNSIGNED NULL AFTER zmeneno'), str_contains($migration107, 'WHERE s.zmeneno IS NOT NULL AND s.zmenil IS NULL'),
    preg_match('/zmeneno  DATETIME NULL,\n    zmenil   INT UNSIGNED NULL,/', (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql')),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Install/Installer.php'), "'zmeneno' => \$installed"),
    str_contains($kernel320, 'AND zobrazit = 1 AND zmenil IS NOT NULL LIMIT 1'), str_contains($kernel320, 'AND zobrazit = 1 AND zmeneno IS NOT NULL LIMIT 1')], [true, true, true, 1, 2, true, false]);
// every place that stamps zmeneno on a page by a person's action stamps who as well – no bare date left in these files
check('[source-pin] 3.20 (C11): a page edit stamps who along with when everywhere (the form, bulk, text, versions, import, copy, publish, MCP update_page, presets, the collection listing page)', [
    preg_match_all("/'zmeneno' => date\('Y-m-d H:i:s'\)(?!, 'zmenil')/", (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pages.php')), // only the helper Pages::edited itself
    preg_match_all("/update\('stranky', (?![^\n]*Pages::edited\()/", (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Collections.php')), // N320-4: the listing page is published through Pages::edited
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Collections.php'), "'zverejnit_od' => null] + Pages::edited(\$auth->id())"),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Builder/Publisher.php'), "update('stranky', ['stavba' => \$new, 'stavba_koncept' => null] + \\Kaleta\\Admin\\Modules\\Pages::edited("),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), '$data += Pages::edited($this->app->auth()->id());'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Builder/Presets.php'), "+ \\Kaleta\\Admin\\Modules\\Pages::edited(")], [0, 0, 1, 1, 1, 1]);
$pagesList320 = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/pages/list.php');
check('[source-pin] 3.20 (C1, C11) pages list: the tabs carry the count and the search keeps the tab; Edited always, Language only with language versions', [
    str_contains($pagesList320, "['published' => 'Published', 'hidden' => 'Hidden', 'draft' => 'Unpublished changes', 'layout' => 'Layout not rebuilt' /* 3.33 */, 'kos' => 'Trash']"),
    str_contains($pagesList320, '<input type="hidden" name="status" value="<?= e($status) ?>">'), substr_count($pagesList320, "e(t('Edited'))"),
    str_contains($pagesList320, "\$siteLanguages !== [] ? '<th scope=\"col\">' . e(t('Language')) . '</th>' : ''"), str_contains($pagesList320, "'heading' => t('No page matches this filter.')")],
    [true, true, 1, true, true]);

// ---- 3.20 "Connected admin", part B (CPO audit C2, C5, C16, C19, C20) ----
// C2: the Form element an enquiry came from – by its stored id, else by the form name, else the only form; nested forms count too
$c2Form = fn (string $id, string $name): array => ['id' => $id, 'typ' => 'formular', 'obsah' => ['nazev' => $name]];
$c2Build = ['v' => 1, 'deti' => [
    ['id' => 's1', 'typ' => 'sekce', 'deti' => [['id' => 'k1', 'typ' => 'kontejner', 'deti' => [$c2Form('f1', 'Enquiry')]]]],
    ['id' => 's2', 'typ' => 'sekce', 'deti' => [$c2Form('f2', 'Newsletter question')]],
]];
check('3.20 (C2): EnquiryOrigin::findForm – the stored id wins, a replaced element is found by its name, a lone form stands in, otherwise nothing', [
    Kaleta\Admin\EnquiryOrigin::findForm($c2Build, 'f2', 'Enquiry'),             // the id is still there – even though the name points elsewhere
    Kaleta\Admin\EnquiryOrigin::findForm($c2Build, 'gone', 'Newsletter question'), // the element was replaced: by name
    Kaleta\Admin\EnquiryOrigin::findForm($c2Build, '', 'Enquiry'),                // an older row without the id
    Kaleta\Admin\EnquiryOrigin::findForm($c2Build, 'gone', 'Renamed'),           // two forms, neither fits
    Kaleta\Admin\EnquiryOrigin::findForm(['v' => 1, 'deti' => [$c2Build['deti'][0]]], 'gone', 'Renamed'), // the only form of the page
    Kaleta\Admin\EnquiryOrigin::findForm(['v' => 1, 'deti' => [['id' => 't', 'typ' => 'text', 'obsah' => ['nazev' => 'Enquiry']]]], '', 'Enquiry'), // not a form
    Kaleta\Admin\EnquiryOrigin::findForm(null, 'f1', 'Enquiry'),
], ['f2', 'f2', 'f1', null, 'f1', null, null]);
$c2Detail = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/enquiries/detail.php');
$c2Builder = (string) file_get_contents(dirname(KALETA_SYSTEM) . '/image/stavitel.js');
check('[source-pin] 3.20 (C2): the enquiry detail links the builder with the form selected, and the builder honours #element=<id> only for an element it has', [
    str_contains($c2Detail, '$origin[\'builder\']') && str_contains($c2Detail, "t('Open in the builder')") && str_contains($c2Detail, "'Page settings'"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/EnquiryOrigin.php'), "'#element=' . \$id"),
    preg_match('/element=\(\[A-Za-z0-9_-\]\+\)\/\.exec\(location\.hash/', $c2Builder) === 1 && str_contains($c2Builder, 'if (wanted && find(wanted[1]))'),
    str_contains($c2Builder, 'markInPreview(scrollToSelected)'),
    // Statistics: the "edit" link next to the public page, only through the same resolver (pages and collection items the user may edit)
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Stats.php'), 'EnquiryOrigin::editLink('),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/views/admin/stats/list.php'), '\'Page\' => $pageCell,'),
], [true, true, true, true, true, 2]);
// C16, 3.23.1 (N-C10 and the owner): the testimonial request is one folded, secondary step after the main actions – the same for
// every role, only for a resolved enquiry of a customer (or one with a request already), the References collection made with the first answer
$c16Testimonials = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Testimonials.php');
check('[source-pin] 3.20 (C16), 3.23.1: the enquiry detail asks for a testimonial in a folded block after the actions, only when it fits; the collection comes with the first answer', [
    substr_count($c16Testimonials, 'WHERE preset = ?'), str_contains($c16Testimonials, ': (int) Presets::create($app, self::PRESET)'),
    Kaleta\Core\Testimonials::NOT_FOR_KINDS, array_diff(Kaleta\Core\Testimonials::NOT_FOR_KINDS, array_keys(Kaleta\Core\Triage::CATEGORIES)),
    str_contains($c2Detail, '$testimonialFits = $p[\'email\'] !== \'\' && !in_array((string) $p[\'kategorie\'], Kaleta\\Core\\Testimonials::NOT_FOR_KINDS, true) && ((int) $p[\'stav\'] === 2 || $testimonials !== []);'),
    str_contains($c2Detail, '<details class="reference-zadost" data-testimonial<?= $testimonials !== [] ? \' open\' : \'\' ?>>'),
    strpos($c2Detail, "e(t('Smazat'))") < strpos($c2Detail, 'data-testimonial'),
    str_contains($c2Detail, 'name="preset" value="references"') || str_contains($c2Detail, 'data-references-missing') || str_contains($c2Detail, '$referencesCollection'),
    Kaleta\Core\Language::runWith('cs', fn (): string => t('Ask the customer for a testimonial'), 'admin-'),
], [1, true, ['spam', 'job', 'supplier'], [], true, true, true, false, 'Požádat zákazníka o referenci']);
// C5: the items screen knows the listing page a preset made; C19: the news form always names the category; C20: Broken links under Site care
$c5Collections = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Collections.php');
$c5Items = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/collections/items.php');
check('[source-pin] 3.20 (C5): the collection items screen carries the listing page – its state, builder, Publish (publishers only) and the menu – and the empty state no longer sends to a second list', [
    // N320-2: only a page whose build lists the collection, the page at the collection's address first – never a page that merely has the slug
    str_contains($c5Collections, 'public static function listingPage(') && !str_contains($c5Collections, "FROM {stranky} WHERE seo_link = ? AND jazyk = '' AND smazano IS NULL\", [(string) \$k['seo_link']])")
        && str_contains($c5Collections, "WHERE smazano IS NULL AND (stavba LIKE ? OR stavba_koncept LIKE ?) ORDER BY seo_link = ? DESC, jazyk = '' DESC, ids LIMIT 1\"")
        && str_contains($c5Collections, "[\$pattern, \$pattern, (string) \$k['seo_link']]);")
        && str_contains($c5Collections, '$pattern = \'%"kolekce":\' . json_encode(') && str_contains($c5Collections, "ChangeLog::write(\$this->app, 'pages', 'shown', "),
    str_contains($c5Collections, 'protected function actionPublishPage()') && str_contains($c5Collections, "\$auth->hasModule('pages') || !\$auth->canPublish()"),
    str_contains($c5Items, "t('Listing page:')") && str_contains($c5Items, "url('publish_page')") && str_contains($c5Items, "t('Add to the menu')") && str_contains($c5Items, "t('Edit in the builder')"),
    str_contains($c5Items, '$listingPage !== null ? t(\'Add the first item – the page %s lists the items; publish it when they are ready.\''),
    str_contains($c5Collections, 'from the “Listing page” line below.'),
], [true, true, true, true, true]);
$c19Form = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/news/form.php');
check('[source-pin] 3.20 (C19): the news form shows the category also when there is only one (read-only, with Manage categories), the hidden field stays', [
    preg_match('/count\(\$category\) < 2\).*?name="tema".*?<span class="popisek"><\?= e\(t\(\'Category\'\)\) \?><\/span>/s', $c19Form) === 1,
    substr_count($c19Form, '$manageCategories'), str_contains($c19Form, "t('Manage categories')") && str_contains($c19Form, "hasModule('categories')"),
], [true, 3, true]);
check('[source-pin] 3.20 (C20): Broken links is a Site care entry that opens the News screen with the same feature and permission; News keeps its shortcut', [
    Kaleta\Admin\Modules\BrokenLinks::GROUP, Kaleta\Admin\Modules\BrokenLinks::EXTENSION, Kaleta\Admin\Modules\BrokenLinks::SHARES_PERMISSION_OF,
    Kaleta\Admin\Modules\BrokenLinks::ADMIN_ONLY, Kaleta\Admin\Modules\BrokenLinks::NAME,
    array_search(Kaleta\Admin\Modules\BrokenLinks::class, Kaleta\Admin\Kernel::MODULES, true) - array_search(Kaleta\Admin\Modules\Redirects::class, Kaleta\Admin\Kernel::MODULES, true),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/BrokenLinks.php'), "(new News(\$this->kernel))->handle('links')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/news/list.php'), "url('links')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/icons.php'), "'odkazy' => '<path"),
], ['Site care', 'novinky', 'news', false, 'Broken links', 1, true, true, true]);

