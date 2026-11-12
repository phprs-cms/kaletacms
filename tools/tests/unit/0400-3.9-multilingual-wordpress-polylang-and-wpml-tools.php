<?php

declare(strict_types=1);

/* ---------- 3.9: multilingual WordPress – Polylang and WPML (tools/fixtures/wordpress-polylang.xml, wordpress-wpml.xml) ---------- */
check('3.10 WpLanguages::code: the slug Kaleta offers (the old addresses use it), else the locale, else the language; a regional version is kept unless the site has only its language; unknown languages null', [
    Kaleta\Core\WpLanguages::code('cs', 'cs_CZ'), Kaleta\Core\WpLanguages::code('cz', 'cs_CZ'), Kaleta\Core\WpLanguages::code('en', 'en_GB'), Kaleta\Core\WpLanguages::code('pt-br'),
    Kaleta\Core\WpLanguages::code('zh-hans'), Kaleta\Core\WpLanguages::code('nb'), Kaleta\Core\WpLanguages::code('DE'), Kaleta\Core\WpLanguages::code('he', 'he_IL'), Kaleta\Core\WpLanguages::code(''),
    Kaleta\Core\WpLanguages::code('english', 'en_GB'), Kaleta\Core\WpLanguages::code('english', 'en_GB', ['cs', 'en']), Kaleta\Core\WpLanguages::code('pt-br', '', ['cs', 'pt']),
    Kaleta\Core\WpLanguages::code('pt-br', '', ['cs', 'pt', 'pt-br']), Kaleta\Core\WpLanguages::code('zh-tw', 'zh_TW'), Kaleta\Core\WpLanguages::code('nb', 'nb_NO')],
    ['cs', 'cs', 'en', 'pt-br', 'zh', 'no', 'de', null, null, 'en-gb', 'en', 'pt', 'pt-br', 'zh', 'no']);
check('3.9 WpLanguages::translationMap: language => id from a Polylang group; extra keys (sync), objects, references and damaged strings give nothing', [
    Kaleta\Core\WpLanguages::translationMap('a:3:{s:2:"cs";i:102;s:2:"en";i:103;s:4:"sync";a:1:{s:2:"en";s:2:"cs";}}'),
    Kaleta\Core\WpLanguages::translationMap('a:2:{s:5:"pt-br";s:2:"12";s:2:"de";i:0;}'),
    Kaleta\Core\WpLanguages::translationMap('a:1:{s:2:"cs";O:8:"stdClass":0:{}}'),
    Kaleta\Core\WpLanguages::translationMap('a:1:{s:2:"cs";R:1;}'),
    Kaleta\Core\WpLanguages::translationMap('a:2:{s:3:"cs";i:1;}'),
    Kaleta\Core\WpLanguages::translationMap('O:8:"stdClass":1:{s:2:"cs";i:1;}'),
    Kaleta\Core\WpLanguages::unserializeArray('a:1:{i:0;a:1:{i:0;a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}}}'),
    Kaleta\Core\WpLanguages::unserializeArray('a:2:{s:1:"a";b:1;s:1:"b";d:0.5;}x')],
    [['cs' => 102, 'en' => 103], ['pt-br' => 12], [], [], [], [], null, null]);
check('3.9 WpLanguages::locale, urlLanguage and majority', [
    Kaleta\Core\WpLanguages::locale('a:3:{s:6:"locale";s:5:"cs_CZ";s:3:"rtl";i:0;s:9:"flag_code";s:2:"cz";}'), Kaleta\Core\WpLanguages::locale('a:1:{s:6:"locale";s:9:"<script>x";}'),
    Kaleta\Core\WpLanguages::urlLanguage('https://a.example/en/about/', ['cs', 'en']), Kaleta\Core\WpLanguages::urlLanguage('https://a.example/?page_id=4&lang=en', ['cs', 'en']),
    Kaleta\Core\WpLanguages::urlLanguage('https://a.example/enterprise/', ['cs', 'en']), Kaleta\Core\WpLanguages::urlLanguage('https://a.example/de/x/', ['cs', 'en']),
    Kaleta\Core\WpLanguages::majority(['en', '', 'cs', 'en']), Kaleta\Core\WpLanguages::majority(['', ''])],
    ['cs_CZ', '', 'en', 'en', '', '', 'en', '']);
