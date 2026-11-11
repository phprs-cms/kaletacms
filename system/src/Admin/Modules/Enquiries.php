<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Enquiries and messages from the site's forms (the Form element in the builder, Front\Forms). Status: 0 new, 1 read, 2 handled.
 * They contain personal data – they delete themselves after the set number of months and can be exported to CSV.
 */
final class Enquiries extends Module
{
    use \Kaleta\Admin\CommentActions; // 3.26: edit and delete a note, @mentions
    use \Kaleta\Admin\TaskActions; // 3.27: the enquiry's tasks and the follow-up buttons

    public const string IDENT = 'enquiries';
    public const string EXTENSION = 'poptavky';
    public const string NAME = 'Enquiries';
    public const string GROUP = 'Workspace';
    public const string ICON = 'poptavky';

    public const array STATUSES = [0 => 'nová', 1 => 'přečtená', 2 => 'vyřízená'];
    private const int PER_PAGE = 50;

    /** The list leaves spam out unless asked for (2.12) – the menu badge and the Dashboard count do the same (N315-10). */
    public const string NOT_SPAM = "kategorie <> 'spam'";

    /** 3.15: new enquiries (not opened yet), as on the Dashboard – spam does not count, as the list does not show it. */
    public static function badge(\Kaleta\Core\App $app): int
    {
        return self::countNew($app->db());
    }

