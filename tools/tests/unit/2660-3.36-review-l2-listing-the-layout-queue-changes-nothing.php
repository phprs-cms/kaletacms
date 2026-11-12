<?php

declare(strict_types=1);

// 3.36 review L2: layout_queue without done only lists – the server writes no change-log row, counts nothing against the
// hourly limit and opens no undo journal for it; list_layout_queue is the same queue as a read-class tool for any connection
use Kaleta\Mcp\Catalog;

$l2Defs = array_column(Kaleta\Mcp\Tools::definitions(), null, 'name');
check('3.36 review L2: Catalog::readsOnly – layout_queue without done, with null or an empty list only reads; with page IDs (or by another tool) it writes', [
    Catalog::readsOnly('layout_queue', []), Catalog::readsOnly('layout_queue', ['done' => null]), Catalog::readsOnly('layout_queue', ['done' => []]),
    Catalog::readsOnly('layout_queue', ['done' => [3]]), Catalog::readsOnly('layout_queue', ['done' => '[3]']), Catalog::readsOnly('save_redirect', []),
], [true, true, true, false, false, false]);
check('3.36 review L2: list_layout_queue – a non-essential read-class tool in English only, in the migration set, titled, without parameters; read connections may use it', [
    Catalog::TOOLS['list_layout_queue'] ?? null, in_array('list_layout_queue', Catalog::ESSENTIALS, true), in_array('list_layout_queue', Catalog::MIGRATION, true),
    Kaleta\Mcp\Translator::czech('list_layout_queue'), Catalog::TITLES['list_layout_queue'] ?? '', (array) ($l2Defs['list_layout_queue']['inputSchema']['properties'] ?? null),
    Catalog::allows('read', 'list_layout_queue'), Catalog::allows('read', 'layout_queue'),
], [['read', ''], false, true, 'list_layout_queue', 'List layouts to rebuild', [], true, false]);
