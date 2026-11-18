<?php

declare(strict_types=1);

/* ---------- 3.46 A: a restore never brings back what was revoked or erased (N156), backups by age (N157), queued bodies blanked (N161) ---------- */
// the restore itself against a database: smoke 4100 ("restore an older backup"); here the rules

// N156: when a backup was made comes from its name (also an uploaded ….enc copy) – the erasures recorded after it are reapplied
check('3.46 N156: RestoreLedger::madeAt reads the site time from a backup name; anything else is null', [
    Kaleta\Core\RestoreLedger::madeAt('kaleta-20261114-031500-auto-a1b2c3d4.sql.gz'),
    Kaleta\Core\RestoreLedger::madeAt('kaleta-20261114-031500-auto-a1b2c3d4.sql.gz.enc'),
    Kaleta\Core\RestoreLedger::madeAt('/x/storage/zalohy/kaleta-20260101-000000-rucni-00000000.sql'),
    Kaleta\Core\RestoreLedger::madeAt('kaleta-20261340-031500-auto-a1b2c3d4.sql.gz'), Kaleta\Core\RestoreLedger::madeAt('kaleta-poskozena.sql.gz'),
], ['2026-11-14 03:15:00', '2026-11-14 03:15:00', '2026-01-01 00:00:00', null, null]);

// the ledger keeps a keyed fingerprint, never the address: HMAC with its own key, over the cleaned address
$r346Fp = Kaleta\Core\RestoreLedger::keyedHash(str_repeat('ab', 32), 'jana@example.com');
check('3.46 N156: the fingerprint is HMAC-SHA256 of the cleaned address under the ledger key (64 hex, no address, another key gives another one)', [
    $r346Fp === hash_hmac('sha256', 'jana@example.com', str_repeat('ab', 32)), strlen($r346Fp), ctype_xdigit($r346Fp), str_contains($r346Fp, 'jana'),
    $r346Fp === Kaleta\Core\RestoreLedger::keyedHash(str_repeat('cd', 32), 'jana@example.com'),
], [true, 64, true, false, false]);

// the ledger table: in the names map under one name, never in a backup (smoke 4100 reads a backup file and restores one)
check('3.46 N156: the ledger table is in the names map; the migration of its own; the database version', [
    Kaleta\Core\Backup::LEDGER, Kaleta\Core\Names::map()['restore_ledger'][0] ?? null, Kaleta\Core\Names::table('restore_ledger'),
    is_file(KALETA_SYSTEM . '/sql/migrace/0133-restore-ledger.sql'), KALETA_DB_VERSION >= 133,
], ['restore_ledger', 'restore_ledger', 'restore_ledger', true, true]);

// the queues a restore stops: the same five, each with the condition its own sender uses
check('3.46 N156: the restored queues that are stopped – mail, webhooks, connector deliveries, newsletters, the mailing-service sync', [
    array_keys(Kaleta\Core\RestoreLedger::QUEUES), Kaleta\Core\RestoreLedger::STOPPED,
    Kaleta\Core\RestoreLedger::QUEUES['webhook_deliveries'][1], Kaleta\Core\RestoreLedger::QUEUES['posta'][1],
], [['posta', 'webhook_deliveries', 'connector_queue', 'newsletter_queue', 'odber_fronta'], 'Restored from a backup – not sent again.',
    'next_attempt IS NOT NULL AND body IS NOT NULL', 'odeslano IS NULL AND telo IS NOT NULL AND dalsi_pokus IS NOT NULL']);

// the preview before a restore names what it loses and what it keeps
$r346Preview = Kaleta\Core\RestoreLedger::previewText(['lost' => ['enquiries' => 3, 'applications' => 1, 'bookings' => 0, 'subscribers' => 2, 'changes' => 14], 'kept' => ['erasures' => 1, 'unsubscribes' => 4]]);
check('3.46 N156: the restore confirmation says what the restore loses and keeps (counts; smoke 4100 reads it in the Backups tab)', [
    str_contains($r346Preview, '3 enquiries, 1 job applications, 0 bookings, 2 newsletter sign-ups and 14 changes'),
    str_contains($r346Preview, '1 erasures of personal data and 4 unsubscribes'), str_contains($r346Preview, 'not sent again'),
    str_contains(Kaleta\Core\RestoreLedger::summary(['same_site' => false]), 'another installation'), str_contains(Kaleta\Core\RestoreLedger::summary(['same_site' => true]), 'another installation'),
], [true, true, true, true, false]);

