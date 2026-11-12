<?php

declare(strict_types=1);

use Kaleta\Core\Clock;
use Kaleta\Core\QuoteLineSets;
use Kaleta\Core\Quotes;

/* ---------- 3.40 B: quotes II – a price list per customer, the customer's Accept / Decline, first opened on, the follow-up, saved line sets ---------- */

$b340Settings = static function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values + ['site_name' => 'Firma', 'site_language' => 'cs']);

    return $s;
};
$b340Quote = static fn (array $over = []): array => $over + ['status' => 'sent', 'snapshot' => ['number' => '2026-0007'], 'anonymised_at' => null, 'valid_until' => '2026-11-20', 'history' => []];

// both settings are off by default (design A-12) and are no MCP settings
check('3.40 B: Accept / Decline and "first opened on" are off by default; neither can be switched over MCP', [
    Kaleta\Core\Settings::DEFAULTS['quote_customer_decision'] ?? null, Kaleta\Core\Settings::DEFAULTS['quote_track_opened'] ?? null,
    array_map(fn (string $k): int => preg_match((string) (new ReflectionClassConstant(Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue(), $k), ['quote_customer_decision', 'quote_track_opened'])], ['0', '0', [0, 0]]);

// when the customer may answer: the setting, a sent quote that is still valid, with its document, never anonymised, one decision
Clock::freeze('2026-11-12 10:00:00');
$b340On = $b340Settings(['quote_customer_decision' => '1']);
$b340Decided = [['action' => 'status', 'from' => 'sent', 'to' => 'declined', 'via' => 'customer'], ['action' => 'status', 'from' => 'declined', 'to' => 'sent']];
check('3.40 B: the customer may decide only with the setting, on a sent and valid quote with its document, never twice', [
    Quotes::customerMayDecide($b340Settings([]), $b340Quote()), Quotes::customerMayDecide($b340On, $b340Quote()),
    Quotes::customerMayDecide($b340On, $b340Quote(['valid_until' => '2026-11-12'])), Quotes::customerMayDecide($b340On, $b340Quote(['valid_until' => '2026-11-11'])),
    Quotes::customerMayDecide($b340On, $b340Quote(['valid_until' => null])), Quotes::customerMayDecide($b340On, $b340Quote(['status' => 'draft'])),
    Quotes::customerMayDecide($b340On, $b340Quote(['status' => 'accepted'])), Quotes::customerMayDecide($b340On, $b340Quote(['snapshot' => null])),
    Quotes::customerMayDecide($b340On, $b340Quote(['anonymised_at' => '2026-11-01 08:00:00'])), Quotes::customerMayDecide($b340On, $b340Quote(['history' => $b340Decided]))],
    [false, true, true, false, true, false, false, false, false, false]);
Clock::freeze(null);
check('3.40 B: "by the customer" is read from the history – any decision of theirs, or the last change to a status', [
    Quotes::customerDecided([]), Quotes::customerDecided($b340Decided), Quotes::customerDecided($b340Decided, 'declined'), Quotes::customerDecided($b340Decided, 'sent'),
    Quotes::customerDecided([['action' => 'status', 'to' => 'accepted', 'by' => 3]], 'accepted'), Quotes::customerDecided([['action' => 'link', 'via' => 'customer']])],
    [false, true, true, false, false, false]);
check('3.40 B: the customer\'s reason is bounded; the follow-up offers 7 days and takes at most 90', [Quotes::CUSTOMER_REASON_MAX, Quotes::FOLLOW_UP_DAYS, Quotes::FOLLOW_UP_MAX_DAYS],
    [1000, 7, 90]);
check('3.40 B: a customer decision of something else than accepted or declined changes nothing (before any database work)', [
    Quotes::customerDecision((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor(), 1, 'sent'),
    Quotes::customerDecision((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor(), 1, '')], [false, false]);

// the follow-up is a task about the quote: quote:<id> is a full target kind (tasks, bells, the section Quotes, My work)
check('3.40 B: quote:<id> parses, is a task target (about a person – never journaled), opens through the section Quotes', [
    Kaleta\Core\Tasks::cleanTarget('quote:12'), Kaleta\Core\Tasks::cleanTarget('quote:0'), Kaleta\Core\Tasks::personal('quote:12'), Kaleta\Core\Inbox::parse('quote:12'),
    Kaleta\Core\Inbox::TARGETS['quote'] ?? null, Kaleta\Core\Assignment::KINDS['quote'] ?? null, Quotes::SECTION],
    ['quote:12', null, true, ['quote', 12], 'quote', 'quotes', 'quotes']);
check('3.40 B: the bell of the customer\'s answer – two kinds, named in the administration language, never with the customer\'s words', [
    array_values(array_intersect(['quote_accepted', 'quote_declined'], Kaleta\Core\Inbox::KINDS)),
    Kaleta\Core\Language::runWith('en', fn (): array => [Kaleta\Core\Inbox::what('quote_accepted', ''), Kaleta\Core\Inbox::what('quote_declined', '')], 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Core\Inbox::what('quote_accepted', ''), 'admin-')],
    [['quote_accepted', 'quote_declined'], ['The customer accepted a quote', 'The customer declined a quote'], 'Zákazník přijal nabídku']);

// saved line sets: what a set inserts – products and own rows without a price (the quote's list prices them), free lines with theirs
$b340Set = ['currency' => 'CZK', 'lines' => [
    ['kind' => 'product', 'item_id' => 5, 'variant_code' => 'L-M', 'quantity' => '2.000', 'discount_percent' => '5.00'],
    ['kind' => 'product', 'item_id' => 6, 'variant_code' => '', 'quantity' => '1.000', 'discount_percent' => '0.00', 'name' => 'Laser – renamed', 'description' => 'with a stand'],
    ['kind' => 'own', 'code' => 'MONT', 'name' => 'Installation', 'quantity' => '3.000', 'discount_percent' => '0.00'],
    ['kind' => 'free', 'code' => '', 'name' => 'Transport', 'unit' => 'km', 'unit_price' => '12.50', 'vat_rate' => '21.00', 'quantity' => '40.000', 'discount_percent' => '0.00'],
    ['kind' => 'free', 'code' => 'X1', 'name' => 'To be priced', 'unit' => '', 'unit_price' => null, 'vat_rate' => '21.00', 'quantity' => '1.000', 'discount_percent' => '0.00']]];
$b340Inputs = QuoteLineSets::inputs($b340Set, 'CZK'); // a quote in the set's currency (3.40 review M3)
check('3.40 B: inserting a set – products by item and variant and own rows by code never carry a price; free lines keep theirs (or none)', [
    array_map(fn (array $l): bool => array_key_exists('unit_price', $l), $b340Inputs), array_column($b340Inputs, 'unit_price'),
    $b340Inputs[0], $b340Inputs[1]['name'] ?? null, $b340Inputs[2]['code'] ?? null, $b340Inputs[3]['unit'] ?? null],
    [[false, false, false, true, true], ['12.50', null], ['item_id' => 5, 'variant_code' => 'L-M', 'quantity' => '2.000', 'discount_percent' => '5.00', 'description' => ''], 'Laser – renamed', 'MONT', 'km']);
check('3.40 B: a set\'s name is one line of at most 120 characters', [QuoteLineSets::cleanName("  Laser \n with   installation "), mb_strlen(QuoteLineSets::cleanName(str_repeat('ž', 300))), QuoteLineSets::NAME_MAX],
    ['Laser with installation', 120, 120]);

// line sets hold prices of free lines: never journaled, named by the classes of quotes only, an English name of their own
$b340Named = [];
foreach (['src', 'views'] as $b340Dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/' . $b340Dir, FilesystemIterator::SKIP_DOTS)) as $b340File) {
        if (str_ends_with((string) $b340File, '.php') && preg_match('/\{quote_line_sets\}|\bka_quote_line_sets\b|->(?:insert|update|delete)\(\s*\'quote_line_sets\'/', (string) file_get_contents((string) $b340File)) === 1) {
            $b340Named[] = substr((string) $b340File, strlen(KALETA_SYSTEM) + 1);
        }
    }
}
check('3.40 B: saved line sets – only Core\QuoteLineSets names their table; never journaled; in the names map', [
    $b340Named, in_array('quote_line_sets', Kaleta\Core\AgentJournal::PERSONAL_TABLES, true), Kaleta\Core\Names::english('quote_line_sets')],
    [['src/Core/QuoteLineSets.php'], true, 'quote_line_sets']);

// MCP: additive only – a flag on list_quotes, a parameter on save_quote; the quote tools stay on demand
$b340Defs = array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), null, 'name');
check('3.40 B MCP: list_quotes takes line_sets (boolean), save_quote line_set (text); both tools stay on demand and outside the essentials', [
    $b340Defs['list_quotes']['inputSchema']['properties']['line_sets']['type'] ?? null, $b340Defs['save_quote']['inputSchema']['properties']['line_set']['type'] ?? null,
    in_array('save_quote', Kaleta\Mcp\Catalog::ON_DEMAND, true), in_array('list_quotes', Kaleta\Mcp\Catalog::ON_DEMAND, true),
    in_array('save_quote', Kaleta\Mcp\Catalog::ESSENTIALS, true)], ['boolean', 'string', true, true, false]);

// the customer's page posts to itself: its CSP allows forms to the site only (never elsewhere), still no script but the site's
$b340Csp = Kaleta\Front\QuotePage::html('<p>x</p>')->headers['Content-Security-Policy'] ?? '';
check('3.40 B: the quote page\'s CSP lets its own forms post to the site and nowhere else', [str_contains($b340Csp, "form-action 'self';"), str_contains($b340Csp, "script-src 'self';"),
    str_contains($b340Csp, "default-src 'none'")], [true, true, true]);
