<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The recovery key of the off-site backups (3.28, D-W12): one random 256-bit key per site that encrypts every database
 * backup before it leaves the server (Core\BackupEncryption). It is kept outside the database – in storage/backup-key.php
 * (never reachable over the web, never in a backup, an export or the off-site copy) – so a stolen database dump or an
 * off-site copy alone cannot be decrypted.
 *
 * The site secret is deliberately not part of the key: restoring after losing the hosting must work with the recovery key
 * alone – secret_key lives in the database inside the encrypted backup, and vault_key in config.php is lost with the
 * server. The key of each file is derived from the recovery key and a per-file salt (BackupEncryption::fileKey), in the
 * same spirit as Core\Vault derives one key per purpose from one base key.
 *
 * The administrator downloads or copies the key once (Settings → Backups and updates) and confirms they saved it; only
 * then are backups copied off the server (RemoteBackup::upload pauses the copies until then – an off-site backup nobody
 * can decrypt would be worse than none, and so would a silent unencrypted one). backup_key_confirmed holds the key's
 * one-way id and the time of the confirmation, never the key. After the confirmation the administration never shows the
 * key again. The key never goes into logs, e-mails, events, the change log or MCP.
 */
final class RecoveryKey
{
    public const string FILE = KALETA_ROOT . '/storage/backup-key.php';

    /** "KBK1" = Kaleta backup key, format 1. */
    private const string PREFIX = 'KBK1';
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * none (no key yet), unconfirmed (a key exists, the administrator has not confirmed saving it – off-site copies wait),
     * confirmed (off-site copies are encrypted with it).
     */
    public static function state(Settings $settings, string $file = self::FILE): string
    {
        $key = self::load($file);
        if ($key === null) {
            return 'none';
        }

        return hash_equals(self::confirmedId($settings), BackupEncryption::keyId($key)) ? 'confirmed' : 'unconfirmed';
    }

    /** May a backup go off the server now (the key exists and its saving was confirmed)? */
    public static function ready(Settings $settings, string $file = self::FILE): bool
    {
        return self::state($settings, $file) === 'confirmed';
    }

    /** The id of the confirmed key from backup_key_confirmed ("id|Y-m-d H:i"), '' when none. */
    public static function confirmedId(Settings $settings): string
    {
        $id = explode('|', $settings->get('backup_key_confirmed'), 2)[0];

        return preg_match('/^[0-9a-f]{16}$/D', $id) === 1 ? $id : '';
    }

    /** When the key was confirmed ("Y-m-d H:i"), '' when not. */
    public static function confirmedAt(Settings $settings): string
    {
        return self::confirmedId($settings) !== '' ? (explode('|', $settings->get('backup_key_confirmed'), 2)[1] ?? '') : '';
    }

