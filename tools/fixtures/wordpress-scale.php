<?php

declare(strict_types=1);

/*
 * A generated WordPress export (WXR 1.2) of a bilingual English and Arabic WooCommerce shop (3.12) – the shape of a clinic
 * supplies site with a few hundred products in each language, for the scale test of import_wordpress:
 *
 *   php tools/fixtures/wordpress-scale.php [--products=396] [--plugin=polylang|wpml] [--site=https://…] [--out=file.xml]
 *
 *  - products (post type "product") in English and Arabic, linked as translations, with WooCommerce fields (SKU, price,
 *    sale price, stock), a featured image (an attachment by URL), product categories (product_cat, two levels, linked
 *    across the languages), a product type and a few variations; every 40th Arabic product has no English original and
 *    every 50th product is a draft;
 *  - Arabic slugs are percent-encoded Arabic, as WordPress makes them from an Arabic title (/ar/product/%d8%af…/);
 *  - pages and posts in both languages with their translations, post categories linked across the languages;
 *  - a main menu per language (pages, a product category, a product, the Polylang language switcher);
 *  - --plugin=polylang (the default): the language and post_translations taxonomies; --plugin=wpml: the custom fields of
 *    WPML Export and Import (_wpml_import_language_code, _wpml_import_translation_group, and the same as term meta).
 *
 * Everything is made up and the same on every run; the .example domain does not exist. tools/test.sh imports a small
 * one (--products=60); the full size is for measuring the import by hand.
 */

$options = getopt('', ['products::', 'plugin::', 'site::', 'out::']);
$count = max(1, min(5000, (int) ($options['products'] ?? 396)));
$plugin = ($options['plugin'] ?? 'polylang') === 'wpml' ? 'wpml' : 'polylang';
$out = (string) ($options['out'] ?? 'php://stdout');
// the old site's address: a test points it at a fake old site (tools/fake-old-site.php) that serves the images and pages
$site = rtrim((string) ($options['site'] ?? 'https://www.fysio-scale.example'), '/');

/** Text inside CDATA (a "]]>" would end it). */
$cdata = fn (string $text): string => '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $text) . ']]>';
/** WordPress's sanitize_title() of an Arabic title: lower-case percent-encoding, words joined by hyphens. */
$arabicSlug = fn (string $title): string => implode('-', array_map(fn (string $w): string => strtolower(rawurlencode($w)), preg_split('/\s+/u', trim($title)) ?: []));
$latinSlug = fn (string $title): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');

// what the products are: English and Arabic names of the same things (the Latin model number stays in the Arabic name)
$nouns = [
    ['Kinesio Tape', 'شريط كينيزيو'], ['Knee Brace', 'دعامة الركبة'], ['Therapy Ball', 'كرة العلاج'], ['Back Support Belt', 'حزام دعم الظهر'],
    ['Neck Pillow', 'وسادة الرقبة'], ['Resistance Band', 'شريط المقاومة'], ['Massage Table', 'طاولة التدليك'], ['Hot Pack', 'كمادة ساخنة'],
    ['Cold Pack', 'كمادة باردة'], ['Shoulder Sling', 'حمالة الكتف'], ['Ankle Support', 'دعامة الكاحل'], ['Wrist Splint', 'جبيرة المعصم'],
    ['Balance Board', 'لوح التوازن'], ['Foam Roller', 'أسطوانة الفوم'], ['TENS Unit', 'جهاز تنس'], ['Ultrasound Gel', 'جل الموجات فوق الصوتية'],
];
$adjectives = [['Pro', 'احترافي'], ['Sport', 'رياضي'], ['Classic', 'كلاسيكي'], ['Flex', 'مرن'], ['Medical', 'طبي']];
// product categories: four top-level ones with two subcategories each, English and Arabic
$categories = [
    ['Taping', 'الأشرطة', ['Kinesiology tape', 'شريط الحركة'], ['Rigid tape', 'شريط صلب']],
    ['Supports and braces', 'الدعامات', ['Knee supports', 'دعامات الركبة'], ['Back supports', 'دعامات الظهر']],
    ['Exercise', 'التمارين', ['Balls and rollers', 'الكرات والأسطوانات'], ['Bands', 'الأشرطة المطاطية']],
    ['Clinic equipment', 'معدات العيادة', ['Tables', 'الطاولات'], ['Electrotherapy', 'العلاج الكهربائي']],
];

