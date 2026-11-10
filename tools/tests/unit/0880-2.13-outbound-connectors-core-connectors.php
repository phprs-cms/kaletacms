<?php

declare(strict_types=1);

/* ---------- 2.13: outbound connectors (Core\Connectors) ---------- */
check('2.13 Connectors: the curated list, an unknown service is none', [Kaleta\Core\Connectors::service('google'), Kaleta\Core\Connectors::service('evil')], [Kaleta\Connectors\Google::class, null]);
putenv('KALETA_CONNECTORS_FAKE=http://127.0.0.1:9');
$fakeUrls = [Kaleta\Core\Connectors::url('https://oauth2.googleapis.com/token'), Kaleta\Core\Connectors::url('https://sheets.googleapis.com/v4/spreadsheets?x=1'), Kaleta\Core\Connectors::url('http://example.com/a')];
putenv('KALETA_CONNECTORS_FAKE');
check('2.13 Connectors::url – tests send every https call to the fake (path and query kept); without it the real address', [$fakeUrls, Kaleta\Core\Connectors::url('https://oauth2.googleapis.com/token')],
    [['http://127.0.0.1:9/token', 'http://127.0.0.1:9/v4/spreadsheets?x=1', 'http://example.com/a'], 'https://oauth2.googleapis.com/token']);
check('2.13 Connectors: Google asks only for its listed scopes, offline, and the delivery queue retries five times', [count(Kaleta\Connectors\Google::SCOPES), Kaleta\Connectors\Google::AUTH, count(Kaleta\Core\Connectors::RETRY_DELAYS), Kaleta\Core\Scheduler::JOBS['connectors'][0]],
    [5, 'oauth', 5, 0]);

