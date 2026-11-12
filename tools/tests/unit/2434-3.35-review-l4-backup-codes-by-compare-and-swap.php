<?php

declare(strict_types=1);

// 3.35 review L4: backup codes of 64 random bits under a salt of the list; used up and replaced by compare-and-swap; a list
// of the older format keeps verifying until it is replaced
use Kaleta\Core\BackupCodes;

[$r357Codes, $r357Stored] = BackupCodes::generate();
$r357List = json_decode($r357Stored, true);
check('3.35 review L4: eight codes of 16 hex digits (64 bits), stored as HMAC under a random salt of the list – never the code', [
    count($r357Codes), (bool) preg_match('/^([0-9a-f]{4}-){3}[0-9a-f]{4}$/', $r357Codes[0]), count(array_unique($r357Codes)),
    $r357List['v'] ?? null, strlen((string) ($r357List['salt'] ?? '')), count($r357List['codes'] ?? []), str_contains($r357Stored, $r357Codes[0]),
    str_contains($r357Stored, hash('sha256', $r357Codes[0])), BackupCodes::count($r357Stored),
], [8, true, 8, 2, 32, 8, false, false, 8]);
[, $r357Other] = BackupCodes::generate();
$r357Left = BackupCodes::consume($r357Stored, strtoupper(str_replace('-', ' ', $r357Codes[3])));
check('3.35 review L4: a code works once (dashes, spaces and case aside), never in another list, nothing empty matches', [
    BackupCodes::count($r357Left), BackupCodes::consume((string) $r357Left, $r357Codes[3]), BackupCodes::count(BackupCodes::consume((string) $r357Left, $r357Codes[0])),
    BackupCodes::consume($r357Other, $r357Codes[0]), BackupCodes::consume($r357Stored, '---'), BackupCodes::consume(null, $r357Codes[0]), BackupCodes::count(null),
    json_decode((string) $r357Left, true)['salt'] === $r357List['salt'],
], [7, null, 6, null, null, null, 0, true]);
$r357Old = (string) json_encode([hash('sha256', 'abcde-12345'), hash('sha256', 'fghij-67890')]);
check('3.35 review L4: a list of the older format verifies as before and stays in its format', [
    BackupCodes::count($r357Old), BackupCodes::consume($r357Old, ' ABCDE-12345 '), BackupCodes::consume($r357Old, 'abcde12345'),
    Kaleta\Core\SignInPolicy::backupCodesLeft(['totp_zalozni' => $r357Old]), Kaleta\Core\SignInPolicy::backupCodesLeft(['totp_zalozni' => $r357Stored]),
], [2, (string) json_encode([hash('sha256', 'fghij-67890')]), null, 2, 8]);
$r357Auth = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
$r357Account = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
check('[source-pin] 3.35 review L4: the sign-in uses a code up by compare-and-swap; every new list replaces the stored one by compare-and-swap', [
    str_contains($r357Auth, "'UPDATE {uzivatele} SET totp_zalozni = ? WHERE idu = ? AND totp_zalozni = ?'"),
    str_contains($r357Auth, "' WHERE idu = ? AND totp_zalozni <=> ?'"),
    str_contains($r357Auth, '$usedBackupCode = $step === null && $user !== null && $this->consumeBackupCode((int) $user[\'idu\'], $code);'),
    substr_count($r357Account, '->replaceBackupCodes('), substr_count($r357Account, "'totp_zalozni' => \$json"), substr_count($r357Account, "'totp_zalozni' => \$firstCodes"),
], [true, true, true, 3, 0, 0]);
