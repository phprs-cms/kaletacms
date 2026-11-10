<?php

declare(strict_types=1);

/* ---------- 3.15 (N10): files from a drafts-only connection wait for approval (Core\MediaStaging) ---------- */
use Kaleta\Core\MediaStaging as Staging315;
check('3.15 MediaStaging::requestedPath – a media file and its variants; never another folder, a dot path, a script or a foreign extension', array_map(Staging315::requestedPath(...), [
    '/media/2026/10/team-a1b2c3.jpg', '/media/2026/10/team-a1b2c3-nahled.png.webp', '/media/2026/10/price-list-0f0f0f.pdf', '/media/2026/10/logo-abcdef.svg',
    '/media/../config.php', '/media/2026/10/shell-a1b2c3.php', '/media/2026/10/x.php.jpg', '/media/ikona-32.png', '/storage/staging/2026/10/team-a1b2c3.jpg', '/media/2026/10/Team-A1.jpg']),
    ['media/2026/10/team-a1b2c3.jpg', 'media/2026/10/team-a1b2c3-nahled.png.webp', 'media/2026/10/price-list-0f0f0f.pdf', 'media/2026/10/logo-abcdef.svg', null, null, null, null, null, null]);
check('3.15 MediaStaging::stem and absolute – one upload with all its variants; the staged copy lies outside the web root', [
    array_map(Staging315::stem(...), ['media/2026/10/team-a1b2c3.jpg', 'media/2026/10/team-a1b2c3-1200.jpg.avif', 'media/2026/10/team-a1b2c3-nahled.png', 'media/2026/10/pricing-1200-a1b2c3.pdf']),
    substr(Staging315::absolute('media/2026/10/team-a1b2c3.jpg', true), strlen(KALETA_ROOT)), substr(Staging315::absolute('media/2026/10/team-a1b2c3.jpg', false), strlen(KALETA_ROOT))],
    [['media/2026/10/team-a1b2c3', 'media/2026/10/team-a1b2c3', 'media/2026/10/team-a1b2c3', 'media/2026/10/pricing-1200-a1b2c3'],
        '/storage/staging/2026/10/team-a1b2c3.jpg', '/media/2026/10/team-a1b2c3.jpg']);
// the promotion scan: a build in JSON (escaped slashes), HTML with the site URL, a variant – each names its staged upload once
$rows315 = [['ido' => 1, 'obr_poloha' => 'media/2026/10/team-a1b2c3.jpg', 'staged_at' => '2026-10-19 10:00:00'],
    ['ido' => 2, 'obr_poloha' => 'media/2026/10/menu-0f0f0f.pdf', 'staged_at' => '2026-10-19 10:00:00'],
    ['ido' => 3, 'obr_poloha' => 'media/2026/10/hall-123456.png', 'staged_at' => null],
    ['ido' => 4, 'obr_poloha' => 'media/2026/09/unused-fedcba.jpg', 'staged_at' => '2026-10-01 10:00:00']];
$stems315 = Staging315::referencedStems('{"deti":[{"typ":"obrazek","obsah":{"src":"media\/2026\/10\/team-a1b2c3-1200.jpg"}}]}',
    '<p><a href="https://www.example.com/media/2026/10/menu-0f0f0f.pdf">Menu</a> <img src="/media/2026/10/hall-123456.png.webp"></p>');
check('3.15 MediaStaging promotion scan – referenced stems and the staged rows they name (a public row and an unused one stay out)', [
    $stems315, array_column(Staging315::matching($rows315, $stems315), 'ido'), Staging315::matching($rows315, []), Staging315::referencedStems('<p>no files</p>')],
    [['media/2026/10/team-a1b2c3', 'media/2026/10/menu-0f0f0f', 'media/2026/10/hall-123456'], [1, 2], [], []]);
check('3.15 MediaStaging::access – the routing decision: signed in or a draft preview see it, published content makes it public, the rest gets 404', [
    Staging315::access(true, false, false), Staging315::access(false, true, false), Staging315::access(false, false, true), Staging315::access(false, false, false), Staging315::access(true, true, true)],
    ['serve', 'serve', 'publish', 'none', 'serve']);
