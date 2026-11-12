<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\Settings;
use Kaleta\Core\Signature;

/**
 * The site's own key pair (2.9): Ed25519 like the publisher's update signatures (Core\Signature). A site signs its
 * heartbeat and its pairing with it; a console signs its answers and commands with its own. The secret key never leaves
 * the site: it is not in the site export (an allowlist), not in MCP settings and not in System status.
 */
final class Keys
{
    private const string SETTING = 'site_key_secret';

    /** The public key of this site (base64); the pair is made the first time it is needed. */
    public static function publicKey(Settings $s): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey(self::secret($s)));
    }

    /** Signature (base64) of the message with this site's secret key. */
    public static function sign(Settings $s, string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, self::secret($s)));
    }

    /** Is the signature (base64) of the message valid for the public key (base64)? Never throws. */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        $sig = base64_decode($signature, true);
        $key = base64_decode($publicKey, true);

        return $sig !== false && $key !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    /** A short fingerprint to compare by eye (the first 8 characters, like the publisher's key ids). */
    public static function fingerprint(string $publicKey): string
    {
        $key = base64_decode($publicKey, true);

        return $key === false ? '' : Signature::id($key);
    }

    public static function isPublicKey(string $publicKey): bool
    {
        $key = base64_decode($publicKey, true);

        return $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    /** A key for this request only, while the stored one cannot be opened (3.39 review M1). */
    private static ?string $standIn = null;

    private static function secret(Settings $s): string
    {
        $secret = base64_decode($s->get(self::SETTING), true);
        if (($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) && $s->secretUnreadable(self::SETTING)) {
            // sealed with a key this config.php does not have (or damaged): never replaced – the console rejects what this
            // stand-in signs until the vault_key line is back, and then the stored key works again (3.39 review M1)
            return self::$standIn ??= sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        }
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
            $s->set(self::SETTING, base64_encode($secret));
        }

        return $secret;
    }
}
