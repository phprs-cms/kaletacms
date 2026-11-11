<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Module;
use Kaleta\Core\App;
use Kaleta\Core\Applications as Store;
use Kaleta\Core\Assignment;
use Kaleta\Core\Auth;
use Kaleta\Core\Response;

/**
 * Job applications (3.34, Core\Applications): the list and the detail of the applications that the job pages and the
 * forms with the Purpose "job application" send – apart from Enquiries, for the people an administrator chooses. The
 * section is never a default of a level (GRANTED_EXPLICITLY): after the update only administrators open it, and the list
 * tells them so until somebody else has it (the ready-made role HR in Roles). Nothing here ever changes the public site
 * (Kernel::SITE_NEUTRAL_MODULES).
 *
 *  - list: tabs by status with their counts (open = not decided, the default; waiting = new without a reply for more
 *    than a week; every status; all), the position, the responsible person ("Mine", nobody, a person), "kept for future
 *    openings" and a word (name, e-mail, text); the number, received, name, position, status, responsible person and the
 *    day it will be deleted; paging; with selected: a status, a responsible person, delete (administrators).
 *  - bulk: the selected applications at once.
 *  - detail: the fields, the CVs (Download), the status (rejected asks for a reason), the responsible person, the consent
 *    for future openings (withdraw), the day it will be deleted, the replies sent, the status history, the team's notes
 *    and tasks (target application:N), Delete.
 *  - attachment: the decrypted CV as a download (application/octet-stream, nosniff) – one change-log line each.
 *  - status, assign, consent, delete: the changes (Core\Applications writes the change log of status and assign).
 *  - settings, settings_save (3.34 B, administrators): the retention, the pool period, the notification and Claude
 *    switches, the reply templates per language, who may open the section.
 *  - search_json (3.34 B): the command palette finds applications by number, name or position.
 *  - move, move_run (3.34 B, administrators): enquiries of a chosen form to applications and back, in batches.
 *  - reply (3.34 B): an e-mail to the applicant from a template (received, invitation, rejection – Settings tab) or not,
 *    with the sender's signature, moving the status with it.
 *
 * The polished screens of part B build on these actions: reply with templates, bulk actions, the settings tab and the
 * move from enquiries by form.
 */
final class Applications extends Module
{
    use \Kaleta\Admin\CommentActions;
    use \Kaleta\Admin\TaskActions;

    public const string IDENT = Store::SECTION;
    public const string NAME = 'Applications';
    public const string GROUP = 'Workspace';
    public const string ICON = 'uzivatele';
    public const string EXTENSION = Store::EXTENSION;
    public const bool GRANTED_EXPLICITLY = true;

    /** Rows per page of the list. */
    public const int PAGE = 50;