$pllPath = KALETA_ROOT . '/tools/fixtures/wordpress-polylang.xml';
$pllHeader = (new Kaleta\Core\WpFile($pllPath))->header();
check('3.9 WpFile: Polylang languages with their locales, the language and group of each category by term number, names of categories sharing a slug',
    [$pllHeader['plugin'], $pllHeader['jazyky'], $pllHeader['jazyk_terminu'], $pllHeader['skupina_terminu'], $pllHeader['nazev_terminu']],
    ['polylang', ['cs' => 'cs_CZ', 'en' => 'en_GB', 'he' => 'he_IL'], [5 => 'cs', 6 => 'en'], [5 => 'pll:pll_66f0a1b2c3d4e', 6 => 'pll:pll_66f0a1b2c3d4e'], [5 => 'Blog ICTX', 6 => 'ICTX Blog']]);
$pllItems = iterator_to_array((new Kaleta\Core\WpFile($pllPath))->items());
check('3.9 WpFile: a post names its Polylang language and translation group', [$pllItems[0]['jazyk_wp'], $pllItems[0]['skupina'], $pllItems[0]['jazyk_plugin'], $pllItems[0]['rubriky']],
    ['en', 'pll:pll_66f0a1b2c3d50', 'polylang', []]);
$pllState = Kaleta\Core\WpImport::newState('wordpress-polylang.xml');
Kaleta\Core\WpImport::analyze($pllState, 30, $pllPath);
check('3.9 WpImport preview (Polylang): items per language, Hebrew reported as unavailable, translation groups (a translation before its original too)',
    [$pllState['jazyky']['plugin'], $pllState['jazyky']['kody'], $pllState['jazyky']['nalezeno'], $pllState['jazyky']['nepodporovane'], $pllState['jazyky']['skupiny'], $pllState['jazyky']['polozky'][103]],
    ['polylang', ['cs' => 'cs', 'en' => 'en', 'he' => ''], ['en' => 3, 'cs' => 3], ['he' => 1],
        ['pll:pll_66f0a1b2c3d50' => [101, 100], 'pll:pll_66f0a1b2c3d51' => [102, 103], 'pll:pll_66f0a1b2c3d52' => [110, 111]], ['en', 'page', 'kontakt-ictx']]);
check('3.9 WpImport::previewMenus: a menu per language, each the main menu of its version; a single-language export as before',
    [Kaleta\Core\WpImport::previewMenus($pllState, 'cs'), Kaleta\Core\WpImport::previewMenus($migState, 'cs')],
    [['hlavni-menu' => ['hlavni', ''], 'main-menu-en' => ['hlavni', 'en']], ['hlavni-menu' => ['hlavni', ''], 'paticka' => ['paticka', ''], 'socialni-site' => ['', '']]]);
$pllSummary = Kaleta\Core\WpImport::summary($pllState);
check('3.9 WpImport::summary of a multilingual export: the languages block; a single-language export has none',
    [array_keys($pllSummary['languages']), $pllSummary['languages']['not_available'][0]['language'], $pllSummary['languages']['translation_groups'], isset(Kaleta\Core\WpImport::summary($migState)['languages'])],
    [['plugin', 'items_per_language', 'not_available', 'translation_groups', 'added_to_site', 'translations_linked', 'slug_clashes', 'more_slug_clashes', 'how'], 'he', 3, false]);
