<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Files sealed at rest (3.34 for job applicants' CVs, shared since 3.46 – N162, N165): the file format and the crypto that
 * Core\Applications introduced, taken out of it unchanged so that whistleblowing attachments (Core\Whistleblowing),
 * internal files (Core\InternalFiles) and the attachments of requests to Claude (Core\Requests) are sealed the same way.
 *
 * A sealed file: MAGIC, the length of the wrapped key (2 bytes), the per-file key wrapped with the Vault key of the purpose
 * (Vault::key, the key in the database while config.php has none), the nonce and the secretbox of the content. The per-file
 * key is also kept by the caller in a column the Vault reseals when its key moves or rotates (Vault::SEALED); the wrapped
 * copy in the header opens the file with the Vault key alone when the row lost its key (a restored backup older than the
 * sealing, an interrupted run). Nothing here is new crypto: sodium secretbox and Core\Vault, as since 3.34.
 *
 * A file is always written next to its place and renamed, so a name never holds half a file. sealInPlace() turns a plain
 * file into a sealed one under the same name, crash-safe: an already sealed file (its header opens with this site's key)
 * is never sealed again, and the plain file is replaced only once the sealed copy opened to the same content.
 */
final class SealedFiles
{
    /** The first bytes of a sealed file (the format of 3.34, Applications::FILE_MAGIC). */
    public const string MAGIC = 'KCV1';

    /**
     * Encrypts $plain into $file under a new random key wrapped into the header with the Vault key of $purpose; returns the
     * per-file key (base64), null when it cannot be written. The folder is created (0770) when missing.
     */
    public static function write(Settings $s, string $purpose, string $plain, string $file): ?string
    {
        $key = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrapped = Vault::seal(self::wrapKey($s, $purpose), $key);
        $blob = self::MAGIC . pack('n', strlen($wrapped)) . $wrapped . $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.part';
        if ((!is_dir(dirname($file)) && !@mkdir(dirname($file), 0770, true) && !is_dir(dirname($file)))
            || @file_put_contents($temporary, $blob, LOCK_EX) !== strlen($blob) || !@rename($temporary, $file)) {
            @unlink($temporary);

            return null;
        }

        return base64_encode($key);
    }

    /** The key that wraps the per-file keys in the headers: the Vault key of the purpose, the key in the database while config.php has none (3.34 review M5). */
    public static function wrapKey(Settings $s, string $purpose): string
    {
        return Vault::state($s) === 'missing' ? Vault::derive((new Antispam($s->db(), $s))->key(), $purpose) : Vault::key($s, $purpose);
    }

    /**
     * The per-file key of a sealed file from its header (3.34 second review N9): unwrapped with the Vault key, the key in the
     * database or the previous signing key; null when none opens it or the file has no header.
     */
    public static function headerKey(Settings $s, string $purpose, string $file): ?string
    {
        $raw = @file_get_contents($file, false, null, 0, 600);
        $length = is_string($raw) ? self::headerLength($raw) : null;
        if ($length === null) {
            return null;
        }
        $wrapped = substr((string) $raw, 6, $length);
        [$previous] = Vault::previousKey($s);
        foreach (array_unique(array_filter([Vault::key($s, $purpose), Vault::derive((new Antispam($s->db(), $s))->key(), $purpose),
            $previous !== null ? Vault::derive($previous, $purpose) : null])) as $candidate) {
            $key = Vault::open($candidate, $wrapped);
            if ($key !== null && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return base64_encode($key);
            }
        }

        return null;
    }

    /** The length of the wrapped key in a sealed file's header, null when the file has no header. Pure. */
    public static function headerLength(string $raw): ?int
    {
        $length = str_starts_with($raw, self::MAGIC) && strlen($raw) > 6 ? unpack('n', substr($raw, 4, 2)) : false;

        return is_array($length) && is_int($length[1] ?? null) ? $length[1] : null;
    }

    /**
     * The content of a sealed file: with the key the row holds (base64), otherwise with the key of its header (headerKey());
     * null when it is gone or neither opens it. A file without the header (a CV sealed before 3.34 second review) opens with
     * the row's key only.
     */
    public static function open(Settings $s, string $purpose, string $file, ?string $key): ?string
    {
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($raw)) {
            return null;
        }
        $length = self::headerLength($raw);
        $body = $length !== null ? substr($raw, 6 + $length) : $raw;
        if (strlen($body) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        foreach ([$key, null] as $try) {
            $candidate = $try ?? ($length !== null ? self::headerKey($s, $purpose, $file) : null);
            $raw64 = $candidate !== null ? base64_decode($candidate, true) : false;
            if ($raw64 === false || strlen($raw64) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                continue;
            }
            $plain = sodium_crypto_secretbox_open(substr($body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $raw64);
            if ($plain !== false) {
                return $plain;
            }
        }

        return null;
    }

    /**
     * Is this file sealed by this site (its header opens with this site's key)? A plain file that happens to start with
     * MAGIC is not – the wrapped key would have to authenticate under the Vault key.
     */
    public static function isSealed(Settings $s, string $purpose, string $file): bool
    {
        return self::headerKey($s, $purpose, $file) !== null;
    }

    /**
     * The content of a file that is sealed or still plain (3.46): sealed – opened with $key or its header; plain (no header
     * this site opens) – read as it is, only when $plainAllowed (the row says it was never sealed). $sha256 (when known):
     * the content must have that hash, so ciphertext whose key is gone is never handed out as the file. Null when gone or
     * unreadable.
     */
    public static function read(Settings $s, string $purpose, string $file, ?string $key, bool $plainAllowed, string $sha256 = ''): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $content = self::open($s, $purpose, $file, $key);
        if ($content === null && $plainAllowed) {
            $raw = @file_get_contents($file);
            // a plain file that starts like a sealed one (MAGIC) is handed out only when its hash says it is the file
            $content = is_string($raw) && (self::headerLength($raw) === null || ($sha256 !== '' && hash_equals($sha256, hash('sha256', $raw)))) ? $raw : null;
        }
        if ($content !== null && $sha256 !== '' && !hash_equals($sha256, hash('sha256', $content))) {
            return null;
        }

        return $content;
    }

    /**
     * Seals a plain file under the same name, crash-safe (3.46, migration 0134): returns the per-file key, null when the file
     * is gone, cannot be read (not enough memory for it) or the sealed copy cannot be written or does not open to the same
     * content – the plain file then stays as it is. The steps, each safe to be the last a killed request ran:
     *  1. a file whose header this site's key opens is sealed already – its key is returned, nothing is written (never
     *     sealed twice; a run killed after step 3 and before its row was updated finds it so);
     *  2. the sealed copy is written next to the file (.part, renamed to <file>.<random>.sealing) and opened with the key it
     *     returned and with its header key, and compared with the plain content ($sha256 when given: the hash the row keeps);
     *  3. only then it replaces the plain file (rename – atomic on one disk).
     * $stopAfter (tests): 1 = the copy is written and left beside, as a killed request would leave it.
     */
    public static function sealInPlace(Settings $s, string $purpose, string $file, string $sha256 = '', int $stopAfter = 0): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $existing = self::headerKey($s, $purpose, $file);
        if ($existing !== null && self::open($s, $purpose, $file, $existing) !== null) {
            return $existing; // 1. sealed already
        }
        $size = (int) @filesize($file);
        if (!self::roomFor($size)) {
            return null;
        }
        $plain = @file_get_contents($file);
        if (!is_string($plain) || ($sha256 !== '' && !hash_equals($sha256, hash('sha256', $plain)))) {
            return null; // unreadable, or not the file the row describes – left as it is
        }
        $copy = $file . '.' . bin2hex(random_bytes(4)) . '.sealing';
        $key = self::write($s, $purpose, $plain, $copy);
        if ($key === null) {
            return null;
        }
        $hash = hash('sha256', $plain);
        $check = self::open($s, $purpose, $copy, $key);
        $header = self::headerKey($s, $purpose, $copy);
        if ($check === null || $header === null || !hash_equals($hash, hash('sha256', $check))) {
            @unlink($copy);

            return null;
        }
        sodium_memzero($plain);
        if ($stopAfter === 1) {
            return null;
        }
        if (!@rename($copy, $file)) {
            @unlink($copy);

            return null;
        }

        return $key;
    }

    /** Leftover copies of sealInPlace() (.sealing) and write() (.part) in a folder tree, older than an hour – removed. Returns how many. */
    public static function sweepLeftovers(string $folder): int
    {
        if (!is_dir($folder)) {
            return 0;
        }
        $removed = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && preg_match('/\.[a-f0-9]{8}\.(sealing|part)$/D', $f->getFilename()) === 1 && $f->getMTime() < time() - 3600) {
                $removed += @unlink($f->getPathname()) ? 1 : 0;
            }
        }

        return $removed;
    }

    /** The name of the database lock of the file work (sealPending of the domains, migration 0134): per database and table prefix, at most 64 characters. */
    private const string LOCK_NAME = "CONCAT('kaleta_seal_', LEFT(SHA2(CONCAT(IFNULL(DATABASE(), ''), '|', ?), 256), 40))";

    /** Takes the lock of the file work without waiting – false when another run holds it. */
    public static function lock(Db $db): bool
    {
        return (int) $db->value('SELECT GET_LOCK(' . self::LOCK_NAME . ', 0)', [$db->prefix]) === 1;
    }

    public static function unlock(Db $db): void
    {
        $db->value('SELECT RELEASE_LOCK(' . self::LOCK_NAME . ')', [$db->prefix]);
    }

    /** A deny-all .htaccess in a private folder (storage/ has one too – a second line of defence on a misconfigured host). */
    public static function protect(string $folder): void
    {
        if (is_dir($folder) && !is_file($folder . '/.htaccess')) {
            @file_put_contents($folder . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }

    /**
     * Is there memory to seal or open a file of $bytes (the content, the sealed copy and the opened check are in memory at
     * once)? The limit is raised when PHP lets it; false when it stays too low – the caller leaves the file as it is.
     */
    public static function roomFor(int $bytes): bool
    {
        $need = 3 * $bytes + 32 * 1048576;
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit < 0 || $limit - memory_get_usage() >= $need) {
            return true;
        }
        @ini_set('memory_limit', (string) (memory_get_usage() + $need));
        $limit = self::bytes((string) ini_get('memory_limit'));

        return $limit < 0 || $limit - memory_get_usage() >= $need;
    }

    /** "128M" => bytes, "-1" => -1 (no limit). Pure. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
