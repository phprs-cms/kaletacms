<?php
/**
 * Router for PHP's built-in development server (on hosting, .htaccess does its job):
 *   php -S localhost:8080 system/dev-router.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
// Semgrep's tainted-filename findings below are covered by this rule: a path with /. (also /..) or a backslash never reaches the file system
// add-ons (3.3.2): only extensions/<slug>/public/ is served, and never PHP from it – as in .htaccess (3.19.1: with path info too)
if (preg_match('#^/(system|storage|tools|docs|dist)(/|$)|^/layout/.+\.php$|^/config(\.sample)?\.php$|/\.(?!well-known/)|\\\\|\x00|^/media/.*\.php|^/extensions/(?![^/]+/public/)|^/extensions/[^/]+/public/.+\.(php\d?|pht|phps|phtml|phar)(\.|/|$)#i', $path)) {
    http_response_code(403);
    exit('403');
}
// same as .htaccess: serve a browser that supports AVIF or WebP the sibling file foto.jpg.avif / .webp
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
if (preg_match('#^/media/.+\.(jpe?g|png)$#i', $path) && is_file($root . $path . '.avif') && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'image/avif')) {
    header('Content-Type: image/avif');
    header('Vary: Accept');
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    readfile($root . $path . '.avif');

    return true;
}
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
if (preg_match('#^/media/.+\.(jpe?g|png)$#i', $path) && is_file($root . $path . '.webp') && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'image/webp')) {
    header('Content-Type: image/webp');
    header('Vary: Accept');
    // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
    readfile($root . $path . '.webp');

    return true;
}
// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.php')) {
        $_SERVER['SCRIPT_NAME'] = $path;
        // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename
        require $root . $path;

        return true;
    }

    return false; // the server serves a static file itself
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
