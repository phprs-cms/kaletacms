<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Users;

/* ---------- 3.35 B2: another user's e-mail changes only with the administrator's own password (owner decision 11 Nov 2026) ---------- */
check('3.35 B2: what counts as a changed e-mail – letter case and spaces do not, a removed or another address does', [
    Users::emailChanges('jana@example.com', 'jana@example.com'), Users::emailChanges('jana@example.com', ' Jana@Example.COM '),
    Users::emailChanges('jana@example.com', ''), Users::emailChanges('', 'jana@example.com'), Users::emailChanges('jana@example.com', 'jana@example.org'), Users::emailChanges('', ''),
], [false, false, true, true, true, false]);
$i335bUsers = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Users.php');
check('3.35 B2: the changed e-mail of another account joins the password and the second factor before confirmPassword, never one\'s own', [
    str_contains($i335bUsers, "\$emailChanged = \$id > 0 && !\$isSelf && self::emailChanges("),
    strpos($i335bUsers, '$emailChanged = ') < strpos($i335bUsers, '->confirmPassword('),
    str_contains($i335bUsers, '$errors[\'soucasne\'] = $check === \'locked\' ? Auth::CONFIRM_LOCKED : self::PASSWORD_NEEDED;'),
], [true, true, true]);
$i335bTexts = [Users::PASSWORD_NEEDED, 'Needed only to change this user\'s e-mail, set a new password for them or turn off their two-factor sign-in.'];
check('3.35 B2: the texts are translated (cs, de, es); the one that addresses the reader has an informal German form', [
    array_values(array_filter($i335bTexts, fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k], $a3312Es[$k]))),
    isset($a3312Du[Users::PASSWORD_NEEDED]),
], [[], true]);
