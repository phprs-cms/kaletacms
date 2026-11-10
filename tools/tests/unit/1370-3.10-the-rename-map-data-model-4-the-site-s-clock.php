<?php

declare(strict_types=1);

/* ---------- 3.10: the rename map (data model §4), the site's clock (§6 step 1) and regional language tags (§2 step 2) ---------- */
// the map covers every table of schema.sql exactly, and is a bijection: no English name twice, none equal to the name another
// table has today ({news} must never mean two tables), every listed column exists and the English columns of a table are unique
$schema310 = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
preg_match_all('/CREATE TABLE ka_(\w+) \((.*?)\n\) ENGINE/s', $schema310, $schemaTables310, PREG_SET_ORDER);
$schemaColumns310 = [];
foreach ($schemaTables310 as [, $table, $body]) {
    preg_match_all('/^\s+([a-z_0-9]+)\s+[A-Z]/m', $body, $c);
    $schemaColumns310[$table] = array_values(array_diff($c[1], ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'CONSTRAINT']));
}
$names310 = Kaleta\Core\Names::map();
$tables310 = array_keys($schemaColumns310);
$mapped310 = array_keys($names310);
sort($tables310);
sort($mapped310);
check('3.10 names.php covers every table of schema.sql, and nothing else', [array_values(array_diff($tables310, $mapped310)), array_values(array_diff($mapped310, $tables310))], [[], []]);
$english310 = array_column($names310, 0);
$nameProblems310 = [];
foreach ($names310 as $table => [$english, $columns]) {
    if (preg_match('/^[a-z][a-z0-9_]*$/D', $english) !== 1) {
        $nameProblems310[] = $table . ': ' . $english . ' is not a placeholder name';
    }
    if (isset($names310[$english]) && $english !== $table) {
        $nameProblems310[] = $table . ': ' . $english . ' is the name of another table today';
    }
    foreach (array_keys($columns) as $column) {
        if (!in_array($column, $schemaColumns310[$table] ?? [], true)) {
            $nameProblems310[] = $table . '.' . $column . ' does not exist';
        }
    }
    $after = array_map(fn (string $c): string => $columns[$c] ?? $c, $schemaColumns310[$table] ?? []);
    if (count($after) !== count(array_unique($after))) {
        $nameProblems310[] = $table . ': two columns would get one English name';
    }
}
check('3.10 names.php is a bijection: English table names unique and free, listed columns exist, English columns unique per table',
    [count($english310) === count(array_unique($english310)), $nameProblems310], [true, []]);
$db310 = new Kaleta\Core\Db('mysql:host=127.0.0.1', '', '', 'ka_');
check('3.10 Db::sql: the English placeholder names the table of today, the Czech one stays, English and unknown names are as they are', [
    $db310->sql('SELECT * FROM {news} n JOIN {categories} c ON c.idt = n.tema'), $db310->sql('SELECT * FROM {novinky}'), $db310->sql('SELECT * FROM {newsletters}'),
    $db310->sql('SELECT * FROM {ext_shop_orders}'), $db310->sql('SELECT * FROM {media}'), Kaleta\Core\Names::english('stranky'), Kaleta\Core\Names::english('ext_shop_orders'),
    Kaleta\Core\Names::columns('news')['idc'] ?? null, Kaleta\Core\Names::columns('novinky')['tema'] ?? null],
    ['SELECT * FROM `ka_novinky` n JOIN `ka_kategorie` c ON c.idt = n.tema', 'SELECT * FROM `ka_novinky`', 'SELECT * FROM `ka_newsletters`',
        'SELECT * FROM `ka_ext_shop_orders`', 'SELECT * FROM `ka_media`', 'pages', 'ext_shop_orders', 'id', 'category_id']);

// N310-5: the journal knows a table by either name, so a write through 'pages' or {pages} is undone like one through 'stranky'
check('3.10 (N310-5) AgentJournal::journaled – every English name of a journaled table is journaled; English names of other tables are not',
    [array_values(array_filter(Kaleta\Core\AgentJournal::TABLES, fn (string $t): bool => !Kaleta\Core\AgentJournal::journaled(Kaleta\Core\Names::english($t)))),
        Kaleta\Core\AgentJournal::journaled('pages'), Kaleta\Core\AgentJournal::journaled('news'), Kaleta\Core\AgentJournal::journaled('audit_log'), Kaleta\Core\AgentJournal::journaled('api_tokens')],
    [[], true, true, false, false]);