$termId = 100;
$terms = []; // header terms in the order WordPress writes them
$groups = []; // Polylang post_translations: group slug => [language => post id]
$productCats = []; // [english slug, arabic slug, english term id, arabic term id] for each category and subcategory, top-level first
$language = fn (string $lang): string => $plugin === 'polylang'
    ? '<category domain="language" nicename="' . $lang . '">' . ($lang === 'ar' ? 'العربية' : 'English') . '</category>' : '';
$termMeta = fn (string $lang, string $group): string => $plugin === 'wpml'
    ? '<wp:termmeta><wp:meta_key>_wpml_import_language_code</wp:meta_key><wp:meta_value>' . $lang . '</wp:meta_value></wp:termmeta>'
        . '<wp:termmeta><wp:meta_key>_wpml_import_translation_group</wp:meta_key><wp:meta_value>' . $group . '</wp:meta_value></wp:termmeta>' : '';
$termTranslations = function (int $en, int $ar) use (&$terms, &$termId, $plugin): void {
    if ($plugin === 'polylang') {
        $slug = sprintf('pll_67fs%08d', ++$termId);
        $terms[] = "\t<wp:term><wp:term_id>$termId</wp:term_id><wp:term_taxonomy>term_translations</wp:term_taxonomy><wp:term_slug>$slug</wp:term_slug><wp:term_name>$slug</wp:term_name>"
            . '<wp:term_description><![CDATA[a:2:{s:2:"en";i:' . $en . ';s:2:"ar";i:' . $ar . ';}]]></wp:term_description></wp:term>';
    }
};

// languages (Polylang keeps them as terms; the locale and the direction are in the description)
if ($plugin === 'polylang') {
    $terms[] = "\t<wp:term><wp:term_id>2</wp:term_id><wp:term_taxonomy>language</wp:term_taxonomy><wp:term_slug>en</wp:term_slug><wp:term_name>English</wp:term_name>"
        . '<wp:term_description><![CDATA[a:3:{s:6:"locale";s:5:"en_GB";s:3:"rtl";i:0;s:9:"flag_code";s:2:"gb";}]]></wp:term_description></wp:term>';
    $terms[] = "\t<wp:term><wp:term_id>3</wp:term_id><wp:term_taxonomy>language</wp:term_taxonomy><wp:term_slug>ar</wp:term_slug><wp:term_name>العربية</wp:term_name>"
        . '<wp:term_description><![CDATA[a:3:{s:6:"locale";s:2:"ar";s:3:"rtl";i:1;s:9:"flag_code";s:4:"arab";}]]></wp:term_description></wp:term>';
}
// post categories (news and tips), linked across the languages
$postCats = [['news-fs', 'akhbar-fs', 'News', 'الأخبار'], ['tips-fs', 'nasaih-fs', 'Tips', 'نصائح']];
$postCatIds = [];
foreach ($postCats as $i => [$enSlug, $arSlug, $enName, $arName]) {
    $en = ++$termId;
    $ar = ++$termId;
    $postCatIds[$i] = [$en, $ar];
    $terms[] = "\t<wp:category><wp:term_id>$en</wp:term_id><wp:category_nicename>$enSlug</wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name>{$cdata($enName)}</wp:cat_name>{$termMeta('en', 'cat-' . $i)}</wp:category>";
    $terms[] = "\t<wp:category><wp:term_id>$ar</wp:term_id><wp:category_nicename>$arSlug</wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name>{$cdata($arName)}</wp:cat_name>{$termMeta('ar', 'cat-' . $i)}</wp:category>";
    $termTranslations($en, $ar);
}
// product categories (custom taxonomy terms; the parent is named by its slug)
foreach ($categories as $c => [$enName, $arName, $sub1, $sub2]) {
    $parent = null;
    foreach ([[$enName, $arName], $sub1, $sub2] as $level => [$en, $ar]) {
        $enSlug = $latinSlug($en) . '-fs';
        $arSlug = $arabicSlug($ar);
        $enId = ++$termId;
        $arId = ++$termId;
        $parentSlugs = $level === 0 ? ['', ''] : [$parent[0], $parent[1]];
        $terms[] = "\t<wp:term><wp:term_id>$enId</wp:term_id><wp:term_taxonomy>product_cat</wp:term_taxonomy><wp:term_slug>$enSlug</wp:term_slug><wp:term_parent>{$parentSlugs[0]}</wp:term_parent><wp:term_name>{$cdata($en)}</wp:term_name>{$termMeta('en', 'pcat-' . $c . '-' . $level)}</wp:term>";
        $terms[] = "\t<wp:term><wp:term_id>$arId</wp:term_id><wp:term_taxonomy>product_cat</wp:term_taxonomy><wp:term_slug>$arSlug</wp:term_slug><wp:term_parent>{$parentSlugs[1]}</wp:term_parent><wp:term_name>{$cdata($ar)}</wp:term_name>{$termMeta('ar', 'pcat-' . $c . '-' . $level)}</wp:term>";
        $termTranslations($enId, $arId);
        $row = [$enSlug, $arSlug, $enId, $arId, $en, $ar];
        $productCats[$c][$level] = $row;
        if ($level === 0) {
            $parent = $row;
        }
    }
}
// the WooCommerce product types and the two menus
foreach (['simple', 'variable'] as $type) {
    $terms[] = "\t<wp:term><wp:term_id>" . (++$termId) . "</wp:term_id><wp:term_taxonomy>product_type</wp:term_taxonomy><wp:term_slug>$type</wp:term_slug><wp:term_name>$type</wp:term_name></wp:term>";
}
$terms[] = "\t<wp:term><wp:term_id>" . (++$termId) . '</wp:term_id><wp:term_taxonomy>nav_menu</wp:term_taxonomy><wp:term_slug>main-menu</wp:term_slug><wp:term_name>Main menu</wp:term_name></wp:term>';
$terms[] = "\t<wp:term><wp:term_id>" . (++$termId) . '</wp:term_id><wp:term_taxonomy>nav_menu</wp:term_taxonomy><wp:term_slug>main-menu-ar</wp:term_slug><wp:term_name>' . $cdata('القائمة الرئيسية') . '</wp:term_name></wp:term>';

