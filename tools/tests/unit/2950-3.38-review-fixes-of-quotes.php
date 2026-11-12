<?php

declare(strict_types=1);

use Kaleta\Core\QuoteDelivery;

/* ---------- 3.38 review: the fixes of the security review of internal prices and quotes ---------- */

// L1: the customer's token is 32 random bytes per link – never derived from the quote, its expiry or the secret key
$r338Tokens = array_map(fn (int $i): string => QuoteDelivery::newToken(), range(1, 200));
check('3.38 review L1: a link token is 64 hex characters of fresh randomness – 200 links, 200 different tokens', [
    count(array_filter($r338Tokens, fn (string $t): bool => preg_match('/^[a-f0-9]{64}$/D', $t) === 1)), count(array_unique($r338Tokens)),
    (new ReflectionClass(QuoteDelivery::class))->hasMethod('token')], [200, 200, false]);
