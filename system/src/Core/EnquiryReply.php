<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * A reply to an enquiry e-mailed from the administration or by Claude (3.22, C10): the text a person wrote (or the drafted
 * reply they checked) goes through the site's mail (Mail::send – the log and the retry queue) to the enquiry's own e-mail
 * address and to nobody else. From = the site's sender, Reply-To = the e-mail of the user who sends it, their e-mail
 * signature from My account is appended. Every reply is kept on the enquiry (ka_poptavky.replies, JSON) – who, when, the
 * subject and the text, and the mail row whose state the detail shows – and in the change log and the events (without
 * the address or the text). Sending needs an SMTP server (like newsletters): the server's mail() lands in spam too often
 * for a personal answer; without one the detail keeps the mailto: link.
 */
final class EnquiryReply
{
    /** The longest reply text and subject. */
    public const int MAX_TEXT = 10000;
    public const int MAX_SUBJECT = 200;

    /** How many replies one enquiry keeps (the oldest drop out). */
    public const int KEEP = 50;

    /**
     * Why the site cannot send a reply right now, or null when it can: SMTP must be set up and a sender known.
     */
    public static function unavailable(Settings $s): ?string
    {
        if ($s->get('mail_mode') !== 'smtp' || $s->get('smtp_host') === '') {
            return 'Replies are sent only through an SMTP server – the server’s mail() function often lands in spam. Set it up in Settings → Mail, or reply from your own mail program.';
        }
        if ($s->get('mail_from') === '' && $s->get('site_email') === '') {
            return 'The site has no sender address – fill in the site e-mail (Settings → General) or the sender in Settings → Mail.';
        }

        return null;
    }

    /** The subject offered for a reply: "Re: " and the form name (an enquiry has no subject of its own). @param array<string, mixed> $enquiry */
    public static function subject(array $enquiry): string
    {
        $name = trim((string) ($enquiry['formular'] ?? ''));

        return 'Re: ' . ($name !== '' ? $name : t('Enquiry'));
    }

    /**
     * The replies kept on an enquiry, newest last, each with its delivery state read from the mail log while it is there
     * (sent | queued – waiting for another attempt | failed); after the log forgets the row the state stored at sending stays.
     *
     * @param array<string, mixed> $enquiry a row of ka_poptavky
     * @return list<array{at: string, by: string, by_id: int, to: string, reply_to: string, subject: string, text: string, mail: int, status: string, error: string, via: string}>
     */
    public static function history(Db $db, array $enquiry): array
    {
        $replies = self::decode($enquiry['replies'] ?? null);
        $mailIds = array_values(array_filter(array_map(fn (array $r): int => $r['mail'], $replies)));
        $log = $mailIds === [] ? [] : $db->all('SELECT idp, odeslano, telo, chyba FROM {posta} WHERE idp IN (' . implode(',', $mailIds) . ')');
        $byId = array_column($log, null, 'idp');
        foreach ($replies as &$r) {
            $row = $byId[$r['mail']] ?? null;
            if ($row !== null) {
                $r['status'] = $row['odeslano'] !== null ? 'sent' : ($row['telo'] !== null ? 'queued' : 'failed');
                $r['error'] = $r['status'] === 'sent' ? '' : (string) $row['chyba'];
            }
        }

        return $replies;
    }

    /**
     * The stored list, cleaned: every entry with all its keys (an older or a damaged value gives an empty list).
     *
     * @return list<array{at: string, by: string, by_id: int, to: string, reply_to: string, subject: string, text: string, mail: int, status: string, error: string, via: string}>
     */
    public static function decode(mixed $json): array
    {
        $list = is_string($json) && $json !== '' ? json_decode($json, true) : [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = ['at' => (string) ($r['at'] ?? ''), 'by' => (string) ($r['by'] ?? ''), 'by_id' => (int) ($r['by_id'] ?? 0), 'to' => (string) ($r['to'] ?? ''),
                'reply_to' => (string) ($r['reply_to'] ?? ''), 'subject' => (string) ($r['subject'] ?? ''), 'text' => (string) ($r['text'] ?? ''), 'mail' => (int) ($r['mail'] ?? 0),
                'status' => in_array($r['status'] ?? '', ['sent', 'queued', 'failed'], true) ? (string) $r['status'] : 'failed', 'error' => (string) ($r['error'] ?? ''),
                'via' => ($r['via'] ?? '') === 'mcp' ? 'mcp' : 'admin'];
        }

        return $out;
    }