    /** New enquiries as the list shows them (spam left out): the menu badge and the Dashboard. */
    public static function countNew(\Kaleta\Core\Db $db): int
    {
        return (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE stav = 0 AND ' . self::NOT_SPAM . self::stillMoving($db));
    }

    /**
     * 3.34 review M3: while migration 0120 still moves job enquiries to Applications, " AND <condition>" that leaves out the
     * ones still to move – for the counts everyone sees; with visible() (N6) for a person without Applications.
     */
    public static function stillMoving(\Kaleta\Core\Db $db, ?\Kaleta\Core\Settings $settings = null): string
    {
        return ' AND ' . self::visible($db, $settings, false);
    }

    /**
     * The one condition every read of enquiry rows uses (3.34 second review N6; a unit test lists every query on ka_poptavky
     * that returns rows and fails on one without it or an explicit exemption) – never empty:
     *  - an enquiry with an application twin (an application whose source_enquiry_id and received_at are its idp and date –
     *    a database restored from a backup taken around the move, N9) has moved: shown to nobody, and acted on by nobody;
     *  - while migration 0120 still moves, the job enquiries still to move (Applications::movingCondition) – for a person
     *    without Applications ($withApplications false), the retention and anything automatic.
     * $table: the name or alias the query gives ka_poptavky.
     */
    public static function visible(\Kaleta\Core\Db $db, ?\Kaleta\Core\Settings $settings, bool $withApplications, string $table = '{poptavky}'): string
    {
        $settings ??= new \Kaleta\Core\Settings($db);
        $parts = [];
        if ($settings->int('db_version') >= 119) {
            $parts[] = 'NOT EXISTS (SELECT 1 FROM {applications} twin WHERE twin.source_enquiry_id = ' . $table . '.idp AND twin.received_at = ' . $table . '.datum)';
        }
        if (!$withApplications && ($moving = \Kaleta\Core\Applications::movingCondition($db, $settings, $table)) !== null) {
            $parts[] = $moving;
        }

        return $parts === [] ? '1 = 1' : implode(' AND ', $parts);
    }

    /** visible() for the signed-in person: the job enquiries still moving only when they may open Applications. */
    private function visibleHere(): string
    {
        return self::visible($this->db, $this->app->settings(), isset($this->kernel->modules()[\Kaleta\Core\Applications::SECTION]));
    }

    /** One enquiry the signed-in person may see and act on (visibleHere()), with the columns asked for; null otherwise. */
    private function findVisible(int $idp, string $columns): ?array
    {
        return $this->db->one('SELECT ' . $columns . ' FROM {poptavky} WHERE idp = ? AND ' . $this->visibleHere(), [$idp]);
    }

    /** 3.24: open enquiries (new or read) handled by the user, spam left out – the "Assigned to me" tile on the Dashboard. */
    public static function countAssigned(\Kaleta\Core\Db $db, int $userId): int
    {
        return $userId > 0 ? (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE prirazeno = ? AND stav < 2 AND ' . self::NOT_SPAM, [$userId]) : 0;
    }

    protected function actionList(): Response
    {
        // T1 (Q5): the retention of enquiries and applications runs in the daily clean-up job (Notifications::purgePersonalData), never on this GET
        $filter = $this->request->get('status');
        $conditions = match ($filter) {
            'otevrene' => ['stav < 2'],
            'vyrizene' => ['stav = 2'],
            'moje' => ['prirazeno = ' . (int) $this->app->auth()->id()],
            default => [],
        };
        $params = [];
        // the kind from triage (2.12): spam stays out of the list unless asked for; '-' = not sorted yet
        $kind = $this->request->get('category');
        if ($kind === '-') {
            $conditions[] = "kategorie = ''";
        } elseif (isset(\Kaleta\Core\Triage::CATEGORIES[$kind])) {
            $conditions[] = 'kategorie = ?';
            $params[] = $kind;
        } else {
            $kind = '';
            $conditions[] = self::NOT_SPAM;
        }
        $search = mb_substr(trim($this->request->get('search')), 0, 100);
        if ($search !== '') {
            $conditions[] = '(email LIKE ? OR formular LIKE ? OR data LIKE ? OR poznamka LIKE ?)';
            // the data is JSON with \uXXXX instead of diacritics – the search also looks in that form
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            $jsonPattern = '%' . addcslashes(substr((string) json_encode($search), 1, -1), '%_\\') . '%';
            array_push($params, $pattern, $pattern, $jsonPattern, $pattern);
        }
        $whereParts = 'WHERE ' . implode(' AND ', $conditions) . ' AND ' . $this->visibleHere(); // never empty: spam is left out unless asked for
        $pageNumber = max(1, $this->request->getInt('page', 1));
        $rows = $this->db->all('SELECT idp, datum, formular, stranka, tema, zdroj, email, stav, kategorie, priorita, data, prirazeno, anonymizovano FROM {poptavky} ' . $whereParts . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params);
        // 3.31: the deal each enquiry became – a small badge for whoever may open the Pipeline
        $deals = isset($this->kernel->modules()[Pipeline::IDENT]) ? \Kaleta\Core\Deals::forEnquiries($this->db, array_map('intval', array_column($rows, 'idp'))) : [];

        return $this->view('list', 'Enquiries', [
            'enquiries' => $rows,
            'deals' => $deals, 'stages' => $deals !== [] ? \Kaleta\Core\Deals::stages($this->app->settings()) : [],
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM {poptavky} ' . $whereParts, $params),
            'filter' => $filter, 'kind' => $kind, 'spam' => (int) $this->db->value("SELECT COUNT(*) FROM {poptavky} WHERE kategorie = 'spam' AND " . $this->visibleHere()), 'search' => $search, 'pageNumber' => $pageNumber, 'perPage' => self::PER_PAGE,
            'users' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY " . $this->db->sortText("IF(jmeno = '', user, jmeno)")),
            'months' => $this->app->settings()->int('enquiries_months'),
            'expiry' => $this->app->settings()->get('enquiries_expiry') === 'anonymise' ? 'anonymise' : 'delete',
            'applicationMonths' => $this->app->settings()->int('job_applications_months'),
            'contactMonths' => \Kaleta\Core\Contacts::months($this->app->settings()), // 3.28
            'suggestion' => \Kaleta\Core\Jobs::suggestedRetention($this->app->settings()->get('company_country'), $this->app->settings()->get('site_language')),
        ]);
    }

    protected function actionDetail(): Response
    {
        $p = $this->findVisible($this->request->getInt('id'), '*');
        if ($p === null) {
            return $this->error('The enquiry does not exist.', 404);
        }
        if ((int) $p['stav'] === 0) {
            $this->db->update('poptavky', ['stav' => 1], ['idp' => $p['idp']]);
            $p['stav'] = 1;
        }

        // 3.32 (U27): the page is titled by who and what (Core\RecordTitle), the number stays in the breadcrumb of the form
        return $this->view('detail', \Kaleta\Core\RecordTitle::enquiry($p), ['p' => $p, 'data' => json_decode((string) $p['data'], true) ?: [],
            'testimonials' => \Kaleta\Core\Testimonials::ofEnquiry($this->db, (int) $p['idp']),
            // 3.20 (C2): the form and the page the enquiry came from, as links into the builder and the page settings
            'origin' => \Kaleta\Admin\EnquiryOrigin::resolve($this->app, $p),
            // 3.22 (C10): the replies sent from here, and whether the site can send one (SMTP)
            'replies' => \Kaleta\Core\EnquiryReply::history($this->db, $p),
            // 3.25: the internal notes (Core\Comments); the detail shows them in the conversation – 3.26: with the deleted ones as
            // placeholders, the mentions as names, Edit and Delete, and "@" for the people who may open Enquiries
            'thread' => $thread = $this->commentThread(\Kaleta\Core\Comments::enquiry((int) $p['idp']), ['idp' => (int) $p['idp']]),
            'notes' => $thread['comments'],
            'replyBlocked' => \Kaleta\Core\EnquiryReply::unavailable($this->app->settings()),
            'replyTo' => (string) ($this->app->auth()->user()['email'] ?? ''),
            'hasSignature' => \Kaleta\Core\EnquiryReply::signature($this->app, $this->app->auth()->user() ?? []) !== null,
            'replyDraft' => $this->takeReplyDraft((int) $p['idp']),
            'users' => $this->listAssignees((int) $p['prirazeno']),
            // 3.27: the tasks about the enquiry, the follow-up buttons first at the top right after a reply
            'tasks' => $this->taskPanel(\Kaleta\Core\Comments::enquiry((int) $p['idp']), ['idp' => (int) $p['idp']], ['follow_up' => true, 'replied' => $this->takeFollowUp((int) $p['idp'])]),
            // 3.28: the customer of the enquiry (Customers), or "Link to a contact" for one from before 3.28
            'contactBox' => Contacts::box($this->app, $this->kernel->modules(), 'enquiry', (int) $p['idp'], isset($p['contact_id']) ? (int) $p['contact_id'] : null,
                ($p['anonymizovano'] ?? null) === null && \Kaleta\Core\Contacts::normalise((string) $p['email']) !== null),
            // 3.31: the deal the enquiry became, or "Create deal" (for whoever may open the Pipeline)
            'dealBox' => Pipeline::enquiryBox($this->app, $this->kernel->modules(), (int) $p['idp']),
            // 3.34: "Move to Applications" for a person who may open both sections (a job application sent through a general form)
            'toApplication' => isset($this->kernel->modules()[\Kaleta\Core\Applications::SECTION])]);
    }

    /** 3.27: was a reply to this enquiry just sent (actionReply)? Once – the detail offers the follow-up at its top. */
    private function takeFollowUp(int $id): bool
    {
        $replied = (int) $this->app->session->get('task_follow_up') === $id;
        if ($replied) {
            $this->app->session->remove('task_follow_up');
        }

        return $replied;
    }

    /** What a refused reply carried (the form shows it again once), for this enquiry only. @return array{subject: string, text: string}|null */
    private function takeReplyDraft(int $id): ?array
    {
        $draft = $this->app->session->get('enquiry_reply_draft');
        if (!is_array($draft)) {
            return null;
        }
        $this->app->session->remove('enquiry_reply_draft');

        return (int) ($draft['id'] ?? 0) === $id ? ['subject' => (string) ($draft['subject'] ?? ''), 'text' => (string) ($draft['text'] ?? '')] : null;
    }

    /**
     * Sends a reply to the enquiry's e-mail address (3.22, C10, Core\EnquiryReply) as the signed-in user: the site's sender,
     * Reply-To = the user's e-mail, their signature appended; logged on the enquiry, resolved unless "keep open" is ticked.
     */
    protected function actionReply(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'detail', ['id' => $id]);
        }
        try {
            $result = \Kaleta\Core\EnquiryReply::send($this->app, $id, $this->request->post('subject'), $this->request->post('text'), !$this->request->postBool('keep_open'));
        } catch (\DomainException $e) {
            // what was written is not lost: the form shows it again
            $this->app->session->set('enquiry_reply_draft', ['id' => $id, 'subject' => mb_substr($this->request->post('subject'), 0, \Kaleta\Core\EnquiryReply::MAX_SUBJECT), 'text' => mb_substr($this->request->post('text'), 0, \Kaleta\Core\EnquiryReply::MAX_TEXT)]);

            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }
        // 3.32 (R4): a follow-up chosen in the reply form is added at once (the signed-in person's own task, as the buttons do);
        // otherwise the detail offers "Follow up if they do not answer" first (3.27)
        $followUp = $this->replyFollowUp($id, $this->request->post('follow_up'));
        if ($followUp === null) {
            $this->app->session->set('task_follow_up', $id);
        }
        $added = $followUp !== null ? ' ' . t('A follow-up for %s is in My work.', format_date($followUp, true)) : '';
        if ($result['status'] === 'sent') {
            return $this->back(t($result['resolved'] ? 'The reply was sent to %s and the enquiry is resolved.' : 'The reply was sent to %s.', $result['to']) . $added, 'detail', ['id' => $id]);
        }

        return $this->back(t('The reply could not be sent right now (%s). It waits in the mail queue and the site tries again in a few minutes – the history below shows when it goes out.', $result['error']) . $added, 'detail', ['id' => $id], 'chyba');
    }

