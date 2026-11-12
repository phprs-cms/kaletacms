<?php

declare(strict_types=1);

/* ---------- 3.31.2 (N102): maintenance mode does not stop the check after an update or the cron address ---------- */
$m3312 = fn (array $home, bool $maintenance, array $probe = [200, 'KALETA-PROBE 9.9.9'], array $admin = [200, 'x']): bool
    => Kaleta\Core\Updater::probeVerdict(['probe' => $probe, 'home' => $home, 'admin' => $admin], '9.9.9', $maintenance) !== null;
check('3.31.2 N102: in maintenance mode the 503 of the home page is not "the new version is broken"; a 500, a broken administration or a check that does not start still are; without maintenance mode a 503 counts as before', [
    $m3312([503, 'Under construction'], true), $m3312([503, 'x'], false), $m3312([500, 'Fatal'], true), $m3312([502, ''], true),
    $m3312([503, 'x'], true, [200, 'KALETA-PROBE 9.9.9'], [500, 'Fatal']), $m3312([503, 'x'], true, [500, '']), $m3312([503, 'x'], true, [503, 'KALETA-PROBE 9.9.9'])],
    [false, true, true, true, true, true, false]);
$k3312 = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php');
check('[source-pin] 3.31.2 N102: the maintenance page lets /ulohy through like /mcp, and the routes of /ulohy still ask for their code', [
    str_contains($k3312, "\$this->app->settings()->bool('maintenance') && !in_array(\$request->path(), ['/mcp', '/ulohy'], true) && \$this->app->auth()->user() === null"),
    substr_count($k3312, "if (\$path === '/ulohy'"), str_contains($k3312, 'hash_equals($probe, $request->get(\'probe\'))'), str_contains($k3312, "!hash_equals(\$token, \$request->get('token'))"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Updater.php'), "return self::probeVerdict(\$answers, \$version, \$this->settings->bool('maintenance'));")],
    [true, 2, true, true, true]);

