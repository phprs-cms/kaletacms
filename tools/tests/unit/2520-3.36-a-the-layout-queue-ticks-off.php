<?php

declare(strict_types=1);

use Kaleta\Mcp\Catalog;

/* ---------- 3.36 (part A): the work queue of a move – list the imported pages whose layout waits, tick off the ones that are fine ---------- */
$q336Defs = array_column(Kaleta\Mcp\Tools::definitions(), null, 'name');
check('3.36 layout_queue: a non-essential draft-class tool in English only (not in the work set), site-neutral and idempotent, titled, with done as a list of page IDs; the admin bulk action value (the row button and the MCP access are checked in smoke 2530)', [
    Catalog::TOOLS['layout_queue'] ?? null, in_array('layout_queue', Catalog::ESSENTIALS, true), in_array('layout_queue', Catalog::WORK, true), Kaleta\Mcp\Translator::czech('layout_queue'),
    Catalog::isSiteNeutral('layout_queue'), Catalog::isIdempotent('layout_queue'), Catalog::TITLES['layout_queue'] ?? '',
    $q336Defs['layout_queue']['inputSchema']['properties']['done']['type'] ?? '', $q336Defs['layout_queue']['inputSchema']['required'] ?? null, Kaleta\Admin\Modules\Pages::REBUILT,
], [['draft', ''], false, false, 'layout_queue', true, true, 'List and tick off layouts to rebuild', 'array', [], 'rebuilt']);

check('3.36 layout_queue: the texts in Czech, German and Spanish', array_map(fn (string $code): array => Kaleta\Core\Language::runWith($code, fn (): array => [t('Layout done') !== 'Layout done',
    t('Pages taken off the list of layouts to rebuild: %d.', 2) !== 'Pages taken off the list of layouts to rebuild: 2.', t('Takes the page off the list of layouts to rebuild – nothing on the page changes.') !== 'Takes the page off the list of layouts to rebuild – nothing on the page changes.'], 'admin-'), ['cs', 'de', 'es']),
    [[true, true, true], [true, true, true], [true, true, true]]);
