<?php

declare(strict_types=1);

use Kaleta\Core\Settings;
use Kaleta\Core\Vault;

/* ---------- 3.39 B (N106): secret settings and TOTP seeds sealed with the key in config.php ---------- */

// a Settings whose values are already loaded (no database in the unit tests): the raw stored values as given
$n106Settings = function (array $values): Settings {
    $settings = new Settings(new Kaleta\Core\Db('mysql:host=127.0.0.1;port=1;dbname=none', 'none', ''));
    (new ReflectionProperty(Settings::class, 'values'))->setValue($settings, $values);

    return $settings;
};
$n106Key = bin2hex(random_bytes(32));
$n106Other = bin2hex(random_bytes(32));
$n106Plain = 'smtp-' . bin2hex(random_bytes(6));
Vault::configure($n106Key);
$n106Config = $n106Settings(['vault_check' => Vault::check($n106Key)]);
$n106Sealed = Vault::sealSecret($n106Config, 'settings', $n106Plain);
$n106Seed = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
$n106SealedSeed = Vault::sealSecret($n106Config, 'totp', $n106Seed);
check('3.39 N106: with the key in config.php a secret is stored sealed – the marker, no plain text, a new nonce each time – and opens again; an empty one stays empty', [
    Vault::isSealed($n106Sealed), str_contains($n106Sealed, $n106Plain), $n106Sealed !== Vault::sealSecret($n106Config, 'settings', $n106Plain),
    Vault::openSecret($n106Config, 'settings', $n106Sealed), Vault::sealSecret($n106Config, 'settings', ''), Vault::openSecret($n106Config, 'settings', 'older-plain-value'),
], [true, false, true, $n106Plain, '', 'older-plain-value']);
check('3.39 N106: a TOTP seed has its own purpose (the settings key does not open it) and a sealed seed fits the widened column (255)', [
    Vault::openSecret($n106Config, 'totp', $n106SealedSeed), Vault::openSecret($n106Config, 'settings', $n106SealedSeed), strlen($n106SealedSeed) <= 255,
], [$n106Seed, '', true]);
$n106Read = $n106Settings(['vault_check' => Vault::check($n106Key), 'smtp_password' => $n106Sealed, 'ai_key' => 'plain-ai', 'site_name' => Vault::SEALED_PREFIX . 'not-a-secret']);
check('3.39 N106: Settings::get opens a sealed secret setting, reads an older plain one as it is, and never touches a setting that is not a secret', [
    $n106Read->get('smtp_password'), $n106Read->get('ai_key'), $n106Read->get('site_name'),
], [$n106Plain, 'plain-ai', Vault::SEALED_PREFIX . 'not-a-secret']);
// another config.php (a database restored without its key): nothing opens, nothing new is sealed with a key that is not there
Vault::configure($n106Other);
$n106Missing = $n106Settings(['vault_check' => Vault::check($n106Key), 'smtp_password' => $n106Sealed]);
$n106MissingState = Vault::state($n106Missing);
check('3.39 N106: without the key the sealed value reads as empty (the site keeps working, System status says why) and a new value stays plain', [
    $n106MissingState, $n106Missing->get('smtp_password'), Vault::openSecret($n106Missing, 'totp', $n106SealedSeed), Vault::sealSecret($n106Missing, 'settings', 'new-value'),
], ['missing', '', '', 'new-value']);
// the key still in the database: plain text – a key next to the data would protect nothing; move() seals them
Vault::configure(null);
$n106Database = $n106Settings(['vault_check' => '']);
check('3.39 N106: with the key in the database secrets stay plain until the key moves to config.php', [Vault::state($n106Database), Vault::sealSecret($n106Database, 'settings', 'value')], ['database', 'value']);
// every field the administration keeps as a secret (type tajne) is sealed – a new one fails here until it is in the list
$n106Typed = [];
foreach ((new ReflectionClassConstant(Kaleta\Admin\Modules\Settings::class, 'FIELDS'))->getValue() as $n106Fields) {
    foreach ($n106Fields as $n106Field => $n106Type) {
        if (str_starts_with((string) $n106Type, 'tajne')) {
            $n106Typed[] = $n106Field;
        }
    }
}
check('3.39 N106: every secret field of the settings is sealed, none is in the site export, and secret_key (it signs links and is the base key in the database) is not among them', [
    array_values(array_diff($n106Typed, Vault::SECRET_SETTINGS)), array_values(array_intersect(Vault::SECRET_SETTINGS, Kaleta\Core\SiteExport::SETTINGS)), in_array('secret_key', Vault::SECRET_SETTINGS, true),
], [[], [], false]);
