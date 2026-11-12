<?php

declare(strict_types=1);

use Kaleta\Core\QuoteDelivery;

/* ---------- 3.38 review: the fixes of the security review of internal prices and quotes ---------- */

// L1: the customer's token is 32 random bytes per link – never derived from the quote, its expiry or the secret key
$r338Tokens = array_map(fn (int $i): string => QuoteDelivery::newToken(), range(1, 200));
check('3.38 review L1: a link token is 64 hex characters of fresh randomness – 200 links, 200 different tokens', [
    count(array_filter($r338Tokens, fn (string $t): bool => preg_match('/^[a-f0-9]{64}$/D', $t) === 1)), count(array_unique($r338Tokens)),
    (new ReflectionClass(QuoteDelivery::class))->hasMethod('token')], [200, 200, false]);

// L2: no address under /quote/ is ever kept among the addresses not found (a mangled link still carries its token)
check('3.38 review L2: every path under /quote/ is private – mangled, with a language prefix, in upper case; other addresses are not', array_map(Kaleta\Core\NotFound::isPrivate(...), [
    'quote/' . str_repeat('a', 64) . '/', '/quote/' . str_repeat('b', 63), 'en/quote/x', '/de-at/Quote/x', 'QUOTE/abc', 'quotes/x', 'kontakt', 'blog/quote/x', 'quote']),
    [true, true, true, true, true, false, false, false, false]);
