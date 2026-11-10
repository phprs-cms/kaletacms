<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;
use Kaleta\Core\Tasks;

/**
 * My work (3.24, Core\MyWork): what waits for the signed-in person – their tasks (3.27, first), the enquiries they handle,
 * the pages and items they are responsible for that need a look, and their requests Claude answered. Every signed-in user
 * has it; it is the first menu item for everyone but administrators, who keep the Dashboard first (and get a tile there).
 * Every row links to the place where the work is done; a task is completed or snoozed right here (complete, snooze), and
 * "New task" adds one about nothing in particular – for me or a colleague (task; the form is views/admin/task_form.php).
 * Administrators also get the Team tab: open tasks per person (Tasks::team) and the tasks nobody active has – handed to
 * nobody or left on a removed or blocked account (Tasks::unassigned) – each given to a colleague right there (reassign).
 */
final class MyWork extends Module
{
    public const string IDENT = 'my_work';
    public const string NAME = 'My work';
    public const string GROUP = 'Content';
    public const string ICON = 'moje';
    public const bool FOR_ALL_USERS = true;

    /**
     * The enquiries assigned to me that are still open, spam left out like the "Assigned to me" tile (N324-5) – one COUNT
     * over the key (prirazeno, stav) of migration 0110 – and (3.27) my tasks that are overdue or due today.
     */
    public static function badge(\Kaleta\Core\App $app): int
    {
        $tasks = Tasks::counts($app->db(), $app->auth()->id());
        $count = $tasks['overdue'] + $tasks['today'];
        if (!\Kaleta\Core\Extensions::isEnabled($app->settings(), Enquiries::EXTENSION) || !$app->auth()->hasModule(Enquiries::IDENT)) {
            return $count;
        }

        return $count + Enquiries::countAssigned($app->db(), $app->auth()->id());
    }

    protected function actionList(): Response
    {
        $modules = $this->kernel->modules();

        $draft = $this->app->session->get('task_draft');
        if (is_array($draft) && ($draft['target'] ?? null) === '') {
            $this->app->session->remove('task_draft');
        }

        return $this->view('list', 'My work', ['lists' => \Kaleta\Core\MyWork::lists($this->app, $modules, $this->app->auth()->id()), 'modules' => $modules,
            'team' => $this->app->auth()->isAdmin(),
            // 3.27: "New task" – the shared form of a record's task panel, for a task about nothing; anyone active may get it
            'newTask' => ['action' => $this->url('task'), 'people' => \Kaleta\Admin\TaskPanel::people($this->app, null, $this->app->auth()->id()),
                'draft' => is_array($draft) && ($draft['target'] ?? null) === '' ? (string) ($draft['title'] ?? '') : '']]);
    }

