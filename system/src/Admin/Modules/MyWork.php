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
 * Every row links to the place where the work is done; a task is completed or snoozed right here (complete, snooze).
 * Administrators also get the Team tab: open tasks per person (Tasks::team).
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

        return $this->view('list', 'My work', ['lists' => \Kaleta\Core\MyWork::lists($this->app, $modules, $this->app->auth()->id()), 'modules' => $modules,
            'team' => $this->app->auth()->isAdmin()]);
    }

    /** The Team tab (3.27, administrators): open tasks per person – overdue, today, upcoming, later. */
    protected function actionTeam(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator sees the tasks of the whole team.', 403);
        }

        return $this->view('team', 'My work', ['people' => Tasks::team($this->db)]);
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
        $title = (string) (Tasks::find($this->db, $id)['title'] ?? '');
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, $done ? 'task_done' : 'task_reopened', '#' . $id . ' ' . $title);

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
        \Kaleta\Admin\ChangeLog::write($this->app, self::IDENT, 'task_snoozed', '#' . $id . ' ' . ($task['title'] ?? ''));

        return $this->back(t('Task moved to %s.', format_date((string) ($task['due_at'] ?? ''))));
    }
}
