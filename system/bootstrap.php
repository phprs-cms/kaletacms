<?php
/**
 * Kaleta - zavaděč systému.
 * Společný start pro index.php (web), admin.php (administrace) a install.php.
 */

declare(strict_types=1);

const KALETA_VERSION = '1.3.0';

/** Číslo poslední migrace v system/sql/migrace - web podle něj pozná, že má po aktualizaci upravit databázi (hlídá tools/test.sh). */
const KALETA_VERZE_DB = 23;

define('KALETA_ROOT', dirname(__DIR__));
define('KALETA_SYSTEM', __DIR__);

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Kaleta vyžaduje PHP 8.4 nebo novější. Na serveru běží PHP ' . PHP_VERSION . '.');
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Prague');

// Vlastní PSR-4 autoloader: system/src/Core/Db.php = Kaleta\Core\Db.
// Composer není k běhu potřeba - web se dá nahrát přes FTP tak, jak je.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Kaleta\\')) {
        return;
    }
    $file = KALETA_SYSTEM . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require KALETA_SYSTEM . '/src/helpers.php';
