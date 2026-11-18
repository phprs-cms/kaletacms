<?php

declare(strict_types=1);

use Kaleta\Admin\FreshProof;
use Kaleta\Core\Backup;
use Kaleta\Core\RemoteBackup;
use Kaleta\Core\RestoreLedger;

/* ---------- 3.46 review: H1 memory, M1–M6 and the lows that can be checked without a database (smoke 4191–4193 run the rest) ---------- */

// L3: the administrator-only actions of sections that are not admin-only need the proof; the rest of those sections does not
$u4191Modules = [];
foreach (Kaleta\Admin\Kernel::MODULES as $u4191Class) {
    $u4191Modules[$u4191Class::IDENT] = $u4191Class;
}
$u4191Stale = [];
foreach (FreshProof::ADMIN_ACTIONS as $u4191Ident => $u4191Actions) {
    $u4191Class = $u4191Modules[$u4191Ident] ?? null;
    foreach ($u4191Actions as $u4191Action) {
        if ($u4191Class === null || $u4191Class::ADMIN_ONLY || !method_exists($u4191Class, 'action' . str_replace('_', '', ucwords($u4191Action, '_')))) {
            $u4191Stale[] = $u4191Ident . ':' . $u4191Action;
        }
    }
}
check('3.46 review L3: whistleblowing readers, internal folders and the erasure of personal data need the proof; anonymising one enquiry or a download does not', [
    $u4191Stale, FreshProof::required('whistleblowing', false, 'settings'), FreshProof::required('internal_files', false, 'folder_save'),
    FreshProof::required('enquiries', false, 'personal'), FreshProof::required('enquiries', false, 'anonymise'), FreshProof::required('internal_files', false, 'download'),
], [[], true, true, true, false, false]);

// M6: the FTPS delete names the file by the folder's absolute path – cURL sends it before it changes into the folder
check('3.46 review M6: RemoteBackup::ftpDeleteCommand – the full path with a folder, the sign-in folder without one, no line break', [
    RemoteBackup::ftpDeleteCommand('backups/site', 'kaleta-x.sql.gz.enc'), RemoteBackup::ftpDeleteCommand('/backups/site/', 'private/storage/requests/a.bin.enc'),
    RemoteBackup::ftpDeleteCommand('', 'kaleta-x.sql.gz.enc'), RemoteBackup::ftpDeleteCommand('b', "x.enc\r\nRMD /"),
], ['DELE /backups/site/kaleta-x.sql.gz.enc', 'DELE /backups/site/private/storage/requests/a.bin.enc', 'DELE kaleta-x.sql.gz.enc', 'DELE /b/x.encRMD /']);

// M5: a private file gone from the site is deleted from the copy after backup_remote_days, not at once, never with 0
$u4191Now = 1_800_000_000;
$u4191Copied = ['storage/applications/a.bin' => '1:1', 'storage/requests/b.bin' => '2:2', 'storage/oznameni/c.bin' => '3:3'];
check('3.46 review M5: RemoteBackup::privateGone – since when a file is gone, due after the days, back = no longer gone, 0 = never', [
    RemoteBackup::privateGone($u4191Copied, [], ['storage/applications/a.bin' => 1], 90, $u4191Now),
    RemoteBackup::privateGone($u4191Copied, ['storage/requests/b.bin' => $u4191Now - 91 * 86400, 'storage/oznameni/c.bin' => $u4191Now - 10 * 86400, 'storage/x' => 1], ['storage/applications/a.bin' => 1], 90, $u4191Now)[1],
    RemoteBackup::privateGone($u4191Copied, ['storage/requests/b.bin' => 1], ['storage/applications/a.bin' => 1, 'storage/requests/b.bin' => 2], 90, $u4191Now)[0],
    RemoteBackup::privateGone($u4191Copied, ['storage/requests/b.bin' => 1], [], 0, $u4191Now)[1],
], [[['storage/requests/b.bin' => $u4191Now, 'storage/oznameni/c.bin' => $u4191Now], []], ['storage/requests/b.bin'], ['storage/oznameni/c.bin' => $u4191Now], []]);

// L8: the first line of a backup says when and under which key; a renamed copy cannot skip an erasure (the earlier time wins)
$u4191Header = Backup::parseHeader('-- Kaleta 3.46.0 - database backup 2026-11-18T10:00:00+01:00 key 0123456789abcdef');
check('3.46 review L8: Backup::parseHeader – the time in the site\'s zone and the key\'s start; an older first line names neither', [
    $u4191Header['key'], strlen((string) $u4191Header['made']), Backup::parseHeader('-- Kaleta 3.45.0 - database backup 2026-11-18T10:00:00+01:00')['key'],
    Backup::parseHeader('-- not a backup'), RestoreLedger::backupTime('kaleta-20991231-235959-x-00000000.sql.gz', '2026-11-01 08:00:00'),
], ['0123456789abcdef', 19, null, ['made' => null, 'key' => null], '2026-11-01 08:00:00']);

