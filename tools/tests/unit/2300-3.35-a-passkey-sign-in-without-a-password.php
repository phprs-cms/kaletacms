<?php

declare(strict_types=1);

use Kaleta\Core\Passkey as P335;
use Kaleta\Core\SignInPolicy as SP335;

/* ---------- 3.35 A: sign-in with a passkey alone (constructed WebAuthn responses, ES256 and RS256; the sign-in rules) ---------- */
$p335Rp = 'admin.example'; $p335Origin = 'https://admin.example';
$p335Client = static fn (string $type, string $challenge, string $origin = 'https://admin.example'): string
    => P335::b64((string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES));
$p335Rejects = static function (callable $f): string { try { $f(); return 'accepted'; } catch (RuntimeException $e) { return $e->getMessage(); } };
// a software authenticator: one ES256 (P-256) and one RS256 (RSA 2048) key, their COSE form and their SPKI DER
$p335Ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$p335EcInfo = openssl_pkey_get_details($p335Ec);
$p335EcCose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . str_pad($p335EcInfo['ec']['x'], 32, "\0", STR_PAD_LEFT) . "\x22\x58\x20" . str_pad($p335EcInfo['ec']['y'], 32, "\0", STR_PAD_LEFT);
$p335Rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$p335RsaInfo = openssl_pkey_get_details($p335Rsa);
$p335RsaCose = "\xA4\x01\x03\x03\x39\x01\x00\x20\x59\x01\x00" . $p335RsaInfo['rsa']['n'] . "\x21\x43" . str_pad($p335RsaInfo['rsa']['e'], 3, "\0", STR_PAD_LEFT);
$p335Der = static fn (array $info): string => base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $info['key']));
$p335Register = static function (int $flags, string $cose, array $info, int $alg, string $challenge) use ($p335Rp, $p335Client, $p335Der): array {
    $id = random_bytes(16);
    $data = hash('sha256', $p335Rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($id)) . $id . $cose;

    return ['clientDataJSON' => $p335Client('webauthn.create', $challenge), 'authenticatorData' => P335::b64($data), 'publicKey' => P335::b64($p335Der($info)), 'publicKeyAlgorithm' => $alg];
};
$p335Assert = static function ($key, string $challenge, int $flags, int $counter = 0, string $rp = 'admin.example', string $origin = 'https://admin.example', string $type = 'webauthn.get') use ($p335Client): array {
    $data = hash('sha256', $rp, true) . chr($flags) . pack('N', $counter);
    $client = $p335Client($type, $challenge, $origin);
    openssl_sign($data . hash('sha256', P335::fromB64($client), true), $signature, $key, OPENSSL_ALGO_SHA256);

    return ['clientDataJSON' => $client, 'authenticatorData' => P335::b64($data), 'signature' => P335::b64($signature)];
};
$p335C = P335::challenge();
$p335EcSaved = P335::verifyRegistration($p335Register(0x45, $p335EcCose, $p335EcInfo, -7, $p335C), $p335C, $p335Origin, $p335Rp, true);
$p335RsaSaved = P335::verifyRegistration($p335Register(0x45, $p335RsaCose, $p335RsaInfo, -257, $p335C), $p335C, $p335Origin, $p335Rp, true);
check('3.35 A: registration with user verification – ES256 and RS256 keys are stored', [$p335EcSaved['alg'], $p335RsaSaved['alg'], str_contains($p335EcSaved['klic'], 'BEGIN PUBLIC KEY'), str_contains($p335RsaSaved['klic'], 'BEGIN PUBLIC KEY')], [-7, -257, true, true]);
check('3.35 A: registration without the UV flag is refused when verification is required (and still passes for the old second step)', [
    str_starts_with($p335Rejects(fn () => P335::verifyRegistration($p335Register(0x41, $p335EcCose, $p335EcInfo, -7, $p335C), $p335C, $p335Origin, $p335Rp, true)), 'The device did not verify you'),
    $p335Rejects(fn () => P335::verifyRegistration($p335Register(0x41, $p335EcCose, $p335EcInfo, -7, $p335C), $p335C, $p335Origin, $p335Rp)),
], [true, 'accepted']);
$p335G = P335::challenge();
check('3.35 A: a passwordless assertion with UV verifies – ES256 and RS256; the counter is returned', [
    P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 3), $p335G, $p335Origin, $p335Rp, $p335EcSaved['klic'], 2, true),
    P335::verifySignIn($p335Assert($p335Rsa, $p335G, 0x05, 0), $p335G, $p335Origin, $p335Rp, $p335RsaSaved['klic'], 0, true),
], [3, 0]);
check('3.35 A: refused – UV missing, wrong rpIdHash, wrong origin, a challenge of another attempt (a replay), a registration response, another key, a counter going back', [
    str_starts_with($p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x01, 4), $p335G, $p335Origin, $p335Rp, $p335EcSaved['klic'], 3, true)), 'The device did not verify you'),
    str_starts_with($p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Rsa, $p335G, 0x01), $p335G, $p335Origin, $p335Rp, $p335RsaSaved['klic'], 0, true)), 'The device did not verify you'),
    $p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 4, 'evil.example'), $p335G, $p335Origin, $p335Rp, $p335EcSaved['klic'], 3, true)),
    $p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 4, 'admin.example', 'https://admin.example.evil.example'), $p335G, $p335Origin, $p335Rp, $p335EcSaved['klic'], 3, true)),
    $p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 4), P335::challenge(), $p335Origin, $p335Rp, $p335EcSaved['klic'], 3, true)),
    $p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 4), '', $p335Origin, $p335Rp, $p335EcSaved['klic'], 3, true)),
    $p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 4, 'admin.example', 'https://admin.example', 'webauthn.create'), $p335G, $p335Origin, $p335Rp, $p335EcSaved['klic'], 3, true)),
    $p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 4), $p335G, $p335Origin, $p335Rp, $p335RsaSaved['klic'], 3, true)),
    str_starts_with($p335Rejects(fn () => P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x05, 3), $p335G, $p335Origin, $p335Rp, $p335EcSaved['klic'], 3, true)), 'The key counter went backwards'),
    // the second step after a password keeps accepting a device without UV (as before 3.35)
    P335::verifySignIn($p335Assert($p335Ec, $p335G, 0x01, 9), $p335G, $p335Origin, $p335Rp, $p335EcSaved['klic'], 3),
], [true, true, 'The key belongs to a different domain.', 'The response was created at a different address than the site address in Settings.',
    'The response does not belong to this attempt. Please try again.', 'The response does not belong to this attempt. Please try again.',
    'The response from the device has an unexpected type.', 'The signature from the device is not valid.', true, 9]);
