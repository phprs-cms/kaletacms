<?php

declare(strict_types=1);

/* ---------- 3.7: the health check names PHP 8.3's crashing JIT mode ---------- */
$jitProbe = static function (string $mode): string {
    $code = 'require ' . var_export(KALETA_ROOT . '/system/bootstrap.php', true) . '; echo var_export(Kaleta\Core\Health::riskyJit(), true);';

    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=1 -d opcache.jit_buffer_size=16M -d opcache.jit=' . $mode . ' -r ' . escapeshellarg($code) . ' 2>/dev/null'));
};
$jitAvailable = function_exists('opcache_get_status') && PHP_VERSION_ID < 80400;
check('3.7 JIT: per-function JIT on first runs (1235) is named on PHP 8.3, nothing on 8.4 or without opcache', $jitProbe('1235'), $jitAvailable ? "'1235'" : 'NULL');
check('3.7 JIT: the default tracing JIT and JIT off are fine', [$jitProbe('tracing'), $jitProbe('off'), Kaleta\Core\Health::riskyJit()], ['NULL', 'NULL', null]);