// 3.15 (the bridge to 4.0): a database whose tables 4.0 renamed – both placeholders name the English table, the code keeps
// knowing the table by today's name, and migrations, the backup and the journal follow
$renamedBefore315 = Kaleta\Core\Names::renamed();
Kaleta\Core\Names::setRenamed(true);
check('3.15 Names: in a renamed database {novinky} and {news} are both `news`; table() stays today\'s name; tables 4.0 does not rename stay', [
    Kaleta\Core\Names::physical('novinky'), Kaleta\Core\Names::physical('news'), Kaleta\Core\Names::physical('newsletters'), Kaleta\Core\Names::physical('ext_shop_orders'),
    Kaleta\Core\Names::physical('stranky', false), Kaleta\Core\Names::table('pages'), Kaleta\Core\Names::table('stranky'), Kaleta\Core\Names::columns('pages')['ids'] ?? null,
    Kaleta\Core\AgentJournal::journaled('pages'), Kaleta\Core\ExportFormat::tableFromKey('news', 3)],
    ['news', 'news', 'newsletters', 'ext_shop_orders', 'stranky', 'stranky', 'stranky', 'id', true, 'novinky']);
Kaleta\Core\Names::setRenamed($renamedBefore315);
check('3.15 Names: every table 4.0 renames maps back to its name of today (the map is the only source both directions use)',
    array_values(array_filter(array_keys(Kaleta\Core\Names::map()), fn (string $t): bool => Kaleta\Core\Names::table(Kaleta\Core\Names::physical($t, true)) !== $t)), []);
check('3.15 Migration::statements: on a renamed database the files\' Czech names become the English ones, the rest (keys, add-on tables) stays', [
    Kaleta\Core\Migration::statements("ALTER TABLE ka_novinky ADD COLUMN x INT;\nCREATE TABLE ka_ext_a_b (id INT, CONSTRAINT fk_x FOREIGN KEY (id) REFERENCES ka_stranky (ids));\nUPDATE ka_nastaveni SET hodnota = 'ka_nahled' WHERE promenna = 'x';\n", 'w_', true),
    Kaleta\Core\Migration::statements("ALTER TABLE ka_novinky_stitky ADD KEY k (idc);\n", 'ka_', false)],
    [['ALTER TABLE w_news ADD COLUMN x INT', 'CREATE TABLE w_ext_a_b (id INT, CONSTRAINT w_fk_x FOREIGN KEY (id) REFERENCES w_pages (ids))', "UPDATE w_settings SET hodnota = 'w_nahled' WHERE promenna = 'x'"],
        ['ALTER TABLE ka_novinky_stitky ADD KEY k (idc)']]);
check('3.15 Backup::twin: a restored table replaces its twin under the other name; tables 4.0 does not rename have none', [
    Kaleta\Core\Backup::twin("DROP TABLE IF EXISTS `ka_novinky`;\n", 'ka_'), Kaleta\Core\Backup::twin("DROP TABLE IF EXISTS `ka_news`;\n", 'ka_'),
    Kaleta\Core\Backup::twin("DROP TABLE IF EXISTS `ka_newsletters`;\n", 'ka_'), Kaleta\Core\Backup::twin("CREATE TABLE `ka_novinky` (\n", 'ka_'),
    Kaleta\Core\Backup::twin("DROP TABLE IF EXISTS `x_novinky`;\n", 'ka_')],
    ['ka_news', 'ka_novinky', null, null, null]);
