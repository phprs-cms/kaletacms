<?php

declare(strict_types=1);

use Kaleta\Core\Migration;
use Kaleta\Core\Files;

/* ---------- 3.18.2 pre-release audit (N3182) ---------- */
// N3182-3: a /media/ path longer than the platform's maximum made is_file() warn under open_basedir, and the error handler
// (Core\App) turned the warning into a crash of Site audit; Files::isFile caps the length – run with open_basedir set
if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
    $longScript = (string) tempnam(sys_get_temp_dir(), 'kaleta-n3182');
    file_put_contents($longScript, '<?php require ' . var_export(KALETA_SYSTEM . '/bootstrap.php', true) . ';'
        . ' set_error_handler(static function (int $no, string $message): never { throw new ErrorException($message, 0, $no); });'
        . ' $name = "media/" . str_repeat("a", 5000) . ".svg"; $plain = "no warning";'
        . ' try { is_file(KALETA_ROOT . "/" . $name); } catch (ErrorException $e) { $plain = "warns"; }'
        . ' try { $r = [Kaleta\Core\Files::isFile(KALETA_ROOT . "/" . $name), Kaleta\Core\Menu::isMediaIcon($name), Kaleta\Core\Files::isFile(KALETA_ROOT . "/" . str_repeat("b/", 600) . "x"),'
        . ' Kaleta\Core\Files::isFile(KALETA_SYSTEM . "/bootstrap.php"), Kaleta\Core\Files::isFile(KALETA_SYSTEM . "/bootstrap.php" . chr(0))]; } catch (Throwable $e) { $r = get_class($e) . ": " . $e->getMessage(); }'
        . ' echo json_encode([$plain, $r]);');
    $longRun = shell_exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('open_basedir=' . KALETA_ROOT . PATH_SEPARATOR . sys_get_temp_dir()) . ' ' . escapeshellarg($longScript) . ' 2>&1');
    unlink($longScript);
    check('3.18.2 N3182-3: a 5,000-character /media/ path is no file and no crash under open_basedir (Files::isFile, Menu::isMediaIcon); a real file still is one', json_decode((string) $longRun, true), ['warns', [false, false, false, true, false]]);
}
check('[source-pin] 3.18.2 N3182-3: Audit and the menu ask the disk about a path from content only through Files::isFile', [
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Audit.php'), 'is_file(KALETA_ROOT . $path)'), substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Menu.php'), ' is_file('),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Audit.php'), 'Files::isFile('), substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Menu.php'), 'Files::isFile(')], [0, 0, 1, 1]);

// N3182-2: the clipboard envelope this site packs is signed; the signature survives the browser's JSON (numeric keys
// reordered, 1.0 written as 1) and breaks on any change; code elements are taken out of an unsigned one
$clipKey = str_repeat('k', 64);
$clipEnvelope = ['kaleta' => 'elements', 'v' => 1, 'site' => 'https://web.example', 'elements' => [['typ' => 'sekce', 'styl' => ['zaklad' => ['b' => 1.0, '10' => 'x', '2' => 'y']], 'deti' => [['typ' => 'html', 'obsah' => ['kod' => '<script>x()</script>']], ['typ' => 'nadpis', 'obsah' => ['text' => 'Ahoj „svět“ / ok']]]]], 'classes' => [], 'components' => []];
$clipEnvelope['sig'] = Kaleta\Builder\ElementClipboard::signature($clipEnvelope, $clipKey);
$clipThroughBrowser = json_decode((string) json_encode(json_decode((string) json_encode($clipEnvelope), false)), true); // a JS-like round trip: objects, then back
$clipThroughBrowser['elements'][0]['styl']['zaklad'] = ['2' => 'y', '10' => 'x', 'b' => 1];
$clipTampered = $clipThroughBrowser;
$clipTampered['elements'][0]['deti'][0]['obsah']['kod'] = '<script>evil()</script>';
$clipClaimed = $clipThroughBrowser;
unset($clipClaimed['sig']);
check('3.18.2 N3182-2: the clipboard signature – kept through the browser, broken by a change, missing or from another key', [
    Kaleta\Builder\ElementClipboard::isSigned($clipEnvelope, $clipKey), Kaleta\Builder\ElementClipboard::isSigned($clipThroughBrowser, $clipKey), Kaleta\Builder\ElementClipboard::isSigned($clipTampered, $clipKey),
    Kaleta\Builder\ElementClipboard::isSigned($clipClaimed, $clipKey), Kaleta\Builder\ElementClipboard::isSigned($clipEnvelope, str_repeat('j', 64)), Kaleta\Builder\ElementClipboard::isSigned('text', $clipKey),
    Kaleta\Builder\ElementClipboard::isSigned(['sig' => ['x']] + $clipEnvelope, $clipKey)],
    [true, true, false, false, false, false, false]);