$headers315 = array_map(Staging315::headers(...), ['media/2026/10/logo-abcdef.svg', 'media/2026/10/offer-abcdef.docx', 'media/2026/10/menu-0f0f0f.pdf', 'media/2026/10/team-a1b2c3.jpg.webp']);
check('3.15 MediaStaging::headers – what media/ has: nosniff everywhere, the SVG sandbox, documents as a download; never cached nor indexed', [
    array_column($headers315, 'X-Content-Type-Options'), str_contains($headers315[0]['Content-Security-Policy'] ?? '', 'sandbox'), $headers315[1]['Content-Disposition'] ?? null,
    isset($headers315[2]['Content-Disposition']), array_column($headers315, 'Content-Type'), array_unique(array_column($headers315, 'Cache-Control'))],
    [['nosniff', 'nosniff', 'nosniff', 'nosniff'], true, 'attachment', false, ['image/svg+xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/pdf', 'image/webp'], ['private, no-store']]);
check('3.15 MediaStaging::buildClasses – the classes a build uses, nested too (a class with a waiting background image counts once a published build uses it)',
    Staging315::buildClasses('{"v":1,"deti":[{"typ":"sekce","tridy":["hero","tmave"],"deti":[{"typ":"nadpis","tridy":["hero"]},{"typ":"text","obsah":{"text":"\"karta\""}}]}]}'), ['hero', 'tmave']);
check('3.15 the background job that publishes or removes waiting files is listed, and upload_file stays a draft tool (N10 closed by staging)', [
    Kaleta\Core\Scheduler::JOBS['media_staging'][0] ?? null, Kaleta\Core\Scheduler::JOBS['media_staging'][1] ?? null, Kaleta\Mcp\Catalog::TOOLS['upload_file'][0], Staging315::DAYS], [3600, 'any', 'draft', 30]);
// N42: the vault key – the derivation of 3.14 stays (old sites read their data), config.php gets the key safely
$n42Key = str_repeat('ab', 32);
$n42Var = "<?php\n/**\n * Kaleta - configuration.\n */\n\ndefine('KALETA_AI_URL', 'https://x.example');\nreturn array (\n  'db' => \n  array (\n    'host' => 'localhost',\n  ),\n  'debug' => false,\n);\n";
$n42Short = "<?php\n// return [ in a comment\nreturn [\n    'db' => ['host' => 'localhost'],\n];\n";
$n42Added = Kaleta\Core\Vault::withKey($n42Var, $n42Key);
check('3.15 N42: Vault::withKey adds the key inside the returned array (var_export and short syntax), keeps the other lines, refuses a second key or a broken file', [
    $n42Added !== null && str_contains($n42Added, "return array (\n    'vault_key' => '" . $n42Key . "',") && str_contains($n42Added, "define('KALETA_AI_URL'"),
    str_contains((string) Kaleta\Core\Vault::withKey($n42Short, $n42Key), "return [\n    'vault_key' => '" . $n42Key . "',"),
    Kaleta\Core\Vault::withKey((string) $n42Added, $n42Key), Kaleta\Core\Vault::withKey("<?php\nreturn [\n", $n42Key), Kaleta\Core\Vault::withKey("<?php\n\$x = 1;\n", $n42Key)],
    [true, true, null, null, null]);
$n42File = sys_get_temp_dir() . '/kaleta-n42-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($n42File, $n42Short);
$n42Written = Kaleta\Core\Vault::writeConfig($n42Key, $n42File);
$n42Config = include $n42File;
chmod($n42File, 0444);
$n42ReadOnly = Kaleta\Core\Vault::writeConfig(str_repeat('cd', 32), $n42File);
chmod($n42File, 0644);
unlink($n42File);
check('3.15 N42: Vault::writeConfig writes a config.php that returns the key; a read-only file is left alone', [$n42Written, $n42Config['vault_key'] ?? null, $n42Config['db']['host'] ?? null, $n42ReadOnly],
    [true, $n42Key, 'localhost', false]);
// N315-2: the key goes only into the one array config.php returns at its top level; anything ambiguous is refused, and the
// written file is read back – a file that returns another key is put back as it was
$n315Configs = [
    'if' => "<?php\nif (getenv('APP_ENV') === 'staging') {\n    return [\n        'db' => ['host' => 'staging'],\n    ];\n}\nreturn [\n    'db' => ['host' => 'localhost'],\n];\n",
    'closure' => "<?php\n\$helper = static function (): array {\n    return [\n        'x' => 1,\n    ];\n};\nreturn [\n    'db' => ['host' => 'localhost'],\n];\n",
    'bare if' => "<?php\nif (getenv('X')) return ['db' => 1];\nreturn ['db' => 2];\n",
    'else' => "<?php\nif (getenv('X')) { \$a = 1; } else return ['db' => 2];\n",
    'two' => "<?php\nreturn ['db' => 1];\nreturn ['db' => 2];\n",
    'call' => "<?php\nreturn array_merge(['db' => 1], []);\n",
];
$n315Added = array_map(fn (string $c): ?string => Kaleta\Core\Vault::withKey($c, $n42Key), $n315Configs);
check('3.15 N315-2: Vault::withKey – only the one top-level return gets the key (a closure before it keeps its own array); an if, an unbraced if or else, two returns or a call are refused', [
    $n315Added['if'], str_contains((string) $n315Added['closure'], "};\nreturn [\n    'vault_key' => '" . $n42Key . "'") && str_contains((string) $n315Added['closure'], "    return [\n        'x' => 1,"),
    $n315Added['bare if'], $n315Added['else'], $n315Added['two'], $n315Added['call']],
    [null, true, null, null, null, null]);
