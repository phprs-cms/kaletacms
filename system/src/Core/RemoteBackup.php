<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Copy of the database backup off the server: FTP/FTPS (another hosting, NAS) or S3-compatible storage
 * (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2…). A backup on the same disk as the site does not protect against
 * losing the hosting. Since 1.8 the media go to the same place, incrementally (syncMedia). No libraries: S3 through cURL
 * with an AWS Signature V4 signature; FTPS through cURL too since 3.19.1 (N80) – PHP's FTP extension cannot check the
 * server's certificate, cURL checks it like the S3 path does. Only a server whose PHP has no cURL with FTPS falls back to
 * the extension, and the Backups tab says that the certificate is not checked there (ftpsVerified()).
 */
final class RemoteBackup
{
    /** Which media files are already copied (path => "size:mtime") and to which target. */
    public const string MEDIA_MANIFEST = Backup::FOLDER . '/media-kopie.json';

    /** Seconds between background runs of the media copy when nothing is waiting. */
    private const int MEDIA_INTERVAL = 3600;

    /** @return string|null error text, null = uploaded (or remote backup is turned off) */
    public static function upload(Settings $s, string $path): ?string
    {
        if (!self::isOn($s)) {
            return null;
        }
        try {
            [$put, $close] = self::open($s);
            try {
                $put(basename($path), $path);
            } finally {
                $close();
            }
            $error = null;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $s->set('remote_backup_status', date('Y-m-d H:i') . '|' . ($error ?? 'ok'));

        return $error;
    }

    public static function isOn(Settings $s): bool
    {
        if (Demo::active()) {
            return false;
        }
        return in_array($s->get('remote_backup'), ['ftp', 's3'], true) && $s->get('backup_host') !== '';
    }

    /**
     * Incremental copy of media/ to the same target as the database backups (1.8), into its folder media/. Only new and
     * changed files go (a manifest remembers what is there); a file deleted on the site stays in the copy. It runs for
     * at most $seconds and continues next time. Status: remote_media_status = "Y-m-d H:i|ok or error|files waiting".
     *
     * @return string|null error text, null = done or nothing to do
     */
    public static function syncMedia(Settings $s, int $seconds = 20): ?string
    {
        if (!self::isOn($s) || !$s->bool('backup_media')) {
            return null;
        }
        $target = sha1($s->get('remote_backup') . '|' . $s->get('backup_host') . '|' . $s->get('backup_folder'));
        $manifest = is_file(self::MEDIA_MANIFEST) ? json_decode((string) file_get_contents(self::MEDIA_MANIFEST), true) : null;
        if (!is_array($manifest) || ($manifest['cil'] ?? '') !== $target) {
            $manifest = ['cil' => $target, 'soubory' => []]; // a new target gets everything
        }
        $pending = [];
        foreach (SiteExport::mediaFiles() as $path => $size) {
            $signature = $size . ':' . (int) @filemtime(KALETA_ROOT . '/' . $path);
            if (($manifest['soubory'][$path] ?? '') !== $signature) {
                $pending[$path] = $signature;
            }
        }
        $error = null;
        $done = 0;
        if ($pending !== []) {
            $start = microtime(true);
            try {
                [$put, $close] = self::open($s);
                try {
                    foreach ($pending as $path => $signature) {
                        if (microtime(true) - $start > $seconds) {
                            break;
                        }
                        $put($path, KALETA_ROOT . '/' . $path);
                        $manifest['soubory'][$path] = $signature;
                        $done++;
                    }
                } finally {
                    $close();
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            if (!is_dir(Backup::FOLDER)) {
                @mkdir(Backup::FOLDER, 0775, true);
            }
            file_put_contents(self::MEDIA_MANIFEST, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        $s->set('remote_media_status', date('Y-m-d H:i') . '|' . ($error ?? 'ok') . '|' . (count($pending) - $done));

        return $error;
    }

    /** From background tasks: at most once an hour, or right away while files are still waiting. */
    public static function syncMediaInBackground(Settings $s, int $seconds): void
    {
        if (!self::isOn($s) || !$s->bool('backup_media')) {
            return;
        }
        $waiting = (int) (explode('|', $s->get('remote_media_status'))[2] ?? 0);
        if ($waiting === 0 && time() - $s->int('media_sync_check') < self::MEDIA_INTERVAL) {
            return;
        }
        $s->set('media_sync_check', (string) time());
        self::syncMedia($s, $seconds);
    }

    /**
     * A connection to the target: a function that uploads one file under a path relative to the backup folder, and one
     * that closes the connection.
     *
     * @return array{0: callable(string, string): void, 1: callable(): void}
     */
    private static function open(Settings $s): array
    {
        return $s->get('remote_backup') === 'ftp' ? self::ftp($s) : self::s3($s);
    }

    /**
     * 3.19.1 (N80): can this server send the FTPS copy with the server's certificate checked (cURL with FTPS)? When not,
     * the copy goes through PHP's FTP extension, which encrypts but cannot check whom it talks to.
     */
    public static function ftpsVerified(): bool
    {
        return function_exists('curl_init') && in_array('ftps', (array) (curl_version()['protocols'] ?? []), true);
    }

    /**
     * The address of one file on the FTP server for cURL (3.19.1): the folder from the settings is absolute (%2F – as
     * ftp_chdir('/' . folder) was before), each path segment is encoded; the host may carry a port and is never taken
     * with a scheme or a path.
     */
    public static function ftpUrl(string $host, string $folder, string $remote): string
    {
        $host = (string) preg_replace('#^[a-z]+://|/.*$#iD', '', trim($host));
        $parts = array_values(array_filter(explode('/', trim($folder, '/') . '/' . $remote), fn (string $p): bool => $p !== ''));

        return 'ftp://' . $host . '/' . (trim($folder, '/') !== '' ? '%2F' : '') . implode('/', array_map(rawurlencode(...), $parts));
    }

    /**
     * The cURL options of an FTPS upload (3.19.1, N80): explicit TLS (AUTH TLS) required for the commands and the data,
     * the certificate and the host name checked, only FTP spoken, missing folders created.
     *
     * @return array<int, mixed>
     */
    public static function ftpsOptions(string $user, string $password): array
    {
        return [
            CURLOPT_USE_SSL => CURLUSESSL_ALL, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_FTP | CURLPROTO_FTPS, CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
            CURLOPT_USERNAME => $user, CURLOPT_PASSWORD => $password, CURLOPT_UPLOAD => true,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 300, CURLOPT_RETURNTRANSFER => true,
        ];
    }

    /** @return array{0: callable(string, string): void, 1: callable(): void} */
    private static function ftp(Settings $s): array
    {
        if (self::ftpsVerified()) {
            return self::ftpsCurl($s);
        }
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('The PHP extension for FTP is missing on the server.');
        }
        $host = $s->get('backup_host');
        // encrypted FTPS only: the backup contains passwords and secret keys, over plain FTP they would travel the network
        // readable (the FTP password too)
        if (!function_exists('ftp_ssl_connect')) {
            throw new \RuntimeException('The server cannot use encrypted FTP (FTPS). Send backups to S3 storage, or download them manually.');
        }
        $connection = @ftp_ssl_connect($host, 21, 15);
        if ($connection === false) {
            throw new \RuntimeException(t('The FTP server %s does not support an encrypted connection (FTPS). Kaleta does not use unencrypted FTP – choose S3 storage.', $host));
        }
        if (!@ftp_login($connection, $s->get('backup_user'), $s->get('backup_password'))) {
            throw new \RuntimeException(t('Signing in to the FTP server %s failed.', $host));
        }
        ftp_pasv($connection, true);
        $folder = trim($s->get('backup_folder'), '/');
        if ($folder !== '' && !@ftp_chdir($connection, '/' . $folder)) {
            @ftp_mkdir($connection, '/' . $folder);
            if (!@ftp_chdir($connection, '/' . $folder)) {
                throw new \RuntimeException(t('The folder %s does not exist on the FTP server and cannot be created.', $folder));
            }
        }
        $made = [];
        $put = function (string $remote, string $local) use ($connection, &$made): void {
            // media/2026/09/photo.jpg: the folders on the way are created once per connection
            $dir = '';
            foreach (array_slice(explode('/', $remote), 0, -1) as $part) {
                $dir .= ($dir === '' ? '' : '/') . $part;
                if (!isset($made[$dir])) {
                    @ftp_mkdir($connection, $dir);
                    $made[$dir] = true;
                }
            }
            if (!@ftp_put($connection, $remote, $local, FTP_BINARY)) {
                throw new \RuntimeException(t('The file could not be uploaded to the FTP server.'));
            }
        };

        return [$put, fn () => @ftp_close($connection)];
    }

    /**
     * FTPS through cURL (3.19.1, N80): one handle for the whole run, so cURL keeps the control connection between files.
     *
     * @return array{0: callable(string, string): void, 1: callable(): void}
     */
    private static function ftpsCurl(Settings $s): array
    {
        $host = $s->get('backup_host');
        $folder = $s->get('backup_folder');
        $ch = curl_init();
        $put = function (string $remote, string $local) use ($ch, $s, $host, $folder): void {
            $f = fopen($local, 'rb');
            if ($f === false) {
                throw new \RuntimeException(t('The file could not be uploaded to the FTP server.'));
            }
            curl_setopt_array($ch, [CURLOPT_URL => self::ftpUrl($host, $folder, $remote), CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => (int) filesize($local)]
                + self::ftpsOptions($s->get('backup_user'), $s->get('backup_password')));
            $ok = curl_exec($ch) !== false;
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            fclose($f);
            if (!$ok) {
                throw new \RuntimeException(self::ftpsError($errno, $error, (string) preg_replace('#^[a-z]+://|[:/].*$#iD', '', $host), $folder));
            }
        };

        return [$put, fn () => null]; // PHP 8: the handle (and the connection) closes when $put is released
    }

    /** What a failed FTPS upload through cURL means for the administrator (N80: a certificate that cannot be checked is named). */
    public static function ftpsError(int $errno, string $error, string $host, string $folder): string
    {
        return match ($errno) {
            // the cURL error codes by number – PHP does not define every one of them: 60 peer failed verification, 51 its
            // older code, 77 CA file, 83 issuer, 90 pinned key
            60, 51, 77, 83, 90 => t('The certificate of the FTP server %s could not be checked (%s). Kaleta sends backups only to a server that proves who it is – ask the server\'s administrator for a certificate from a recognised authority (for example Let\'s Encrypt) for this name, or choose S3 storage.', $host, $error),
            CURLE_SSL_CONNECT_ERROR => t('The encrypted connection to the FTP server %s failed (%s).', $host, $error),
            64 /* CURLE_USE_SSL_FAILED */ => t('The FTP server %s does not support an encrypted connection (FTPS). Kaleta does not use unencrypted FTP – choose S3 storage.', $host),
            67 /* CURLE_LOGIN_DENIED */ => t('Signing in to the FTP server %s failed.', $host),
            CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT => t('The FTP server %s could not be reached (%s).', $host, $error),
            9 /* CURLE_REMOTE_ACCESS_DENIED */ => t('The folder %s does not exist on the FTP server and cannot be created.', trim($folder, '/')),
            default => t('The file could not be uploaded to the FTP server.') . ($error !== '' ? ' (' . $error . ')' : ''),
        };
    }

    /** @return array{0: callable(string, string): void, 1: callable(): void} */
    private static function s3(Settings $s): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException(t('The cURL extension is missing on the server.'));
        }
        $host = preg_replace('#^https?://|/.*$#', '', $s->get('backup_host')) ?? '';
        $bucket = trim($s->get('backup_folder'), '/');
        if ($bucket === '' || !preg_match('/^[a-z0-9.-]+$/iD', $host)) {
            throw new \RuntimeException(t('Fill in the storage address (e.g. s3.eu-central-1.amazonaws.com) and the bucket name.'));
        }
        // tests: the storage can be a local fake server (only through the database, it is not in the admin)
        $base = 'https://' . $host;
        $test = $s->get('backup_test_url');
        if ($test !== '' && preg_match('#^http://(127\.0\.0\.1:\d+)$#D', $test, $m)) {
            [$base, $host] = [$test, $m[1]];
        }
        $put = function (string $remote, string $local) use ($s, $host, $bucket, $base): void {
            $uri = '/' . implode('/', array_map(rawurlencode(...), explode('/', $bucket . '/' . $remote)));
            $headers = self::signS3('PUT', $host, $uri, (string) hash_file('sha256', $local), $s->get('backup_region') ?: 'us-east-1', $s->get('backup_user'), $s->get('backup_password'), time());
            $f = fopen($local, 'rb');
            $ch = curl_init($base . $uri);
            curl_setopt_array($ch, [
                CURLOPT_PUT => true, CURLOPT_INFILE => $f, CURLOPT_INFILESIZE => filesize($local), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
                CURLOPT_PROTOCOLS => str_starts_with($base, 'https://') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
                CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
            ]);
            $response = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            fclose($f);
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException(t('The storage refused the upload (code %s)', (string) $code) . (preg_match('#<Message>([^<]+)#', $response, $m) ? ': ' . $m[1] : ($code === 0 ? ': ' . t('could not connect') : '')) . '.');
            }
        };

        return [$put, fn () => null];
    }

    /**
     * Request headers signed with AWS Signature Version 4 (service s3).
     *
     * @return array<string, string>
     */
    public static function signS3(string $method, string $host, string $uri, string $bodyHash, string $region, string $key, string $secret, int $time): array
    {
        $date = gmdate('Ymd\THis\Z', $time);
        $day = substr($date, 0, 8);
        $headers = ['host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date];
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = $method . "\n" . $uri . "\n\n" . implode('', array_map(fn (string $k, string $v): string => $k . ':' . $v . "\n", array_keys($headers), $headers)) . "\n" . $signedHeaders . "\n" . $bodyHash;
        $scope = $day . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $secret, true), true), true), true);

        return [
            'Host' => $host, 'x-amz-content-sha256' => $bodyHash, 'x-amz-date' => $date,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $key . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . hash_hmac('sha256', $stringToSign, $k),
        ];
    }
}
