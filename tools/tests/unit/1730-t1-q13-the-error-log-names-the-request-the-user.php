<?php

declare(strict_types=1);

use Kaleta\Core\Files;

/* ---------- T1 (Q13): the error log names the request, the user, the version and a short trace; capped and rotated ---------- */
$t13Log = sys_get_temp_dir() . '/kaleta-t13-errors-' . getmypid() . '.log';
@unlink($t13Log); @unlink($t13Log . '.1');
Kaleta\Core\ErrorLog::$path = $t13Log;
$t13Deep = static function (int $n) use (&$t13Deep): never {
    if ($n > 0) {
        $t13Deep($n - 1);
    }
    throw new RuntimeException('Duplicate entry jana.novakova@example.cz for key email, token ' . str_repeat('ab12', 8) . ' in /var/x');
};
try {
    $t13Deep(12);
} catch (RuntimeException $t13E) {
}
$t13Entry = Kaleta\Core\ErrorLog::exception($t13E);
check('T1 Q13: an exception entry has class, message, place, context (cli here) with the version and the first eight frames without arguments', [
    str_starts_with($t13Entry, 'RuntimeException: Duplicate entry [e-mail] for key email, token [redacted] in /var/x in tools/tests/unit/1730-t1-q13-the-error-log-names-the-request-the-user.php:'),
    str_contains($t13Entry, ' · cli · Kaleta ' . KALETA_VERSION . ' · trace: #0 tools/tests/unit/1730-t1-q13-the-error-log-names-the-request-the-user.php:'), substr_count($t13Entry, ' | #'), str_contains($t13Entry, '#8 '), (bool) preg_match('/ \| … \d+ more$/', $t13Entry),
    str_contains($t13Entry, 'jana.novakova'), str_contains($t13Entry, str_repeat('ab12', 8)), str_contains($t13Entry, "\n")],
    [true, true, 7, false, true, false, false, false]);
// a web request: method and path only – never the query, which can hold a token or a preview key
$t13Server = $_SERVER;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/admin.php?module=settings&token=' . str_repeat('cd34', 8);
check('T1 Q13: a web request is logged as method and path – the query (a token here) is left out', [Kaleta\Core\ErrorLog::context('fpm-fcgi')], ['POST /admin.php · Kaleta ' . KALETA_VERSION]);
check('T1 Q13: clean() keeps ordinary words, class names and paths; masks e-mails and token-like strings', [
    Kaleta\Core\ErrorLog::clean('SQLSTATE[42S02]: Base table ka_poptavky not found in system/src/Core/Contacts.php'),
    Kaleta\Core\ErrorLog::clean('key ' . bin2hex(random_bytes(16)) . ' for x@y.cz'), mb_strlen(Kaleta\Core\ErrorLog::clean(str_repeat('slovo ', 200)))],
    ['SQLSTATE[42S02]: Base table ka_poptavky not found in system/src/Core/Contacts.php', 'key [redacted] for [e-mail]', 500]);
$_SERVER = $t13Server;
// the cap: a log over MAX_BYTES moves to .1 before the next entry; the older .1 is replaced; one line per entry
file_put_contents($t13Log, str_repeat("[2026-01-01T00:00:00+01:00] old\n", (int) ceil((Kaleta\Core\ErrorLog::MAX_BYTES + 10) / 32)));
file_put_contents($t13Log . '.1', 'oldest');
Kaleta\Core\ErrorLog::write("first line\nsecond line");
check('T1 Q13: past 1 MB the log is rotated to chyby.log.1 (the older copy dropped), the new entry starts a fresh file as one line', [
    (string) file_get_contents($t13Log), filesize($t13Log . '.1') > Kaleta\Core\ErrorLog::MAX_BYTES, count(Kaleta\Core\ErrorLog::files())],
    ['[' . date('c') . "] first line second line\n", true, 2]);
check('T1 Q13: the exception handler, the migration error and Clear the log use Core\ErrorLog', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/App.php'), 'ErrorLog::write(ErrorLog::exception($e));'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Migration.php'), "ErrorLog::write('The database migration failed: '"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php'), 'foreach (\Kaleta\Core\ErrorLog::files() as $i => $log)')],
    [true, true, true]);
Kaleta\Core\ErrorLog::$path = null;
@unlink($t13Log); @unlink($t13Log . '.1');

