<?php

declare(strict_types=1);

/* ---------- 3.0: the extension API (Extension\Registry, Extension\Api) ---------- */
check('3.0 Registry::satisfies – version requirements of add-ons', [
    Kaleta\Extension\Registry::satisfies('3.0.0', '>=3.0'), Kaleta\Extension\Registry::satisfies('3.2.1', '>=3.0 <4.0'), Kaleta\Extension\Registry::satisfies('4.0.0', '>=3.0 <4.0'),
    Kaleta\Extension\Registry::satisfies('3.1.0', '^3'), Kaleta\Extension\Registry::satisfies('4.1.0', '^3'), Kaleta\Extension\Registry::satisfies('2.16.0', '3.0'), Kaleta\Extension\Registry::satisfies('3.0.0', ''),
    Kaleta\Extension\Registry::satisfies('3.0.0', 'banana')],
    [true, true, false, true, false, false, true, false]);
$reg = Kaleta\Extension\Registry::get();
$reg->addToken('unit', 'hi', fn (array $a): string => '<b>' . htmlspecialchars($a['name'] ?? '?') . '</b>');
$reg->addToken('unit', 'boom', fn (array $a): string => throw new RuntimeException('x'));
$reg->addFilter('footer', fn (string $h): string => $h . '[a]');
$reg->addFilter('footer', fn (string $h): string => throw new RuntimeException('x'));
$reg->addFilter('footer', fn (string $h): string => $h . '[b]');
check('3.0 add-on tokens and filters: attributes in quotes (3.3.2: never in escaped quotes – that is how a visitor\'s text looks), a failing token prints nothing, a failing filter is skipped, unknown tokens stay', [
    Kaleta\Extension\Registry::fillTokens('<p>{{ext.unit.hi name="Jana"}} {{ext.unit.hi name=&quot;Petr&quot;}} {{ext.unit.boom}} {{ext.other.x}}</p>'), Kaleta\Extension\Registry::applyFilter('footer', '')],
    ['<p><b>Jana</b> {{ext.unit.hi name=&quot;Petr&quot;}}  {{ext.other.x}}</p>', '[a][b]']);
$apiErrors = [];
$api = new Kaleta\Extension\Api($reg, 'unit', new Kaleta\Core\App(['db' => []], new Kaleta\Core\Request([], [], [])));
foreach ([fn () => $api->filter('body', fn ($h) => $h), fn () => $api->mcpTool('x', 'd', [], 'admin', fn () => 1), fn () => $api->token('Bad Name', fn () => '')] as $call) {
    try { $call(); } catch (InvalidArgumentException $e) { $apiErrors[] = $e->getMessage(); }
}
$api->mcpTool('greet', 'Greets.', ['properties' => []], 'write', fn (array $a): string => 'hi');
check('3.0 Api: unknown filters, access levels and names are refused; a tool is ext_<slug>_<name> with its access in the catalog', [count($apiErrors), $reg->tool('ext_unit_greet')['access'] ?? null,
    Kaleta\Mcp\Catalog::access('ext_unit_greet'), Kaleta\Mcp\Catalog::english('ext_unit_greet'), Kaleta\Mcp\Catalog::allows('read', 'ext_unit_greet'), Kaleta\Mcp\Catalog::allows('full', 'ext_unit_greet')],
    [3, 'write', 'write', 'ext_unit_greet', false, true]);
$api->mcpTool('list_orders', 'Lists orders.', ['properties' => []], 'read', fn (array $a): string => 'x');
$api->mcpTool('sync_stock', 'Syncs the stock.', ['properties' => []], 'write', fn (array $a): string => 'x', '', '<b>Sync the  stock</b>', openWorld: true, idempotent: true);
check('3.8 Api: an add-on tool gets a title (its own, or its name in words) and the hints it declares', [Kaleta\Mcp\Tools::annotations('ext_unit_list_orders'), Kaleta\Mcp\Tools::annotations('ext_unit_sync_stock'),
    Kaleta\Mcp\Tools::annotations('ext_unit_greet')['title'], Kaleta\Mcp\Server::listed(['name' => 'ext_unit_list_orders', 'description' => 'Lists orders.', 'inputSchema' => ['type' => 'object']])['title']],
    [['title' => 'List orders', 'readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
     ['title' => 'Sync the stock', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true], 'Greet', 'List orders']);

