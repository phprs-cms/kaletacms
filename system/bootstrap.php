<?php
/**
 * MiroCMS - zavaděč systému.
 * Společný start pro index.php (web), admin.php (administrace) a install.php.
 */

declare(strict_types=1);

const MIROCMS_VERSION = '3.0.0';

/** Číslo poslední migrace v system/sql/migrace - web podle něj pozná, že má po aktualizaci upravit databázi (hlídá tools/test.sh). */
const MIROCMS_VERZE_DB = 5;

define('MIROCMS_ROOT', dirname(__DIR__));
define('MIROCMS_SYSTEM', __DIR__);

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('MiroCMS vyžaduje PHP 8.4 nebo novější. Na serveru běží PHP ' . PHP_VERSION . '.');
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Prague');

// Vlastní PSR-4 autoloader: system/src/Core/Db.php = MiroCMS\Core\Db.
// Composer není k běhu potřeba - web se dá nahrát přes FTP tak, jak je.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'MiroCMS\\')) {
        return;
    }
    $file = MIROCMS_SYSTEM . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require MIROCMS_SYSTEM . '/src/helpers.php';