    /**
     * The replies of an anonymised enquiry (Core\Privacy): who and when and how it went stay for the history, the address,
     * the subject and the text go.
     */
    public static function anonymised(mixed $json): ?string
    {
        $replies = self::decode($json);
        if ($replies === []) {
            return null;
        }

        return (string) json_encode(array_map(fn (array $r): array => ['to' => '', 'reply_to' => '', 'subject' => '', 'text' => ''] + $r, $replies), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sends a reply to the enquiry's e-mail address as the signed-in user (an administration user with the Enquiries section,
     * or the user of an MCP connection). Throws \DomainException with a message for the person when it cannot be sent.
     *
     * @param bool $resolve true = the enquiry becomes resolved (the default in the form); otherwise it stays as it is
     * @param 'admin'|'mcp' $via
     * @return array{status: 'sent'|'queued', error: string, resolved: bool, to: string}
     */
    public static function send(App $app, int $idp, string $subject, string $text, bool $resolve, string $via = 'admin'): array
    {
        $db = $app->db();
        $s = $app->settings();
        $enquiry = $db->one('SELECT idp, email, formular, stav, prirazeno, replies, anonymizovano FROM {poptavky} WHERE idp = ?', [$idp]);
        if ($enquiry === null) {
            throw new \DomainException('The enquiry does not exist.');
        }
        // only the enquiry's own address, exactly one and valid: Mail::addresses refuses lists, quotes and line breaks
        $to = Mail::addresses((string) $enquiry['email']);
        if (count($to) !== 1 || $enquiry['anonymizovano'] !== null) {
            throw new \DomainException('This enquiry has no e-mail address to reply to – reply by phone or another way, and note it in the internal note.');
        }
        $reason = self::unavailable($s);
        if ($reason !== null) {
            throw new \DomainException($reason);
        }
        $text = trim(mb_substr(str_replace("\r\n", "\n", $text), 0, self::MAX_TEXT));
        if (mb_strlen($text) < 2) {
            throw new \DomainException('Write the reply first.');
        }
        // a subject is one line: a line break could never become a header (Mail strips them too)
        $subject = trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject), 0, self::MAX_SUBJECT));
        $subject = $subject !== '' ? $subject : self::subject($enquiry);

        $user = $app->auth()->user() ?? [];
        $name = trim((string) ($user['jmeno'] ?? '')) !== '' ? trim((string) $user['jmeno']) : (string) ($user['user'] ?? 'admin');
        $replyTo = Mail::addresses((string) ($user['email'] ?? ''));
        $headers = count($replyTo) === 1 ? ['Reply-To' => $replyTo[0]] : [];

        // the user's own e-mail signature (My account → My e-mail signature): plain text under the "-- " line, HTML below the text
        $record = $user !== [] ? \Kaleta\Builder\TeamRecord::forUser($db, $user) : null;
        $signature = $record !== null ? \Kaleta\Builder\EmailSignature::forItem($app, $record['collection'], $record['item']) : null;
        $plain = $text . ($signature !== null && $signature['text'] !== '' ? "\n\n-- \n" . $signature['text'] : '');
        $html = $signature !== null ? self::html($text, $signature['html']) : '';

        $ok = Mail::send($s, $to[0], $subject, $plain, $html, $headers);
        $error = $ok ? '' : Mail::$error;
        $entry = ['at' => date('Y-m-d H:i:s'), 'by' => mb_substr($name, 0, 100), 'by_id' => (int) ($user['idu'] ?? 0), 'to' => $to[0], 'reply_to' => $headers['Reply-To'] ?? '',
            'subject' => $subject, 'text' => $text, 'mail' => (int) (Mail::$lastId ?? 0), 'status' => $ok ? 'sent' : 'queued', 'error' => mb_substr($error, 0, 255), 'via' => $via];
        $replies = array_slice([...self::decode($enquiry['replies']), $entry], -self::KEEP);
        $changes = ['replies' => (string) json_encode($replies, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
        if ($resolve) {
            $changes['stav'] = 2;
        } elseif ((int) $enquiry['stav'] === 0) {
            $changes['stav'] = 1;
        }
        if ($enquiry['prirazeno'] === null && (int) ($user['idu'] ?? 0) > 0) {
            $changes['prirazeno'] = (int) $user['idu']; // who answered handles it, unless someone already does
        }
        $db->update('poptavky', $changes, ['idp' => $idp]);
        \Kaleta\Admin\ChangeLog::write($app, 'enquiries', 'reply', '#' . $idp . ($ok ? '' : ' (queued)'));
        // the enquiry and who sent it – never the address or the text (Core\Events)
        Events::record($db, 'enquiry.replied', $ok ? 'info' : 'warning', mb_substr(t($ok ? '%s replied to enquiry #%d.' : '%s replied to enquiry #%d – the e-mail waits in the queue for another attempt.', $name, $idp), 0, 255),
            ['enquiry' => $idp, 'via' => $via]);

        return ['status' => $ok ? 'sent' : 'queued', 'error' => $error, 'resolved' => $resolve, 'to' => $to[0]];
    }

    /** The HTML part when a signature goes with the reply: the text as written (line breaks kept), the signature table below. */
    private static function html(string $text, string $signatureHtml): string
    {
        $dir = Language::isRtl(Language::code()) ? ' dir="rtl"' : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body' . $dir . '>'
            . '<div style="font:15px/1.6 system-ui, -apple-system, \'Segoe UI\', Roboto, Arial, sans-serif; color:#1f2328; white-space:pre-wrap;">' . e($text) . '</div>'
            . '<div style="margin-top:24px;">' . $signatureHtml . '</div></body></html>';
    }
}