$clipDropped = 0;
$clipWithout = Kaleta\Builder\ElementClipboard::withoutCode([['typ' => 'html', 'obsah' => ['kod' => 'a']], ['typ' => 'sekce', 'deti' => [['typ' => 'html'], ['typ' => 'kontejner', 'deti' => [['typ' => 'html'], ['typ' => 'text']]]]], ['typ' => 'nadpis']], $clipDropped);
check('3.18.2 N3182-2: code elements leave an unsigned envelope, also from inside containers', [$clipDropped, array_column($clipWithout, 'typ'), array_column($clipWithout[0]['deti'], 'typ'), array_column($clipWithout[0]['deti'][0]['deti'], 'typ')],
    [3, ['sekce', 'nadpis'], ['kontejner'], ['text']]);

// N3182-1: the editor's paste cleanup (image/editor.js) – iframes emptied and checked against the embed list of image/web.js,
// the clean-up on the nodes, no regular expression on the serialized markup
$editorJs = (string) file_get_contents(KALETA_ROOT . '/image/editor.js');
$webJs = (string) file_get_contents(KALETA_ROOT . '/image/web.js');
preg_match('#var EMBED = (/\^https:.*?/);#', $editorJs, $editorEmbed);
preg_match('#if \(!(/\^https:.*?/)\.test\(address\)\)#', $webJs, $webEmbed);
check('[source-pin] 3.18.2 N3182-1: the editor allows a pasted frame only from the services the front end embeds (the same list as data-vlozit)', [($editorEmbed[1] ?? 'a') === ($webEmbed[1] ?? 'b'),
    str_contains($editorJs, "n.textContent = '';"), preg_match('#innerHTML\.replace\(#', $editorJs), preg_match('#/\^\\\\s\*javascript:/#', $editorJs)], [true, true, 0, 0]);

