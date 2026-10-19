<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The key that encrypts what the site keeps secret (3.15, N42): the connectors' credentials (Core\Connectors) and the
 * whistleblowing reports (Core\Whistleblowing), each under a key of its own derived from one base key with sodium.
 *
 * Until 3.15 the base was secret_key in the database, so every database dump and backup held the data and its key. A new
 * site gets a random vault_key in config.php from the installer; an existing site keeps working with secret_key until an
 * administrator moves the key (Settings → Backups and updates, move()): the key is written into config.php, every stored
 * value is encrypted again in one transaction, and the setting vault_check (a one-way check value, never the key) says the
 * data now belongs to the key in config.php. Until vault_check matches the key in config.php, secret_key stays the base –
 * a half-finished move never leaves data under a key nobody uses.
 *
 * The key is never printed: not in the administration, the logs, MCP or an export. The one exception is the line for
 * config.php, shown once to the administrator when the file cannot be written (and kept nowhere).
 */
final class Vault
{
    /** The base key from config.php (64 hex characters), or null when config.php has none. */
    private static ?string $configKey = null;

    /** The encrypted columns: table => [purpose of the key, primary key, columns]. */
    public const array SEALED = [
        'connectors' => ['connectors', 'service', ['secret', 'access_token', 'refresh_token']],
        'whistleblowing_cases' => ['whistleblowing', 'id', ['text', 'contact', 'attachments']],
        'whistleblowing_messages' => ['whistleblowing', 'id', ['text']],
    ];

    /** App reads config.php: 'vault_key' => '…' (anything else than 64 hex characters is ignored). */
    public static function configure(mixed $key): void
    {
        self::$configKey = is_string($key) && preg_match('/^[0-9a-f]{64}$/D', $key) ? $key : null;
    }

    /** A new base key for config.php. */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** The one-way check value stored in the settings (vault_check): which key the data belongs to, never the key itself. */
    public static function check(string $baseKey): string
    {
        return bin2hex(sodium_crypto_generichash('kaleta-vault-check|' . $baseKey, '', 16));
    }

    /**
     * Where the key is: config (moved, the data belongs to the key in config.php), database (secret_key, as before 3.15),
     * pending (config.php has a key, the data has not been encrypted with it yet) or missing (the data belongs to a key that
     * this config.php does not have – a database restored on another server without its config.php).
     */
    public static function state(Settings $settings): string
    {
        $check = $settings->get('vault_check');

        return match (true) {
            $check !== '' && self::$configKey !== null && hash_equals($check, self::check(self::$configKey)) => 'config',
            $check !== '' => 'missing',
            self::$configKey !== null => 'pending',
            default => 'database',
        };
    }

    /** What state() means, for the administrator (Settings → Backups and updates, Ready for 4.0) – never the key. */
    public static function describe(string $state): string
    {
        return match ($state) {
            'config' => t('Connector secrets and whistleblowing reports are encrypted with a key that is in config.php, not in the database – a stolen database or backup alone cannot be read.'),
            'pending' => t('The encryption key is already in config.php, but the stored secrets and reports are still encrypted with the old key in the database. Finish the move in Settings → Backups and updates.'),
            'missing' => t('The stored secrets and reports belong to an encryption key that this config.php does not have, so they cannot be read. Put back the line with vault_key from the config.php of the site this database comes from.'),
            default => t('Connector secrets and whistleblowing reports are encrypted with a key kept in the database, so every database backup holds both the data and its key. Move the key to config.php in Settings → Backups and updates.'),
        };
    }

    /** The key of one purpose (connectors, whistleblowing) for secretbox. */
    public static function key(Settings $settings, string $purpose): string
    {
        return self::derive(self::state($settings) === 'config' ? (string) self::$configKey : (new Antispam($settings->db(), $settings))->key(), $purpose);
    }

