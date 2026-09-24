<?php
/**
 * Kaleta - administrace.
 * Adresy mají tvar admin.php?modul=novinky&akce=edit&id=5
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Kaleta\Core\App::boot();
$odpoved = (new Kaleta\Admin\Kernel($app))->handle();
// formulář smí odeslat jen na vlastní web; výjimka je souhlas s připojením aplikace (OAuth): po odeslání prohlížeč přejde
// na adresu návratu aplikace (claude.ai, localhost u Claude Code) a CSP form-action hlídá i tohle přesměrování
$hlavicky = $odpoved->headers;
$odeslatNa = "'self'" . (preg_match('#^https?://[a-z0-9.\[\]:-]+$#i', $hlavicky['X-Kaleta-Form-Action'] ?? '') ? ' ' . $hlavicky['X-Kaleta-Form-Action'] : '');
unset($hlavicky['X-Kaleta-Form-Action']);
// administrace: nic z ní nepatří do mezipaměti prohlížeče ani proxy a smí spouštět jen vlastní skripty (žádné inline, žádné cizí)
(new Kaleta\Core\Response($odpoved->body, $odpoved->status, $hlavicky + [
    'Cache-Control' => 'no-store, private',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' https:; "
        . "frame-src 'self' https:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action {$odeslatNa}; frame-ancestors 'self'",
] + ($app->request->isHttps() ? ['Strict-Transport-Security' => 'max-age=15552000'] : [])))->send();
