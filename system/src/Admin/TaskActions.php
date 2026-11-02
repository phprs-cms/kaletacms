<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Assignment;
use Kaleta\Core\Response;
use Kaleta\Core\Tasks;

/**
 * The tasks of a record (3.27, Core\Tasks) in the module that owns it – Enquiries (the detail, with the follow-up buttons),
 * Pages (page settings) and Collections (the item form). One action, "task": add (op=add, also the quick days and the task
 * for the review date), follow_up (an enquiry's "Follow up tomorrow / in 3 days / in a week" – the signed-in person's own
 * task titled after the form, never after what the customer wrote), edit, done, reopen, snooze, delete and claude (hand
 * the task to Claude as a request – Core\Tasks::toRequest, people with the Requests section). The admin's kernel checks the
 * form token and the section; the module says which record the form is about (taskTarget), and a task id alone never
 * reaches a task of another record. Tasks never change the public site (Admin\Kernel::SITE_NEUTRAL_ACTIONS).
 */
trait TaskActions
{
    /**
     * The record the posted task form is about: its target ('enquiry:<idp>' …), the screen to return to ([action, params]),
     * its title (change log, default titles) and its review date (pages and items); null when it does not exist (or is in
     * the trash).
     *
     * @return array{target: string, back: array{0: string, 1: array<string, int|string>}, title: string, review?: ?string}|null
     */
    abstract protected function taskTarget(): ?array;

    protected function actionTask(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $record = $this->taskTarget();
        if ($record === null) {
            return $this->error('What the task is about does not exist (or is in the trash).', 404);
        }
        $target = $record['target'];
        $me = $this->app->auth()->id();
        $op = $this->request->post('op');
        $id = $this->request->postInt('task');
        $back = $this->url($record['back'][0], $record['back'][1]) . '#tasks';
        try {
            if (!in_array($op, ['add', 'follow_up'], true) && (Tasks::find($this->db, $id)['target'] ?? null) !== $target) {
                throw new \DomainException(t('The task does not exist.'));
            }
            [$done, $message, $id] = match ($op) {
                'add' => $this->addTask($record, $me),
                'follow_up' => $this->followUp($record, $me),
                'edit' => $this->editTask($id, $me),
                default => $this->changeTask($op, $id, $me),
            };
        } catch (\DomainException $e) {
            if ($op === 'add') {
                // what was written is not lost: the form shows it again once (taskPanel)
                $this->app->session->set('task_draft', ['target' => $target, 'title' => mb_substr($this->request->post('title'), 0, Tasks::TITLE)]);
            }
            $this->app->session->flash('chyba', $e->getMessage());

            return Response::redirect($back);
        }
        ChangeLog::write($this->app, static::IDENT, 'task ' . $done, mb_substr('#' . explode(':', $target)[1] . ' ' . $record['title'] . ' (task #' . $id . ')', 0, 255));
        $this->app->session->flash('ok', $message);

        return Response::redirect($back);
    }

    /**
     * Done, open again, snooze (Tasks::SNOOZE), delete, or hand to Claude (only with the Requests section).
     *
     * @return array{0: string, 1: string, 2: int} what the change log says, the message, the task's id
     */
    private function changeTask(string $op, int $id, int $me): array
    {
        switch ($op) {
            case 'done':
            case 'reopen':
                Tasks::complete($this->app, $id, $me, $op === 'done');

                return [$op === 'done' ? 'done' : 'reopened', $op === 'done' ? 'The task is done.' : 'The task is open again.', $id];
            case 'snooze':
                $days = Tasks::SNOOZE[$this->request->post('snooze')] ?? throw new \DomainException(t('Choose when the task should come back.'));
                Tasks::snooze($this->app, $id, $me, $days);

                return ['snoozed', t('The task comes back on %s.', format_date((string) (Tasks::find($this->db, $id)['due_at'] ?? ''), true)), $id];
            case 'delete':
                Tasks::delete($this->app, $id, $me);

                return ['deleted', 'The task was deleted.', $id];
            case 'claude':
                if (!isset($this->kernel->modules()['requests'])) {
                    throw new \DomainException(t('Only someone with Ask Claude can hand a task to Claude.'));
                }

                return ['handed to Claude', t('The task was handed to Claude as request #%s – it is done here.', Tasks::toRequest($this->app, $id, $me)), $id];
        }

        throw new \DomainException(t('The task does not exist.'));
    }

