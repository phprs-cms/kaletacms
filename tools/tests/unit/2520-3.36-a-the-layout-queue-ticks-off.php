<?php

declare(strict_types=1);

use Kaleta\Mcp\Catalog;

/* ---------- 3.36 (part A): the work queue of a move – list the imported pages whose layout waits, tick off the ones that are fine ---------- */
$q336Defs = array_column(Kaleta\Mcp\Tools::definitions(), null, 'name');
check('3.36 layout_queue: a non-essential draft-class tool in English only (not in the work set), site-neutral and idempotent, titled, with done as a list of page IDs; the handler asks for the Pages section', [
    Catalog::TOOLS['layout_queue'] ?? null, in_array('layout_queue', Catalog::ESSENTIALS, true), in_array('layout_queue', Catalog::WORK, true), Kaleta\Mcp\Translator::czech('layout_queue'),
    Catalog::isSiteNeutral('layout_queue'), Catalog::isIdempotent('layout_queue'), Catalog::TITLES['layout_queue'] ?? '',
    $q336Defs['layout_queue']['inputSchema']['properties']['done']['type'] ?? '', $q336Defs['layout_queue']['inputSchema']['required'] ?? null,
    str_contains((string) file_get_contents(KALETA_ROOT . '/system/src/Mcp/Handlers/MigrationTools.php'), "if (!\$auth->hasModule('pages')) {\n            throw new \\DomainException('The queue of layouts to rebuild"),
], [['draft', ''], false, false, 'layout_queue', true, true, 'List and tick off layouts to rebuild', 'array', [], true]);

$q336List = (string) file_get_contents(KALETA_ROOT . '/system/views/admin/pages/list.php');
check('3.36 layout_queue: the admin – "Layout done" on each waiting row and as a bulk action when some page waits, through the one bulk action (Pages::REBUILT) that keeps the page cache', [
    Kaleta\Admin\Modules\Pages::REBUILT, substr_count($q336List, '$module::REBUILT'), str_contains($q336List, 'data-layout-done='),
    str_contains((string) file_get_contents(KALETA_ROOT . '/system/src/Admin/Modules/Pages.php'), 'if ($done > 0 && $action !== self::REBUILT) {'),
], ['rebuilt', 2, true, true]);

check('3.36 layout_queue: the texts in Czech, German and Spanish', array_map(fn (string $code): array => Kaleta\Core\Language::runWith($code, fn (): array => [t('Layout done') !== 'Layout done',
    t('Pages taken off the list of layouts to rebuild: %d.', 2) !== 'Pages taken off the list of layouts to rebuild: 2.', t('Takes the page off the list of layouts to rebuild – nothing on the page changes.') !== 'Takes the page off the list of layouts to rebuild – nothing on the page changes.'], 'admin-'), ['cs', 'de', 'es']),
    [[true, true, true], [true, true, true], [true, true, true]]);
