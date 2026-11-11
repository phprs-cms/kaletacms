<?php

declare(strict_types=1);

use Kaleta\Mcp\Catalog;

/* ---------- 3.35 B3 (N104): only a full connection makes the site download an address ---------- */
check('3.35 B3 N104: the network rule per connection level – full and no connection download, drafts and read never', [
    Catalog::mayFetch(null), Catalog::mayFetch(['access' => 'full']), Catalog::mayFetch(['access' => 'drafts']), Catalog::mayFetch(['access' => 'read']),
], [true, true, false, false]);
check('3.35 B3 N104: the tools that read the network are full-only – migration_report and read_old_page hidden from drafts and read, the importers are writes', [
    array_map(fn (string $tool): array => [Catalog::allows('full', $tool), Catalog::allows('drafts', $tool), Catalog::allows('read', $tool)], ['migration_report', 'read_old_page', 'import_website', 'import_wordpress']),
    // upload_file and save_collection_items stay drafts tools – they refuse only the download by address
    [Catalog::allows('drafts', 'upload_file'), Catalog::allows('drafts', 'save_collection_items'), Catalog::allows('read', 'upload_file')],
    Catalog::NEEDS_REFUSAL['migration_report'],
], [array_fill(0, 4, [true, false, false]), [true, true, false], 'The migration report reads the old site, so it runs only over a connection with full access.']);
Kaleta\Extension\Registry::get()->addTool('ext_t335_fetch_feed', 'Reads a feed from another site.', ['properties' => []], 'read', fn (array $a): string => '', 'admin', '', true);
Kaleta\Extension\Registry::get()->addTool('ext_t335_local_count', 'Counts something on the site.', ['properties' => []], 'read', fn (array $a): string => '', 'admin');
check('3.35 B3 N104: an add-on tool that declares openWorld is offered to full connections only; its other read tools stay for every level', [
    Catalog::allows('full', 'ext_t335_fetch_feed'), Catalog::allows('drafts', 'ext_t335_fetch_feed'), Catalog::allows('read', 'ext_t335_fetch_feed'),
    Catalog::allows('read', 'ext_t335_local_count'), Catalog::allows('drafts', 'ext_t335_local_count'),
], [true, false, false, true, true]);
$i335cTools = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php');
$i335cBatch = (string) file_get_contents(KALETA_SYSTEM . '/src/Builder/ItemBatch.php');
$i335cMigration = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/MigrationTools.php');
check('3.35 B3 N104: upload_file refuses a url before any request, media reuse comes before the rule, migration_report checks it in code too', [
    strpos($i335cTools, 'Catalog::mayFetch($this->app->auth()->connection())') < strpos($i335cTools, '(new \Kaleta\Core\ImageDownloader($url))->download($url, false)'),
    strpos($i335cBatch, '$reused = self::mediaFromMap($app, $url);') < strpos($i335cBatch, 'Catalog::mayFetch($app->auth()->connection())'),
    strpos($i335cBatch, 'Catalog::mayFetch($app->auth()->connection())') < strpos($i335cBatch, '(new ImageDownloader($url))->download($url, $image)'),
    (bool) preg_match('/function toolMigrationReport\(.*?mayFetch\(.*?new ImageDownloader/s', $i335cMigration),
    str_contains((string) json_encode(array_values(array_filter(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), fn (array $t): bool => $t['name'] === 'upload_file'))[0]['inputSchema']['properties']['url'] ?? []), 'full access only'),
], [true, true, true, true, true]);
