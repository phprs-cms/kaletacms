<?php

declare(strict_types=1);

/* ---------- 3.51 R0.3: Mail::lastResult – the stage and the server's reply of the last delivery, against tools/fake-smtp.php ---------- */
use Kaleta\Core\Mail as R792Mail;
use Kaleta\Core\Settings as R792Settings;

$r792Dir = sys_get_temp_dir() . '/kaleta-r792-' . bin2hex(random_bytes(4));
$r792FreePort = function (): int {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    if ($probe === false) {
        return 0;
    }
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    return $port;
};
$r792Port = $r792FreePort();
$r792Sink = proc_open([PHP_BINARY, KALETA_ROOT . '/tools/fake-smtp.php', (string) $r792Port, $r792Dir], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $r792Pipes);
for ($r792I = 0; $r792I < 50 && ($r792Up = @stream_socket_client('tcp://127.0.0.1:' . $r792Port, $r792No, $r792Err, 0.2)) === false; $r792I++) {
    usleep(100000);
}
if (isset($r792Up) && $r792Up !== false) {
    fclose($r792Up);
}
$r792Settings = function (int $port): R792Settings {
    $s = new R792Settings(new Kaleta\Core\Db('invalid-r792:', '', ''));
    (new ReflectionProperty(R792Settings::class, 'values'))->setValue($s, ['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port,
        'smtp_encryption' => 'zadne', 'smtp_user' => '', 'mail_from' => 'web@example.com', 'site_name' => 'R792', 'smtp_last_ok' => date('Y-m-d H:i:s') . '|127.0.0.1']);
    (new ReflectionProperty(R792Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(R792Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));

    return $s;
};
$r792Site = $r792Settings($r792Port);
$r792Try = function (string $to) use ($r792Site): array {
    $ok = R792Mail::deliverNow($r792Site, $to, 'R792', 'Text.', '');

    return ['sent' => $ok] + R792Mail::lastResult();
};
$r792Results = [
    $r792Try('ok-r792@example.com'),
    $r792Try('odmitnout-r792@example.com'),
    $r792Try('blokovano-r792@example.com'),
    $r792Try('docasne-r792@example.com'),
    $r792Try('odesilatel-r792@example.com'),
    $r792Try('data554-r792@example.com'),
    $r792Try('ok2-r792@example.com'),
    $r792Try('not an address'),
];
R792Mail::close();
check('3.51 R0.3: lastResult() – delivered (body 250), 550 5.1.1 / 550 5.7.1 / 451 4.7.0 / 550 5.1.8 at RCPT, 554 5.6.0 after DATA, a delivery after a failure starts clean, an invalid recipient is stage local', $r792Results, [
    ['sent' => true, 'ok' => true, 'stage' => 'body', 'code' => 250, 'enhanced' => ''],
    ['sent' => false, 'ok' => false, 'stage' => 'rcpt', 'code' => 550, 'enhanced' => '5.1.1'],
    ['sent' => false, 'ok' => false, 'stage' => 'rcpt', 'code' => 550, 'enhanced' => '5.7.1'],
    ['sent' => false, 'ok' => false, 'stage' => 'rcpt', 'code' => 451, 'enhanced' => '4.7.0'],
    ['sent' => false, 'ok' => false, 'stage' => 'rcpt', 'code' => 550, 'enhanced' => '5.1.8'],
    ['sent' => false, 'ok' => false, 'stage' => 'body', 'code' => 554, 'enhanced' => '5.6.0'],
    ['sent' => true, 'ok' => true, 'stage' => 'body', 'code' => 250, 'enhanced' => ''],
    ['sent' => false, 'ok' => false, 'stage' => 'local', 'code' => 0, 'enhanced' => ''],
]);
// a reused connection: two deliveries in a row, the second one goes through RSET
$r792Reuse = [$r792Try('one-r792@example.com'), $r792Try('two-r792@example.com')];
R792Mail::close();
check('3.51 R0.3: two deliveries over one connection (the second after RSET) both end at body 250; the fake server stored the delivered ones only', [
    array_column($r792Reuse, 'stage'), count(glob($r792Dir . '/*.eml') ?: [])], [['body', 'body'], 4]);
// a refused connection: a port nobody listens on
$r792Closed = $r792Settings($r792FreePort());
R792Mail::deliverNow($r792Closed, 'x-r792@example.com', 'R792', 'Text.', '');
check('3.51 R0.3: a refused connection is stage connect without a reply code; Mail::$error still says why', [R792Mail::lastResult(), R792Mail::$error !== ''],
    [['ok' => false, 'stage' => 'connect', 'code' => 0, 'enhanced' => ''], true]);
if (is_resource($r792Sink)) {
    proc_terminate($r792Sink);
    proc_close($r792Sink);
}
array_map('unlink', glob($r792Dir . '/*') ?: []);
@rmdir($r792Dir);