$p335RegOptions = P335::registrationOptions('c', 'admin.example', 'Site', 'h4ndle', 'jana', 'Jana', ['k1']);
$p335Handles = [P335::newUserHandle(), P335::newUserHandle()];
check('3.35 A: new passkeys are discoverable and verify the user; the passwordless request names no key and requires UV; the user handle is random and opaque', [
    $p335RegOptions['authenticatorSelection'], $p335RegOptions['user']['id'],
    P335::signInOptions('c', 'admin.example', [], true)['userVerification'], P335::signInOptions('c', 'admin.example', [], true)['allowCredentials'],
    P335::signInOptions('c', 'admin.example', ['k1'])['userVerification'],
    strlen(P335::fromB64($p335Handles[0])), $p335Handles[0] !== $p335Handles[1],
], [['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'], 'h4ndle', 'required', [], 'preferred', 16, true]);

// the sign-in rules per account state (Core\SignInPolicy) – the table of the release notes
$p335Old = ['idk' => 1, 'discoverable' => 0, 'pouzito' => null];
$p335New = ['idk' => 2, 'discoverable' => 1, 'pouzito' => null];
$p335Used = ['idk' => 3, 'discoverable' => 1, 'pouzito' => '2026-11-11 10:00:00'];
$p335Codes = (string) json_encode(['a', 'b']);
$p335Rules = static function (array $user, array $keys, array $site) use ($reportSettings): array {
    $s = $reportSettings($site);

    return [SP335::state($user, $keys, $s), SP335::needsSecondStep($user, $keys), SP335::secondSteps($user, $keys, $s)];
};
check('3.35 A: sign-in rules – no passkey / two-factor / passkey + password / old second-step passkey / passkey only / site requires passkeys', [
    $p335Rules(['totp_tajemstvi' => '', 'totp_zalozni' => null], [], []),
    $p335Rules(['totp_tajemstvi' => 'SECRET', 'totp_zalozni' => $p335Codes], [], ['signin_passkey_only' => '1']),
    $p335Rules(['totp_tajemstvi' => '', 'totp_zalozni' => $p335Codes], [$p335New], []),
    $p335Rules(['totp_tajemstvi' => 'SECRET', 'totp_zalozni' => $p335Codes, 'passkey_only' => 1], [$p335Old], []),
    $p335Rules(['totp_tajemstvi' => 'SECRET', 'totp_zalozni' => $p335Codes, 'passkey_only' => 1], [$p335New], []),
    $p335Rules(['totp_tajemstvi' => 'SECRET', 'totp_zalozni' => '[]'], [$p335New], ['signin_passkey_only' => '1']),
], [
    ['password', false, ['code' => false, 'backup' => false, 'passkey' => false]],
    ['two_factor', true, ['code' => true, 'backup' => true, 'passkey' => false]], // the site setting never touches an account without a passkey
    ['passkey', true, ['code' => false, 'backup' => true, 'passkey' => true]],
    ['passkey', true, ['code' => true, 'backup' => true, 'passkey' => true]], // "passkey only" waits for a working (discoverable) passkey
    ['passkey_only', true, ['code' => false, 'backup' => true, 'passkey' => true]],
    ['passkey_only', true, ['code' => false, 'backup' => false, 'passkey' => true]],
]);
check('3.35 A: a working passkey is a discoverable one; requiring passkeys of the site needs one that has signed in; freshness lasts ten minutes; the escape hatch is a config.php constant', [
    SP335::hasWorkingPasskey([$p335Old]), SP335::hasWorkingPasskey([$p335Old, $p335New]), SP335::hasUsedWorkingPasskey([$p335New]), SP335::hasUsedWorkingPasskey([$p335Old, $p335Used]),
    Kaleta\Core\Auth::verifiedWithin(null, 1000), Kaleta\Core\Auth::verifiedWithin(1000, 1599), Kaleta\Core\Auth::verifiedWithin(1000, 1600), Kaleta\Core\Auth::verifiedWithin(1000, 999), // one window for both proofs (Auth::VERIFIED_WINDOW)
    SP335::ESCAPE_HATCH, SP335::escapeHatch(), SP335::backupCodesLeft(['totp_zalozni' => $p335Codes]), SP335::backupCodesLeft(['totp_zalozni' => null]),
], [false, true, false, true, false, true, false, false, 'KALETA_PASSKEY_ONLY_OFF', false, 2, 0]);
check('3.35 A: passkeys only on the canonical host of the site URL (another host name, the IP or a port mismatch of the name does not count)', [
    SP335::hostMatches('www.firma.cz', 'https://www.firma.cz'), SP335::hostMatches('WWW.Firma.cz:443', 'https://www.firma.cz/'), SP335::hostMatches('firma.cz', 'https://www.firma.cz'),
    SP335::hostMatches('127.0.0.1:8095', 'http://localhost:8095'), SP335::hostMatches('localhost:8095', 'http://localhost:8095'), SP335::hostMatches('', 'https://www.firma.cz'),
], [true, true, false, false, true, false]);