    /** The tabs of the list: the status filter => label ('' = all). */
    public const array TABS = ['open' => 'Open', 'waiting' => 'Waiting', 'new' => 'New', 'reviewed' => 'Reviewed', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected', '' => 'All'];

    /** New applications (the number next to the menu item). */
    public static function badge(App $app): int
    {
        return Store::newCount($app->db());
    }

    protected function actionList(): Response
    {
        $me = $this->app->auth()->id();
        $query = ['status' => $this->request->has('status') ? $this->request->get('status') : 'open', 'job' => $this->request->get('job'),
            'assignee' => $this->request->get('assignee') === 'me' ? (string) $me : $this->request->get('assignee'), 'pool' => $this->request->get('pool'), 'search' => $this->request->get('search')];
        $page = max(1, $this->request->getInt('page', 1));
        $result = Store::list($this->app, $query, $page, self::PAGE);
        $assignees = array_values(array_filter(array_column($result['rows'], 'assignee_id')));

        return $this->view('list', 'Applications', ['result' => $result, 'page' => $page, 'pages' => max(1, (int) ceil($result['total'] / self::PAGE)),
            'counts' => Store::counts($this->db), 'jobs' => Store::byJob($this->db), 'people' => Assignment::candidates($this->db, 'application'),
            'names' => Assignment::labels($this->db, $assignees), 'me' => $me,
            // B-2: until somebody besides the administrators has the section, the administrators are told to choose
            'nobody' => $this->app->auth()->isAdmin() && $this->db->value('SELECT 1 FROM {uzivatele_prava} p JOIN {uzivatele} u ON u.idu = p.fk_id_user WHERE p.ident_modulu = ? AND u.admin <> ? AND u.blokovat = 0 LIMIT 1',
                [Store::SECTION, Auth::ADMIN]) === null,
            'admin' => $this->app->auth()->isAdmin()]);
    }

    /**
     * The selected applications at once (3.34 B): a status (rejected with its reason), a responsible person, or – for
     * administrators only – deletion for good (HR rejects; the retention deletes).
     */
    protected function actionBulk(): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->request->postList('ids')), fn (int $id): bool => $id > 0)));
        if (!$this->request->isPost() || $ids === []) {
            return $this->back('Select at least one application.', '', [], 'chyba');
        }
        $me = $this->app->auth()->id();
        $do = $this->request->post('do');
        if ($do === 'delete') {
            if (!$this->app->auth()->isAdmin()) {
                return $this->error('Only an administrator can delete applications – reject them instead; the retention deletes them.', 403);
            }

            return $this->back(t('Applications deleted, with their CVs, notes and tasks: %d.', Store::delete($this->app, $ids)));
        }
        $changed = 0;
        foreach ($ids as $id) {
            try {
                $changed += (int) match ($do) {
                    'status' => Store::setStatus($this->app, $id, $this->request->post('status'), $this->request->post('reject_reason'), $me),
                    'assign' => Store::assign($this->app, $id, $this->request->postInt('assignee_id'), $me),
                    default => false,
                };
            } catch (\DomainException) {
                continue; // an application deleted meanwhile, or an unknown status – the others still change
            }
        }

        return $this->back(t('Applications changed: %d.', $changed));
    }

    protected function actionDetail(): Response
    {
        $a = Store::get($this->app, $this->request->getInt('id'));
        if ($a === null) {
            return $this->error('The application does not exist.', 404);
        }
        $target = Store::target($a['id']);
        $people = Assignment::candidates($this->db, 'application', (int) $a['assignee_id']);
        // 3.34 B: the reply – a refused one comes back as written, a chosen template fills the form (?template=…, no script)
        $draft = $this->app->session->get('application_reply_draft');
        $this->app->session->set('application_reply_draft', null);
        $template = $this->request->get('template');
        $reply = is_array($draft) && (int) ($draft['id'] ?? 0) === $a['id'] ? ['subject' => (string) $draft['subject'], 'text' => (string) $draft['text'], 'template' => (string) ($draft['template'] ?? '')]
            : (isset(Store::TEMPLATES[$template]) ? Store::compose($this->app->settings(), $a, $template) + ['template' => $template] : ['subject' => '', 'text' => '', 'template' => '']);
        $user = $this->app->auth()->user() ?? [];

        return $this->view('detail', t('Application #%s', (string) $a['id']), ['a' => $a, 'people' => $people,
            'names' => Assignment::labels($this->db, array_values(array_filter([$a['assignee_id'], ...array_column($a['history'], 'by')]))),
            'deletesOn' => Store::deletionDate($a, $this->app->settings()->int('applications_months')),
            'thread' => $this->commentThread($target, ['id' => $a['id']]), 'tasks' => $this->taskPanel($target, ['id' => $a['id']]),
            'others' => Store::others($this->db, $a['id'], is_string($a['email_norm'] ?? null) ? $a['email_norm'] : null), // 3.34 B: other applications from this address
            'me' => $this->app->auth()->id(), 'admin' => $this->app->auth()->isAdmin(),
            'reply' => $reply, 'replies' => \Kaleta\Core\EnquiryReply::history($this->db, ['replies' => (string) json_encode($a['replies'])]), 'replyBlocked' => \Kaleta\Core\EnquiryReply::unavailable($this->app->settings()), 'replyTo' => (string) ($user['email'] ?? ''),
            'hasSignature' => $user !== [] && \Kaleta\Core\EnquiryReply::signature($this->app, $user) !== null]);
    }

    /**
     * A reply to the applicant (3.34 B, Core\Applications::reply): the text as written – from a template or not –, the
     * signature, and the status it moves the application to. What a refused reply carried is shown again.
     */
    protected function actionReply(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back('', 'detail', ['id' => $id]);
        }
        $setStatus = $this->request->post('status'); // '' = keep it (a new one becomes reviewed)
        try {
            $result = Store::reply($this->app, $id, $this->request->post('subject'), $this->request->post('text'), $setStatus !== '' ? $setStatus : null, $this->request->post('reject_reason'));
        } catch (\DomainException $e) {
            $this->app->session->set('application_reply_draft', ['id' => $id, 'subject' => mb_substr($this->request->post('subject'), 0, \Kaleta\Core\EnquiryReply::MAX_SUBJECT),
                'text' => mb_substr($this->request->post('text'), 0, \Kaleta\Core\EnquiryReply::MAX_TEXT), 'template' => mb_substr($this->request->post('template'), 0, 20)]);

            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }
        if ($result['status'] === 'sent') {
            return $this->back(t('The reply was sent to %s.', $result['to']), 'detail', ['id' => $id]);
        }

        return $this->back(t('The reply could not be sent right now (%s). It waits in the mail queue and the site tries again in a few minutes – the history below shows when it goes out.', $result['error']), 'detail', ['id' => $id], 'chyba');
    }

    /** The CV, decrypted in memory and sent as a download – never inline, never cached; one change-log line. */
    protected function actionAttachment(): Response
    {
        $id = $this->request->getInt('id');
        $file = Store::attachment($this->app, $id, $this->request->getInt('index'));
        if ($file === null) {
            return $this->error('The attachment no longer exists.', 404);
        }
        ChangeLog::write($this->app, Store::SECTION, 'attachment', t('CV downloaded, application #%s', (string) $id));

        return new Response($file['content'], 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => "attachment; filename*=UTF-8''" . rawurlencode($file['name'] !== '' ? $file['name'] : 'attachment')]);
    }

    protected function actionStatus(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $changed = Store::setStatus($this->app, $id, $this->request->post('status'), $this->request->post('reject_reason'), $this->app->auth()->id());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }

        return $this->back($changed ? 'The status was changed.' : '', 'detail', ['id' => $id]);
    }

    protected function actionAssign(): Response
    {
        $id = $this->request->postInt('id');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $changed = Store::assign($this->app, $id, $this->request->postInt('assignee_id'), $this->app->auth()->id());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), '', [], 'chyba');
        }

        return $this->back($changed ? 'The responsible person was saved.' : '', 'detail', ['id' => $id]);
    }

    /** The applicant withdrew the consent for future openings: the normal retention applies. */
    protected function actionConsent(): Response
    {
        $id = $this->request->postInt('id');

        return $this->request->isPost() && Store::withdrawConsent($this->app, $id)
            ? $this->back('The consent was withdrawn – the application follows the normal retention.', 'detail', ['id' => $id]) : $this->back('', 'detail', ['id' => $id]);
    }

    /** Deleting for good is an administrator's (3.34 B): HR rejects an application, the retention deletes it. */
    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can delete applications – reject them instead; the retention deletes them.', 403);
        }

        return Store::delete($this->app, [$this->request->postInt('id')]) > 0 ? $this->back('The application was deleted, with its CVs, notes and tasks.') : $this->back();
    }

    /**
     * The command palette (Ctrl K, 3.34 B): applications by number, name or position – only for people with the section
     * (the palette asks only when the module is theirs); never by e-mail or the fields.
     */
    protected function actionSearchJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['applications' => []]);
        }
        $number = preg_match('/^#?(\d{1,9})$/D', $q, $m) === 1 ? (int) $m[1] : 0;
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = $this->db->all('SELECT id, name, job_title, status FROM {applications} WHERE id = ? OR name LIKE ? OR job_title LIKE ? ORDER BY id = ? DESC, id DESC LIMIT 6', [$number, $like, $like, $number]);

        return Response::json(['applications' => array_map(fn (array $r): array => ['name' => (string) $r['name'] !== '' ? (string) $r['name'] : t('Application #%s', (string) $r['id']),
            'note' => implode(' · ', array_filter(['#' . (int) $r['id'], (string) $r['job_title'], t(Store::STATUS_LABELS[(string) $r['status']] ?? (string) $r['status'])])),
            'url' => $this->url('detail', ['id' => (int) $r['id']])], $rows)]);
    }

    /* ---------- settings (3.34 B, administrators) ---------- */

    /**
     * The settings tab: the retention, the pool period, the notification switch, the Claude switch, the reply templates per
     * language and who may open the section. Administrators only (Admin\Kernel: the section is site-neutral).
     */
    protected function actionSettings(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of applications.', 403);
        }
        $s = $this->app->settings();
        $templates = [];
        foreach (self::siteLanguages($s) as $code => $_) {
            foreach (Store::TEMPLATES as $key => $_t) {
                $templates[$code][$key] = Store::template($s, $key, $code);
            }
        }
        $suggestion = \Kaleta\Core\Jobs::suggestedRetention($s->get('company_country'), $s->get('site_language'));
        $admins = array_flip(array_map('intval', array_column($this->db->all('SELECT idu FROM {uzivatele} WHERE admin = ? AND blokovat = 0', [Auth::ADMIN]), 'idu')));

        return $this->view('settings', 'Applications', [
            'values' => ['months' => $s->int('applications_months'), 'pool' => max(1, $s->int('applications_pool_months')), 'full' => $s->bool('applications_notify_full'), 'claude' => $s->bool('applications_claude')],
            'languages' => self::siteLanguages($s), 'templates' => $templates,
            'people' => array_map(fn (int $id, string $name): array => ['id' => $id, 'name' => $name, 'admin' => isset($admins[$id])], array_keys($candidates = Assignment::candidates($this->db, 'application')), $candidates),
            'practice' => $suggestion !== null ? t('Usual practice in %s: %d months – check with your lawyer.', $suggestion[0], $suggestion[1]) : null]);
    }

    protected function actionSettingsSave(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the settings of applications.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'settings');
        }
        $s = $this->app->settings();
        $s->set('applications_months', (string) max(0, min(120, $this->request->postInt('applications_months'))));
        $s->set('applications_pool_months', (string) max(1, min(60, $this->request->postInt('applications_pool_months'))));
        $s->set('applications_notify_full', $this->request->postBool('applications_notify_full') ? '1' : '0');
        $s->set('applications_claude', $this->request->postBool('applications_claude') ? '1' : '0');
        $posted = [];
        foreach (self::siteLanguages($s) as $code => $_) {
            foreach (Store::TEMPLATES as $key => $_t) {
                $posted[$code][$key] = ['subject' => $this->request->post('tpl_' . $code . '_' . $key . '_subject'), 'text' => $this->request->post('tpl_' . $code . '_' . $key . '_text')];
            }
        }
        $s->set('application_templates', self::templatesToStore($s, $posted));
        ChangeLog::write($this->app, Store::SECTION, 'settings', '');

        return $this->back('The settings of applications were saved.', 'settings');
    }

    /**
     * The templates as stored: per language of the site and template, only a text that differs from the built-in one (an
     * empty text = the built-in one again).
     *
     * @param array<string, array<string, array{subject: string, text: string}>> $posted code => key => the fields tpl_<code>_<key>_subject|text
     */
    private static function templatesToStore(\Kaleta\Core\Settings $s, array $posted): string
    {
        $out = [];
        foreach (self::siteLanguages($s) as $code => $_) {
            foreach (Store::TEMPLATES as $key => [$subject, $text]) {
                $row = $posted[$code][$key] ?? null;
                if ($row === null || trim($row['text']) === '') {
                    continue;
                }
                $mine = ['subject' => trim($row['subject']), 'text' => trim(str_replace("\r\n", "\n", $row['text']))];
                $builtIn = \Kaleta\Core\Language::runWith($code, fn (): array => ['subject' => t($subject), 'text' => t($text)]);
                if ($mine !== $builtIn) {
                    $out[$code][$key] = $mine;
                }
            }
        }

        return $out === [] ? '' : (string) json_encode(Store::storedTemplates((string) json_encode($out)), JSON_UNESCAPED_UNICODE);
    }

    /**
     * The languages of the site: code => name, the default language first.
     *
     * @return array<string, string>
     */
    private static function siteLanguages(\Kaleta\Core\Settings $s): array
    {
        $out = [];
        foreach ([\Kaleta\Core\Language::defaults($s), ...\Kaleta\Core\Language::additional($s)] as $code) {
            $out[$code] = \Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code;
        }

        return $out;
    }

    /* ---------- move by form (3.34 B, administrators) ---------- */

    /**
     * The forms of the enquiries (those that look like applications first) with Move to Applications, and the forms of the
     * applications with Move back to Enquiries – for a career form that is not on a job's item page (Core\ApplicationMove).
     */
    protected function actionMove(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can move enquiries to applications.', 403);
        }

        return $this->view('move', 'Applications', ['enquiryForms' => \Kaleta\Core\ApplicationMove::enquiryForms($this->db),
            'applicationForms' => \Kaleta\Core\ApplicationMove::applicationForms($this->db), 'batch' => \Kaleta\Core\ApplicationMove::BATCH]);
    }

    /** One batch of the chosen form, either way; safe to run again – it says how many are left. */
    protected function actionMoveRun(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can move enquiries to applications.', 403);
        }
        $form = \Kaleta\Core\ApplicationMove::parseKey($this->request->post('form'));
        if (!$this->request->isPost() || $form === null) {
            return $this->back('Choose a form.', 'move', [], 'chyba');
        }
        if ($this->request->post('direction') === 'back') {
            $result = \Kaleta\Core\ApplicationMove::toEnquiries($this->app, $form['form'], $form['source']);

            return $this->back(t('Moved back to Enquiries: %d. Left of this form: %d.', $result['moved'], $result['left']), 'move');
        }
        $result = \Kaleta\Core\ApplicationMove::toApplications($this->app, $form['form'], $form['source']);

        return $this->back(t('Moved to Applications: %d. Left of this form: %d.', $result['moved'], $result['left'])
            . ($result['deals'] !== [] ? ' ' . t('Deals that lost their link to the enquiry: %s.', '#' . implode(', #', $result['deals'])) : '')
            . ($result['contacts_deleted'] > 0 ? ' ' . t('Customer records made only by these enquiries were deleted: %d.', $result['contacts_deleted']) : ''), 'move');
    }

    /* ---------- comments and tasks (Admin\CommentActions, Admin\TaskActions) ---------- */

    /** The application a note or task form is about ("id"); its change-log rows say "application" – never the applicant. A note or task counts as activity. */
    protected function commentTarget(): ?array
    {
        $id = $this->request->postInt('id');
        if ($this->db->value('SELECT 1 FROM {applications} WHERE id = ?', [$id]) === null) {
            return null;
        }
        Store::touch($this->db, $id);

        return ['target' => Store::target($id), 'back' => ['detail', ['id' => $id]], 'title' => 'application'];
    }

    protected function taskTarget(): ?array
    {
        $record = $this->commentTarget();

        return $record === null ? null : $record + ['review' => null];
    }
}
