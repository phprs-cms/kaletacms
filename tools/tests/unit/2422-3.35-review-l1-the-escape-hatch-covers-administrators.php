<?php

declare(strict_types=1);

// 3.35 review L1: KALETA_PASSKEY_ONLY_OFF also switches the passkey requirement off for administrators – pinned here: without
// the constant (as in every test run) nothing changes
use Kaleta\Core\SignInPolicy;

$r354Key = [['rp_id' => '', 'discoverable' => 1]];
check('3.35 review L1: without the constant an account with a passkey always needs the second step – administrators too', [
    defined(SignInPolicy::ESCAPE_HATCH), SignInPolicy::escapeHatch(),
    SignInPolicy::needsSecondStep(['admin' => 2, 'totp_tajemstvi' => '', 'totp_zalozni' => null], $r354Key),
    SignInPolicy::needsSecondStep(['admin' => 1, 'totp_tajemstvi' => '', 'totp_zalozni' => null], $r354Key),
    SignInPolicy::needsSecondStep(['admin' => 2, 'totp_tajemstvi' => 'ABC', 'totp_zalozni' => null], []),
    SignInPolicy::needsSecondStep(['admin' => 2, 'totp_tajemstvi' => '', 'totp_zalozni' => null], []),
], [false, false, true, true, true, false]);
$r354Policy = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/SignInPolicy.php');
check('[source-pin] 3.35 review L1: with the constant only an administrator with no app and no backup codes is let in by the password', [
    str_contains($r354Policy, "return \$keys !== [] && !(self::escapeHatch() && (int) (\$user['admin'] ?? -1) === Auth::ADMIN && self::backupCodesLeft(\$user) === 0);"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php'), "if (\\Kaleta\\Core\\SignInPolicy::escapeHatch() && \$app->auth()->isAdmin() && !\$request->isPost()) {"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php'), 'if (SignInPolicy::escapeHatch()) {'),
], [true, true, true]);
check('3.35 review L1: the constant is documented – config.sample.php, README, the guide in both languages', [
    str_contains((string) file_get_contents(KALETA_ROOT . '/config.sample.php'), "// define('KALETA_PASSKEY_ONLY_OFF', true);"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/README.md'), "define('KALETA_PASSKEY_ONLY_OFF', true);"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/docs/guide.md'), "define('KALETA_PASSKEY_ONLY_OFF', true);"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/docs/prirucka.md'), "define('KALETA_PASSKEY_ONLY_OFF', true);"),
], [true, true, true, true]);
check('3.35 review L1: the warning is translated (cs, de, es)', array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code, fn (): bool => t(Kaleta\Admin\Kernel::ESCAPE_HATCH_WARNING) !== Kaleta\Admin\Kernel::ESCAPE_HATCH_WARNING, 'admin-'), ['cs', 'de', 'es']), [true, true, true]);
