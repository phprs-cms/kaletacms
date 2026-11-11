<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * Job applications apart from enquiries (3.34, the feature "applications", table ka_applications, admin
 * Modules\Applications in the Workspace group). An application is not a kind of enquiry: enquiries are read in dozens of
 * places behind one section check, and applications differ in what matters – six fixed statuses, no customer record, no
 * deal, a shorter retention, CVs sealed at rest. This class is the one place that writes and reads them; the admin
 * screens, the MCP tools and the reports go through it.
 *
 * Intake (Front\Forms): a Form is an application form when the feature is on and the form is in the item template of a
 * jobs collection (Jobs::sources, the rule of 2.11) or its property Purpose says "job application" (isApplicationForm).
 * The position is looked up on the server (position()): the job item of the page the form came back to, or – on a
 * general form – the value of a field labelled like a position matched to an open job; nothing posted is trusted.
 * receive() stores the row, seals the files, records the event application.received (the position and the form, never
 * the sender) and e-mails the form's recipients the position and a link only (unless applications_notify_full). It never
 * creates a customer record, never offers a deal, never runs triage, webhooks or the "enquiry received" event.
 *
 * CVs (checkUpload, sealFile, attachment): a narrow list of types (CV_EXTENSIONS), the detected content must fit the
 * extension (finfo) and never be HTML, PHP, a script or a program (Files::forbiddenContent). Each file is encrypted with
 * its own random key (sodium secretbox) into storage/applications/YYYY/MM/<random>.bin, outside the web root; the keys,
 * names and sizes live in the attachments column, sealed with the Vault key of the purpose "applications"
 * (Vault::SEALED – moved and resealed with the other secrets when the key moves or rotates). Files are read only through
 * attachment() by the admin action of a person with the section; MCP never gets a file. Applications moved from
 * enquiries (migration 0120) keep their old files until sealPending() (the daily clean-up) seals them.
 *
 * Statuses (STATUSES): any may follow any; hired and rejected decide (decided_at), reopening clears it; every change is in
 * the history column (HISTORY_MAX) and counts as activity. Retention (expired(), purge() from the daily clean-up): a
 * decided application goes applications_months after its decision, an undecided one after applications_months without
 * any activity, and the consent for future openings keeps it at least until pool_until. Seven days before an undecided one
 * goes, its responsible person gets one notification (deletion_due). Deleting removes the files first, then the row and
 * the comments, tasks and notifications about it (target application:<id>).
 *
 * Applications are never journalled for undo (AgentJournal::PERSONAL_TABLES), never in the site export, blueprints or the
 * fleet heartbeat, and never passed to the site's AI assistant. Notifications to colleagues name an application only by
 * its number and position (Inbox::where, Assignment::mail).
 */
final class Applications
{
    /** The admin section (Module::IDENT) and the feature (Core\Extensions) – the same word. */
    public const string SECTION = 'applications';
    public const string EXTENSION = 'applications';

    /** The Form property Purpose (ucel) that makes a form an application form anywhere on the site. */
    public const string PURPOSE = 'uchazec';

    /** The fixed statuses, in their order (B-12: no stage editor). */
    public const array STATUSES = ['new', 'reviewed', 'interview', 'offer', 'hired', 'rejected'];

    /** status => label (English, through t() in the admin). */
    public const array STATUS_LABELS = ['new' => 'New', 'reviewed' => 'Reviewed', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected'];

    /** The statuses that decide an application (decided_at). */
    public const array DECIDED = ['hired', 'rejected'];

    /** Why an application was rejected => label. */
    public const array REJECT_REASONS = ['not_suitable' => 'Not suitable', 'position_filled' => 'Position filled', 'withdrew' => 'Withdrew', 'no_response' => 'No response', 'other' => 'Other'];

    /** The file types an application accepts (no archives, spreadsheets or drawings). */
    public const array CV_EXTENSIONS = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'jpg', 'jpeg', 'png'];

