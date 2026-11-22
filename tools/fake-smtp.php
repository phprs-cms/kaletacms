<?php
/**
 * Kaleta – a fake SMTP server for tests: accepts every message and writes it to <dir>/<n>.eml (the envelope recipient on the
 * first line as "X-Rcpt-To:"). Recipients whose address contains a word are answered at RCPT as a real server would (3.51 –
 * Core\Mail::lastResult, the newsletter brake and the bounce register):
 *   "odmitnout"  550 5.1.1 (no such mailbox)      "blokovano"  550 5.7.1 (the sender is refused)
 *   "docasne"    451 4.7.0 (try again later)       "odesilatel" 550 5.1.8 (the sender's address is refused)
 * and "data554" is accepted at RCPT but its message is refused after DATA (554 5.6.0). A refused connection is tested with a
 * port nobody listens on.
 * No TLS and no login – set smtp_encryption to "zadne" and leave smtp_user empty.
 *   php tools/fake-smtp.php <port> <dir>
 */

declare(strict_types=1);

[$port, $dir] = [(int) ($argv[1] ?? 2525), $argv[2] ?? sys_get_temp_dir() . '/kaleta-smtp'];
@mkdir($dir, 0777, true);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error) ?: exit("fake-smtp: $error\n");
$count = count(glob($dir . '/*.eml') ?: []);

while ($client = @stream_socket_accept($server, -1)) {
    $say = fn (string $line) => fwrite($client, $line . "\r\n");
    $say('220 fake-smtp ready');
    $to = [];
    while (($line = fgets($client)) !== false) {
        $command = strtoupper(substr(trim($line), 0, 4));
        if ($command === 'EHLO' || $command === 'HELO') {
            $say('250-fake-smtp');
            $say('250 8BITMIME');
        } elseif ($command === 'MAIL' || $command === 'RSET' || $command === 'NOOP') {
            $to = $command === 'MAIL' || $command === 'RSET' ? [] : $to;
            $say('250 OK');
        } elseif ($command === 'RCPT') {
            $address = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            $refusal = match (true) {
                str_contains($address, 'odmitnout') => '550 5.1.1 Mailbox unavailable',
                str_contains($address, 'blokovano') => '550 5.7.1 Sender blocked',
                str_contains($address, 'docasne') => '451 4.7.0 Try again later',
                str_contains($address, 'odesilatel') => '550 5.1.8 Sender address rejected',
                default => '',
            };
            if ($refusal !== '') {
                $say($refusal);
            } else {
                $to[] = $address;
                $say('250 OK');
            }
        } elseif ($command === 'DATA') {
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($row = fgets($client)) !== false && rtrim($row, "\r\n") !== '.') {
                $data .= str_starts_with($row, '..') ? substr($row, 1) : $row;
            }
            if (array_filter($to, fn (string $address): bool => str_contains($address, 'data554')) !== []) {
                $say('554 5.6.0 Message content rejected');
            } else {
                file_put_contents(sprintf('%s/%04d.eml', $dir, ++$count), 'X-Rcpt-To: ' . implode(', ', $to) . "\r\n" . $data);
                $say('250 OK queued');
            }
        } elseif ($command === 'QUIT') {
            $say('221 Bye');
            break;
        } else {
            $say('502 Not implemented');
        }
    }
    fclose($client);
}
