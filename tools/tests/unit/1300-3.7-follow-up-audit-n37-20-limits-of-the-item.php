<?php

declare(strict_types=1);

/* ---------- 3.7 follow-up audit (N37-20+): limits of the item batch, the web import and the MCP numbers ---------- */
// N37-23: a hostile robots.txt – 1 MB of wildcard rules – is cut to ROBOTS_BYTES and ROBOTS_RULES, and 2,000 links are
// checked against it in a moment (each rule was a regular expression per link before: minutes, or a skipped rule)
$hostileRobots = "User-agent: *\nCrawl-delay: 0\nDisallow: /blocked/\nAllow: /blocked/open\n";
for ($n = 0; strlen($hostileRobots) < 1024 * 1024; $n++) {
    $hostileRobots .= 'Disallow: /*x' . $n . "*y*z*w$\n";
}
$hostileStart = microtime(true);
$hostileRules = Kaleta\Core\WebImport::robots($hostileRobots);
$hostileAllowed = 0;
for ($n = 1; $n <= 2000; $n++) {
    $hostileAllowed += Kaleta\Core\WebImport::robotsAllow($hostileRules, 'https://a.cz/l/' . $n . '/') ? 1 : 0;
}
check('3.7 N37-23 WebImport::robots – a 1 MB robots.txt is cut (500 rules, noted), its first rules still hold, 2,000 links are checked in under 3 s',
    [count($hostileRules['disallow']) + count($hostileRules['allow']), $hostileRules['omezeno'], $hostileAllowed, Kaleta\Core\WebImport::robotsAllow($hostileRules, 'https://a.cz/blocked/x'),
        Kaleta\Core\WebImport::robotsAllow($hostileRules, 'https://a.cz/blocked/open/1'), Kaleta\Core\WebImport::robotsAllow($hostileRules, 'https://a.cz/q/x3/y/z/w'),
        microtime(true) - $hostileStart < 3.0, Kaleta\Core\WebImport::robots("User-agent: *\nDisallow: /x\n")['omezeno']],
    [Kaleta\Core\WebImport::ROBOTS_RULES, true, 2000, false, true, false, true, false]);
// the matcher without regular expressions reads * and $ as before – and a pattern that made PCRE give up (backtracking)
// is matched, not skipped: no rule fails open
$wild = Kaleta\Core\WebImport::robots("User-agent: *\nDisallow: /*.php$\nDisallow: /a*b*c\nDisallow: /exact$\nDisallow: /" . str_repeat('*a', 30) . "*b$\nAllow: /a1b2c3/ok\nDisallow: /%C5%BE/\n");
check('3.7 N37-23 WebImport::robotsAllow – wildcards in order, $ at the end, longest rule, encoded paths, a backtracking pattern is matched',
    array_map(fn (string $p): bool => Kaleta\Core\WebImport::robotsAllow($wild, 'https://a.cz' . $p), ['/x.php', '/x.php?a=1', '/axbxc', '/acb', '/a1b2c3/ok', '/exact', '/exact/more', '/ž/x', '/%C5%BE/y',
        '/' . str_repeat('a', 3000) . 'b', '/' . str_repeat('a', 3000)]),
    [false, true, false, true, true, false, true, true, false, false, true]);
check('3.7 N37-23/24 WebImport::notes – a cut robots.txt and the sitemaps that were not read are said, nothing otherwise', Kaleta\Core\Language::runWith('en', fn (): array => [
    Kaleta\Core\WebImport::notes(['robots' => ['omezeno' => true], 'mapy_cizi' => ['pocet' => 2, 'hostitele' => ['cdn.example', 'other.example']], 'mapy_navic' => 3]),
    Kaleta\Core\WebImport::notes(['robots' => ['omezeno' => false]])], 'admin-'),
    [['The robots.txt of the old site is very long: only its first 512 KB and 500 rules for Kaleta were read.',
        '2 sitemaps on other hosts were not read (cdn.example, other.example): only the old site’s own sitemaps are followed.', '3 more sitemaps were not read: at most 100 are read.'], []]);
