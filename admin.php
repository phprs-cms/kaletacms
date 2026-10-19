<?php
/**
 * Kaleta - admin.
 * URLs look like admin.php?module=news&action=edit&id=5.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Kaleta\Core\App::boot();
$response = (new Kaleta\Admin\Kernel($app))->handle();
// 3.15 (N10): after a change, files waiting for approval that published content now uses go public (Core\MediaStaging)
if ($app->request->isPost() && $app->auth()->user() !== null && !in_array($app->request->get('action'), Kaleta\Admin\Kernel::DRAFT_ACTIONS, true)) {
    Kaleta\Core\MediaStaging::promotePublished($app->db());
}
// forms may submit only to the site itself; the exception is consent to connecting an application (OAuth): after submitting,
// the browser goes to the application's return URL (claude.ai, localhost for Claude Code) and CSP form-action guards this redirect too
$headers = $response->headers;
$formTargets = "'self'" . (preg_match('#^https?://[a-z0-9.\[\]:-]+$#iD', $headers['X-Kaleta-Form-Action'] ?? '') ? ' ' . $headers['X-Kaleta-Form-Action'] : '');
unset($headers['X-Kaleta-Form-Action']);
// admin: nothing from it belongs in the browser or proxy cache, and it may run only its own scripts (no inline, no third-party)
(new Kaleta\Core\Response($response->body, $response->status, $headers + [
    'Cache-Control' => 'no-store, private',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' https:; "
        . "frame-src 'self' https:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action {$formTargets}; frame-ancestors 'self'",
] + ($app->request->isHttps() ? ['Strict-Transport-Security' => 'max-age=15552000'] : [])))->send();
Kaleta\Core\Webhook::afterResponse($app); // e.g. a just-published news item
Kaleta\Core\Mail::afterResponse($app); // e.g. the password reset link – queued, so the answer's timing reveals no account (3.3.3)
