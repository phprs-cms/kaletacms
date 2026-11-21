<?php

declare(strict_types=1);

/* ---------- 3.50 B1a (Q14): every inline branch of Front\Kernel::handle() is a private route method after it ---------- */
// The chain keeps its order and its conditions; a branch body became a method, so the routes answer as before (the record of
// 3.49 in plan/rec350/routes.tag.txt is identical) and the handlers can be called one by one, as here.
$b1aKernel = new ReflectionClass(Kaleta\Front\Kernel::class);
$b1aHandle = $b1aKernel->getMethod('handle');
$b1aRoutes = array_values(array_filter($b1aKernel->getMethods(), fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'route')));
check('3.50 B1a: the route methods are private instance methods placed right after handle(), each answering a Response', [
    count($b1aRoutes) >= 20,
    array_values(array_map(fn (ReflectionMethod $method): string => $method->getName(), array_filter($b1aRoutes, fn (ReflectionMethod $method): bool => !$method->isPrivate() || $method->isStatic()))),
    array_values(array_map(fn (ReflectionMethod $method): string => $method->getName(), array_filter($b1aRoutes, fn (ReflectionMethod $method): bool => $method->getStartLine() < $b1aHandle->getEndLine()))),
    array_values(array_unique(array_map(fn (ReflectionMethod $method): string => ltrim((string) $method->getReturnType(), '?'), $b1aRoutes))),
], [true, [], [], ['Kaleta\Core\Response']]);

/**
 * A kernel on the given settings and request, without a database and without the constructor (which reads the database):
 * enough for the routes that answer from the settings and the request alone.
 */
$b1aKernelFor = static function (array $settings, array $query = [], array $server = []): Kaleta\Front\Kernel {
    $app = new Kaleta\Core\App([], new Kaleta\Core\Request($query, [], $server + ['REQUEST_METHOD' => 'GET']));
    $siteSettings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($siteSettings, $settings + ['site_name' => 'Unit site']);
    (new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($app, $siteSettings);
    $kernel = (new ReflectionClass(Kaleta\Front\Kernel::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Front\Kernel::class, 'app'))->setValue($kernel, $app);

    return $kernel;
};
$b1aCall = static fn (Kaleta\Front\Kernel $kernel, string $route, mixed ...$arguments): Kaleta\Core\Response => (new ReflectionMethod(Kaleta\Front\Kernel::class, $route))->invoke($kernel, ...$arguments);
$b1aShape = static fn (Kaleta\Core\Response $response): array => [$response->status, $response->headers['Content-Type'] ?? '', $response->headers['Retry-After'] ?? '', $response->headers['Cache-Control'] ?? ''];

$b1aProbeCode = bin2hex(random_bytes(6));
$b1aProbeOk = $b1aCall($b1aKernelFor(['update_probe' => $b1aProbeCode], ['probe' => $b1aProbeCode]), 'routeUpdateProbe');
check('3.50 B1a: /ulohy?probe= answers the running version for the one-time code of an update, 403 for another code or with no update under way', [
    $b1aShape($b1aProbeOk), $b1aProbeOk->body,
    $b1aShape($b1aCall($b1aKernelFor(['update_probe' => $b1aProbeCode], ['probe' => $b1aProbeCode . 'x']), 'routeUpdateProbe')),
    $b1aShape($b1aCall($b1aKernelFor(['update_probe' => ''], ['probe' => $b1aProbeCode]), 'routeUpdateProbe')),
], [[200, 'text/plain; charset=utf-8', '', 'no-store'], 'KALETA-PROBE ' . KALETA_VERSION . "\n", [403, 'text/plain; charset=utf-8', '', ''], [403, 'text/plain; charset=utf-8', '', '']]);

$b1aToken = bin2hex(random_bytes(6));
check('3.50 B1a: cron (/ulohy) and monitoring (/stav.json) refuse a missing or wrong token before they touch anything', [
    $b1aShape($b1aCall($b1aKernelFor(['tasks_token' => ''], ['token' => '']), 'routeTasks')),
    $b1aShape($b1aCall($b1aKernelFor(['tasks_token' => $b1aToken], ['token' => $b1aToken . 'x']), 'routeTasks')),
    $b1aShape($b1aCall($b1aKernelFor(['health_token' => $b1aToken], ['token' => 'x']), 'routeHealth')),
    $b1aCall($b1aKernelFor(['health_token' => ''], []), 'routeHealth')->body,
], [[403, 'text/plain; charset=utf-8', '', ''], [403, 'text/plain; charset=utf-8', '', ''], [403, 'application/json; charset=utf-8', '', ''], '{"chyba":"Invalid token."}']);

$b1aMaintenance = $b1aCall($b1aKernelFor(['maintenance_text' => 'Back soon <b>', 'update_probe' => '']), 'routeMaintenance');
check('3.50 B1a: the maintenance page is a 503 with Retry-After and the escaped text; the update\'s own check gets the same page with 200', [
    $b1aShape($b1aMaintenance), str_contains($b1aMaintenance->body, '<p>Back soon &lt;b&gt;</p>'), str_contains($b1aMaintenance->body, '<h1 style="font-size:28px">Unit site</h1>'),
    $b1aShape($b1aCall($b1aKernelFor(['update_probe' => 'x'], [], ['HTTP_USER_AGENT' => 'Kaleta-update-check/3.50']), 'routeMaintenance')),
    $b1aShape($b1aCall($b1aKernelFor(['update_probe' => ''], [], ['HTTP_USER_AGENT' => 'Kaleta-update-check/3.50']), 'routeMaintenance')),
], [[503, 'text/html; charset=utf-8', '3600', 'no-store'], true, true, [200, 'text/html; charset=utf-8', '3600', 'no-store'], [503, 'text/html; charset=utf-8', '3600', 'no-store']]);

$b1aIndexNow = $b1aCall($b1aKernelFor([]), 'routeIndexNowKey', 'k' . $b1aToken);
check('3.50 B1a: the IndexNow key file answers the key as plain text', [$b1aShape($b1aIndexNow), $b1aIndexNow->body], [[200, 'text/plain; charset=utf-8', '', ''], 'k' . $b1aToken]);