// raw SQL in core that names a table without a placeholder would miss the renamed tables: no `ka_<table>` in code strings,
// and a table name put together with the prefix goes through Db::tableName() (the one place that knows the names)
$rawTables315 = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/src', FilesystemIterator::SKIP_DOTS)) as $file315) {
    if ($file315->getExtension() !== 'php') {
        continue;
    }
    $source315 = (string) file_get_contents($file315->getPathname());
    $relative315 = substr($file315->getPathname(), strlen(KALETA_ROOT) + 1);
    foreach (token_get_all($source315) as $token315) {
        if (is_array($token315) && in_array($token315[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match_all('/\bka_([a-z][a-z0-9_]*)/', $token315[1], $hits315) > 0) {
            foreach ($hits315[1] as $hit315) {
                if (isset($names310[$hit315])) {
                    $rawTables315[] = $relative315 . ':' . $token315[2] . ' ka_' . $hit315;
                }
            }
        }
    }
    // "$db->prefix . $table" / ". 'novinky'" – Readiness only quotes a prefixed name in its advice and scans add-ons with it
    if ($relative315 !== 'system/src/Core/Readiness.php' && preg_match_all('/->prefix\s*\.\s*(\$[a-zA-Z_]|\'[a-z])/', $source315, $joined315, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($joined315[0] as [$text315, $offset315]) {
            $rawTables315[] = $relative315 . ':' . (substr_count(substr($source315, 0, $offset315), "\n") + 1) . ' ' . $text315;
        }
    }
}
check('3.15 no table name in core bypasses the placeholders or Db::tableName() (renamed tables after a rollback from 4.0)', $rawTables315, []);
// the update sources of the major lines (3.15): aktualizace.json is the 3.x line for good, 4.x has its own manifest
check('3.15 updates: each major line has its own manifest next to aktualizace.json, with a stable twin; another file name has none', [
    Kaleta\Core\Updater::manifestFile(3), Kaleta\Core\Updater::manifestFile(1), Kaleta\Core\Updater::manifestFile(4), Kaleta\Core\Updater::manifestFile(4, 'stable'), Kaleta\Core\Updater::manifestFile(3, 'stable'),
    Kaleta\Core\Updater::lineUrl(Kaleta\Core\Updater::DEFAULT_URL, 4), Kaleta\Core\Updater::lineUrl('https://mirror.example/k/aktualizace.json?t=1', 4),
    Kaleta\Core\Updater::lineUrl('https://mirror.example/k/aktualizace-4.json', 5), Kaleta\Core\Updater::lineUrl('https://mirror.example/k/aktualizace-4.json', 3),
    Kaleta\Core\Updater::lineUrl('https://mirror.example/updates.php', 4), Kaleta\Core\Updater::lineUrl('https://mirror.example/aktualizace.json.bak', 4),
    Kaleta\Core\Updater::stableUrl('https://kaletacms.com/aktualizace-4.json'), Kaleta\Core\Updater::stableUrl(Kaleta\Core\Updater::DEFAULT_URL),
    array_map(Kaleta\Core\Updater::majorOf(...), ['3.15.0', '4.0.0-rc1', '10.2.3', 'x', ''])],
    ['aktualizace.json', 'aktualizace.json', 'aktualizace-4.json', 'aktualizace-4-stable.json', 'aktualizace-stable.json',
        'https://kaletacms.com/aktualizace-4.json', 'https://mirror.example/k/aktualizace-4.json?t=1', 'https://mirror.example/k/aktualizace-5.json', 'https://mirror.example/k/aktualizace.json',
        null, null, 'https://kaletacms.com/aktualizace-4-stable.json', 'https://kaletacms.com/aktualizace-stable.json', [3, 4, 10, 0, 0]]);
$major315 = static function (?array $manifest, string $current, bool $next, string $channel = 'latest'): string {
    $c = Kaleta\Core\Updater::choose($manifest, $channel, $current, '8.4.5', $next);

    return $c['nova'] !== null ? 'offer ' . $c['nova']['verze'] : ($c['ahead_of'] !== null ? 'ahead of ' . $c['ahead_of'] : 'nothing');
};
check('3.15 updates: the regular manifest never offers another major version (not even a security one), the next-major one only the next major', [
    $major315(['verze' => '4.0.0', 'min_php' => '8.3', 'bezpecnostni' => true], '3.15.0', false), $major315(['verze' => '3.15.2', 'min_php' => '8.3'], '3.15.0', false),
    $major315(['verze' => '4.0.0', 'min_php' => '8.3', 'kanal' => 'stable'], '3.15.0', false, 'stable'), $major315(['verze' => '1.0.0', 'kanal' => 'stable'], '3.15.0', false, 'stable'),
    $major315(['verze' => '3.14.0', 'kanal' => 'stable'], '4.0.0', true, 'stable'),
    $major315(['verze' => '4.0.0', 'min_php' => '8.3'], '3.15.0', true), $major315(['verze' => '4.1.0-rc1', 'min_php' => '8.3'], '3.15.0', true),
    $major315(['verze' => '3.16.0', 'min_php' => '8.3'], '3.15.0', true), $major315(['verze' => '5.0.0', 'min_php' => '8.3'], '3.15.0', true),
    $major315(['verze' => '4.0.0', 'min_php' => '8.3'], '4.0.0', true), $major315(['verze' => '5.0.0', 'min_php' => '8.3'], '4.2.0', true)],
    ['nothing', 'offer 3.15.2', 'nothing', 'ahead of 1.0.0', 'nothing', 'offer 4.0.0', 'offer 4.1.0-rc1', 'nothing', 'nothing', 'nothing', 'offer 5.0.0']);
$now315 = (int) strtotime('2026-10-19 12:00:00');
check('3.15 updates: the prompt counts the days since the site first saw the newer release or since its signed date, whichever is earlier', [
    Kaleta\Core\Updater::behindDays(['nova' => null, 'behind_since' => $now315 - 30 * 86400], $now315),
    Kaleta\Core\Updater::behindDays(['nova' => ['verze' => '3.15.1', 'vydano' => '2026-10-17'], 'behind_since' => $now315 - 20 * 86400], $now315),
    Kaleta\Core\Updater::behindDays(['nova' => ['verze' => '3.15.1', 'vydano' => '2026-09-30'], 'behind_since' => $now315 - 86400], $now315),
    Kaleta\Core\Updater::behindDays(['nova' => ['verze' => '3.15.1', 'vydano' => ''], 'behind_since' => 0], $now315),
    Kaleta\Core\Updater::behindDays(['nova' => ['verze' => '3.15.1', 'vydano' => '2099-01-01'], 'behind_since' => $now315 - 3 * 86400], $now315),
    Kaleta\Core\Updater::BEHIND_DAYS, Kaleta\Core\Settings::DEFAULTS['update_behind_since'] ?? null, array_key_exists('update_major_cache', Kaleta\Core\Settings::DEFAULTS),
    in_array('update_major_cache', Kaleta\Core\SiteExport::SETTINGS, true) || in_array('update_behind_since', Kaleta\Core\SiteExport::SETTINGS, true)],
    [null, 20, 19, 0, 3, 14, '0', true, false]);
check('3.15 updates: release.php writes the manifest of the release\'s own line and needs --major for X.0.0; the daily check knows the major lines', [
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/release.php'), '$manifestName = Kaleta\Core\Updater::manifestFile($releaseMajor, $options[\'channel\'])'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/release.php'), "\$arg === '--major'"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/check-channel.php'), 'Updater::lineUrl($manifestUrl, $major)')],
    [true, true, true]);
// the clock: in 3.x it is the site's wall time, exactly what date() gave
$zone310 = date_default_timezone_get();
date_default_timezone_set('Europe/Prague');
$moment310 = (new DateTimeImmutable('2026-10-25 00:30:00', new DateTimeZone('UTC')))->getTimestamp(); // the first 02:30 of the DST switch
check('3.10 Clock: wall time on the site\'s clock, to and from a DATETIME column; zero, empty and impossible values are null', [
    Kaleta\Core\Clock::toDb($moment310), Kaleta\Core\Clock::toDb($moment310 + 3600), Kaleta\Core\Clock::toDb(new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC'))),
    Kaleta\Core\Clock::fromDb('2026-07-01 12:00:00')?->getTimestamp(), Kaleta\Core\Clock::fromDb('2026-07-01')?->format('Y-m-d H:i'),
    Kaleta\Core\Clock::fromDb(null), Kaleta\Core\Clock::fromDb(''), Kaleta\Core\Clock::fromDb('0000-00-00 00:00:00'), Kaleta\Core\Clock::fromDb('2026-02-30 10:00:00'), Kaleta\Core\Clock::fromDb('yesterday'),
    Kaleta\Core\Clock::zone()->getName(), Kaleta\Core\Clock::offset() === date('P'), abs(strtotime(Kaleta\Core\Clock::now()) - time()) <= 1, Kaleta\Core\Clock::today() === date('Y-m-d'),
    abs(strtotime(Kaleta\Core\Clock::nowUtc() . ' UTC') - time()) <= 1, abs(strtotime(Kaleta\Core\Clock::at('-1 hour')) - (time() - 3600)) <= 1],
    ['2026-10-25 02:30:00', '2026-10-25 02:30:00', '2026-07-01 12:00:00', (new DateTimeImmutable('2026-07-01 10:00:00', new DateTimeZone('UTC')))->getTimestamp(), '2026-07-01 00:00',
        null, null, null, null, null, 'Europe/Prague', true, true, true, true, true]);
date_default_timezone_set($zone310);
check('3.10 Health::clock: the setting, PHP and the database session agree; a zone the server does not know and a shifted session warn', Kaleta\Core\Language::runWith('en', fn (): array => [
    Kaleta\Core\Health::clock('Europe/Prague', 'Europe/Prague', 120, 120)[0], Kaleta\Core\Health::clock('Europe/Prague', 'Europe/Prague', 120, 120)[1],
    Kaleta\Core\Health::clock('Europe/Prague', 'Europe/Prague', 120, 0)[0], str_contains(Kaleta\Core\Health::clock('Europe/Prague', 'Europe/Prague', 120, 0)[1], 'UTC+00:00'),
    Kaleta\Core\Health::clock('America/Nuuk', 'Europe/Prague', 120, 120)[0], Kaleta\Core\Health::clock('', 'UTC', 0, 0)[0], Kaleta\Core\Health::clock('Asia/Kolkata', 'Asia/Kolkata', 330, -150)[1]], 'admin-'),
    ['ok', 'Europe/Prague (UTC+02:00) – PHP and the database count time the same way', 'varovani', true, 'varovani', 'ok',
        'PHP writes dates in Asia/Kolkata (UTC+05:30), but the database compares them in UTC-02:30 – scheduled publishing and time limits are off by 480 minutes. Ask the hosting whether the database connection keeps its session time zone.']);
// the 3.x classes that already write through the clock stay that way (the rest follow table by table, §6)
$clockFiles310 = [];
foreach (['AgentJournal', 'Requests', 'Facts', 'DraftComments', 'Triage', 'SocialDrafts'] as $class) {
    if (str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/' . $class . '.php'), "date('Y-m-d H:i:s'")) {
        $clockFiles310[] = $class;
    }
}
check('3.10: the classes moved to Core\Clock do not write wall time with date() again', $clockFiles310, []);

// regional language tags: offered like any language, stored lowercase, printed in the canonical case
$regional310 = array_values(array_filter(array_keys(Kaleta\Core\Language::AVAILABLE), fn (string $c): bool => str_contains($c, '-')));
check('3.10 Language::AVAILABLE offers the regional versions after their language; every code is a stored tag with a locale; CODES lists them all', [
    $regional310, array_values(array_filter(array_keys(Kaleta\Core\Language::AVAILABLE), fn (string $c): bool => !Kaleta\Core\Language::isTag($c) || !isset(Kaleta\Core\Language::AVAILABLE[Kaleta\Core\Language::base($c)])
        || preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D', Kaleta\Core\Language::AVAILABLE[$c][1]) !== 1)), Kaleta\Core\Language::CODES === implode('|', array_keys(Kaleta\Core\Language::AVAILABLE))],
    [['en-gb', 'de-at', 'pt-br', 'zh-hant'], [], true]);
check('3.10 Language::tag – the canonical BCP 47 case for lang, hreflang and feeds; two-letter codes as they are', array_map(Kaleta\Core\Language::tag(...),
    ['cs', 'en', 'pt-br', 'zh-hant', 'sr-latn-rs', 'es-419', 'en-gb', 'de-at-1996', 'en-x-private', 'yue', 'PT-BR']),
    ['cs', 'en', 'pt-BR', 'zh-Hant', 'sr-Latn-RS', 'es-419', 'en-GB', 'de-AT-1996', 'en-x-private', 'yue', 'pt-BR']);
check('3.10 Language::base / forSite – a regional source lands in the version the site has; the version itself when the site has it', [
    Kaleta\Core\Language::base('pt-br'), Kaleta\Core\Language::base('cs'), Kaleta\Core\Language::forSite('en-gb', ['cs', 'en']), Kaleta\Core\Language::forSite('en-gb', ['cs', 'en', 'en-gb']),
    Kaleta\Core\Language::forSite('en-gb', ['cs']), Kaleta\Core\Language::forSite('en', ['cs', 'en-gb']), Kaleta\Core\Language::forSite('', ['cs'])],
    ['pt', 'cs', 'en', 'en-gb', 'en-gb', 'en', '']);
Kaleta\Core\Language::set('de-at');
$deAt310 = [Kaleta\Core\Language::code(), t('Read more'), t('datum_format'), format_count(12345)];
Kaleta\Core\Language::set('en-gb');
$enGb310 = [t('Read more'), format_number(4.5), format_count(12345)];
Kaleta\Core\Language::set('pt-br');
$ptBr310 = [t('datum_format'), Kaleta\Core\Language::intlLocale() ?? 'pt_BR'];
Kaleta\Core\Language::set('cs');
check('3.10 a regional version uses the dictionary and number format of its language, and its own date format and locale without one', [$deAt310, $enGb310, $ptBr310],
    [['de-at', (require KALETA_SYSTEM . '/jazyky/de.php')['Read more'] ?? 'Read more', 'd.m.Y', '12.345'], [(require KALETA_SYSTEM . '/jazyky/en.php')['Read more'] ?? 'Read more', '4.5', '12,345'], ['d/m/Y', 'pt_BR']]);
check('3.10 a page slug cannot be a regional language code (the prefix /pt-br/ belongs to the version)', [
    Kaleta\Core\Routes::systemSlugError('pt-br') !== null, Kaleta\Core\Routes::systemSlugError('zh-hant') !== null, Kaleta\Core\Routes::systemSlugError('pt-brasil')], [true, true, null]);

