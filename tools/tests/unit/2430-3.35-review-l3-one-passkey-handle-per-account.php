<?php

declare(strict_types=1);

// 3.35 review L3: the account's opaque passkey handle is created by a compare-and-swap and read back – two option requests
// at the same moment end with one handle
$r356Account = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
check('3.35 review L3: UPDATE … WHERE passkey_handle = \'\' and a fresh read; no blind update of the handle', [
    str_contains($r356Account, "\$db->run(\"UPDATE {uzivatele} SET passkey_handle = ? WHERE idu = ? AND passkey_handle = ''\", [Passkey::newUserHandle(), \$user['idu']]);"),
    str_contains($r356Account, "\$handle = (string) \$db->value('SELECT passkey_handle FROM {uzivatele} WHERE idu = ?', [\$user['idu']]);"),
    str_contains($r356Account, "['passkey_handle' => \$handle]"),
], [true, true, false]);
