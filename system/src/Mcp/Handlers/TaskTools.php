<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Handover;
use Kaleta\Core\Language;
use Kaleta\Core\Tasks;

/**
 * MCP tools of tasks and follow-ups (3.27, Core\Tasks): list_tasks reads them, save_task creates or changes one,
 * complete_task marks one done or open again. The rules are those of the administration (Tasks::mayAssign,
 * Tasks::mayChange); their refusals come in English (Language::runWith). A task never changes the site (the page cache
 * stays, Catalog::SITE_NEUTRAL); a change to a task not about an enquiry is part of the Claude session (Core\Tasks journals
 * it), so undo_agent_session takes it back. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait TaskTools
{
    /** list_tasks */
    private function toolListTasks(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $self = $this->app->auth()->id();
        $filter = trim((string) ($a['filter'] ?? '')) ?: 'mine';
        $status = (string) ($a['status'] ?? 'open');
        if (!in_array($status, ['open', 'done'], true)) {
            throw new \InvalidArgumentException('status must be open or done.');
        }
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        if ($filter === 'mine' || $filter === 'overdue') {
            $rows = Tasks::forUser($db, $self, $filter === 'overdue' ? 'overdue' : $status, $limit);
        } elseif (str_starts_with($filter, 'assignee:') && in_array(strtolower(trim(substr($filter, 9))), ['none', 'nobody'], true)) {
            // the tasks nobody active has (handed to nobody, a removed or blocked account) – the administrator gives each to
            // someone with save_task; for anyone else the refusal of every other assignee filter (N327-1)
            if (!$this->app->auth()->isAdmin()) {
                throw new \DomainException('Only an administrator may list the tasks of someone else – use filter mine.');
            }
            $rows = Tasks::unassigned($db, $status, $limit);
        } elseif (str_starts_with($filter, 'assignee:')) {
            // N327-1: for anyone but an administrator one answer for an unknown and an existing colleague – the filter never
            // tells whether an e-mail or a sign-in name belongs to an account
            try {
                $user = $this->taskPerson(substr($filter, 9), $self);
            } catch (\InvalidArgumentException $e) {
                $user = $this->app->auth()->isAdmin() ? throw $e : 0;
            }
            if ($user !== $self && !$this->app->auth()->isAdmin()) {
                throw new \DomainException('Only an administrator may list the tasks of someone else – use filter mine.');
            }
            $rows = Tasks::forUser($db, $user, $status, $limit);
        } elseif (str_starts_with($filter, 'target:')) {
            $target = Tasks::cleanTarget(substr($filter, 7)) ?? throw new \InvalidArgumentException('target must be enquiry:<id>, page:<id>, item:<id>, contact:<id>, company:<id>, deal:<id>, fleet_site:<id> or fleet_customer:<id>, e.g. "target:page:12".');
            $this->taskTargetOpen($target);
            $rows = array_values(array_filter(Tasks::forTarget($db, $target), fn (array $t): bool => ($t['done_at'] !== null) === ($status === 'done')));
            $rows = array_slice($rows, 0, $limit);
        } else {
            throw new \InvalidArgumentException('filter must be mine, overdue, assignee:<user>, assignee:none or target:<enquiry|page|item>:<id>.');
        }
        $labels = Tasks::labels($db, array_column($rows, 'target'));

        return ['filter' => $filter, 'status' => $status, 'tasks' => array_map(fn (array $t): array => $this->taskOut($t, $labels), $rows), 'total' => count($rows),
            'admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=my_work')]
            + ($rows === [] ? ['next' => 'No tasks here. save_task creates one.'] : []);
    }

    /** save_task */
    private function toolSaveTask(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $self = $this->app->auth()->id();
        $id = (int) ($a['id'] ?? 0);
        $fields = [];
        if (array_key_exists('title', $a)) {
            $fields['title'] = is_scalar($a['title']) ? (string) $a['title'] : '';
        }
        if (array_key_exists('target', $a)) {
            $raw = is_scalar($a['target']) ? trim((string) $a['target']) : '';
            $fields['target'] = in_array(strtolower($raw), ['', 'none', 'null'], true) ? null
                : (Tasks::cleanTarget($raw) ?? throw new \InvalidArgumentException('target must be enquiry:<id>, page:<id>, item:<id>, contact:<id>, company:<id>, deal:<id>, fleet_site:<id>, fleet_customer:<id> or "none".'));
        }
        if (array_key_exists('assignee', $a)) {
            $fields['assignee_id'] = $this->taskPerson($a['assignee'], $self);
        }
        foreach (['due' => 'due_at', 'remind' => 'remind_at'] as $param => $key) {
            if (array_key_exists($param, $a)) {
                $fields[$key] = is_scalar($a[$param]) ? (string) $a[$param] : '';
            }
        }
        $created = $id <= 0;
        try {
            Language::runWith('en', function () use (&$id, $fields, $self, $created): void {
                if ($created) {
                    $id = Tasks::create($this->app, $fields, $self);
                } else {
                    Tasks::update($this->app, $id, $fields, $self);
                }
            }, 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing was saved.');
        }
        $task = Tasks::find($db, $id) ?? throw new \DomainException('The task does not exist.');

        return ['id' => $id, ($created ? 'created' : 'updated') => true, 'task' => $this->taskOut($task, Tasks::labels($db, [$task['target']]))];
    }

    /** complete_task */
    private function toolCompleteTask(string $name, array $a): mixed
    {
        $id = (int) ($a['id'] ?? 0);
        $done = !array_key_exists('done', $a) || filter_var($a['done'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
        try {
            Language::runWith('en', fn () => Tasks::complete($this->app, $id, $this->app->auth()->id(), $done), 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing changed.');
        }
        $task = Tasks::find($this->app->db(), $id) ?? throw new \DomainException('The task does not exist.');

        return ['id' => $id, 'done' => $task['done_at'] !== null] + ($task['done_at'] !== null ? ['done_at' => substr($task['done_at'], 0, 16)] : ['group' => $task['group']]);
    }

    /**
     * A task as the tools show it: who it is for (Handover::person, "(former user)" for a removed account), the moments
     * to the minute, its group and what it is about with the admin link.
     *
     * @param array<string, mixed> $t a row of Tasks::find
     * @param array<string, array<string, mixed>> $labels Tasks::labels
     * @return array<string, mixed>
     */
    private function taskOut(array $t, array $labels): array
    {
        $db = $this->app->db();
        $origin = $this->app->request->origin();
        $about = $t['target'] !== null ? ($labels[$t['target']] ?? null) : null;

        return ['id' => $t['id'], 'title' => $t['title'], 'assignee' => Handover::person($db, $t['assignee_id']), 'group' => $t['group']]
            + ($t['due_at'] !== null ? ['due' => substr($t['due_at'], 0, 16)] : [])
            + ($t['remind_at'] !== null ? ['remind' => substr($t['remind_at'], 0, 16)] : [])
            + ($t['target'] !== null ? ['about' => ['target' => $t['target']] + ($about !== null ? ['title' => $about['title']] + (isset($about['collection']) ? ['collection' => $about['collection']] : [])
                + ['admin_url' => $origin . $this->app->url($about['url'])] : ['missing' => true])] : [])
            + ($t['created_by'] !== null && $t['created_by'] !== $t['assignee_id'] ? ['created_by' => Handover::person($db, $t['created_by'])] : [])
            + ($t['done_at'] !== null ? ['done_at' => substr($t['done_at'], 0, 16)] : [])
            + ($t['request_id'] !== null ? ['request_id' => $t['request_id']] : []);
    }

    /**
     * The person a call names for a task: a user id, the e-mail or the sign-in name of an active account, or "me". A task
     * needs someone ("none" is refused).
     */
    private function taskPerson(mixed $value, int $self): int
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text !== '' && !ctype_digit($text) && !str_contains($text, '@') && !in_array(strtolower($text), ['me', 'none', 'nobody'], true)) {
            $id = (int) $this->app->db()->value('SELECT idu FROM {uzivatele} WHERE user = ? AND blokovat = 0', [$text]);
            if ($id <= 0) {
                throw new \InvalidArgumentException('Nobody signs in as "' . $text . '". Give a user id, the e-mail or sign-in name of an active account, or "me".');
            }

            return $id;
        }

        return Handover::resolve($this->app->db(), $text === '' ? 'me' : $value, $self)
            ?? throw new \InvalidArgumentException('A task needs a person: a user id, the e-mail or sign-in name of an active account, or "me".');
    }

    /** The connected user must be able to open the record (its section, an active account) – otherwise an error says why. */
    private function taskTargetOpen(string $target): void
    {
        $section = \Kaleta\Core\Assignment::KINDS[explode(':', $target)[0]];
        if (!Handover::mayOpen($this->app->db(), $this->app->settings(), $this->app->auth()->id(), $section)) {
            throw new \DomainException('Only a user who may open ' . ['pages' => 'Pages', 'collections' => 'Collections', 'enquiries' => 'Enquiries', 'contacts' => 'Customers', 'fleet' => 'the fleet console', 'pipeline' => 'Pipeline'][$section] . ' can see the tasks about it.');
        }
    }
}
