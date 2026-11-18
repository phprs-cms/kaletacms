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
        $header = self::MAGIC . pack('n', strlen($wrapped)) . $wrapped . $nonce;
        // 3.46 review H1: the header and the ciphertext are written one after the other – never joined into a third copy
        // of the content in memory (the plain content and the ciphertext are the peak: twice the file)
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.part';
        $written = false;
        if (is_dir(dirname($file)) || @mkdir(dirname($file), 0770, true) || is_dir(dirname($file))) {
            $handle = @fopen($temporary, 'xb');
            if ($handle !== false) {
                $written = @fwrite($handle, $header) === strlen($header) && @fwrite($handle, $cipher) === strlen($cipher);
                $written = @fclose($handle) && $written;
            }
        }
        unset($cipher);
        if (!$written || !@rename($temporary, $file)) {
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
        $offset = $length !== null ? 6 + $length : 0;
        if (strlen($raw) - $offset <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        // 3.46 review H1: one copy of the ciphertext straight from the file, the file itself freed before decrypting – the
        // peak is the ciphertext and the content (twice the file), not five copies
        $nonce = substr($raw, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, $offset + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        unset($raw);
        foreach ([$key, null] as $try) {
            $candidate = $try ?? ($length !== null ? self::headerKey($s, $purpose, $file) : null);
            $raw64 = $candidate !== null ? base64_decode($candidate, true) : false;
            if ($raw64 === false || strlen($raw64) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                continue;
            }
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $raw64);
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
        if (!is_file($file) || !self::roomFor((int) @filesize($file))) {
            return null; // gone, or too large for the memory this request may use – left as it is (review H1: checked before any read)
        }
        $existing = self::headerKey($s, $purpose, $file);
        if ($existing !== null && self::open($s, $purpose, $file, $existing) !== null) {
            return $existing; // 1. sealed already
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
        sodium_memzero($plain); // review H1: only the hash is kept while the copy is verified
        unset($plain);
        $check = self::open($s, $purpose, $copy, $key);
        $same = $check !== null && hash_equals($hash, hash('sha256', $check));
        unset($check);
        if (!$same || self::headerKey($s, $purpose, $copy) === null) {
            @unlink($copy);

            return null;
        }
        if ($stopAfter === 1) {
            return null;
        }
        if (!@rename($copy, $file)) {
            @unlink($copy);

            return null;
        }

        return $key;
    }

    /**
     * Review N2: '1' while a pass over the private files of before 3.46 is still due – set by migration 0134 when a visitor's
     * request finished it with the structure only; the administrator's requests and the daily job seal (sealAll) and clear
     * it once a pass got through every file. Visitors' requests never run the migration again for it.
     */
    public const string UNSEALED = 'private_files_unsealed';

    /**
     * Is there possibly anything of before 3.46 to seal? Cheap: an internal file version without its key, or any report with
     * attachments (whether they are sealed needs the key – the first pass tells).
     */
    public static function workLeft(Db $db): bool
    {
        return InternalFiles::unsealedLeft($db) || Whistleblowing::unsealedLeft($db);
    }

    /**
     * Review N2: one pass over the private files of before 3.46 (whistleblowing attachments, then internal files) within
     * $seconds; when it got through every file, UNSEALED is cleared. Returns whether it did.
     */
    public static function sealAll(Settings $s, float $seconds): bool
    {
        $probe = (string) $s->db()->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'update_probe'");
        $probeAt = (int) $s->db()->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'update_probe_at'");
        if ($probe !== '') {
            if ($probeAt <= 0) { // a probe an older release's updater wrote: counted from when it was first seen (as migration 0134 does)
                [$seenHash, $seenAt] = array_pad(explode('|', $s->get('private_files_probe_seen'), 2), 2, '');
                $probeAt = hash_equals($seenHash, hash('sha256', $probe)) ? (int) $seenAt : 0;
                if ($probeAt <= 0) {
                    $probeAt = time();
                    $s->set('private_files_probe_seen', hash('sha256', $probe) . '|' . $probeAt);
                }
            }
            if (time() - $probeAt < 600) {
                return false; // never while an update checks itself – an update that rolls back must find the files as its code reads them
            }
        }
        $deadline = microtime(true) + $seconds;
        $reports = Whistleblowing::sealPending($s, $deadline);
        $files = $reports['left'] ? ['left' => true] : InternalFiles::sealPending($s, $deadline);
        $done = !$reports['left'] && !$files['left'];
        if ($done && $s->get(self::UNSEALED) !== '') {
            $s->set(self::UNSEALED, '');
        }

        return $done;
    }

    /** The setting that names the file a run is sealing right now (review H1): left set only by a request that died on it. */
    public const string SEALING = 'private_files_sealing';

    /** The files a run died on, "id|memory_limit in bytes" per line – skipped until the memory limit is raised above that. */
    public const string SKIPPED = 'private_files_skipped';

    /**
     * Before a run of sealPending (review H1): a file named in SEALING killed the previous run (a memory or time limit no
     * try/catch can catch) – it goes to SKIPPED with the memory limit of that time, an event says so, and no run tries it
     * first again. It stays readable as it is.
     */
    public static function takeOverDeadRun(Settings $s): void
    {
        $dead = $s->get(self::SEALING);
        if ($dead === '') {
            return;
        }
        $lines = array_filter(explode("\n", $s->get(self::SKIPPED)), static fn (string $l): bool => $l !== '' && !str_starts_with($l, $dead . '|'));
        $lines[] = $dead . '|' . self::bytes((string) ini_get('memory_limit'));
        $s->set(self::SKIPPED, implode("\n", array_slice($lines, -200)));
        $s->set(self::SEALING, '');
        try {
            Events::record($s->db(), 'files.seal_failed', 'warning', t('A private file could not be sealed – the request ran out of memory or time on it (%s). It stays readable as it is; raise memory_limit and it is tried again.', $dead), ['file' => $dead]);
        } catch (\Throwable) {
            // a record never stops the work
        }
    }

    /** Does a run skip this file (it killed a run before, and the memory limit is not higher now)? */
    public static function skipped(Settings $s, string $id): bool
    {
        foreach (explode("\n", $s->get(self::SKIPPED)) as $line) {
            [$name, $limit] = array_pad(explode('|', $line, 2), 2, '0');
            if ($name === $id) {
                $now = self::bytes((string) ini_get('memory_limit'));

                return $now >= 0 && $now <= (int) $limit;
            }
        }

        return false;
    }

    /**
     * sealInPlace() with the guard of review H1: the file's id is written into SEALING before and cleared after, so a run a
     * fatal error ends on this file is noticed by the next one (takeOverDeadRun) instead of dying on it again.
     */
    public static function sealGuarded(Settings $s, string $id, string $purpose, string $file, string $sha256 = '', int $stopAfter = 0): ?string
    {
        $s->set(self::SEALING, $id);
        try {
            return self::sealInPlace($s, $purpose, $file, $sha256, $stopAfter);
        } finally {
            $s->set(self::SEALING, '');
        }
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
     * Is there memory to seal or open a file of $bytes? 3.46 review H1: write(), open() and sealInPlace() hold at most two
     * copies of the content at once (the content and its ciphertext; smoke 4191 measures it); the budget is three copies
     * and 32 MB for the rest of the request. The limit is raised when PHP lets it; false when it stays too low – the
     * caller leaves the file as it is (or refuses the download with a message), never a fatal error.
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