// the code paths that must not loosen
$p335Auth = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
$p335Account = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
$p335Js = (string) file_get_contents(KALETA_ROOT . '/image/klice.js');
$p335Login = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/login.php');
check('3.35 A: an empty app secret is never checked (its codes are public); the passwordless sign-in requires UV, the account\'s handle and a discoverable key, uses the sign-in limits and pops its challenge first', [
    str_contains($p335Auth, "\$step = \$appCode && (string) \$user['totp_tajemstvi'] !== '' ? Totp::step("),
    str_contains($p335Auth, "(string) \$key['verejny'], (int) \$key['pocitadlo'], true);"),
    str_contains($p335Auth, "if (empty(\$key['discoverable'])) {"),
    str_contains($p335Auth, "hash_equals(Passkey::fromB64((string) \$user['passkey_handle']), Passkey::fromB64(\$handle))"),
    strpos($p335Auth, "\$this->session->remove('passkey_signin');") < strpos($p335Auth, "Passkey::verifySignIn(\$response, \$challenge, Passkey::origin(\$siteUrl), Passkey::rpId(\$siteUrl), (string) \$key['verejny'], (int) \$key['pocitadlo'], true)"),
    substr_count($p335Auth, 'return t(self::PASSKEY_FAILED);'),
    str_contains($p335Auth, '$secondStep = SignInPolicy::needsSecondStep($user, $this->workingKeys((int) $user[\'idu\']));'), // 3.35 review M3: the passkeys of this host
], [true, true, true, true, true, 1, true]);
check('3.35 A: registration needs a fresh confirmation and requires UV; removing too; the old TOTP precondition is gone; the script offers conditional UI and the button', [
    str_contains($p335Account, "\$this->freshnessRefused('Enter your current password to add a passkey.')"),
    str_contains($p335Account, "\$this->freshnessRefused('Enter your current password to remove a passkey. Nothing was removed.')"),
    str_contains($p335Account, 'Passkey::rpId($url), true);'), str_contains($p335Account, "'Turn on two-factor sign-in first.'"),
    str_contains($p335Js, "mediation: 'conditional'"), str_contains($p335Js, 'isConditionalMediationAvailable'), str_contains($p335Login, "'username webauthn'"),
    str_contains($p335Login, 'data-klic-bezhesla'),
], [true, true, true, false, true, true, true, true]);
$p335Keys = ['Signing in with the passkey did not succeed, or the account is temporarily locked after a series of failed attempts. Try again in a moment, or sign in with your user name and password.',
    'Sign in with a passkey', 'Passkeys work only at the main address of the site:', 'accounts with a passkey sign in with it only', 'Register again', 'second step only', 'without a password',
    'Passkey only', 'sign in with a passkey only', 'Backup code:', 'The device did not verify you (fingerprint, face or PIN). Set up a screen lock on the device and try again.',
    'This passkey was set up as the second step only. Sign in with your user name and password, then register the passkey again in My account to sign in without a password.',
    'reset the second factor and the passkeys (the user lost their devices and backup codes)', 'with a passkey, without a password', 'passkey only turned on', 'passkey only turned off'];
$p335Es = require KALETA_SYSTEM . '/jazyky/admin-es.php';
$p335Du = require KALETA_SYSTEM . '/jazyky/admin-de-du.php';
check('3.35 A: the texts are translated (cs, de, es) and the German ones that address the reader have an informal form', [
    array_values(array_filter($p335Keys, fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k], $p335Es[$k]))),
    array_values(array_filter($p335Keys, fn (string $k): bool => preg_match('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihnen)\b/', $adminDe[$k] ?? '') === 1 && !isset($p335Du[$k]))),
], [[], []]);
