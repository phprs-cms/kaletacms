<?php

declare(strict_types=1);

/* ---------- 3.45 A: first quote, first week (CPO 3, CPO 6, U62) ---------- */
use Kaleta\Admin\Kernel as A345Kernel;
use Kaleta\Admin\Modules\Quotes as A345Quotes;
use Kaleta\Admin\Modules\Roles as A345Roles;
use Kaleta\Core\Settings as A345Settings;
use Kaleta\Mcp\Prompts as A345Prompts;

// CPO 3: the first steps of quotes – six steps in order, done from the facts, gone once all are done
$a345None = A345Quotes::setupChecklist([]);
$a345Some = A345Quotes::setupChecklist(['company' => true, 'vat' => true, 'mail' => true]);
check('3.45 A CPO 3: the first steps of quotes – company, VAT, collection, prices, mail, a quote; done from the facts; empty when everything is done', [
    array_column($a345None, 'key'), array_column($a345Some, 'done'),
    A345Quotes::setupChecklist(array_fill_keys(['company', 'vat', 'collection', 'prices', 'mail', 'quote'], true)),
    array_values(array_filter(array_column($a345None, 'url'), fn (string $u): bool => !str_starts_with($u, 'admin.php'))),
    $a345None[2]['url'], $a345None[1]['url']],
    [['company', 'vat', 'collection', 'prices', 'mail', 'quote'], [true, true, false, false, true, false], [], [],
        'admin.php?module=quotes&action=setup#price-collections', 'admin.php?module=quotes&action=settings']);

// CPO 6: the office's first steps – four steps, done from the facts, gone once all are done
check('3.45 A CPO 6: the office\'s first steps – a colleague, the signature, a reply, a follow-up; empty when everything is done', [
    array_column(A345Kernel::officeChecklist([]), 'key'), array_column(A345Kernel::officeChecklist(['reply' => true]), 'done'),
    A345Kernel::officeChecklist(array_fill_keys(['colleague', 'signature', 'reply', 'follow_up'], true))],
    [['colleague', 'signature', 'reply', 'follow_up'], [false, false, true, false], []]);

// one Hide for three lists, each with its own setting (internal flags with a default, never in update_settings or an export)
$a345Lists = A345Kernel::FIRST_STEP_LISTS;
check('3.45 A: Hide knows three lists – the website\'s, the office\'s and the quotes\' – each its own setting with the default 0, none in a site export', [
    array_keys($a345Lists), array_map(fn (array $l): string => A345Settings::DEFAULTS[$l[0]] ?? 'missing', $a345Lists), $a345Lists['quotes'][1],
    array_values(array_intersect(array_column($a345Lists, 0), Kaleta\Core\SiteExport::SETTINGS)), in_array('hide_first_steps', A345Kernel::SITE_NEUTRAL_ACTIONS[''], true)],
    [['', 'office', 'quotes'], ['' => '0', 'office' => '0', 'quotes' => '0'], 'admin.php?module=quotes', [], true]);

// CPO 6: "Office / sales" (was "Enquiries only") with Bookings and Quotes, never prices or content; the base level has a neutral name
$a345Office = A345Roles::PRESETS['office'];
check('3.45 A CPO 6: the preset Office / sales offers enquiries, customers, deals, bookings and quotes – no prices, no content – at the author level', [
    $a345Office[0], $a345Office[2], array_values(array_diff(['enquiries', 'contacts', 'pipeline', 'bookings', 'quotes', 'requests'], $a345Office[3])),
    array_values(array_intersect(['prices_edit', 'pages', 'news', 'collections', Kaleta\Core\Auth::APPEARANCE_AREA], $a345Office[3])),
    array_values(array_diff($a345Office[3], array_keys(A345Roles::configurable())))],
    ['Office / sales', Kaleta\Core\Auth::AUTHOR, [], [], []]);

// the texts of both lists, the preset and the level are translated in every admin language
$a345Texts = [...array_merge(...array_map(fn (array $k): array => [$k['title'], $k['text']], [...$a345None, ...A345Kernel::officeChecklist([])])),
    $a345Office[0], $a345Office[1], 'Team member', 'First steps with quotes', 'First steps for the office', 'Price list set-up', 'No products in the price list yet.', 'Move your old site', 'Finish the move', 'Still open: %s.'];
$a345Gaps = [];
foreach (['cs', 'de', 'es'] as $a345Language) {
    $a345Dictionary = require KALETA_SYSTEM . '/jazyky/admin-' . $a345Language . '.php';
    foreach ($a345Texts as $a345Text) {
        if (!isset($a345Dictionary[$a345Text])) {
            $a345Gaps[] = $a345Language . ': ' . $a345Text;
        }
    }
}
check('3.45 A: the first steps, the preset and the level are translated (cs, de, es)', $a345Gaps, []);

// CPO 3: the prompt set_up_quotes – listed, the same path as the admin's list, nothing sent without an explicit yes, no new tool
$a345Prompt = A345Prompts::get('set_up_quotes', [])['messages'][0]['content']['text'];
check('3.45 A CPO 3: the prompt set_up_quotes walks the set-up – extension, company, VAT, the price list, prices, mail, a test quote – and sends nothing without an explicit yes', [
    in_array('set_up_quotes', A345Prompts::names(), true),
    array_values(array_filter(['update_settings', 'company_name', 'Quotes → Settings', 'Price list set-up', 'list_collections', 'list_prices', 'save_price', 'get_health', 'save_contact', 'save_quote',
        'send_quote', 'explicit', 'Never invent or guess a price', 'office_day'], fn (string $w): bool => !str_contains($a345Prompt, $w))),
    str_contains(Kaleta\Mcp\Translator::INSTRUCTION_FEATURES['quotes'], 'set_up_quotes'), strlen(Kaleta\Mcp\Translator::instructions()) < 7000],
    [true, [], true, true]);
