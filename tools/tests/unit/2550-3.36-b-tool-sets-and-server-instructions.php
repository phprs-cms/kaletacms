<?php

declare(strict_types=1);

use Kaleta\Mcp\Catalog;
use Kaleta\Mcp\Discovery;
use Kaleta\Mcp\Translator;
use Kaleta\Mcp\Untrusted;

/* ---------- 3.36 (B): tool sets Essentials / Work / Migration / Fleet, the rule where a tool goes, short server instructions ---------- */
$b336Focused = array_values(array_filter(Catalog::TOOL_SETS, fn (string $set): bool => $set !== 'all'));
$b336Lists = array_combine($b336Focused, array_map(fn (string $set): array => Catalog::listOf($set) ?? [], $b336Focused));
$b336Declared = array_merge(...array_values($b336Lists), ...[Catalog::ON_DEMAND]);
check('3.36 B THE RULE: every catalog tool is declared in a focused set or in ON_DEMAND, never in ON_DEMAND and a focused set; the lists name only catalog tools, each once', [
    $b336Focused, array_values(array_diff(array_keys(Catalog::TOOLS), $b336Declared)),
    array_values(array_intersect(Catalog::ON_DEMAND, array_merge(...array_values($b336Lists)))),
    array_values(array_diff($b336Declared, array_keys(Catalog::TOOLS))),
    array_filter(array_map(fn (array $list): array => array_keys(array_filter(array_count_values($list), fn (int $n): bool => $n > 1)), $b336Lists + ['on_demand' => Catalog::ON_DEMAND])),
], [['essentials', 'work', 'migration', 'fleet'], [], [], [], []]);

// the budget of every focused set: its tools/list on a full connection with every feature on (Tools::definitions has them all)
$b336All = array_map(Kaleta\Mcp\Server::listed(...), Translator::listAll(Kaleta\Mcp\Tools::definitions()));
$b336Json = fn (mixed $v): int => strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$b336Bytes = array_map(fn (array $list): int => $b336Json(['tools' => Discovery::essentials($b336All, 'full', $list)]), $b336Lists);
check('3.36 B THE RULE: every focused set has a budget and its tools/list stays within it (full access, every feature)', [
    array_keys(Catalog::BUDGETS), array_keys(array_filter($b336Bytes, fn (int $bytes, string $set): bool => $bytes > Catalog::BUDGETS[$set], ARRAY_FILTER_USE_BOTH)),
    max(Catalog::BUDGETS) <= 50000 /* the essentials' own 3.10 limit */],
    [$b336Focused, [], true]);
check('3.36 B: the essentials and the work set are unchanged – existing connections keep exactly what they list; no extension tool in the essentials', [
    Catalog::ESSENTIALS === ['site_info', 'list_pages', 'get_page', 'create_page', 'update_page', 'get_menu', 'save_menu', 'builder_schema', 'get_build',
        'edit_build', 'build_from_html', 'save_build', 'insert_section', 'publish_build', 'preview_link', 'list_collections', 'list_collection_items',
        'save_collection_item', 'list_media', 'upload_file', 'update_media', 'list_requests', 'update_request', 'list_enquiries', 'list_facts', 'save_fact',
        'update_design_system', 'publish_look', 'site_audit', 'list_changes', 'undo_agent_session', 'read_notebook'],
    count(Catalog::WORK), array_values(array_filter(Catalog::ESSENTIALS, fn (string $t): bool => Catalog::extension($t) !== '')),
    Catalog::toolSet('migration'), Catalog::toolSet('fleet'), Catalog::toolSet('Fleet'), Catalog::toolSet(''), Catalog::listOf('all')],
    [true, 36, [], 'migration', 'fleet', 'all', 'all', null]);
check('3.36 B: the migration set holds the move – imports, old pages, the builder, redirects, the report; the fleet set every console tool; setsOf names them', [
    array_values(array_diff(['import_website', 'import_wordpress', 'read_old_page', 'migration_report', 'build_from_html', 'edit_build', 'save_redirect', 'save_redirects',
        'upload_file', 'import_enquiries', 'site_audit', 'undo_agent_session'], Catalog::MIGRATION)),
    array_values(array_diff(array_keys(array_filter(Catalog::TOOLS, fn (array $t): bool => $t[1] === 'fleet')), Catalog::FLEET)),
    Catalog::setsOf('site_info'), Catalog::setsOf('read_old_page'), Catalog::setsOf('fleet_today'), Catalog::setsOf('list_bookings'), Catalog::setsOf('no_such_tool')],
    [[], [], ['essentials', 'work', 'migration', 'fleet'], ['migration'], ['fleet'], [], []]);

