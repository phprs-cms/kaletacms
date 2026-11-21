<?php

declare(strict_types=1);

/* ---------- 3.50.1 A5: an FTPS listing does not recreate removed folders; a dot-named entry is not taken for deleted ---------- */
use Kaleta\Core\RemoteBackup as A610Backup;

if (extension_loaded('curl')) {
    $a610Upload = A610Backup::ftpsOptions('user-a610', 'pass-a610');
    $a610Command = A610Backup::ftpsCommandOptions('user-a610', 'pass-a610');
    check('3.50.1 A5a: the upload creates missing folders, a listing or delete creates none and uploads nothing – the TLS and sign-in options are the same', [
        $a610Upload[CURLOPT_FTP_CREATE_MISSING_DIRS], $a610Upload[CURLOPT_UPLOAD], $a610Command[CURLOPT_FTP_CREATE_MISSING_DIRS], $a610Command[CURLOPT_UPLOAD],
        array_diff_key($a610Command, [CURLOPT_FTP_CREATE_MISSING_DIRS => 1, CURLOPT_UPLOAD => 1]) === array_diff_key($a610Upload, [CURLOPT_FTP_CREATE_MISSING_DIRS => 1, CURLOPT_UPLOAD => 1])],
        [CURLFTP_CREATE_DIR_RETRY, true, 0, false, true]);
}
$a610Lists = fn (array $folders): callable => fn (string $dir): array|false => $folders[$dir] ?? false;
check('3.50.1 A5b: a dot-named file or folder is never proven gone by a listing that lacks it (unknown, retried); an ordinary name still is', [
    A610Backup::listingLacks(['a.txt'], '.htaccess'), A610Backup::listingLacks(['a.txt'], 'media/.hidden'), A610Backup::listingLacks(['a.txt'], 'media/b.txt'),
    A610Backup::goneFrom($a610Lists(['media/.cache' => false, 'media' => ['x.jpg']]), 'media/.cache/a.jpg'),
    A610Backup::goneFrom($a610Lists(['media/old' => false, 'media' => ['x.jpg']]), 'media/old/a.jpg'),
    A610Backup::goneFrom($a610Lists(['media' => ['x.jpg']]), 'media/.env')],
    [false, false, true, false, true, false]);
