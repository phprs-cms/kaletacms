<?php
/**
 * Kaleta - konfigurace. Soubor config.php vytvoří instalátor (install.php);
 * ručně stačí zkopírovat tento vzor a doplnit údaje k databázi.
 */

// Locked out after losing a passkey? Uncomment the next line: every "passkey only" rule is switched off, and an
// administrator account whose only second step is a passkey signs in with the password alone. The admin warns on every
// page while it is here – register a passkey again in My account and remove the line.
// define('KALETA_PASSKEY_ONLY_OFF', true);

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'kaleta',
        'user' => 'kaleta',
        'password' => '',
        'prefix' => 'ka_',
    ],
    // true = chyby se vypisují do stránky; na ostrém webu vždy false
    'debug' => false,
];