    /** extension => the content types finfo may report for it. */
    public const array CONTENT_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-word', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip', 'application/octet-stream'],
        'rtf' => ['text/rtf', 'application/rtf', 'text/plain'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** At most this many file fields of one application are stored. */
    public const int MAX_FILES = 3;

    /** Where the sealed files live (not reachable from the web, like storage/prilohy). */
    public const string FOLDER = KALETA_ROOT . '/storage/applications';

    /** Where the files of enquiries were (the applications moved by migration 0120 until sealPending()). */
    public const string OLD_FOLDER = KALETA_ROOT . '/storage/prilohy';

    /** The status changes kept per application. */
    public const int HISTORY_MAX = 50;

    /** Days before an undecided application is deleted when its responsible person is told (once). */
    public const int WARN_DAYS = 7;

    /** Files sealPending() seals per run (the daily clean-up). */
    public const int SEAL_BATCH = 200;

    /** A field whose label names the position on a general form ("Position", "Pozice", "Stelle"…). */
    public const string POSITION_LABEL = '/(position|pozice|pracovn[ií] m[ií]sto|job|stelle|puesto|vacan|nab[ií]dk)/iu';

    /** The feature is on. */
    public static function isOn(Settings $settings): bool
    {
        return Extensions::isEnabled($settings, self::EXTENSION);
    }

    /**
     * Is this form an application form (with the feature on – the caller checks isOn())? Its Purpose says so, or it is in
     * the item template of a jobs collection (its source is one of Jobs::sources). Pure.
     *
     * @param array<string, mixed> $content the Form element's content
     * @param list<string> $jobSources Jobs::sources()
     */
    public static function isApplicationForm(array $content, string $source, array $jobSources): bool
    {
        return ($content['ucel'] ?? '') === self::PURPOSE || in_array($source, $jobSources, true);
    }

    /**
     * The extension of an uploaded file when an application may keep it, null when it is refused: the type must be in
     * CV_EXTENSIONS, the detected content must fit it and never be HTML, PHP, a script, SVG or a program.
     */
    public static function checkUpload(string $tmpPath, string $originalName): ?string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::CV_EXTENSIONS, true) || !is_file($tmpPath) || Files::forbiddenContent($tmpPath)) {
            return null;
        }

        return self::contentFits($extension, strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath))) ? $extension : null;
    }

    /** Does a detected content type fit the extension (CONTENT_TYPES)? Pure. */
    public static function contentFits(string $extension, string $type): bool
    {
        return in_array(strtolower($type), self::CONTENT_TYPES[$extension] ?? [], true);
    }

    /**
     * The position of a submission, looked up on the server: on a job's item page (the source is a jobs collection and the
     * page an item of it) that item; on any other form the first field labelled like a position whose value names an open
     * job of a jobs collection (case-insensitive, the language's version first); otherwise none – a spontaneous application.
     * late = the job's closing date (valid_until) had passed (a cached page sent after the job hid itself).
     *
     * @param list<array{0: string, 1: string}> $data the stored fields
     * @return array{collection: ?int, item: ?int, title: string, late: bool}
     */
    public static function position(Db $db, string $source, string $back, array $data): array
    {
        $none = ['collection' => null, 'item' => null, 'title' => '', 'late' => false];
        $collections = $db->all('SELECT idk, seo_link FROM {kolekce} WHERE preset = ?', [Jobs::PRESET]);
        if ($collections === []) {
            return $none;
        }
        $row = null;
        if (preg_match('/^kolekce:(\d+)$/D', $source, $m) === 1) {
            foreach ($collections as $c) {
                if ((int) $c['idk'] === (int) $m[1]) {
                    $slug = \Kaleta\Front\EnquiryTopic::itemSlug((string) $c['seo_link'], $back);
                    $row = $slug === null ? null : $db->one('SELECT idp, idk, nazev, valid_until FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND smazano IS NULL ORDER BY jazyk = ? DESC LIMIT 1',
                        [(int) $c['idk'], $slug, Language::siteColumn()]);
                }
            }
        }
        if ($row === null) {
            $wanted = self::positionValue($data);
            if ($wanted !== '') {
                $ids = implode(',', array_map(fn (array $c): int => (int) $c['idk'], $collections));
                $row = $db->one('SELECT idp, idk, nazev, valid_until FROM {kolekce_polozky} WHERE idk IN (' . $ids . ') AND smazano IS NULL AND zobrazit = 1 AND LOWER(nazev) = ? ORDER BY jazyk = ? DESC, idp DESC LIMIT 1',
                    [mb_strtolower($wanted), Language::siteColumn()]);
            }
        }
        if ($row === null) {
            return $none;
        }
        $until = is_string($row['valid_until'] ?? null) ? (string) $row['valid_until'] : '';

        return ['collection' => (int) $row['idk'], 'item' => (int) $row['idp'], 'title' => mb_substr((string) $row['nazev'], 0, 200), 'late' => $until !== '' && $until < Clock::today()];
    }

    /**
     * The titles of the open jobs of the jobs collections in a language column ('' = the default language), A–Z: visible,
     * not in the trash, not past their closing date (3.34 B – the builder fills a form's Position choice with them; the
     * submission matches the chosen title back, position()). At most 100.
     *
     * @return list<string>
     */
    public static function openPositions(Db $db, string $language): array
    {
        try {
            return array_values(array_unique(array_map('strval', array_column($db->all("SELECT p.nazev FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.preset = ? AND p.zobrazit = 1
                AND p.smazano IS NULL AND (p.valid_until IS NULL OR p.valid_until >= ?) AND p.jazyk = ? ORDER BY p.nazev LIMIT 100", [Jobs::PRESET, Clock::today(), $language]), 'nazev'))));
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * The value of the first field labelled like a position (POSITION_LABEL) – a select of the open jobs on a general
     * form, or the hidden "Job opening" field of the jobs template. Pure.
     *
     * @param list<mixed> $data fields as [label, value] (what a form or an old enquiry row holds – checked here)
     */
    public static function positionValue(array $data): string
    {
        foreach ($data as $field) {
            if (is_array($field) && is_scalar($field[0] ?? null) && is_scalar($field[1] ?? null) && preg_match(self::POSITION_LABEL, (string) $field[0]) === 1) {
                $value = trim((string) $field[1]);
                if ($value !== '' && mb_strlen($value) <= 200) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Stores an application from a form submission (Front\Forms, after anti-spam, validation and the file checks) and
     * returns its id. $files: [index in $data => [temporary path, extension, original name, size]] of uploads that passed
     * checkUpload(); at most MAX_FILES are stored. $poolConsent: the applicant ticked the consent for future openings
     * (its wording is the form's own, pool_consent). Then the event, the notification of the form's recipients and – when
     * the form sends one – the confirmation to the applicant.
     *
     * @param array<string, mixed> $element the Form element of the published build
     * @param list<array{0: string, 1: string}> $data the fields as stored ([label, value]; a file field holds "name (size)")
     * @param array<int, array{0: string, 1: string, 2: string, 3: int}> $files
     */
    public static function receive(App $app, array $element, string $source, string $back, array $data, string $email, array $files, bool $poolConsent): int
    {
        $db = $app->db();
        $s = $app->settings();
        $content = is_array($element['obsah'] ?? null) ? $element['obsah'] : [];
        $now = Clock::now();
        $position = self::position($db, $source, $back, $data);
        $people = Contacts::fromFields($data, $email);
        $attachments = [];
        foreach (array_slice($files, 0, self::MAX_FILES, true) as $index => [$tmp, $extension, $original, $size]) {
            $stored = self::sealFile($tmp);
            if ($stored !== null) {
                $attachments[] = ['field' => (int) $index, 'name' => mb_substr($original, 0, 120), 'size' => $size, 'ext' => $extension] + $stored + ['sealed' => true];
            }
        }
        $consentText = trim(is_string($content['souhlas_uchovani'] ?? null) ? $content['souhlas_uchovani'] : '');
        $poolMonths = max(1, $s->int('applications_pool_months'));
        $id = $db->insert('applications', [
            'received_at' => $now, 'job_collection_id' => $position['collection'], 'job_item_id' => $position['item'], 'job_title' => $position['title'],
            'form' => mb_substr((string) ($content['nazev'] ?? ''), 0, 120), 'source' => mb_substr($source, 0, 40), 'element' => mb_substr((string) ($element['id'] ?? ''), 0, 16),
            'page' => mb_substr($back, 0, 255), 'language' => Language::siteColumn(), 'name' => $people['name'], 'email' => mb_substr($email, 0, 190),
            'email_norm' => PersonalData::normalise($email), 'phone' => $people['phone'], 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'attachments' => self::encodeAttachments($s, $attachments), 'status' => 'new', 'status_at' => $now, 'late' => $position['late'] ? 1 : 0,
            'pool_consent_at' => $poolConsent && $consentText !== '' ? $now : null, 'pool_consent_text' => $poolConsent && $consentText !== '' ? mb_substr($consentText, 0, 500) : null,
            'pool_until' => $poolConsent && $consentText !== '' ? Clock::day('+' . $poolMonths . ' months') : null,
            'last_activity_at' => $now,
        ]);
        // the position and the form, never the sender (as enquiry.received)
        Events::record($db, 'application.received', 'info', t('Application #%d for “%s” from the form “%s”', $id, $position['title'] !== '' ? $position['title'] : t('no position'), mb_substr((string) ($content['nazev'] ?? ''), 0, 80)),
            ['application' => $id, 'job' => $position['item'], 'form' => (string) ($element['id'] ?? ''), 'source' => $source]);
        self::notifyRecipients($app, $id, $content, $position['title'], $data, $email);
        self::confirmReceipt($app, $content, $id, $email); // 3.34 B: when the form sends no confirmation of its own

        return $id;
    }

    /**
     * The e-mail about a new application to the form's recipients (B-9): "New application for <position>" and the admin
     * link; the fields only when applications_notify_full is on. Reply-To is the applicant only then too – a plain
     * notification carries nothing of the person.
     *
     * @param array<string, mixed> $content
     * @param list<array{0: string, 1: string}> $data
     */
    private static function notifyRecipients(App $app, int $id, array $content, string $position, array $data, string $email): void
    {
        $s = $app->settings();
        [$to, $bcc] = \Kaleta\Front\Forms::recipients((string) ($content['prijemce'] ?? ''), (string) ($content['skryta_kopie'] ?? ''), $s->get('site_email'));
        if ($to === []) {
            return;
        }
        [$subject, $text] = self::notification($s, $id, $position, $s->bool('applications_notify_full') ? $data : [], rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/') . $app->url(self::adminPath($id)));
        $headers = ($s->bool('applications_notify_full') && $email !== '' ? ['Reply-To' => $email] : []) + ($bcc !== [] ? ['Bcc' => implode(', ', $bcc)] : []);
        Mail::send($s, implode(', ', $to), $subject, $text, '', $headers, true, $subject);
    }

    /**
     * Subject and text of the notification of a new application: the number, the position and the link; the fields only
     * when given (applications_notify_full). Pure apart from the dictionaries.
     *
     * @param list<array{0: string, 1: string}> $data
     * @return array{0: string, 1: string}
     */
    public static function notification(Settings $s, int $id, string $position, array $data, string $url): array
    {
        $subject = $position !== '' ? t('New application for %s: %s', $position, $s->get('site_name')) : t('New application: %s', $s->get('site_name'));
        $text = ($position !== '' ? t('A new application #%d for the position “%s” arrived.', $id, $position) : t('A new application #%d arrived (no position).', $id))
            . ($data !== [] ? "\n\n" . implode("\n\n", array_map(fn (array $d): string => $d[0] . ":\n" . $d[1], $data)) : '')
            . "\n\n—\n" . t('Open it in the administration (Applications): %s', $url);

        return [$subject, $text];
    }

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
        if ($setStatus !== null && $setStatus !== '' && !in_array($setStatus, self::STATUSES, true)) {
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
        $statusSet = $target !== null && self::setStatus($app, $id, $target, $reason, $me) ? $target : null;
        ChangeLog::write($app, self::SECTION, 'reply', '#' . $id . ($ok ? '' : ' (queued)'));
        // the application and who sent it – never the address, the name or the text
        Events::record($db, 'application.replied', $ok ? 'info' : 'warning', mb_substr(t('%s replied to application #%d.', $name, $id), 0, 255), ['application' => $id, 'via' => $via]);

        return ['status' => $ok ? 'sent' : 'queued', 'error' => $error, 'to' => $to[0], 'status_set' => $statusSet];
    }

    /* ---------- reading ---------- */

    /**
     * One application with its JSON columns decoded: data (list of [label, value]), attachments (name, size, ext, sealed –
     * never the key or the path for a caller to pass on; attachment() reads the file), replies (EnquiryReply::decode
     * format), history (list of {at, by, from, to}); numbers as numbers. Null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public static function get(App $app, int $id): ?array
    {
        $row = $id > 0 ? $app->db()->one('SELECT * FROM {applications} WHERE id = ?', [$id]) : null;

        return $row === null ? null : self::decode($app->settings(), $row);
    }

    /**
     * A row as get() returns it. Pure apart from opening the sealed attachment list.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decode(Settings $s, array $row): array
    {
        $int = fn (mixed $v): ?int => $v !== null ? (int) $v : null;
        $data = json_decode((string) ($row['data'] ?? ''), true);
        $history = json_decode((string) ($row['history'] ?? ''), true);

        return ['id' => (int) $row['id'], 'job_collection_id' => $int($row['job_collection_id']), 'job_item_id' => $int($row['job_item_id']), 'assignee_id' => $int($row['assignee_id']),
            'source_enquiry_id' => $int($row['source_enquiry_id']), 'late' => (int) $row['late'] === 1,
            'data' => is_array($data) ? array_values(array_filter($data, 'is_array')) : [],
            'attachments' => array_map(fn (array $a): array => ['field' => (int) ($a['field'] ?? -1), 'name' => (string) ($a['name'] ?? ''), 'size' => (int) ($a['size'] ?? 0),
                'ext' => (string) ($a['ext'] ?? ''), 'sealed' => (bool) ($a['sealed'] ?? false)], self::attachments($s, $row['attachments'] ?? null)),
            'replies' => EnquiryReply::decode($row['replies'] ?? null),
            'history' => is_array($history) ? array_values(array_filter($history, 'is_array')) : []] + $row;
    }

    /**
     * Filters of the list as given (the admin query, MCP arguments), checked: status (a status, 'open' = not decided,
     * 'waiting' = new and without a reply for more than WAITING_DAYS (3.34 B), '' = all), job (an item id, 'none' = spontaneous, '' = all), assignee (a user id, 'none', '' = all), pool ('1' = kept
     * for future openings), search (name, e-mail or text). Pure.
     *
     * @param array<string, mixed> $q
     * @return array{status: string, job: string, assignee: string, pool: bool, search: string}
     */
    public static function filter(array $q): array
    {
        $str = fn (string $k): string => is_scalar($q[$k] ?? null) ? trim((string) $q[$k]) : '';
        $status = $str('status');
        $job = $str('job');
        $assignee = $str('assignee');

        return ['status' => in_array($status, self::STATUSES, true) || $status === 'open' || $status === 'waiting' ? $status : '',
            'job' => ctype_digit($job) && (int) $job > 0 || $job === 'none' ? $job : '',
            'assignee' => ctype_digit($assignee) && (int) $assignee > 0 || $assignee === 'none' ? $assignee : '',
            'pool' => $str('pool') === '1', 'search' => mb_substr($str('search'), 0, 100)];
    }

    /**
     * The WHERE of a filter and its parameters. Pure.
     *
     * @param array{status: string, job: string, assignee: string, pool: bool, search: string} $filter
     * @return array{0: string, 1: list<mixed>}
     */
    public static function where(array $filter, string $today): array
    {
        $where = [];
        $params = [];
        if ($filter['status'] === 'open') {
            $where[] = "status NOT IN ('hired', 'rejected')";
        } elseif ($filter['status'] === 'waiting') {
            $where[] = "status = 'new' AND replies IS NULL AND received_at < ?";
            $params[] = self::waitingBefore($today);
        } elseif ($filter['status'] !== '') {
            $where[] = 'status = ?';
            $params[] = $filter['status'];
        }
        if ($filter['job'] === 'none') {
            $where[] = 'job_item_id IS NULL';
        } elseif ($filter['job'] !== '') {
            $where[] = 'job_item_id = ?';
            $params[] = (int) $filter['job'];
        }
        if ($filter['assignee'] === 'none') {
            $where[] = 'assignee_id IS NULL';
        } elseif ($filter['assignee'] !== '') {
            $where[] = 'assignee_id = ?';
            $params[] = (int) $filter['assignee'];
        }
        if ($filter['pool']) {
            $where[] = 'pool_until >= ?';
            $params[] = $today;
        }
        if ($filter['search'] !== '') {
            $like = '%' . addcslashes($filter['search'], '%_\\') . '%';
            $where[] = '(name LIKE ? OR email LIKE ? OR job_title LIKE ? OR data LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        return [$where === [] ? '1 = 1' : implode(' AND ', $where), $params];
    }

    /**
     * A page of the list, newest first: id, received_at, name, email, job_item_id, job_title, status, status_at,
     * decided_at, assignee_id, pool_until, late, last_activity_at and deletes_on (deletionDate()); total = all that match.
     *
     * @param array<string, mixed> $query raw filters (filter())
     * @return array{rows: list<array<string, mixed>>, total: int, filter: array{status: string, job: string, assignee: string, pool: bool, search: string}}
     */
    public static function list(App $app, array $query, int $page = 1, int $perPage = 50): array
    {
        $db = $app->db();
        $s = $app->settings();
        $filter = self::filter($query);
        [$where, $params] = self::where($filter, Clock::today());
        $perPage = max(1, min(200, $perPage));
        $total = (int) $db->value('SELECT COUNT(*) FROM {applications} WHERE ' . $where, $params);
        $rows = $db->all('SELECT id, received_at, name, email, job_item_id, job_title, status, status_at, decided_at, assignee_id, pool_until, late, last_activity_at, source_enquiry_id FROM {applications} WHERE '
            . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage, $params);
        $months = $s->int('applications_months');

        return ['rows' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'job_item_id' => $r['job_item_id'] !== null ? (int) $r['job_item_id'] : null,
            'assignee_id' => $r['assignee_id'] !== null ? (int) $r['assignee_id'] : null, 'late' => (int) $r['late'] === 1,
            'deletes_on' => self::deletionDate($r, $months)] + $r, $rows), 'total' => $total, 'filter' => $filter];
    }

    /**
     * How many applications per status (every status, 0 when none), plus 'open' (not decided), 'all' and 'waiting' (new
     * ones older than WAITING_DAYS without a reply – B10).
     *
     * @return array<string, int>
     */
    public static function counts(Db $db): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        try {
            foreach ($db->pairs('SELECT status, COUNT(*) FROM {applications} GROUP BY status') as $status => $n) {
                if (isset($out[$status])) {
                    $out[$status] = (int) $n;
                }
            }
            $waiting = (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE status = 'new' AND replies IS NULL AND received_at < ?", [self::waitingBefore(Clock::today())]);
        } catch (\PDOException) {
            $waiting = 0; // before migration 0119
        }

        return $out + ['open' => array_sum(array_diff_key($out, array_flip(self::DECIDED))), 'all' => array_sum($out), 'waiting' => $waiting];
    }

    /** "Waiting more than 7 days without a reply" (B10). */
    public const int WAITING_DAYS = 7;

    /** The moment before which a new application without a reply counts as waiting (the start of the day WAITING_DAYS ago). Pure. */
    public static function waitingBefore(string $today): string
    {
        return (new \DateTimeImmutable($today))->modify('-' . self::WAITING_DAYS . ' days')->format('Y-m-d') . ' 00:00:00';
    }

    /**
     * The other applications from the same address (3.34 B, the detail): id, job_title, status, received_at – newest first,
     * at most 10; only inside the module.
     *
     * @return list<array{id: int, job_title: string, status: string, received_at: string}>
     */
    public static function others(Db $db, int $id, ?string $emailNorm): array
    {
        if ($emailNorm === null || $emailNorm === '') {
            return [];
        }

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'job_title' => (string) $r['job_title'], 'status' => (string) $r['status'], 'received_at' => (string) $r['received_at']],
            $db->all('SELECT id, job_title, status, received_at FROM {applications} WHERE email_norm = ? AND id <> ? ORDER BY id DESC LIMIT 10', [$emailNorm, $id]));
    }

    /**
     * Applications per position, the open positions first: job_item_id (null = spontaneous), job_title, open, all.
     *
     * @return list<array{job_item_id: ?int, job_title: string, open: int, all: int}>
     */
    public static function byJob(Db $db): array
    {
        return array_map(fn (array $r): array => ['job_item_id' => $r['job_item_id'] !== null ? (int) $r['job_item_id'] : null, 'job_title' => (string) $r['job_title'],
            'open' => (int) $r['open'], 'all' => (int) $r['n']],
            $db->all("SELECT job_item_id, MAX(job_title) AS job_title, SUM(status NOT IN ('hired', 'rejected')) AS open, COUNT(*) AS n FROM {applications} GROUP BY job_item_id ORDER BY open DESC, n DESC LIMIT 100"));
    }

    /**
     * Applications per job of one collection (3.34 B, the items list of a jobs collection): item id => [new, all].
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public static function countsByItem(Db $db, int $collectionId): array
    {
        $out = [];
        try {
            foreach ($db->all("SELECT job_item_id, SUM(status = 'new') AS fresh, COUNT(*) AS n FROM {applications} WHERE job_collection_id = ? AND job_item_id IS NOT NULL GROUP BY job_item_id", [$collectionId]) as $r) {
                $out[(int) $r['job_item_id']] = [(int) $r['fresh'], (int) $r['n']];
            }
        } catch (\PDOException) {
            // before migration 0119
        }

        return $out;
    }

    /**
     * While migration 0120 still moves job enquiries (3.34 review M3 – a large site moves over several requests): the SQL
     * condition on ka_poptavky that leaves out the enquiries still to move (job sources, idp up to applications_move_until),
     * so nobody without Applications reads them in Enquiries meanwhile; null when nothing is moving. The sources are numbers
     * read from the database, written into the SQL as literals.
     */
    public static function movingCondition(Db $db, Settings $s): ?string
    {
        $until = $s->get('applications_move_until');
        if (!ctype_digit($until) || (int) $until <= 0) {
            return null;
        }
        $sources = Jobs::sources($db);

        return $sources === [] ? null : "NOT (zdroj IN ('" . implode("','", $sources) . "') AND idp <= " . (int) $until . ')';
    }

    /** How many job enquiries migration 0120 has still to move (the notice on the list); 0 when nothing is moving. */
    public static function movingLeft(Db $db, Settings $s): int
    {
        $condition = self::movingCondition($db, $s);

        return $condition === null ? 0 : (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE NOT (' . $condition . ')');
    }

    /** New applications nobody has looked at (the menu badge). */
    public static function newCount(Db $db): int
    {
        try {
            return (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE status = 'new'");
        } catch (\PDOException) {
            return 0;
        }
    }

    /** The admin address of an application (relative). */
    public static function adminPath(int $id): string
    {
        return 'admin.php?module=' . self::SECTION . '&action=detail&id=' . $id;
    }

    /** The target of an application's comments, tasks and notifications. */
    public static function target(int $id): string
    {
        return 'application:' . $id;
    }

    /**
     * Where notifications and tasks about applications point (Core\Inbox::places): the number and the position only – a
     * colleague's bell or e-mail never carries the applicant (B7). Batched.
     *
     * @param list<int> $ids
     * @return array<string, array{kind: string, id: int, title: string, path: string}>
     */
    public static function places(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        $out = [];
        try {
            foreach ($ids === [] ? [] : $db->all('SELECT id, job_title FROM {applications} WHERE id IN (' . implode(',', $ids) . ')') as $r) {
                $out[self::target((int) $r['id'])] = ['kind' => 'application', 'id' => (int) $r['id'], 'title' => (string) $r['job_title'], 'path' => self::adminPath((int) $r['id'])];
            }
        } catch (\PDOException) {
            // before migration 0119
        }

        return $out;
    }

    /* ---------- changing ---------- */

    /**
     * Moves an application to a status (any from any – B-12): hired and rejected set decided_at, the others clear it;
     * rejected keeps its reason (REJECT_REASONS, 'other' when none fits), any other status clears it. The change goes to
     * the history (who, when, from, to; at most HISTORY_MAX) and counts as activity. Returns false when nothing changed
     * (the same status and reason) – throws \DomainException for an unknown status or application.
     */
    public static function setStatus(App $app, int $id, string $status, string $reason, int $byUserId): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \DomainException('Unknown status.');
        }
        $db = $app->db();
        $row = $db->one('SELECT id, status, reject_reason, history FROM {applications} WHERE id = ?', [$id]) ?? throw new \DomainException('The application does not exist.');
        $reason = $status === 'rejected' ? (isset(self::REJECT_REASONS[$reason]) ? $reason : 'other') : null;
        if ($row['status'] === $status && $row['reject_reason'] === $reason) {
            return false;
        }
        $now = Clock::now();
        $decided = in_array($status, self::DECIDED, true);
        $db->update('applications', ['status' => $status, 'status_at' => $now, 'decided_at' => $decided ? $now : null, 'reject_reason' => $reason,
            'history' => self::appendHistory($row['history'] ?? null, ['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'from' => (string) $row['status'], 'to' => $status]),
            'last_activity_at' => $now], ['id' => $id]);
        ChangeLog::write($app, self::SECTION, 'status', sprintf('#%d %s → %s', $id, (string) $row['status'], $status));

        return true;
    }

    /**
     * The history JSON with one more change, the oldest dropped beyond HISTORY_MAX. Pure.
     *
     * @param array{at: string, by: ?int, from: string, to: string} $change
     */
    public static function appendHistory(mixed $json, array $change): string
    {
        $list = is_string($json) ? json_decode($json, true) : null;
        $list = is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
        $list[] = $change;

        return (string) json_encode(array_slice($list, -self::HISTORY_MAX), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Makes a person responsible (null = nobody): only a candidate (Assignment – an active administrator or someone with
     * the section) or the one stored already; the new person is notified (number and position only). Returns whether it
     * changed. Throws \DomainException when the application does not exist.
     */
    public static function assign(App $app, int $id, ?int $assigneeId, int $byUserId): bool
    {
        $db = $app->db();
        $row = $db->one('SELECT assignee_id FROM {applications} WHERE id = ?', [$id]) ?? throw new \DomainException('The application does not exist.');
        $stored = $row['assignee_id'] !== null ? (int) $row['assignee_id'] : null;
        $new = Assignment::fromForm($db, 'application', (int) $assigneeId, $stored);
        if ($new === $stored) {
            return false;
        }
        $db->update('applications', ['assignee_id' => $new, 'last_activity_at' => Clock::now()], ['id' => $id]);
        ChangeLog::write($app, self::SECTION, 'assign', '#' . $id);
        if ($new !== null) {
            Assignment::notify($app, 'application', $id, $new, $byUserId);
        }

        return true;
    }

    /** How many undecided applications a person is responsible for (Core\Handover::owned); 0 before migration 0119. */
    public static function owned(Db $db, int $userId): int
    {
        try {
            return (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE assignee_id = ? AND status NOT IN ('hired', 'rejected')", [$userId]);
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * The same for every account (the Users list): idu => count.
     *
     * @return array<int, int>
     */
    public static function ownedByAll(Db $db): array
    {
        try {
            return array_map('intval', $db->pairs("SELECT assignee_id, COUNT(*) FROM {applications} WHERE assignee_id IS NOT NULL AND status NOT IN ('hired', 'rejected') GROUP BY assignee_id"));
        } catch (\PDOException) {
            return [];
        }
    }

    /** A leaver's undecided applications go to another person (null = nobody, Core\Handover::handOver); a decided one keeps who handled it. */
    public static function handOver(Db $db, int $from, ?int $to): int
    {
        try {
            return $db->run("UPDATE {applications} SET assignee_id = ? WHERE assignee_id = ? AND status NOT IN ('hired', 'rejected')", [$to, $from])->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Something happened on the application (a reply, a note, a task): it counts for the retention. */
    public static function touch(Db $db, int $id): void
    {
        try {
            $db->run('UPDATE {applications} SET last_activity_at = ? WHERE id = ?', [Clock::now(), $id]);
        } catch (\PDOException) {
            // before migration 0119
        }
    }

    /**
     * The consent for future openings withdrawn (the applicant asked): pool_until and the consent go, the normal retention
     * applies. Returns whether there was one.
     */
    public static function withdrawConsent(App $app, int $id): bool
    {
        $changed = $app->db()->run('UPDATE {applications} SET pool_consent_at = NULL, pool_consent_text = NULL, pool_until = NULL WHERE id = ? AND pool_consent_at IS NOT NULL', [$id])->rowCount() > 0;
        if ($changed) {
            ChangeLog::write($app, self::SECTION, 'consent_withdrawn', '#' . $id);
        }

        return $changed;
    }

    /**
     * Deletes applications for good: the files first, then the rows, then the comments, tasks and notifications about
     * them. Returns how many rows went. $log = false for the retention clean-up, which writes its own line.
     *
     * @param list<int> $ids
     */
    public static function delete(App $app, array $ids, bool $log = true): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $db = $app->db();
        $s = $app->settings();
        $deleted = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            foreach ($db->all('SELECT id, attachments FROM {applications} WHERE id IN (' . implode(',', $chunk) . ')') as $row) {
                foreach (self::attachments($s, $row['attachments'] ?? null) as $a) {
                    $path = self::filePath($a);
                    if ($path !== null && is_file($path)) {
                        @unlink($path);
                    }
                }
            }
            $deleted += $db->run('DELETE FROM {applications} WHERE id IN (' . implode(',', $chunk) . ')')->rowCount();
        }
        Comments::forget($db, array_map(self::target(...), $ids)); // with the notifications and tasks about them
        Tasks::forgetTargets($db, array_map(self::target(...), $ids));
        if ($log && $deleted > 0) {
            ChangeLog::write($app, self::SECTION, 'delete', $deleted === 1 ? '#' . $ids[0] : (string) $deleted);
        }

        return $deleted;
    }

    /* ---------- files ---------- */

    /**
     * Encrypts a file into FOLDER under a new random key and returns where it is and the key (base64); the source is
     * removed once the sealed copy is written (an upload the caller checked, or a file moved from storage/prilohy). Null
     * when it cannot be read or written.
     *
     * @return array{path: string, key: string}|null
     */
    public static function sealFile(string $source): ?array
    {
        $plain = @file_get_contents($source);
        if (!is_string($plain)) {
            return null;
        }
        $key = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $path = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.bin';
        $target = self::FOLDER . '/' . $path;
        if ((!is_dir(dirname($target)) && !@mkdir(dirname($target), 0770, true)) || @file_put_contents($target, $nonce . sodium_crypto_secretbox($plain, $nonce, $key), LOCK_EX) === false) {
            return null;
        }
        self::protectFolder();
        @unlink($source);
        sodium_memzero($plain);

        return ['path' => $path, 'key' => base64_encode($key)];
    }

    /** A deny-all .htaccess in the folder (storage/ has one too – a second line of defence on a misconfigured host). */
    private static function protectFolder(): void
    {
        if (!is_file(self::FOLDER . '/.htaccess')) {
            @file_put_contents(self::FOLDER . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }

    /**
     * The file of an attachment on disk, or null (the stored path is validated – nothing outside the two folders).
     *
     * @param array<string, mixed> $attachment a raw entry of the sealed list
     */
    public static function filePath(array $attachment): ?string
    {
        $path = (string) ($attachment['path'] ?? '');
        if (!empty($attachment['sealed'])) {
            return preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#D', $path) === 1 ? self::FOLDER . '/' . $path : null;
        }

        return preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', $path) === 1 ? self::OLD_FOLDER . '/' . $path : null;
    }

    /**
     * The content of the attachment number $index of an application, decrypted in memory, with its original name; null
     * when there is none or its file is gone or cannot be opened. Only for the admin download of a person with the
     * section – never for MCP or a public address.
     *
     * @return array{name: string, content: string}|null
     */
    public static function attachment(App $app, int $id, int $index): ?array
    {
        $row = $app->db()->one('SELECT attachments FROM {applications} WHERE id = ?', [$id]);
        $a = $row !== null ? (self::attachments($app->settings(), $row['attachments'] ?? null)[$index] ?? null) : null;
        $path = $a !== null ? self::filePath($a) : null;
        if ($a === null || $path === null || !is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        if (empty($a['sealed'])) {
            return ['name' => (string) $a['name'], 'content' => $raw];
        }
        $key = base64_decode((string) ($a['key'] ?? ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

        return $plain === false ? null : ['name' => (string) $a['name'], 'content' => $plain];
    }

    /**
     * The raw attachment list of a row (with paths and keys – stays inside this class and the download): the sealed JSON
     * opened with the Vault key of the purpose; a plain JSON list is read too (migration 0120 on a server where sealing
     * was not possible). Unreadable = no attachments.
     *
     * @return list<array<string, mixed>>
     */
    public static function attachments(Settings $s, mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        $json = str_starts_with($stored, '[') ? $stored : self::openList($s, $stored);
        $list = $json !== null ? json_decode($json, true) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** The JSON of a sealed attachment list, null when it cannot be opened. */
    private static function openList(Settings $s, string $stored): ?string
    {
        return Vault::openFor($s, self::SECTION, $stored);
    }

    /**
     * An attachment list is stored but cannot be opened – the encryption key (Vault) is not the one it was sealed with
     * (3.34 review M2, M5): never "no attachments", and nothing may move or delete as if there were none.
     */
    public static function attachmentsLocked(Settings $s, mixed $stored): bool
    {
        return is_string($stored) && $stored !== '' && !str_starts_with($stored, '[') && self::openList($s, $stored) === null;
    }

    /**
     * The attachment list as stored: sealed JSON, null when there is none.
     *
     * @param list<array<string, mixed>> $list
     */
    public static function encodeAttachments(Settings $s, array $list): ?string
    {
        return $list === [] ? null : Vault::seal(Vault::key($s, self::SECTION), (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Seals the files of applications moved from enquiries (migration 0120, the move by form: still in storage/prilohy,
     * sealed false) – at most $limit files per run, from the daily clean-up. 3.34 review M1: the rows are walked with an
     * id cursor (the setting applications_seal_after), so a row whose file is gone or cannot be sealed does not hold the
     * others back; at the end the cursor starts over. A file that is gone stays listed (the download says it no longer
     * exists). Returns how many files were sealed.
     */
    public static function sealPending(App $app, int $limit = self::SEAL_BATCH): int
    {
        $db = $app->db();
        $s = $app->settings();
        $sealed = 0;
        $after = $s->int('applications_seal_after');
        while (true) {
            try {
                $rows = $db->all('SELECT id, attachments FROM {applications} WHERE source_enquiry_id IS NOT NULL AND attachments IS NOT NULL AND id > ? ORDER BY id LIMIT 200', [$after]);
            } catch (\PDOException) {
                return 0;
            }
            if ($rows === []) {
                $s->set('applications_seal_after', '0'); // the end: the next run starts over (rows moved since)
                break;
            }
            foreach ($rows as $row) {
                $list = self::attachments($s, $row['attachments']);
                if ($list === [] && !str_starts_with((string) $row['attachments'], '[')) {
                    $after = (int) $row['id']; // a sealed list that cannot be opened (Vault) – nothing to do here
                    continue;
                }
                $changed = false;
                $full = false;
                foreach ($list as $i => $a) {
                    if (!empty($a['sealed'])) {
                        continue;
                    }
                    if ($sealed >= $limit) {
                        $full = true;
                        break;
                    }
                    $path = self::filePath($a);
                    $stored = $path !== null && is_file($path) ? self::sealFile($path) : null;
                    if ($stored !== null) {
                        $list[$i] = $stored + ['sealed' => true] + $a;
                        $sealed++;
                        $changed = true;
                    }
                }
                if ($changed || str_starts_with((string) $row['attachments'], '[')) {
                    $db->update('applications', ['attachments' => self::encodeAttachments($s, $list)], ['id' => (int) $row['id']]);
                }
                if ($full) {
                    $s->set('applications_seal_after', (string) $after); // this row again next time
                    break 2;
                }
                $after = (int) $row['id'];
            }
            $s->set('applications_seal_after', (string) $after);
        }

        return $sealed;
    }

    /**
     * How many CVs moved from enquiries still wait in storage/prilohy to be sealed (3.34 review M1 – the settings tab says
     * so): unsealed entries whose file is still there.
     */
    public static function unsealedCount(App $app): int
    {
        $s = $app->settings();
        $count = 0;
        try {
            foreach ($app->db()->all('SELECT attachments FROM {applications} WHERE source_enquiry_id IS NOT NULL AND attachments IS NOT NULL') as $row) {
                foreach (self::attachments($s, $row['attachments']) as $a) {
                    $path = empty($a['sealed']) ? self::filePath($a) : null;
                    $count += $path !== null && is_file($path) ? 1 : 0;
                }
            }
        } catch (\PDOException) {
            return 0; // before migration 0119
        }

        return $count;
    }

    /* ---------- retention ---------- */

    /** The marker (a setting) an administrator sets by saving Applications → Settings: the moved applications may be purged. */
    public const string RETENTION_CONFIRMED = 'applications_retention_confirmed';

    /** Has an administrator confirmed the retention since applications last moved in from Enquiries (3.34 review H1)? */
    public static function retentionConfirmed(Settings $s): bool
    {
        return $s->get(self::RETENTION_CONFIRMED) === '1';
    }

    /**
     * The retention a site starts with when its job enquiries become applications (3.34 review H1 – an update never
     * shortens it): job_applications_months when it was set, otherwise the site's enquiries_months (0 = keep stays keep).
     * Pure.
     */
    public static function startingRetention(int $jobMonths, int $enquiryMonths): int
    {
        return max(0, min(120, $jobMonths > 0 ? $jobMonths : $enquiryMonths));
    }

    /**
     * Applications are moving in from Enquiries (migration 0120 – it does the same itself –, the move by form, one enquiry):
     * a site that never stored applications_months starts at startingRetention() instead of the default, and the
     * administrator has to confirm the retention again before anything moved is purged.
     */
    public static function adoptRetention(Db $db, Settings $s): void
    {
        if ($db->value("SELECT 1 FROM {nastaveni} WHERE promenna = 'applications_months'") === null) {
            $s->set('applications_months', (string) self::startingRetention($s->int('job_applications_months'), $s->int('enquiries_months')));
        }
        $s->set(self::RETENTION_CONFIRMED, '');
    }

    /**
     * What waits for the confirmation (the notice on the settings tab and the list): how many applications moved from
     * Enquiries the clean-up would delete today, and the day before which they had their last activity (or decision);
     * null when the retention is confirmed, off, or nothing moved is past it.
     *
     * @return array{count: int, before: string, months: int}|null
     */
    public static function awaitingConfirmation(App $app): ?array
    {
        $s = $app->settings();
        $months = $s->int('applications_months');
        if ($months <= 0 || self::retentionConfirmed($s)) {
            return null;
        }
        $today = Clock::today();
        $before = (new \DateTimeImmutable($today))->modify('-' . $months . ' months')->format('Y-m-d');
        try {
            $rows = $app->db()->all('SELECT id, decided_at, last_activity_at, pool_until FROM {applications} WHERE source_enquiry_id IS NOT NULL AND COALESCE(decided_at, last_activity_at) < ?', [$before . ' 23:59:59']);
        } catch (\PDOException) {
            return null; // before migration 0119
        }
        $count = count(self::expired($rows, $months, $today));

        return $count > 0 ? ['count' => $count, 'before' => $before, 'months' => $months] : null;
    }

    /**
     * The day an application will be deleted (Y-m-d), null when the retention is off (0 months): a decided one
     * applications_months after its decision, an undecided one after that long without activity – and never before
     * pool_until. Pure.
     *
     * @param array<string, mixed> $row decided_at, last_activity_at, pool_until
     */
    public static function deletionDate(array $row, int $months): ?string
    {
        if ($months <= 0) {
            return null;
        }
        $from = is_string($row['decided_at'] ?? null) && $row['decided_at'] !== '' ? (string) $row['decided_at'] : (string) ($row['last_activity_at'] ?? '');
        if ($from === '') {
            return null;
        }
        $day = (new \DateTimeImmutable(substr($from, 0, 10)))->modify('+' . $months . ' months')->format('Y-m-d');
        $pool = is_string($row['pool_until'] ?? null) ? substr((string) $row['pool_until'], 0, 10) : '';

        return $pool !== '' && $pool > $day ? (new \DateTimeImmutable($pool))->modify('+1 day')->format('Y-m-d') : $day;
    }

    /**
     * The ids of the applications past their retention on $today (deletionDate() on or before it). Pure.
     *
     * @param list<array<string, mixed>> $rows id, decided_at, last_activity_at, pool_until
     * @return list<int>
     */
    public static function expired(array $rows, int $months, string $today): array
    {
        $out = [];
        foreach ($rows as $r) {
            $day = self::deletionDate($r, $months);
            if ($day !== null && $day <= $today) {
                $out[] = (int) $r['id'];
            }
        }

        return $out;
    }

    /**
     * The undecided applications with a responsible person that will be deleted within WARN_DAYS and were not announced
     * yet (no deletion_due notification about them). Pure.
     *
     * @param list<array<string, mixed>> $rows id, decided_at, last_activity_at, pool_until, assignee_id
     * @param array<int, true> $warned ids already announced
     * @return list<array{id: int, assignee: int, day: string}>
     */
    public static function dueForWarning(array $rows, int $months, string $today, array $warned): array
    {
        $limit = (new \DateTimeImmutable($today))->modify('+' . self::WARN_DAYS . ' days')->format('Y-m-d');
        $out = [];
        foreach ($rows as $r) {
            $day = self::deletionDate($r, $months);
            if ($day !== null && $day > $today && $day <= $limit && ($r['decided_at'] ?? null) === null && (int) ($r['assignee_id'] ?? 0) > 0 && !isset($warned[(int) $r['id']])) {
                $out[] = ['id' => (int) $r['id'], 'assignee' => (int) $r['assignee_id'], 'day' => $day];
            }
        }

        return $out;
    }

    /**
     * The daily clean-up (Notifications::purgePersonalData): seals moved files, warns the responsible people of the
     * undecided applications that go within a week, deletes those past their retention with their files, comments, tasks
     * and notifications, and records the count (change log, event applications.purged – as before 3.34). Runs while the
     * feature is off too: stored applications keep their retention. An application moved from Enquiries (source_enquiry_id)
     * is neither warned about nor deleted until an administrator saved Applications → Settings (RETENTION_CONFIRMED).
     * Returns how many were deleted.
     */
    public static function purge(App $app): int
    {
        $db = $app->db();
        try {
            $db->value('SELECT 1 FROM {applications} LIMIT 1');
        } catch (\PDOException) {
            return 0; // before migration 0119
        }
        self::sealPending($app);
        $months = $app->settings()->int('applications_months');
        if ($months <= 0) {
            return 0;
        }
        $today = Clock::today();
        // 3.34 review H1: what moved in from Enquiries waits until an administrator confirmed the retention (Settings tab)
        $rows = $db->all('SELECT id, decided_at, last_activity_at, pool_until, assignee_id FROM {applications} WHERE COALESCE(decided_at, last_activity_at) < ?'
            . (self::retentionConfirmed($app->settings()) ? '' : ' AND source_enquiry_id IS NULL'),
            [(new \DateTimeImmutable($today))->modify('-' . $months . ' months')->modify('+' . (self::WARN_DAYS + 1) . ' days')->format('Y-m-d')]);
        $warned = array_fill_keys(array_map(fn (array $r): int => (int) substr((string) $r['target'], 12), $db->all("SELECT target FROM {notifications} WHERE kind = 'deletion_due' AND target LIKE 'application:%'")), true);
        foreach (self::dueForWarning($rows, $months, $today, $warned) as $due) {
            Inbox::notify($app, $due['assignee'], 'deletion_due', self::target($due['id']), null, format_date($due['day']));
        }
        $expired = self::expired($rows, $months, $today);
        if ($expired === []) {
            return 0;
        }
        $count = self::delete($app, $expired, false);
        ChangeLog::write($app, self::SECTION, 'purge', sprintf('%d past the retention of %d months', $count, $months));
        Events::record($db, 'applications.purged', 'info', t('%d job applications older than %d months were deleted, including the CVs.', $count, $months), ['count' => $count, 'months' => $months]);

        return $count;
    }

    /* ---------- personal data, reports ---------- */

    /**
     * The applications of an address (Core\PersonalData): sent from it, or naming it in a field – with the replies, the
     * team's notes and tasks, the attachments by name and size (never the file), the status history.
     *
     * @return list<array<string, mixed>>
     */
    public static function ofEmail(App $app, string $email): array
    {
        $db = $app->db();
        try {
            $rows = $db->all('SELECT * FROM {applications} WHERE email_norm = ? OR LOWER(email) = ? OR LOWER(data) LIKE ? ORDER BY id', [$email, $email, '%' . addcslashes($email, '%_\\') . '%']);
        } catch (\PDOException) {
            return []; // before migration 0119
        }

        return array_map(function (array $row) use ($app, $db): array {
            $a = self::decode($app->settings(), $row);
            $target = self::target($a['id']);

            return ['id' => $a['id'], 'received_at' => $a['received_at'], 'position' => $a['job_title'], 'form' => $a['form'], 'page' => $a['page'], 'name' => $a['name'],
                'email' => $a['email'], 'phone' => $a['phone'], 'data' => $a['data'], 'attachments' => array_map(fn (array $x): array => ['name' => $x['name'], 'size' => $x['size']], $a['attachments']),
                'status' => $a['status'], 'history' => $a['history'], 'pool_consent_at' => $a['pool_consent_at'], 'pool_consent_text' => $a['pool_consent_text'], 'pool_until' => $a['pool_until'],
                'replies' => array_map(fn (array $x): array => ['at' => $x['at'], 'by' => $x['by'], 'subject' => $x['subject'], 'text' => $x['text']], $a['replies']),
                'notes' => array_map(fn (array $n): array => ['at' => $n['at'], 'by' => $n['by'], 'text' => $n['text']], Comments::of($db, $target)),
                'tasks' => array_map(fn (array $t): array => ['title' => $t['title'], 'due_at' => $t['due_at'], 'done_at' => $t['done_at'], 'created_at' => $t['created_at']], Tasks::forTarget($db, $target))];
        }, $rows);
    }

    /**
     * The month's recruitment for the monthly report (B10): received (and the top five positions by count), invited to an
     * interview, hired, rejected (status changes in the month, from the history), and how many undecided still wait for
     * the first reply. Counts and position titles only; null when nothing happened or the table is missing.
     *
     * @return array{received: int, positions: list<array{title: string, n: int}>, interview: int, hired: int, rejected: int, waiting: int}|null
     */
    public static function monthCounts(Db $db, string $from, string $to): ?array
    {
        try {
            $received = (int) $db->value('SELECT COUNT(*) FROM {applications} WHERE received_at >= ? AND received_at < ?', [$from, $to]);
            $positions = array_map(fn (array $r): array => ['title' => (string) $r['title'], 'n' => (int) $r['n']],
                $db->all("SELECT job_title AS title, COUNT(*) AS n FROM {applications} WHERE received_at >= ? AND received_at < ? AND job_title <> '' GROUP BY job_title ORDER BY n DESC, job_title LIMIT 5", [$from, $to]));
            $moves = array_fill_keys(['interview', 'hired', 'rejected'], 0);
            foreach ($db->all('SELECT history FROM {applications} WHERE status_at >= ? AND history IS NOT NULL', [$from]) as $r) {
                foreach (self::movesIn((string) $r['history'], $from, $to) as $status) {
                    if (isset($moves[$status])) {
                        $moves[$status]++;
                    }
                }
            }
            $waiting = (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE status = 'new' AND replies IS NULL AND received_at < ?", [$to]);
        } catch (\PDOException) {
            return null;
        }
        if ($received + array_sum($moves) + $waiting === 0) {
            return null;
        }

        return ['received' => $received, 'positions' => $positions] + $moves + ['waiting' => $waiting];
    }

    /**
     * The day job applications started to be counted apart from enquiries (3.34 B), when it falls between $from and $to –
     * the first move of applications out of the enquiries (event applications.moved) or the first application that came
     * straight in, whichever is earlier; null otherwise (the monthly report then compares enquiries as before).
     */
    public static function countingChanged(Db $db, string $from, string $to): ?string
    {
        try {
            $moved = $db->value("SELECT MIN(created_at) FROM {events} WHERE type = 'applications.moved'");
            $first = $db->value('SELECT MIN(received_at) FROM {applications} WHERE source_enquiry_id IS NULL');
        } catch (\PDOException) {
            return null;
        }
        $start = min(array_filter([is_string($moved) ? $moved : null, is_string($first) ? $first : null]) ?: ['']);

        return $start !== '' && $start >= $from && $start < $to ? substr($start, 0, 10) : null;
    }

    /**
     * The statuses an application reached between $from and $to (each once), from its history JSON. Pure.
     *
     * @return list<string>
     */
    public static function movesIn(string $history, string $from, string $to): array
    {
        $list = json_decode($history, true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $change) {
            if (is_array($change) && is_string($change['at'] ?? null) && is_string($change['to'] ?? null) && $change['at'] >= $from && $change['at'] < $to) {
                $out[$change['to']] = true;
            }
        }

        return array_keys($out);
    }
}
