<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * One-time backup codes of an account (ka_uzivatele.totp_zalozni) – the step after the password when the phone or the
 * passkey is not at hand. 3.35 review L4: a new list has codes of 64 random bits (xxxx-xxxx-xxxx-xxxx) stored as
 * HMAC-SHA256 under a random salt of the list – {"v":2,"salt":"…","codes":["…"]}; a code is typed with or without the
 * dashes and spaces, in any letter case. A list made before (a JSON list of sha256 hashes of the 10-character codes) keeps
 * verifying exactly as before until it is replaced. Pure – the compare-and-swap of the stored list is in Core\Auth.
 */
final class BackupCodes
{
    /** How many codes a new list has. */
    public const int COUNT = 8;

    /**
     * A new list: the codes to show once, and what is stored.
     *
     * @return array{0: list<string>, 1: string}
     */
    public static function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = implode('-', str_split(bin2hex(random_bytes(8)), 4));
        }
        $salt = bin2hex(random_bytes(16));

        return [$codes, (string) json_encode(['v' => 2, 'salt' => $salt, 'codes' => array_map(static fn (string $c): string => self::hash($c, $salt), $codes)])];
    }

    /** How many codes the stored list has left. */
    public static function count(?string $stored): int
    {
        $list = json_decode((string) $stored, true);
        if (!is_array($list)) {
            return 0;
        }

        return array_is_list($list) ? count($list) : (is_array($list['codes'] ?? null) ? count($list['codes']) : 0);
    }

    /**
     * Uses a code up: the stored list without it, or null when the code is not on the list. The older format compares
     * the code as it always did (sha256 of the code in lower case, without surrounding spaces).
     */
    public static function consume(?string $stored, string $code): ?string
    {
        $list = json_decode((string) $stored, true);
        if (!is_array($list)) {
            return null;
        }
        if (array_is_list($list)) {
            $needle = hash('sha256', strtolower(trim($code)));

            return in_array($needle, $list, true) ? (string) json_encode(array_values(array_diff($list, [$needle]))) : null;
        }
        $salt = is_string($list['salt'] ?? null) ? $list['salt'] : '';
        $hashes = is_array($list['codes'] ?? null) ? $list['codes'] : [];
        if ($salt === '' || self::normalise($code) === '') {
            return null;
        }
        $needle = self::hash($code, $salt);
        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && hash_equals($hash, $needle)) {
                unset($hashes[$i]);

                return (string) json_encode(['v' => 2, 'salt' => $salt, 'codes' => array_values($hashes)]);
            }
        }

        return null;
    }

    /** The code as it is compared: letters and digits only, lower case. Pure. */
    public static function normalise(string $code): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $code));
    }

    private static function hash(string $code, string $salt): string
    {
        return hash_hmac('sha256', self::normalise($code), $salt);
    }
}