$wpmlPath = KALETA_ROOT . '/tools/fixtures/wordpress-wpml.xml';
$wpmlHeader = (new Kaleta\Core\WpFile($wpmlPath))->header();
check('3.9 WpFile: WPML Export and Import termmeta – the language and the group of each category', [$wpmlHeader['plugin'], $wpmlHeader['jazyk_terminu'], $wpmlHeader['skupina_terminu']],
    ['wpml', [30 => 'cs', 31 => 'en'], [30 => 'wpml:7', 31 => 'wpml:7']]);
$wpmlState = Kaleta\Core\WpImport::newState('wordpress-wpml.xml');
Kaleta\Core\WpImport::analyze($wpmlState, 30, $wpmlPath);
$wpmlItems = iterator_to_array((new Kaleta\Core\WpFile($wpmlPath))->items());
check('3.9 WpImport preview (WPML): the language from the custom fields, a duplicate grouped with its original, a page with the language only in its address',
    [$wpmlState['jazyky']['nalezeno'], $wpmlState['jazyky']['skupiny'], $wpmlState['jazyky']['polozky'][307], Kaleta\Core\WpImport::itemLanguage($wpmlItems[4], $wpmlState), Kaleta\Core\WpImport::itemLanguage($wpmlItems[0], $wpmlState)],
    [['cs' => 3, 'en' => 4], ['wpml:1' => [300, 301], 'dup:305' => [306], 'wpml:2' => [310, 311]], ['en', 'page', 'about-wpx'], ['en', ''], ['cs', '']]);
// 3.9 N39-2: translation groups past WpFile::MAX_TERMS are not kept – an export with a huge number of them cannot fill the memory
$n392File = (string) tempnam(sys_get_temp_dir(), 'kaleta-n392');
$n392 = '<?xml version="1.0" encoding="UTF-8"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>x</title>';
for ($i = 0; $i < Kaleta\Core\WpFile::MAX_TERMS / 2 + 5000; $i++) {
    $n392 .= '<wp:term><wp:term_id>' . $i . '</wp:term_id><wp:term_taxonomy>term_translations</wp:term_taxonomy><wp:term_slug>pll_' . $i . '</wp:term_slug><wp:term_description><![CDATA[a:2:{s:2:"cs";i:' . (2 * $i + 1) . ';s:2:"en";i:' . (2 * $i + 2) . ';}]]></wp:term_description></wp:term>';
}
file_put_contents($n392File, $n392 . '</channel></rss>');
$n392Header = (new Kaleta\Core\WpFile($n392File))->header();
@unlink($n392File);
// 3.12: a WooCommerce shop (Core\WpWooCommerce) – the generated bilingual export of tools/fixtures/wordpress-scale.php,
// products as the Products preset, product categories with their parents and languages
$wooFile = (string) tempnam(sys_get_temp_dir(), 'kaleta-woo');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(KALETA_ROOT . '/tools/fixtures/wordpress-scale.php') . ' --products=6 --out=' . escapeshellarg($wooFile), $wooOutput, $wooExit);
$wooHeader = (new Kaleta\Core\WpFile($wooFile))->header();
$wooItems = array_values(array_filter(iterator_to_array((new Kaleta\Core\WpFile($wooFile))->items()), fn (array $p): bool => $p['typ'] === 'product'));
$wooState = Kaleta\Core\WpImport::newState('scale.xml');
Kaleta\Core\WpImport::analyze($wooState, 30, $wooFile);
@unlink($wooFile);
$wooCategory = array_search(['polozky', 'taping-fs'], $wooHeader['terminy'], true);
$wooChild = array_search(['polozky', 'kinesiology-tape-fs'], $wooHeader['terminy'], true);
check('3.12 WooCommerce: product categories in the header with name, parent and language; a product with its categories, meta and attributes', [
    $wooExit, $wooHeader['nazev_terminu'][$wooCategory] ?? null, $wooHeader['predek_terminu'][$wooChild] ?? null, $wooHeader['jazyk_terminu'][$wooChild] ?? null,
    $wooHeader['skupina_terminu'][$wooChild] === ($wooHeader['skupina_terminu'][$wooChild + 1] ?? ''), $wooItems[0]['kategorie'], $wooItems[0]['woo']['_sku'] ?? null,
    $wooItems[0]['woo']['typ'] ?? null, Kaleta\Core\WpWooCommerce::isProduct($wooItems[0]), $wooState['prehled']['typy']['product']['woo'], count($wooState['prehled']['typy']['product']['kategorie']),
    $wooState['prehled']['jine'], $wooState['jazyky']['nalezeno']],
    [0, 'Taping', 'taping-fs', 'en', true, ['supports-and-braces-fs' => 'Supports and braces', 'knee-supports-fs' => 'Knee supports'], 'FS-001', 'simple', true, true, 20, [], ['en' => 32, 'ar' => 32]]);
