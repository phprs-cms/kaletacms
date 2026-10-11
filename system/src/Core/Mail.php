<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Sending e-mails: either with the server's mail() function or through the site's own SMTP server ("Nastavení → Pošta",
 * Settings → Mail).
 *
 * SMTP is more reliable - messages go out from a verified mailbox (SPF, DKIM) and do not end up in spam. The client is
 * deliberately small and has no libraries: STARTTLS or SSL, AUTH LOGIN/PLAIN login, one connection is reused while
 * processing the mail queue.
 */
final class Mail
{
    /** Text of the last error (for the test e-mail and System status). */
    public static string $error = '';

    /** @var resource|null open SMTP connection */
    private static $connection = null;

    /** How long until a failed send is retried (minutes); after the last attempt the message stays in the queue as failed. */
    private const array RETRY_DELAYS = [5, 30, 120, 720];

    /**
     * Sends the message right away. When that fails (SMTP outage), it stores it in the queue and retries later - so
     * a registration confirmation or a new password is not lost. Every message has a log entry ("Nastavení → Pošta").
     *
     * @param array<string, string> $headers extra headers (e.g. List-Unsubscribe)
     * @param bool $queueOnFailure false = a one-off message that is not retried on error (test e-mail)
     */
    public static function send(Settings $siteSettings, string $recipient, string $subject, string $text, string $html = '', array $headers = [], bool $queueOnFailure = true): bool
    {
        if ($html === '' && Language::isRtl(Language::code())) {
            $html = self::rightToLeft($text, Language::code()); // a plain message in Arabic reads from the right in every mail client
        }
        $ok = self::deliver($siteSettings, $recipient, $subject, $text, $html, $headers);
        $error = self::$error;
        try {
            $siteSettings->db()->insert('posta', [
                'komu' => mb_substr($recipient, 0, 190), 'predmet' => mb_substr($subject, 0, 255), 'vytvoreno' => date('Y-m-d H:i:s'), 'pokusu' => 1,
                'odeslano' => $ok ? date('Y-m-d H:i:s') : null, 'chyba' => mb_substr($error, 0, 255),
                'telo' => $ok || !$queueOnFailure ? null : json_encode(['text' => $text, 'html' => $html, 'hlavicky' => $headers], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'dalsi_pokus' => $ok || !$queueOnFailure ? null : date('Y-m-d H:i:s', time() + self::RETRY_DELAYS[0] * 60),
            ]);
            if (random_int(1, 50) === 1) {
                $siteSettings->db()->run('DELETE FROM {posta} WHERE vytvoreno < NOW() - INTERVAL 30 DAY');
            }
        } catch (\Throwable) {
            // the mail log must not break sending (e.g. before the migration runs, the table does not exist yet)
        }
        self::$error = $error;

        return $ok;
    }

    /**
     * The HTML part of a plain message in a right-to-left language: the same text with dir="rtl", the lines kept and the
     * addresses as links. Mail clients guess the direction of plain text line by line – a line that starts with a Latin name
     * or a number would otherwise turn to the left.
     */
    public static function rightToLeft(string $text, string $language): string
    {
        $body = preg_replace_callback('~https?://(?:(?!&quot;|&#039;)[^\s<>"])+~', fn (array $m): string => '<a href="' . $m[0] . '" dir="ltr">' . $m[0] . '</a>', e($text)) ?? e($text);

        return '<!DOCTYPE html><html lang="' . e(Language::tag($language)) . '" dir="rtl"><head><meta charset="utf-8"></head>'
            . '<body><div dir="rtl" style="font:15px/1.6 Tahoma, Arial, sans-serif; text-align: right; white-space: pre-wrap;">' . $body . '</div></body></html>';
    }

    /** A message was queued by later() in this request: afterResponse() sends it. */
    private static bool $pending = false;

    /**
     * Puts the message into the queue (ka_posta) and sends it right after the response (afterResponse in admin.php; the
     * background jobs retry it like any queued message). The answer to the request then takes as long whether a message
     * was sent or not – the password reset does not reveal by its timing which accounts exist (3.3.3, N59).
     */
    public static function later(Settings $siteSettings, string $recipient, string $subject, string $text): void
    {
        try {
            $siteSettings->db()->insert('posta', [
                'komu' => mb_substr($recipient, 0, 190), 'predmet' => mb_substr($subject, 0, 255), 'vytvoreno' => date('Y-m-d H:i:s'), 'pokusu' => 0,
                'odeslano' => null, 'chyba' => '', 'dalsi_pokus' => date('Y-m-d H:i:s'),
                'telo' => json_encode(['text' => $text, 'html' => '', 'hlavicky' => []], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            self::$pending = true;
        } catch (\Throwable) {
            self::send($siteSettings, $recipient, $subject, $text); // without the queue table (before a migration) right away
        }
    }

    /** After the page was sent (admin.php): delivers what later() queued in this request; what fails waits for a retry. */
    public static function afterResponse(App $app): void
    {
        if (!self::$pending) {
            return;
        }
        self::$pending = false;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            self::processQueue($app->settings());
        } catch (\Throwable) {
            // the background jobs try again
        }
    }

    /** Another attempt at messages waiting in the queue; called from background tasks. Returns the number sent. */
    public static function processQueue(Settings $siteSettings, int $maxCount = 10): int
    {
        $db = $siteSettings->db();
        $sent = 0;
        foreach ($db->all('SELECT * FROM {posta} WHERE odeslano IS NULL AND telo IS NOT NULL AND dalsi_pokus <= NOW() ORDER BY idp LIMIT ' . max(1, $maxCount)) as $z) {
            $body = json_decode((string) $z['telo'], true) ?: [];
            $attempt = (int) $z['pokusu'] + 1;
            // claim the message first: a concurrent request (the background jobs, Mail::afterResponse) then does not send it a second time
            if ($db->run('UPDATE {posta} SET pokusu = ?, dalsi_pokus = ? WHERE idp = ? AND pokusu = ? AND odeslano IS NULL',
                [$attempt, date('Y-m-d H:i:s', time() + (self::RETRY_DELAYS[$attempt - 1] ?? 0) * 60), $z['idp'], $z['pokusu']])->rowCount() === 0) {
                continue;
            }
            if (self::deliver($siteSettings, $z['komu'], $z['predmet'], (string) ($body['text'] ?? ''), (string) ($body['html'] ?? ''), (array) ($body['hlavicky'] ?? []))) {
                $db->update('posta', ['odeslano' => date('Y-m-d H:i:s'), 'telo' => null, 'dalsi_pokus' => null, 'chyba' => ''], ['idp' => $z['idp']]);
                $sent++;
            } else {
                $end = !isset(self::RETRY_DELAYS[$attempt - 1]);
                $db->update('posta', ['chyba' => mb_substr(self::$error, 0, 255)] + ($end ? ['telo' => null, 'dalsi_pokus' => null] : []), ['idp' => $z['idp']]);
                if ($end) {
                    // the subject and the error, not the recipient (2.8, Core\Events)
                    Events::record($db, 'mail.failed', 'error', mb_substr(t('E-mail “%s” could not be sent: %s', (string) ($z['predmet'] ?? ''), self::$error), 0, 255), ['mail' => (int) $z['idp']]);
                }
            }
        }

        return $sent;
    }

    /**
     * Sends one message right away, without the mail log and the retry queue – newsletters keep their own queue and
     * their recipients must not end up in the log (Core\Mailing).
     *
     * @param array<string, string> $headers
     */
    public static function deliverNow(Settings $siteSettings, string $recipient, string $subject, string $text, string $html, array $headers = []): bool
    {
        return self::deliver($siteSettings, $recipient, $subject, $text, $html, $headers);
    }

    /** @param array<string, string> $headers */
    private static function deliver(Settings $siteSettings, string $recipient, string $subject, string $text, string $html = '', array $headers = []): bool
    {
        self::$error = '';
        if (Demo::active()) {
            self::$error = 'Sending e-mail is switched off in the public demo.';

            return false; // nobody uses the demo to send e-mail to strangers
        }
        $from = $siteSettings->get('mail_from') !== '' ? $siteSettings->get('mail_from') : $siteSettings->get('site_email');
        if ($from === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\x00-\x20\x7F"<>]/', $recipient)) {
            self::$error = $from === '' ? 'Neither the site e-mail (Settings → General) nor a sender address is filled in.' : 'The recipient address is not valid.';

            return false;
        }
        $displayName = '=?UTF-8?B?' . base64_encode($siteSettings->get('site_name')) . '?=';
        $h = ['From' => "{$displayName} <{$from}>", 'MIME-Version' => '1.0'] + $headers;
        if ($siteSettings->get('mail_reply_to') !== '') {
            $h['Reply-To'] = $siteSettings->get('mail_reply_to');
        }
        if ($html === '') {
            $h['Content-Type'] = 'text/plain; charset=utf-8';
            $h['Content-Transfer-Encoding'] = 'base64';
            $body = chunk_split(base64_encode($text));
        } else {
            $boundary = 'kaleta-' . bin2hex(random_bytes(8));
            $h['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
                . "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--{$boundary}--\r\n";
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $rows = [];
        foreach ($h as $name => $value) {
            $rows[] = $name . ': ' . str_replace(["\r", "\n"], '', $value); // headers must not be splittable by an injected line break
        }

        if ($siteSettings->get('mail_mode') === 'smtp' && $siteSettings->get('smtp_host') !== '') {
            try {
                self::smtp($siteSettings, $from, $recipient, array_merge(['Date: ' . date('r'), 'To: ' . $recipient, 'Subject: ' . $encodedSubject,
                    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr((string) strrchr($from, '@'), 1) ?: 'localhost') . '>'], $rows), $body);

                return true;
            } catch (\RuntimeException $e) {
                self::$error = $e->getMessage();
                self::close();

                return false;
            }
        }
        if (!function_exists('mail')) {
            self::$error = 'The mail() function is disabled on the server – set up sending via SMTP.';

            return false;
        }
        $ok = @mail($recipient, $encodedSubject, $body, implode("\r\n", $rows));
        if (!$ok) {
            self::$error = 'The server refused to send the message (the mail() function failed).';
        }

        return $ok;
    }

    /** @param list<string> $headers */
    private static function smtp(Settings $siteSettings, string $from, string $recipient, array $headers, string $body): void
    {
        if (!is_resource(self::$connection)) {
            self::connect($siteSettings);
        } else {
            self::statement('RSET', [250]);
        }
        self::statement('MAIL FROM:<' . $from . '>', [250]);
        self::statement('RCPT TO:<' . $recipient . '>', [250, 251]);
        self::statement('DATA', [354]);
        // a line starting with a dot gets the dot doubled, a lone dot ends the message
        $message = preg_replace('/^\./m', '..', implode("\r\n", $headers) . "\r\n\r\n" . $body) ?? '';
        self::statement(rtrim($message, "\r\n") . "\r\n.", [250]);
    }

    private static function connect(Settings $siteSettings): void
    {
        $host = $siteSettings->get('smtp_host');
        $encryption = $siteSettings->get('smtp_encryption');
        $port = $siteSettings->int('smtp_port') ?: ($encryption === 'ssl' ? 465 : 587);
        if (!preg_match('/^[a-z0-9.-]+$/iD', $host)) {
            throw new \RuntimeException('The SMTP server address is not valid.');
        }
        $connection = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $number, $error, 10);
        if ($connection === false) {
            throw new \RuntimeException(t('Could not connect to the SMTP server %s. Check the address, port and encryption; some hosts block outgoing SMTP.', "{$host}:{$port}" . ($error !== '' ? " ({$error})" : '')));
        }
        stream_set_timeout($connection, 15);
        self::$connection = $connection;
        self::response([220]);
        $me = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost');
        $options = self::statement($me, [250]);
        if ($encryption === 'tls') {
            self::statement('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new \RuntimeException('The encrypted connection (STARTTLS) could not be established – the server probably has an invalid certificate.');
            }
            $options = self::statement($me, [250]);
        }
        if ($siteSettings->get('smtp_user') !== '') {
            try {
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $options)) {
                    self::statement('AUTH PLAIN ' . base64_encode("\0" . $siteSettings->get('smtp_user') . "\0" . $siteSettings->get('smtp_password')), [235], true);
                } else {
                    self::statement('AUTH LOGIN', [334]);
                    self::statement(base64_encode($siteSettings->get('smtp_user')), [334], true);
                    self::statement(base64_encode($siteSettings->get('smtp_password')), [235], true);
                }
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(t('The SMTP server refused the sign-in – check the user name and password (Gmail and Seznam need an app password).') . ' ' . $e->getMessage());
            }
        }
        register_shutdown_function(self::close(...));
    }

    /** @param list<int> $expected */
    private static function statement(string $statement, array $expected, bool $secret = false): string
    {
        if (@fwrite(self::$connection, $statement . "\r\n") === false) {
            throw new \RuntimeException('The connection to the SMTP server was interrupted.');
        }

        return self::response($expected, $secret ? t('(credentials)') : strtok($statement, "\r\n "));
    }

    /** @param list<int> $expected */
    private static function response(array $expected, string $commandName = ''): string
    {
        $commandName = $commandName !== '' ? $commandName : t('the connection');
        $response = '';
        do {
            $row = fgets(self::$connection, 1024);
            if ($row === false) {
                throw new \RuntimeException('The SMTP server did not respond in time.');
            }
            $response .= $row;
        } while (isset($row[3]) && $row[3] === '-');
        if (!in_array((int) substr($response, 0, 3), $expected, true)) {
            throw new \RuntimeException(t('SMTP server reply to %s: %s', $commandName, mb_substr(trim($response), 0, 200)));
        }

        return $response;
    }

    public static function close(): void
    {
        if (is_resource(self::$connection)) {
            @fwrite(self::$connection, "QUIT\r\n");
            @fclose(self::$connection);
        }
        self::$connection = null;
    }
}
