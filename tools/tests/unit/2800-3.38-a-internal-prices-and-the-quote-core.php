<?php

declare(strict_types=1);

use Kaleta\Core\Money;
use Kaleta\Core\Prices;
use Kaleta\Core\Quotes;

/* ---------- 3.38 A: internal prices, money in whole numbers and the core of quotes ---------- */

// Money: parsing as people and Claude write prices, quantities and percentages – one table of cases
$m338Parse = static function (mixed $raw): string {
    try {
        return var_export(Money::parse($raw), true);
    } catch (\DomainException $e) {
        return 'refused';
    }
};
check('3.38 Money::parse: grouped thousands, decimal comma or point, JSON numbers, empty = none, nonsense refused', array_map($m338Parse,
    ['12 500,50', '12,500.50', '12.500,50', "12\u{00A0}500", '12500.5', 12.5, 1999, '', null, '0', '1.234', '-5', 'abc', '1e3', '9999999999.99', '10000000000.00', '0,05']),
    ['1250050', '1250050', '1250050', '1250000', '1250050', '1250', '199900', 'NULL', 'NULL', '0', 'refused', 'refused', 'refused', 'refused', '999999999999', 'refused', '5']);
$m338Try = static function (callable $f): string {
    try {
        return (string) $f();
    } catch (\DomainException) {
        return 'refused';
    }
};
check('3.38 Money::quantity and percent: thousandths and basis points, no thousands grouping, 0 and over 100 % refused', [
    Money::quantity(''), Money::quantity('2'), Money::quantity('2,5'), Money::quantity('1.250'), Money::quantity(3), Money::quantity(0.5), $m338Try(fn () => Money::quantity('0')),
    $m338Try(fn () => Money::quantity('1.2345')), Money::percent(''), Money::percent('21'), Money::percent('12,5 %'), Money::percent(5.25), $m338Try(fn () => Money::percent('100.01')),
    $m338Try(fn () => Money::percent('-1')), Money::percent('100'),
], [1000, 2000, 2500, 1250, 3000, 500, 'refused', 'refused', 0, 2100, 1250, 525, 'refused', 'refused', 10000]);
check('3.38 Money: DECIMAL texts both ways, the short form for people', [
    Money::toDecimal(1250050), Money::toDecimal(5), Money::toDecimal(-1999), Money::fromDecimal('12500.50'), Money::fromDecimal('-3.00'), Money::fromDecimal('7.1'), Money::fromDecimal(null),
    Money::quantityText(2500), Money::percentText(2100), Money::percentText(5), Money::short('21.00'), Money::short('2.500'), Money::short('12.50'),
], ['12500.50', '0.05', '-19.99', 1250050, -300, 710, null, '2.500', '21.00', '0.05', '21', '2.5', '12.5']);
check('3.38 Money::lineNet: one rounding of quantity × price × (1 − discount), half up', [
    Money::lineNet(1999, 3000, 1000), Money::lineNet(1000, 1000, 0), Money::lineNet(333, 3333, 0), Money::lineNet(5, 500, 0), Money::lineNet(5, 499, 0),
    Money::lineNet(12345, 1000, 10000), Money::lineNet(999999999999, 9999, 0), $m338Try(fn () => Money::lineNet(999999999999, 9999999999, 0)), $m338Try(fn () => Money::lineNet(100, 1000, 10001)),
], [5397, 1000, 1110, 3, 2, 0, 9998999999990, 'refused', 'refused']);
check('3.38 Money::vat and discounted: per rate, half up; mulDiv never overflows', [
    Money::vat(10000, 2100), Money::vat(12666, 2100), Money::vat(4750, 1200), Money::vat(1, 5000), Money::vat(999, 0), Money::discounted(3333, 500), Money::discounted(100, 0),
    Money::vat(99999999999999, 10000), Money::mulDiv(PHP_INT_MAX - 1, 1, 1),
], [2100, 2660, 570, 1, 0, 3166, 100, 99999999999999, PHP_INT_MAX - 1]);
$m338Lines = [['net' => 10000, 'rate' => 2100], ['net' => 5000, 'rate' => 1200], ['net' => 3333, 'rate' => 2100], ['net' => null, 'rate' => 0]];
check('3.38 Money::totals: the whole-quote discount on each line, VAT per rate on its sum; reverse charge and no VAT carry none; unpriced lines counted', [
    Money::totals($m338Lines, 500, 'standard'), Money::totals($m338Lines, 0, 'reverse_charge')['vat'], Money::totals($m338Lines, 0, 'none')['gross'],
    array_column(Money::totals($m338Lines, 0, 'reverse_charge')['rates'], 'rate'), Money::totals([], 0, 'standard'),
], [['net' => 17416, 'vat' => 3230, 'gross' => 20646, 'rates' => [['rate' => 2100, 'net' => 12666, 'vat' => 2660], ['rate' => 1200, 'net' => 4750, 'vat' => 570]], 'unpriced' => 1],
    0, 18333, [0], ['net' => 0, 'vat' => 0, 'gross' => 0, 'rates' => [], 'unpriced' => 0]]);

