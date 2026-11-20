<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Replies to job applicants (3.34 B, split out of Core\Applications in 3.47): the three templates and the administrator's
 * own texts per language (TEMPLATES, storedTemplates, template, compose), the automatic "received" confirmation
 * (confirmReceipt – no text the visitor typed, safeName), a reply as the signed-in user (reply) and the bulk rejection
 * (rejectWithTemplate). Core\Applications keeps a forward for every public member.
 */
final class ApplicationReplies
{
    /* ---------- replies and templates (3.34 B) ---------- */

    /**
     * The reply templates (owner request B: a "received" confirmation, an invitation and a rejection), English source texts
     * of the visitor dictionaries – each language of the site has them in its own words (system/jazyky/<code>.php). The
     * administrator may rewrite them per language (application_templates, the Settings tab); a language without its own text
     * uses these. Placeholders: {{name}}, {{position}}, {{company}}.
     */
    public const array TEMPLATES = [
        'received' => ['We have received your application', "Hello {{name}},\n\nthank you for your application for {{position}}. We have received it and will get back to you as soon as we have looked at it.\n\nKind regards\n{{company}}"],
        'interview' => ['Invitation to an interview – {{position}}', "Hello {{name}},\n\nthank you for your application for {{position}}. We would like to invite you to an interview. Please reply to this e-mail with a few times that suit you.\n\nKind regards\n{{company}}"],
        'rejection' => ['Your application – {{position}}', "Hello {{name}},\n\nthank you for your interest and for your application for {{position}}. After careful consideration we have decided not to continue with your application this time. We wish you every success.\n\nKind regards\n{{company}}"],
    ];

    /** template key => its label in the administration. */
    public const array TEMPLATE_LABELS = ['received' => 'Application received', 'interview' => 'Invitation to an interview', 'rejection' => 'Rejection'];

    /** The longest template text and subject an administrator may store. */
    public const int TEMPLATE_MAX = 5000;

    /**
     * The stored templates (application_templates): language code => template key => {subject, text}; anything that is not
     * a known language code, key or a pair of strings is dropped. Pure.
     *
     * @return array<string, array<string, array{subject: string, text: string}>>
     */
    public static function storedTemplates(string $json): array
    {
        $list = json_decode($json, true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $language => $templates) {
            if (!is_string($language) || preg_match('/^' . Language::TAG . '$/D', $language) !== 1 || !is_array($templates)) {
                continue;
            }
            foreach (self::TEMPLATES as $key => $_) {
                $t = $templates[$key] ?? null;
                if (is_array($t) && is_string($t['subject'] ?? null) && is_string($t['text'] ?? null) && trim($t['text']) !== '') {
                    $out[$language][$key] = ['subject' => mb_substr(trim($t['subject']), 0, EnquiryReply::MAX_SUBJECT), 'text' => mb_substr(str_replace("\r\n", "\n", $t['text']), 0, self::TEMPLATE_MAX)];
                }
            }
        }

        return $out;
    }

    /**
     * One template in a language (a code of the site's languages): the administrator's own text, otherwise the built-in one
     * in that language. Placeholders stay unfilled (fill()).
     *
     * @return array{subject: string, text: string, own: bool}
     */
    public static function template(Settings $s, string $key, string $language): array
    {
        $own = self::storedTemplates($s->get('application_templates'))[$language][$key] ?? null;
        if ($own !== null) {
            return $own + ['own' => true];
        }
        [$subject, $text] = self::TEMPLATES[$key] ?? self::TEMPLATES['received'];

        return Language::runWith($language, fn (): array => ['subject' => t($subject), 'text' => t($text), 'own' => false]);
    }

    /**
     * A template with its placeholders filled: {{name}} (an empty name takes its comma or space with it – "Hello," not
     * "Hello ,"), {{position}} and {{company}}. Pure.
     *
     * @param array{name: string, position: string, company: string} $vars
     */
    public static function fill(string $text, array $vars): string
    {
        if (trim($vars['name']) === '') {
            $text = str_replace([', {{name}}', ' {{name}}', '{{name}}'], '', $text);
        }

        return str_replace(['{{name}}', '{{position}}', '{{company}}'], [trim($vars['name']), $vars['position'], $vars['company']], $text);
    }

    /** The language an application was sent in (its site version), as a code: '' = the default language of the site. */
    public static function languageOf(Settings $s, array $application): string
    {
        $language = (string) ($application['language'] ?? '');

        return $language !== '' && preg_match('/^' . Language::TAG . '$/D', $language) === 1 ? $language : Language::defaults($s);
    }

