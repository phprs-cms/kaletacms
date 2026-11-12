<?php

declare(strict_types=1);

use Kaleta\Core\Settings;
use Kaleta\Core\Vault;

/* ---------- 3.39 review M1: a secret stored sealed but unreadable is never replaced by one the site makes itself ---------- */

// a Settings with its values loaded and a database nobody can reach: any write would fail with a connection error
$m1Settings = function (array $values): Settings {
    $settings = new Settings(new Kaleta\Core\Db('mysql:host=127.0.0.1;port=1;dbname=none', 'none', ''));
    (new ReflectionProperty(Settings::class, 'values'))->setValue($settings, $values);

    return $settings;
};
$m1Write = function (callable $write): string {
    try {
        $write();

        return 'nothing written';
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'not overwritten') ? 'refused' : 'tried to write';
    } catch (Throwable) {
        return 'tried to write'; // the unreachable database: the value would have been stored
    }
};
$m1Key = bin2hex(random_bytes(32));
Vault::configure($m1Key);
$m1Config = $m1Settings(['vault_check' => Vault::check($m1Key)]);
$m1SealedWebhook = Vault::sealSecret($m1Config, 'settings', 'whsec_' . bin2hex(random_bytes(8)));
$m1SealedSiteKey = Vault::sealSecret($m1Config, 'settings', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
// the database restored next to another config.php: state missing
Vault::configure(bin2hex(random_bytes(32)));
$m1Missing = $m1Settings(['vault_check' => Vault::check($m1Key), 'webhook_secret' => $m1SealedWebhook, 'site_key_secret' => $m1SealedSiteKey, 'smtp_password' => '']);
$m1Public = Kaleta\Fleet\Keys::publicKey($m1Missing);
check('3.39 review M1: without the key the webhook secret and the site key read as unreadable (an empty one does not); the heartbeat signs with a stand-in that is never stored, the webhook secret stays empty', [
    $m1Missing->secretUnreadable('webhook_secret'), $m1Missing->secretUnreadable('site_key_secret'), $m1Missing->secretUnreadable('smtp_password'),
    $m1Write(fn () => Kaleta\Core\Webhook::secret($m1Missing)), Kaleta\Core\Webhook::secret($m1Missing), Kaleta\Fleet\Keys::isPublicKey($m1Public), Kaleta\Fleet\Keys::publicKey($m1Missing) === $m1Public,
], [true, true, false, 'nothing written', '', true, true]);
check('3.39 review M1: Settings::set refuses to overwrite a sealed-but-unreadable secret; a person forcing it (the Settings form) and an ordinary empty secret go through', [
    $m1Write(fn () => $m1Missing->set('webhook_secret', 'whsec_new')), $m1Write(fn () => $m1Missing->set('site_key_secret', 'x')),
    $m1Write(fn () => $m1Missing->set('webhook_secret', 'whsec_new', true)), $m1Write(fn () => $m1Missing->set('smtp_password', 'new')),
], ['refused', 'refused', 'tried to write', 'tried to write']);
// the key back in config.php: the original opens again – nothing replaced it
Vault::configure($m1Key);
$m1Back = $m1Settings(['vault_check' => Vault::check($m1Key), 'webhook_secret' => $m1SealedWebhook, 'site_key_secret' => $m1SealedSiteKey]);
$m1Damaged = $m1Settings(['vault_check' => Vault::check($m1Key), 'webhook_secret' => Vault::SEALED_PREFIX . base64_encode(random_bytes(60))]);
check('3.39 review M1: with the vault_key line back the stored values open again; a damaged sealed value counts as unreadable and is kept too', [
    $m1Back->secretUnreadable('webhook_secret'), str_starts_with(Kaleta\Core\Webhook::secret($m1Back), 'whsec_'), Kaleta\Fleet\Keys::publicKey($m1Back) !== $m1Public,
    $m1Damaged->secretUnreadable('webhook_secret'), $m1Write(fn () => $m1Damaged->set('webhook_secret', 'whsec_other')),
], [false, true, true, true, 'refused']);
Vault::configure(null);
