<?php

declare(strict_types=1);

use Kaleta\Front\Seo;

/* ---------- right-to-left languages: Arabic (docs/design/4.0-data-model.md §5) ---------- */
check('RTL Language::isRtl / dir / dirAttribute – Arabic, Hebrew, Persian and Urdu right to left, a script subtag decides first; Latin languages keep no attribute',
    [array_map(Kaleta\Core\Language::isRtl(...), ['ar', 'he', 'fa', 'ur', 'ar-eg', 'cs', 'en', 'en-gb', 'az-arab', 'ar-latn', 'pa-arab', 'zh-hant', '']),
        Kaleta\Core\Language::dir('ar'), Kaleta\Core\Language::dir('de'), Kaleta\Core\Language::dirAttribute('ar'), Kaleta\Core\Language::dirAttribute('cs')],
    [[true, true, true, true, true, false, false, false, true, false, true, false, false], 'rtl', 'ltr', ' dir="rtl"', '']);
check('RTL Arabic is offered: a stored tag, its locale and CODES; a Polylang slug ar or a locale ar_SA maps to it; Hebrew is ready but not offered',
    [Kaleta\Core\Language::isOffered('ar'), Kaleta\Core\Language::AVAILABLE['ar'][1], str_ends_with(Kaleta\Core\Language::CODES, '|ar'), Kaleta\Core\Language::tag('ar'), Kaleta\Core\Language::fromForeign('ar_SA'),
        Kaleta\Core\WpLanguages::code('ar', 'ar'), Kaleta\Core\WpLanguages::code('ar-sa'), Kaleta\Core\WpLanguages::code('he', 'he_IL'), Kaleta\Core\Language::splitPrefix('/ar/kontakt'), Kaleta\Core\Routes::systemSlugError('ar') !== null],
    [true, 'ar_SA', true, 'ar', 'ar', 'ar', 'ar', null, ['ar', '/kontakt'], true]);
// the visitor dictionary covers every text the other visitor dictionaries translate (a native speaker should review it)
$rtlArabic = require KALETA_SYSTEM . '/jazyky/ar.php';
$rtlOthers = [];
foreach (['de', 'es', 'fr', 'it', 'pl', 'sk'] as $rtlCode) {
    $rtlOthers += require KALETA_SYSTEM . '/jazyky/' . $rtlCode . '.php';
}
check('RTL ar.php has every key the other visitor dictionaries have, no empty translation, and the cookie bar link text',
    [array_values(array_diff(array_keys($rtlOthers), array_keys($rtlArabic))), array_keys(array_filter($rtlArabic, fn (string $v): bool => trim($v) === '')), isset($rtlArabic['More about cookies and privacy'])], [[], [], true]);
// 3.12 (UX310-12): Portuguese (pt-br uses it), Simplified and Traditional Chinese – complete like the others (machine-assisted,
// a native review is welcome); every regional version Kaleta offers finds visitor texts, and Settings says which languages have none
$visitorOthers = $rtlOthers + $rtlArabic;
$visitorMissing = [];
foreach (['pt', 'zh', 'zh-hant', 'hu'] as $visitorCode) { // hu (3.14.2): found missing while building a Hungarian site
    $visitorDictionary = require KALETA_SYSTEM . '/jazyky/' . $visitorCode . '.php';
    foreach (array_diff(array_keys($visitorOthers), array_keys($visitorDictionary)) as $missingKey) {
        $visitorMissing[] = $visitorCode . ': ' . $missingKey;
    }
    foreach (array_keys(array_filter($visitorDictionary, fn (string $v): bool => trim($v) === '')) as $emptyKey) {
        $visitorMissing[] = $visitorCode . ': empty ' . $emptyKey;
    }
}
check('3.12 pt.php, zh.php, zh-hant.php and hu.php have every key the other visitor dictionaries have, none empty', $visitorMissing, []);
$visitorTexts = [];
foreach (['pt-br' => 'pt', 'pt' => 'pt', 'zh' => 'zh', 'zh-hant' => 'zh-hant', 'hu' => 'hu'] as $visitorCode => $visitorFile) {
    Kaleta\Core\Language::set($visitorCode);
    $visitorTexts[] = t('Read more') === (require KALETA_SYSTEM . '/jazyky/' . $visitorFile . '.php')['Read more'] && t('Read more') !== 'Read more';
}
Kaleta\Core\Language::set('zh-hant');
$visitorTexts[] = t('datum_format');
Kaleta\Core\Language::set('hu');
$visitorTexts[] = t('datum_format');
Kaleta\Core\Language::set('cs');
check('3.12 pt-br reads pt.php, zh-hant its own file with its own date format; hu its own file and the date format of AVAILABLE', $visitorTexts, [true, true, true, true, true, 'Y/m/d', Kaleta\Core\Language::AVAILABLE['hu'][2]]);
check('3.12 Language::hasVisitorTexts: Czech, English and every language with a dictionary (regional ones through theirs) – Japanese and unknown codes not',
    [Kaleta\Core\Language::hasVisitorTexts('cs'), Kaleta\Core\Language::hasVisitorTexts('en-gb'), Kaleta\Core\Language::hasVisitorTexts('pt-br'), Kaleta\Core\Language::hasVisitorTexts('zh-hant'),
        Kaleta\Core\Language::hasVisitorTexts('de-at'), Kaleta\Core\Language::hasVisitorTexts('ja'), Kaleta\Core\Language::hasVisitorTexts('xx'), Kaleta\Core\Language::hasVisitorTexts('../cs')],
    [true, true, true, true, true, false, false, false]);
