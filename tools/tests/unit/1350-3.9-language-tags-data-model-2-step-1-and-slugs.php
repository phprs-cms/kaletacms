<?php

declare(strict_types=1);

/* ---------- 3.9: language tags (data model §2 step 1) and slugs per language (§3 step 1) ---------- */
check('3.9 Language::isTag – the stored form is a lowercase BCP 47 tag of at most 35 characters', array_map(fn (string $t): bool => Kaleta\Core\Language::isTag($t),
    ['cs', 'en', 'pt-br', 'zh-hant', 'sr-latn', 'yue', 'pt-BR', 'pt_br', 'e', 'english', 'en-', '-en', 'en/x', '', 'a-' . str_repeat('b', 40)]),
    [true, true, true, true, true, true, false, false, false, false, false, false, false, false, false]);
check('3.9 Language::isOffered / offeredOrDefault – only what AVAILABLE offers (3.10: regional versions too), anything else is the default language', [
    Kaleta\Core\Language::isOffered('en'), Kaleta\Core\Language::isOffered('xx'), Kaleta\Core\Language::isOffered('pt-br'), Kaleta\Core\Language::isOffered('EN'),
    Kaleta\Core\Language::offeredOrDefault('de'), Kaleta\Core\Language::offeredOrDefault('xx'), Kaleta\Core\Language::offeredOrDefault(null), Kaleta\Core\Language::offeredOrDefault(['en']), Kaleta\Core\Language::offeredOrDefault("en' OR 1=1")],
    [true, false, true, false, 'de', '', '', '', '']);
check('3.10 Language::fromForeign – Joomla pt-BR, a locale en_GB and a Drupal langcode keep the longest part Kaleta offers (§2 step 2); unknown ones are dropped', array_map(fn (string $t): string => Kaleta\Core\Language::fromForeign($t),
    ['pt-BR', 'en_GB', 'de', 'DE-at', 'xx-YY', '*', '', 'zh-Hant-TW', 'en-US', 'pt-PT', 'sr-Latn']), ['pt-br', 'en-gb', 'de', 'de-at', '', '', '', 'zh-hant', 'en', 'pt', 'sr']);
check('3.9 Language::splitPrefix – the language prefix of a path only for a code Kaleta offers (the router then asks additional())', [
    Kaleta\Core\Language::splitPrefix('/en/kontakt'), Kaleta\Core\Language::splitPrefix('/en'), Kaleta\Core\Language::splitPrefix('/en/'), Kaleta\Core\Language::splitPrefix('/de/sluzby/web'), Kaleta\Core\Language::splitPrefix('/kontakt'),
    Kaleta\Core\Language::splitPrefix('/xx/kontakt'), Kaleta\Core\Language::splitPrefix('/pt-br/x'), Kaleta\Core\Language::splitPrefix('/english/x'), Kaleta\Core\Language::splitPrefix('en/x'), Kaleta\Core\Language::splitPrefix("/en/a\nb"),
    Kaleta\Core\Language::splitPrefix('/pt-BR/x'), Kaleta\Core\Language::splitPrefix('/zh-hant'), Kaleta\Core\Language::splitPrefix('/pt-xx/x')],
    [['en', '/kontakt'], ['en', '/'], ['en', '/'], ['de', '/sluzby/web'], null, null, ['pt-br', '/x'], null, null, ['en', "/a\nb"], null, ['zh-hant', '/'], null]);
check('3.9 Language::settingKey – per-language settings keys (site_name_en, nazev_webu_de) only with a code Kaleta offers', [
    Kaleta\Core\Language::settingKey('site_name_en', ['site_name', 'site_description']), Kaleta\Core\Language::settingKey('site_description_de', ['site_name', 'site_description']),
    Kaleta\Core\Language::settingKey('site_name_xx', ['site_name']), Kaleta\Core\Language::settingKey('site_name', ['site_name']), Kaleta\Core\Language::settingKey('site_name_en', ['nazev_webu']),
    Kaleta\Core\OldSettingsKeys::current('nazev_webu_en'), Kaleta\Core\OldSettingsKeys::current('nazev_webu_xx'), Kaleta\Admin\Modules\Settings::verifyValue('nazev_webu_en', ' Northfield ')],
    [['site_name', 'en'], ['site_description', 'de'], null, null, null, 'site_name_en', 'nazev_webu_xx', 'Northfield']);
