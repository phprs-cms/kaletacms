<?php

declare(strict_types=1);

// 3.35 review M1: a lock after wrong passwords never blocks a valid passkey – only a blocked account is closed to it
$r351Auth = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
$r351Fn = static function (string $name) use ($r351Auth): string {
    $start = strpos($r351Auth, 'function ' . $name . '(');
    $end = $start === false ? false : strpos($r351Auth, "\n    }\n", $start);

    return $start === false || $end === false ? '' : substr($r351Auth, $start, $end - $start);
};
check('3.35 review M1: the passkey sign-in closes only a BLOCKED account; a locked one is verified and counts on the same counter', [
    str_contains($r351Fn('signInWithPasskey'), "\$closed = \$user !== null && !empty(\$user['blokovat']);"),
    str_contains($r351Fn('signInWithPasskey'), 'isLocked('),
    str_contains($r351Fn('signInWithPasskey'), "if (\$user !== null && !\$closed && \$challenge !== '') {\n                \$this->countError(\$user);"),
    str_contains($r351Fn('signInWithPasskey'), 't(self::PASSKEY_FAILED)'),
], [true, false, true, true]);
check('3.35 review M1: the passkey second step and the passkey confirmation are not stopped by the lock; the code step still is', [
    str_contains($r351Fn('verifyKey'), 'zamceno_do'), str_contains($r351Fn('verifyKey'), 'blokovat = 0'),
    str_contains($r351Fn('confirmWithPasskey'), "if (\$attempts >= 10) {\n            return 'locked';"),
    (bool) preg_match('/markVerified\(true\);/', $r351Fn('confirmWithPasskey')) && str_contains($r351Fn('confirmWithPasskey'), "['pocet_chyb' => 0, 'zamceno_do' => null]"),
    str_contains($r351Fn('verifyCode'), "strtotime(\$user['zamceno_do']) > time()"),
    str_contains($r351Fn('login'), "self::isLocked(\$user)"),
], [false, true, true, true, true, true]);
check('3.35 review M1: a completed sign-in ends the lock with the failure counter', str_contains($r351Fn('recordSignIn'), "['pocet_chyb' => 0, 'zamceno_do' => null, 'posledni_login' =>"), true);
