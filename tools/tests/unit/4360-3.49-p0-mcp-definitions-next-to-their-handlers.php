<?php

declare(strict_types=1);

use Kaleta\Mcp\Catalog;
use Kaleta\Mcp\Schema;
use Kaleta\Mcp\Tools;

/* ---------- 3.49 P0 (the 3.48 carry-over): the MCP definitions sit next to their handlers, the answers stay byte for byte ---------- */

require_once dirname(__DIR__, 2) . '/contracts.php';

// the record taken from v3.48.0 before a line moved: every definition hash, the order, the whole list and the meta tools
$p0Recorded = json_decode((string) file_get_contents(KALETA_ROOT . '/tools/contracts/mcp-definitions.json'), true);
$p0Current = kaleta_definitions_contract();
check('3.49 P0: the definitions match the record (tools/contracts/mcp-definitions.json) – order, every hash, the whole list and the meta tools',
    [$p0Current['order'] === $p0Recorded['order'], array_keys(array_diff_assoc($p0Current['sha256'], $p0Recorded['sha256'])), $p0Current['raw'] === $p0Recorded['raw'], $p0Current['discovery'] === $p0Recorded['discovery']],
    [true, [], true, true]);

// ORDER holds the names of the traits' arrays once each – nothing defined twice, nothing listed that no trait defines
$p0Order = (new ReflectionClassConstant(Tools::class, 'ORDER'))->getValue();
$p0Traits = array_values(array_map(fn (string $trait): string => substr($trait, strrpos($trait, '\\') + 1), class_uses(Tools::class)));
$p0Defined = [];
$p0Files = [];
foreach ($p0Traits as $p0Trait) {
    $p0Method = lcfirst($p0Trait) . 'Definitions';
    $p0File = (new ReflectionMethod(Tools::class, $p0Method))->getFileName();
    foreach (array_keys(Tools::$p0Method()) as $p0Name) {
        $p0Defined[] = $p0Name;
        $p0Files[$p0Name] = [$p0File, $p0Trait];
    }
}
$p0Sorted = function (array $names): array {
    sort($names);

    return $names;
};
check('3.49 P0: every trait of Tools has its definitions; ORDER has no duplicate and equals the union of the trait arrays; no name is defined twice',
    [count($p0Traits), count(array_filter($p0Traits, fn (string $t): bool => method_exists(Tools::class, lcfirst($t) . 'Definitions'))), count($p0Order) === count(array_unique($p0Order)),
        $p0Sorted($p0Order) === $p0Sorted($p0Defined), count($p0Defined) === count(array_unique($p0Defined)), count($p0Order)],
    [29, 29, true, true, true, count(Catalog::TOOLS)]);

// each definition sits in the file of the method that runs the tool (Catalog::method)
check('3.49 P0: each definition sits in the file of its tool… method (ReflectionMethod::getFileName)',
    array_keys(array_filter($p0Files, fn (array $where, string $name): bool => (new ReflectionMethod(Tools::class, Catalog::method((string) Catalog::english($name))))->getFileName() !== $where[0], ARRAY_FILTER_USE_BOTH)), []);

// a parameterless tool keeps "properties": {} (a fresh \stdClass, never an empty array that would become [])
$p0ByName = array_column(Tools::definitions(), null, 'name');
$p0Empty = Schema::object([]);
check('3.49 P0: a parameterless tool encodes properties as {} – Schema::object gives a fresh object each time, no definition has an empty array',
    [json_encode($p0ByName['info_o_webu']['inputSchema']), $p0Empty['properties'] instanceof stdClass && $p0Empty['properties'] !== Schema::object([])['properties'],
        array_keys(array_filter($p0ByName, fn (array $d): bool => $d['inputSchema']['properties'] === [])), Schema::text('x'), Schema::number('y')],
    ['{"type":"object","properties":{},"required":[]}', true, [], ['type' => 'string', 'description' => 'x'], ['type' => 'integer', 'description' => 'y']]);

// the HTTP record of v3.48.0 (smoke 4360 compares it with the test site): 5 sets × 3 accesses, two feature states, find_tools
$p0Record = array_map('basename', glob(KALETA_ROOT . '/tools/contracts/mcp-record/*.json') ?: []);
check('3.49 P0: the stored tools/list and find_tools answers – every tool set for a full, a drafts and a read connection, Customers off, Pipeline off, two find_tools calls',
    [count($p0Record), array_values(array_diff(array_merge(...array_map(fn (string $set): array => ["tools-list-$set-full.json", "tools-list-$set-drafts.json", "tools-list-$set-read.json"], Catalog::TOOL_SETS)), $p0Record)),
        array_values(array_intersect(['tools-list-all-full-customers-off.json', 'tools-list-all-full-pipeline-off.json', 'find-tools-index.json', 'find-tools-names.json'], $p0Record))],
    [19, [], ['tools-list-all-full-customers-off.json', 'tools-list-all-full-pipeline-off.json', 'find-tools-index.json', 'find-tools-names.json']]);
