<?php
/**
 * Kaleta - konfigurace. Soubor config.php vytvoří instalátor (install.php);
 * ručně stačí zkopírovat tento vzor a doplnit údaje k databázi.
 */

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
