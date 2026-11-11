<?php

declare(strict_types=1);

/* ---------- 3.6: WordPress menus, authors and page builders (tools/fixtures/wordpress-migration.xml) ---------- */
$migPath = KALETA_ROOT . '/tools/fixtures/wordpress-migration.xml';
$migHeader = (new Kaleta\Core\WpFile($migPath))->header();
check('3.6 WpFile: nav_menu terms, author e-mails (lowercase, only to find a user) and term numbers of categories and tags',
    [$migHeader['menu'], $migHeader['emaily'], $migHeader['terminy']],
    [['hlavni-menu' => 'Hlavní menu', 'paticka' => 'Patička', 'socialni-site' => 'Sociální sítě'], ['jnovak' => 'jan.novak@stavby-novak.example', 'pkralova' => 'petra@stavby-novak.example'],
        [2 => ['rubrika', 'aktuality-stavby'], 3 => ['stitek', 'rekonstrukce']]]);
$migState = Kaleta\Core\WpImport::newState('wordpress-migration.xml');
Kaleta\Core\WpImport::analyze($migState, 30, $migPath);
check('3.6 WpImport preview: menus with their items, posts per author, a Breakdance layout, nothing unknown',
    [$migState['prehled']['menu'], count($migState['menu']), $migState['prehled']['prispevky_autoru'], $migState['prehled']['stavitele'], $migState['prehled']['jine']],
    [['hlavni-menu' => ['nazev' => 'Hlavní menu', 'polozky' => 8], 'paticka' => ['nazev' => 'Patička', 'polozky' => 2], 'socialni-site' => ['nazev' => 'Sociální sítě', 'polozky' => 1]],
        11, ['jnovak' => 1, 'pkralova' => 1], ['Breakdance' => 1], []]);
// 3.12 (UX310-16): the channel's <language> for the preview's language check, and the pages a page builder laid out by name
check('3.12 WpFile/WpImport preview: the export language and the page laid out with Breakdance by name',
    [$migHeader['jazyk'], $migState['web']['jazyk'], $migState['prehled']['stavitele_nazvy'], (new Kaleta\Core\WpFile(KALETA_ROOT . '/tools/fixtures/wordpress-wpml.xml'))->header()['jazyk']],
    ['cs', 'cs', ['Breakdance' => ['O firmě Novák']], 'cs-CZ']);
check('3.6 WpImport preview: a menu item keeps where it leads, its parent and order – not the builder data', [$migState['menu'][3], str_contains((string) json_encode($migState), 'tree_json_string')],
    [['id' => 43, 'menu' => 'hlavni-menu', 'nadrazena' => 42, 'poradi' => 4, 'druh' => 'custom', 'objekt' => 'custom', 'objekt_id' => 43,
        'url' => 'https://www.stavby-novak.example/sluzby-stavby/rekonstrukce-bytu/#postup', 'text' => 'Postup', 'nove_okno' => false], false]);
check('3.6 WpImport::menuLocations: by name, the largest unnamed menu is the main menu, the options win, one menu per place', [
    Kaleta\Core\WpImport::menuLocations(['hlavni-menu' => 'Hlavní menu', 'paticka' => 'Patička', 'socialni-site' => 'Sociální sítě'], ['hlavni-menu' => 8, 'paticka' => 2, 'socialni-site' => 1], []),
    Kaleta\Core\WpImport::menuLocations(['menu-1' => 'Menu 1', 'menu-2' => 'Menu 2'], ['menu-1' => 3, 'menu-2' => 9], []),
    Kaleta\Core\WpImport::menuLocations(['menu-1' => 'Menu 1', 'menu-2' => 'Menu 2', 'footer' => 'Footer'], ['menu-1' => 3, 'menu-2' => 9, 'footer' => 2], ['menu-2' => '', 'menu-1' => 'paticka', 'x' => 'nikam']),
], [['hlavni-menu' => 'hlavni', 'paticka' => 'paticka', 'socialni-site' => ''], ['menu-1' => '', 'menu-2' => 'hlavni'], ['menu-1' => 'paticka', 'menu-2' => '', 'footer' => '']]);
$migWarnings = [];
$migTree = Kaleta\Core\WpImport::fitMenuDepth([['typ' => 'odkaz', 'text' => 'A', 'url' => '/', 'deti' => [
    ['typ' => 'stranka', 'ids' => 1, 'text' => '', 'deti' => [['typ' => 'odkaz', 'text' => 'C', 'url' => '/c', 'deti' => [['typ' => 'odkaz', 'text' => 'D', 'url' => '/d']]]]],
    ['typ' => 'skupina', 'text' => 'G', 'deti' => [['typ' => 'odkaz', 'text' => 'E', 'url' => '/e']]]]]], $migWarnings);
check('3.6 WpImport::fitMenuDepth: deeper WordPress levels move up into the submenu, a group keeps its column, Menu::sanitize keeps every item', [
    array_column($migTree[0]['deti'], 'text'), array_column($migTree[0]['deti'][3]['deti'], 'text'), count(Kaleta\Core\Menu::flatten(Kaleta\Core\Menu::sanitize($migTree))) === count(Kaleta\Core\Menu::flatten($migTree)), count($migWarnings)],
    [['', 'C', 'D', 'G'], ['E'], true, 1]);
$migSkipped = array_column(Kaleta\Core\WpImport::summary($migState)['skipped'], 'count', 'what');
check('3.6 WpImport::summary: the page builder layout, author names and comments are reported as skipped with a reason', [
    $migSkipped['layout:Breakdance'] ?? null, $migSkipped['author_names'] ?? null, array_key_exists('comments', $migSkipped),
    array_keys(Kaleta\Core\WpImport::summary($migState))], [1, 2, true, ['found', 'result', 'skipped', 'redirects', 'menus', 'authors', 'images', 'starter_pages', 'addresses_changed']]); // 3.33: starter_pages and addresses_changed added
check('3.6 WpImport::options: unknown values fall back, menu places only main/footer/skip, skryte and obrazky are booleans', (function (): array {
    $o = Kaleta\Core\WpImport::DEFAULT_OPTIONS;

    return [$o['skryte'], $o['obrazky'], $o['menu'], isset(Kaleta\Core\Menu::LOCATIONS['hlavni'], Kaleta\Core\Menu::LOCATIONS['paticka'])];
})(), [false, false, true, true]);
check('3.6 import_wordpress: a write tool (not for a drafts-only connection) that reaches outside the site, English only',
    [Kaleta\Mcp\Catalog::access('import_wordpress'), Kaleta\Mcp\Catalog::allows('drafts', 'import_wordpress'), Kaleta\Mcp\Tools::annotations('import_wordpress'), Kaleta\Mcp\Translator::czech('import_wordpress')],
    ['write', false, ['title' => 'Import a WordPress export', 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true], 'import_wordpress']);
check('WpImport náhled: adresy příloh pro galerie a hlavní obrázky', $wpState['prilohy'][202] ?? '', 'https://www.podhorsky-zpravodaj.example/wp-content/uploads/2026/05/pohled.jpg');

