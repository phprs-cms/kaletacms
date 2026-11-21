<?php

declare(strict_types=1);

/* ---------- 3.50 B1b (Q14): the public addresses after the page cache are one table, Front\Kernel::ROUTES ---------- */
// Rows [method, path or anchored pattern, handler] are tried top to bottom; a handler returns null for "not mine" and the next
// row is tried, so the table keeps every fall-through of the old if chain (the routes record of 3.49 is identical, and smoke
// 4530 replays its status and headers from tools/contracts/front-routes.txt). These checks replace reading handle() as text.
$b1bRoutes = (new ReflectionClassConstant(Kaleta\Front\Kernel::class, 'ROUTES'))->getValue();
$b1bHandlers = array_column($b1bRoutes, 2);
$b1bPageRow = (int) array_search('routePage', $b1bHandlers, true);
check('3.50 B1b: the order of the route table is the contract – a new row is written here too, at its place', array_map(fn (array $route): string => implode(' ', $route), $b1bRoutes), [
    'ANY / routeHome', 'ANY /index.php routeHome', 'ANY #^/(?:novinky(?:/.*)?|rss\\.xml|feed\\.json)$#Ds routeNewsOff', 'ANY /novinky routeNewsList',
    'ANY #^/novinky/kategorie/([a-z0-9-]+)$#D routeNewsCategory', 'ANY #^/novinky/stitek/([a-z0-9-]+)$#D routeNewsTag',
    'ANY #^/novinky/([a-z0-9-]+)\\.md$#D routeNewsMarkdown', 'ANY #^/novinky/([a-z0-9-]+)$#D routeNewsItem', 'ANY /hledani routeSearch', 'ANY /rss.xml routeRss',
    'ANY /manifest.webmanifest routeManifest', 'ANY /favicon.ico routeFavicon', 'ANY #^/og/([a-f0-9]{32})\\.png$#D routeShareImage',
    'ANY /robots.txt routeRobots', 'ANY /sitemap.xml routeSitemap', 'ANY /feed.json routeFeed', 'ANY /.well-known/security.txt routeSecurityTxt',
    'ANY /llms.txt routeLlms', 'ANY #^/.+\\.txt$#Ds routeIndexNowKey', 'POST /souhlas routeConsentLog', 'POST /popup routePopupCounter',
    'POST /fleet/pair routeFleet', 'POST /fleet/heartbeat routeFleet', 'POST /fleet/unpair routeFleet', 'POST /fleet/kit routeFleet', 'POST /fleet/uptime routeFleet',
    'ANY /fleet/calendar.ics routeFleetCalendar', 'POST /hit routeHit', 'POST /vitals routeVitals', 'POST /konverze routeConversion', 'ANY /mcp routeMcp',
    'ANY #^/download/([A-Za-z0-9._-]{30,900})$#D routeDownload', 'ANY /odber routeSubscription',
    'ANY #^/application-consent/([a-f0-9]{64})$#D routeApplicationConsent', 'ANY /formular routeForm', 'POST /_booking routeBooking',
    'ANY /_booking/days routeBookingAvailability', 'ANY /_booking/slots routeBookingAvailability', 'ANY #^/_booking/cancel/([a-f0-9]{32})$#D routeBookingCancel',
    'ANY #^/_booking/choose/([a-f0-9]{32})$#D routeBookingChoose', 'ANY #^/_booking/ics/([a-f0-9]{32})$#D routeBookingIcs',
    'ANY /ulohy routeUpdateProbe', 'ANY /ulohy routeTasks', 'ANY #^/screen/([a-f0-9]{32})$#D routeScreen', 'ANY /stav.json routeHealth',
    'ANY #^/.*$#Ds routePage', 'ANY #^/_sekce/([a-z0-9-]{1,40})$#D routeSectionPreview', 'ANY #^/_popup/(\\d+)$#D routePopupPreview',
    'ANY #^/_komponenta/(\\d+)$#D routeComponentPreview', 'ANY #^/([a-z0-9-]{1,110})(?:/([a-z0-9-]{1,160}))?\\.ics$#D routeCalendarFile',
    'ANY #^/_testimonial/([a-f0-9]{32})$#D routeTestimonial', 'POST /_komentar routeDraftComment', 'ANY /_report routeWhistleblowing',
    'ANY /_report/follow routeWhistleblowing', 'ANY #^/([a-z0-9-]{1,110})/(?:_compare|_porovnat)$#D routeCompare',
    'ANY #^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#D routeCollectionItem', 'ANY #^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})/latest$#D routeDocumentLatest',
    'ANY #^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})/([a-z0-9-]{1,160})$#D routeCollectionCategory', 'ANY #^/([a-z0-9-]{1,110})/_kategorie$#D routeCategoryTemplate',
]);

