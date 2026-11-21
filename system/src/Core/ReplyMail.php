<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * One sender of the replies the site e-mails as a person (3.49 A): a reply to an enquiry (Core\EnquiryReply), to a job
 * application (Core\ApplicationReplies) and – through sender() only – the quote e-mail (Core\QuoteDelivery), which keeps
 * its own body in the quote's language. From = the site (Mail), Reply-To = the person's own e-mail, the person's e-mail
 * signature under the "-- " line and as an HTML table below the text, the customer's message quoted under it when given.
 */
final class ReplyMail
{
    /**
     * Who sends: the name the history keeps, the Reply-To address ('' when the account has no valid e-mail) and the
     * e-mail signature (EnquiryReply::signature – null without a signed-in user or a signature).
     *
     * @param array<string, mixed>|null $user a row of ka_uzivatele; null = the signed-in user
     * @return array{name: string, reply_to: string, signature: array{text: string, html: string}|null, id: int}
     */
    public static function sender(App $app, ?array $user = null): array
    {
        $user ??= $app->auth()->user() ?? [];
        $name = trim((string) ($user['jmeno'] ?? '')) !== '' ? trim((string) $user['jmeno']) : (string) ($user['user'] ?? 'admin');
        $replyTo = Mail::addresses((string) ($user['email'] ?? ''));

        return ['name' => $name, 'reply_to' => count($replyTo) === 1 ? $replyTo[0] : '', 'signature' => EnquiryReply::signature($app, $user), 'id' => (int) ($user['idu'] ?? 0)];
    }

    /**
     * Fills placeholders: only the keys given in $vars ({{name}} for 'name'), any other {{x}} stays as it is. An empty
     * name takes the comma or the space before it along ("Dear {{name}}," – "Dear,"). Pure; the order of $vars is the
     * order of the replacements (as ApplicationReplies::fill always did).
     *
     * @param array<string, string> $vars
     */
    public static function fill(string $text, array $vars): string
    {
        if (array_key_exists('name', $vars) && trim($vars['name']) === '') {
            $text = str_replace([', {{name}}', ' {{name}}', '{{name}}'], '', $text);
        }
        $keys = array_map(fn (string $key): string => '{{' . $key . '}}', array_keys($vars));
        $values = array_map(fn (string $key, string $value): string => $key === 'name' ? trim($value) : $value, array_keys($vars), $vars);

        return str_replace($keys, $values, $text);
    }

    /**
     * Sends one reply as the signed-in user. The text and subject are taken as they are (the caller cleans them). Options:
     * quote (the customer's message under the signature), log_subject (what the mail log keeps instead of the subject),
     * attachments (Mail::send; an attachment with queue => false never waits in the queue) and queue (false = a failed
     * message is not queued for another attempt).
     *
     * @param array{quote?: string, log_subject?: string, attachments?: list<array<string, mixed>>, queue?: bool} $options
     * @return array{ok: bool, error: string, mail: int, reply_to: string, by: string, by_id: int}
     */
    public static function send(App $app, string $to, string $subject, string $text, array $options = []): array
    {
        $sender = self::sender($app);
        $replyTo = $sender['reply_to'];
        $headers = $replyTo !== '' ? ['Reply-To' => $replyTo] : [];
        $signature = $sender['signature'];
        $quote = (string) ($options['quote'] ?? '');
        $plain = $text . ($signature !== null && $signature['text'] !== '' ? "\n\n-- \n" . $signature['text'] : '') . ($quote !== '' ? "\n\n" . $quote : '');
        $html = $signature !== null ? EnquiryReply::html($text, $signature['html'], $quote) : '';
        $ok = Mail::send($app->settings(), $to, $subject, $plain, $html, $headers, (bool) ($options['queue'] ?? true), (string) ($options['log_subject'] ?? ''),
            $options['attachments'] ?? []);

        return ['ok' => $ok, 'error' => $ok ? '' : Mail::$error, 'mail' => (int) (Mail::$lastId ?? 0), 'reply_to' => $replyTo, 'by' => mb_substr($sender['name'], 0, 100), 'by_id' => $sender['id']];
    }
}