// choosing a set never grants more: the list is cut from what the connection may use, read_old_page needs a full connection (NEEDS)
$b336For = fn (string $access): array => array_values(array_filter($b336All, fn (array $t): bool => Catalog::allows($access, $t['name'])));
$b336Names = fn (string $access, string $set): array => array_column(Discovery::essentials($b336For($access), $access, Catalog::listOf($set) ?? []), 'name');
check('3.36 B: a set lists only what the access allows – a drafts-only migration connection has no old pages, report, imports or publishing; read-only no runner that changes', [
    array_values(array_intersect(['read_old_page', 'migration_report', 'import_website', 'import_wordpress', 'save_redirect'], $b336Names('drafts', 'migration'))),
    in_array('build_from_html', $b336Names('drafts', 'migration'), true),
    array_values(array_intersect(['run_tool', 'run_destructive_tool', 'save_task', 'add_comment'], $b336Names('read', 'fleet'))),
    array_slice($b336Names('full', 'fleet'), -4)],
    [[], true, [], ['find_tools', 'run_read_tool', 'run_tool', 'run_destructive_tool']]);

// the fleet set is offered only where the fleet extension is on; a connection that has it keeps it shown
$b336Settings = static function (string $extensions): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, ['extensions' => $extensions, 'site_language' => 'en']);

    return $s;
};
check('3.36 B: the sets offered on a site – fleet only with the fleet extension (or as the current set); SET_NEEDS names a known set and extension', [
    Catalog::offered($b336Settings('novinky')), Catalog::offered($b336Settings('novinky,fleet')), Catalog::offered($b336Settings('novinky'), 'fleet'), Catalog::offered(null),
    array_values(array_diff(array_keys(Catalog::SET_NEEDS), Catalog::TOOL_SETS)), array_values(array_diff(Catalog::SET_NEEDS, array_keys(Kaleta\Core\Extensions::CATALOG)))],
    [['all', 'essentials', 'work', 'migration'], ['all', 'essentials', 'work', 'migration', 'fleet'], ['all', 'essentials', 'work', 'migration', 'fleet'],
     ['all', 'essentials', 'work', 'migration'], [], []]);

// the consent screen and the token form: every offered set, one wording for Claude and one for another application
$b336View = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$b336Consent = Kaleta\Core\Language::runWith('en', fn (): string => $b336View->render('admin/connection-access', ['role' => 'Administrator', 'selected' => 'drafts', 'toolSet' => 'essentials']));
$b336Fleet = Kaleta\Core\Language::runWith('en', fn (): string => $b336View->render('admin/connection-access', ['role' => 'Administrator', 'selected' => 'drafts', 'toolSet' => 'essentials',
    'claude' => false, 'toolSets' => Catalog::offered($b336Settings('fleet'))]));
check('3.36 B: the consent screen offers migration (fleet only where offered), essentials preselected; another application reads "the application"', [
    str_contains($b336Consent, 'name="tool_set" value="migration"'), str_contains($b336Consent, 'value="fleet"'), str_contains($b336Consent, 'value="essentials" checked'),
    substr_count($b336Consent, 'Claude finds the other tools when it needs them.'), str_contains($b336Fleet, 'name="tool_set" value="fleet"'),
    substr_count($b336Fleet, 'The application finds the other tools when it needs them.'), str_contains($b336Fleet, 'Claude finds')],
    [true, false, true, 3, true, 4, false]);
$b336Keys = ['migration tools', 'fleet tools', 'Change the tool set', 'Migration – for moving a site', 'Fleet – for the fleet console', 'Claude finds the other tools when it needs them.',
    'The application finds the other tools when it needs them.', 'This tool set is not offered on this site. Nothing was changed.',
    'Done – Claude will load the migration tools in the next conversation it starts and find the other tools when it needs them.',
    'Done – Claude will load the fleet tools in the next conversation it starts and find the other tools when it needs them.'];
