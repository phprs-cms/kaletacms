<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Which ways of signing in an account has (3.35) – one place for the rules, pure functions over the account row, its
 * passkeys and the site settings, so the sign-in, My account, Users and the tests cannot drift apart.
 *
 *  - No passkey: the password, and the code from the app (or a backup code) when two-factor sign-in is on – as before.
 *  - A passkey: the passkey signs in alone (sign-in without a password; the device verified the user). With the password
 *    a second step is ALWAYS needed: the code from the app, the passkey, or a backup code – a password alone is never enough.
 *  - Passkey only (the account's choice, or the site's setting signin_passkey_only): applies only once the account has a
 *    working passkey (a discoverable one, registered since 3.35 with user verification). The password then never signs in
 *    with the code from the app: after it only the passkey or a backup code (recovery) completes the sign-in. The
 *    password can still be set and reset.
 *  - Recovery: a backup code after the password, or an administrator resets the passkeys and the second factor (Users,
 *    with their own password). A password-reset e-mail changes only the password – the steps above stay.
 *  - The escape hatch: define('KALETA_PASSKEY_ONLY_OFF', true); in config.php switches every "passkey only" off (the
 *    account's and the site's) for a site whose administrators cannot sign in – the password and the second step work again.
 */
final class SignInPolicy
{
    /** The config.php constant that switches "passkey only" off everywhere (a locked-out site). */
    public const string ESCAPE_HATCH = 'KALETA_PASSKEY_ONLY_OFF';

    /** How long a passkey or backup-code step counts as a fresh confirmation for passkey changes (seconds). */
    public const int FRESH_SECONDS = 600;

    public static function escapeHatch(): bool
    {
        return defined(self::ESCAPE_HATCH) && (bool) constant(self::ESCAPE_HATCH);
    }

    /**
     * A working passkey: one that signs in without a password – discoverable and registered with user verification
     * (3.35). A passkey registered as the second step before 3.35 works only as the second step.
     *
     * @param list<array<string, mixed>> $keys rows of ka_uzivatele_klice
     */
    public static function hasWorkingPasskey(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!empty($key['discoverable'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A working passkey that has signed in at least once (3.35) – proof that passkeys work on this host for the account;
     * an administrator needs one before requiring passkeys of the whole site.
     *
     * @param list<array<string, mixed>> $keys
     */
    public static function hasUsedWorkingPasskey(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!empty($key['discoverable']) && ($key['pouzito'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the password is no longer a way in with the code from the app: the account chose "passkey only", or the site
     * requires it – in both cases only once the account has a working passkey (the safe rollout), and never while the
     * escape hatch is defined.
     *
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $keys
     */
    public static function passkeyOnly(array $user, array $keys, Settings $settings): bool
    {
        return !self::escapeHatch() && self::hasWorkingPasskey($keys)
            && (!empty($user['passkey_only']) || $settings->get('signin_passkey_only') === '1');
    }

    /**
     * Whether the password needs a second step: two-factor sign-in is on, or the account has any passkey.
     *
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $keys
     */
    public static function needsSecondStep(array $user, array $keys): bool
    {
        return (string) ($user['totp_tajemstvi'] ?? '') !== '' || $keys !== [];
    }

    /**
     * The second steps that complete a sign-in after the right password.
     *
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $keys
     * @return array{code: bool, backup: bool, passkey: bool} code = the code from the app
     */
    public static function secondSteps(array $user, array $keys, Settings $settings): array
    {
        return [
            'code' => (string) ($user['totp_tajemstvi'] ?? '') !== '' && !self::passkeyOnly($user, $keys, $settings),
            'backup' => self::backupCodesLeft($user) > 0,
            'passkey' => $keys !== [],
        ];
    }

    /** @param array<string, mixed> $user */
    public static function backupCodesLeft(array $user): int
    {
        $codes = json_decode((string) ($user['totp_zalozni'] ?? ''), true);

        return is_array($codes) ? count($codes) : 0;
    }

    /**
     * The account's state in one word, for the change log, the tests and the documentation table: password (no second
     * step), two_factor (password + code), passkey (a passkey; the password needs a second step), passkey_only.
     *
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $keys
     */
    public static function state(array $user, array $keys, Settings $settings): string
    {
        return match (true) {
            self::passkeyOnly($user, $keys, $settings) => 'passkey_only',
            $keys !== [] => 'passkey',
            (string) ($user['totp_tajemstvi'] ?? '') !== '' => 'two_factor',
            default => 'password',
        };
    }

    /** Whether a passkey or backup-code step at $at (Unix time, 0 = none) is still a fresh confirmation at $now. */
    public static function isFresh(int $at, int $now): bool
    {
        return $at > 0 && $now - $at >= 0 && $now - $at < self::FRESH_SECONDS;
    }

    /**
     * Whether the host the request came to may use passkeys at all: WebAuthn binds them to the site's canonical host (the
     * host of the site URL in Settings). Another host name of the same site (an alias domain, www vs. no www, the IP)
     * cannot, nor can a page without HTTPS other than localhost – the browser refuses there.
     */
    public static function hostMatches(string $requestHost, string $siteUrl): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', trim($requestHost)));

        return $host !== '' && $host === Passkey::rpId($siteUrl);
    }
}