$wooText = fn (string $t): string => '[' . $t . ']';
$wooValues = Kaleta\Core\WpWooCommerce::values($wooItems[0], [(int) $wooItems[0]['nahled'] => 'https://old.example/a.jpg'], ['knee-supports-fs' => 'supports-and-braces-fs'], $wooText);
check('3.12 WooCommerce: a product as the fields of the Products preset (image, code, the most specific category, price, availability, parameters)', [
    $wooValues['image'], $wooValues['code'], $wooValues['category'], $wooValues['price'], $wooValues['availability'], $wooValues['parameters'], str_starts_with($wooValues['description'], '<p>A high quality'),
    array_diff(array_keys($wooValues), array_column((array) Kaleta\Builder\Presets::get('products')['fields'], 0))],
    ['https://old.example/a.jpg', 'FS-001', 'Knee supports', '11.90', '[In stock]', 'Material: Cotton, Elastane', true, []]);
check('3.12 WooCommerce: the price a visitor saw, the attributes as Name: value lines within the limits of the Parameters field', [
    Kaleta\Core\WpWooCommerce::price(['_price' => '19,5', '_regular_price' => '25']), Kaleta\Core\WpWooCommerce::price(['_regular_price' => '25']), Kaleta\Core\WpWooCommerce::price(['_price' => 'free']),
    Kaleta\Core\WpWooCommerce::parameters(serialize(['pa_size' => ['name' => 'pa_size', 'value' => '', 'is_taxonomy' => 1], 'weight' => ['name' => 'Weight: net', 'value' => '1 kg', 'is_taxonomy' => 0]]), [['pa_size', 'S'], ['pa_size', 'M'], ['pa_colour', 'Red']]),
    Kaleta\Builder\Products::cleanParameters(Kaleta\Core\WpWooCommerce::parameters(serialize(['x' => ['name' => str_repeat('n', 100), 'value' => str_repeat('v', 300), 'is_taxonomy' => 0]]), [])) !== null,
    Kaleta\Core\WpWooCommerce::parameters('O:8:"stdClass":0:{}', [])],
    ['19.5', '25', '', "Size: S, M\nWeight  net: 1 kg\nColour: Red", true, '']);
check('3.12 WooCommerce: the old addresses of a product category, with the parent and without, behind the language prefix (decoded)', [
    Kaleta\Core\WpWooCommerce::oldCategoryPaths('knee-fs', 'supports-fs', ''), Kaleta\Core\WpWooCommerce::oldCategoryPaths('%d8%a3', '', 'ar'),
    Kaleta\Core\WpWooCommerce::mainCategory(['a' => 'A', 'b' => 'B'], ['b' => 'a']), Kaleta\Core\WpWooCommerce::mainCategory(['a' => 'A'], [])],
    [['product-category/supports-fs/knee-fs', 'product-category/knee-fs'], ['ar/product-category/أ'], 'B', 'A']);
