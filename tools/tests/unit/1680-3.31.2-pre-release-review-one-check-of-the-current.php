<?php

declare(strict_types=1);

/* ---------- 3.31.2 (pre-release review): one check of the current password, on the sign-in's counter and lock ---------- */
$c3312Auth = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
$c3312Confirm = substr($c3312Auth, (int) strpos($c3312Auth, 'public function confirmPassword('), 900);
$c3312User = substr($c3312Auth, (int) strpos($c3312Auth, 'public function user(): ?array'), 2200);
check('3.31.2: every "current password" goes through Auth::confirmPassword – no password_verify of its own in My account; the lock is checked before the password, a wrong one counts like a wrong sign-in, an empty one is not counted, a right one resets nothing, and the lock never ends the sign-in', [
    substr_count($a3312, 'password_verify('), substr_count($a3312, '->confirmPassword(') + substr_count($a3312, '->freshProof('), // 3.35 review M2: Auth::freshProof calls confirmPassword
    strpos($c3312Confirm, 'self::isLocked($user)') < strpos($c3312Confirm, 'self::matchingPassword('),
    str_contains($c3312Confirm, "return \$this->countError(\$user) ? 'locked' : 'wrong';"),
    strpos($c3312Confirm, "if (\$typed === '') {") < strpos($c3312Confirm, 'countError('),
    str_contains($c3312Confirm, 'pocet_chyb\' => 0') || str_contains($c3312Confirm, 'recordSignIn('),
    str_contains($c3312User, 'zamceno_do'),
    str_contains($a3312, "'locked' => ['chyba', \\Kaleta\\Core\\Auth::CONFIRM_LOCKED],")],
    [0, 1, true, true, true, false, false, true]);
check('3.31.2: the message of the lock is translated (cs, de, es) with an informal German form', [
    isset($adminCs[Kaleta\Core\Auth::CONFIRM_LOCKED], $adminDe[Kaleta\Core\Auth::CONFIRM_LOCKED], $a3312Es[Kaleta\Core\Auth::CONFIRM_LOCKED], $a3312Du[Kaleta\Core\Auth::CONFIRM_LOCKED])], [true]);

