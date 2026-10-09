<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Verification of publisher signatures (Ed25519). The file system/aktualizace.pub may hold SEVERAL public keys - one per line
 * (base64, an optional description after a space, lines with # are comments). A signature is valid when it matches any of them.
 *
 * Why several keys: besides the operational key there is a backup key that is kept offline and not used. If the operational
 * key is lost, it signs a release with a new operational key; if it leaks, a release that removes the compromised key from the file.
 * The procedure is in docs/RELEASING.md.
 */
final class Signature
{
    /** @return array<string, string> key identifier => public key (binary) */
    public static function keys(string $file): array
    {
        $keys = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $row) {
            $row = trim($row);
            if ($row === '' || $row[0] === '#') {
                continue;
            }
            $key = base64_decode((string) strtok($row, " \t"), true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[self::id($key)] = $key;
            }
        }

        return $keys;
    }

    /** Short key identifier (the first 8 characters of the fingerprint) - for the manifest and the docs, so it is clear what signed it. */
    public static function id(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 8);
    }

    /**
     * Is the signature (base64) of the message valid against any of the keys in the file? With $keyId only the key with
     * that identifier counts (manifest signature v2 names its key in the signed field "klic").
     */
    public static function isValid(string $message, string $signatureBase64, string $file, ?string $keyId = null): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        foreach (self::keys($file) as $key) {
            if (($keyId === null || self::id($key) === $keyId) && sodium_crypto_sign_verify_detached($signature, $message, $key)) {
                return true;
            }
        }

        return false;
    }

    /** What exactly is signed for a package: the version, the ZIP fingerprint and the security-release flag (that one installs itself). */
    public static function packageMessage(string $version, string $sha256, bool $securityRelease): string
    {
        return $version . '|' . strtolower($sha256) . '|' . ($securityRelease ? 'bezpecnostni' : 'bezne');
    }

    /**
     * Manifest signature v2 (3.9, audit findings N38-3 and N37-4): the fields of the update manifest a site acts on, in
     * this order. The v1 signature (packageMessage, field "podpis") covers only the version, the package hash and the
     * security flag; v2 (field "podpis2") covers all of these. Fields not listed here are not signed and no site acts on them.
     */
    public const array MANIFEST_FIELDS = ['verze', 'vydano', 'url', 'sha256', 'min_php', 'bezpecnostni', 'kanal', 'klic', 'zmeny'];

    /**
     * The exact bytes signature v2 covers: the header "kaleta-manifest-v2\n", then one line per field in MANIFEST_FIELDS
     * order, "<name>=<byte length>:<value>\n". Each value is the one the site reads: a missing string field is "", the
     * security flag "true" or "false" (missing = false), the package hash in lower case, the list of changes the
     * concatenation of "<byte length>:<text>" per change (missing = none). The lengths make the encoding unambiguous –
     * no value can end early or run into the next one, whatever characters it holds.
     *
     * Null = a field has a type no site reads (a number for the version, an object for the URL, a string for the flag):
     * such a manifest has no valid v2 signature.
     *
     * @param array<array-key, mixed> $manifest
     */
    public static function manifestMessage(array $manifest): ?string
    {
        $message = "kaleta-manifest-v2\n";
        foreach (self::MANIFEST_FIELDS as $field) {
            $value = $manifest[$field] ?? null;
            if ($field === 'bezpecnostni') {
                if ($value !== null && !is_bool($value)) {
                    return null;
                }
                $value = $value === true ? 'true' : 'false';
            } elseif ($field === 'zmeny') {
                if ($value !== null && (!is_array($value) || !array_is_list($value))) {
                    return null;
                }
                $items = '';
                foreach ($value ?? [] as $item) {
                    if (!is_string($item)) {
                        return null;
                    }
                    $items .= strlen($item) . ':' . $item;
                }
                $value = $items;
            } elseif ($value === null) {
                $value = '';
            } elseif (!is_string($value)) {
                return null;
            } elseif ($field === 'sha256') {
                $value = strtolower($value);
            }
            $message .= $field . '=' . strlen($value) . ':' . $value . "\n";
        }

        return $message;
    }

    /**
     * Does the manifest carry a valid signature v2 ("podpis2")? Only the key named in its signed field "klic" counts; a
     * manifest without "klic" may be signed by any key in the file.
     *
     * @param array<array-key, mixed> $manifest
     */
    public static function manifestValid(array $manifest, string $file): bool
    {
        $message = self::manifestMessage($manifest);
        $signature = $manifest['podpis2'] ?? null;
        $keyId = $manifest['klic'] ?? null;

        return $message !== null && is_string($signature) && self::isValid($message, $signature, $file, is_string($keyId) && $keyId !== '' ? $keyId : null);
    }

    /**
     * Signs a manifest as tools/release.php publishes it: "klic" = the identifier of the key, "podpis" = signature v1 exactly
     * as before 3.9 (every installed version up to 3.8 checks only that one) and "podpis2" = signature v2 over every field
     * in MANIFEST_FIELDS. Used by tools/release.php and by the tests (with throwaway keys).
     *
     * @param array<string, mixed> $manifest verze, sha256 and the other fields filled in
     * @param non-empty-string $secretKey
     * @return array<string, mixed>
     */
    public static function signManifest(array $manifest, string $secretKey): array
    {
        $version = $manifest['verze'] ?? null;
        $sha256 = $manifest['sha256'] ?? null;
        if (!is_string($version) || !is_string($sha256)) {
            throw new \InvalidArgumentException('The manifest needs "verze" and "sha256".');
        }
        $manifest['klic'] = self::id(sodium_crypto_sign_publickey_from_secretkey($secretKey));
        $manifest['podpis'] = base64_encode(sodium_crypto_sign_detached(self::packageMessage($version, $sha256, !empty($manifest['bezpecnostni'])), $secretKey));
        $message = self::manifestMessage($manifest) ?? throw new \InvalidArgumentException('A manifest field has a type the sites do not read.');
        $manifest['podpis2'] = base64_encode(sodium_crypto_sign_detached($message, $secretKey));

        return $manifest;
    }
}
