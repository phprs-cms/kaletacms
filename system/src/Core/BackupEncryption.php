<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Encryption at rest of the database backups that leave the server (3.28, D-W12): before a backup goes to the off-site
 * target (FTPS or S3, Core\RemoteBackup) it is encrypted with authenticated encryption under a key derived from the
 * site's recovery key (Core\RecoveryKey). The local backup in storage/zalohy/ stays as it was, so restoring on the same
 * server works as before; an encrypted copy (….sql.gz.enc) uploaded back into storage/zalohy/ is restored with the
 * recovery key (Backup::restore decrypts it into a temporary file first, every chunk checked, and only then touches the
 * database – a wrong key or a damaged file never leaves the database half restored).
 *
 * The file is streamed in chunks (CHUNK bytes), so a large backup never has to fit in memory.
 *
 * Format (version 1), all integers big-endian:
 *   header  = MAGIC (8) | version (1) | algorithm (1) | chunk size (4) | key id (8) | salt (16) [| secretstream header (24)]
 *   sodium  = chunks of: length (4) | secretstream ciphertext – the last one tagged FINAL; the header is the additional data
 *   openssl = chunks of: final flag (1) | length (4) | AES-256-GCM ciphertext | tag (16); the nonce is the chunk counter,
 *             the additional data the header, the counter and the flag (no reordering, no cut at a chunk boundary)
 * The file key is HKDF-SHA256 of the recovery key with the per-file salt and the algorithm in the info, so no two files
 * share a key (the GCM counter nonce is then never reused). The key id lets a restore say "this key does not belong to
 * this backup" instead of "damaged" – it is a one-way value and does not help anyone guess the key.
 *
 * libsodium's secretstream (XChaCha20-Poly1305) is used when PHP has it; otherwise OpenSSL AES-256-GCM. The algorithm is
 * written in the header, so a restore knows which one it needs.
 */
final class BackupEncryption
{
    /** PNG-style: a transfer in FTP's text mode (CR/LF changed) or a cut-off first block is noticed at once. */
    public const string MAGIC = "\x89KBK\r\n\x1a\n";
    public const int VERSION = 1;
    public const int SODIUM = 1;
    public const int OPENSSL = 2;
    /** 1 MiB of the backup per chunk. */
    public const int CHUNK = 1048576;
    /** The extension of an encrypted backup. */
    public const string EXTENSION = '.enc';

    private const int SALT_BYTES = 16;
    private const int ID_BYTES = 8;
    private const int GCM_TAG = 16;
    /** A header asking for chunks outside these bounds is not a file of ours. */
    private const int MIN_CHUNK = 1024;
    private const int MAX_CHUNK = 16777216;

    /** The algorithm this server can encrypt with: sodium when available, otherwise OpenSSL; null = neither. */
    public static function algorithm(): ?int
    {
        return match (true) {
            self::available(self::SODIUM) => self::SODIUM,
            self::available(self::OPENSSL) => self::OPENSSL,
            default => null,
        };
    }

    public static function available(int $algorithm): bool
    {
        return match ($algorithm) {
            self::SODIUM => function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'),
            self::OPENSSL => function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
            default => false,
        };
    }

    public static function name(int $algorithm): string
    {
        return [self::SODIUM => 'XChaCha20-Poly1305 (libsodium)', self::OPENSSL => 'AES-256-GCM (OpenSSL)'][$algorithm] ?? '?';
    }

    /** The one-way id of a recovery key (hex, 16 characters): in the header of every file and in the setting backup_key_confirmed. */
    public static function keyId(string $key): string
    {
        return bin2hex(self::rawId($key));
    }

