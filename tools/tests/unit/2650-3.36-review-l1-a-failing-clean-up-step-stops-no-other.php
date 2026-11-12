<?php

declare(strict_types=1);

// 3.36 review L1: the daily clean-up runs every retention step even when an earlier one fails (a real database error in the
// applications or the customers must not keep enquiries, bookings, consents and sign-ups past their retention); each failure
// is logged and the first one is thrown on, so the job is still recorded as failed
use Kaleta\Core\ErrorLog;
use Kaleta\Core\Notifications;

$l1Log = sys_get_temp_dir() . '/kaleta-l1-' . bin2hex(random_bytes(4)) . '.log';
$l1Before = ErrorLog::$path;
ErrorLog::$path = $l1Log;
$l1Ran = [];
$l1First = new RuntimeException('l1 first');
$l1Thrown = null;
try {
    Notifications::runEach([
        function () use (&$l1Ran): void { $l1Ran[] = 'a'; },
        function () use (&$l1Ran, $l1First): void { $l1Ran[] = 'b'; throw $l1First; },
        function () use (&$l1Ran): void { $l1Ran[] = 'c'; },
        function () use (&$l1Ran): void { $l1Ran[] = 'd'; throw new PDOException('l1 second'); },
        function () use (&$l1Ran): void { $l1Ran[] = 'e'; },
    ]);
} catch (Throwable $e) {
    $l1Thrown = $e;
}
$l1Logged = is_file($l1Log) ? (string) file_get_contents($l1Log) : '';
check('3.36 review L1: Notifications::runEach runs every step after a failing one, logs each failure and throws the first one at the end',
    [$l1Ran, $l1Thrown === $l1First, substr_count($l1Logged, 'RuntimeException: l1 first'), substr_count($l1Logged, 'PDOException: l1 second')],
    [['a', 'b', 'c', 'd', 'e'], true, 1, 1]);
$l1Quiet = 'none';
try {
    Notifications::runEach([fn () => 1, fn () => 2]);
} catch (Throwable) {
    $l1Quiet = 'thrown';
}
check('3.36 review L1: … and throws nothing when every step succeeds', $l1Quiet, 'none');
ErrorLog::$path = $l1Before;
@unlink($l1Log);
