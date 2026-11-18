<?php

declare(strict_types=1);

use Kaleta\Admin\FreshProof;
use Kaleta\Core\RemoteBackup;
use Kaleta\Core\RestoreLedger;

/* ---------- 3.46.1: the security re-audit of 3.46 – what can be checked without a database (smoke 4200–4201 run the rest) ---------- */

// L4: the administrator's settings of Applications need the proof; the everyday work of the section does not
check('3.46.1 re-audit L4: Applications – settings_save needs the recent proof, the list and a status change do not', [
    FreshProof::required('applications', false, 'settings_save'), FreshProof::required('applications', false, 'list'), FreshProof::required('applications', false, 'status'),
], [true, false, false]);

// M5: the access settings of today survive a restore; what is left to the backup is said in the preview
$u4200Carried = array_intersect(['whistleblowing_readers', 'claude_destructive', 'claude_protected_pages', 'claude_apps_only', 'claude_change_limit', 'tasks_token', 'health_token', 'screen_secret',
    'firewall_enabled', 'captcha_secret'], RestoreLedger::CARRIED_SETTINGS);
$u4200Preview = ['lost' => ['enquiries' => 0, 'applications' => 0, 'bookings' => 0, 'subscribers' => 0, 'changes' => 0], 'kept' => ['erasures' => 2, 'unsubscribes' => 0], 'known' => true];
check('3.46.1 re-audit M5, L8a: the readers of the whistleblowing channel, the rules of Claude, the secrets of cron, the health check and the screen, the firewall and the CAPTCHA are today\'s; the preview names what stays the backup\'s (not for another installation) and that erasures before 3.46 are not on record', [
    count($u4200Carried), count(array_diff(RestoreLedger::CARRIED_SETTINGS, array_keys(Kaleta\Core\Settings::DEFAULTS))),
    str_contains(RestoreLedger::previewText($u4200Preview, 'site'), RestoreLedger::backupsOwnText()), str_contains(RestoreLedger::previewText($u4200Preview, 'other'), RestoreLedger::backupsOwnText()),
    str_contains(RestoreLedger::previewText($u4200Preview, 'other'), 'Kaleta 3.46'),
], [10, 0, true, false, true]);

// M1: an application deleted by its applicant is remembered by its number and address together (one person's application only)
check('3.46.1 re-audit M1: the fingerprint of a deleted application is its number with its cleaned address; the kind fits the ledger column', [
    RestoreLedger::applicationSubject(5, " Jana@Example.CZ\u{00A0}"), RestoreLedger::applicationSubject(5, 'jana@example.cz') === RestoreLedger::applicationSubject(6, 'jana@example.cz'),
    strlen(RestoreLedger::APPLICATION_DELETED) <= 20,
], ['application:5|jana@example.cz', false, true]);

// M4: a new recovery key or target copies everything again, but keeps what is still to be deleted off-site
$u4200Old = ['cil' => 'old', 'soubory' => ['storage/requests/2026/11/a.bin' => '1:1', 'storage/requests/2026/11/b.bin' => '2:2', 'storage/oznameni/c.bin' => '3:3'],
    'smazano' => ['storage/requests/2026/11/b.bin' => 1000], 'nesmazano' => ['storage/requests/2026/11/b.bin' => 3]];
$u4200New = RemoteBackup::resetManifest($u4200Old, 'new', ['storage/requests/2026/11/a.bin' => 1]);
check('3.46.1 re-audit M4: a manifest reset keeps the copied files gone from the site (with since when and the failed deletions), forgets the ones still on the disk (they are copied again)', [
    $u4200New['cil'], array_keys($u4200New['soubory']), $u4200New['smazano'], $u4200New['nesmazano'], RemoteBackup::resetManifest(null, 'x', [])['soubory'],
], ['new', ['storage/requests/2026/11/b.bin', 'storage/oznameni/c.bin'], ['storage/requests/2026/11/b.bin' => 1000], ['storage/requests/2026/11/b.bin' => 3], []]);
$u4200Gone = RemoteBackup::privateGone($u4200New['soubory'], $u4200New['smazano'], ['storage/requests/2026/11/a.bin' => 1], 90, 1000 + 91 * 86400);
check('3.46.1 re-audit M4: after the reset the file gone the longest is due for deletion; the one gone just now waits', [$u4200Gone[1], isset($u4200Gone[0]['storage/oznameni/c.bin'])], [['storage/requests/2026/11/b.bin'], true]);

// L9: a key of the manifest deletes only inside the private folders
check('3.46.1 re-audit L9: only a path inside a private folder is deleted off-site – no "..", no backslash, no folder itself, nothing else', array_map(RemoteBackup::isPrivatePath(...), [
    'storage/requests/2026/11/ab.bin', 'storage/applications/x.bin', 'storage/requests/../config.php', 'storage/requests/', 'storage/requests', 'media/a.jpg', 'storage/requests\\..\\x', '../storage/requests/a', 'storage/requests/./a',
]), [true, true, false, false, false, false, false, false, false]);