// 3.18.3 (owner's request): a collection's own item in the admin menu – the column for existing sites (on by default) and
// in schema.sql for new ones, the cap of the menu, the icon, the optional MCP parameter (boolean) and the export
$migration3183 = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0103-collection-admin-menu.sql');
$mcp3183 = array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), null, 'name');
$icons3183 = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/icons.php');
check('[source-pin] 3.18.3: migration 0103 adds ka_kolekce.admin_menu on by default, schema.sql has it, KALETA_DB_VERSION is at least 103', [
    str_contains($migration3183, 'ALTER TABLE ka_kolekce ADD COLUMN admin_menu TINYINT(1) NOT NULL DEFAULT 1'), (bool) preg_match('/^\s+admin_menu\s+TINYINT\(1\) NOT NULL DEFAULT 1,/m', (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql')),
    KALETA_DB_VERSION >= 103, Migration::latest() === KALETA_DB_VERSION], [true, true, true, true]);
check('[source-pin] 3.18.3, 3.25: at most 5 collections in the menu (then More…), with the list icon; create_collection and update_collection take an optional boolean admin_menu', [
    Kaleta\Admin\Modules\Collections::MENU_LIMIT, str_contains($icons3183, "'polozky' =>"),
    $mcp3183['create_collection']['inputSchema']['properties']['admin_menu']['type'] ?? null, in_array('admin_menu', $mcp3183['create_collection']['inputSchema']['required'], true),
    $mcp3183['update_collection']['inputSchema']['properties']['admin_menu']['type'] ?? null, in_array('admin_menu', $mcp3183['update_collection']['inputSchema']['required'], true),
    Kaleta\Mcp\Translator::arguments('update_collection', ['admin_menu' => false])],
    [5, true, 'boolean', false, 'boolean', false, ['admin_menu' => false]]);
check('[source-pin] 3.18.3: the site export carries admin_menu and the import keeps it (on when an older export has none)', [
    (bool) preg_match('/detail, admin_menu, (icon, )?hidden_redirect/', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteExport.php')),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteImport.php'), "'admin_menu' => (int) !empty(\$r['admin_menu'] ?? 1)")], [true, true]);

// 3.19 (owner, 22 October 2026): an icon per collection – a key of the built-in set (Builder\Icons::SET) or none; the column
// for existing sites (0104) and in schema.sql; optional in create/update_collection, English names through Mcp\Vocabulary
check('3.19: Collections::icon keeps only a key of the built-in icon set', array_map(fn (mixed $v): ?string => Kaleta\Builder\Collections::icon($v),
    ['dum', 'auto', 'home', '', null, '<svg>', 'dum ', ['dum'], 5, 'polozky']), ['dum', 'auto', null, null, null, null, null, null, null, null]);
$migration319icon = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0104-collection-icon.sql');
check('[source-pin] 3.19: migration 0104 adds ka_kolekce.icon (nullable, English name), schema.sql has it after admin_menu', [
    str_contains($migration319icon, 'ALTER TABLE ka_kolekce ADD COLUMN icon VARCHAR(40) NULL AFTER admin_menu;'),
    (bool) preg_match('/^\s+admin_menu\s+TINYINT\(1\) NOT NULL DEFAULT 1,[^\n]*\n\s+icon\s+VARCHAR\(40\)\s+NULL,/m', (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'))], [true, true]);
$mcp319 = array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), null, 'name');
check('3.19: create_collection and update_collection take an optional string icon', [
    $mcp319['create_collection']['inputSchema']['properties']['icon']['type'] ?? null, in_array('icon', $mcp319['create_collection']['inputSchema']['required'], true),
    $mcp319['update_collection']['inputSchema']['properties']['icon']['type'] ?? null, in_array('icon', $mcp319['update_collection']['inputSchema']['required'], true)],
    ['string', false, 'string', false]);
check('3.19: every built-in icon has an English MCP name, and the names are unique', [array_values(array_diff(array_keys(Kaleta\Builder\Icons::SET), array_keys(Kaleta\Mcp\Vocabulary::VALUES['ikona']))),
    count(array_unique(Kaleta\Mcp\Vocabulary::VALUES['ikona'])) === count(Kaleta\Mcp\Vocabulary::VALUES['ikona'])], [[], true]);
$iconIn319 = new ReflectionMethod(Kaleta\Mcp\Tools::class, 'collectionIconIn');
$iconOut319 = new ReflectionMethod(Kaleta\Mcp\Tools::class, 'collectionIconOut');
$iconRefused319 = '';
try {
    $iconIn319->invoke(null, 'castle');
} catch (InvalidArgumentException $e) {
    $iconRefused319 = $e->getMessage();
}
check('3.19 MCP: an icon by its English name or stored key, "" or null = none, an unknown one refused with the list; the output is English',
    [$iconIn319->invoke(null, 'home'), $iconIn319->invoke(null, 'dum'), $iconIn319->invoke(null, ''), $iconIn319->invoke(null, null), str_contains($iconRefused319, 'home, shield'),
        $iconOut319->invoke(null, 'auto'), $iconOut319->invoke(null, 'castle'), $iconOut319->invoke(null, null)],
    ['dum', 'dum', null, null, true, 'car', null, null]);
check('[source-pin] 3.19: the export carries the icon and the import keeps only a key of the set', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteExport.php'), 'admin_menu, icon, hidden_redirect'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteImport.php'), "'icon' => \\Kaleta\\Builder\\Collections::icon(\$r['icon'] ?? null)")], [true, true]);

// 3.19 (data model §3 step 2, §1.3): addresses per language on every site, tags per language, redirects by the exact address
$files319 = array_filter(Migration::files(), fn (int $n): bool => $n >= 104 && $n <= 106, ARRAY_FILTER_USE_KEY); // the migrations of 3.19
check('3.19: the data migration 0106 is the highest migration of its release (the update request of 3.18.3 runs .sql files by number), listed in Migration::DATA, KALETA_DB_VERSION = latest',
    [array_map('basename', $files319), str_ends_with((string) end($files319), '.php'), in_array('0106-addresses-per-language', Migration::DATA, true), Kaleta\Core\Slug::MIGRATION, KALETA_DB_VERSION === Migration::latest()],
    [[104 => '0104-collection-icon.sql', 105 => '0105-slug-lookup-keys.sql', 106 => '0106-addresses-per-language.php'], true, true, '0106-addresses-per-language', true]);