$b1bBadHandlers = [];
foreach (array_unique($b1bHandlers) as $b1bHandler) {
    $b1bMethod = method_exists(Kaleta\Front\Kernel::class, $b1bHandler) ? new ReflectionMethod(Kaleta\Front\Kernel::class, $b1bHandler) : null;
    if ($b1bMethod === null || !$b1bMethod->isPrivate() || $b1bMethod->isStatic() || $b1bMethod->getNumberOfParameters() !== 1
        || (string) $b1bMethod->getParameters()[0]->getType() !== 'array' || !in_array((string) $b1bMethod->getReturnType(), ['Kaleta\Core\Response', '?Kaleta\Core\Response'], true)) {
        $b1bBadHandlers[] = $b1bHandler;
    }
}
check('3.50 B1b: every handler of the table exists as a private route method that takes the match and answers a Response or null', $b1bBadHandlers, []);

$b1bBadRows = [];
foreach ($b1bRoutes as $b1bRow => [$b1bMethodName, $b1bPattern]) {
    $b1bRegex = str_starts_with($b1bPattern, '#^');
    if (!in_array($b1bMethodName, ['ANY', 'POST'], true)
        || ($b1bRegex ? (@preg_match($b1bPattern, '') === false || preg_match('/\$#Ds?$/D', $b1bPattern) !== 1) : (!str_starts_with($b1bPattern, '/') || str_contains($b1bPattern, '#')))) {
        $b1bBadRows[] = $b1bRow . ' ' . $b1bPattern;
    }
}
check('3.50 B1b: every row is ANY or POST, an exact path from the root or a pattern that compiles and is anchored at both ends', $b1bBadRows, []);

$b1bExact = array_column(array_filter($b1bRoutes, fn (array $route): bool => !str_starts_with($route[1], '#^')), 1);
check('3.50 B1b: every Czech system path keeps its row (Core\Routes::SYSTEM_PATHS exact, SYSTEM_PREFIXES as a pattern), the English forms reach it through Routes::systemPath', [
    array_values(array_filter(array_keys(Kaleta\Core\Routes::SYSTEM_PATHS), fn (string $czech): bool => !in_array('/' . $czech, $b1bExact, true))),
    array_values(array_filter(array_keys(Kaleta\Core\Routes::SYSTEM_PREFIXES), fn (string $czech): bool => array_filter($b1bRoutes, fn (array $route): bool => str_starts_with($route[1], '#^/' . $czech . '/')) === [])),
], [[], []]);

// a one-segment path answered before the page row would hide a page with that slug, and a news slug of that word would take it
$b1bEarlyWords = array_values(array_unique(array_filter(array_map(fn (string $path): string => substr($path, 1), array_slice($b1bExact, 0, (int) array_search('/stav.json', $b1bExact, true) + 1)),
    fn (string $word): bool => $word !== '' && !str_contains($word, '/') && !str_contains($word, '.') && !str_starts_with($word, '_'))));