// N37-27: a huge number from MCP is a clear tool error naming the parameter – never a wrong id or an error page
$numberTools = [['name' => 'migration_report', 'inputSchema' => ['properties' => ['offset' => ['type' => 'integer'], 'url' => ['type' => 'string']]]]];
check('3.7 N37-27 Mcp\Server::badNumber – a float over 2^53 anywhere, an integer parameter as text over it; sensible numbers pass', [
    Kaleta\Mcp\Server::badNumber($numberTools, 'migration_report', ['offset' => 1e20]), Kaleta\Mcp\Server::badNumber($numberTools, 'migration_report', ['offset' => '1e20']),
    Kaleta\Mcp\Server::badNumber($numberTools, 'save_collection_items', ['items' => [['id' => 5], ['id' => 1e20]]]),
    Kaleta\Mcp\Server::badNumber($numberTools, 'migration_report', ['offset' => 100, 'url' => '1e20']), Kaleta\Mcp\Server::badNumber($numberTools, 'save_build', ['build' => ['opacity' => 0.5, 'n' => 9007199254740991]])],
    ['The number in offset is too large – numbers up to 9007199254740991 are accepted. Send the value the user meant (an ID from a list tool, a count, a position).',
        'The number in offset is too large – numbers up to 9007199254740991 are accepted. Send the value the user meant (an ID from a list tool, a count, a position).',
        'The number in items.1.id is too large – numbers up to 9007199254740991 are accepted. Send the value the user meant (an ID from a list tool, a count, a position).', null, null]);
check('3.7 N37-27/28 ItemBatch::fromMcp – a huge or broken id or order is refused with the reason, unknown keys of the item are reported', [
    Kaleta\Builder\ItemBatch::fromMcp(['id' => 1e20, 'name' => 'A'])['error'], Kaleta\Builder\ItemBatch::fromMcp(['id' => '12abc', 'name' => 'A'])['error'] !== '',
    Kaleta\Builder\ItemBatch::fromMcp(['name' => 'A', 'order' => 1e12])['error'], Kaleta\Builder\ItemBatch::fromMcp(['id' => 7.0, 'order' => '-12', 'name' => 'A'])['id'],
    Kaleta\Builder\ItemBatch::fromMcp(['name' => 'A', 'publish_at' => '2027-01-01', 'zobrazit' => 1, 'values' => []])['extra']],
    ['id must be the whole number of an item (list_collection_items).', true, 'order must be a whole number from -9999 to 9999.', 7, ['publish_at', 'zobrazit']]);
// N37-25: the daily clean-up of storage/import – states and rows untouched for 14 days and dead temporary files go,
// an uploaded WordPress export and anything else stay
$purgeDir = sys_get_temp_dir() . '/kaleta-purge-' . bin2hex(random_bytes(4));
mkdir($purgeDir);
$purgeNow = time();
foreach (['polozky-0123456789abcdef.json' => 15, 'polozky-0123456789abcdef.rows.json' => 15, 'web-0123456789abcdef.json' => 20, 'parita-0123456789abcdef.json' => 30, 'stav-0123456789abcdef.json' => 15,
    'polozky-fedcba9876543210.json' => 13, 'parita-fedcba9876543210.json' => 1, 'export-2025.xml' => 400, 'nahrani-0123456789ab.tmp' => 2, 'obrazek-0123456789ab.tmp' => 0, 'import.zamek' => 100, 'notes.json' => 100] as $purgeName => $purgeDays) {
    touch($purgeDir . '/' . $purgeName, $purgeNow - $purgeDays * 86400 - 60);
}
$purgeDeleted = Kaleta\Core\WpFile::purgeOld($purgeDir, $purgeNow);
$purgeLeft = array_values(array_diff(scandir($purgeDir) ?: [], ['.', '..']));
sort($purgeLeft);
array_map('unlink', glob($purgeDir . '/*') ?: []);
rmdir($purgeDir);
check('3.7 N37-25 WpFile::purgeOld – import states, rows and reports after 14 days, temporary files after a day; exports and other files stay',
    [$purgeDeleted, $purgeLeft], [6, ['export-2025.xml', 'import.zamek', 'notes.json', 'obrazek-0123456789ab.tmp', 'parita-fedcba9876543210.json', 'polozky-fedcba9876543210.json']]);
