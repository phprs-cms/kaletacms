<?php

declare(strict_types=1);

/* ---------- 3.50.1 A4: a copy that was only to be queued is sent once when the queue cannot be written (CODE-l2) ---------- */
use Kaleta\Core\Mail as A608Mail;
use Kaleta\Core\Settings as A608Settings;

// the local sink: tools/fake-smtp.php on a free port in a temporary folder; settings over a database that cannot be reached
// (the queue insert throws), the mail settings given as the store would have them
$a608Dir = sys_get_temp_dir() . '/kaleta-a608-' . bin2hex(random_bytes(4));
$a608Probe = stream_socket_server('tcp://127.0.0.1:0');
$a608Port = $a608Probe === false ? 0 : (int) substr((string) strrchr((string) stream_socket_get_name($a608Probe, false), ':'), 1);
if ($a608Probe !== false) {
    fclose($a608Probe);
}
$a608Sink = proc_open([PHP_BINARY, KALETA_ROOT . '/tools/fake-smtp.php', (string) $a608Port, $a608Dir], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $a608Pipes);
for ($a608I = 0; $a608I < 50 && ($a608Up = @stream_socket_client('tcp://127.0.0.1:' . $a608Port, $a608No, $a608Err, 0.2)) === false; $a608I++) {
    usleep(100000);
}
if (isset($a608Up) && $a608Up !== false) {
    fclose($a608Up);
}
$a608Settings = new A608Settings(new Kaleta\Core\Db('invalid-a608:', '', ''));
(new ReflectionProperty(A608Settings::class, 'values'))->setValue($a608Settings, ['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $a608Port,
    'smtp_encryption' => 'zadne', 'smtp_user' => '', 'mail_from' => 'web@example.com', 'site_name' => 'A608', 'smtp_last_ok' => date('Y-m-d H:i:s') . '|127.0.0.1']);
(new ReflectionProperty(A608Settings::class, 'lazyRead'))->setValue($a608Settings, array_fill_keys((new ReflectionMethod(A608Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));
$a608Count = fn (): int => count(glob($a608Dir . '/*.eml') ?: []);
$a608Queued = A608Mail::send($a608Settings, 'copy-a608@example.com', 'Copy A608', 'The second copy.', '', [], true, '', [], 0, false);
$a608AfterCopy = $a608Count();
$a608Failed = A608Mail::send($a608Settings, 'odmitnout-a608@example.com', 'Refused A608', 'Refused.', '', [], true, '', [], 0, true);
$a608AfterFailed = $a608Count();
A608Mail::close();
check('3.50.1 A4: with the queue insert failing, a copy meant only for the queue ($tryNow false) is delivered once through the local sink; a send tried now that fails is not tried again', [
    $a608Port > 0, $a608Queued, $a608AfterCopy, str_contains((string) file_get_contents((glob($a608Dir . '/*.eml') ?: [''])[0] ?: '/dev/null'), 'copy-a608@example.com'), $a608Failed, $a608AfterFailed],
    [true, true, 1, true, false, 1]);
if (is_resource($a608Sink)) {
    proc_terminate($a608Sink);
    proc_close($a608Sink);
}
array_map('unlink', glob($a608Dir . '/*') ?: []);
@rmdir($a608Dir);