    /**
     * "New task" (3.27): a task about nothing in particular from the shared form (Admin\TaskPanel::fromForm) – for me by
     * default, or for any active colleague (Core\Tasks, who is notified). A refused title is offered again.
     */
    protected function actionTask(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $fields = \Kaleta\Admin\TaskPanel::fromForm($this->request);
            $id = Tasks::create($this->app, ['target' => null] + $fields, $this->app->auth()->id());
        } catch (\DomainException $e) {
            $this->app->session->set('task_draft', ['target' => '', 'title' => mb_substr($this->request->post('title'), 0, Tasks::TITLE)]);

            return $this->back($e->getMessage(), type: 'chyba');
        }
        $task = Tasks::find($this->db, $id);
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'task_added', '#' . $id . ' ' . ($task['title'] ?? ''));
        $for = (int) ($task['assignee_id'] ?? 0);

        return $this->back($for === $this->app->auth()->id() ? 'The task was added.' : t('The task was added for %s – they are notified.', \Kaleta\Core\Assignment::label($this->db, $for)));
    }

    /**
     * The Team tab (3.27, administrators): open tasks per person – overdue, today, upcoming, later – and "Not assigned":
     * every open task nobody active has (Tasks::unassigned), with what it is about and who may take it (TaskPanel::people
     * for its record – the same people the task's own form offers).
     */
    protected function actionTeam(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator sees the tasks of the whole team.', 403);
        }
        $me = $this->app->auth()->id();
        $tasks = Tasks::unassigned($this->db);
        $labels = Tasks::labels($this->db, array_column($tasks, 'target'));
        $people = [];
        foreach ($tasks as $i => $t) {
            $key = (string) $t['target'];
            $people[$key] ??= \Kaleta\Admin\TaskPanel::people($this->app, $t['target'], $me);
            $tasks[$i] += ['about' => $t['target'] !== null ? ($labels[$t['target']] ?? null) : null, 'people' => $people[$key],
                'was' => $t['assignee_id'] !== null ? \Kaleta\Core\Assignment::label($this->db, $t['assignee_id']) : t('nobody')];
        }

        return $this->view('team', 'My work', ['people' => Tasks::team($this->db), 'unassigned' => $tasks, 'modules' => $this->kernel->modules()]);
    }

    /**
     * "Reassign to…" of the Team tab (3.27, administrators; POST id, assignee_id): a task nobody active has goes to a
     * colleague through Tasks::update – the same checks as the task's own form (Tasks::mayAssign), and the person is
     * notified like for any assignment. A task someone has meanwhile is left alone.
     */
    protected function actionReassign(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator sees the tasks of the whole team.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'team');
        }
        $id = $this->request->postInt('id');
        $to = $this->request->postInt('assignee_id');
        $task = Tasks::find($this->db, $id);
        if ($task === null || $task['done_at'] !== null || Tasks::hasActiveAssignee($this->db, $task['assignee_id'])) {
            return $this->back('This task is no longer waiting for someone.', 'team', type: 'chyba');
        }
        if ($to <= 0) {
            return $this->back('Choose who takes the task.', 'team', type: 'chyba');
        }
        try {
            Tasks::update($this->app, $id, ['assignee_id' => $to], $this->app->auth()->id());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'team', type: 'chyba');
        }
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'task_reassigned', self::logged($id, $task));

        return $this->back($to === $this->app->auth()->id() ? 'The task is yours now.'
            : t('The task was given to %s – they are notified.', \Kaleta\Core\Assignment::label($this->db, $to)), 'team');
    }

    /** Marks one of my tasks done (POST id; done=0 opens it again). */
    protected function actionComplete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $done = $this->request->post('done') !== '0';
        $id = $this->request->postInt('id');
        try {
            Tasks::complete($this->app, $id, $this->app->auth()->id(), $done);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), type: 'chyba');
        }
        $task = Tasks::find($this->db, $id);
        $title = (string) ($task['title'] ?? '');
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, $done ? 'task_done' : 'task_reopened', self::logged($id, $task));

        return $this->back($done ? t('Task done: %s', $title) : t('Task open again: %s', $title));
    }

    /** Snoozes one of my tasks (POST id, by=tomorrow|3days – Tasks::SNOOZE). */
    protected function actionSnooze(): Response
    {
        $days = Tasks::SNOOZE[$this->request->post('by') ?: $this->request->get('by')] ?? null; // the button's formaction carries it
        if (!$this->request->isPost() || $days === null) {
            return $this->back();
        }
        $id = $this->request->postInt('id');
        try {
            Tasks::snooze($this->app, $id, $this->app->auth()->id(), $days);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), type: 'chyba');
        }
        $task = Tasks::find($this->db, $id);
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'task_snoozed', self::logged($id, $task));

        return $this->back(t('Task moved to %s.', format_date((string) ($task['due_at'] ?? ''))));
    }

    /**
     * Edit of a task right in My work (3.32, U35; POST id, title, assignee_id, due_date, due_time, remind): the fields of the
     * shared task form (Admin\TaskPanel::fromForm) saved by Tasks::update – who may change the task (its person, who added
     * it, an administrator) and to whom it may go are checked there, as in a record's task panel. remind=keep keeps the
     * reminder; assignee 0 keeps the person.
     */
    protected function actionTaskEdit(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('id');
        try {
            $fields = \Kaleta\Admin\TaskPanel::fromForm($this->request);
            if ($fields['assignee_id'] === 0) {
                unset($fields['assignee_id']);
            }
            if ($this->request->post('remind') === 'keep') {
                unset($fields['remind_at']);
            }
            Tasks::update($this->app, $id, $fields, $this->app->auth()->id());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), type: 'chyba');
        }
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'task_edited', self::logged($id, Tasks::find($this->db, $id)));

        return $this->back('The task was saved.');
    }

    /**
     * "Take it" (3.32, U35; POST idp): a new enquiry nobody handles becomes mine – only while nobody handles it (one UPDATE
     * with prirazeno IS NULL, so two people taking it at once cannot both get it) and only for someone with the Enquiries
     * section of a site with enquiries on. Nobody is e-mailed: I chose it myself.
     */
    protected function actionTake(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), Enquiries::EXTENSION) || !$this->app->auth()->hasModule(Enquiries::IDENT)) {
            return $this->error('You do not have access to this section.', 403);
        }
        $idp = $this->request->postInt('idp');
        $me = $this->app->auth()->id();
        if ($this->db->run('UPDATE {poptavky} SET prirazeno = ? WHERE idp = ? AND prirazeno IS NULL', [$me, $idp])->rowCount() !== 1) {
            return $this->back('Someone already handles this enquiry.', type: 'chyba');
        }
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'enquiry_taken', '#' . $idp);

        return $this->back('The enquiry is yours now.');
    }

    /**
     * What the change log says about a task (N327-3): its id and title – for a task about an enquiry its id and the
     * enquiry instead, since the title may name the customer and the change log outlives the enquiry's deletion and
     * anonymisation (Core\Tasks::forgetTargets, anonymiseTargets), as Admin\TaskActions logs it.
     *
     * @param array<string, mixed>|null $task
     */
    private static function logged(int $id, ?array $task): string
    {
        $target = (string) ($task['target'] ?? '');

        return mb_substr('#' . $id . ' ' . (str_starts_with($target, 'enquiry:') ? '(' . $target . ')' : (string) ($task['title'] ?? '')), 0, 255);
    }
}