    /** The key (32 bytes), null when there is none or the file is damaged. */
    public static function load(string $file = self::FILE): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
            if (preg_match('/^key=([A-Za-z0-9+\/]{43}=)$/D', rtrim($line), $m) === 1) {
                $key = base64_decode($m[1], true);

                return is_string($key) && strlen($key) === 32 ? $key : null;
            }
        }

        return null;
    }

    /** The key of this site; a new one is made (and saved) when there is none. */
    public static function ensure(string $file = self::FILE): string
    {
        return self::load($file) ?? self::create($file);
    }

    /**
     * A new key replaces the old one (written next to it and renamed). Not yet confirmed: off-site copies wait until the
     * administrator has saved it.
     *
     * @throws \RuntimeException when storage/ is not writable
     */
    public static function create(string $file = self::FILE): string
    {
        $key = random_bytes(32);
        self::save($key, $file);

        return $key;
    }

    /**
     * Writes the key into the file: a PHP file that ends at once when someone requests it over the web (storage/ is closed
     * anyway), readable only by the site (Vault::configMode).
     *
     * @throws \RuntimeException
     */
    public static function save(string $key, string $file = self::FILE): void
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('A recovery key has 32 bytes.');
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException(t('The recovery key cannot be saved – the folder storage/ is not writable.'));
        }
        $content = "<?php exit; ?>\n# Kaleta: the key of the encrypted off-site backups (3.28). The recovery key the administrator saved is this key.\n"
            . "# Never share this file; keep it out of every copy of the site.\nkey=" . base64_encode($key) . "\n";
        $temporary = $file . '.kaleta-' . bin2hex(random_bytes(4));
        // N328-8: the file is made empty and closed to others first, the key is written only then – never readable by another
        // account of a shared hosting, not even for a moment between the write and the chmod
        $made = @touch($temporary);
        if ($made) {
            @chmod($temporary, Vault::configMode()); // a filesystem without permissions keeps what it has, as before
        }
        if (!$made || @file_put_contents($temporary, $content, LOCK_EX) === false) {
            @unlink($temporary);
            throw new \RuntimeException(t('The recovery key cannot be saved – the folder storage/ is not writable.'));
        }
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException(t('The recovery key cannot be saved – the folder storage/ is not writable.'));
        }
    }

    /** The administrator has saved the key: off-site copies start (only the id and the time are stored). */
    public static function confirm(Settings $settings, string $key): void
    {
        $settings->set('backup_key_confirmed', BackupEncryption::keyId($key) . '|' . date('Y-m-d H:i'));
    }

    /** The key as the administrator saves it: KBK1-XXXX-XXXX-… (base32, 52 characters in groups of four). */
    public static function format(string $key): string
    {
        $bits = '';
        foreach (str_split($key) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 5) * 5, '0');
        $text = '';
        foreach (str_split($bits, 5) as $group) {
            $text .= self::ALPHABET[bindec($group)];
        }

        return self::PREFIX . '-' . implode('-', str_split($text, 4));
    }

    /**
     * The key from what the administrator pasted or typed (case, spaces and dashes do not matter, the prefix may be left
     * out; the digits 0, 1 and 8, which base32 does not use, are read as O, I and B); null when it is not a recovery key.
     */
    public static function parse(string $text): ?string
    {
        $text = strtoupper((string) preg_replace('/[\s\-_.]+/u', '', $text));
        if (str_starts_with($text, self::PREFIX) && strlen($text) === 52 + strlen(self::PREFIX)) {
            $text = substr($text, strlen(self::PREFIX));
        }
        $text = strtr($text, ['0' => 'O', '1' => 'I', '8' => 'B']);
        if (strlen($text) !== 52 || strspn($text, self::ALPHABET) !== 52) {
            return null;
        }
        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        if (trim(substr($bits, 256), '0') !== '') {
            return null; // the 4 padding bits must be zero
        }
        $key = '';
        foreach (str_split(substr($bits, 0, 256), 8) as $byte) {
            $key .= chr((int) bindec($byte));
        }

        return $key;
    }

    /** What the state means, for the administrator (Settings → Backups and updates, System status) – never the key. */
    public static function describe(string $state, bool $remoteOn): string
    {
        return match (true) {
            $state === 'confirmed' => t('Off-site copies of the database are encrypted before they leave the server. Only your recovery key opens them – without it an off-site backup is useless, so keep the key somewhere safe, away from this server.'),
            !$remoteOn => t('Off-site copies of the database are encrypted with a recovery key that only you keep. You save it when you switch the copies on.'),
            default => t('Off-site copies are paused: the database is encrypted before it leaves the server, and the copies start once you have saved the recovery key. Without that key an off-site backup is useless – nobody can open it, not even the authors of Kaleta.'),
        };
    }

    /** The text file the administrator downloads (in the language of the administration). */
    public static function document(string $key, string $siteUrl): string
    {
        return implode("\n", [
            t('Kaleta – recovery key for the off-site backups of %s', $siteUrl),
            t('Created %s', date('Y-m-d H:i')),
            '',
            self::format($key),
            '',
            t('The off-site copies of the database (files ending in .sql.gz.enc on your FTP or S3 storage) are encrypted with this key. Without it they cannot be restored – nobody can open them, not even the authors of Kaleta.'),
            t('Keep this file somewhere safe, away from the site\'s server: a password manager, or a printed copy with the site\'s records. Anyone with the key and an off-site copy can read the database.'),
            '',
            t('To restore after losing the hosting: install Kaleta on the new hosting, upload the .enc backup into the backups folder storage/zalohy/, then in Settings → Backups and updates enter this key and click Restore at that backup.'),
            '',
        ]);
    }
}