check('3.50 B1b: every one-segment path above the page row is a reserved page slug and no news slug', [
    count($b1bEarlyWords) >= 10,
    array_values(array_diff($b1bEarlyWords, Kaleta\Admin\Modules\Pages::RESERVED_SLUGS)),
    array_values(array_filter($b1bEarlyWords, fn (string $word): bool => $word !== 'novinky' && Kaleta\Core\Routes::systemSlugError($word) === null)),
], [true, [], []]);

check('3.50 B1b: /ulohy has two rows next to each other – the update\'s probe (with ?probe= only), then cron – and the page row comes after every system route', [
    array_keys(array_filter($b1bRoutes, fn (array $route): bool => $route[1] === '/ulohy')) === [$b1bPageRow - 4, $b1bPageRow - 3],
    array_values(array_column(array_filter($b1bRoutes, fn (array $route): bool => $route[1] === '/ulohy'), 2)),
    $b1bPageRow > (int) array_search('routeHealth', $b1bHandlers, true), count(array_keys($b1bHandlers, 'routePage', true)),
], [true, ['routeUpdateProbe', 'routeTasks'], true, 1]);

// the table at work, on the routes that answer from the settings and the request alone (b1aKernelFor of 4530)
$b1bRoute = static fn (Kaleta\Front\Kernel $kernel, string $path, bool $isPost = false): ?Kaleta\Core\Response => (new ReflectionMethod(Kaleta\Front\Kernel::class, 'route'))->invoke($kernel, $path, $isPost);
$b1bCode = bin2hex(random_bytes(6));
$b1bProbe = $b1bRoute($b1aKernelFor(['update_probe' => $b1bCode, 'tasks_token' => ''], ['probe' => $b1bCode]), '/ulohy');
$b1bCron = $b1bRoute($b1aKernelFor(['update_probe' => $b1bCode, 'tasks_token' => ''], []), '/ulohy');
$b1bKeyFile = $b1bRoute($b1aKernelFor(['indexnow_key' => 'k' . $b1bCode]), '/k' . $b1bCode . '.txt');
check('3.50 B1b: a row that passes (the probe without ?probe=) leads to the next one (cron), a pattern row hands its match to the handler', [
    [$b1bProbe?->status, $b1bProbe?->body], [$b1bCron?->status, $b1bCron?->body], [$b1bKeyFile?->status, $b1bKeyFile?->body],
    $b1bRoute($b1aKernelFor(['health_token' => ''], []), '/stav.json')?->status,
    (new ReflectionMethod(Kaleta\Front\Kernel::class, 'routeIndexNowKey'))->invoke($b1aKernelFor(['indexnow_key' => 'k' . $b1bCode]), ['/x' . $b1bCode . '.txt']),
    (new ReflectionMethod(Kaleta\Front\Kernel::class, 'routeIndexNowKey'))->invoke($b1aKernelFor(['indexnow_key' => '']), ['/.txt']),
    (new ReflectionMethod(Kaleta\Front\Kernel::class, 'routeUpdateProbe'))->invoke($b1aKernelFor(['update_probe' => $b1bCode]), ['/ulohy']),
], [[200, 'KALETA-PROBE ' . KALETA_VERSION . "\n"], [403, t('Invalid token.') . "\n"], [200, 'k' . $b1bCode], 403, null, null, null]);

// 3.50 integration: the talent pool consent link is a row above the pages, so its word cannot be the news slug either (a news slug
// "application-consent" was rewritten to /novinky in the constructor and hid the link); unit 0110 now reads it like any early path
check('3.50 integration: the talent pool path is reserved for the news slug – refused as a new one, ignored as a stored one', [
    in_array(Kaleta\Core\TalentPool::PATH, Kaleta\Core\Routes::NEWS_RESERVED, true), Kaleta\Core\Routes::systemSlugError(Kaleta\Core\TalentPool::PATH),
    in_array('routeApplicationConsent', array_column(array_slice($b1bRoutes, 0, $b1bPageRow), 2), true)],
    [true, 'This URL is used by the system, choose another one.', true]);
