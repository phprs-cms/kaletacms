<?php

declare(strict_types=1);

use Kaleta\Core\RecordSearch;
use Kaleta\Mcp\Catalog;

/* ---------- 3.52 A4, A5: the command palette searches every kind of record on the server, in one answer ---------- */

check('3.52 A5: RecordSearch::rank() – the same name 0, its start 1, the start of a word 2, anywhere 3; case and diacritics do not matter (3.53 B4: the same words in any order are the same name)', [
    RecordSearch::rank('Dvořák', 'dvorak'), RecordSearch::rank('Škoda Auto', 'skoda'), RecordSearch::rank('Jan Novák', 'novak'),
    RecordSearch::rank('Janovák', 'novak'), RecordSearch::rank('Jan Novák', 'novak jan'), RecordSearch::rank('', 'x'), RecordSearch::rank('Ceník 2026', 'CENÍK  2026')],
    [0, 1, 2, 3, 0, 3, 0]);

// a person without a section gets nothing of it – with no section at all, nothing is even read; under two characters neither
$a810App = new Kaleta\Core\App([]);
check('3.52 A4: RecordSearch::search() – no section = no group, a search under two characters = nothing', [
    RecordSearch::search($a810App, 'dvorak', []), RecordSearch::search($a810App, 'd', ['pages', 'news']), RecordSearch::search($a810App, '   ', ['pages'])],
    [[], [], []]);

check('3.52 A4: the palette searches Media and Enquiries in the columns of their lists', [
    RecordSearch::MEDIA_COLUMNS === Kaleta\Admin\Modules\Media::SEARCH_COLUMNS, RecordSearch::ENQUIRY_COLUMNS === Kaleta\Admin\Modules\Enquiries::SEARCH_COLUMNS],
    [true, true]);

check('3.52 A4: MCP search – read-only, Czech alias hledej, one find_tools away, its results marked as visitors\' text', [
    Catalog::TOOLS['search'] ?? null, Kaleta\Mcp\Translator::czech('search'), in_array('search', Catalog::ON_DEMAND, true), Kaleta\Mcp\Untrusted::TOOLS['search'] ?? null,
    method_exists(Kaleta\Mcp\Tools::class, Catalog::method('search')), Catalog::english('hledej')],
    [['read', ''], 'hledej', true, ['results'], true, 'search']);

// the keywords of sections and settings tabs (A9): each names an entry the palette has, and is translated for the admin
$a810Entries = array_merge(array_column(array_merge(...array_values(Kaleta\Admin\Palette::QUICK)), 1), array_column(Kaleta\Admin\Palette::EVERYONE, 1),
    array_map(fn (string $class): string => 'module=' . $class::IDENT, Kaleta\Admin\Kernel::MODULES),
    array_map(fn (string $tab): string => 'module=settings&tab=' . $tab, array_keys(Kaleta\Admin\Modules\Settings::TABS)));
check('3.52 A9: Palette::KEYWORDS – every key is a palette entry, every keyword list has its cs/de/es translation', [
    array_values(array_diff(array_keys(Kaleta\Admin\Palette::KEYWORDS), $a810Entries)),
    array_map(fn (string $code): array => array_values(array_diff(Kaleta\Admin\Palette::KEYWORDS, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [[], [[], [], []]]);