$items = [];
$postId = 1000;
$date = fn (int $n): string => date('Y-m-d H:i:s', 1735725600 + $n * 3600); // from 2025-01-01, an hour apart
/** One <item>; $extra = the category and postmeta lines. */
$item = function (array $p) use ($cdata, $date): string {
    $lines = ["\t<item>", "\t\t<title>{$cdata($p['title'])}</title>", "\t\t<link>{$p['link']}</link>", "\t\t<pubDate>" . date('D, d M Y H:i:s', strtotime($date($p['id']))) . ' +0000</pubDate>',
        "\t\t<dc:creator>{$cdata('fs')}</dc:creator>", "\t\t<content:encoded>{$cdata($p['content'] ?? '')}</content:encoded>", "\t\t<excerpt:encoded>{$cdata($p['excerpt'] ?? '')}</excerpt:encoded>",
        "\t\t<wp:post_id>{$p['id']}</wp:post_id>", "\t\t<wp:post_date>{$cdata($date($p['id']))}</wp:post_date>", "\t\t<wp:post_date_gmt>{$cdata($date($p['id']))}</wp:post_date_gmt>",
        "\t\t<wp:post_name>{$cdata($p['slug'])}</wp:post_name>", "\t\t<wp:status>{$cdata($p['status'] ?? 'publish')}</wp:status>", "\t\t<wp:post_parent>" . ($p['parent'] ?? 0) . '</wp:post_parent>',
        "\t\t<wp:menu_order>" . ($p['order'] ?? 0) . '</wp:menu_order>', "\t\t<wp:post_type>{$cdata($p['type'])}</wp:post_type>", "\t\t<wp:post_password>{$cdata('')}</wp:post_password>"];
    if (isset($p['attachment'])) {
        $lines[] = "\t\t<wp:attachment_url>{$cdata($p['attachment'])}</wp:attachment_url>";
    }
    foreach ($p['categories'] ?? [] as [$domain, $nicename, $name]) {
        $lines[] = "\t\t<category domain=\"$domain\" nicename=\"$nicename\">{$cdata($name)}</category>";
    }
    foreach ($p['meta'] ?? [] as $key => $value) {
        $lines[] = "\t\t<wp:postmeta><wp:meta_key>{$cdata((string) $key)}</wp:meta_key><wp:meta_value>{$cdata((string) $value)}</wp:meta_value></wp:postmeta>";
    }
    $lines[] = "\t</item>";

    return implode("\n", $lines);
};
/** The language of an item: Polylang's taxonomies (on $categories) or WPML's custom fields (on $meta). */
$inLanguage = function (array $p, string $lang, string $group) use ($plugin, &$groups): array {
    if ($plugin === 'polylang') {
        $p['categories'][] = ['language', $lang, $lang === 'ar' ? 'العربية' : 'English'];
        if ($group !== '') {
            $slug = 'pll_67fs' . substr(md5($group), 0, 8);
            $groups[$slug][$lang] = $p['id'];
            $p['categories'][] = ['post_translations', $slug, $slug];
        }
    } else {
        $p['meta']['_wpml_import_language_code'] = $lang;
        if ($group !== '') {
            $p['meta']['_wpml_import_translation_group'] = $group;
            $p['meta']['_wpml_import_source_language_code'] = $lang === 'en' ? '' : 'en';
        }
    }

    return $p;
};
$paragraph = fn (string $lang, int $n): string => $lang === 'ar'
    ? '<p>منتج عالي الجودة للعلاج الطبيعي، مناسب للعيادات والرياضيين. الرقم ' . $n . ' في مجموعتنا، مع ضمان لمدة سنتين وتوصيل سريع.</p>'
    : '<p>A high quality physiotherapy product for clinics and athletes. Number ' . $n . ' in our range, with a two-year warranty and fast delivery.</p>';