// the patterns of 3.8 and older read two letters on their own (22 places); now every language code goes through the helpers above
$ownLanguagePatterns = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (str_ends_with((string) $file, '.php') && substr_count((string) file_get_contents((string) $file), '[a-z]{2}') > 0) {
        $ownLanguagePatterns[] = basename((string) $file);
    }
}
sort($ownLanguagePatterns);
check('3.9: no two-letter language pattern of its own in system/src – only the day names (Front\Company), the Mailchimp data centre (Core\Newsletter) and Amazon SES regions (Core\MailServices) use [a-z]{2}', $ownLanguagePatterns, ['Company.php', 'MailServices.php', 'Newsletter.php']);
// the language columns: VARCHAR(35) ASCII in the schema, and migration 0083 widens exactly those
$schema39 = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
preg_match_all('/CREATE TABLE (\w+) \((.*?)\n\) ENGINE/s', $schema39, $schemaTables39, PREG_SET_ORDER);
$languageColumns39 = [];
foreach ($schemaTables39 as [, $table, $body]) {
    foreach (preg_split('/\n/', $body) ?: [] as $line) {
        if (preg_match('/^\s+(jazyk|language)\s+(.+?)\s+NOT NULL/', $line, $c)) {
            $languageColumns39[$table . '.' . $c[1]] = $c[2];
        }
    }
}
preg_match_all('/ALTER TABLE (\w+) MODIFY (\w+) VARCHAR\(35\) CHARACTER SET ascii/', (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0083-language-tags-and-slugs.sql'), $widened39, PREG_SET_ORDER);
$widened39 = array_map(fn (array $m): string => $m[1] . '.' . $m[2], $widened39);
$widened39[] = 'ka_stitky.jazyk'; // 3.14: migration 0095 adds the tags' language column in the same type
$widened39[] = 'ka_contacts.language'; // 3.28: migration 0114 creates the contacts' language column in the same type
$widened39[] = 'ka_applications.language'; // 3.34: migration 0119 creates the applications' language column in the same type
$widened39[] = 'ka_quotes.language'; // 3.38: migration 0123 creates the quotes' language column in the same type
sort($widened39);
$schemaLanguageColumns39 = array_keys($languageColumns39);
sort($schemaLanguageColumns39);
check('[source-pin] 3.9: every language column is VARCHAR(35) CHARACTER SET ascii in schema.sql (18 of them with the tags\' of 3.14, the contacts\' of 3.28, the applications\' of 3.34 and the quotes\' of 3.38) and migrations 0083, 0095, 0114, 0119 and 0123 make the same ones', [
    array_unique(array_values($languageColumns39)), count($languageColumns39), $widened39 === $schemaLanguageColumns39, str_contains($schema39, 'CHAR(2)')],
    [['VARCHAR(35) CHARACTER SET ascii'], 18, true, false]);
$slugMigrations39 = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0083-language-tags-and-slugs.sql') . (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0095-tag-language.sql');
// 3.19: schema.sql has no global key any more (migration 0106 drops it on every site that can switch), only the per-language
// one and a plain key on seo_link for the lookups in any version (migration 0105)
$plainKeys319 = ['stranky' => 'ix_stranky_seo', 'novinky' => 'ix_clanky_seo', 'kategorie' => 'ix_topic_seo', 'stitky' => 'ix_stitky_seo'];
$keyMigration319 = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0105-slug-lookup-keys.sql');
check('[source-pin] 3.9/3.19: pages, news, categories and tags have the per-language slug key (schema, migrations 0083, 0095), no global one in schema.sql, and a plain key on seo_link (0105)', array_map(
    fn (array $t): bool => !str_contains($schema39, 'UNIQUE KEY ' . $t[1] . ' (seo_link)') && str_contains($schema39, 'UNIQUE KEY ' . $t[2] . ' (jazyk, seo_link)')
        && str_contains($slugMigrations39, 'ADD UNIQUE KEY ' . $t[2] . ' (jazyk, seo_link)'), Kaleta\Core\Slug::TABLES)
    + array_map(fn (string $key): bool => (bool) preg_match('/^\s+KEY ' . $key . ' \(seo_link\)/m', $schema39) && str_contains($keyMigration319, ' ADD KEY ' . $key . ' (seo_link);'), $plainKeys319),
    ['stranky' => true, 'novinky' => true, 'kategorie' => true, 'stitky' => true]);
// the "is the slug taken?" checks and the lookups by slug follow the setting
Kaleta\Core\Slug::setPerLanguage(false);
$slugsOff39 = [Kaleta\Core\Slug::scope(null, 'en'), Kaleta\Core\Slug::redirectPath(null, 'novinky/stary', 'en'), Kaleta\Core\Slug::redirectPath(null, 'stary', '')];
Kaleta\Core\Slug::setPerLanguage(true);
$slugsOn39 = [Kaleta\Core\Slug::scope(null, 'en'), Kaleta\Core\Slug::scope(null, '', 'p.jazyk'), Kaleta\Core\Slug::redirectPath(null, 'novinky/stary', 'en'), Kaleta\Core\Slug::redirectPath(null, '/stary', 'en'), Kaleta\Core\Slug::redirectPath(null, 'stary', '')];
$healOn39 = [Kaleta\Core\LinkHealing::rewrite('/en/stare', 'stare', 'nove', '', false), Kaleta\Core\LinkHealing::rewrite('/stare/', 'stare', 'nove', '', false),
    Kaleta\Core\LinkHealing::rewrite('/en/stare#a', 'en/stare', 'en/nove', '', false), Kaleta\Core\LinkHealing::rewrite('/stare', 'en/stare', 'en/nove', '', false),
    Kaleta\Core\LinkHealing::html('<a href="/stare">cs</a> <a href="/en/stare">en</a>', 'stare', 'nove', '', false)];
Kaleta\Core\Slug::setPerLanguage(null);
check('3.9 Slug::scope / redirectPath – off: global checks and redirects as before; on: per language version, redirects with the prefix', [$slugsOff39, $slugsOn39], [
    [['', []], 'novinky/stary', 'stary'],
    [[' AND jazyk = ?', ['en']], [' AND p.jazyk = ?', ['']], 'en/novinky/stary', 'en/stary', 'stary']]);
check('3.9 LinkHealing with slugs per language: the default version\'s redirect heals only links without a prefix, a version\'s own redirect only its links', $healOn39,
    [null, '/nove/', '/en/nove#a', null, '<a href="/nove">cs</a> <a href="/en/stare">en</a>']);
check('3.9 LinkHealing without slugs per language: a link with any language prefix is healed, as before', Kaleta\Core\LinkHealing::rewrite('/en/stare', 'stare', 'nove'), '/en/nove');
$systemWords39 = array_values(array_filter([...array_keys(Kaleta\Core\Routes::SYSTEM_PATHS), ...array_values(Kaleta\Core\Routes::SYSTEM_PATHS)],
    fn (string $w): bool => preg_match('/^[a-z0-9-]+$/D', $w) === 1)); // stav.json and _komentar can never be a slug
check('3.9 Pages::slugReserved – system addresses (Routes::SYSTEM_PATHS, both forms) and language codes stay reserved in every language version', array_map(
    fn (string $s): bool => Kaleta\Admin\Modules\Pages::slugReserved($s, null), [...$systemWords39, 'en', 'de', 'kontakt']),
    [...array_fill(0, count($systemWords39), true), true, true, false]);
// every lookup of a page, news item or category by its slug names the language (or prefers the version shown); the few
// that stay global say why here – a new one fails until it is language-aware or listed (design §3, "about 30 places")
$globalSlugLookups39 = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    foreach (str_ends_with((string) $file, '.php') ? (file((string) $file) ?: []) : [] as $line) {
        // 3.19: the English placeholders ({pages}, {news}, {categories}, {tags}) name the same tables
        if (preg_match('/\{(stranky|novinky|kategorie|stitky|pages|news|categories|tags)\}[^\']*seo_link (=|IN)/', $line, $m) === 1
            && preg_match('/jazyk = \?|jazyk = \'\'|\$sameLanguage|ORDER BY (c\.)?jazyk/', $line) !== 1) {
            $globalSlugLookups39[] = basename((string) $file) . ':' . $m[1];
        }
    }
}
sort($globalSlugLookups39);
check('3.9: slug lookups without the language are only the global ones on purpose', $globalSlugLookups39, [
    'Audit.php:novinky',     // a news item of another version still answers there (Kernel redirects to its own version)
    'Blueprint.php:stranky', // does the site have a page with one of the suggested slugs at all
    'Links.php:novinky',     // the same as Audit for the broken-link check
    'Routes.php:stranky', 'Routes.php:stranky', 'Routes.php:stranky', // the news slug and the English system paths are one for all versions
    'WpImport.php:stranky',  // a collection's slug is taken in every version (/<collection> and /en/<collection>)
]);
