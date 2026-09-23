<?php
/**
 * MiroCMS - konfigurace. Soubor config.php vytvoří instalátor (install.php);
 * ručně stačí zkopírovat tento vzor a doplnit údaje k databázi.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'mirocms',
        'user' => 'mirocms',
        'password' => '',
        'prefix' => 'rs_',
    ],
    // true = chyby se vypisují do stránky; na ostrém webu vždy false
    'debug' => false,
];
