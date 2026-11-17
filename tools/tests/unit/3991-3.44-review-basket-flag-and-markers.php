<?php

declare(strict_types=1);

use Kaleta\Core\Prices;
use Kaleta\Mcp\Untrusted;

/* ---------- 3.44 review: the basket flag of a mapped collection (M1), the markers of quotes (L1) ---------- */
// The database paths (the basket on a page, Claude's prices, the Send warning) are in smoke 3990; here the pure rules.
check('3.44 review M1: the mapping keeps an explicit basket choice and nothing when none was made', [
    Prices::parseMapping('[{"collection":4,"code":"kod","variants":"","basket":false},{"collection":5,"basket":true},{"collection":6,"basket":"yes"}]')],
    [[['collection' => 4, 'code' => 'kod', 'variants' => '', 'basket' => false], ['collection' => 5, 'code' => '', 'variants' => '', 'basket' => true], ['collection' => 6, 'code' => '', 'variants' => '']]]);
check('3.44 review M1: a mapped collection is offered in the basket by its own choice, otherwise only with public item pages', [
    Prices::offeredInBasket(['basket' => true], ['detail' => 0]), Prices::offeredInBasket(['basket' => false], ['detail' => 1]),
    Prices::offeredInBasket([], ['detail' => 1]), Prices::offeredInBasket([], ['detail' => 0]), Prices::offeredInBasket([], [])],
    [true, false, true, false, false]);
check('3.44 review L1: my_work marks its quote lists, get_quote the texts a drafts-only connection writes', [
    array_values(array_diff(['quotes_drafts', 'quotes_waiting', 'quotes_expiring'], Untrusted::TOOLS['my_work'])),
    array_values(array_diff(['intro', 'terms', 'history'], Untrusted::TOOLS['get_quote']))], [[], []]);
check('3.44 review L2: the refusal for an address Claude wrote through the customer record is one constant the Send page compares', [
    str_contains(Kaleta\Core\QuoteDelivery::CLAUDE_WROTE_ADDRESS, 'written by a Claude connection')], [true]);
