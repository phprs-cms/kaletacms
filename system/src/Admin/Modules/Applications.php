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
 *  - list: tabs by status with their counts (open = not decided, the default; every status; all), the position, the
 *    responsible person ("Mine", nobody, a person), "kept for future openings" and a word (name, e-mail, text); the
 *    number, received, name, position, status, responsible person and the day it will be deleted; paging.
 *  - detail: the fields, the CVs (Download), the status (rejected asks for a reason), the responsible person, the consent
 *    for future openings (withdraw), the day it will be deleted, the replies sent, the status history, the team's notes
 *    and tasks (target application:N), Delete.
 *  - attachment: the decrypted CV as a download (application/octet-stream, nosniff) – one change-log line each.
 *  - status, assign, consent, delete: the changes (Core\Applications writes the change log of status and assign).
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
    public const array TABS = ['open' => 'Open', 'new' => 'New', 'reviewed' => 'Reviewed', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected', '' => 'All'];

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
                [Store::SECTION, Auth::ADMIN]) === null]);
    }

    protected function actionDetail(): Response
    {
        $a = Store::get($this->app, $this->request->getInt('id'));
        if ($a === null) {
            return $this->error('The application does not exist.', 404);
        }
        $target = Store::target($a['id']);
        $people = Assignment::candidates($this->db, 'application', (int) $a['assignee_id']);

        return $this->view('detail', t('Application #%s', (string) $a['id']), ['a' => $a, 'people' => $people,
            'names' => Assignment::labels($this->db, array_values(array_filter([$a['assignee_id'], ...array_column($a['history'], 'by')]))),
            'deletesOn' => Store::deletionDate($a, $this->app->settings()->int('applications_months')),
            'thread' => $this->commentThread($target, ['id' => $a['id']]), 'tasks' => $this->taskPanel($target, ['id' => $a['id']]),
            'me' => $this->app->auth()->id()]);
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

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }

        return Store::delete($this->app, [$this->request->postInt('id')]) > 0 ? $this->back('The application was deleted, with its CVs, notes and tasks.') : $this->back();
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