    /**
     * The follow-up chosen in the reply form (3.32, R4; a key of Admin\TaskPanel::FOLLOW_UP, '' = none): the signed-in
     * person's own task "Follow up: <form>" about the enquiry – the same task as the follow-up buttons (never the customer's
     * name or text in the title: its reminder is e-mailed). Returns its due moment, or null when none was chosen or it failed.
     */
    private function replyFollowUp(int $idp, string $choice): ?string
    {
        $days = \Kaleta\Admin\TaskPanel::FOLLOW_UP[$choice] ?? null;
        $form = $days !== null ? $this->db->value('SELECT formular FROM {poptavky} WHERE idp = ?', [$idp]) : null;
        if ($days === null || $form === null) {
            return null;
        }
        $me = $this->app->auth()->id();
        try {
            $task = \Kaleta\Core\Tasks::create($this->app, ['title' => \Kaleta\Admin\TaskPanel::followUpTitle((string) $form), 'target' => \Kaleta\Core\Comments::enquiry($idp),
                'assignee_id' => $me, 'due_at' => '+' . $days . ' days'], $me);
        } catch (\DomainException) {
            return null; // the reply went out; the buttons on the detail still offer the follow-up
        }
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'task follow-up added', '#' . $idp . ' (task #' . $task . ')');

