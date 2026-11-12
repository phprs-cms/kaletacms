<?php

declare(strict_types=1);

use Kaleta\Core\Settings;
use Kaleta\Core\Vault;

/* ---------- 3.39 review M2: a TOTP seed is sealed only once the widened column (0126) is in place ---------- */

$m2Settings = function (array $values): Settings {
    $settings = new Settings(new Kaleta\Core\Db('mysql:host=127.0.0.1;port=1;dbname=none', 'none', ''));
    (new ReflectionProperty(Settings::class, 'values'))->setValue($settings, $values);

    return $settings;
};
$m2Key = bin2hex(random_bytes(32));
Vault::configure($m2Key);
// the database one version behind (0126 failed, timed out or has not run yet): nothing is sealed, a new seed stays plain
$m2Pending = $m2Settings(['vault_check' => Vault::check($m2Key), 'db_version' => (string) (KALETA_DB_VERSION - 1)]);
check('3.39 review M2: while a migration is pending a new TOTP seed is stored plain (it always fits and reads) and sealStored seals nothing – without touching the database', [
    Vault::seedsFit($m2Pending->db(), $m2Pending), Vault::sealSeed($m2Pending->db(), $m2Pending, 'JBSWY3DPEHPK3PXP'), Vault::sealStored($m2Pending->db(), $m2Pending),
], [false, 'JBSWY3DPEHPK3PXP', 0]);
// the key still in the database: plain as before, whatever the column
Vault::configure(null);
$m2Database = $m2Settings(['vault_check' => '', 'db_version' => (string) KALETA_DB_VERSION]);
check('3.39 review M2: with the key in the database a new seed stays plain', Vault::sealSeed($m2Database->db(), $m2Database, 'JBSWY3DPEHPK3PXP'), 'JBSWY3DPEHPK3PXP');
