<?php

declare(strict_types=1);

// 3.35 review L2: turning "passkeys only" off for the site and changing the two-factor rule need a fresh proof (the strong
// proof for an administrator who signs in with a passkey only) – Settings::signInChangeRefusal, Auth::freshProof
$r355Settings = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php');
$r355Fn = substr($r355Settings, (int) strpos($r355Settings, 'private function signInChangeRefusal('), 3000);
check('[source-pin] 3.35 review L2: signin_passkey_only from on to off and any change of require_2fa join the changes that need the proof', [
    str_contains($r355Fn, "\$settings->get('signin_passkey_only') === '1' && !\$this->request->postBool('signin_passkey_only')"),
    str_contains($r355Fn, "\$this->request->post('require_2fa') !== \$settings->get('require_2fa')"),
    (bool) preg_match("/->freshProof\(.*?'recent'\).*?'passkey' => self::PROOF_PASSKEY,.*?return \[\[\.\.\.\\\$unconfirmed, \.\.\.\\\$sensitive\], /s", $r355Fn), // 3.35 re-review N1
    str_contains($r355Settings, "[\$refused, \$refusedReason] = \$this->signInChangeRefusal(\$fields);"),
], [true, true, true, true]);
check('[source-pin] 3.35 review L2: Security shows the proof (password field, or the passkey confirmation posted to My account)', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/security.php'), "\$proofId = 'security'; require __DIR__ . '/proof.php';"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/proof.php'), 'data-klic-potvrdit'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/list.php'), "' data-klice=\"' . e(\$app->url('admin.php?action=account')) . '\"'"),
], [true, true, true]);
