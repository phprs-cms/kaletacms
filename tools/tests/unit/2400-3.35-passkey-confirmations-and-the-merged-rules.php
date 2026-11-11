<?php

declare(strict_types=1);

// 3.35 (integration of A and B): one fresh-proof concept in Core\Auth, new backup codes in My account, the passkey
// confirmation of a full connection, and the 3.34 applications under B's rules (untrusted marker, exact address, publish rule)
use Kaleta\Core\Auth;

$i340Auth = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
$i340Policy = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/SignInPolicy.php');
check('3.35: one fresh-proof concept – one window, two strengths; markVerified is the only writer of the session proofs', [
    Auth::VERIFIED_KEY, Auth::STRONG_KEY, Auth::VERIFIED_WINDOW, str_contains($i340Policy, 'FRESH_SECONDS'), str_contains($i340Policy, 'function isFresh'),
    substr_count($i340Auth, "->session->set('verified_at'") + substr_count($i340Auth, "->session->set('strong_at'"),
    // a passkey step, the passkey-first sign-in, a backup code and a passkey confirmation are strong; a step with the app code is not
    substr_count($i340Auth, '$this->markVerified(true);'), (bool) preg_match('/function verifyCode\(.*?if \(\$usedBackupCode\) \{\s*\$this->markVerified\(true\);/s', $i340Auth),
    (bool) preg_match('/function startSignIn\(.*?\$this->session->remove\(self::STRONG_KEY\);[^\n]*\n\s*\$this->markVerified\(\);/s', $i340Auth),
    (bool) preg_match('/function isFreshlyVerified\(.*?self::STRONG_KEY.*?verifiedWithin\(/s', $i340Auth),
], ['verified_at', 'strong_at', 600, false, false, 0, 4, true, true, true]);
check('3.35: a passkey confirmation – the account\'s own passkeys with user verification, one challenge per attempt, counted like a wrong password', [
    (bool) preg_match('/function confirmChallenge\(.*?accountKeys\(\$this->id\(\)\).*?signInOptions\(.*?, true\);/s', $i340Auth),
    (bool) preg_match('/function confirmWithPasskey\(.*?->session->remove\(\'passkey_confirm\'\).*?WHERE idu = \? AND otisk_id = \?.*?verifySignIn\(.*?, true\);.*?countError\(\$user\).*?markVerified\(true\)/s', $i340Auth),
], [true, true]);

// new backup codes (owner decision 11 Nov 2026)
check('3.35: “New backup codes” only for an account with a passkey and without the authenticator app', [
    Kaleta\Admin\Account::mayRenewBackupCodes(['totp_tajemstvi' => ''], [['idk' => 1]]), Kaleta\Admin\Account::mayRenewBackupCodes(['totp_tajemstvi' => 'ABC'], [['idk' => 1]]),
    Kaleta\Admin\Account::mayRenewBackupCodes(['totp_tajemstvi' => ''], []), Kaleta\Admin\Account::mayRenewBackupCodes([], [['idk' => 1]]),
], [true, false, false, true]);
$i340Account = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
check('3.35: new backup codes need the fresh confirmation, replace all the old ones and are shown once (no redirect)', [
    (bool) preg_match("/case 'backup_codes_new':.*?mayRenewBackupCodes\(.*?freshnessRefused\(.*?Totp::backupCodes\(\);\s*\\\$db->update\('uzivatele', \['totp_zalozni' => \\\$json\].*?return \\\$this->page\(\['backupCodes' => \\\$codes/s", $i340Account),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/account.php'), '<input type="hidden" name="co" value="backup_codes_new">'),
], [true, true]);

// the OAuth consent: passkey confirmation
$i340Kernel = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
$i340View = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/oauth.php');
check('3.35: the consent confirms with the passkey (klice.js data-klic-potvrdit) and a passkey-only account gets no password field', [
    str_contains($i340Kernel, "in_array(\$app->request->post('krok'), ['passkey_confirm_options', 'passkey_confirm'], true)"),
    str_contains($i340View, 'data-klic-potvrdit'), str_contains((string) file_get_contents(KALETA_ROOT . '/image/klice.js'), "button.hasAttribute('data-klic-potvrdit')"),
    str_contains($i340View, '<?php if (!$verified && !$passkeyOnly):'),
], [true, true, true, true]);
$i340Texts = [Kaleta\Admin\Kernel::FULL_ACCESS_PASSKEY, Kaleta\Admin\Kernel::PASSKEY_CONFIRM_FAILED, Kaleta\Admin\Kernel::PASSKEY_CONFIRM_LOCKED, Kaleta\Admin\Account::RENEW_CODES_REFUSAL,
    'Confirm with your passkey', 'New backup codes', 'Your new backup codes', 'Enter your current password to create new backup codes. Nothing was changed.', 'new backup codes created', 'confirmed with a passkey'];
check('3.35: the new texts are translated (cs, de, es)', array_map(fn (string $code): array => Kaleta\Core\Language::runWith($code, fn (): array => array_values(array_filter($i340Texts, fn (string $k): bool => t($k) === $k)), 'admin-'), ['cs', 'de', 'es']),
    [[], [], []]);

// 3.34 applications under B's rules
$i340Apps = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/ApplicationTools.php');
check('3.35 (N105): the application tools carry the one untrusted marker – the list names, the detail what the applicant wrote', [
    str_contains($i340Apps, "Untrusted::mark(['applications'])"), str_contains($i340Apps, "Untrusted::mark(['name', 'email', 'phone', 'fields'])"), str_contains($i340Apps, "'untrusted' =>"),
], [true, true, false]);
check('3.35 (N94): personal-data requests find applications by their own whole address only – never one that merely contains it', [
    (bool) preg_match('/function ofEmail\(.*?WHERE email_norm = \? OR LOWER\(email\) = \?/s', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php')),
    (bool) preg_match('/function ofEmail\(.*?\bLIKE\b.*?return array_map/s', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php')),
], [true, false]);
check('3.35 (N107): the ready-made role HR stays at the editor level – B\'s publish rule leaves the job openings in Collections to it', [
    Kaleta\Admin\Modules\Roles::PRESETS['hr'][2], in_array('collections', Kaleta\Admin\Modules\Roles::PRESETS['hr'][3], true), Kaleta\Admin\Modules\Roles::PRESETS['writer'][2],
], [Auth::EDITOR, true, Auth::AUTHOR]);
check('3.35 (N104): no application tool reaches the network – none is open-world, so the fetch rule has nothing to add', [
    array_values(array_filter(['list_applications', 'get_application', 'update_application', 'reply_to_application'], fn (string $t): bool => (Kaleta\Mcp\Catalog::META[$t] ?? [])['openWorld'] ?? false)),
], [[]]);