// Quotes: the totals as DECIMAL texts, the numbering, the statuses, the basket of an enquiry, why it cannot be sent
check('3.38 Quotes::computeTotals: the stored lines to DECIMAL texts per rate', Quotes::computeTotals([
    ['unit_price' => '100.00', 'quantity' => '2.000', 'discount_percent' => '0.00', 'vat_rate' => '21.00'],
    ['unit_price' => '19.99', 'quantity' => '3.000', 'discount_percent' => '10.00', 'vat_rate' => '12.00'],
    ['unit_price' => null, 'quantity' => '1.000', 'discount_percent' => '0.00', 'vat_rate' => '21.00']], '5', 'standard'),
    ['net' => '241.27', 'vat' => '46.05', 'gross' => '287.32', 'rates' => [['rate' => '21.00', 'net' => '190.00', 'vat' => '39.90'], ['rate' => '12.00', 'net' => '51.27', 'vat' => '6.15']], 'unpriced' => 1]);
check('3.38 Quotes::formatNumber: the year, the padded counter and a prefix (design A-7)', [
    Quotes::formatNumber('{YYYY}-{NNNN}', 2026, 12), Quotes::formatNumber('N{YYYY}-{NNNN}', 2026, 1), Quotes::formatNumber('Q{YY}/{NNNNNN}', 2026, 345), Quotes::formatNumber('', 2027, 3),
    Quotes::formatNumber('{YYYY}-{NN}', 2026, 1234),
], ['2026-0012', 'N2026-0001', 'Q26/000345', '2027-0003', '2026-1234']);
check('3.38 Quotes::formatRefusal: a format needs the year and one counter, plain characters around, at most 21 characters', array_map(fn (string $f): bool => Quotes::formatRefusal($f) === null,
    ['{YYYY}-{NNNN}', 'N{YYYY}-{NNNN}', 'Q {YY}/{NNN}', '{NNNN}', '{YYYY}', '{YYYY}-{NNNN}-{NN}', '<b>{YYYY}{NNNN}', 'VERYLONGPREFIX{YYYY}-{NNNN}']),
    [true, true, true, false, false, false, false, false]);
check('3.38 Quotes statuses: draft → sent only through markSent; accepted and declined go back to sent; expired is final; every status has a label', [
    Quotes::TRANSITIONS['draft'], Quotes::TRANSITIONS['sent'], Quotes::TRANSITIONS['accepted'], Quotes::TRANSITIONS['declined'], Quotes::TRANSITIONS['expired'],
    array_keys(Quotes::STATUS_LABELS) === Quotes::STATUSES, array_keys(Quotes::TRANSITIONS) === Quotes::STATUSES,
], [[], ['accepted', 'declined', 'expired'], ['sent'], ['sent'], [], true, true]);
check('3.38 Quotes::parseBasket: the basket lines a form stores, in any site language; other text ignored', [
    Quotes::parseBasket("3 × Lehátko Basic – Modrá (LB-1-M)\n1 × Lehátko Pro (LP-2)\nNotiz: bitte schnell"),
    Quotes::parseBasket("2 × Massage table – Grey\n12 × Liege Komfort (LK-9)\n0 × Nic (X)\n5 x Not a basket line"),
], [[['quantity' => 3, 'name' => 'Lehátko Basic', 'variant' => 'Modrá', 'code' => 'LB-1-M'], ['quantity' => 1, 'name' => 'Lehátko Pro', 'variant' => '', 'code' => 'LP-2']],
    [['quantity' => 2, 'name' => 'Massage table', 'variant' => 'Grey', 'code' => ''], ['quantity' => 12, 'name' => 'Liege Komfort', 'variant' => '', 'code' => 'LK-9']]]);