check('3.12 WooCommerce: a shop\'s own product category base from links in its menus and texts (with a language prefix and a parent), the default otherwise; old addresses under both', [
    Kaleta\Core\WpWooCommerce::ownPaths('<a href="https://www.shop.example/kategorie-produktu/tejpy/">x</a> <a href="https://shop.example/ar/kategorie-produktu/%d8%a3/">y</a> <a href="https://other.example/a/b">z</a>', 'shop.example'),
    Kaleta\Core\WpWooCommerce::categoryBase(['kategorie-produktu/tejpy', 'ar/kategorie-produktu/أ', 'shop/category/podpory/kolena', 'kontakt', 'product-category/tejpy'], ['tejpy', '%d8%a3', 'podpory', 'kolena'], ['ar', 'en']),
    Kaleta\Core\WpWooCommerce::categoryBase(['product-category/tejpy', 'blog/tejpy-navod'], ['tejpy'], ['en']),
    Kaleta\Core\WpWooCommerce::oldCategoryPaths('kolena', 'podpory', 'ar', 'kategorie-produktu')],
    [['kategorie-produktu/tejpy', 'ar/kategorie-produktu/أ'], 'kategorie-produktu', '', ['ar/product-category/podpory/kolena', 'ar/product-category/kolena', 'ar/kategorie-produktu/podpory/kolena', 'ar/kategorie-produktu/kolena']]);
check('3.12 N312-4/5: imported redirect sources are literal – no *, no invalid UTF-8, control or direction characters, no long ones; ?p= and ?lang= stay', array_map(Kaleta\Core\WpImport::literalPath(...),
    ['product-category/tejpy', 'ar/product-category/أ', 'product-category/*', '*/*', "product-category/\xff\xfe", "x/\u{202E}gpj", str_repeat('a', 256), '?p=12', '?lang=ar', '?s=x', '']),
    [true, true, false, false, false, false, false, true, true, false, false]);
check('3.12 N312-4/5: a readable slug only from valid text; a detected category base never with a "*" segment', [
    Kaleta\Core\WpWooCommerce::readableSlug('%d8%a3'), Kaleta\Core\WpWooCommerce::readableSlug('%ff%fe'), Kaleta\Core\WpWooCommerce::readableSlug('%e2%80%aex'),
    Kaleta\Core\WpWooCommerce::categoryBase(['*/tejpy', '*/tejpy', 'shop/tejpy'], ['tejpy'], []), Kaleta\Core\WpWooCommerce::ownPaths('<a href="https://shop.example/%ff/tejpy">x</a>', 'shop.example')],
    ['أ', '%ff%fe', '%e2%80%aex', 'shop', []]);
// 3.12: fonts and scripts (Builder\FontScripts) – an Arabic version with a font without Arabic letters is reported, with fonts that have them
$fsDs = Kaleta\Builder\DesignSystem::sanitize([]);
$fsCustom = ['vlastni_pisma' => [['nazev' => 'Cairo Bold', 'soubor' => 'media/c.woff2', 'tucny' => ''], ['nazev' => 'Brand Sans', 'soubor' => 'media/b.woff2', 'tucny' => ''], ['nazev' => 'Interstate', 'soubor' => 'media/i.woff2', 'tucny' => '']]];
$fsProblems = Kaleta\Builder\FontScripts::problems(['pismo_titulky' => 'elegantni', 'pismo_text' => 'moderni'] + $fsDs, ['en', 'ar', 'ru']);
check('3.12 FontScripts: the scripts of languages, of offered stacks and of custom fonts by their family (a whole word), unknown families', [
    Kaleta\Builder\FontScripts::script('ar'), Kaleta\Builder\FontScripts::script('sr'), Kaleta\Builder\FontScripts::script('en-gb'), Kaleta\Builder\FontScripts::covered($fsCustom, 'vlastni-1'),
    Kaleta\Builder\FontScripts::covered($fsCustom, 'vlastni-2'), Kaleta\Builder\FontScripts::covered($fsCustom, 'vlastni-3'), Kaleta\Builder\FontScripts::covered($fsDs, 'moderni'),
    array_diff(array_keys(Kaleta\Front\SiteIdentity::TITLE_FONTS + Kaleta\Front\SiteIdentity::TEXT_FONTS), array_keys(Kaleta\Builder\FontScripts::STACKS))],
    ['arabic', 'cyrillic', null, ['arabic'], null, null, ['arabic', 'hebrew', 'cyrillic', 'greek'], []]);