$migration319 = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0106-addresses-per-language.php');
preg_match_all('/Kaleta\\\\([A-Za-z]+\\\\[A-Za-z]+)/', $migration319, $classes319);
check('3.19: migration 0106 is self-contained – besides Db and Settings only Core\\RedirectSources (unchanged since 3.14), no other class of the code', array_values(array_unique($classes319[1])), ['Core\\Db', 'Core\\Settings', 'Core\\RedirectSources']);
$installer319 = (string) file_get_contents(KALETA_SYSTEM . '/src/Install/Installer.php');
check('[source-pin] 3.19: both installation paths write slugs_per_language = 1 (schema.sql has no global keys)', substr_count($installer319, "'slugs_per_language' => '1'"), 2);
check('3.19 Slug::failure reads the reason the migration stored; anything else is no failure', [
    Kaleta\Core\Slug::failure($reportSettings(['slugs_switch_failed' => '{"reason":"shared","table":"kategorie","slugs":["/novinky/kategorie/x"]}']))['slugs'] ?? null,
    Kaleta\Core\Slug::failure($reportSettings(['slugs_switch_failed' => 'nonsense'])), Kaleta\Core\Slug::failure($reportSettings([]))], [['/novinky/kategorie/x'], null, null]);
check('3.19 Slug::failureText says what is wrong and what to do', Kaleta\Core\Language::runWith('en', fn (): array => [
    str_contains(Kaleta\Core\Slug::failureText(['reason' => 'shared', 'table' => 'kategorie', 'slugs' => ['/novinky/kategorie/x', '/novinky/kategorie/y']]), '/novinky/kategorie/x, /novinky/kategorie/y'),
    str_contains(Kaleta\Core\Slug::failureText(['reason' => 'database', 'table' => 'stranky', 'error' => 'ALTER command denied']), 'The database said: ALTER command denied'),
    str_contains(Kaleta\Core\Slug::failureText(['reason' => 'database', 'table' => '']), 'The database said'),
    str_contains(Kaleta\Core\Slug::failureText(['reason' => 'shared', 'table' => 'x']), 'Settings → Languages')], 'admin-'), [true, true, false, true]); // 3.23 (C12): the switch is on the Languages tab
// every lookup of a redirect for a visited or linked address compares source_norm – never the Czech collation of z_adresy
$byCollation319 = [];
foreach (['Front/Kernel.php', 'Core/Audit.php', 'Core/Links.php', 'Core/MigrationReport.php'] as $file319) {
    foreach (file(KALETA_SYSTEM . '/src/' . $file319) ?: [] as $n => $line) {
        if (preg_match('/\{(presmerovani|redirects)\}[^\']*WHERE z_adresy (=|IN)/', $line) === 1) {
            $byCollation319[] = $file319 . ':' . ($n + 1);
        }
    }
}
check('3.19: redirects are looked up by source_norm in the router, the audit, the broken-link check and the migration report', $byCollation319, []);
$writers319 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Redirects.php') . (string) file_get_contents(KALETA_SYSTEM . '/src/Core/RedirectRules.php');
check('[source-pin] 3.19: every writer of a redirect fills its normal form (Redirects::add, the admin form, a pattern rule, the import)', [
    substr_count($writers319, 'INSERT INTO {presmerovani} (z_adresy, source_norm,'), str_contains($writers319, "'source_norm' => null"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteImport.php'), "'source_norm' => RedirectSources::normalize(\$from)")], [2, false, true]);
check('[source-pin] 3.19: System status has the rows Addresses in every language and Old addresses of redirects; the events are documented', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php'), "t('Addresses in every language')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php'), "t('Old addresses of redirects')"),
    array_values(array_diff(['slugs.per_language', 'slugs.per_language_failed', 'tags.split', 'redirect.collisions'], array_keys(Kaleta\Core\Events::TYPES)))], [true, true, []]);

// 3.19 (N3182-7, the owner's decision of 22 October 2026): the privacy template says that a store locator loading its map at
// once sends visitors' IP addresses to OpenStreetMap before consent – always in the template made at installation, otherwise
// when a published build uses it; in every visitor language the template is translated into
$privacySettings319 = $reportSettings(['extensions' => 'poptavky']);
check('3.19 (N3182-7): the privacy template mentions OpenStreetMap at installation, and later only when the map loads at once', [
    str_contains(Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Builder\Library::privacyPolicyText()), 'OpenStreetMap (OpenStreetMap Foundation) as soon as the page opens'),
    str_contains(Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Builder\Library::privacyPolicyText()), 'spojí s OpenStreetMap'),
    str_contains(Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Builder\Library::privacyPolicyText($privacySettings319)), 'OpenStreetMap'),
    str_contains(Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Builder\Library::privacyPolicyText($privacySettings319, true)), 'before you give your consent in the cookie bar')],
    [true, true, false, true]);