$m338Quote = ['status' => 'draft', 'lines' => [['x' => 1]], 'totals' => ['unpriced' => 0], 'vat_mode' => 'standard',
    'customer' => ['name' => 'Eva', 'company' => '', 'address' => '', 'reg_no' => '', 'vat_id' => '', 'email' => 'eva@example.cz']];
check('3.38 Quotes::sendRefusal: a draft with lines, every price, a customer, the VAT number for reverse charge, an e-mail for sending (not for Finalise)', [
    Quotes::sendRefusal($m338Quote), Quotes::sendRefusal(['status' => 'sent'] + $m338Quote) !== null, Quotes::sendRefusal(['lines' => []] + $m338Quote) !== null,
    Quotes::sendRefusal(['totals' => ['unpriced' => 2]] + $m338Quote) !== null, Quotes::sendRefusal(['vat_mode' => 'reverse_charge'] + $m338Quote) !== null,
    Quotes::sendRefusal(['customer' => ['email' => ''] + $m338Quote['customer']] + $m338Quote) !== null, Quotes::sendRefusal(['customer' => ['email' => ''] + $m338Quote['customer']] + $m338Quote, false),
    Quotes::sendRefusal(['customer' => ['name' => ''] + $m338Quote['customer']] + $m338Quote) !== null, Quotes::label(null, 7), Quotes::label('2026-0001', 7),
], [null, true, true, true, true, true, null, true, '#7', '2026-0001']);

