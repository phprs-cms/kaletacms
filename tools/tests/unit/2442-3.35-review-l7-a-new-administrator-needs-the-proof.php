<?php

declare(strict_types=1);

// 3.35 review L7: a new administrator – a new account at that level, raising an account to it, or every section by hand –
// needs the acting administrator's fresh proof (the current password; the strong proof for a passkey-only administrator)
use Kaleta\Admin\Modules\Users;

$r359All = ['pages', 'news', 'enquiries'];
check('3.35 review L7: Users::elevates – the administrator level for a new or a lower account, every section by hand when it lacked one', [
    Users::elevates(null, 2, null, [], $r359All), Users::elevates(1, 2, null, [], $r359All), Users::elevates(2, 2, null, [], $r359All),
    Users::elevates(null, 1, null, [], $r359All), Users::elevates(null, 0, ['pages', 'news', 'enquiries'], [], $r359All),
    Users::elevates(1, 1, ['pages', 'news', 'enquiries', 'x'], ['pages'], $r359All), Users::elevates(1, 1, ['pages', 'news', 'enquiries'], ['pages', 'news', 'enquiries'], $r359All),
    Users::elevates(1, 1, ['pages', 'news'], ['pages'], $r359All),
], [true, true, false, false, true, true, false, false]);
$r359Users = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Users.php');
check('[source-pin] 3.35 review L7: the elevation joins the changes confirmed by Auth::freshProof before anything is written; never for one\'s own account', [
    str_contains($r359Users, '$elevates = !$isSelf && self::elevates('),
    str_contains($r359Users, "if (\$errors === [] && ((\$id > 0 && (\$password !== '' || \$totpReset || \$emailChanged)) || \$elevates)) {"),
    strpos($r359Users, '->freshProof(') < strpos($r359Users, '$this->db->transaction('),
    str_contains($r359Users, "'passkey' => Auth::PASSKEY_PROOF,"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/users/form.php'), 'data-klic-potvrdit'),
], [true, true, true, true, true]);
check('3.35 review L7: the refusal is translated (cs, de, es, de-du)', [
    array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code, fn (): bool => t(Users::ELEVATION_NEEDS_PASSWORD) !== Users::ELEVATION_NEEDS_PASSWORD, 'admin-'), ['cs', 'de', 'es']),
    isset((require KALETA_SYSTEM . '/jazyky/admin-de-du.php')[Users::ELEVATION_NEEDS_PASSWORD]),
], [[true, true, true], true]);
