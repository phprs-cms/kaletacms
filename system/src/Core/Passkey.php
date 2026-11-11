<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Login keys (passkeys, the WebAuthn standard): fingerprint, Face ID, Windows Hello or a security key
 * instead of copying a code from an app. Pure PHP, no libraries - the openssl extension verifies the signature.
 *
 * How it works: on registration the device creates a key pair bound to the site's domain. The private key never leaves
 * the device, it sends only the public one to the server. On login the server sends a random challenge, the device signs
 * it (after the fingerprint) and the server verifies the signature with the stored public key. A forged page on another
 * domain does not get the signature.
 *
 * What is verified (WebAuthn Level 2, sections 7.1 and 7.2) and why:
 *  - the response type (webauthn.create / webauthn.get) - a registration response cannot be used to log in and vice versa,
 *  - the challenge - the response belongs to THIS attempt (against replaying an old response),
 *  - the origin and the domain hash (rpIdHash) - the response was created on our site, not on a forged page,
 *  - the user presence flag (UP) - someone really touched the device,
 *  - the user verification flag (UV) where the caller requires it (3.35: registration and the sign-in without a password) -
 *    the device checked the fingerprint, face or PIN itself, so the passkey alone is two factors,
 *  - the signature over the authenticator data and the hash of the client data,
 *  - the signature counter - if the device keeps one, it must grow (reveals a copied key).
 *
 * Attestation (proof of the device manufacturer) is neither required nor verified ("none"): a CMS does not need to know
 * what device the user has and does not want to find out anything about it. So the browser supplies the public key in
 * SPKI form (getPublicKey()); for ES256 keys it is also checked that the same key is in the authenticator data.
 *
 * The class stores nothing and touches neither the session nor the database - it only computes. The caller takes care
 * of challenges, accounts and stored keys.
 */
final class Passkey
{
    /** Supported signature algorithms (COSE numbers): ES256 = ECDSA P-256 + SHA-256, RS256 = RSA PKCS#1 v1.5 + SHA-256. */
    public const array ALGORITHMS = [-7, -257];

    private const int FLAG_UP = 0x01; // the user was present
    private const int FLAG_UV = 0x04; // the device verified the user (fingerprint, face, PIN)
    private const int FLAG_AT = 0x40; // the data contains a newly created key (only on registration)

    /** Random challenge for one attempt (base64url). The caller stores it in the session and discards it after use. */
    public static function challenge(): string
    {
        return self::b64(random_bytes(32));
    }

    /**
     * A new opaque user handle for an account (3.35): 16 random bytes, base64url. The device stores it with a discoverable
     * passkey and returns it at the sign-in without a password; it says nothing about the account (not its number, name
     * or e-mail), so a device or a list of passkeys does not give the account away.
     */
    public static function newUserHandle(): string
    {
        return self::b64(random_bytes(16));
    }

    /** The domain the keys are bound to (rpId), from the site URL: https://www.web.cz:8443/x -> www.web.cz */
    public static function rpId(string $siteUrl): string
    {
        return rtrim(strtolower((string) parse_url($siteUrl, PHP_URL_HOST)), '.'); // 3.35 review L6: "example.com." is example.com
    }

    /**
     * The origin the browser states in the response (scheme://host[:port]), from the site URL. 3.35 review L6: a default
     * port (https :443, http :80) and a trailing dot of the host are left out – the browser never states them.
     */
    public static function origin(string $siteUrl): string
    {
        $c = parse_url($siteUrl);
        $scheme = strtolower($c['scheme'] ?? 'https');
        $port = isset($c['port']) && !(($scheme === 'https' && (int) $c['port'] === 443) || ($scheme === 'http' && (int) $c['port'] === 80)) ? ':' . $c['port'] : '';

        return $scheme . '://' . self::rpId($siteUrl) . $port;
    }