$n315File = sys_get_temp_dir() . '/kaleta-n315-' . bin2hex(random_bytes(3)) . '.php';
$n315Spread = "<?php\n\$extra = ['vault' . '_key' => str_repeat('c', 64)];\nreturn [\n    'db' => ['host' => 'localhost'],\n    ...\$extra,\n];\n";
file_put_contents($n315File, $n315Spread);
$n315SpreadWritten = Kaleta\Core\Vault::writeConfig($n42Key, $n315File); // the parser accepts it, the read-back sees another key
$n315SpreadKept = file_get_contents($n315File) === $n315Spread;
file_put_contents($n315File, "<?php\ndefine('KALETA_N315_TEST', 1);\nreturn ['db' => ['host' => 'localhost']];\n"); // one line, a constant defined again on the read-back
$n315LineWritten = Kaleta\Core\Vault::writeConfig($n42Key, $n315File);
$n315LineConfig = Kaleta\Core\Vault::loadConfig($n315File);
$n315Yields = [Kaleta\Core\Vault::yieldsKey($n315File, $n42Key), Kaleta\Core\Vault::yieldsKey($n315File, str_repeat('cd', 32))];
unlink($n315File);
check('3.15 N315-2: Vault::writeConfig reads the file back – one that returns another key is put back unchanged; a one-line array works; yieldsKey says which key the file gives', [
    $n315SpreadWritten, $n315SpreadKept, $n315LineWritten, $n315LineConfig['vault_key'] ?? null, $n315LineConfig['db']['host'] ?? null, $n315Yields],
    [false, true, true, $n42Key, 'localhost', [true, false]]);
// N315-3: the next major only from a confirmed newest release of the line – it fails closed
$n315State = ['nastaveno' => true, 'nova' => null, 'vyzaduje_php' => null, 'chyba' => null, 'stale' => false, 'refused' => false];
$n315Blocked = fn (array $change): ?string => Kaleta\Core\Updater::majorBlocker($change + $n315State, '3.15.0');
check('3.15 N315-3: Updater::majorBlocker – only a fresh, clean answer without a newer 3.x lets 4.0 install; a newer 3.x for a newer PHP, a failing, refused or stale source, or none blocks it', [
    $n315Blocked([]), $n315Blocked(['nova' => ['verze' => '3.15.1']]), $n315Blocked(['vyzaduje_php' => ['verze' => '3.15.1', 'min_php' => '8.5', 'bezpecnostni' => false]]),
    str_contains((string) $n315Blocked(['chyba' => 'HTTP 500']), '3.x'), $n315Blocked(['stale' => true]) !== null, $n315Blocked(['refused' => true]) !== null, $n315Blocked(['nastaveno' => false]) !== null],
    [null, 'Install version 3.15.1 first – a new major version is installed only from the newest release of the current one.',
        'Install version 3.15.1 first – it needs PHP 8.5 or newer, and a new major version is installed only from the newest release of the current one.', true, true, true, true]);
// N315-1, N315-4, N315-5: what the promotion scan reads, and where it runs
$n315From = implode(' | ', array_column(Staging315::PUBLISHED, 0));
check('3.15 N315-1/N315-4: the promotion scan reads every public place (category texts, item descriptions, document versions, booking services, menus, facts, the author box, social drafts) and never the shared classes', [
    str_contains($n315From, '{tridy}'), array_map(fn (string $t): bool => str_contains($n315From, $t), ['{collection_category_texts}', '{document_versions}', '{booking_services}', '{menu}', '{facts}', '{uzivatele}', '{social_drafts}']),
    str_contains(Staging315::PUBLISHED[2][1], 'popis'), str_contains(Staging315::PUBLISHED[0][1], 'kod_hlavicky')],
    [false, [true, true, true, true, true, true, true], true, true]);
