<?php
/**
 * Kaleta - public part of the site.
 */

declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

$app = Kaleta\Core\App::boot();
(new Kaleta\Front\Kernel($app))->handle()->send();

// after the page is sent: notifications about just-published (including scheduled) articles and a check for security updates (at most once per 12 hours)
Kaleta\Core\Webhook::afterResponse($app); // a new enquiry goes to the webhook only now – the visitor does not wait
Kaleta\Core\Mail::afterResponse($app); // 3.26: an e-mail queued by an MCP call (an assignment, a mention) goes right after its answer
Kaleta\Core\Contacts::afterResponse($app); // 3.28: a new enquiry, booking or subscription joins its customer – the visitor does not wait
Kaleta\Core\Notifications::runInBackground($app);
Kaleta\Core\Updater::runInBackground($app);