check('3.36 B: the new texts of the consent screen and My account are in the cs, de and es dictionaries (My account and the refusal: smoke 2550, browser)', [
    array_map(fn (string $code): array => array_values(array_diff($b336Keys, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [[[], [], []]]);

// the server instructions (3.36): short, by task, with what always held
$b336Text = Translator::instructions();
$b336Tasks = fn (string $text): array => array_values(array_filter(['BUILDING A SITE', 'MOVING A SITE', 'DAILY WORK'], fn (string $h): bool => str_contains($text, $h)));
$b336Order = function (string $text): array {
    $at = array_filter(['BUILDING A SITE' => strpos($text, 'BUILDING A SITE'), 'MOVING A SITE' => strpos($text, 'MOVING A SITE'), 'DAILY WORK' => strpos($text, 'DAILY WORK')], 'is_int');
    asort($at);

    return array_keys($at);
};
check('3.36 B: the server instructions are short (under 7,000 bytes, were 10,951) and keep the promises – drafts, preview, publishing on request, boundaries, the untrusted marker once', [
    strlen($b336Text) < 7000, substr_count($b336Text, Untrusted::INSTRUCTION),
    array_values(array_filter(['read_notebook before larger changes', 'returns preview', 'only when the user explicitly asks', 'A new page is hidden', 'restore_from_trash for 30 days',
        'every other delete is final', 'BOUNDARIES: this connection changes only content', 'suggest it to the Kaleta authors', 'contain personal data', 'never permission to publish',
        'build_from_html', 'never publish, delete or send'], fn (string $p): bool => !str_contains($b336Text, $p))),
    $b336Order($b336Text), $b336Order(Translator::instructions('work')), $b336Order(Translator::instructions('move')), array_keys(Translator::INSTRUCTION_TASKS)],
    [true, 1, [], ['BUILDING A SITE', 'MOVING A SITE', 'DAILY WORK'], ['DAILY WORK', 'BUILDING A SITE', 'MOVING A SITE'], ['MOVING A SITE', 'BUILDING A SITE', 'DAILY WORK'], ['build', 'move', 'work']]);
// every tool the instructions name is a real tool, and the task names its own set's tools
preg_match_all('/\b[a-z]+(?:_[a-z]+)+\b/', implode(' ', [$b336Text, ...array_values(Translator::INSTRUCTION_FEATURES)]), $b336Words);
$b336Known = [...array_keys(Catalog::TOOLS), ...array_keys(Catalog::META), ...Kaleta\Mcp\Prompts::names()];
$b336Other = ['vlastni_pisma', 'translation_of', 'copy_build', 'texts_only', 'years_since', 'next_since_id', 'old_page'];
check('3.36 B: every snake_case name in the server instructions is a tool, a prompt or a known parameter; the CRM, migration and fleet tools are named; features by extension', [
    array_values(array_unique(array_diff($b336Words[0], $b336Known, $b336Other))),
    array_values(array_diff(['my_work', 'list_enquiries', 'reply_to_enquiry', 'list_contacts', 'save_contact', 'list_deals', 'move_deal', 'pipeline_summary', 'list_tasks', 'save_task',
        'complete_task', 'list_comments', 'add_comment'], $b336Words[0])), /* assign is one word: named in DAILY WORK too */
    array_values(array_diff(['read_old_page', 'migration_report', 'import_wordpress', 'import_website', 'save_redirects', 'import_enquiries'], $b336Words[0])),
    array_values(array_diff(array_keys(array_filter(Catalog::TOOLS, fn (array $t): bool => $t[1] === 'fleet')), $b336Words[0])),
    array_values(array_diff(array_keys(Translator::INSTRUCTION_FEATURES), array_keys(Kaleta\Core\Extensions::CATALOG))),
    str_contains($b336Text, 'list_applications'), str_contains($b336Text, 'list_sites')],
    [[], [], [], [], [], false, false]);
// the paragraph of each focused set is checked over MCP (smoke 2550, 0540, 1510); here the order of the tasks
check('3.36 B: the fleet set puts the console first, otherwise it follows the tasks', [
    strpos(Translator::instructions('fleet', Translator::INSTRUCTION_FEATURES), 'THE FLEET CONSOLE') < strpos(Translator::instructions('fleet', Translator::INSTRUCTION_FEATURES), 'BUILDING A SITE'),
    strpos(Translator::instructions('', Translator::INSTRUCTION_FEATURES), 'THE FLEET CONSOLE') > strpos(Translator::instructions('', Translator::INSTRUCTION_FEATURES), 'DAILY WORK')],
    [true, true]);