// the feature, the section and the area: off by default, given to nobody by an update or a level, the area in Users and Roles
check('3.38: the feature quotes is off by default; the section is granted explicitly, in Workspace after the Pipeline; the prices area is configurable and nobody\'s default', [
    Kaleta\Core\Extensions::CATALOG['quotes'][2], Kaleta\Admin\Modules\Quotes::GRANTED_EXPLICITLY, Kaleta\Admin\Modules\Quotes::EXTENSION, Kaleta\Admin\Modules\Quotes::GROUP,
    array_search(Kaleta\Admin\Modules\Quotes::class, Kaleta\Admin\Kernel::MODULES, true) === array_search(Kaleta\Admin\Modules\Pipeline::class, Kaleta\Admin\Kernel::MODULES, true) + 1,
    array_intersect(['quotes', 'prices_edit'], [...Kaleta\Admin\Modules\Users::defaultModules(Kaleta\Core\Auth::EDITOR), ...Kaleta\Admin\Modules\Users::defaultModules(Kaleta\Core\Auth::AUTHOR)]),
    array_filter(Kaleta\Admin\Modules\Roles::PRESETS, fn (array $p): bool => array_intersect(['quotes', 'prices_edit'], $p[3]) !== []),
    array_key_exists(Kaleta\Core\Auth::PRICES_AREA, Kaleta\Admin\Modules\Roles::configurable()), Kaleta\Core\Auth::PRICES_AREA, Prices::SECTION, Quotes::SECTION,
    in_array('quotes', Kaleta\Admin\Kernel::SITE_NEUTRAL_MODULES, true),
], [false, true, 'quotes', 'Workspace', true, [], [], true, 'prices_edit', 'quotes', 'quotes', true]);
check('3.38: the settings of quotes have defaults, none reaches update_settings or a site export; the five tables are never journaled for undo', [
    array_diff(['quote_number_format', 'quote_next_number', 'quote_valid_days', 'quote_vat_payer', 'quote_vat_rate', 'quote_vat_rates', 'quote_max_discount', 'quote_intro', 'quote_terms',
        'quote_footer', 'quote_link_days', 'quotes_months', 'quote_set_deal_value'], array_keys(Kaleta\Core\Settings::DEFAULTS)),
    Kaleta\Core\Settings::DEFAULTS['quote_number_format'], Kaleta\Core\Settings::DEFAULTS['quotes_months'], Kaleta\Core\Settings::DEFAULTS['quote_max_discount'],
    array_values(array_filter(array_keys(Kaleta\Core\Settings::DEFAULTS), fn (string $k): bool => str_starts_with($k, 'quote')
        && preg_match((string) (new ReflectionClassConstant(Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue(), $k) === 1)),
    array_values(array_filter(Kaleta\Core\SiteExport::SETTINGS, fn (string $k): bool => str_starts_with($k, 'quote'))),
    array_diff(['price_lists', 'prices', 'quotes', 'quote_lines', 'quote_counters'], Kaleta\Core\AgentJournal::PERSONAL_TABLES),
], [[], '{YYYY}-{NNNN}', '36', '20', [], [], []]);

// MCP: two non-essential tools of the feature, outside the essentials and the work set (design A8), in the right lists
check('3.38 MCP: list_prices reads, save_price writes; both need the feature, live in ON_DEMAND only, English on both sides; save_price is site-neutral and idempotent', [
    Kaleta\Mcp\Catalog::TOOLS['list_prices'], Kaleta\Mcp\Catalog::TOOLS['save_price'], Kaleta\Mcp\Catalog::setsOf('list_prices'), Kaleta\Mcp\Catalog::setsOf('save_price'),
    in_array('list_prices', Kaleta\Mcp\Catalog::ON_DEMAND, true) && in_array('save_price', Kaleta\Mcp\Catalog::ON_DEMAND, true),
    Kaleta\Mcp\Catalog::isSiteNeutral('save_price'), Kaleta\Mcp\Catalog::isIdempotent('save_price'), Kaleta\Mcp\Catalog::isOpenWorld('save_price'),
    Kaleta\Mcp\Translator::czech('list_prices'), Kaleta\Mcp\Translator::czech('save_price'),
    mb_strlen(Kaleta\Mcp\Catalog::TITLES['list_prices']) <= 40 && mb_strlen(Kaleta\Mcp\Catalog::TITLES['save_price']) <= 40,
], [['read', 'quotes'], ['write', 'quotes'], [], [], true, true, true, false, 'list_prices', 'save_price', true]);

// A4: the structure that keeps prices private – only these files name the tables of prices and quotes
$m338Allowed = ['src/Core/Prices.php', 'src/Core/Quotes.php', 'src/Core/PersonalData.php', 'src/Core/Privacy.php', 'src/Admin/Modules/Quotes.php',
    'src/Mcp/Handlers/QuoteTools.php', 'src/Front/QuotePage.php',
    'src/Core/QuoteDelivery.php']; // 3.38 C: the customer's link, sending, the expiry job, the retention (the daily clean-up step calls it) and the personal data of quotes
$m338Names = static function (string $root): array {
    $found = [];
    foreach (['src', 'views'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            $code = (string) file_get_contents((string) $file);
            if (preg_match('/\{(?:prices|price_lists|quotes|quote_lines|quote_counters)\}|\bka_(?:prices|price_lists|quotes|quote_lines|quote_counters)\b|->(?:insert|update|delete)\(\s*\'(?:prices|price_lists|quotes|quote_lines|quote_counters)\'/', $code) === 1) {
                $found[] = substr((string) $file, strlen($root) + 1);
            }
        }
    }
    sort($found);

    return $found;
};
$m338Found = $m338Names(KALETA_SYSTEM);
check('3.38 A4: the tables of internal prices and quotes are named only by Prices, Quotes, PersonalData, Privacy, the Quotes module, QuoteTools and QuotePage (prices are not in items, so no other read path can print them)',
    [array_values(array_diff($m338Found, $m338Allowed)), in_array('src/Core/Prices.php', $m338Found, true), in_array('src/Core/Quotes.php', $m338Found, true)], [[], true, true]);