check('3.12 FontScripts: an elegant serif for headings has neither Arabic nor Cyrillic letters, the system font for text has both; the fonts with Arabic are offered', [
    array_map(fn (array $p): string => $p['role'] . ':' . $p['script'] . ':' . implode(',', $p['languages']) . ':' . ($p['known'] ? 'known' : 'unknown'), $fsProblems),
    $fsProblems[0]['offered'], $fsProblems[0]['upload'][0], Kaleta\Builder\FontScripts::problems(['pismo_titulky' => 'arabske', 'pismo_text' => 'arabske'] + $fsDs, ['en', 'ar']),
    str_contains(Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Builder\FontScripts::message($fsProblems[0], Kaleta\Front\SiteIdentity::TITLE_FONTS), 'admin-'), 'Noto Sans Arabic')],
    [['headings:arabic:ar:known', 'headings:cyrillic:ru:known'], ['moderni', 'arabske'], 'Noto Sans Arabic', [], true]);
// 3.9.1: the phone menu – rows close together whatever the element's gap, and every arrow in the same place
$navCss = Kaleta\Builder\Elements\Navigation::baseCss();
// 3.9.2 N67: an imported Kaleta export may not place a file with a dangerous extension inside its name, and media/.htaccess
// refuses such a name wherever the extension stands; migration 0084 carries exactly the shipped media/.htaccess
check('3.9.2 N67: SiteImport::mediaTarget takes photo.jpg and Kaleta\'s photo.jpg.webp, never photo.php.jpg, x.phtml.pdf or x.shtml.pdf', array_map(
    fn (string $n): ?string => Kaleta\Core\SiteImport::mediaTarget($n),
    ['media/2026/10/photo.jpg', 'media/2026/10/photo.jpg.webp', 'media/2026/10/photo.png.avif', 'media/doc/price-list.pdf', 'media/x.php.jpg', 'media/a/x.phtml.pdf',
        'media/x.shtml.pdf', 'media/x.php5.txt', 'media/noextension', 'media/x.jpg.']),
    ['media/2026/10/photo.jpg', 'media/2026/10/photo.jpg.webp', 'media/2026/10/photo.png.avif', 'media/doc/price-list.pdf', null, null, null, null, null, null]);
$n67Htaccess = (string) file_get_contents(KALETA_ROOT . '/media/.htaccess');
preg_match('/<FilesMatch "([^"]+)">/', $n67Htaccess, $n67Deny);
check('3.9.2 N67: media/.htaccess refuses a script extension inside the name too, never an ordinary photo or document', array_map(
    fn (string $n): bool => preg_match('/' . str_replace('/', '\/', $n67Deny[1] ?? '^$') . '/', $n) === 1, // no /i: the rule itself says (?i), as Apache needs (N392-1)
    ['x.php.jpg', 'x.shtml.pdf', 'x.phtml', 'x.PHP7.png', 'x.PHP.jpg', 'index.html', 'photo.jpg', 'photo.jpg.webp', 'wash.jpg', 'english.pdf', 'phpinfo.png']),
    [true, true, true, true, true, true, false, false, false, false, false]);