Kaleta\Core\Language::set('ar');
$rtlFormats = [format_count(12345), format_number(4.5), t('datum_format'), format_date('2026-10-07'), format_date_long('2026-10-07'), t('Read more') !== 'Read more',
    t('Back to %s', 'Kinesio Tape (5 cm)'), t('Step %d', 2) === sprintf($rtlArabic['Step %d'], 2)];
Kaleta\Core\Language::set('cs');
$rtlState = Kaleta\Core\WpImport::newState('wordpress-arabic.xml');
Kaleta\Core\WpImport::analyze($rtlState, 30, KALETA_ROOT . '/tools/fixtures/wordpress-arabic.xml');
check('RTL WpImport preview (Polylang, tools/fixtures/wordpress-arabic.xml): Arabic is a language the site can have, nothing left out, the page and post pairs grouped',
    [$rtlState['jazyky']['plugin'], $rtlState['jazyky']['kody'], $rtlState['jazyky']['nalezeno'], $rtlState['jazyky']['nepodporovane'], count($rtlState['jazyky']['skupiny'])],
    ['polylang', ['en' => 'en', 'ar' => 'ar'], ['en' => 2, 'ar' => 2], [], 2]);
check('RTL Arabic formats: Western digits with . and , (as the site writes them), d/m/Y, the date in words with Arabic names, a value in a sentence isolated (FSI…PDI), numbers not',
    $rtlFormats, ['12,345', '4.5', 'd/m/Y', '07/10/2026', 'الأربعاء، 7 أكتوبر 2026', true, sprintf($rtlArabic['Back to %s'], "\u{2068}Kinesio Tape (5 cm)\u{2069}"), true]);