    /** Is this file (by its first bytes) an encrypted backup of this format? */
    public static function isEncrypted(string $path): bool
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return false;
        }
        $start = (string) fread($f, strlen(self::MAGIC));
        fclose($f);

        return $start === self::MAGIC;
    }

    /**
     * The header of an encrypted file: version, algorithm, chunk size and key id (hex). Throws a RuntimeException with a
     * message for the administrator when it is not one, or one this version cannot read.
     *
     * @return array{version: int, algorithm: int, chunk: int, key_id: string}
     */
    public static function inspect(string $path): array
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            throw new \RuntimeException(t('The encrypted backup cannot be read.'));
        }
        try {
            $header = self::readHeader($f);
        } finally {
            fclose($f);
        }

        return ['version' => $header['version'], 'algorithm' => $header['algorithm'], 'chunk' => $header['chunk'], 'key_id' => bin2hex($header['id'])];
    }

    /**
     * Encrypts $from into $to (written next to it, renamed when complete). $algorithm and $chunk only for tests.
     *
     * @throws \RuntimeException
     */
    public static function encryptFile(string $from, string $to, string $key, ?int $algorithm = null, int $chunk = self::CHUNK): void
    {
        $algorithm ??= self::algorithm();
        if ($algorithm === null || !self::available($algorithm)) {
            throw new \RuntimeException(t('This server\'s PHP can neither use libsodium nor OpenSSL AES-256-GCM, so the backup cannot be encrypted and is not copied off the server. Ask the hosting for the sodium or openssl extension.'));
        }
        if ($chunk < self::MIN_CHUNK || $chunk > self::MAX_CHUNK || strlen($key) !== 32) {
            throw new \InvalidArgumentException('Invalid chunk size or key.');
        }
        $in = @fopen($from, 'rb');
        if ($in === false) {
            throw new \RuntimeException(t('The backup cannot be read.'));
        }
        $partial = $to . '.part';
        $out = @fopen($partial, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException(t('The encrypted copy of the backup cannot be written – check the write permissions of storage/zalohy.'));
        }
        try {
            $salt = random_bytes(self::SALT_BYTES);
            $header = self::MAGIC . chr(self::VERSION) . chr($algorithm) . pack('N', $chunk) . self::rawId($key) . $salt;
            $fileKey = self::fileKey($key, $salt, $algorithm);
            if ($algorithm === self::SODIUM) {
                [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
                $header .= $streamHeader;
            }
            self::write($out, $header);
            $counter = 0;
            $current = self::readFull($in, $chunk);
            while (true) {
                // read ahead: the chunk before the end of the file is the final one (an empty file is one empty final chunk)
                $next = $current === '' ? '' : self::readFull($in, $chunk);
                $final = $next === '';
                if ($algorithm === self::SODIUM) {
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, $header,
                        $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                    self::write($out, pack('N', strlen($cipher)) . $cipher);
                } else {
                    $flag = $final ? "\x01" : "\x00";
                    $tag = '';
                    $cipher = openssl_encrypt($current, 'aes-256-gcm', $fileKey, OPENSSL_RAW_DATA, self::nonce($counter), $tag, $header . pack('J', $counter) . $flag, self::GCM_TAG);
                    if ($cipher === false) {
                        throw new \RuntimeException(t('The backup could not be encrypted.'));
                    }
                    self::write($out, $flag . pack('N', strlen($cipher)) . $cipher . $tag);
                }
                $counter++;
                if ($final) {
                    break;
                }
                $current = $next;
            }
            if (!fflush($out)) {
                throw new \RuntimeException(t('The encrypted copy of the backup cannot be written – check the write permissions of storage/zalohy.'));
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($partial);
            throw $e;
        } finally {
            fclose($in);
            if (isset($state)) {
                sodium_memzero($state);
            }
        }
        fclose($out);
        if (!@rename($partial, $to)) {
            @unlink($partial);
            throw new \RuntimeException(t('The encrypted copy of the backup cannot be written – check the write permissions of storage/zalohy.'));
        }
    }

    /**
     * Decrypts $from into $to. Every chunk is checked, and the file must end with its final chunk – $to exists only when
     * the whole backup was read back exactly (a wrong key, a damaged or a cut-off file throws and leaves nothing).
     *
     * @throws \RuntimeException with a message for the administrator
     */
    public static function decryptFile(string $from, string $to, string $key): void
    {
        $in = @fopen($from, 'rb');
        if ($in === false) {
            throw new \RuntimeException(t('The encrypted backup cannot be read.'));
        }
        $partial = $to . '.part';
        $out = null;
        try {
            $header = self::readHeader($in);
            if (!hash_equals($header['id'], self::rawId($key))) {
                throw new \RuntimeException(t('The recovery key does not belong to this backup – check that it was copied in full, or use the key of the site the backup comes from. Nothing was restored.'));
            }
            if (!self::available($header['algorithm'])) {
                throw new \RuntimeException($header['algorithm'] === self::SODIUM
                    ? t('This backup was encrypted with libsodium, which this server\'s PHP lacks. Restore it on a server with the sodium extension (part of standard PHP), or ask the hosting to enable it.')
                    : t('This backup was encrypted with OpenSSL AES-256-GCM, which this server\'s PHP lacks. Ask the hosting for the openssl extension.'));
            }
            $out = @fopen($partial, 'wb');
            if ($out === false) {
                throw new \RuntimeException(t('The backup cannot be decrypted here – check the write permissions of storage/zalohy.'));
            }
            $fileKey = self::fileKey($key, $header['salt'], $header['algorithm']);
            $damaged = t('The encrypted backup is damaged or was changed – it cannot be restored. Nothing was restored.');
            $cut = t('The encrypted backup is incomplete (the end of the file is missing) – upload it again in full. Nothing was restored.');
            if ($header['algorithm'] === self::SODIUM) {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header['stream'], $fileKey);
                $max = $header['chunk'] + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
                while (true) {
                    $length = self::readFull($in, 4);
                    if ($length === '') {
                        throw new \RuntimeException($cut);
                    }
                    $size = strlen($length) === 4 ? (int) unpack('N', $length)[1] : -1;
                    if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > $max) {
                        throw new \RuntimeException($size === -1 ? $cut : $damaged);
                    }
                    $cipher = self::readFull($in, $size);
                    if (strlen($cipher) !== $size) {
                        throw new \RuntimeException($cut);
                    }
                    $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $header['raw']);
                    if ($result === false) {
                        throw new \RuntimeException($damaged);
                    }
                    [$plain, $tag] = $result;
                    self::write($out, $plain);
                    if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        break;
                    }
                }
            } else {
                $counter = 0;
                while (true) {
                    $flag = self::readFull($in, 1);
                    if ($flag === '') {
                        throw new \RuntimeException($cut);
                    }
                    $length = self::readFull($in, 4);
                    $size = strlen($length) === 4 ? (int) unpack('N', $length)[1] : -1;
                    if ($size === -1) {
                        throw new \RuntimeException($cut);
                    }
                    if (($flag !== "\x00" && $flag !== "\x01") || $size > $header['chunk']) {
                        throw new \RuntimeException($damaged);
                    }
                    $cipher = self::readFull($in, $size + self::GCM_TAG);
                    if (strlen($cipher) !== $size + self::GCM_TAG) {
                        throw new \RuntimeException($cut);
                    }
                    $plain = openssl_decrypt(substr($cipher, 0, $size), 'aes-256-gcm', $fileKey, OPENSSL_RAW_DATA, self::nonce($counter),
                        substr($cipher, $size), $header['raw'] . pack('J', $counter) . $flag);
                    if ($plain === false) {
                        throw new \RuntimeException($damaged);
                    }
                    self::write($out, $plain);
                    $counter++;
                    if ($flag === "\x01") {
                        break;
                    }
                }
            }
            if (fread($in, 1) !== '') {
                throw new \RuntimeException($damaged); // anything after the final chunk is not ours
            }
            if (!fflush($out)) {
                throw new \RuntimeException(t('The backup cannot be decrypted here – check the write permissions of storage/zalohy.'));
            }
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($partial);
            throw $e;
        } finally {
            fclose($in);
        }
        fclose($out);
        if (!@rename($partial, $to)) {
            @unlink($partial);
            throw new \RuntimeException(t('The backup cannot be decrypted here – check the write permissions of storage/zalohy.'));
        }
    }

    /**
     * @param resource $f
     * @return array{version: int, algorithm: int, chunk: int, id: string, salt: string, stream: string, raw: string}
     */
    private static function readHeader($f): array
    {
        $fixed = strlen(self::MAGIC) + 1 + 1 + 4 + self::ID_BYTES + self::SALT_BYTES;
        $raw = self::readFull($f, $fixed);
        if (strlen($raw) < strlen(self::MAGIC) || !str_starts_with($raw, self::MAGIC)) {
            throw new \RuntimeException(t('The file is not an encrypted Kaleta backup (or it was changed on the way, e.g. uploaded over FTP in text mode).'));
        }
        if (strlen($raw) !== $fixed) {
            throw new \RuntimeException(t('The encrypted backup is incomplete (the end of the file is missing) – upload it again in full. Nothing was restored.'));
        }
        $version = ord($raw[8]);
        if ($version !== self::VERSION) {
            throw new \RuntimeException(t('The backup was encrypted by a newer version of Kaleta (format %d). Update Kaleta first, then restore it.', $version));
        }
        $algorithm = ord($raw[9]);
        $chunk = (int) unpack('N', substr($raw, 10, 4))[1];
        if (!in_array($algorithm, [self::SODIUM, self::OPENSSL], true) || $chunk < self::MIN_CHUNK || $chunk > self::MAX_CHUNK) {
            throw new \RuntimeException(t('The encrypted backup is damaged or was changed – it cannot be restored. Nothing was restored.'));
        }
        $stream = '';
        if ($algorithm === self::SODIUM) {
            $stream = self::readFull($f, 24); // SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES, without needing sodium to read it
            if (strlen($stream) !== 24) {
                throw new \RuntimeException(t('The encrypted backup is incomplete (the end of the file is missing) – upload it again in full. Nothing was restored.'));
            }
        }

        return ['version' => $version, 'algorithm' => $algorithm, 'chunk' => $chunk, 'id' => substr($raw, 14, self::ID_BYTES),
            'salt' => substr($raw, 14 + self::ID_BYTES, self::SALT_BYTES), 'stream' => $stream, 'raw' => $raw . $stream];
    }

    /** The key of one file: HKDF-SHA256 of the recovery key, the file's salt, the algorithm in the info. */
    private static function fileKey(string $key, string $salt, int $algorithm): string
    {
        return hash_hkdf('sha256', $key, 32, 'kaleta-backup-file|v' . self::VERSION . '|' . $algorithm, $salt);
    }

    private static function rawId(string $key): string
    {
        return substr(hash_hmac('sha256', 'kaleta-backup-key-id', $key, true), 0, self::ID_BYTES);
    }

    /** The GCM nonce of chunk $counter: 4 zero bytes and the counter (the file key is unique per file). */
    private static function nonce(int $counter): string
    {
        return "\0\0\0\0" . pack('J', $counter);
    }

    /**
     * Up to $length bytes – fewer only at the end of the file.
     *
     * @param resource $f
     */
    private static function readFull($f, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($f)) {
            $part = fread($f, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }

        return $data;
    }

    /** @param resource $f */
    private static function write($f, string $data): void
    {
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $n) {
            $n = fwrite($f, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException(t('The encrypted copy of the backup cannot be written – check the write permissions of storage/zalohy.'));
            }
        }
    }
}