$privacyLanguages319 = [];
foreach (glob(KALETA_SYSTEM . '/jazyky/*.php') ?: [] as $file319) {
    $dictionary319 = require $file319;
    if (is_array($dictionary319) && isset($dictionary319['Maps load from the map provider only after you click them.']) && !isset($dictionary319[Kaleta\Core\Privacy::MAP_AT_ONCE_TEXT])) {
        $privacyLanguages319[] = basename($file319);
    }
}
check('3.19 (N3182-7): every visitor language with the privacy template translates the OpenStreetMap paragraph', $privacyLanguages319, []);
check('3.19 (N3182-7): the informal German overlay has its own du-form of the paragraph', str_contains((string) ((require KALETA_SYSTEM . '/jazyky/de-du.php')[Kaleta\Core\Privacy::MAP_AT_ONCE_TEXT] ?? ''), 'dein Browser'), true);

// 3.19: a translation of a text with %s or %d goes through sprintf – a lone % in it ("kódováním % nebo") throws a ValueError
// and the screen that shows it breaks (System status did, in the first run of the 3.19 tests)
$strayPercent319 = [];
foreach (glob(KALETA_SYSTEM . '/jazyky/*.php') ?: [] as $file319) {
    foreach ((array) require $file319 as $key319 => $value319) {
        if (preg_match('/%(?:\d+\$)?[sd]/', (string) $key319) === 1 && str_contains((string) preg_replace('/%(?:%|(?:\d+\$)?[sd])/', '', (string) $value319), '%')) {
            $strayPercent319[] = basename($file319) . ': ' . mb_substr((string) $key319, 0, 60);
        }
    }
}
check('3.19: no translation of a text with placeholders has a lone % (sprintf would throw)', array_slice($strayPercent319, 0, 5), []);

// 3.19: the whistleblowing channel forgets a sent report's row (stamped with its day) when the day is over, never "24 hours
// back" – on the day the clocks go back that is already today during its last hour, and every new row of the day was
// deleted as it was written (the daily limit let the sixth report through; tools/test.sh failed so at 23:xx on 25 Oct 2026)
$zone319 = date_default_timezone_get();
date_default_timezone_set('Europe/Prague');
check('3.19: the whistleblowing rows are forgotten by the day, also in the last hour of the 25-hour day of the clock change', [
    Kaleta\Core\Whistleblowing::forgetBefore((int) strtotime('2026-10-25 23:30:00')), Kaleta\Core\Whistleblowing::forgetBefore((int) strtotime('2026-10-26 10:00:00')),
    Kaleta\Core\Whistleblowing::forgetBefore((int) strtotime('2026-03-29 23:30:00'))[1]],
    [['2026-10-25 00:30:00', '2026-10-25 00:00:00'], ['2026-10-25 10:00:00', '2026-10-26 00:00:00'], '2026-03-29 00:00:00']);
date_default_timezone_set($zone319);
// 3.19 (N319-7, N319-1, N319-3): what leaves the site carries no database message; an on site and a busy lock have their own words
$failure319 = ['reason' => 'database', 'table' => 'stitky', 'error' => "ALTER command denied to user 'w123'@'10.0.0.5'"];
$texts319 = Kaleta\Core\Language::runWith('en', fn (): array => [Kaleta\Core\Slug::failureText($failure319), Kaleta\Core\Slug::failureText($failure319, false),
    Kaleta\Core\Slug::failureText($failure319, false, true), Kaleta\Core\Slug::failureText(['reason' => 'shared', 'table' => 'kategorie', 'slugs' => ['/a']], true, true),
    Kaleta\Core\Slug::failureText(['reason' => 'busy', 'table' => ''])], 'admin-');
check('3.19 (N319-7, N319-1, N319-3): the failure texts – the database message only where asked, an on site told to save once more, a busy lock', [
    str_contains($texts319[0], "w123'@'10.0.0.5"), str_contains($texts319[1], 'w123'), str_contains($texts319[1], 'ALTER'), str_contains($texts319[2], 'older key'),
    str_contains($texts319[3], 'stays on'), str_contains($texts319[4], 'Another request')], [true, false, true, true, true, true]);
check('3.19 (N319-2): the refusal of an accent twin names the saved address, in Czech too', [
    Kaleta\Core\Language::runWith('cs', fn (): string => t(Kaleta\Admin\Modules\Redirects::TWIN, '/cafe'), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => t(Kaleta\Core\RedirectRules::TWIN), 'admin-') !== Kaleta\Core\RedirectRules::TWIN],
    ['Stará adresa, která se od této liší jen velikostí písmen nebo diakritikou, už je uložená: /cafe. Upravte raději to přesměrování – do Kalety 4.0 nemohou mít obě různé cíle.', true]);