// N392-1, N392-2: System status finds a planted file in media/ and knows the rule of 3.9.2
$n392Media = sys_get_temp_dir() . '/kaleta-n392-' . bin2hex(random_bytes(3)) . '/media';
@mkdir($n392Media . '/2026/10', 0777, true);
foreach (['2026/10/photo.jpg', '2026/10/photo.jpg.webp', '2026/10/x.PHP.jpg', 'a.shtml.pdf', '.htaccess'] as $n392File) {
    file_put_contents($n392Media . '/' . $n392File, 'x');
}
$n392Found = Kaleta\Core\Health::riskyMedia($n392Media);
sort($n392Found);
array_map('unlink', array_filter([...glob($n392Media . '/2026/10/*') ?: [], ...glob($n392Media . '/{,.}[!.]*', GLOB_BRACE) ?: []], 'is_file'));
@rmdir($n392Media . '/2026/10'); @rmdir($n392Media . '/2026'); @rmdir($n392Media); @rmdir(dirname($n392Media));
check('3.9.2 N392-1: System status names files with a script extension in media/, never Kaleta\'s own names or .htaccess', $n392Found, ['media/2026/10/x.PHP.jpg', 'media/a.shtml.pdf']);
check('3.9.2 N392-2: the rule System status looks for is the one media/.htaccess ships',
    str_contains($n67Htaccess, (string) (new ReflectionClassConstant(Kaleta\Core\Health::class, 'MEDIA_RULE'))->getValue()), true);
check('3.9.2 N392-4: a German "Vor- und Nachname" field is the name of a lead',
    array_map(fn (string $l): bool => preg_match((string) (new ReflectionClassConstant(Kaleta\Core\EnquiryDelivery::class, 'NAME_LABEL'))->getValue(), $l) === 1, ['Vor- und Nachname', 'Name', 'Jméno a příjmení', 'Firmenname', 'Unternehmen']),
    [true, true, true, false, false]);
