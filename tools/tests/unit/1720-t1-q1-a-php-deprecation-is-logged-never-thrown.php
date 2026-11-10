<?php

declare(strict_types=1);

/* ---------- T1 (Q1): a PHP deprecation is logged, never thrown ---------- */
$t1Log = sys_get_temp_dir() . '/kaleta-t1-errors-' . getmypid() . '.log';
@unlink($t1Log);
Kaleta\Core\ErrorLog::$path = $t1Log;
// App::boot() sets error_reporting(E_ALL) before it installs handleError; the bare PHP of the tests may not (setup-php in CI uses
// php.ini-production: E_ALL & ~E_DEPRECATED, so handleError would hand the deprecation back to PHP), so the checks set it the same way
$t1Reporting = error_reporting(E_ALL);
$t1Thrown = static function (int $severity, ?bool $strict): string {
    try {
        return Kaleta\Core\App::handleError($severity, 'T1 old function', KALETA_ROOT . '/system/src/Core/X.php', 7, $strict) ? 'handled' : 'php';
    } catch (\ErrorException $e) {
        return 'thrown ' . $e->getSeverity();
    }
};
$t1Rows = static fn (): array => array_values(array_filter(explode("\n", (string) @file_get_contents($t1Log))));
check('T1 Q1: a deprecation (PHP or user) is logged and the page goes on; a warning and a notice are still thrown; strict mode (the tests) throws a deprecation', [
    $t1Thrown(E_DEPRECATED, false), $t1Thrown(E_USER_DEPRECATED, false), $t1Thrown(E_WARNING, false), $t1Thrown(E_USER_NOTICE, false), $t1Thrown(E_USER_DEPRECATED, true)],
    ['handled', 'handled', 'thrown ' . E_WARNING, 'thrown ' . E_USER_NOTICE, 'thrown ' . E_USER_DEPRECATED]);
$t1First = $t1Rows();
check('T1 Q1: the deprecation is one line, once a day (the same message and place), with the path inside the site; System status tells it from an error', [
    count($t1First), (bool) preg_match('~^\[\d{4}-\d\d-\d\dT[^\]]+\] PHP deprecation: T1 old function in system/src/Core/X\.php:7$~', $t1First[0] ?? ''),
    Kaleta\Core\ErrorLog::isDeprecation($t1First[0] ?? ''), Kaleta\Core\ErrorLog::isDeprecation('[2026-11-10T10:00:00+01:00] RuntimeException: PHP deprecation: x')],
    [1, true, true, false]);
// another request the same day (a fresh process forgets what it logged): the log itself says it is there already
$t1Seen = new ReflectionProperty(Kaleta\Core\ErrorLog::class, 'seen');
$t1Seen->setValue(null, []);
check('T1 Q1: a later request the same day does not log the same deprecation again; another message is logged', [
    Kaleta\Core\ErrorLog::deprecation('T1 old function', KALETA_ROOT . '/system/src/Core/X.php', 7), Kaleta\Core\ErrorLog::deprecation('T1 other', KALETA_ROOT . '/system/src/Core/X.php', 8), count($t1Rows())],
    [false, true, 2]);
check('T1 Q1: App installs handleError, the strict switch is the constant of the test copies, and the System status text is translated (cs, de, es)', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/App.php'), 'set_error_handler(self::handleError(...));'),
    str_contains((string) smoke_test_source(), '<?php define(\x27KALETA_STRICT_DEPRECATIONS\x27, true);'),
    isset($adminCs['%d PHP deprecation notice(s) – the site keeps working, a Kaleta update removes them'], $adminDe['%d PHP deprecation notice(s) – the site keeps working, a Kaleta update removes them'], $a3312Es['%d PHP deprecation notice(s) – the site keeps working, a Kaleta update removes them'])],
    [true, true, true]);
Kaleta\Core\ErrorLog::$path = null;
error_reporting($t1Reporting);
@unlink($t1Log);

