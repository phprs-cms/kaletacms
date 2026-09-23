<?php
/**
 * Router pro vestavěný vývojový server PHP (na hostingu jeho práci dělá .htaccess):
 *   php -S localhost:8080 system/dev-router.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('#^/(system|storage|tools|docs|dist)(/|$)|^/layout/.+\.php$|^/config(\.sample)?\.php$|/\.|^/media/.*\.php#i', $path)) {
    http_response_code(403);
    exit('403');
}
// stejně jako .htaccess: prohlížeči s podporou WebP podat sourozenecký soubor foto.jpg.webp
if (preg_match('#^/media/.+\.(jpe?g|png)$#i', $path) && is_file($root . $path . '.webp') && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'image/webp')) {
    header('Content-Type: image/webp');
    header('Vary: Accept');
    readfile($root . $path . '.webp');

    return true;
}
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.php')) {
        $_SERVER['SCRIPT_NAME'] = $path;
        require $root . $path;

        return true;
    }

    return false; // statický soubor obslouží server sám
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
