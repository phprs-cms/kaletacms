<?php

declare(strict_types=1);

/* ---------- 3.31.2 (N100, N101): the second factor and a personal token need the current password; a password change revokes personal tokens by default ---------- */
$a3312 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
$a3312Case = fn (string $case): string => substr($a3312, (int) strpos($a3312, "case '" . $case . "':"), 2200);
$a3312Verify = '$this->passwordRefused(\'';
check('3.31.2 N100: a new second factor is refused while one is set, and confirming one checks the password before the code and before anything is written', [
    (bool) preg_match("/case 'totp_start':\s+case 'totp_potvrd':/", $a3312),
    strpos($a3312Case('totp_start'), "\$user['totp_tajemstvi'] !== ''") < strpos($a3312Case('totp_start'), 'Totp::newSecret()'),
    strpos($a3312Case('totp_start'), $a3312Verify) < strpos($a3312Case('totp_start'), 'Totp::verify($secret'),
    strpos($a3312Case('totp_start'), $a3312Verify) < strpos($a3312Case('totp_start'), "'totp_tajemstvi' => \$secret")],
    [true, true, true, true]);
check('3.31.2 N101: a token is made only after the password check; a password change keeps personal tokens only on an explicit "0" – in My account and in Users', [
    strpos($a3312Case('token_novy'), $a3312Verify) < strpos($a3312Case('token_novy'), 'random_bytes(24)'),
    array_map(Kaleta\Admin\Account::keepsTokens(...), ['0', '1', '', 'off', 'false']),
    str_contains($a3312Case('heslo'), "self::keepsTokens(\$r->post('zrusit_tokeny')) ? 0 : \$db->delete('api_tokeny', ['idu' => \$user['idu'], 'druh' => 'token'])"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Users.php'), "\$revokeTokens = !\\Kaleta\\Admin\\Account::keepsTokens(\$r->post('zrusit_tokeny'));"),
    array_map(fn (string $view): int => substr_count((string) file_get_contents(KALETA_SYSTEM . '/views/admin/' . $view), '<input type="hidden" name="zrusit_tokeny" value="0">'), ['account.php', 'users/form.php']),
    // OAuth connections end with every password change as before (N39-1) – that line did not become optional
    str_contains($a3312Case('heslo'), '\Kaleta\Front\OAuth::revokeConnections($db, (int) $user[\'idu\'])')],
    [true, [true, false, false, false, false], true, true, [1, 1], true]);
$a3312Texts = ['Two-factor sign-in is already on. To set it up again, turn it off first.', 'Enter your current password to turn on two-factor sign-in. Nothing was changed.', 'Needed to turn on two-factor sign-in.',
    'Enter your current password to create a token. Nothing was created.', 'Needed to create a token – a token works without the password and without two-factor sign-in.'];
$a3312Es = require KALETA_SYSTEM . '/jazyky/admin-es.php';
$a3312Du = require KALETA_SYSTEM . '/jazyky/admin-de-du.php';
check('3.31.2 N100/N101: the new texts are translated (cs, de, es) and the ones that address the reader have an informal German form', [
    array_values(array_filter($a3312Texts, fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k], $a3312Es[$k]))),
    array_values(array_filter($a3312Texts, fn (string $k): bool => str_contains($adminDe[$k] ?? '', 'Sie') && !isset($a3312Du[$k])))], [[], []]);