check('RTL a left-to-right t() with values is as it was', t('Back to %s', 'x'), 'Zpět na x');
// the page CSS: the rules logical properties cannot say only on a right-to-left page
$rtlCss = function (string $code): string {
    $context = new Kaleta\Builder\Context((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor());
    $context->types = ['casova_osa' => true, 'pred_po' => true, 'hotspoty' => true, 'cenik' => true, 'prubeh' => true];
    return Kaleta\Core\Language::runWith($code, fn (): string => Kaleta\Builder\Build::css((new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(), $context));
};
$rtlTypes = array_map(fn (string $c): string => $c::TYPE, [Kaleta\Builder\Elements\Timeline::class, Kaleta\Builder\Elements\BeforeAfter::class, Kaleta\Builder\Elements\Hotspots::class, Kaleta\Builder\Elements\PricingTable::class, Kaleta\Builder\Elements\Progress::class]);
check('RTL the element types with right-to-left rules are the ones the test renders', $rtlTypes, ['casova_osa', 'pred_po', 'hotspoty', 'cenik', 'prubeh']);
$rtlArabicCss = $rtlCss('ar');
$rtlEnglishCss = $rtlCss('en');
check('RTL Build::css: an Arabic page adds the translate, clip, origin and photo-position rules after the base CSS; an English page has none of them',
    [str_contains($rtlArabicCss, '.ka-casova-osa::before, .ka-casova-osa-polozka::before { translate: 50% 0; }'), str_contains($rtlArabicCss, 'clip-path: inset(0 var(--ka-delic) 0 0)'),
        str_contains($rtlArabicCss, '.ka-hotspoty-bod { right: auto; left: var(--x); }'), str_contains($rtlArabicCss, 'transform-origin: right'), str_contains($rtlEnglishCss, 'translate: 50%'),
        $rtlEnglishCss === str_replace(array_map(fn (string $c): string => $c::rtlCss() . "\n", [Kaleta\Builder\Elements\Timeline::class, Kaleta\Builder\Elements\BeforeAfter::class, Kaleta\Builder\Elements\Hotspots::class, Kaleta\Builder\Elements\PricingTable::class, Kaleta\Builder\Elements\Progress::class]), '', $rtlArabicCss)],
    [true, true, true, true, false, true]);
check('RTL the builder style: From left / From right are the start and end insets; left and right in imported CSS map to them; the stored keys stay',
    [str_contains(Kaleta\Builder\Style::css('#a', ['zaklad' => ['zleva' => 'm', 'zprava' => '0']]), 'inset-inline-start: var(--ka-mezera-m)'), str_contains(Kaleta\Builder\Style::css('#a', ['zaklad' => ['zprava' => 'm']]), 'inset-inline-end'),
        Kaleta\Builder\Style::fromCss('left', 'var(--ka-mezera-m)'), Kaleta\Builder\Style::fromCss('right', 'var(--ka-mezera-s)'), Kaleta\Mcp\Vocabulary::STYLE['zleva'] ?? null],
    [true, true, ['zleva' => 'm'], ['zprava' => 's'], 'left']);
check('RTL icons and item arrows: the arrow and send icons are mirrored on an Arabic page only',
    [Kaleta\Core\Language::runWith('ar', fn (): bool => str_contains(Kaleta\Builder\Icons::svg('sipka'), '<g transform="matrix(-1 0 0 1 24 0)">')),
        Kaleta\Core\Language::runWith('en', fn (): bool => str_contains(Kaleta\Builder\Icons::svg('sipka'), 'matrix')), Kaleta\Core\Language::runWith('ar', fn (): bool => str_contains(Kaleta\Builder\Icons::svg('telefon'), 'matrix'))],
    [true, false, false]);
$rtlValues = ['nazev' => ['Kinesio Tape (5 cm)', 'text'], 'popis' => ['<p>x</p>', 'html']];
check('RTL Collections::fill: on an Arabic page a value inside a heading or a text is isolated in <bdi>; formatted text, plain fields and a Latin page stay as they were',
    [Kaleta\Core\Language::runWith('ar', fn (): array => [Kaleta\Builder\Collections::fill('{{nazev}}', 'inline', $rtlValues), Kaleta\Builder\Collections::fill('<p>السعر: {{nazev}}</p>', 'html', $rtlValues),
        Kaleta\Builder\Collections::fill('{{popis}}', 'html', $rtlValues), Kaleta\Builder\Collections::fill('{{nazev}}', 'text', $rtlValues)]),
        Kaleta\Builder\Collections::fill('{{nazev}}', 'inline', $rtlValues)],
    [['<bdi>Kinesio Tape (5 cm)</bdi>', '<p>السعر: <bdi>Kinesio Tape (5 cm)</bdi></p>', '<p>x</p>', 'Kinesio Tape (5 cm)'], 'Kinesio Tape (5 cm)']);
// 0x85 (NEL) is a line break for a byte pattern – and the second byte of م (U+0645) or Å: lines are split as UTF-8
$rtlList = Kaleta\Builder\Elements\BulletList::render(['znacka' => 'ul', 'obsah' => ['polozky' => "تصميم الحل\nمدير مشروع\nÅre", 'styl' => 'fajfky']], '', '', new Kaleta\Builder\Context((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor()));
check('RTL a list, FAQ and opening hours split lines as UTF-8: Arabic and Å stay whole', [substr_count($rtlList, '<li>'), str_contains($rtlList, '<li>تصميم الحل</li>'), str_contains($rtlList, '<li>Åre</li>'), count(Seo::faq("كم يستغرق المشروع؟\nمم\n\nسؤال\nجواب"))], [3, true, true, 2]);
check('RTL slugify: Arabic titles get a stable lowercase ASCII transliteration (vowel marks dropped, Arabic-Indic digits as digits); nothing to transliterate stays n-a for Slug::fromId',
    array_map(fn (string $t): string => slugify($t), ['شريط لاصق', 'شريط كينيزيو 5 cm', 'تثبيت الركبة المؤلمة بالشريط', 'الأَمْنُ ٢٠٢٦', 'مدونة FXM', 'עברית', 'Příliš žluťoučký']),
    ['shryt-lasq', 'shryt-kynyzyw-5-cm', 'tthbyt-alrkba-almulma-balshryt', 'alamn-2026', 'mdwna-fxm', 'n-a', 'prilis-zlutoucky']);
check('RTL bdi(): a typed value isolated and escaped for the administration lists', [bdi('سارة <x>'), bdi(null)], ['<bdi>سارة &lt;x&gt;</bdi>', '<bdi></bdi>']);
check('RTL Slug::FALLBACK names the four tables a new slug can come from', array_keys(Kaleta\Core\Slug::FALLBACK), ['stranky', 'novinky', 'kategorie', 'kolekce_polozky']);
check('RTL Search::normalize: Arabic stays searchable without vowel marks and with one alef; Latin tokens as before',
    [Kaleta\Core\Search::normalize('<p>الأَمْنُ والسلامة – Kinesio Tape (5 cm)</p>'), Kaleta\Core\Search::normalize('Příliš žluťoučký kůň, 5 cm!'), Kaleta\Core\Search::query('شريط لاصق')],
    ['الامن والسلامه kinesio tape 5 cm', 'prilis zlutoucky kun 5 cm', '+شريط* +لاصق*']);
check('3.12 Search::normalize: taa marbuta as ha, every alef as one, alef maqsura as ya, no tatweel or harakat – the text and the query meet',
    [Kaleta\Core\Search::normalize('مَدْرَسَةٌ ٱلْإِسْلَامِيَّة آمِنَة عَلَى طـــويل'), Kaleta\Core\Search::normalize('مدرسه الاسلاميه امنه علي طويل'),
        Kaleta\Core\Search::find('الركبه', [['titulek' => 'دعامة الركبة', 'adresa' => 'x', 'text' => '']]) !== []],
    ['مدرسه الاسلاميه امنه علي طويل', 'مدرسه الاسلاميه امنه علي طويل', true]);
$rtlMail = Kaleta\Core\Mail::rightToLeft("شكرًا لكم\nhttps://example.test/ar/x?a=1&b=2 <test>", 'ar');
check('RTL Mail::rightToLeft: the HTML part of a plain Arabic e-mail is right to left, keeps the lines, links the address and escapes the rest',
    [str_contains($rtlMail, '<html lang="ar" dir="rtl">'), str_contains($rtlMail, 'white-space: pre-wrap'), str_contains($rtlMail, '<a href="https://example.test/ar/x?a=1&amp;b=2" dir="ltr">'), str_contains($rtlMail, '&lt;test&gt;')],
    [true, true, true, true]);
// the shared CSS of the site uses logical properties; what stays physical is said in a comment next to it
$rtlPhysical = '/(?<![-\w])(left|right)\s*:|(margin|padding|border)-(left|right)\b|text-align:\s*(left|right)/';
$rtlWeb = array_values(array_filter(preg_split('/\n/', (string) file_get_contents(KALETA_ROOT . '/image/web.css')) ?: [], fn (string $l): bool => preg_match($rtlPhysical, $l) === 1
    && !str_contains($l, 'anchor') && !str_contains($l, ':root[dir="rtl"]') && !str_contains($l, '.ka-medium-spustit span')));
$rtlTemplate = preg_match_all($rtlPhysical, (string) file_get_contents(KALETA_ROOT . '/image/sablona.css'));
$rtlViews = array_sum(array_map(fn (string $f): int => (int) preg_match_all($rtlPhysical, (string) file_get_contents(KALETA_SYSTEM . '/views/front/' . $f . '.php')), ['cookies', 'pristupnost', 'komentare']));
$rtlElements = [];
foreach (Kaleta\Builder\Build::ELEMENTS as $rtlClass) {
    if (preg_match($rtlPhysical, $rtlClass::baseCss()) === 1) {
        $rtlElements[] = $rtlClass::TYPE;
    }
}
check('RTL web.css, sablona.css, the cookie bar, the accessibility toolbar and the element CSS have no physical left/right (except the language menu anchor, the play icon and the centred mega menu panel)',
    [$rtlWeb, $rtlTemplate, $rtlViews, $rtlElements], [[], 0, 0, ['navigace']]);