// N157: local backups by count AND age; the age rule needs the time (the rotation tests of 3.31.1 pass none)
$r346Now = 1_800_000_000;
$r346List = [['soubor' => 'kaleta-a-1.sql.gz', 'velikost' => 1, 'cas' => $r346Now - 86400], ['soubor' => 'kaleta-a-2.sql.gz', 'velikost' => 1, 'cas' => $r346Now - 29 * 86400],
    ['soubor' => 'kaleta-a-3.sql.gz', 'velikost' => 1, 'cas' => $r346Now - 31 * 86400], ['soubor' => 'kaleta-a-4.sql.gz.enc', 'velikost' => 1, 'cas' => $r346Now - 40 * 86400]];
check('3.46 N157: no local backup older than 30 days (also the newest uploaded copy), a protected one stays, none without the time', [
    Kaleta\Core\Backup::KEEP, Kaleta\Core\Backup::KEEP_DAYS,
    Kaleta\Core\Backup::prunable($r346List, [], $r346Now), Kaleta\Core\Backup::prunable($r346List, ['kaleta-a-3.sql.gz'], $r346Now), Kaleta\Core\Backup::prunable($r346List),
    Kaleta\Core\Backup::prunable([$r346List[0]], [], $r346Now),
], [10, 30, ['kaleta-a-3.sql.gz', 'kaleta-a-4.sql.gz.enc'], ['kaleta-a-4.sql.gz.enc'], [], []]);

// N157: off-site copies past backup_remote_days (90; 0 = keep) – only Kaleta's encrypted names, never the one just uploaded
$r346Names = ['kaleta-20260801-030000-auto-a1b2c3d4-s0a1b2c3d.sql.gz.enc', 'kaleta-20261101-030000-auto-b1b2c3d4-s0a1b2c3d.sql.gz.enc', 'kaleta-20260801-030000-auto-c1b2c3d4-s0a1b2c3d.sql.gz',
    'media/kaleta-20260101-030000-x-s0a1b2c3d.sql.gz.enc', 'kaleta-20260701-030000-rucni-d1b2c3d4-s0a1b2c3d.sql.enc', 'notes.txt',
    'kaleta-20260701-030000-auto-e1b2c3d4.sql.gz.enc', 'kaleta-20260701-030000-auto-f1b2c3d4-s99999999.sql.gz.enc'];
check('3.46 N157: RemoteBackup::expired – encrypted backup names of this site older than the limit, not another file, not the uploaded one, nothing with 0 days (review L6: not without this site\'s mark)', [
    Kaleta\Core\Settings::DEFAULTS['backup_remote_days'], (new ReflectionClassConstant(Kaleta\Admin\Modules\Settings::class, 'FIELDS'))->getValue()['backups']['backup_remote_days'] ?? null,
    Kaleta\Core\RemoteBackup::expired($r346Names, 90, '2026-10-01 00:00:00', '', 's0a1b2c3d'),
    Kaleta\Core\RemoteBackup::expired($r346Names, 90, '2026-10-01 00:00:00', 'kaleta-20260701-030000-rucni-d1b2c3d4-s0a1b2c3d.sql.enc', 's0a1b2c3d'),
    Kaleta\Core\RemoteBackup::expired($r346Names, 0, '2026-10-01 00:00:00', '', 's0a1b2c3d'), Kaleta\Core\RemoteBackup::expired($r346Names, 90, '2026-10-01 00:00:00'),
], ['90', 'cislo:0:3650', ['kaleta-20260801-030000-auto-a1b2c3d4-s0a1b2c3d.sql.gz.enc', 'kaleta-20260701-030000-rucni-d1b2c3d4-s0a1b2c3d.sql.enc'],
    ['kaleta-20260801-030000-auto-a1b2c3d4-s0a1b2c3d.sql.gz.enc'], [], []]);

