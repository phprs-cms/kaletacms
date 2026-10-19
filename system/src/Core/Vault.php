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
     * config.php with the key added right after the opening of the array the file returns; null when the file cannot be
     * changed safely: it already has a vault_key, it does not parse, or which array it returns is not certain (N315-2) –
     * exactly one return at the top level of the file (not inside an if, a loop, a closure or a function), as its own
     * statement, directly with "[" or "array (", and no other return outside a function. Other lines stay as they are.
     */
    public static function withKey(string $config, string $baseKey): ?string
    {
        if (str_contains($config, "'vault_key'") || str_contains($config, '"vault_key"')) {
            return null;
        }
        $at = self::returnedArray($config);
        if ($at === null) {
            return null;
        }
        // the line ends with a comment, so whatever followed the bracket on its line goes to the next one
        $new = substr($config, 0, $at) . "\n    " . self::configLine($baseKey) . (preg_match('/\G[ \t]*\r?\n/', $config, $m, 0, $at) ? '' : "\n") . substr($config, $at);
        // the same parser on the result: it must parse and return the key from the same place
        $check = self::returnedArray($new);
        if ($check !== $at || !str_starts_with(substr($new, $at), "\n    " . self::configLine($baseKey))) {
            return null;
        }

        return $new;
    }

    /**
     * The offset right after the "[" or "array (" of the one array config.php returns at its top level, or null when the
     * file does not parse or the returned array is ambiguous (more returns, a conditional return, a return of anything else).
     */
    private static function returnedArray(string $config): ?int
    {
        try {
            $tokens = token_get_all($config, TOKEN_PARSE);
        } catch (\ParseError) {
            return null;
        }
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $stack = []; // the open brackets: 'f' = the body of a function or closure, 'b' = anything else
        $functionPending = false; // a "function" keyword whose body has not opened yet
        $previous = null; // the previous significant token (text)
        $found = null;
        $offset = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;
            $start = $offset;
            $offset += strlen($text);
            if (in_array($id, $skip, true)) {
                continue;
            }
            if ($id === T_FUNCTION) {
                $functionPending = true;
            } elseif ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $stack[] = $functionPending && $text === '{' ? 'f' : 'b';
                $functionPending = false;
            } elseif ($text === '(' || $text === '[' || $id === T_ATTRIBUTE) {
                $stack[] = 'b';
            } elseif ($text === '}' || $text === ')' || $text === ']') {
                array_pop($stack);
            } elseif ($text === ';') {
                $functionPending = false; // an abstract or interface method has no body
            } elseif ($id === T_RETURN && !in_array('f', $stack, true)) {
                // a return outside any function: only one, at the top level, as a statement of its own
                if ($found !== null || $stack !== [] || !in_array($previous, [null, ';', '}', '<?php'], true)) {
                    return null;
                }
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) {
                    $j++;
                }
                $next = $tokens[$j] ?? null;
                if ($next === '[') {
                    $found = [$j, 1];
                } elseif (is_array($next) && $next[0] === T_ARRAY) {
                    $k = $j + 1;
                    while ($k < $count && is_array($tokens[$k]) && in_array($tokens[$k][0], $skip, true)) {
                        $k++;
                    }
                    if (($tokens[$k] ?? null) !== '(') {
                        return null;
                    }
                    $found = [$k, 1];
                } else {
                    return null;
                }
            }
            $previous = is_array($token) ? trim($text) : $text;
        }
        if ($found === null || $stack !== []) {
            return null;
        }
        // the byte offset right after the opening bracket
        $offset = 0;
        for ($i = 0; $i <= $found[0]; $i++) {
            $offset += strlen(is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]);
        }

        return $offset;
    }

    /**
     * What config.php returns when loaded in a scope of its own (N315-2): the read-back of a written key. Notices and warnings
     * of the second load (a constant defined again) are ignored; anything else than an array gives [].
     *
     * @return array<mixed>
     */
    public static function loadConfig(string $file): array
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        set_error_handler(static fn (): bool => true);
        try {
            $config = (static fn (string $path): mixed => include $path)($file);
        } catch (\Throwable) {
            $config = null;
        } finally {
            restore_error_handler();
        }

        return is_array($config) ? $config : [];
    }

    /**
     * Writes the key into config.php (written next to it and renamed), then reads the file back in a scope of its own: only
     * when it now returns exactly this key is the move allowed to go on (N315-2). Otherwise the previous content is put back
     * and false comes back – nothing has changed, the administrator gets the line to add by hand.
     */
    public static function writeConfig(string $baseKey, string $file = KALETA_ROOT . '/config.php'): bool
    {
        if (!is_file($file) || !is_writable($file) || ($old = file_get_contents($file)) === false || ($new = self::withKey($old, $baseKey)) === null) {
            return false;
        }
        if (!self::replaceFile($file, $new)) {
            return false;
        }
        if (!self::yieldsKey($file, $baseKey)) {
            self::replaceFile($file, $old);

            return false;
        }

        return true;
    }

    /**
     * Does config.php give exactly this key (N315-2)? The same parser finds the line in the one returned array, then the file
     * is loaded in a scope of its own and must return it – unless it declares functions or classes, which a second load
     * would declare again (a fatal error): then the parser's answer stands.
     */
    public static function yieldsKey(string $file, string $baseKey): bool
    {
        $content = (string) @file_get_contents($file);
        $at = self::returnedArray($content);
        if ($at === null || !str_starts_with(substr($content, $at), "\n    " . self::configLine($baseKey))) {
            return false;
        }
        if (self::declaresSymbols($content)) {
            return true;
        }

        return (self::loadConfig($file)['vault_key'] ?? null) === $baseKey;
    }

    /** Does the PHP source declare a named function, class, interface, trait or enum? */
    private static function declaresSymbols(string $source): bool
    {
        $tokens = array_values(array_filter(token_get_all($source), fn (mixed $t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && !(is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_NEW)
                && !(is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON)) {
                return true;
            }
            if ($token[0] === T_FUNCTION) {
                $next = $tokens[$i + 1] ?? null;
                $next = $next === '&' ? ($tokens[$i + 2] ?? null) : $next;
                if (is_array($next) && $next[0] === T_STRING) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The content of a file replaced in one step (a temporary file with the same rights, renamed). */
    private static function replaceFile(string $file, string $content): bool
    {
        $temporary = $file . '.kaleta-' . bin2hex(random_bytes(4));
        $mode = fileperms($file) & 0777;
        if (@file_put_contents($temporary, $content, LOCK_EX) === false || !@chmod($temporary, $mode) || !@rename($temporary, $file)) {
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
     * cannot be written or does not return the key when read back (writeConfig), the line to add comes back and nothing
     * changes – then every stored value is encrypted again under it in one transaction with vault_check; any failure rolls
     * all of it back and secret_key stays the key.
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
                    // locked until the commit (N315-6): a token refresh that writes meanwhile waits and does not overwrite
                    foreach ($db->all('SELECT ' . $primary . ', ' . implode(', ', $columns) . ' FROM {' . $table . '} FOR UPDATE') as $row) {
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