    /** The key of a purpose from a base key – the same derivation as before 3.15 ("kaleta-<purpose>|" + the base). */
    public static function derive(string $baseKey, string $purpose): string
    {
        return sodium_crypto_generichash('kaleta-' . $purpose . '|' . $baseKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function seal(string $key, string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    /** null when it cannot be read (another key, damaged). */
    public static function open(string $key, ?string $sealed): ?string
    {
        $raw = $sealed !== null ? base64_decode($sealed, true) : false;
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

        return $plain === false ? null : $plain;
    }

    /** One stored value encrypted again under another key; null = it stays as it is (empty, or unreadable already). */
    public static function reseal(string $from, string $to, ?string $sealed): ?string
    {
        $plain = $sealed === null || $sealed === '' ? null : self::open($from, $sealed);

        return $plain === null ? null : self::seal($to, $plain);
    }

    /** The line for config.php (inside the returned array). */
    public static function configLine(string $baseKey): string
    {
        return "'vault_key' => '" . $baseKey . "', // encrypts connector secrets and whistleblowing reports – keep it with your backups";
    }

    /**
     * config.php with the key added right after the opening of the returned array; null when the file cannot be changed
     * safely (not writable, already has a vault_key, no "return [" / "return array (" found, or the result would not parse).
     * Other lines of the file (constants, comments) stay as they are.
     */
    public static function withKey(string $config, string $baseKey): ?string
    {
        if (str_contains($config, "'vault_key'") || str_contains($config, '"vault_key"')) {
            return null;
        }
        try {
            $tokens = token_get_all($config, TOKEN_PARSE);
        } catch (\ParseError) {
            return null;
        }
        $offset = 0;
        $afterReturn = false;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            if ($afterReturn && !in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($text === '[' || $id === T_ARRAY) {
                    $at = $offset + strlen($text);
                    if ($id === T_ARRAY && !preg_match('/\G\s*\(/', $config, $m, 0, $at)) {
                        return null;
                    }
                    $at += $id === T_ARRAY ? strlen($m[0]) : 0;
                    $new = substr($config, 0, $at) . "\n    " . self::configLine($baseKey) . substr($config, $at);
                    try {
                        token_get_all($new, TOKEN_PARSE);
                    } catch (\ParseError) {
                        return null;
                    }

                    return $new;
                }

                return null;
            }
            if ($id === T_RETURN) {
                $afterReturn = true;
            }
            $offset += strlen($text);
        }

        return null;
    }

    /** Writes the key into config.php (written next to it and renamed); false when it cannot. */
    public static function writeConfig(string $baseKey, string $file = KALETA_ROOT . '/config.php'): bool
    {
        if (!is_file($file) || !is_writable($file) || ($new = self::withKey((string) file_get_contents($file), $baseKey)) === null) {
            return false;
        }
        $temporary = $file . '.kaleta-' . bin2hex(random_bytes(4));
        $mode = fileperms($file) & 0777;
        if (@file_put_contents($temporary, $new, LOCK_EX) === false || !@chmod($temporary, $mode) || !@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        return true;
    }

    /**
     * Moves the key out of the database (an administrator's action): the key goes into config.php – or, when the file
     * cannot be written, the line to add comes back and nothing changes – then every stored value is encrypted again
     * under it in one transaction with vault_check; any failure rolls all of it back and secret_key stays the key.
     *
     * @return array{status: 'moved'|'line'|'already'|'missing'|'error', line?: string, count?: int, unreadable?: int, error?: string}
     */
    public static function move(App $app, string $file = KALETA_ROOT . '/config.php'): array
    {
        $settings = $app->settings();
        $state = self::state($settings);
        if ($state === 'config' || $state === 'missing') {
            return ['status' => $state === 'config' ? 'already' : 'missing'];
        }
        if (self::$configKey === null) {
            $key = self::newKey();
            if (!self::writeConfig($key, $file)) {
                return ['status' => 'line', 'line' => self::configLine($key)];
            }
            self::$configKey = $key;
        }
        $from = (new Antispam($settings->db(), $settings))->key();
        $to = (string) self::$configKey;
        try {
            [$count, $unreadable] = $app->db()->transaction(function (Db $db) use ($settings, $from, $to): array {
                $count = 0;
                $unreadable = 0;
                foreach (self::SEALED as $table => [$purpose, $primary, $columns]) {
                    [$fromKey, $toKey] = [self::derive($from, $purpose), self::derive($to, $purpose)];
                    foreach ($db->all('SELECT ' . $primary . ', ' . implode(', ', $columns) . ' FROM {' . $table . '}') as $row) {
                        $changes = [];
                        foreach ($columns as $column) {
                            $new = self::reseal($fromKey, $toKey, $row[$column] ?? null);
                            if ($new !== null) {
                                $changes[$column] = $new;
                            } elseif (($row[$column] ?? '') !== '' && $row[$column] !== null) {
                                $unreadable++; // already unreadable (another site's key, damaged): it stays as it was
                            }
                        }
                        if ($changes !== []) {
                            $db->update($table, $changes, [$primary => $row[$primary]]);
                            $count += count($changes);
                        }
                    }
                }
                $settings->set('vault_check', self::check($to));

                return [$count, $unreadable];
            });
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
        \Kaleta\Admin\ChangeLog::write($app, 'settings', 'encryption key moved', 'values encrypted again: ' . $count . ($unreadable > 0 ? ', unreadable before and left as they were: ' . $unreadable : ''));

        return ['status' => 'moved', 'count' => $count, 'unreadable' => $unreadable];
    }
}
