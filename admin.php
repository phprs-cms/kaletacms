<?php
/**
 * Kaleta - administrace.
 * Adresy mají tvar admin.php?modul=novinky&akce=edit&id=5
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Kaleta\Core\App::boot();
$odpoved = (new Kaleta\Admin\Kernel($app))->handle();
// administrace: nic z ní nepatří do mezipaměti prohlížeče ani proxy a smí spouštět jen vlastní skripty (žádné inline, žádné cizí)
(new Kaleta\Core\Response($odpoved->body, $odpoved->status, $odpoved->headers + [
    'Cache-Control' => 'no-store, private',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' https:; "
        . "frame-src 'self' https:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
] + ($app->request->isHttps() ? ['Strict-Transport-Security' => 'max-age=15552000'] : [])))->send();
