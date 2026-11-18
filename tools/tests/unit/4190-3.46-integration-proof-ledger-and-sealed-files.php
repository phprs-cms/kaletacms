<?php

declare(strict_types=1);

use Kaleta\Admin\FreshProof;

/* ---------- 3.46 integration: what A (restore ledger, retention) and C (sealed private files) added, under B's proof ---------- */
// the POSTs of Settings that A and C added or widened: saving the off-site target and backup_remote_days, restoring (A runs
// the ledger and the pending migrations, 0134 among them), deleting and downloading a backup need the proof; a new backup
// on this server and its copy to the target already set (now with the private folders, encrypted under the recovery key) do not
check('3.46 integration: the backup POSTs of Settings – save, restore, delete and download need the proof; a backup and its copy to the set target do not', [
    FreshProof::required('settings', Kaleta\Admin\Modules\Settings::ADMIN_ONLY, 'save'),
    FreshProof::required('settings', Kaleta\Admin\Modules\Settings::ADMIN_ONLY, 'restore_backup'),
    FreshProof::required('settings', Kaleta\Admin\Modules\Settings::ADMIN_ONLY, 'delete_backup'),
    FreshProof::required('settings', Kaleta\Admin\Modules\Settings::ADMIN_ONLY, 'download_backup'),
    FreshProof::required('settings', Kaleta\Admin\Modules\Settings::ADMIN_ONLY, 'backup'),
    FreshProof::required('settings', Kaleta\Admin\Modules\Settings::ADMIN_ONLY, 'media_backup'),
], [true, true, true, true, false, false]);
// C's private downloads (a request's attachment, an internal file, a whistleblowing attachment) are GET reads of sections
// that are not the administrator's alone: the section's own rights guard them, the proof rule never asks
check('3.46 integration: the private downloads of C live in sections that are not admin-only – the proof rule never stands between a colleague and a file', [
    Kaleta\Admin\Modules\Requests::ADMIN_ONLY, Kaleta\Admin\Modules\InternalFiles::ADMIN_ONLY, Kaleta\Admin\Modules\Whistleblowing::ADMIN_ONLY,
    FreshProof::required('requests', Kaleta\Admin\Modules\Requests::ADMIN_ONLY, 'file'),
], [false, false, false, false]);
// A's off-site pruning never deletes C's encrypted private copies (private/<path>.enc) nor the media, only backup names past the days
$u4190Before = Kaleta\Core\Clock::at('-90 days');
$u4190Old = 'kaleta-20200101-000000-auto-abcd1234-s0a1b2c3d.sql.gz.enc';
check('3.46 integration: backup_remote_days prunes only old encrypted backups – never private/ or media/', Kaleta\Core\RemoteBackup::expired([
    $u4190Old, 'private/storage/internal-files/2020/01/' . str_repeat('a', 32) . '.bin.enc', 'private/storage/applications/kaleta-20200101-000000-auto-x.sql.gz.enc',
    'media/2020/01/photo.jpg', 'kaleta-20200101-000000-auto-abcd1234-s0a1b2c3d.sql.gz',
], 90, $u4190Before, '', 's0a1b2c3d'), [$u4190Old]);
// the ledger is never in a backup and the private folders are copied off-site encrypted, the ledger is not a private folder
check('3.46 integration: the ledger stays out of backups; the sealed columns of C are resealed by the Vault; the private folders hold the sealed files', [
    Kaleta\Core\Backup::LEDGER, isset(Kaleta\Core\Vault::SEALED['internal_file_versions'], Kaleta\Core\Vault::SEALED['requests']),
    in_array('storage/internal-files', Kaleta\Core\RemoteBackup::PRIVATE_FOLDERS, true) && in_array('storage/oznameni', Kaleta\Core\RemoteBackup::PRIVATE_FOLDERS, true),
], ['restore_ledger', true, true]);
