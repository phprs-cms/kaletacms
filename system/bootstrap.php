<?php
/**
 * Kaleta - system bootstrap.
 * Common start for index.php (site), admin.php (admin) and install.php.
 */

declare(strict_types=1);

const KALETA_VERSION = '3.9.0';

/** Number of the last migration in system/sql/migrace - the site uses it to tell that it must update the database after an update (checked by tools/test.sh). */
const KALETA_DB_VERSION = 83;

define('KALETA_ROOT', dirname(__DIR__));
define('KALETA_SYSTEM', __DIR__);

/**
 * The oldest PHP Kaleta runs on (3.7: 8.3, so that sites on hostings without 8.4 can move over; system/compat adds what
 * 8.3 lacks). The installer checks it, tools/release.php writes it into the update manifest as min_php.
 */
const KALETA_MIN_PHP = '8.3';

if (version_compare(PHP_VERSION, KALETA_MIN_PHP, '<')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    // no dictionary is loaded yet: English first, then Czech (INV-28)
    exit('Kaleta requires PHP ' . KALETA_MIN_PHP . ' or newer. The server runs PHP ' . PHP_VERSION . ".\n"
        . 'Kaleta vyžaduje PHP ' . KALETA_MIN_PHP . ' nebo novější. Na serveru běží PHP ' . PHP_VERSION . ".\n");
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Prague');

// Custom PSR-4 autoloader: system/src/Core/Db.php = Kaleta\Core\Db.
// Composer is not needed at runtime - the site can be uploaded over FTP as it is.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Kaleta\\')) {
        return;
    }
    $file = KALETA_SYSTEM . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// PHP 8.3: the HTML5 DOM that PHP 8.4 has built in (Dom\HTMLDocument and the classes around it) comes from system/compat
if (PHP_VERSION_ID < 80400 && extension_loaded('dom')) {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Dom\\') && preg_match('/^Dom\\\\[A-Za-z]+$/', $class) === 1 && is_file($file = KALETA_SYSTEM . '/compat/Dom/' . substr($class, 4) . '.php')) {
            require $file;
        }
    });
}

require KALETA_SYSTEM . '/src/helpers.php';