// the S3 signature: the listing signs its query string; without one the signature is the one of 1.8 (0150 pins it)
$r346Sig = fn (string $q): string => Kaleta\Core\RemoteBackup::signS3('GET', 's3.example.com', '/bucket', hash('sha256', ''), 'eu-central-1', 'AKID', 'secret', 1_700_000_000, $q)['Authorization'];
check('3.46 N157: signS3 takes a canonical query string (the listing), an empty one changes nothing', [
    $r346Sig('') === Kaleta\Core\RemoteBackup::signS3('GET', 's3.example.com', '/bucket', hash('sha256', ''), 'eu-central-1', 'AKID', 'secret', 1_700_000_000)['Authorization'],
    $r346Sig('list-type=2&prefix=kaleta-') === $r346Sig(''),
], [true, false]);

// N157: the erase result and the processing record state the backup horizon
$r346Horizon = Kaleta\Core\PersonalData::horizonText(true, 90);
check('3.46 N157: the backup horizon in words – local days and files, the off-site days (or the bucket rule), a restore erases again (smoke 4100: the erase result and the processing record)', [
    str_contains($r346Horizon, 'up to 30 days (at most 10 backups'), str_contains($r346Horizon, 'deleted after 90 days'),
    str_contains(Kaleta\Core\PersonalData::horizonText(true, 0), 'lifecycle rule'), str_contains(Kaleta\Core\PersonalData::horizonText(false, 90), 'Off-site'),
    str_contains($r346Horizon, 'erases the address again'),
], [true, true, true, false, true]);

// N161: a queued body is the person's by its enquiry id or by a value that is exactly the address
$r346Hook = ['udalost' => 'nova_poptavka', 'id' => 41, 'email' => 'jana@example.com', 'pole' => [['popisek' => 'E-mail', 'hodnota' => ' Jana@Example.com ']]];
$r346Lead = ['service' => 'hubspot', 'enquiry' => 7, 'email' => 'petr@example.com', 'fields' => [['Name', 'Petr', 'text'], ['Colleague', 'jana@example.com', 'email']]];
check('3.46 N161: PersonalData::queuedFor – the enquiry id (webhook "id", connector "enquiry"), or any value that is exactly the address; a longer address is not', [
    Kaleta\Core\PersonalData::queuedFor($r346Hook, '', [41 => 0]), Kaleta\Core\PersonalData::queuedFor($r346Hook, 'jana@example.com', []),
    Kaleta\Core\PersonalData::queuedFor($r346Lead, '', [7 => 0]), Kaleta\Core\PersonalData::queuedFor($r346Lead, 'jana@example.com', []),
    Kaleta\Core\PersonalData::queuedFor($r346Lead, 'ana@example.com', []), Kaleta\Core\PersonalData::queuedFor(['udalost' => 'test', 'id' => 41], '', [41 => 0]),
    Kaleta\Core\PersonalData::queuedFor($r346Hook, '', []),
], [true, true, true, true, false, false, false]);
check('3.46 N161: a given-up delivery keeps its body for the Retry button this many days', Kaleta\Core\PersonalData::RETRY_DAYS, 7);

// the new admin texts are translated in the admin languages
check('3.46: the new texts of the restore, the retention and the erase result are in admin-cs, admin-de and admin-es', array_map(fn (string $code): array => array_values(array_diff([
    'Since this backup, the restore loses %d enquiries, %d job applications, %d bookings, %d newsletter sign-ups and %d changes in the change log.',
    'The erased data can stay in the backups for up to %d days (at most %d backups on this server).', 'Keep off-site copies (days)',
    'Kept from today: %d tokens and %d passkeys the backup had were removed, %d accounts kept their current sign-in, %d accounts the backup had are blocked; %d erasures and %d unsubscribes applied again; %d queued deliveries from the backup stopped.',
], array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es']), [[], [], []]);
