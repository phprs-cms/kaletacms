<?php

declare(strict_types=1);

/* ---------- 3.32 (pre-release review): the error log – rotation under a lock with a re-check, the cut first line of a tail, more masking ---------- */
$r332Errors = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/ErrorLog.php');
$r332Long = str_repeat('Ab', 18);
check('3.32: ErrorLog::clean masks 32+ letters-only and digits-only tokens and the value of "Duplicate entry \'…\'"; short words, class names and paths stay; rotate() locks and re-checks; System status skips a cut first line', [
    Kaleta\Core\ErrorLog::clean('token ' . $r332Long . ' and ' . str_repeat('7', 32) . ' end'),
    Kaleta\Core\ErrorLog::clean("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'Olivia O\\'Hart-3' for key 'ka_contacts.uk'"),
    Kaleta\Core\ErrorLog::clean('Kaleta\Core\ErrorLog in system/src/Core/ErrorLog.php:12 ' . str_repeat('a', 31) . ' ' . str_repeat('1', 31)),
    str_contains($r332Errors, 'flock($f, LOCK_EX)'), str_contains($r332Errors, "\$locked['ino'] === \$now['ino'] && \$locked['dev'] === \$now['dev'] && (int) \$now['size'] > self::MAX_BYTES"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php'), "if (!str_starts_with(\$row, '[') || substr(\$row, 1, 25) < \$from) {")],
    ['token [redacted] and [redacted] end', "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '[redacted]' for key 'ka_contacts.uk'",
        'Kaleta\Core\ErrorLog in system/src/Core/ErrorLog.php:12 ' . str_repeat('a', 31) . ' ' . str_repeat('1', 31), true, true, true]);
// a real race: the log over the cap is rotated once; a second rotate() of a request that saw the full log leaves the fresh log alone
$r332Dir = sys_get_temp_dir() . '/kaleta-r332-' . getmypid();
@mkdir($r332Dir);
$r332Saved = Kaleta\Core\ErrorLog::$path;
Kaleta\Core\ErrorLog::$path = $r332Dir . '/chyby.log';
file_put_contents(Kaleta\Core\ErrorLog::$path, str_repeat("[2026-01-01T00:00:00+00:00] old\n", (int) (Kaleta\Core\ErrorLog::MAX_BYTES / 30) + 10));
$r332Held = fopen(Kaleta\Core\ErrorLog::$path, 'rb'); // a request that opened the full log before the rotation
Kaleta\Core\ErrorLog::write('first after the rotation');
Kaleta\Core\ErrorLog::rotate(); // the late request: the file at the path is small now – nothing moves
$r332State = [filesize(Kaleta\Core\ErrorLog::$path . '.1') > Kaleta\Core\ErrorLog::MAX_BYTES, substr_count((string) file_get_contents(Kaleta\Core\ErrorLog::$path), "\n")];
fclose($r332Held);
Kaleta\Core\ErrorLog::$path = $r332Saved;
array_map('unlink', glob($r332Dir . '/*') ?: []);
@rmdir($r332Dir);
check('3.32: a rotated log keeps its history in .1 when a second request rotates late', $r332State, [true, 1]);