    /**
     * Options for navigator.credentials.create(). Since 3.35 every new passkey is discoverable (residentKey required) and
     * verifies the user (userVerification required): the same passkey then signs in without a user name and a password,
     * and still serves as the second step after a password. $userId is the account's opaque handle (never its number or
     * e-mail) - the device gives it back at the sign-in without a password.
     *
     * @param list<string> $existing ids of the account's already registered keys (base64url) - a device is not registered twice
     * @return array<string, mixed>
     */
    public static function registrationOptions(string $challenge, string $rpId, string $siteName, string $userId, string $login, string $displayName, array $existing): array
    {
        return [
            'challenge' => $challenge,
            'rp' => ['id' => $rpId, 'name' => $siteName],
            'user' => ['id' => $userId, 'name' => $login, 'displayName' => $displayName !== '' ? $displayName : $login],
            'pubKeyCredParams' => array_map(static fn (int $alg): array => ['type' => 'public-key', 'alg' => $alg], self::ALGORITHMS),
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => $id], $existing),
        ];
    }

    /**
     * Options for navigator.credentials.get(). The second step names the account's keys and prefers user verification
     * (the password was the first factor); the sign-in without a password (3.35) names none - the device offers its
     * passkeys for this domain - and requires it.
     *
     * @param list<string> $allowed ids of the account's keys (base64url); [] = any discoverable passkey of the site
     * @return array<string, mixed>
     */
    public static function signInOptions(string $challenge, string $rpId, array $allowed, bool $requireVerification = false): array
    {
        return [
            'challenge' => $challenge,
            'rpId' => $rpId,
            'timeout' => 120000,
            'userVerification' => $requireVerification ? 'required' : 'preferred',
            'allowCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => $id], $allowed),
        ];
    }

    /**
     * Verifies a registration response and returns what should be stored.
     *
     * @param array<string, mixed> $response clientDataJSON, authenticatorData, publicKey (SPKI DER) - all base64url; publicKeyAlgorithm
     * @return array{id:string, klic:string, alg:int, pocitadlo:int} key id (base64url), public key (PEM), algorithm, counter
     * @throws \RuntimeException with the reason for rejection
     */
    public static function verifyRegistration(array $response, string $challenge, string $origin, string $rpId, bool $requireVerification = false): array
    {
        self::verifyClient((string) ($response['clientDataJSON'] ?? ''), 'webauthn.create', $challenge, $origin);
        $data = self::fromB64((string) ($response['authenticatorData'] ?? ''));
        $flags = self::verifyData($data, $rpId, $requireVerification);
        if (($flags & self::FLAG_AT) === 0 || strlen($data) < 55) {
            throw new \RuntimeException('The response does not contain a new key.');
        }
        $idLength = (ord($data[53]) << 8) | ord($data[54]);
        if ($idLength < 1 || $idLength > 1023 || strlen($data) < 55 + $idLength + 1) {
            throw new \RuntimeException('The key identifier has an invalid length.');
        }
        $id = substr($data, 55, $idLength);
        $rest = substr($data, 55 + $idLength); // the public key as the authenticator wrote it (COSE)

        $alg = (int) ($response['publicKeyAlgorithm'] ?? 0);
        if (!in_array($alg, self::ALGORITHMS, true)) {
            throw new \RuntimeException('The device offered an algorithm the system does not support.');
        }
        $der = self::fromB64((string) ($response['publicKey'] ?? ''));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = $der === '' ? false : openssl_pkey_get_public($pem);
        $description = $key === false ? false : openssl_pkey_get_details($key);
        if ($description === false) {
            throw new \RuntimeException('The device\'s public key could not be read.');
        }
        if ($alg === -7) {
            // ES256: curve P-256 and the same point (x, y) must also be in the data signed by the authenticator
            $ec = $description['ec'] ?? null;
            if ($description['type'] !== OPENSSL_KEYTYPE_EC || !is_array($ec) || ($ec['curve_name'] ?? '') !== 'prime256v1'
                || !str_contains($rest, str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT)) || !str_contains($rest, str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT))) {
                throw new \RuntimeException('The public key does not match the data from the device.');
            }
        } elseif ($description['type'] !== OPENSSL_KEYTYPE_RSA || $description['bits'] < 2048 || !str_contains($rest, (string) $description['rsa']['n'])) {
            throw new \RuntimeException('The public key does not match the data from the device.');
        }

        return ['id' => self::b64($id), 'klic' => $pem, 'alg' => $alg, 'pocitadlo' => self::counter($data)];
    }

    /**
     * Verifies a login response. Returns the new signature counter to store. $requireVerification (3.35, the sign-in
     * without a password): the UV flag must be set, otherwise the response is refused.
     *
     * @param array<string, mixed> $response clientDataJSON, authenticatorData, signature - all base64url
     * @throws \RuntimeException with the reason for rejection
     */
    public static function verifySignIn(array $response, string $challenge, string $origin, string $rpId, string $publicKeyPem, int $storedCounter, bool $requireVerification = false): int
    {
        $client = self::verifyClient((string) ($response['clientDataJSON'] ?? ''), 'webauthn.get', $challenge, $origin);
        $data = self::fromB64((string) ($response['authenticatorData'] ?? ''));
        self::verifyData($data, $rpId, $requireVerification);

        $signature = self::fromB64((string) ($response['signature'] ?? ''));
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false || $signature === '' || openssl_verify($data . hash('sha256', $client, true), $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('The signature from the device is not valid.');
        }
        // devices that keep a counter must increase it; the same or a lower value = someone copied the key.
        // Synced keys (iCloud, Google) always send zero - for them the check has nothing to compare.
        $counter = self::counter($data);
        if (($counter !== 0 || $storedCounter !== 0) && $counter <= $storedCounter) {
            throw new \RuntimeException('The key counter went backwards – the key may have been copied. Remove it and register it again.');
        }

        return $counter;
    }

    /** Client data: operation type, challenge and origin. Returns the raw JSON (its hash is part of the signed data). */
    private static function verifyClient(string $b64, string $type, string $challenge, string $origin): string
    {
        $json = self::fromB64($b64);
        $client = json_decode($json, true);
        if (!is_array($client) || ($client['type'] ?? '') !== $type) {
            throw new \RuntimeException('The response from the device has an unexpected type.');
        }
        if ($challenge === '' || !is_string($client['challenge'] ?? null) || !hash_equals($challenge, rtrim($client['challenge'], '='))) {
            throw new \RuntimeException('The response does not belong to this attempt. Please try again.');
        }
        if (!is_string($client['origin'] ?? null) || !hash_equals($origin, strtolower($client['origin']))) {
            throw new \RuntimeException('The response was created at a different address than the site address in Settings.');
        }
        if (!empty($client['crossOrigin'])) {
            throw new \RuntimeException('The response was created in an embedded frame of another site.');
        }

        return $json;
    }

    /** Authenticator data: domain hash, the user presence flag and, when required, the user verification flag. Returns the flags byte. */
    private static function verifyData(string $data, string $rpId, bool $requireVerification = false): int
    {
        if (strlen($data) < 37) {
            throw new \RuntimeException('The data from the device is incomplete.');
        }
        if ($rpId === '' || !hash_equals(hash('sha256', $rpId, true), substr($data, 0, 32))) {
            throw new \RuntimeException('The key belongs to a different domain.');
        }
        $flags = ord($data[32]);
        if (($flags & self::FLAG_UP) === 0) {
            throw new \RuntimeException('The device did not confirm that a user was present.');
        }
        if ($requireVerification && ($flags & self::FLAG_UV) === 0) {
            throw new \RuntimeException('The device did not verify you (fingerprint, face or PIN). Set up a screen lock on the device and try again.');
        }

        return $flags;
    }

    private static function counter(string $data): int
    {
        return (int) (unpack('N', substr($data, 33, 4))[1] ?? 0);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function fromB64(string $text): string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $text) !== 1) {
            return '';
        }

        return (string) base64_decode(strtr(rtrim($text, '='), '-_', '+/'), true);
    }
}
