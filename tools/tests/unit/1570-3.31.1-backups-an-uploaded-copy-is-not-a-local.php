<?php

declare(strict_types=1);

/* ---------- 3.31.1: backups – an uploaded copy is not a local backup, the rotation never takes the copy being restored, a failed off-site copy is an event ---------- */
$b3311List = [['soubor' => 'kaleta-20261109-030000-auto-a1b2c3d4.sql.gz.enc', 'velikost' => 9, 'cas' => 300], ['soubor' => 'kaleta-20261101-030000-auto-b1b2c3d4.sql.gz', 'velikost' => 9, 'cas' => 200],
    ['soubor' => 'kaleta-20261020-030000-rucni-c1b2c3d4.sql', 'velikost' => 9, 'cas' => 100]];
$b3311Sources = implode("\n", array_map(fn (string $f): string => (string) file_get_contents(KALETA_SYSTEM . '/src/' . $f),
    ['Core/Health.php', 'Core/MonthlyReport.php', 'Admin/Kernel.php', 'Mcp/Handlers/HealthTools.php', 'Fleet/Heartbeat.php', 'Core/Backup.php']));
check('3.31.1: an encrypted copy uploaded back (.enc) is not a local backup – the schedule, System status, the dashboard, the monthly report, get_health and the heartbeat read listLocal()', [
    array_column(Kaleta\Core\Backup::local($b3311List), 'soubor'), Kaleta\Core\Backup::local([]), array_column(Kaleta\Core\Backup::local([$b3311List[0]]), 'soubor'),
    preg_match_all('/Backup::listAll\(\)\[0\]/', $b3311Sources), substr_count($b3311Sources, 'Backup::listLocal()[0]') + substr_count($b3311Sources, 'self::listLocal()[0]'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/backups.php'), "Kaleta\\Core\\Backup::encrypted(\$z['soubor'])): // 3.31.1"),
    array_map(fn (string $code): array => array_values(array_diff(['uploaded copy', 'An encrypted off-site copy uploaded back for a restore. It does not count as a backup made on this server.'],
        array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [['kaleta-20261101-030000-auto-b1b2c3d4.sql.gz', 'kaleta-20261020-030000-rucni-c1b2c3d4.sql'], [], [], 0, 6, true, [[], [], []]]);

// the rotation: the backups made here and the uploaded copies rotate apart (KEEP each), a protected or held name is never deleted
$b3311Backups = fn (int $local, int $uploaded, int $uploadedFirst = 0): array => [
    ...array_map(fn (int $i): array => ['soubor' => sprintf('kaleta-202611%02d-030000-auto-x.sql.gz.enc', 30 - $i), 'velikost' => 1, 'cas' => 2000 - $i], $uploadedFirst > 0 ? range(0, $uploadedFirst - 1) : []),
    ...array_map(fn (int $i): array => ['soubor' => sprintf('kaleta-202610%02d-030000-auto-y.sql.gz', 30 - $i), 'velikost' => 1, 'cas' => 1000 - $i], $local > 0 ? range(0, $local - 1) : []),
    ...array_map(fn (int $i): array => ['soubor' => sprintf('kaleta-202609%02d-030000-auto-z.sql.gz.enc', 30 - $i), 'velikost' => 1, 'cas' => 500 - $i], $uploaded > 0 ? range(0, $uploaded - 1) : []),
];
$b3311Settings = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php');
$b3311Restore = substr($b3311Settings, (int) strpos($b3311Settings, 'function actionRestoreBackup'), 6000);
check('3.31.1: the rotation never deletes the copy being restored – an old uploaded copy is not pushed out by ten local backups, each kind keeps its newest KEEP, protected names stay; the restore holds the file, makes the safety backup without rotating and prunes afterwards', [
    Kaleta\Core\Backup::KEEP,
    Kaleta\Core\Backup::prunable($b3311Backups(11, 1)), // the safety backup before restoring the old uploaded copy: only the 11th local goes
    Kaleta\Core\Backup::prunable($b3311Backups(11, 1), ['kaleta-20261020-030000-auto-y.sql.gz']), // the oldest local is being restored: it stays
    Kaleta\Core\Backup::prunable($b3311Backups(3, 11)), // eleven uploaded copies: the oldest one goes, the newest always stays
    Kaleta\Core\Backup::prunable($b3311Backups(10, 2, 1)), Kaleta\Core\Backup::prunable([]),
    (new ReflectionMethod(Kaleta\Core\Backup::class, 'create'))->getParameters()[2]->getDefaultValue(),
    strpos($b3311Restore, 'Backup::hold($file);') < strpos($b3311Restore, "Backup::create(\$this->db, 'predobnovou', false)"),
    (bool) preg_match('/\} finally \{\s+Backup::release\(\$file, \$hold\);\s+Backup::prune\(\[\$file\]\);/', $b3311Restore),
    substr_count($b3311Settings, "Backup::create(\$this->db, 'predobnovou'"), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Backup.php'), "if (\$prune) {\n            self::prune([\$file]);")],
    [10, ['kaleta-20261020-030000-auto-y.sql.gz'], [], ['kaleta-20260920-030000-auto-z.sql.gz.enc'], [], [], true, true, true, 1, true]);

// review of 3.31.1: one hold marker per request (.hold-<name>.<token>) – two restores of one file each hold it and release only
// their own; a marker name is checked (no other folder, no other file); the order of backups never depends on the folder listing
$b3311Tok = '0123456789ab';
$b3311Sorted = array_column(Kaleta\Core\Backup::newestFirst([['soubor' => 'kaleta-20200101-000001-a.sql', 'velikost' => 1, 'cas' => 100],
    ['soubor' => 'kaleta-20200101-000003-c.sql', 'velikost' => 1, 'cas' => 100], ['soubor' => 'kaleta-20200101-000002-b.sql', 'velikost' => 1, 'cas' => 100],
    ['soubor' => 'kaleta-20190101-000000-z.sql', 'velikost' => 1, 'cas' => 200]]), 'soubor');
$b3311BackupSrc = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Backup.php');
check('3.31.1 (review): a hold marker per request – heldName reads .hold-<backup>.<12 hex> only (no old single marker, no path, no other name); held() deletes an expired marker; the restore releases its own token; ties of one second sort by name', [
    Kaleta\Core\Backup::heldName('.hold-kaleta-20261101-030000-auto-x.sql.gz.' . $b3311Tok), Kaleta\Core\Backup::heldName('.hold-kaleta-x.sql.gz.enc.' . $b3311Tok),
    Kaleta\Core\Backup::heldName('.hold-kaleta-x.sql.' . $b3311Tok), Kaleta\Core\Backup::heldName('.hold-kaleta-x.sql.gz'),
    Kaleta\Core\Backup::heldName('.hold-kaleta-a/../b.sql.' . $b3311Tok), Kaleta\Core\Backup::heldName('.hold-../kaleta-b.sql.' . $b3311Tok),
    Kaleta\Core\Backup::heldName('.hold-kaleta-x.sql.' . strtoupper($b3311Tok)), Kaleta\Core\Backup::heldName('.hold-kaleta-x.sql.' . $b3311Tok . '0'),
    Kaleta\Core\Backup::heldName('.hold-kaleta-x.txt.' . $b3311Tok),
    str_contains($b3311Restore, '$hold = Backup::hold($file);'), (new ReflectionMethod(Kaleta\Core\Backup::class, 'release'))->getNumberOfRequiredParameters(),
    str_contains($b3311BackupSrc, "@unlink(\$marker); // left by a request that died"), $b3311Sorted],
    ['kaleta-20261101-030000-auto-x.sql.gz', 'kaleta-x.sql.gz.enc', 'kaleta-x.sql', null, null, null, null, null, null, true, 2, true,
        ['kaleta-20190101-000000-z.sql', 'kaleta-20200101-000003-c.sql', 'kaleta-20200101-000002-b.sql', 'kaleta-20200101-000001-a.sql']]);

// a failed off-site copy is one backup.failed event with a short system text; a paused copy stays backup.copy_paused
$b3311S = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($b3311S, ['backup_password' => 'Pa55-' . 'word-x', 'backup_user' => 'jan.novak']);
$b3311Fail = fn (string $error): string => Kaleta\Core\RemoteBackup::failureText($error, $b3311S);
$b3311Remote = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/RemoteBackup.php');
$b3311Upload = substr($b3311Remote, (int) strpos($b3311Remote, 'public static function upload('), 2600);
check('3.31.1: failureText – one line without "|", no password or user name of the target (any case), no credentials in an address, no e-mail address, no site folder, at most 200 characters', [
    $b3311Fail("530 Login incorrect\n for JAN.NOVAK"), $b3311Fail('Could not connect to ftp://jan.novak:Pa55-word-x@nas.example.com/zalohy'),
    $b3311Fail('Access denied (user jan@client.example)'), $b3311Fail('fopen(' . KALETA_ROOT . '/storage/zalohy/.upload-1.enc): failed'),
    mb_strlen($b3311Fail(str_repeat('é', 500))), str_ends_with($b3311Fail(str_repeat('x', 500)), '…'),
    $b3311Fail('The storage refused the upload (code 403): Access Denied.'), $b3311Fail('a | b'),
    (function () use ($b3311S): string { (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($b3311S, ['backup_password' => 'ab', 'backup_user' => '']); return Kaleta\Core\RemoteBackup::failureText('ab cab', $b3311S); })()],
    ['530 Login incorrect for …', 'Could not connect to ftp://nas.example.com/zalohy', 'Access denied (user …)', 'fopen(storage/zalohy/.upload-1.enc): failed', 200, true,
        'The storage refused the upload (code 403): Access Denied.', 'a b', 'ab cab']);
// review of 3.31.1: the secrets are masked before the text becomes one line – a password with "|" or two spaces is still masked
$b3311Masked = (function () use ($b3311S): array {
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($b3311S, ['backup_password' => 'se|cr  ' . 'et9', 'backup_user' => 'bob1']);
    $once = Kaleta\Core\RemoteBackup::failureText('auth failed for bob1 with se|cr  et9', $b3311S);

    return [$once, Kaleta\Core\RemoteBackup::failureText('auth failed for BOB1 with se cr et9', $b3311S), Kaleta\Core\RemoteBackup::failureText($once, $b3311S)];
})();
check('3.31.1 (review): failureText masks a password with "|" and double spaces in the raw text and in its one-line form; running it again changes nothing', $b3311Masked,
    ['auth failed for … with …', 'auth failed for … with …', 'auth failed for … with …']);
// review of 3.31.1: the warnings of unused accounts and Claude connections point to Settings → Security (the switch moved there in
// 3.23); the old keys are replaced, not kept beside the new ones
$h3311Keys = ['%d account(s) unused for %d days – block them in Users, or switch on the automatic suspension in Settings → Security',
    '%d connection(s) unused for %d days – revoke them in the user’s account, or switch on the automatic suspension in Settings → Security'];
$h3311Health = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php');
check('3.31.1 (review): System status sends the automatic suspension of unused accounts and connections to Settings → Security – both warnings, every dictionary (cs, de, de-du, es), no old key left', [
    array_map(fn (string $key): bool => str_contains($h3311Health, "t('" . $key . "'"), $h3311Keys), substr_count($h3311Health, 'automatic suspension in Settings → General'),
    array_map(function (string $code) use ($h3311Keys): array {
        $dictionary = require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php';

        return [count(array_intersect_key($dictionary, array_flip($h3311Keys))), count(array_filter(array_keys($dictionary), fn (string $key): bool => str_contains($key, 'automatic suspension in Settings → General'))),
            count(array_filter(array_intersect_key($dictionary, array_flip($h3311Keys)), fn (string $text): bool => (bool) preg_match('/→ (Zabezpečení|Sicherheit|Seguridad)\b/u', $text)))];
    }, ['cs', 'de', 'de-du', 'es'])],
    [[true, true], 0, [[2, 0, 2], [2, 0, 2], [2, 0, 2], [2, 0, 2]]]);
check('3.31.1: upload() records backup.failed once per failed copy (the short text in the status and the event), the paused copy returns before it; the media copy records its failure when it starts; translated (cs, de, es)', [
    substr_count($b3311Upload, "Events::record(\$s->db(), 'backup.failed', 'error'"), strpos($b3311Upload, 'return self::pausedText();') < strpos($b3311Upload, "'backup.failed'"),
    str_contains($b3311Upload, "\$s->set('remote_backup_status', date('Y-m-d H:i') . '|' . \$text);"),
    substr_count($b3311Remote, "'backup.failed'"), str_contains($b3311Remote, 'if ($error !== null && !$failing) {'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Backup.php'), "'backup.failed'"), isset(Kaleta\Core\Events::TYPES['backup.failed']), in_array('backup.copy_paused', Kaleta\Core\Alerts::WARNINGS, true),
    array_map(fn (string $code): array => array_values(array_diff(['The off-site copy of the backup failed: %s', 'The off-site copy of the media failed: %s'],
        array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [1, true, true, 2, true, 2, true, true, [[], [], []]]);