preg_match("/<<<'HTACCESS'\n(.*?)\nHTACCESS/s", (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0084-media-htaccess.php'), $n67Migration);
// 3.15 (N72): migration 0098 carries the newer file (the header rules match an upper-case extension too) and replaces the one 0084 wrote
preg_match("/<<<'HTACCESS'\n(.*?)\nHTACCESS/s", (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0098-media-htaccess-case.php'), $n72Migration);
check('[source-pin] 3.9.2 N67 / 3.15 N72: migration 0098 writes exactly the media/.htaccess of the package and replaces the one of 0084 (3.9.2)', [
    ($n72Migration[1] ?? '') . "\n" === $n67Htaccess, str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0098-media-htaccess-case.php'), "'" . hash('sha256', ($n67Migration[1] ?? '') . "\n") . "'"),
    str_contains($n67Htaccess, (string) (new ReflectionClassConstant(Kaleta\Core\Health::class, 'MEDIA_HEADER_RULE'))->getValue()),
    preg_match_all('/<FilesMatch "\(\?i\)/', $n67Htaccess)], [true, true, true, 3]);
check('3.9.1 Navigation: the phone sheet resets the gap of its lists and puts the arrow of a group where a toggle has it', [
    str_contains($navCss, '.ka-nav-menu[popover] ul { flex-direction: column; gap: 2px; }'),
    str_contains($navCss, '.ka-nav-menu[popover] .podmenu > .menu-skupina::after { margin-inline: 0 calc(1.375rem - 0.2em); }'),
    str_contains($navCss, '.ka-nav .menu-rozbalit { display: grid; place-items: center; min-width: 1.75em; min-height: 1.75em; margin-inline-start: -0.225em;')],
    [true, true, true]);
check('3.9 N39-2: the WordPress header keeps at most WpFile::MAX_TERMS term languages', [count($n392Header['jazyk_terminu']), $n392Header['skupina_terminu'][1] ?? ''], [Kaleta\Core\WpFile::MAX_TERMS, 'pll:pll_0']);
check('3.9 WpImport::itemLanguage: a single-language export never guesses a language from an address; categoryKey',
    [Kaleta\Core\WpImport::itemLanguage(['odkaz' => 'https://a.example/en/x/', 'jazyk_wp' => '', 'jazyk_plugin' => ''], Kaleta\Core\WpImport::newState('x.xml')),
        Kaleta\Core\WpImport::categoryKey('blog', ''), Kaleta\Core\WpImport::categoryKey('blog', 'en')],
    [['', ''], 'blog', 'blog@en']);
check('3.9 UXM-11: the cookie bar text and policy link per language are settings with the type of their base (form, MCP, archive import)', [
    Kaleta\Admin\Modules\Settings::checkable('cookies_text_de'), Kaleta\Admin\Modules\Settings::checkable('cookies_policy_url_en'), Kaleta\Admin\Modules\Settings::checkable('cookies_mode_de'),
    Kaleta\Admin\Modules\Settings::verifyValue('cookies_policy_url_de', '/de/datenschutz'), Kaleta\Admin\Modules\Settings::verifyValue('cookies_policy_url_de', 'javascript:alert(1)'),
    Kaleta\Admin\Modules\Settings::verifyValue('cookies_text_de', ' Wir verwenden Cookies. '), in_array('cookies_text', Kaleta\Core\Settings::PER_LANGUAGE, true)],
    [true, true, false, '/de/datenschutz', null, 'Wir verwenden Cookies.', true]);

$wpTmp = sys_get_temp_dir() . '/kaleta-wp-' . bin2hex(random_bytes(4));
mkdir($wpTmp);
$wpHead = '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title><link>https://stary.example</link>';
file_put_contents($wpTmp . '/tajne.txt', 'TAJNY-OBSAH-SERVERU');
$wpMalicious = [
    'vnější entita (XXE)' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://' . $wpTmp . '/tajne.txt">]>' . $wpHead . '<item><title>&xxe;</title><content:encoded>&xxe;</content:encoded></item></channel></rss>',
    'miliarda smíchů' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "haha"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]>' . $wpHead . '<item><title>&c;</title></item></channel></rss>',
    'vnější DTD' => '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://127.0.0.1:1/zly.dtd">' . $wpHead . '</channel></rss>',
    'nedefinovaná entita' => '<?xml version="1.0"?>' . $wpHead . '<item><title>&neexistuje;</title></item></channel></rss>',
    'jiné XML než export WordPressu' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Obyčejné RSS</title><item><title>x</title></item></channel></rss>',
    'poškozené XML' => '<?xml version="1.0"?>' . $wpHead . '<item><title>neuzavřeno</item>',
    'HTML místo XML' => '<html><body>xmlns:wp="http://wordpress.org/export/1.2/"</body></html>',
];
foreach ($wpMalicious as $label => $xml) {
    file_put_contents($wpTmp . '/zly.xml', $xml);
    $read = '';
    $rejected = $wpRejects(function () use ($wpTmp, &$read): void {
        $bad = new Kaleta\Core\WpFile($wpTmp . '/zly.xml');
        $bad->verify();
        $read = (string) json_encode([$bad->header(), iterator_to_array($bad->items())]);
    });
    check('WpSoubor odmítne: ' . $label, [$rejected, str_contains($read, 'TAJNY-OBSAH') || str_contains($read, 'hahahaha')], [true, false]);
}
file_put_contents($wpTmp . '/dobry.xml', '<?xml version="1.0"?>' . $wpHead . '<item><title>A &amp; B</title></item></channel></rss>');
check('WpSoubor: běžné entity (&amp;) jsou v pořádku', iterator_to_array((new Kaleta\Core\WpFile($wpTmp . '/dobry.xml'))->items())[0]['titulek'], 'A & B');
exec('rm -rf ' . escapeshellarg($wpTmp));
foreach (['export.xml' => true, 'Můj web.WordPress.2026-09-21.XML' => true, '../config.xml' => false, 'slozka/export.xml' => false, '.skryty.xml' => false, 'export.php' => false, 'export.xml.php' => false, "export\0.xml" => false, '' => false] as $name => $expectedResult) {
    check('WpSoubor::platnyNazev ' . json_encode((string) $name), Kaleta\Core\WpFile::isValidName((string) $name), $expectedResult);
}
check('WpSoubor: název nahraného souboru bez diakritiky a vždy .xml', Kaleta\Core\WpFile::uploadName('Můj web.WordPress.2026-09-21.xml'), 'muj-web-wordpress-2026-09-21.xml');

