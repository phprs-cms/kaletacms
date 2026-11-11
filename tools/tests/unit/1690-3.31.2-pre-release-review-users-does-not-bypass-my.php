<?php

declare(strict_types=1);

/* ---------- 3.31.2 (pre-release review): Users does not bypass My account; another user's password or second factor needs the administrator's password ---------- */
$s3312Users = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Users.php');
$s3312Form = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/users/form.php');
check('3.31.2: Users ignores one\'s own e-mail, password and two-factor reset; for another account a new password or a turned-off second factor is confirmed with the administrator\'s own password before anything is written', [
    str_contains($s3312Users, "\$data['email'] = (string) \$this->db->value('SELECT email FROM {uzivatele} WHERE idu = ?', [\$id]);"),
    str_contains($s3312Users, "\$password = !\$isSelf && is_string(\$_POST['password'] ?? null)"),
    str_contains($s3312Users, "\$totpReset = \$id > 0 && !\$isSelf && \$r->postBool('totp_reset');"),
    str_contains($s3312Users, "if (\$errors === [] && \$id > 0 && (\$password !== '' || \$totpReset || \$emailChanged)) {"),
    strpos($s3312Users, '->confirmPassword(') < strpos($s3312Users, "\$data['totp_tajemstvi'] = '';"),
    strpos($s3312Users, '->confirmPassword(') < strpos($s3312Users, 'DELETE FROM {uzivatele_klice}'),
    strpos($s3312Users, 'DELETE FROM {uzivatele_klice}') > strpos($s3312Users, '$this->db->transaction('),
    substr_count($s3312Form, 'name="soucasne"'), str_contains($s3312Form, "<?php if (!empty(\$author['totp_tajemstvi']) && !\$isSelf): ?>"),
    str_contains($s3312Form, "<?php if (\$author['idu'] && !\$isSelf):")],
    [true, true, true, true, true, true, true, 1, true, true]);
$s3312Texts = ["Enter your own current password to set this user's password or turn off their two-factor sign-in. Nothing was saved.",
    'Change your own e-mail address, password and two-factor sign-in in My account – it asks for your current password.', 'Your current password',
    'Needed only to set a new password for this user or to turn off their two-factor sign-in.'];
check('3.31.2: the texts of the Users form are translated (cs, de, es) and the ones that address the reader have an informal German form', [
    array_values(array_filter($s3312Texts, fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k], $a3312Es[$k]))),
    array_values(array_filter($s3312Texts, fn (string $k): bool => preg_match('/\b(Sie|Ihr|Ihre|Ihr)\b/', $adminDe[$k] ?? '') === 1 && !isset($a3312Du[$k])))], [[], []]);