    /**
     * A template ready for one application: in its language, with its name, position (or "a position with us") and the
     * company (Business details, else the site name).
     *
     * $automatic (3.34 review M4): the text goes out without a person reading it first (the "received" confirmation) – the
     * name only when it passes safeName(), otherwise none; the position is always the job's own title from the server.
     *
     * @param array<string, mixed> $application a row (name, job_title, language)
     * @return array{subject: string, text: string}
     */
    public static function compose(Settings $s, array $application, string $key, bool $automatic = false): array
    {
        $language = self::languageOf($s, $application);
        $template = self::template($s, $key, $language);
        $vars = ['name' => $automatic ? self::safeName((string) ($application['name'] ?? '')) : (string) ($application['name'] ?? ''),
            'position' => (string) ($application['job_title'] ?? '') !== '' ? (string) $application['job_title'] : Language::runWith($language, fn (): string => t('a position with us')),
            'company' => $s->get('company_name') !== '' ? $s->get('company_name') : $s->get('site_name')];

        return ['subject' => self::fill($template['subject'], $vars), 'text' => self::fill($template['text'], $vars)];
    }

    /** The longest name an automatic e-mail carries (safeName). */
    public const int SAFE_NAME_MAX = 60;

    /**
     * A name an automatic e-mail may carry (3.34 review M4 – the form could otherwise send any text to any address): letters,
     * spaces, hyphens and apostrophes only, at most SAFE_NAME_MAX characters; anything else gives ''. Pure.
     */
    public static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $name !== '' && mb_strlen($name) <= self::SAFE_NAME_MAX && preg_match("/^\\p{L}[\\p{L}\\p{M} '’-]*$/uD", $name) === 1 ? $name : '';
    }

    /**
     * The "received" confirmation to the applicant (3.34 B) – only when the form sends no confirmation of its own (its
     * property potvrzeni, sent by Front\Forms) and the address is valid. The mail log keeps "Application received: <site>",
     * never a name or the position (the rule of 3.32's form notifications). Never fails the submission. 3.34 review M4: it
     * carries no text the visitor typed – the name only when safeName() lets it through, the position is the job's title.
     *
     * @param array<string, mixed> $content the Form element's content
     */
    public static function confirmReceipt(App $app, array $content, int $id, string $email): bool
    {
        $to = Mail::addresses($email);
        if (!empty($content['potvrzeni']) || count($to) !== 1) {
            return false;
        }
        $s = $app->settings();
        $row = $app->db()->one('SELECT name, job_title, language FROM {applications} WHERE id = ?', [$id]);
        if ($row === null) {
            return false;
        }
        $mail = self::compose($s, $row, 'received', true); // M4: no visitor text but a plain name
        $from = $s->get('site_email');

        return Mail::send($s, $to[0], $mail['subject'], $mail['text'], '', Mail::addresses($from) !== [] ? ['Reply-To' => Mail::addresses($from)[0]] : [], true,
            Language::runWith(Language::defaults($s), fn (): string => t('Application received: %s', $s->get('site_name')), 'admin-')); // the log is read in the administration
    }

    /**
     * Sends a reply to the applicant's own address as the signed-in user (an administration user with the section, or the
     * user of an MCP connection) – the machinery of enquiry replies (Core\EnquiryReply): SMTP only, From = the site, Reply-To =
     * the user, their e-mail signature below the text, the reply kept on the application (replies). $setStatus moves the
     * application with it (rejected with $reason); without one a new application becomes reviewed. Nobody responsible yet =
     * the person who answered, when they may be. The mail log and the events never name the applicant: the log subject is
     * "Reply to application #N: <site>". Throws \DomainException with a message for the person when it cannot be sent.
     *
     * @param 'admin'|'mcp' $via
     * @return array{status: 'sent'|'queued', error: string, to: string, status_set: ?string}
     */
    public static function reply(App $app, int $id, string $subject, string $text, ?string $setStatus, string $reason, string $via = 'admin'): array
    {
        $db = $app->db();
        $s = $app->settings();
        $row = $db->one('SELECT id, email, name, job_title, language, status, assignee_id, replies FROM {applications} WHERE id = ?', [$id]) ?? throw new \DomainException('The application does not exist.');
        if ($setStatus !== null && $setStatus !== '' && !in_array($setStatus, Applications::STATUSES, true)) {
            throw new \DomainException('Unknown status.');
        }
        $to = Mail::addresses((string) $row['email']);
        if (count($to) !== 1) {
            throw new \DomainException('This application has no e-mail address to reply to – call the applicant, and note it in the internal note.');
        }
        $blocked = EnquiryReply::unavailable($s);
        if ($blocked !== null) {
            throw new \DomainException($blocked);
        }
        $text = trim(mb_substr(str_replace("\r\n", "\n", $text), 0, EnquiryReply::MAX_TEXT));
        if (mb_strlen($text) < 2) {
            throw new \DomainException('Write the reply first.');
        }
        $subject = trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject), 0, EnquiryReply::MAX_SUBJECT));
        $subject = $subject !== '' ? $subject : self::compose($s, $row, 'received')['subject'];

        $user = $app->auth()->user() ?? [];
        $name = trim((string) ($user['jmeno'] ?? '')) !== '' ? trim((string) $user['jmeno']) : (string) ($user['user'] ?? 'admin');
        $replyTo = Mail::addresses((string) ($user['email'] ?? ''));
        $headers = count($replyTo) === 1 ? ['Reply-To' => $replyTo[0]] : [];
        $signature = EnquiryReply::signature($app, $user);
        $plain = $text . ($signature !== null && $signature['text'] !== '' ? "\n\n-- \n" . $signature['text'] : '');
        $html = $signature !== null ? EnquiryReply::html($text, $signature['html']) : '';
        $ok = Mail::send($s, $to[0], $subject, $plain, $html, $headers, true, t('Reply to application #%d: %s', $id, $s->get('site_name')));
        $error = $ok ? '' : Mail::$error;
        $now = Clock::now();
        $entry = ['at' => $now, 'by' => mb_substr($name, 0, 100), 'by_id' => (int) ($user['idu'] ?? 0), 'to' => $to[0], 'reply_to' => $headers['Reply-To'] ?? '',
            'subject' => $subject, 'text' => $text, 'mail' => (int) (Mail::$lastId ?? 0), 'status' => $ok ? 'sent' : 'queued', 'error' => mb_substr($error, 0, 255), 'via' => $via];
        $replies = array_slice([...EnquiryReply::decode($row['replies']), $entry], -EnquiryReply::KEEP);
        $changes = ['replies' => (string) json_encode($replies, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'last_activity_at' => $now];
        $me = (int) ($user['idu'] ?? 0);
        if ($row['assignee_id'] === null && $me > 0 && Assignment::allowed($db, 'application', $me)) {
            $changes['assignee_id'] = $me; // who answered handles it, unless someone already does
        }
        $db->update('applications', $changes, ['id' => $id]);
        $target = $setStatus !== null && $setStatus !== '' ? $setStatus : ($row['status'] === 'new' ? 'reviewed' : null);
        $statusSet = $target !== null && Applications::setStatus($app, $id, $target, $reason, $me) ? $target : null;
        ChangeLog::write($app, Applications::SECTION, 'reply', '#' . $id . ($ok ? '' : ' (queued)'));
        // the application and who sent it – never the address, the name or the text
        Events::record($db, 'application.replied', $ok ? 'info' : 'warning', mb_substr(t('%s replied to application #%d.', $name, $id), 0, 255), ['application' => $id, 'via' => $via]);

        return ['status' => $ok ? 'sent' : 'queued', 'error' => $error, 'to' => $to[0], 'status_set' => $statusSet];
    }

    /** Applications one bulk rejection answers at most (rejectWithTemplate). */
    public const int BULK_MAX = 100;

    /**
     * Rejects applications at once with the rejection template (3.40, design B6 "bulk: reject with the rejection template"):
     * each one gets the template in its own language, filled with its name and position, sent by reply() – the signed-in
     * user's signature, the reply and the status change on its own history. An application already rejected or hired is
     * left alone and counted as skipped (no second rejection e-mail, never a rejection of a new colleague – 3.40 review M2);
     * one without an e-mail address is only rejected. Throws \DomainException before anything
     * changes when the site cannot send replies (no SMTP, no sender) or nothing is selected.
     *
     * @param list<int> $ids
     * @return array{sent: int, queued: int, no_email: int, skipped: int}
     */
    public static function rejectWithTemplate(App $app, array $ids, string $reason, string $via = 'admin'): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0))), 0, self::BULK_MAX);
        if ($ids === []) {
            throw new \DomainException('Select at least one application.');
        }
        $s = $app->settings();
        $blocked = EnquiryReply::unavailable($s);
        if ($blocked !== null) {
            throw new \DomainException($blocked);
        }
        $reason = isset(Applications::REJECT_REASONS[$reason]) ? $reason : 'other';
        $out = ['sent' => 0, 'queued' => 0, 'no_email' => 0, 'skipped' => 0];
        $me = $app->auth()->id();
        foreach ($app->db()->all('SELECT id, name, email, job_title, language, status FROM {applications} WHERE id IN (' . implode(',', $ids) . ') ORDER BY id') as $row) {
            $id = (int) $row['id'];
            if (in_array($row['status'], Applications::DECIDED, true)) {
                $out['skipped']++; // 3.40 review M2: a hired applicant is never rejected nor e-mailed by a bulk rejection
                continue;
            }
            if (count(Mail::addresses((string) $row['email'])) !== 1) {
                Applications::setStatus($app, $id, 'rejected', $reason, $me);
                $out['no_email']++;
                continue;
            }
            $mail = self::compose($s, $row, 'rejection', true); // nobody reads each text: the name only when it is a plain name (M4)
            try {
                $result = self::reply($app, $id, $mail['subject'], $mail['text'], 'rejected', $reason, $via);
            } catch (\DomainException) {
                $out['skipped']++; // deleted meanwhile
                continue;
            }
            $out[$result['status'] === 'sent' ? 'sent' : 'queued']++;
        }

        return $out;
    }
}