$n315Idents = array_map(fn (string $class): string => $class::IDENT, Kaleta\Admin\Kernel::MODULES);
check('3.15 N315-5: only the sections that show content on the site look for waiting files after a change (each is a real section; Enquiries, Users of the change log… never pay for the scan)', [
    array_values(array_diff(Kaleta\Admin\Kernel::PUBLISHING_MODULES, $n315Idents)), array_values(array_intersect(['enquiries', 'connectors', 'whistleblowing', 'subscribers', 'stats', 'notebook', 'requests'], Kaleta\Admin\Kernel::PUBLISHING_MODULES)),
    Staging315::CHECK_SECONDS >= 60],
    [[], [], true]);
// N315-12: after a restore, tables left under the other naming scheme are renamed; a table under both names is left and named
[$n315Renames, $n315Both] = Kaleta\Core\Backup::alignment(['ka_novinky' => true, 'ka_stranky' => true, 'ka_pages' => true, 'ka_settings' => true, 'ka_media' => true, 'ka_ext_shop_orders' => true], 'ka_', false);
[$n315RenamesEn, $n315BothEn] = Kaleta\Core\Backup::alignment(['ka_novinky' => true, 'ka_stranky' => true, 'ka_pages' => true, 'ka_settings' => true], 'ka_', true);
check('3.15 N315-12: Backup::alignment – a table only under the other scheme is renamed to the one the site uses, one under both names stays (and is named); unmapped and add-on tables are left alone', [
    $n315Renames, $n315Both, $n315RenamesEn, $n315BothEn],
    [['ka_settings' => 'ka_nastaveni'], ['ka_stranky'], ['ka_novinky' => 'ka_news'], ['ka_pages']]);
check('3.15 N315-12: an add-on install.sql on a renamed database names the English core table (the Migration::statements rewrite)',
    Kaleta\Core\Migration::statements("CREATE TABLE IF NOT EXISTS ka_ext_shop_orders (id INT, page INT, CONSTRAINT fk_ext_shop_page FOREIGN KEY (page) REFERENCES ka_stranky (ids));", 'ka_', true),
    ['CREATE TABLE IF NOT EXISTS ka_ext_shop_orders (id INT, page INT, CONSTRAINT ka_fk_ext_shop_page FOREIGN KEY (page) REFERENCES ka_pages (ids))']);
check('3.15 N315-10: the Enquiries badge and the Dashboard leave spam out, as the list does', Kaleta\Admin\Modules\Enquiries::NOT_SPAM, "kategorie <> 'spam'");
$n42Old = Kaleta\Core\Vault::derive('legacy-secret', 'connectors');
$n42Sealed = Kaleta\Core\Vault::seal($n42Old, 'client-secret');
$n42New = Kaleta\Core\Vault::derive($n42Key, 'connectors');
check('3.15 N42: Vault – the derivation of 3.14 (kaleta-<purpose>|base), re-encryption to the new key, unreadable values stay, the check value is not the key', [
    $n42Old === sodium_crypto_generichash('kaleta-connectors|legacy-secret', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES),
    Kaleta\Core\Vault::open($n42New, Kaleta\Core\Vault::reseal($n42Old, $n42New, $n42Sealed)), Kaleta\Core\Vault::reseal($n42New, $n42Old, $n42Sealed), Kaleta\Core\Vault::reseal($n42Old, $n42New, null),
    Kaleta\Core\Vault::derive($n42Key, 'connectors') !== Kaleta\Core\Vault::derive($n42Key, 'whistleblowing'), strlen(Kaleta\Core\Vault::check($n42Key)), str_contains(Kaleta\Core\Vault::check($n42Key), substr($n42Key, 0, 8))],
    [true, 'client-secret', null, null, true, 32, false]);
check('3.15 N42: the line for config.php and every state text never show more than the line itself', [
    Kaleta\Core\Vault::configLine($n42Key) === "'vault_key' => '" . $n42Key . "', // encrypts connector secrets and whistleblowing reports – keep it with your backups",
    count(array_unique(array_map(Kaleta\Core\Vault::describe(...), ['config', 'database', 'pending', 'missing'])))], [true, 4]);
// N44: the change log keeps both texts within its 255 characters
check('3.15 N44: ClaudeInstructions::logText – both texts, whitespace folded, long ones shortened to fit the change log', [
    Kaleta\Core\ClaudeInstructions::logText('', "Say renovation.\nNever reconstruction."), mb_strlen(Kaleta\Core\ClaudeInstructions::logText(str_repeat('a', 4000), str_repeat('b', 4000))) <= 255],
    ['before: (empty) → now: «Say renovation. Never reconstruction.»', true]);
