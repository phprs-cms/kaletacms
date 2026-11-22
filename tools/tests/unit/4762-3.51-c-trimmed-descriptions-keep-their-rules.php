<?php

declare(strict_types=1);

/* ---------- 3.51 C1: the trimmed tool descriptions still say what a caller must know ---------- */
use Kaleta\Mcp\Translator as C762Translator;

$c762Tools = array_column(array_map(Kaleta\Mcp\Server::listed(...), C762Translator::listAll(Kaleta\Mcp\Tools::definitions())), null, 'name');
// what is missing from a tool: [where => [words]] – where is "description" or a parameter
$c762Missing = function (string $tool, array $wanted) use ($c762Tools): array {
    $missing = [];
    foreach ($wanted as $where => $words) {
        $text = $where === 'description' ? (string) ($c762Tools[$tool]['description'] ?? '') : (string) ($c762Tools[$tool]['inputSchema']['properties'][$where]['description'] ?? '');
        foreach ($words as $word) {
            if (!str_contains($text, $word)) {
                $missing[] = $where . ': ' . $word;
            }
        }
    }

    return $missing;
};
check('3.51 C1: save_quote – a draft only, prices from the price list and never guessed, the discount limit, nothing sent, not undoable; the parameters unchanged', [
    $c762Missing('save_quote', ['description' => ['DRAFT', 'a sent one is revised in the admin', 'list_prices', 'never invent or guess a price', 'unpriced line for a person', 'counts as a discount',
        'limit (default 20 %) is refused', 'Drafts-only connections change only their user\'s drafts', 'Nothing is sent', 'NOT UNDOABLE'],
        'lines' => ['only on the user\'s explicit word', 'id (a line to keep)'], 'line_set' => ['list_quotes line_sets=true', 'today\'s list price'], 'price_list' => ['fixes the currency']]),
    array_keys($c762Tools['save_quote']['inputSchema']['properties'])],
    [[], ['id', 'enquiry', 'deal', 'contact', 'company', 'price_list', 'lines', 'customer', 'discount_percent', 'valid_until', 'valid_days', 'title', 'intro', 'terms', 'language', 'vat_mode', 'owner', 'line_set', 'reason']]);
check('3.51 C1: save_collection_item – hidden by default, the drafts-only rule, the field keys of list_collections, the categories list', [
    $c762Missing('save_collection_item', ['description' => ['Without "visible": true a new item stays hidden', 'drafts-only connection creates and changes hidden items only', 'publish_at'],
        'values' => ['keys from list_collections', 'fields you leave out stay'], 'categories' => ['list_collection_categories', 'the whole new list', '[] = none'],
        'language' => ['the original\'s slug', 'hreflang'], 'name' => ['required for a new item']])], [[]]);
check('3.51 C1: preview_link – what it shows (the draft of a page or site part, the whole site with the draft look), for whom and how long, not indexed, comments', [
    $c762Missing('preview_link', ['description' => ['draft preview of a page or site part', 'without signing in', 'only for this target', 'default 60', 'search engines do not index it'],
        'minutes' => ['default 60', 'at most 10080'], 'site' => ['whole site with every draft and the draft look'], 'comments' => ['page drafts only', 'list_draft_comments']])], [[]]);
check('3.51 C1: save_deal – a deal from an enquiry (title, customer, handler, one open deal), the first stage, the currency, not undoable, never on the site', [
    $c762Missing('save_deal', ['description' => ['first words of the message', 'its handler as responsible', 'one open deal per enquiry', 'first open stage', 'move_deal', 'ISO currency', 'NOT UNDOABLE', 'Never shown on the site'],
        'enquiry' => ['list_enquiries']])], [[]]);
check('3.51 C1: import_wordpress – everything arrives hidden, menus into the draft look, new languages only when asked, the preview before confirm, the limit', [
    $c762Missing('import_wordpress', ['description' => ['EVERYTHING ARRIVES HIDDEN', 'news as drafts', 'draft look', 'nothing becomes public', 'add_languages: true', 'ask the user first', 'No accounts are created',
        'up to 1 GB', 'In the phase "preview" show the user', 'confirm: true', 'migration_report', 'hourly change limit'],
        'add_languages' => ['ask the user first'], 'file' => ['storage/import/']])], [[]]);
check('3.51 C3: site_info says it names this connection with its access and tool set', [$c762Missing('site_info', ['description' => ['this connection (access, tool set)']])], [[]]);