// products: an attachment (the featured image), the English product and its Arabic translation
$english = [];
for ($n = 1; $n <= $count; $n++) {
    [$noun, $nounAr] = $nouns[$n % count($nouns)];
    [$adjective, $adjectiveAr] = $adjectives[intdiv($n, count($nouns)) % count($adjectives)];
    $model = sprintf('FS-%03d', $n);
    $cat = $productCats[$n % count($productCats)];
    $sub = $cat[1 + intdiv($n, count($productCats)) % 2];
    $attachment = ++$postId;
    $image = $site . '/wp-content/uploads/2025/' . sprintf('%02d', 1 + $n % 12) . '/' . $latinSlug($noun) . '-' . $n . '.jpg';
    $items[] = $item(['id' => $attachment, 'type' => 'attachment', 'title' => "$noun $model", 'slug' => $latinSlug("$noun $model") . '-image', 'status' => 'inherit',
        'link' => $site . '/product/' . $latinSlug("$adjective $noun $model") . '/' . $latinSlug("$noun $model") . '-image/', 'attachment' => $image]);
    $group = 'product-' . $n;
    $draft = $n % 50 === 0;
    $meta = ['_edit_lock' => '1735725600:1', '_sku' => $model, '_regular_price' => (string) (10 + $n % 90) . '.90', '_price' => (string) (10 + $n % 90) . '.90',
        '_stock_status' => $n % 7 === 0 ? 'outofstock' : 'instock', 'total_sales' => (string) ($n * 3), '_thumbnail_id' => (string) $attachment, '_wc_review_count' => '0'];
    if ($n % 5 === 0) {
        $meta['_sale_price'] = (string) (8 + $n % 80) . '.50';
    }
    // attributes: the product's own (Material) and, on every third product, a global one (pa_colour) named by its term
    $attributes = ['material' => ['name' => 'Material', 'value' => 'Cotton | Elastane', 'position' => 0, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0]];
    if ($n % 3 === 0) {
        $attributes['pa_colour'] = ['name' => 'pa_colour', 'value' => '', 'position' => 1, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1];
    }
    $colour = $n % 3 === 0 ? [['pa_colour', $n % 2 === 0 ? 'blue' : 'black', $n % 2 === 0 ? 'Blue' : 'Black']] : [];
    $title = "$adjective $noun $model";
    $slug = $latinSlug($title);
    $enId = ++$postId;
    $english[$n] = [$enId, $slug, $title];
    if ($n % 40 !== 0) { // every 40th product exists only in Arabic
        $items[] = $item($inLanguage(['id' => $enId, 'type' => 'product', 'title' => $title, 'slug' => $slug, 'status' => $draft ? 'draft' : 'publish',
            'link' => $site . '/product/' . $slug . '/', 'content' => $paragraph('en', $n) . '<h2>Features</h2><ul><li>Size ' . (1 + $n % 5) . '</li><li>Latex free</li></ul>' . $paragraph('en', $n + 1),
            'excerpt' => "$title for clinics and home use.",
            'categories' => [['product_cat', $cat[0][0], $cat[0][4]], ['product_cat', $sub[0], $sub[4]], ['product_type', 'simple', 'simple'], ...$colour],
            'meta' => $meta + ['_product_attributes' => serialize($attributes)]], 'en', $group));
    }
    $titleAr = "$nounAr $adjectiveAr $model";
    $arId = ++$postId;
    $items[] = $item($inLanguage(['id' => $arId, 'type' => 'product', 'title' => $titleAr, 'slug' => $arabicSlug($titleAr), 'status' => $draft ? 'draft' : 'publish',
        'link' => $site . '/ar/product/' . $arabicSlug($titleAr) . '/', 'content' => $paragraph('ar', $n) . '<h2>المميزات</h2><ul><li>المقاس ' . (1 + $n % 5) . '</li><li>خالٍ من اللاتكس</li></ul>',
        'excerpt' => "$titleAr للعيادات والاستخدام المنزلي.",
        'categories' => [['product_cat', $cat[0][1], $cat[0][5]], ['product_cat', $sub[1], $sub[5]], ['product_type', 'simple', 'simple'], ...$colour],
        'meta' => $meta + ['_product_attributes' => serialize(['material' => ['value' => 'قطن | إيلاستين', 'name' => 'المادة'] + $attributes['material']] + $attributes)]], 'ar', $n % 40 !== 0 ? $group : ''));
    if ($n % 25 === 0) { // a variation of a variable product: WooCommerce's internals, never imported
        $items[] = $item(['id' => ++$postId, 'type' => 'product_variation', 'title' => "$title – size L", 'slug' => $slug . '-l', 'parent' => $enId,
            'link' => $site . '/?product_variation=' . $slug . '-l', 'meta' => ['_sku' => $model . '-L', '_price' => '19.90']]);
    }
}
// pages: a home, about, contact, shipping… in both languages, linked
$pages = [['Home', 'الرئيسية'], ['About us', 'من نحن'], ['Contact', 'اتصل بنا'], ['Shipping', 'الشحن'], ['Returns', 'الإرجاع'], ['Privacy policy', 'سياسة الخصوصية']];
$pageIds = [];
foreach ($pages as $i => [$en, $ar]) {
    $enId = ++$postId;
    $arId = ++$postId;
    $pageIds[$i] = [$enId, $arId];
    $enSlug = $latinSlug($en) . '-fs';
    $items[] = $item($inLanguage(['id' => $enId, 'type' => 'page', 'title' => $en, 'slug' => $enSlug, 'link' => $site . '/' . $enSlug . '/', 'content' => $paragraph('en', $i)], 'en', 'page-' . $i));
    $items[] = $item($inLanguage(['id' => $arId, 'type' => 'page', 'title' => $ar, 'slug' => $arabicSlug($ar), 'link' => $site . '/ar/' . $arabicSlug($ar) . '/', 'content' => $paragraph('ar', $i)], 'ar', 'page-' . $i));
}
// posts: twenty pairs in the two linked categories
for ($i = 1; $i <= 20; $i++) {
    $c = $i % 2;
    $en = "Exercise tip number $i";
    $ar = "نصيحة التمرين رقم $i";
    $items[] = $item($inLanguage(['id' => ++$postId, 'type' => 'post', 'title' => $en, 'slug' => $latinSlug($en) . '-fs', 'link' => $site . '/' . $latinSlug($en) . '-fs/', 'content' => $paragraph('en', $i),
        'categories' => [['category', $postCats[$c][0], $postCats[$c][2]]]], 'en', 'post-' . $i));
    $items[] = $item($inLanguage(['id' => ++$postId, 'type' => 'post', 'title' => $ar, 'slug' => $arabicSlug($ar), 'link' => $site . '/ar/' . $arabicSlug($ar) . '/', 'content' => $paragraph('ar', $i),
        'categories' => [['category', $postCats[$c][1], $postCats[$c][3]]]], 'ar', 'post-' . $i));
}
// a menu per language: three pages, a product category with its subcategory, a product and the Polylang language switcher
foreach (['en' => [0, 'main-menu', 'Main menu'], 'ar' => [1, 'main-menu-ar', 'القائمة الرئيسية']] as $lang => [$side, $menu, $menuName]) {
    $order = 0;
    $menuItem = function (string $title, string $type, string $object, int $objectId, string $url = '', int $parent = 0) use (&$postId, &$order, &$items, $item, $site, $menu, $menuName): int {
        $id = ++$postId;
        $items[] = $item(['id' => $id, 'type' => 'nav_menu_item', 'title' => $title, 'slug' => (string) $id, 'link' => $site . '/?p=' . $id, 'order' => ++$order,
            'categories' => [['nav_menu', $menu, $menuName]],
            'meta' => ['_menu_item_type' => $type, '_menu_item_menu_item_parent' => (string) $parent, '_menu_item_object_id' => (string) $objectId, '_menu_item_object' => $object, '_menu_item_url' => $url]]);

        return $id;
    };
    foreach ([1, 3, 2] as $page) {
        $menuItem('', 'post_type', 'page', $pageIds[$page][$side]);
    }
    $shop = $menuItem($side === 0 ? 'Shop' : 'المتجر', 'taxonomy', 'product_cat', $productCats[0][0][2 + $side]);
    $menuItem('', 'taxonomy', 'product_cat', $productCats[0][1][2 + $side], '', $shop);
    $menuItem('', 'post_type', 'product', $side === 0 ? $english[1][0] : $english[1][0] + 1, '', $shop);
    if ($plugin === 'polylang') {
        $menuItem($side === 0 ? 'Languages' : 'اللغات', 'custom', 'custom', 0, '#pll_switcher');
    }
}
// Polylang's translation groups of posts are header terms too: written after the posts are known
if ($plugin === 'polylang') {
    foreach ($groups as $slug => $members) {
        if (count($members) > 1) {
            $map = implode('', array_map(fn (string $l, int $id): string => 's:2:"' . $l . '";i:' . $id . ';', array_keys($members), $members));
            $terms[] = "\t<wp:term><wp:term_id>" . (++$termId) . "</wp:term_id><wp:term_taxonomy>post_translations</wp:term_taxonomy><wp:term_slug>$slug</wp:term_slug><wp:term_name>$slug</wp:term_name>"
                . '<wp:term_description><![CDATA[a:' . count($members) . ':{' . $map . '}]]></wp:term_description></wp:term>';
        }
    }
}

$xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
    . '<!-- Generated by tools/fixtures/wordpress-scale.php (' . $count . ' products per language, ' . $plugin . '). Made up; the .example domain does not exist. -->' . "\n"
    . '<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/"'
    . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">' . "\n<channel>\n"
    . "\t<title>Fysio Scale</title>\n\t<link>$site</link>\n\t<description>Physiotherapy supplies</description>\n\t<language>en-GB</language>\n"
    . "\t<wp:wxr_version>1.2</wp:wxr_version>\n\t<wp:base_site_url>$site</wp:base_site_url>\n\t<wp:base_blog_url>$site</wp:base_blog_url>\n"
    . "\t<wp:author><wp:author_id>1</wp:author_id><wp:author_login>{$cdata('fs')}</wp:author_login><wp:author_email>{$cdata('web@fysio-scale.example')}</wp:author_email><wp:author_display_name>{$cdata('Fysio Scale')}</wp:author_display_name></wp:author>\n"
    . implode("\n", $terms) . "\n\t<generator>https://wordpress.org/?v=6.6</generator>\n"
    . implode("\n", $items) . "\n</channel>\n</rss>\n";
if (file_put_contents($out, $xml) === false) {
    fwrite(STDERR, "Cannot write $out\n");
    exit(1);
}
