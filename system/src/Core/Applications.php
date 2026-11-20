<?php

declare(strict_types=1);

namespace Kaleta\Core;

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
 * Since 3.47 the work around it lives in four classes, and every public member that moved keeps a forward here (the block at
 * the end), so callers and migration 0120 are unchanged:
 *  - Core\ApplicationFiles – the CVs: what an upload may be (checkUpload, CV_EXTENSIONS), the files sealed at rest under
 *    storage/applications (sealFile, openSealed), the sealed list of a row (attachments), the download (attachment) and
 *    the daily sealing and sweep (sealPending, sweepOrphans, resealHeld);
 *  - Core\ApplicationRetention – how long an application stays (deletionDate, expired), the warning to its responsible
 *    person, the confirmation for moved applications and the daily clean-up (purge);
 *  - Core\ApplicationReplies – the templates, the "received" confirmation, a reply and the bulk rejection;
 *  - Core\ApplicationMove (moves either way, movingCondition) and Core\ApplicationCounts (the monthly counts).
 *
 * Statuses (STATUSES): any may follow any; hired and rejected decide (decided_at), reopening clears it; every change is in
 * the history column (HISTORY_MAX) and counts as activity. Deleting removes the files first, then the row and the
 * comments, tasks and notifications about it (target application:<id>).
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

    /** The status changes kept per application. */
    public const int HISTORY_MAX = 50;

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
     * The position of a submission, looked up on the server: on a job's item page (the source is a jobs collection and the
     * page an item of it) that item – since 3.41 also on the item page of any other collection, because a form there is an
     * application form only by its Purpose, so the item is what the visitor applies for (a site that keeps its openings in
     * a collection of its own, not one made from the jobs preset); on any other form the first field labelled like a position whose value names an open
     * job of a jobs collection (case-insensitive, the language's version first); otherwise none – a spontaneous application.
     * late = the job's closing date (valid_until) had passed (a cached page sent after the job hid itself).
     *
     * @param list<array{0: string, 1: string}> $data the stored fields
     * @return array{collection: ?int, item: ?int, title: string, late: bool}
     */
    /**
     * The collections a submission's position is looked up in (3.41): the jobs collections, and the collection whose item
     * template the form is in (source kolekce:<idk>) when it is not one of them. Pure apart from $find, which reads that
     * collection (idk, seo_link) or returns null when it is gone.
     *
     * @param list<array<string, mixed>> $jobCollections rows with idk and seo_link
     * @param callable(int): ?array<string, mixed> $find
     * @return list<array<string, mixed>>
     */
    public static function positionCollections(array $jobCollections, string $source, callable $find): array
    {
        if (preg_match('/^kolekce:(\d+)$/D', $source, $m) !== 1 || in_array((int) $m[1], array_map('intval', array_column($jobCollections, 'idk')), true)) {
            return $jobCollections;
        }
        $own = $find((int) $m[1]);

        return $own === null ? $jobCollections : [...$jobCollections, $own];
    }

    public static function position(Db $db, string $source, string $back, array $data): array
    {
        $none = ['collection' => null, 'item' => null, 'title' => '', 'late' => false];
        $collections = self::positionCollections($db->all('SELECT idk, seo_link FROM {kolekce} WHERE preset = ?', [Jobs::PRESET]), $source,
            fn (int $idk): ?array => $db->one('SELECT idk, seo_link FROM {kolekce} WHERE idk = ?', [$idk]));
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
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
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
     * checkUpload(); at most MAX_FILES are stored (Front\Forms refuses more). Throws \RuntimeException when a CV cannot be
     * stored – nothing is kept then and the applicant is told (review low c). $poolConsent: the applicant ticked the consent for future openings
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
        $forget = static function (array $done): void {
            foreach ($done as $a) {
                $path = self::filePath($a);
                if ($path !== null) {
                    @unlink($path);
                }
            }
        };
        foreach (array_slice($files, 0, self::MAX_FILES, true) as $index => [$tmp, $extension, $original, $size]) {
            $stored = self::sealFile($s, $tmp);
            if ($stored === null) {
                // review low (c): a CV that cannot be stored fails the submission – never a silent OK without it
                $forget($attachments);
                throw new \RuntimeException('A CV could not be stored.');
            }
            $attachments[] = ['field' => (int) $index, 'name' => mb_substr($original, 0, 120), 'size' => $size, 'ext' => $extension] + $stored + ['sealed' => true];
        }
        $consentText = trim(is_string($content['souhlas_uchovani'] ?? null) ? $content['souhlas_uchovani'] : '');
        $poolMonths = max(1, $s->int('applications_pool_months'));
        try {
            $id = $db->insert('applications', [
                'received_at' => $now, 'job_collection_id' => $position['collection'], 'job_item_id' => $position['item'], 'job_title' => $position['title'],
                'form' => mb_substr((string) ($content['nazev'] ?? ''), 0, 120), 'source' => mb_substr($source, 0, 40), 'element' => mb_substr((string) ($element['id'] ?? ''), 0, 16),
                'page' => mb_substr($back, 0, 255), 'language' => Language::siteColumn(), 'name' => $people['name'], 'email' => mb_substr($email, 0, 190),
                'email_norm' => PersonalData::normalise($email), 'phone' => $people['phone'], 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE),
                'attachments' => self::encodeAttachments($s, $attachments), 'attachment_paths' => self::paths($attachments), 'status' => 'new', 'status_at' => $now, 'late' => $position['late'] ? 1 : 0,
                'pool_consent_at' => $poolConsent && $consentText !== '' ? $now : null, 'pool_consent_text' => $poolConsent && $consentText !== '' ? mb_substr($consentText, 0, 500) : null,
                'pool_until' => $poolConsent && $consentText !== '' ? Clock::day('+' . $poolMonths . ' months') : null,
                'last_activity_at' => $now,
            ]);
        } catch (\Throwable $e) {
            $forget($attachments); // N1: no row lists them – the sealed copies go, the applicant is told
            throw new \RuntimeException('The application could not be stored.', 0, $e);
        }
        // N1: the uploads go only once the row lists their sealed copies (PHP removes them at the end of the request anyway)
        foreach (array_slice($files, 0, self::MAX_FILES, true) as [$tmp]) {
            @unlink($tmp);
        }
        if ($attachments !== [] && Vault::state($s) === 'missing') {
            // M5: kept and readable (DB_SEALED), but the administrator must put the key back – never lost silently
            Events::record($db, 'applications.vault_missing', 'warning', sprintf('Application #%d: the encryption key of config.php is missing, so its CVs were sealed with the key in the database – put the vault_key line back into config.php (System status).', $id), ['application' => $id]);
        }
        ApplicationCounts::bump($db, 'received', $position['item'], $position['title'], $now); // 3.40: the statistics keep the count after the retention
        // the position and the form, never the sender (as enquiry.received)
        Events::record($db, 'application.received', 'info', t('Application #%d for “%s” from the form “%s”', $id, $position['title'] !== '' ? $position['title'] : t('no position'), mb_substr((string) ($content['nazev'] ?? ''), 0, 80)),
            ['application' => $id, 'job' => $position['item'], 'form' => (string) ($element['id'] ?? ''), 'source' => $source]);
        self::notifyRecipients($app, $id, $content, $position['title'], $data, $email, $attachments);
        self::confirmReceipt($app, $content, $id, $email); // 3.34 B: when the form sends no confirmation of its own
        TalentPool::webhook($app, $id, $position); // 3.40: the optional webhook application.received – the number and the position only

        return $id;
    }

    /**
     * The e-mail about a new application to the form's recipients (B-9): "New application for <position>" and the admin
     * link; the fields only when applications_notify_full is on. Reply-To is the applicant only then too – a plain
     * notification carries nothing of the person.
     *
     * 3.46.2: every CV as a download link (Core\FileLink – signed, 30 days, it needs signing in with the section), never
     * attached; the hidden copies (Bcc) go as separate messages without the links.
     *
     * @param array<string, mixed> $content
     * @param list<array{0: string, 1: string}> $data
     * @param list<array<string, mixed>> $attachments the sealed files as stored (field, name), in the order of their index
     */
    private static function notifyRecipients(App $app, int $id, array $content, string $position, array $data, string $email, array $attachments = []): void
    {
        $s = $app->settings();
        [$to, $bcc] = \Kaleta\Front\Forms::recipients((string) ($content['prijemce'] ?? ''), (string) ($content['skryta_kopie'] ?? ''), $s->get('site_email'));
        if ($to === []) {
            return;
        }
        $files = [];
        foreach ($attachments as $index => $a) {
            // the original name only with the full details: a CV is often named after the person
            $files[] = ['label' => (string) ($data[(int) ($a['field'] ?? -1)][0] ?? ''), 'name' => $s->bool('applications_notify_full') ? (string) ($a['name'] ?? '') : FileLink::neutralName('application', $id, (int) $index, (string) ($a['name'] ?? '')),
                'url' => FileLink::url($app, self::SECTION, $id, (int) $index)];
        }
        $full = $s->bool('applications_notify_full');
        $record = rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/') . $app->url(self::adminPath($id));
        [$subject, $text] = self::notification($s, $id, $position, $full ? $data : [], $record, FileLink::text($files));
        $replyTo = $full && $email !== '' ? ['Reply-To' => $email] : [];
        Mail::send($s, implode(', ', $to), $subject, $text, '', $replyTo, true, $subject);
        [, $copy] = self::notification($s, $id, $position, $full ? $data : [], $record);
        foreach ($bcc as $address) {
            Mail::send($s, $address, $subject, $copy, '', $replyTo, true, $subject); // each hidden recipient alone – nobody sees the others
        }
    }

    /**
     * Subject and text of the notification of a new application: the number, the position and the link; the fields only
     * when given (applications_notify_full). Pure apart from the dictionaries.
     *
     * $files (3.46.2) = the download links of the CVs (FileLink::text), before the link of the record.
     *
     * @param list<array{0: string, 1: string}> $data
     * @return array{0: string, 1: string}
     */
    public static function notification(Settings $s, int $id, string $position, array $data, string $url, string $files = ''): array
    {
        $subject = $position !== '' ? t('New application for %s: %s', $position, $s->get('site_name')) : t('New application: %s', $s->get('site_name'));
        $text = ($position !== '' ? t('A new application #%d for the position “%s” arrived.', $id, $position) : t('A new application #%d arrived (no position).', $id))
            . ($data !== [] ? "\n\n" . implode("\n\n", array_map(fn (array $d): string => $d[0] . ":\n" . $d[1], $data)) : '')
            . ($files !== '' ? "\n\n" . $files : '')
            . "\n\n—\n" . t('Open it in the administration (Applications): %s', $url);

        return [$subject, $text];
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
            'attachments_locked' => self::attachmentsLocked($s, $row['attachments'] ?? null), // M5: never shown as "no attachments"
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
        $rows = $db->all('SELECT id, received_at, name, email, job_item_id, job_title, status, status_at, decided_at, assignee_id, pool_until, late, last_activity_at, source_enquiry_id, attachments FROM {applications} WHERE '
            . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage, $params);
        $months = $s->int('applications_months');

        return ['rows' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'job_item_id' => $r['job_item_id'] !== null ? (int) $r['job_item_id'] : null,
            'assignee_id' => $r['assignee_id'] !== null ? (int) $r['assignee_id'] : null, 'late' => (int) $r['late'] === 1,
            'deletes_on' => self::deletionDate($r, $months), 'attachments_locked' => self::attachmentsLocked($s, $r['attachments']), 'attachments' => null] + $r, $rows), 'total' => $total, 'filter' => $filter];
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
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
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
    public static function others(Db $db, int $id, ?string $emailNorm, int $months = 0): array
    {
        if ($emailNorm === null || $emailNorm === '') {
            return [];
        }

        // 3.40: with the day each one will be deleted (its own retention – the list never keeps one longer)
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'job_title' => (string) $r['job_title'], 'status' => (string) $r['status'], 'received_at' => (string) $r['received_at'],
            'deletes_on' => self::deletionDate($r, $months)],
            $db->all('SELECT id, job_title, status, received_at, decided_at, last_activity_at, pool_until FROM {applications} WHERE email_norm = ? AND id <> ? ORDER BY id DESC LIMIT 10', [$emailNorm, $id]));
    }

    /** How many other applications came from the same address (3.40: the detail says when there are more than others() lists). */
    public static function othersCount(Db $db, int $id, ?string $emailNorm): int
    {
        return $emailNorm === null || $emailNorm === '' ? 0 : (int) $db->value('SELECT COUNT(*) FROM {applications} WHERE email_norm = ? AND id <> ?', [$emailNorm, $id]);
    }

    /** Days a decided application stays on the board (3.40). */
    public const int BOARD_DECIDED_DAYS = 30;

    /** Applications per column of the board at most (3.40). */
    public const int BOARD_MAX = 100;

    /**
     * The board of one position (3.40, design B15 "a board by status for one position"): a column per status, newest first;
     * hired and rejected only when decided in the last BOARD_DECIDED_DAYS days. $job: an item id or 'none' (spontaneous).
     * Each card: id, name, email (when there is no name), received_at, assignee_id, waiting (new without a reply for more
     * than WAITING_DAYS), late, pool_until, reject_reason. 'more' = per status how many the column leaves out.
     *
     * @return array{columns: array<string, list<array<string, mixed>>>, more: array<string, int>}
     */
    public static function board(Db $db, string $job, string $today): array
    {
        $columns = array_fill_keys(self::STATUSES, []);
        $more = array_fill_keys(self::STATUSES, 0);
        if ($job !== 'none' && (!ctype_digit($job) || (int) $job <= 0)) {
            return ['columns' => $columns, 'more' => $more];
        }
        $since = (new \DateTimeImmutable($today))->modify('-' . self::BOARD_DECIDED_DAYS . ' days')->format('Y-m-d') . ' 00:00:00';
        $waiting = self::waitingBefore($today);
        $rows = $db->all('SELECT id, name, email, received_at, status, assignee_id, replies IS NULL AS unanswered, late, pool_until, reject_reason FROM {applications} WHERE '
            . ($job === 'none' ? 'job_item_id IS NULL' : 'job_item_id = ' . (int) $job) . " AND (status NOT IN ('hired', 'rejected') OR decided_at >= ?) ORDER BY id DESC LIMIT 2000", [$since]);
        foreach ($rows as $r) {
            $status = in_array($r['status'], self::STATUSES, true) ? (string) $r['status'] : 'new';
            if (count($columns[$status]) >= self::BOARD_MAX) {
                $more[$status]++;
                continue;
            }
            $columns[$status][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'received_at' => (string) $r['received_at'],
                'assignee_id' => $r['assignee_id'] !== null ? (int) $r['assignee_id'] : null, 'waiting' => $status === 'new' && (int) $r['unanswered'] === 1 && (string) $r['received_at'] < $waiting,
                'late' => (int) $r['late'] === 1, 'pool_until' => $r['pool_until'] !== null ? (string) $r['pool_until'] : null, 'reject_reason' => $r['reject_reason'] !== null ? (string) $r['reject_reason'] : null];
        }

        return ['columns' => $columns, 'more' => $more];
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
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0119
        }

        return $out;
    }

    /** New applications nobody has looked at (the menu badge). */
    public static function newCount(Db $db): int
    {
        try {
            return (int) $db->value("SELECT COUNT(*) FROM {applications} WHERE status = 'new'");
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /** A line of the change log of Applications (3.40: for Core\TalentPool, which stays out of the admin layer). */
    public static function log(App $app, string $action, string $description): void
    {
        ChangeLog::write($app, self::SECTION, $action, $description);
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
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
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
        $row = $db->one('SELECT id, status, reject_reason, history, job_item_id, job_title FROM {applications} WHERE id = ?', [$id]) ?? throw new \DomainException('The application does not exist.');
        $reason = $status === 'rejected' ? (isset(self::REJECT_REASONS[$reason]) ? $reason : 'other') : null;
        if ($row['status'] === $status && $row['reject_reason'] === $reason) {
            return false;
        }
        $now = Clock::now();
        $decided = in_array($status, self::DECIDED, true);
        $db->update('applications', ['status' => $status, 'status_at' => $now, 'decided_at' => $decided ? $now : null, 'reject_reason' => $reason,
            'history' => self::appendHistory($row['history'] ?? null, ['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'from' => (string) $row['status'], 'to' => $status]),
            'last_activity_at' => $now], ['id' => $id]);
        if ($row['status'] !== $status && ApplicationCounts::counts($status, $row['history'] ?? null, $now)) {
            ApplicationCounts::bump($db, $status, $row['job_item_id'] !== null ? (int) $row['job_item_id'] : null, (string) $row['job_title'], $now); // 3.40
        }
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
     * After applications moved in from Enquiries (3.34 review low a – migration 0120 does the same in its own SQL): a
     * responsible person who may not open Applications is cleared, and the bell entries about them (moved from the enquiry,
     * with their excerpts) of people who may not open Applications go.
     *
     * @param list<int> $ids
     */
    public static function keepToSection(Db $db, array $ids): void
    {
        foreach ($ids === [] ? [] : $db->all('SELECT id, assignee_id FROM {applications} WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) {
            if ($r['assignee_id'] !== null && !Assignment::allowed($db, 'application', (int) $r['assignee_id'])) {
                $db->update('applications', ['assignee_id' => null], ['id' => (int) $r['id']]);
            }
            foreach ($db->all('SELECT id, user_id FROM {notifications} WHERE target = ?', [self::target((int) $r['id'])]) as $n) {
                if (!Assignment::allowed($db, 'application', (int) $n['user_id'])) {
                    $db->delete('notifications', ['id' => (int) $n['id']]);
                }
            }
        }
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
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
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
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
    }

    /** A leaver's undecided applications go to another person (null = nobody, Core\Handover::handOver); a decided one keeps who handled it. */
    public static function handOver(Db $db, int $from, ?int $to): int
    {
        try {
            return $db->run("UPDATE {applications} SET assignee_id = ? WHERE assignee_id = ? AND status NOT IN ('hired', 'rejected')", [$to, $from])->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0;
        }
    }

    /** Something happened on the application (a reply, a note, a task): it counts for the retention. */
    public static function touch(Db $db, int $id): void
    {
        try {
            $db->run('UPDATE {applications} SET last_activity_at = ? WHERE id = ?', [Clock::now(), $id]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
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
     * them. Returns how many rows went. $log = false for the retention clean-up, which writes its own line. 3.34 second
     * review N7: the files are found from attachment_paths (and the list when it opens) – sealed copies, CVs still in
     * storage/prilohy and the copy an interrupted sealing may have left – so a list the encryption key cannot open loses no
     * plain CV and leaves no file behind; an application whose list cannot be opened and that has no attachment_paths is
     * not deleted (its files cannot be located) and its id goes to $kept.
     *
     * @param list<int> $ids
     * @param list<int>|null $kept
     * @param-out list<int> $kept
     */
    public static function delete(App $app, array $ids, bool $log = true, ?array &$kept = null): int
    {
        $kept = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $db = $app->db();
        $s = $app->settings();
        $deleted = 0;
        $gone = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $rows = [];
            foreach ($db->all('SELECT id, attachments, attachment_paths FROM {applications} WHERE id IN (' . implode(',', $chunk) . ')') as $row) {
                $files = self::rowFiles($s, $row);
                if ($files === null) {
                    $kept[] = (int) $row['id'];
                    continue;
                }
                foreach ($files as $file) {
                    foreach ([$file, $file . self::ORPHAN_SUFFIX] as $one) { // F1: also a copy the sweep set aside
                        if (is_file($one)) {
                            @unlink($one);
                        }
                    }
                }
                $rows[] = (int) $row['id'];
            }
            if ($rows !== []) {
                self::forgetTwins($app, $rows); // F4: an enquiry twin a restored backup brought back would surface once its application went
                $deleted += $db->run('DELETE FROM {applications} WHERE id IN (' . implode(',', $rows) . ')')->rowCount();
                $gone = [...$gone, ...$rows];
            }
        }
        if ($gone !== []) {
            Comments::forget($db, array_map(self::target(...), $gone)); // with the notifications and tasks about them
            Tasks::forgetTargets($db, array_map(self::target(...), $gone));
        }
        if ($kept !== []) {
            Events::record($db, 'applications.delete_held', 'warning', mb_substr(sprintf('%d application(s) were not deleted: their CV list cannot be opened (encryption key) and their files cannot be located – put the vault_key line back into config.php, then delete again: #%s', count($kept), implode(', #', array_slice($kept, 0, 20))), 0, 255),
                ['applications' => array_slice($kept, 0, 200)]);
        }
        if ($log && $deleted > 0) {
            ChangeLog::write($app, self::SECTION, 'delete', $deleted === 1 ? '#' . $gone[0] : (string) $deleted);
        }

        return $deleted;
    }

    /**
     * Enquiries an application has a twin of (3.34 third review F4): a backup restored around the move brought the moved
     * enquiry back next to its application – the same idp as source_enquiry_id and the same date. Enquiries::visible() hides
     * it only while the application exists, so it goes for good – with its files (a CV an application still lists stays,
     * Enquiries::deleteAttachments), the link of a deal and its notes, tasks and notifications – before the application is
     * deleted, and in the daily clean-up. $ids: the twins of these applications only; null = every twin. Returns how many.
     *
     * @param list<int>|null $ids
     */
    public static function forgetTwins(App $app, ?array $ids = null): int
    {
        $db = $app->db();
        if ($ids === []) {
            return 0;
        }
        try {
            $rows = $db->all('SELECT p.idp, p.data FROM {poptavky} p JOIN {applications} a ON a.source_enquiry_id = p.idp AND a.received_at = p.datum'
                . ($ids !== null ? ' WHERE a.id IN (' . implode(',', array_map('intval', $ids)) . ')' : ''));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0119
        }
        if ($rows === []) {
            return 0;
        }
        $idps = array_map('intval', array_column($rows, 'idp'));
        \Kaleta\Admin\Modules\Enquiries::deleteAttachments($rows, $db);
        Deals::forgetEnquiries($db, $idps);
        $count = $db->run('DELETE FROM {poptavky} WHERE idp IN (' . implode(',', $idps) . ')')->rowCount();
        Comments::forgetEnquiries($db, $idps);

        return $count;
    }

    /* ---------- personal data, reports ---------- */

    /**
     * The applications of an address (Core\PersonalData): sent from it (their own e-mail field, review low e) – with the replies, the
     * team's notes and tasks, the attachments by name and size (never the file), the status history.
     *
     * @return list<array<string, mixed>>
     */
    public static function ofEmail(App $app, string $email): array
    {
        $db = $app->db();
        try {
            // review low (e): the application's own address only – an application that merely names it (a referee, a colleague)
            // belongs to its applicant and is neither exported to nor erased for someone else
            $rows = $db->all('SELECT * FROM {applications} WHERE email_norm = ? OR LOWER(email) = ? ORDER BY id', [PersonalData::normalise($email) ?? $email, mb_strtolower($email)]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0119
        }

        return array_map(function (array $row) use ($app, $db): array {
            $a = self::decode($app->settings(), $row);
            $target = self::target($a['id']);

            return ['id' => $a['id'], 'received_at' => $a['received_at'], 'position' => $a['job_title'], 'form' => $a['form'], 'page' => $a['page'], 'name' => $a['name'],
                'email' => $a['email'], 'phone' => $a['phone'], 'data' => $a['data'], 'attachments' => array_map(fn (array $x): array => ['name' => $x['name'], 'size' => $x['size']], $a['attachments']),
                'status' => $a['status'], 'history' => $a['history'], 'pool_consent_at' => $a['pool_consent_at'], 'pool_consent_text' => $a['pool_consent_text'], 'pool_until' => $a['pool_until'],
                'pool_renewal_sent_at' => $a['pool_renewal_sent_at'] ?? null, // 3.40: the renewal e-mail of the consent
                'replies' => array_map(fn (array $x): array => ['at' => $x['at'], 'by' => $x['by'], 'subject' => $x['subject'], 'text' => $x['text']], $a['replies']),
                'notes' => array_map(fn (array $n): array => ['at' => $n['at'], 'by' => $n['by'], 'text' => $n['text']], Comments::of($db, $target)),
                'tasks' => array_map(fn (array $t): array => ['title' => $t['title'], 'due_at' => $t['due_at'], 'done_at' => $t['done_at'], 'created_at' => $t['created_at']], Tasks::forTarget($db, $target))];
        }, $rows);
    }

    /* ---------- forwards (3.47): the members that moved to ApplicationFiles, ApplicationRetention, ApplicationReplies,
       ApplicationMove and ApplicationCounts keep their old names here, so callers, migration 0120 and the tests are unchanged ---------- */

    public const array CV_EXTENSIONS = ApplicationFiles::CV_EXTENSIONS;
    public const array CONTENT_TYPES = ApplicationFiles::CONTENT_TYPES;
    public const int MAX_FILES = ApplicationFiles::MAX_FILES;
    public const string FOLDER = ApplicationFiles::FOLDER;
    public const string OLD_FOLDER = ApplicationFiles::OLD_FOLDER;
    public const int SEAL_BATCH = ApplicationFiles::SEAL_BATCH;
    public const string FILE_MAGIC = ApplicationFiles::FILE_MAGIC;
    public const int ORPHAN_DAYS = ApplicationFiles::ORPHAN_DAYS;
    public const string ORPHAN_SUFFIX = ApplicationFiles::ORPHAN_SUFFIX;
    public const string FILES_SINCE = ApplicationFiles::FILES_SINCE;
    public const string DB_SEALED = ApplicationFiles::DB_SEALED;
    public const int WARN_DAYS = ApplicationRetention::WARN_DAYS;
    public const string RETENTION_CONFIRMED = ApplicationRetention::RETENTION_CONFIRMED;
    public const int UNCONFIRMED_DAYS = ApplicationRetention::UNCONFIRMED_DAYS;
    public const array TEMPLATES = ApplicationReplies::TEMPLATES;
    public const array TEMPLATE_LABELS = ApplicationReplies::TEMPLATE_LABELS;
    public const int TEMPLATE_MAX = ApplicationReplies::TEMPLATE_MAX;
    public const int SAFE_NAME_MAX = ApplicationReplies::SAFE_NAME_MAX;
    public const int BULK_MAX = ApplicationReplies::BULK_MAX;

    /** @see ApplicationFiles::checkUpload() */
    public static function checkUpload(string $tmpPath, string $originalName): ?string
    {
        return ApplicationFiles::checkUpload($tmpPath, $originalName);
    }

    /** @see ApplicationFiles::contentFits() */
    public static function contentFits(string $extension, string $type): bool
    {
        return ApplicationFiles::contentFits($extension, $type);
    }

    /** @see ApplicationFiles::sealFile() */
    public static function sealFile(Settings $s, string $source, ?string $target = null): ?array
    {
        return ApplicationFiles::sealFile($s, $source, $target);
    }

    /** @see ApplicationFiles::writeSealed() */
    public static function writeSealed(Settings $s, string $plain, string $path): ?string
    {
        return ApplicationFiles::writeSealed($s, $plain, $path);
    }

    /** @see ApplicationFiles::markFilesSince() */
    public static function markFilesSince(Settings $s): void
    {
        ApplicationFiles::markFilesSince($s);
    }

    /** @see ApplicationFiles::backupMissesFiles() */
    public static function backupMissesFiles(int $backupTime, string $filesSince): bool
    {
        return ApplicationFiles::backupMissesFiles($backupTime, $filesSince);
    }

    /** @see ApplicationFiles::headerKey() */
    public static function headerKey(Settings $s, string $file): ?string
    {
        return ApplicationFiles::headerKey($s, $file);
    }

    /** @see ApplicationFiles::openSealed() */
    public static function openSealed(Settings $s, string $file, ?string $key): ?string
    {
        return ApplicationFiles::openSealed($s, $file, $key);
    }

    /** @see ApplicationFiles::sealedTarget() */
    public static function sealedTarget(string $oldPath): string
    {
        return ApplicationFiles::sealedTarget($oldPath);
    }

    /** @see ApplicationFiles::filePath() */
    public static function filePath(array $attachment): ?string
    {
        return ApplicationFiles::filePath($attachment);
    }

    /** @see ApplicationFiles::paths() */
    public static function paths(array $list): ?string
    {
        return ApplicationFiles::paths($list);
    }

    /** @see ApplicationFiles::pathFiles() */
    public static function pathFiles(mixed $paths): array
    {
        return ApplicationFiles::pathFiles($paths);
    }

    /** @see ApplicationFiles::attachmentInfo() */
    public static function attachmentInfo(App $app, int $id, int $index): ?array
    {
        return ApplicationFiles::attachmentInfo($app, $id, $index);
    }

    /** @see ApplicationFiles::attachment() */
    public static function attachment(App $app, int $id, int $index): ?array
    {
        return ApplicationFiles::attachment($app, $id, $index);
    }

    /** @see ApplicationFiles::attachments() */
    public static function attachments(Settings $s, mixed $stored): array
    {
        return ApplicationFiles::attachments($s, $stored);
    }

    /** @see ApplicationFiles::attachmentsLocked() */
    public static function attachmentsLocked(Settings $s, mixed $stored): bool
    {
        return ApplicationFiles::attachmentsLocked($s, $stored);
    }

    /** @see ApplicationFiles::encodeAttachments() */
    public static function encodeAttachments(Settings $s, array $list): ?string
    {
        return ApplicationFiles::encodeAttachments($s, $list);
    }

    /** @see ApplicationFiles::lockedCount() */
    public static function lockedCount(App $app): int
    {
        return ApplicationFiles::lockedCount($app);
    }

    /** @see ApplicationFiles::lockFiles() */
    public static function lockFiles(Db $db, int $wait): bool
    {
        return ApplicationFiles::lockFiles($db, $wait);
    }

    /** @see ApplicationFiles::unlockFiles() */
    public static function unlockFiles(Db $db): void
    {
        ApplicationFiles::unlockFiles($db);
    }

    /** @see ApplicationFiles::sealPending() */
    public static function sealPending(App $app, int $limit = self::SEAL_BATCH): int
    {
        return ApplicationFiles::sealPending($app, $limit);
    }

    /** @see ApplicationFiles::sealEntry() */
    public static function sealEntry(App $app, int $id, int $index, int $steps = 4): string
    {
        return ApplicationFiles::sealEntry($app, $id, $index, $steps);
    }

    /** @see ApplicationFiles::sweepOrphans() */
    public static function sweepOrphans(App $app): int
    {
        return ApplicationFiles::sweepOrphans($app);
    }

    /** @see ApplicationFiles::resealHeld() */
    public static function resealHeld(App $app): int
    {
        return ApplicationFiles::resealHeld($app);
    }

    /** @see ApplicationFiles::heldCount() */
    public static function heldCount(Db $db): int
    {
        return ApplicationFiles::heldCount($db);
    }

    /** @see ApplicationFiles::unsealedCount() */
    public static function unsealedCount(App $app): int
    {
        return ApplicationFiles::unsealedCount($app);
    }

    /** @see ApplicationRetention::confirmedUpTo() */
    public static function confirmedUpTo(Settings $s): int
    {
        return ApplicationRetention::confirmedUpTo($s);
    }

    /** @see ApplicationRetention::confirmRetention() */
    public static function confirmRetention(App $app): void
    {
        ApplicationRetention::confirmRetention($app);
    }

    /** @see ApplicationRetention::unconfirmed() */
    public static function unconfirmed(App $app): array
    {
        return ApplicationRetention::unconfirmed($app);
    }

    /** @see ApplicationRetention::startingRetention() */
    public static function startingRetention(int $jobMonths, int $enquiryMonths): int
    {
        return ApplicationRetention::startingRetention($jobMonths, $enquiryMonths);
    }

    /** @see ApplicationRetention::adoptRetention() */
    public static function adoptRetention(Db $db, Settings $s, array $movedIds = []): void
    {
        ApplicationRetention::adoptRetention($db, $s, $movedIds);
    }

    /** @see ApplicationRetention::awaitingConfirmation() */
    public static function awaitingConfirmation(App $app): ?array
    {
        return ApplicationRetention::awaitingConfirmation($app);
    }

    /** @see ApplicationRetention::deletionDate() */
    public static function deletionDate(array $row, int $months): ?string
    {
        return ApplicationRetention::deletionDate($row, $months);
    }

    /** @see ApplicationRetention::expired() */
    public static function expired(array $rows, int $months, string $today): array
    {
        return ApplicationRetention::expired($rows, $months, $today);
    }

    /** @see ApplicationRetention::dueForWarning() */
    public static function dueForWarning(array $rows, int $months, string $today, array $warned, bool $hired = false): array
    {
        return ApplicationRetention::dueForWarning($rows, $months, $today, $warned, $hired);
    }

    /** @see ApplicationRetention::purge() */
    public static function purge(App $app): int
    {
        return ApplicationRetention::purge($app);
    }

    /** @see ApplicationReplies::storedTemplates() */
    public static function storedTemplates(string $json): array
    {
        return ApplicationReplies::storedTemplates($json);
    }

    /** @see ApplicationReplies::template() */
    public static function template(Settings $s, string $key, string $language): array
    {
        return ApplicationReplies::template($s, $key, $language);
    }

    /** @see ApplicationReplies::fill() */
    public static function fill(string $text, array $vars): string
    {
        return ApplicationReplies::fill($text, $vars);
    }

    /** @see ApplicationReplies::languageOf() */
    public static function languageOf(Settings $s, array $application): string
    {
        return ApplicationReplies::languageOf($s, $application);
    }

    /** @see ApplicationReplies::compose() */
    public static function compose(Settings $s, array $application, string $key, bool $automatic = false): array
    {
        return ApplicationReplies::compose($s, $application, $key, $automatic);
    }

    /** @see ApplicationReplies::safeName() */
    public static function safeName(string $name): string
    {
        return ApplicationReplies::safeName($name);
    }

    /** @see ApplicationReplies::confirmReceipt() */
    public static function confirmReceipt(App $app, array $content, int $id, string $email): bool
    {
        return ApplicationReplies::confirmReceipt($app, $content, $id, $email);
    }

    /** @see ApplicationReplies::reply() */
    public static function reply(App $app, int $id, string $subject, string $text, ?string $setStatus, string $reason, string $via = 'admin'): array
    {
        return ApplicationReplies::reply($app, $id, $subject, $text, $setStatus, $reason, $via);
    }

    /** @see ApplicationReplies::rejectWithTemplate() */
    public static function rejectWithTemplate(App $app, array $ids, string $reason, string $via = 'admin'): array
    {
        return ApplicationReplies::rejectWithTemplate($app, $ids, $reason, $via);
    }

    /** @see ApplicationMove::movingCondition() */
    public static function movingCondition(Db $db, Settings $s, string $table = '{poptavky}'): ?string
    {
        return ApplicationMove::movingCondition($db, $s, $table);
    }

    /** @see ApplicationMove::movingLeft() */
    public static function movingLeft(Db $db, Settings $s): int
    {
        return ApplicationMove::movingLeft($db, $s);
    }

    /** @see ApplicationCounts::monthCounts() */
    public static function monthCounts(Db $db, string $from, string $to): ?array
    {
        return ApplicationCounts::monthCounts($db, $from, $to);
    }

    /** @see ApplicationCounts::countingChanged() */
    public static function countingChanged(Db $db, string $from, string $to): ?string
    {
        return ApplicationCounts::countingChanged($db, $from, $to);
    }

    /** @see ApplicationCounts::movesIn() */
    public static function movesIn(string $history, string $from, string $to): array
    {
        return ApplicationCounts::movesIn($history, $from, $to);
    }

    /** @see ApplicationFiles::rowFiles() */
    public static function rowFiles(Settings $s, array $row): ?array
    {
        return ApplicationFiles::rowFiles($s, $row);
    }
}
