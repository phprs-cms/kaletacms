<?php

declare(strict_types=1);

// 3.35 review M2: an account that signs in with a passkey only is never confirmed by its password – My account and the
// OAuth consent take the one rule of Auth::freshProof (the strong proof, or "Confirm with your passkey")
$r352Auth = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
$r352Account = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
$r352Kernel = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
$r352View = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/account.php');
check('3.35 review M2: Auth::freshProof – a passkey-only account only with the strong proof, the password not even checked', [
    (bool) preg_match("/function freshProof\(.*?if \(\\\$this->isPasskeyOnly\(\)\) \{\s*return \\\$this->isFreshlyVerified\(\) \? 'ok' : 'passkey';\s*\}.*?return \\\$this->confirmPassword\(\\\$typed\);/s", $r352Auth),
    (bool) preg_match('/function isPasskeyOnly\(.*?SignInPolicy::passkeyOnly\(/s', $r352Auth),
], [true, true]);
check('3.35 review M2: every confirmation of My account goes through freshProof (password or strong), the OAuth consent too (recent)', [
    substr_count($r352Account, '->freshProof('), substr_count($r352Account, '->confirmPassword('),
    (bool) preg_match("/function passwordRefused\(string \\\$wrong\): \?array\s*\{\s*return \\\$this->proofRefused\(\\\$wrong, 'password'\);/", $r352Account),
    (bool) preg_match("/function freshnessRefused\(string \\\$wrong\): \?array\s*\{\s*return \\\$this->proofRefused\(\\\$wrong, 'strong'\);/", $r352Account),
    str_contains($r352Account, "'passkey' => ['chyba', \\Kaleta\\Core\\Auth::PASSKEY_PROOF],"),
    (bool) preg_match("/function fullAccessRefusal\(.*?->freshProof\(.*?'recent'\).*?'passkey' => self::FULL_ACCESS_PASSKEY/s", $r352Kernel),
    str_contains($r352Kernel, 'passkeyConfirmOnly'), str_contains($r352Kernel, "'passkeyOnly' => \$app->auth()->isPasskeyOnly(),"),
], [1, 0, true, true, true, true, false, true]);
// every sensitive action of My account keeps its confirmation (e-mail, password, token, app on/off, passkeys, passkey only, codes)
foreach (["case 'profil':" => 'passwordRefused(', "case 'heslo':" => 'passwordRefused(', "case 'token_novy':" => 'passwordRefused(', "case 'totp_start':" => 'passwordRefused(',
    "case 'klic_smaz':" => 'freshnessRefused(', "case 'passkey_only':" => 'freshnessRefused(', "case 'backup_codes_new':" => 'freshnessRefused(', "case 'totp_vypni':" => 'passwordRefused('] as $r352Case => $r352Call) {
    $r352Start = strpos($r352Account, $r352Case);
    check('3.35 review M2: ' . $r352Case . ' asks ' . $r352Call, $r352Start !== false && str_contains(substr($r352Account, $r352Start, (strpos($r352Account, '                case ', $r352Start + 20) ?: strlen($r352Account)) - $r352Start) /* 3.36: the last case runs to the end of the file */, $r352Call), true);
}
check('3.35 review M2: My account confirms with the passkey (the endpoints of the consent) and its forms hide the password fields', [
    str_contains($r352Account, "in_array(\$r->post('krok'), ['passkey_confirm_options', 'passkey_confirm'], true)"),
    (bool) preg_match('/function confirmWithPasskey\(\): Response.*?confirmChallenge\(.*?->confirmWithPasskey\(.*?passkey_confirm/s', $r352Account),
    str_contains($r352View, 'data-klic-potvrdit'), substr_count($r352View, '<?= $proofNote ?>'),
], [true, true, true, 8]);
check('3.35 review M2: the new texts are translated (cs, de, es, de-du)', array_map(fn (string $code): array => Kaleta\Core\Language::runWith($code, fn (): array => [
    t(Kaleta\Core\Auth::PASSKEY_PROOF) !== Kaleta\Core\Auth::PASSKEY_PROOF, t(Kaleta\Admin\Account::PASSKEY_CONFIRM_FAILED) !== Kaleta\Admin\Account::PASSKEY_CONFIRM_FAILED,
], 'admin-'), ['cs', 'de', 'es']), [[true, true], [true, true], [true, true]]);
$r352Du = require KALETA_SYSTEM . '/jazyky/admin-de-du.php';
check('3.35 review M2: the informal German form', isset($r352Du[Kaleta\Core\Auth::PASSKEY_PROOF], $r352Du[Kaleta\Admin\Account::PASSKEY_CONFIRM_FAILED]), true);