// L7: any statement that names the ledger is skipped by a restore, not only the three shapes a backup writes
check('3.46 review L7: Backup::namesLedger – DELETE, UPDATE, TRUNCATE, RENAME and the usual shapes; the name inside values is not', [
    Backup::namesLedger("DELETE FROM `ka_restore_ledger`;", 'ka_'), Backup::namesLedger("UPDATE ka_restore_ledger SET hash = '';", 'ka_'),
    Backup::namesLedger("TRUNCATE TABLE ka_restore_ledger;", 'ka_'), Backup::namesLedger("RENAME TABLE `ka_restore_ledger` TO `x`;", 'ka_'),
    Backup::namesLedger("INSERT INTO `ka_novinky` VALUES (1,'about ka_restore_ledger');", 'ka_'), Backup::namesLedger("DROP TABLE IF EXISTS `ka_restore_ledger_old`;", 'ka_'),
], [true, true, true, true, false, false]);

// L8: the preview promises today's sign-in only for this site's backup, and says "not known" instead of zeros
$u4191Preview = ['lost' => ['enquiries' => 0, 'applications' => 0, 'bookings' => 0, 'subscribers' => 0, 'changes' => 0], 'kept' => ['erasures' => 0, 'unsubscribes' => 0]];
check('3.46 review L8: the restore preview by the origin of the backup and a time nobody knows', [
    str_contains(RestoreLedger::previewText($u4191Preview, 'other'), 'It keeps the sign-in security'), str_contains(RestoreLedger::previewText($u4191Preview, 'other'), 'another installation'),
    str_contains(RestoreLedger::previewText($u4191Preview, 'unknown'), 'If the backup comes from this site'), str_contains(RestoreLedger::previewText(['known' => false] + $u4191Preview), 'loses 0'),
    str_contains(RestoreLedger::previewText(['known' => false] + $u4191Preview), 'It is not known when'),
], [false, true, true, false, true]);

// M3, M4: what a restore carries from today names real settings, tables and columns
$u4191Schema = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
$u4191Missing = array_values(array_diff([...RestoreLedger::CARRIED_SETTINGS, ...RestoreLedger::SENT_MARKERS], array_keys(Kaleta\Core\Settings::DEFAULTS)));
foreach (RestoreLedger::SENT_FLAGS as $u4191Table => [$u4191Id, $u4191Columns]) {
    foreach ([$u4191Id, ...$u4191Columns] as $u4191Column) {
        if (preg_match('/CREATE TABLE ka_' . preg_quote($u4191Table, '/') . ' \(.*?\n    ' . preg_quote($u4191Column, '/') . ' /s', $u4191Schema) !== 1) {
            $u4191Missing[] = $u4191Table . '.' . $u4191Column;
        }
    }
}
check('3.46 review M3/M4: the carried settings and the "sent" columns exist', $u4191Missing, []);

// L6: a backup name carries this site's mark – the off-site prune deletes only names with it
check('3.46 review L6: the name of a backup with the site mark is still a backup name, its time is read from it', [
    Backup::encrypted('kaleta-20261118-100000-auto-a1b2c3d4-s0a1b2c3d.sql.gz.enc'), RestoreLedger::madeAt('kaleta-20261118-100000-auto-a1b2c3d4-s0a1b2c3d.sql.gz'),
    Backup::heldName('.hold-kaleta-20261118-100000-auto-a1b2c3d4-s0a1b2c3d.sql.gz.0123456789ab'),
], [true, '2026-11-18 10:00:00', 'kaleta-20261118-100000-auto-a1b2c3d4-s0a1b2c3d.sql.gz']);

// H1: the memory budget is three copies of the file (write, open and sealInPlace hold at most two – smoke 4191 measures it)
check('3.46 review H1: SealedFiles::bytes reads memory_limit; a file is skipped by the setting that names a dead run', [
    Kaleta\Core\SealedFiles::bytes('128M'), Kaleta\Core\SealedFiles::bytes('-1'), Kaleta\Core\SealedFiles::bytes('1G'),
    isset(Kaleta\Core\Settings::DEFAULTS[Kaleta\Core\SealedFiles::SEALING], Kaleta\Core\Settings::DEFAULTS[Kaleta\Core\SealedFiles::SKIPPED]),
], [134217728, -1, 1073741824, true]);