        return \Kaleta\Core\Tasks::find($this->db, $task)['due_at'] ?? null;
    }

    /**
     * Who can handle an enquiry: active administrators and users with the permission for Enquiries (not e.g. a news author,
     * who does not see them). An already assigned user stays in the list even if they lost the permission in the meantime –
     * saving the note does not silently remove them.
     *
     * @return array<int, string>
     */
    private function listAssignees(int $assignee = 0): array
    {
        return \Kaleta\Core\Assignment::candidates($this->db, 'enquiry', $assignee); // 3.24: one rule for enquiries, pages and items
    }

    /** Internal note and who handles the enquiry. */
    /** Asks the customer of an enquiry for a testimonial (2.12, Core\Testimonials): the link by e-mail, or only to copy. */
    protected function actionTestimonial(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $result = \Kaleta\Core\Testimonials::request($this->app, $id, $this->request->postBool('poslat'));
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }

        return $this->back(t($result['sent'] ? 'The request was sent. The link: %s' : 'The link to send yourself: %s', $result['link']), 'detail', ['id' => $id]);
    }

    /** A person's triage (2.12): the kind, the priority and the drafted reply – Claude and the assistant never overwrite it. */
    protected function actionTriage(): Response
    {
        $id = $this->request->postInt('id');
        if ($this->request->isPost() && $this->findVisible($id, 'idp') !== null) {
            $user = $this->app->auth()->user();
            \Kaleta\Core\Triage::save($this->db, $id, \Kaleta\Core\Triage::clean($this->request->post('kategorie'), $this->request->postInt('priorita'), $this->request->post('navrh_odpovedi')),
                (string) ($user['jmeno'] ?? '') !== '' ? (string) $user['jmeno'] : (string) ($user['user'] ?? 'admin'));
        }

        return $this->back('Saved.', 'detail', ['id' => $id]);
    }

    /**
     * Two forms post here: the note composer of the conversation (add_note – appended as a new note, 3.25) and the form
     * with who handles the enquiry and its summary (poznamka – the one editable note it had before 3.25).
     */
    protected function actionNote(): Response
    {
        $idp = $this->request->postInt('idp');
        if ($this->request->isPost() && $this->request->hasPost('add_note')) {
            if ($this->findVisible($idp, 'idp') === null) {
                return $this->error('The enquiry does not exist.', 404);
            }
            try {
                // 3.26: the mentions checked and the people mentioned notified (Core\Comments::post)
                $note = \Kaleta\Core\Comments::post($this->app, \Kaleta\Core\Comments::enquiry($idp), $this->request->post('add_note'), $this->app->auth()->user() ?? []);
            } catch (\DomainException $e) {
                $this->app->session->set('comment_draft', ['target' => \Kaleta\Core\Comments::enquiry($idp), 'comment' => 0, 'text' => mb_substr($this->request->post('add_note'), 0, \Kaleta\Core\Comments::MAX_TEXT)]);

                return $this->back($e->getMessage(), 'detail', ['id' => $idp], 'chyba');
            }
            \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'note added', '#' . $idp . ' (comment #' . $note['id'] . ')');

            return $this->back('The note was added.', 'detail', ['id' => $idp]);
        }
        if ($this->request->isPost() && $this->request->hasPost('poznamka')) {
            $found = $this->findVisible($idp, 'prirazeno');
            if ($found === null) {
                return $this->error('The enquiry does not exist.', 404);
            }
            $who = $this->request->postInt('prirazeno');
            $before = $found['prirazeno'];
            $after = $who > 0 && isset($this->listAssignees((int) $before)[$who]) ? $who : null;
            $this->db->update('poptavky', ['poznamka' => mb_substr(trim($this->request->post('poznamka')), 0, 5000), 'prirazeno' => $after], ['idp' => $idp]);
            if ($after !== null && $after !== ($before === null ? null : (int) $before)) {
                // 3.24: one e-mail to the new person handling it – never to oneself, never with the enquiry's content
                \Kaleta\Core\Assignment::notify($this->app, 'enquiry', $idp, $after, $this->app->auth()->id());
            }
        }

        return $this->back('The note has been saved.', 'detail', ['id' => $idp]);
    }

    /** 3.26: the enquiry whose notes a form edits or deletes (the composer posts to actionNote). */
    protected function commentTarget(): ?array
    {
        $enquiry = $this->db->one('SELECT idp, formular FROM {poptavky} WHERE idp = ? AND ' . $this->visibleHere(), [$this->request->postInt('idp')]); // N6

        return $enquiry === null ? null : ['target' => \Kaleta\Core\Comments::enquiry((int) $enquiry['idp']), 'back' => ['detail', ['id' => (int) $enquiry['idp']]], 'title' => (string) $enquiry['formular']];
    }

    /** 3.27: the enquiry a task form is about. */
    protected function taskTarget(): ?array
    {
        $record = $this->commentTarget();

        return $record === null ? null : $record + ['review' => null];
    }

    /** Form attachment for download (only for a signed-in user with access to enquiries). */
    protected function actionAttachment(): Response
    {
        $p = $this->findVisible($this->request->getInt('id'), 'data');
        $item = ($p !== null ? (json_decode((string) $p['data'], true) ?: []) : [])[$this->request->getInt('field')] ?? null;
        $path = is_array($item) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', (string) ($item[2] ?? '')) ? KALETA_ROOT . '/storage/prilohy/' . $item[2] : null;
        // 3.34 second review N9: a CV sealed for Applications and then restored as an enquiry (a backup older than the move) opens
        // from its encrypted copy – the key of its header
        $content = $path !== null && is_file($path) ? (string) file_get_contents($path)
            : ($path !== null ? \Kaleta\Core\Applications::openSealed($this->app->settings(), \Kaleta\Core\Applications::FOLDER . '/' . \Kaleta\Core\Applications::sealedTarget((string) $item[2]), null) : null);
        if ($path === null || $content === null) {
            return $this->error('The attachment no longer exists.', 404);
        }
        $displayName = preg_replace('/ \([^)]*\)$/', '', (string) $item[1]) ?: basename($path);

        return new Response($content, 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($displayName)]);
    }

    /** In bulk: mark as handled, or delete (including attachments). */
    protected function actionBulk(): Response
    {
        $ids = array_map('intval', $this->request->postList('oznacene'));
        // N6: only the enquiries this person may see
        $ids = $ids === [] ? [] : array_map('intval', array_column($this->db->all('SELECT idp FROM {poptavky} WHERE idp IN (' . implode(',', $ids) . ') AND ' . $this->visibleHere()), 'idp'));
        if (!$this->request->isPost() || $ids === []) {
            return $this->back();
        }
        $v = implode(',', $ids);
        if ($this->request->post('provest') === 'smazat') {
            self::deleteAttachments($this->db->all('SELECT idp, data FROM {poptavky} WHERE idp IN (' . $v . ')'), $this->db);
            \Kaleta\Core\Deals::forgetEnquiries($this->db, $ids); // N331-2: a deal from one stays, without the link and (3.32) the person's words – before the rows go
            $this->db->run('DELETE FROM {poptavky} WHERE idp IN (' . $v . ')');
            \Kaleta\Core\Comments::forgetEnquiries($this->db, $ids);

            return $this->back(t('Enquiries deleted: %d.', count($ids)));
        }
        $this->db->run('UPDATE {poptavky} SET stav = 2 WHERE idp IN (' . $v . ')');

        return $this->back(t('Enquiries resolved: %d.', count($ids)));
    }

    /**
     * The files of enquiries that go. 3.34 second review N9: a file an application still lists (attachment_paths – an
     * enquiry restored next to its application twin, a CV not sealed yet) is never removed.
     *
     * @param list<array<string, mixed>> $rows each with data (and idp)
     */
    public static function deleteAttachments(array $rows, \Kaleta\Core\Db $db): void
    {
        $paths = [];
        foreach ($rows as $r) {
            foreach (json_decode((string) $r['data'], true) ?: [] as $item) {
                if (is_array($item) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.[a-z0-9]{2,5}$#D', (string) ($item[2] ?? ''))) {
                    $paths[] = (string) $item[2];
                }
            }
        }
        foreach (array_diff($paths, self::listedByApplications($db, $paths)) as $path) {
            @unlink(KALETA_ROOT . '/storage/prilohy/' . $path);
        }
    }

    /**
     * Which of these storage/prilohy paths an application still lists (attachment_paths, old); [] before migration 0119.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private static function listedByApplications(\Kaleta\Core\Db $db, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $listed = [];
        try {
            foreach (array_chunk(array_values(array_unique($paths)), 50) as $chunk) {
                $like = implode(' OR ', array_fill(0, count($chunk), 'attachment_paths LIKE ?'));
                foreach ($db->all('SELECT attachment_paths FROM {applications} WHERE ' . $like, array_map(fn (string $p): string => '%' . addcslashes($p, '%_\\') . '%', $chunk)) as $r) {
                    $old = json_decode((string) $r['attachment_paths'], true);
                    foreach (is_array($old) && is_array($old['old'] ?? null) ? $old['old'] : [] as $path) {
                        $listed[] = (string) $path;
                    }
                }
            }
        } catch (\PDOException $e) {
            // Before migration 0119 (no table or column) nothing is listed. Any other error must not read as
            // "nothing listed": then every path counts as listed, so no file an application may still need is deleted.
            return in_array((string) $e->getCode(), ['42S02', '42S22'], true) ? [] : array_values(array_unique($paths));
        }

        return array_values(array_intersect($paths, $listed));
    }

    protected function actionStatus(): Response
    {
        if ($this->request->isPost() && $this->findVisible($this->request->postInt('idp'), 'idp') !== null) {
            $state = $this->request->postInt('stav');
            $this->db->update('poptavky', ['stav' => isset(self::STATUSES[$state]) ? $state : 1], ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back($this->request->postInt('stav') === 2 ? 'The enquiry is resolved.' : 'The enquiry is open again.');
    }

    /**
     * 3.34: "Move to Applications" on the detail – one enquiry that is really a job application goes to Applications
     * (Core\ApplicationMove, the steps of the move by form), for a person who may open both sections.
     */
    protected function actionToApplication(): Response
    {
        $idp = $this->request->postInt('idp');
        if (!$this->request->isPost() || !isset($this->kernel->modules()[\Kaleta\Core\Applications::SECTION])) {
            return $this->back('You cannot open Applications.', 'detail', ['id' => $idp], 'chyba');
        }
        try {
            $result = \Kaleta\Core\ApplicationMove::enquiryToApplication($this->app, $idp);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $idp], 'chyba'); // 3.34 second review N4: another move runs
        }
        if ($result === null) {
            return $this->back('The enquiry does not exist.', '', [], 'chyba');
        }
        $this->app->session->flash('ok', t('The enquiry was moved to Applications.')
            . ($result['deals'] !== [] ? ' ' . t('Deals that lost their link to the enquiry: %s.', '#' . implode(', #', $result['deals'])) : ''));

        return Response::redirect($this->app->url(\Kaleta\Core\Applications::adminPath($result['id'])));
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost() && $this->findVisible($this->request->postInt('idp'), 'idp') !== null) {
            self::deleteAttachments($this->db->all('SELECT idp, data FROM {poptavky} WHERE idp = ?', [$this->request->postInt('idp')]), $this->db);
            \Kaleta\Core\Deals::forgetEnquiries($this->db, [$this->request->postInt('idp')]); // N331-2: a deal from it stays, without the link and (3.32) the person's words – before the row goes
            $this->db->delete('poptavky', ['idp' => $this->request->postInt('idp')]);
            \Kaleta\Core\Comments::forgetEnquiries($this->db, [$this->request->postInt('idp')]);
        }

        return $this->back('The enquiry was deleted.');
    }

    /**
     * A personal data request (2.14, Core\PersonalData): everything the site keeps about one e-mail address, as a file for
     * the person or erased. Administrators only – it reaches subscribers and queues beyond the enquiries.
     */
    protected function actionPersonal(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can handle personal data requests.', 403);
        }
        $email = \Kaleta\Core\PersonalData::normalise($this->request->isPost() ? $this->request->post('email') : '');
        $do = $this->request->isPost() ? $this->request->post('provest') : '';
        if ($email !== null && $do === 'export') {
            return new Response(\Kaleta\Core\PersonalData::export($this->app, $email), 200, ['Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="personal-data-' . date('Y-m-d') . '.json"']);
        }
        if ($email !== null && $do === 'erase') {
            if (!$this->request->postBool('potvrzeno')) {
                return $this->back('Tick that you want to erase the data.', 'personal', [], 'chyba');
            }
            $result = \Kaleta\Core\PersonalData::erase($this->app, $email);
            $message = t('Erased: %d enquiries, %d subscriptions, %d e-mails in the queue, %d testimonial requests.', $result['erased']['enquiries'], $result['erased']['subscriber'], $result['erased']['mail'], $result['erased']['testimonials'])
                . (($result['erased']['applications'] ?? 0) > 0 ? ' ' . t('Job applications erased: %d.', $result['erased']['applications']) : '') // 3.34
                . (($result['erased']['applications_held'] ?? 0) > 0 ? ' ' . t('%d job application(s) were not erased: their CV list cannot be opened (encryption key) and their files cannot be located. Put the vault_key line back into config.php, then erase again.', $result['erased']['applications_held']) : '');
            if ($result['kept_testimonials'] !== []) {
                $message .= ' ' . t('A testimonial the person sent stays in References (items %s) – remove it there if they ask.', implode(', ', $result['kept_testimonials']));
            }

            return $this->back($message, 'personal');
        }
        if ($this->request->isPost() && $email === null) {
            return $this->back('Enter a valid e-mail address.', 'personal', [], 'chyba');
        }

        return $this->view('personal', t('Personal data request'), ['email' => $email ?? '', 'found' => $email !== null ? \Kaleta\Core\PersonalData::find($this->db, $email, $this->app) : null]);
    }

    /** Saving the periods after which enquiries and job applications delete themselves (administrator only). */
    protected function actionSettings(): Response
    {
        if ($this->request->isPost() && $this->app->auth()->isAdmin()) {
            $this->app->settings()->set('enquiries_months', (string) max(0, min(120, $this->request->postInt('mesice'))));
            $this->app->settings()->set('job_applications_months', (string) max(0, min(120, $this->request->postInt('mesice_uchazeci'))));
            if ($this->request->hasPost('contacts_months')) {
                // 3.28: the customers' retention, next to the enquiries' (a form from before 3.28 leaves it as it is)
                $this->app->settings()->set('contacts_months', (string) max(0, min(120, $this->request->postInt('contacts_months'))));
            }
            $this->app->settings()->set('triage_assistant', $this->request->postBool('triage_assistant') ? '1' : '0');
            $this->app->settings()->set('enquiries_expiry', $this->request->post('po_uplynuti') === 'anonymise' ? 'anonymise' : 'delete');
        }

        return $this->back('Enquiry settings saved.');
    }

    /** All enquiries to CSV (UTF-8 with BOM, semicolon – opens directly in Excel). */
    protected function actionCsv(): Response
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Number'), t('Date'), t('Form'), t('Status'), t('Email'), t('Page'), t('Campaign'), t('Content')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {poptavky} WHERE ' . $this->visibleHere() . ' ORDER BY idp') as $p) {
            $content = implode("\n", array_map(fn (array $d): string => $d[0] . ': ' . $d[1], json_decode((string) $p['data'], true) ?: []));
            // a cell starting with = + - @ would run as a formula in a spreadsheet
            $row = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $p['idp'], (string) $p['datum'], (string) $p['formular'], t(self::STATUSES[(int) $p['stav']] ?? ''), (string) $p['email'], (string) $p['stranka'], \Kaleta\Front\Forms::campaignText((string) ($p['kampan'] ?? '')), $content]);
            fputcsv($f, $row, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Admin\ChangeLog::write($this->app, 'enquiries', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="poptavky-' . date('Y-m-d') . '.csv"']);
    }

    /**
     * Enquiries older than the set number of months: deleted including attachments, or – with enquiries_expiry = anonymise
     * (2.14) – kept as rows without the person for statistics (Core\Privacy). Also called by the background cleanup (Core\Notifications).
     */
    public static function deleteExpired(\Kaleta\Core\Db $db, \Kaleta\Core\Settings $siteSettings): void
    {
        \Kaleta\Core\Comments::sweep($db); // 3.25: notes of an enquiry some other path deleted
        $months = $siteSettings->int('enquiries_months');
        if ($months <= 0) {
            return;
        }
        // 3.31 (D-W6): an enquiry of a deal, or of a customer with a deal, stays while the deal is open and for the retention
        // after it closed (Contacts::heldByDeals → Core\Deals::heldEnquiries)
        $before = \Kaleta\Core\Clock::monthsAgo($months);
        $anonymise = $siteSettings->get('enquiries_expiry') === 'anonymise';
        // N6: never a job enquiry migration 0120 is still to move (it becomes an application with its own retention), never a moved twin
        $visible = ' AND ' . self::visible($db, $siteSettings, false);
        $expired = array_map('intval', array_column($db->all('SELECT idp FROM {poptavky} WHERE datum < ?' . ($anonymise ? ' AND anonymizovano IS NULL' : '') . $visible, [$before]), 'idp'));
        $held = \Kaleta\Core\Contacts::heldByDeals($db, $expired, $months);
        $keep = ($held !== [] ? ' AND idp NOT IN (' . implode(',', $held) . ')' : '') . $visible;
        $expired = array_values(array_diff($expired, $held));
        \Kaleta\Core\Contacts::enquiriesExpiring($db, $siteSettings, $expired); // 3.28: the customer keeps "first contact on … through …"
        if ($anonymise) {
            \Kaleta\Core\Privacy::anonymise($db, $expired);

            return;
        }
        self::deleteAttachments($db->all('SELECT idp, data FROM {poptavky} WHERE datum < ?' . $keep, [$before]), $db);
        \Kaleta\Core\Deals::forgetEnquiries($db, $expired); // 3.31: a deal from it stays, without the link and (3.32) the person's words – before the rows go
        $db->run('DELETE FROM {poptavky} WHERE datum < ?' . $keep, [$before]);
        \Kaleta\Core\Comments::forgetEnquiries($db, $expired); // 3.25: the notes go with their enquiry
    }

    /** Blanks everything about the person in one enquiry and keeps the row (2.14, Core\Privacy). */
    protected function actionAnonymise(): Response
    {
        $idp = $this->request->postInt('idp');
        if ($this->request->isPost() && \Kaleta\Core\Privacy::anonymise($this->db, [$idp]) > 0) {
            \Kaleta\Admin\ChangeLog::write($this->app, 'enquiries', 'anonymise', '#' . $idp);
        }

        return $this->back('The enquiry was anonymised – the row stays for statistics without the person.', 'detail', ['id' => $idp]);
    }
}
