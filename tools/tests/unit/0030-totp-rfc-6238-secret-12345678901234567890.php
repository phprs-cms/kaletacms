<?php

declare(strict_types=1);

use Kaleta\Core\Totp;

/* ---------- TOTP (RFC 6238, secret "12345678901234567890") ---------- */
$totpSeed = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check('TOTP: vektor T=59', Totp::code($totpSeed, intdiv(59, 30)), '287082');
check('TOTP: vektor T=1111111109', Totp::code($totpSeed, intdiv(1111111109, 30)), '081804');
check('TOTP: vektor T=2000000000', Totp::code($totpSeed, intdiv(2000000000, 30)), '279037');
check('TOTP: platný kód projde', Totp::verify($totpSeed, '287082', 59), true);
check('TOTP: sousední okno projde', Totp::verify($totpSeed, '287082', 59 + 30), true);
check('TOTP: starý kód neprojde', Totp::verify($totpSeed, '287082', 59 + 300), false);
check('TOTP: nesmysl neprojde', Totp::verify($totpSeed, 'abcdef', 59), false);
check('TOTP: nové tajemství má 160 bitů', strlen(Totp::newSecret()), 32);