    /**
     * "Add a task": the form's fields; with "review" the title defaults to "Review: <title>" and the due day is the record's
     * review date (from the database, never from the form).
     *
     * @param array{target: string, title: string, review?: ?string} $record
     * @return array{0: string, 1: string, 2: int} what the change log says, the message, the new task's id
     */
    private function addTask(array $record, int $me): array
    {
        $review = $this->request->postBool('review') ? ($record['review'] ?? null) : null;
        if ($this->request->postBool('review') && $review === null) {
            throw new \DomainException(t('There is no review date to plan for.'));
        }
        $fields = TaskPanel::fromForm($this->request);
        if ($review !== null) {
            $fields['due_at'] = Tasks::parseMoment(TaskPanel::due($review, $this->request->post('due_time')));
            $fields['remind_at'] = TaskPanel::remindAt($fields['due_at'], $this->request->post('remind'));
            $fields['title'] = $fields['title'] !== '' ? $fields['title'] : TaskPanel::reviewTitle($record['title']);
        }
        $id = Tasks::create($this->app, $fields + ['target' => $record['target']], $me);
        $for = (int) (Tasks::find($this->db, $id)['assignee_id'] ?? $me);

        return ['added', $for === $me ? 'The task was added.' : t('The task was added for %s – they are notified.', Assignment::label($this->db, $for)), $id];
    }

    /**
     * A follow-up of an enquiry: the signed-in person's own task "Follow up: <form>" due in 1, 3 or 7 days.
     *
     * @param array{target: string, title: string} $record
     * @return array{0: string, 1: string, 2: int}
     */
    private function followUp(array $record, int $me): array
    {
        $days = TaskPanel::FOLLOW_UP[$this->request->post('days')] ?? throw new \DomainException(t('Choose when to follow up.'));
        $id = Tasks::create($this->app, ['title' => TaskPanel::followUpTitle($record['title']), 'target' => $record['target'], 'assignee_id' => $me,
            'due_at' => '+' . $days . ' days'], $me);

        return ['follow-up added', t('A follow-up for %s is in My work.', format_date((string) (Tasks::find($this->db, $id)['due_at'] ?? ''), true)), $id];
    }

    /**
     * The in-place edit of a task: title, person, due moment and reminder ('keep' leaves a reminder set elsewhere alone).
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function editTask(int $id, int $me): array
    {
        $fields = TaskPanel::fromForm($this->request);
        if ($fields['assignee_id'] === 0) {
            unset($fields['assignee_id']);
        }
        if ($this->request->post('remind') === 'keep') {
            unset($fields['remind_at']);
        }
        Tasks::update($this->app, $id, $fields, $me);

        return ['edited', 'The task was saved.', $id];
    }

    /**
     * What views/admin/tasks_panel.php needs: the record's tasks with the names and what the signed-in person may do, who
     * may get a new one, a refused title to show again, the follow-up buttons (enquiries; $options['follow_up'], 'replied'
     * right after a reply) and the task for the review date ($options['review'] = the date, 'title' = the record's title –
     * left out once an open task is due that day).
     *
     * @param array<string, int|string> $fields the hidden fields that name the record (idp, ids, idk)
     * @param array{follow_up?: bool, replied?: bool, review?: ?string, title?: string} $options
     * @return array<string, mixed>
     */
    protected function taskPanel(string $target, array $fields, array $options = []): array
    {
        $me = $this->app->auth()->id();
        $admin = $this->app->auth()->isAdmin();
        $tasks = Tasks::forTarget($this->db, $target);
        $names = Assignment::labels($this->db, array_merge(array_column($tasks, 'assignee_id'), array_column($tasks, 'created_by'), array_column($tasks, 'done_by')));
        $people = TaskPanel::people($this->app, $target, $me);
        $review = $options['review'] ?? null;
        foreach ($tasks as &$task) {
            $assignee = (int) $task['assignee_id'];
            $task['who'] = $assignee > 0 ? ($names[$assignee] ?? '') : t('nobody');
            $task['by'] = $names[(int) $task['created_by']] ?? '';
            $task['done_by_name'] = $names[(int) $task['done_by']] ?? '';
            $task['may'] = $me > 0 && ($admin || $assignee === $me || (int) $task['created_by'] === $me); // Tasks::mayChange
            $task['people'] = $assignee > 0 && !isset($people[$assignee]) ? $people + [$assignee => $task['who']] : $people;
            $task['remind_choice'] = TaskPanel::remindChoice($task['due_at'], $task['remind_at']);
            if ($review !== null && $task['done_at'] === null && substr((string) $task['due_at'], 0, 10) === $review) {
                $review = null; // a task for that day exists
            }
        }
        unset($task);
        $draft = $this->app->session->get('task_draft');
        if (is_array($draft)) {
            $this->app->session->remove('task_draft');
        }

        return ['target' => $target, 'tasks' => $tasks, 'fields' => $fields, 'action' => $this->url('task'), 'people' => $people, 'me' => $me,
            'claude' => isset($this->kernel->modules()['requests']), 'requestUrl' => $this->app->url('admin.php?module=requests&action=detail&id='),
            'draft' => is_array($draft) && ($draft['target'] ?? '') === $target ? (string) ($draft['title'] ?? '') : '',
            'followUp' => (bool) ($options['follow_up'] ?? false), 'replied' => (bool) ($options['replied'] ?? false),
            'review' => $review !== null ? ['date' => $review, 'title' => TaskPanel::reviewTitle((string) ($options['title'] ?? ''))] : null];
    }
}
