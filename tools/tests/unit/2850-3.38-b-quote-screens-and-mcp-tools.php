<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Quotes as QuotesModule;
use Kaleta\Core\Quotes;
use Kaleta\Mcp\Catalog;
use Kaleta\Mcp\Translator;

/* ---------- 3.38 B: the quote screens, the boxes of the records and the MCP quote tools ---------- */

// the list filter: a status of the list (all by default), "mine", nobody or a person, a word, the record of a box – anything else falls back
check('3.38 B: the quote list filter keeps known statuses, mine/none/a person, a word up to 100 characters and the ids of a record; anything else falls back', [
    QuotesModule::filter([]),
    QuotesModule::filter(['status' => 'open', 'owner' => 'mine', 'search' => '  laser  ', 'enquiry' => '12', 'deal' => 'x', 'contact' => '-3', 'company' => '7']),
    QuotesModule::filter(['status' => 'won', 'owner' => '0', 'search' => str_repeat('a', 120)])['status'],
    QuotesModule::filter(['owner' => '42'])['owner'], QuotesModule::filter(['owner' => 'none'])['owner'], QuotesModule::filter(['owner' => 'everyone'])['owner'],
    mb_strlen(QuotesModule::filter(['search' => str_repeat('ž', 120)])['search']),
], [['status' => 'all', 'owner' => '', 'search' => '', 'enquiry' => 0, 'deal' => 0, 'contact' => 0, 'company' => 0],
    ['status' => 'open', 'owner' => 'mine', 'search' => 'laser', 'enquiry' => 12, 'deal' => 0, 'contact' => 0, 'company' => 7],
    'all', '42', 'none', '', 100]);
check('3.38 B: the list offers every status plus all and open; each status has its label colour (expired plain)', [
    array_values(array_diff(Quotes::STATUSES, Quotes::LIST_STATUSES)), array_slice(Quotes::LIST_STATUSES, 0, 2),
    array_map(QuotesModule::statusClass(...), Quotes::STATUSES),
], [[], ['all', 'open'], ['stitek stitek-koncept', 'stitek stitek-plan', 'stitek stitek-vydano', 'stitek stitek-chyba', 'stitek']]);

// MCP (design A8, A-6): four non-essential tools of the feature – save_quote saves drafts only, mark_quote changes a status;
// on demand (the work set has no room under its budget and stays as it is), English on both sides
$b338Tools = ['list_quotes', 'get_quote', 'save_quote', 'mark_quote'];
check('3.38 B MCP: list_quotes and get_quote read, save_quote saves drafts, mark_quote writes; all need the feature quotes, live on demand only, English names, short titles', [
    array_map(fn (string $t): array => Catalog::TOOLS[$t], $b338Tools), array_map(Catalog::setsOf(...), $b338Tools),
    array_values(array_diff($b338Tools, Catalog::ON_DEMAND)), array_map(Translator::czech(...), $b338Tools),
    array_filter($b338Tools, fn (string $t): bool => mb_strlen(Catalog::TITLES[$t] ?? '') === 0 || mb_strlen(Catalog::TITLES[$t]) > 40),
], [[['read', 'quotes'], ['read', 'quotes'], ['draft', 'quotes'], ['write', 'quotes']], [[], [], [], []], [], $b338Tools, []]);
check('3.38 B MCP: both write tools are site-neutral (a quote never changes a page), mark_quote is idempotent, none reaches outside the site', [
    Catalog::isSiteNeutral('save_quote'), Catalog::isSiteNeutral('mark_quote'), Catalog::isIdempotent('mark_quote'), Catalog::isIdempotent('save_quote'),
    array_values(array_filter($b338Tools, fn (string $t): bool => Catalog::isOpenWorld($t))),
    Catalog::allows('drafts', 'save_quote'), Catalog::allows('drafts', 'mark_quote'), Catalog::allows('read', 'get_quote'), Catalog::allows('read', 'save_quote'),
], [true, true, true, false, [], true, false, true, false]);
$b338Defs = array_column(Kaleta\Mcp\Tools::definitions(), null, 'name');
check('3.38 B MCP: the definitions – required ids, a status for mark_quote, no confirm or recipient on save_quote (sending is send_quote\'s), the never-guess rule said', [
    $b338Defs['get_quote']['inputSchema']['required'] ?? null, $b338Defs['mark_quote']['inputSchema']['required'] ?? null,
    array_values(array_intersect(['confirm', 'to', 'recipient', 'send'], array_keys((array) $b338Defs['save_quote']['inputSchema']['properties']))),
    str_contains($b338Defs['save_quote']['description'], 'never invent or guess a price'), str_contains($b338Defs['save_quote']['description'], 'NOT UNDOABLE'),
    array_keys((array) $b338Defs['list_quotes']['inputSchema']['properties']),
    // "reason" is every tool's guardrail argument (Mcp\Server takes it away) – the decline reason has its own name
    array_values(array_filter($b338Tools, fn (string $t): bool => array_key_exists('reason', (array) $b338Defs[$t]['inputSchema']['properties']))),
    array_key_exists('decline_reason', (array) $b338Defs['mark_quote']['inputSchema']['properties']),
], [['id'], ['id', 'status'], [], true, true, ['status', 'contact', 'company', 'deal', 'enquiry', 'owner', 'search', 'page', 'limit', 'line_sets'], [], true]); // 3.40: line_sets

// the dictionaries: the screens speak Czech, German (formal and the du overlay where it addresses the person) and Spanish
$b338Keys = ['Create a quote', 'Save and send', 'Finalise without sending', 'Mark accepted', 'Mark declined', 'Move the deal to won', 'New quote', 'manual price', 'no price',
    'When sent, set the value of the deal to the net total', 'Claude may save a discount up to %s %%; a higher one only a person sets here.'];
$b338Missing = [];
foreach (['admin-cs', 'admin-de', 'admin-es'] as $b338File) {
    $b338Dict = require KALETA_SYSTEM . '/jazyky/' . $b338File . '.php';
    foreach ($b338Keys as $b338Key) {
        if (!isset($b338Dict[$b338Key])) {
            $b338Missing[] = $b338File . ': ' . $b338Key;
        }
    }
}
$b338Du = require KALETA_SYSTEM . '/jazyky/admin-de-du.php';
check('3.38 B: the quote screens are in the Czech, German and Spanish admin; German texts that address the person have their du form', [
    $b338Missing, isset($b338Du['No product or own row of the price list has the code %s – choose one from the list.'], $b338Du['Quote %s is finalised – hand it over or print it.']),
], [[], true]);
