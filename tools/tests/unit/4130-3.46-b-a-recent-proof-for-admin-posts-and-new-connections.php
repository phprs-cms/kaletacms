<?php

declare(strict_types=1);

use Kaleta\Admin\FreshProof;

/* ---------- 3.46 B: one default-deny rule – a recent proof for every POST of an administrator's section (N127, N136, I39) ---------- */
$u4130Modules = [];
foreach (Kaleta\Admin\Kernel::MODULES as $u4130Class) {
    $u4130Modules[$u4130Class::IDENT] = $u4130Class;
}
check('3.46 N127: a POST of an admin-only module needs the proof unless EXEMPT names it; other modules are never asked', [
    FreshProof::required('settings', true, 'save'), FreshProof::required('settings', true, 'check'), FreshProof::required('appearance', true, 'save'),
    FreshProof::required('users', true, 'revoke_connection'), FreshProof::required('users', true, ''), FreshProof::required('pages', false, 'save'),
    FreshProof::required('a_new_admin_module', true, 'anything'),
], [true, false, false, false, true, false, true]);
// every exemption names an admin-only module and an action it has – a renamed action never leaves a stale hole or a typo
$u4130Stale = [];
foreach (FreshProof::EXEMPT as $u4130Ident => $u4130Actions) {
    $u4130Class = $u4130Modules[$u4130Ident] ?? null;
    if ($u4130Class === null || !$u4130Class::ADMIN_ONLY) {
        $u4130Stale[] = $u4130Ident;
        continue;
    }
    foreach ($u4130Actions as $u4130Action) {
        if ($u4130Action !== '*' && !method_exists($u4130Class, 'action' . str_replace('_', '', ucwords($u4130Action, '_')))) {
            $u4130Stale[] = $u4130Ident . ':' . $u4130Action;
        }
    }
}
check('3.46 N127: FreshProof::EXEMPT names only existing admin-only modules and their actions', $u4130Stale, []);
// what the audit named is never exempt: the mail server, the backup target and secrets, backups, users, roles, connections,
// add-ons, import and export, updates, the console pairing, the signing key and the recovery key
$u4130Sensitive = [['settings', 'save'], ['settings', 'download_backup'], ['settings', 'restore_backup'], ['settings', 'delete_backup'], ['settings', 'vault_move'],
    ['settings', 'backup_key_new'], ['settings', 'backup_key_download'], ['settings', 'new_signing_key'], ['settings', 'update'], ['settings', 'update_major'],
    ['settings', 'fleet_pair'], ['settings', 'fleet_unpair'], ['settings', 'new_webhook_secret'], ['settings', 'time_zone'], ['status', 'save'], ['extensions', 'save'],
    ['claude_settings', 'save'], ['users', 'save'], ['users', 'delete'], ['users', 'reactivate'], ['users', 'password_link'], ['users', 'handover'],
    ['roles', 'save'], ['roles', 'delete'], ['connectors', 'save'], ['connectors', 'connect'], ['addons', 'toggle'], ['transfer', 'upload'], ['transfer', 'run'],
    ['transfer', 'export'], ['transfer', 'kaleta_run'], ['fleet', 'pairing_key'], ['fleet', 'rollout_save'], ['schedules', 'save']];
check('3.46 N127, N136, I39: the sensitive actions all need the proof', array_values(array_filter($u4130Sensitive,
    fn (array $a): bool => !FreshProof::required($a[0], $u4130Modules[$a[0]]::ADMIN_ONLY, $a[1]))), []);
check('3.46: the way back after a confirmation stays in the administration', array_map(FreshProof::localBack(...),
    ['admin.php?module=transfer', '/sub/admin.php?module=settings&tab=mail', 'https://evil.example/admin.php?module=x', '//evil.example', 'javascript:alert(1)', 'admin.php?x=<b>', '']),
    ['admin.php?module=transfer', 'admin.php?module=settings&tab=mail', 'admin.php?module=x', 'admin.php', 'admin.php', 'admin.php', 'admin.php']);
check('3.46: the posted form goes again whole – nested fields as name[key], without the token and the password field', FreshProof::hiddenFields([
    '_csrf' => 'x', 'soucasne' => 'secret', 'tab' => 'mail', 'rozsireni' => ['a', 'b'], 'pole' => ['k' => ['v' => '1']], 'n' => 5, 'skip' => null,
]), [['tab', 'mail'], ['rozsireni[0]', 'a'], ['rozsireni[1]', 'b'], ['pole[k][v]', '1'], ['n', '5']]);
check('3.46 N127, N158, N138: the new texts are translated (cs, de, es)', array_map(fn (string $code): array => Kaleta\Core\Language::runWith($code, fn (): array => array_values(array_filter([
    FreshProof::NEEDED, FreshProof::WRONG, FreshProof::PASSKEY, 'Confirm it is you', 'Confirm and continue', 'The file you chose was not sent. Confirm here, then choose it again and submit the form.',
    'Then you have 10 minutes for further changes without the password.', 'Confirmed. Submit the form again within 10 minutes – choose the file again.',
    Kaleta\Admin\Kernel::CONNECT_PASSWORD, Kaleta\Admin\Kernel::CONNECT_PASSKEY, Kaleta\Admin\Kernel::PASSKEY_CONFIRM_FAILED,
    'A new application was connected to your account', 'Enter your current password to set up two-factor sign-in. Nothing was changed.', 'Needed to set up two-factor sign-in.',
], fn (string $k): bool => t($k) === $k)), 'admin-'), ['cs', 'de', 'es']), [[], [], []]);
$u4130Du = require KALETA_SYSTEM . '/jazyky/admin-de-du.php';
check('3.46: the informal German form of the texts that address the person', array_values(array_filter([FreshProof::NEEDED, FreshProof::PASSKEY, Kaleta\Admin\Kernel::CONNECT_PASSWORD, 'Confirm it is you'],
    fn (string $k): bool => !isset($u4130Du[$k]))), []);
