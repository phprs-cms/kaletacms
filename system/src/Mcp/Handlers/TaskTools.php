<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Handover;
use Kaleta\Core\Language;
use Kaleta\Core\Tasks;
use Kaleta\Mcp\Schema;

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
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function taskToolsDefinitions(): array
    {
        return [
            'list_tasks' => ['The team\'s tasks and follow-ups (read-only, 3.27): title, who it is for, the due moment and its group (overdue | today | upcoming = within 7 days | later = further or no date), what it is about (an enquiry, a page or a collection item with admin_url). '
                . 'filter mine (default: the connected user\'s open tasks), overdue (theirs that are overdue), assignee:<user> (an administrator may list anyone\'s; others only their own), assignee:none (administrators: the tasks nobody active has – handed to nobody or left on a removed or blocked account; give each to someone with save_task) or target:<enquiry|page|item>:<id> (every task about that record – the user must be able to open it).',
                Schema::object(['filter' => Schema::text('mine | overdue | assignee:<user id, e-mail or sign-in name> | assignee:none | target:<enquiry:12 | page:5 | item:9>'), 'status' => Schema::text('open (default) | done'),
                    'limit' => Schema::number('1–200, default 50')])],
            'save_task' => ['Creates a task, or changes one with id (3.27): "remind me in 3 days if they do not answer", "review the price page by 1 Dec". The team\'s to-do – never shown on the site; at the due moment (or the reminder) the person gets a notification in the administration and by e-mail as they chose. '
                . 'For someone else only when both may open the record it is about (an enquiry, a page or a collection item); they are notified. No subtasks, recurrence or time tracking.',
                Schema::object(['id' => Schema::number('ID of a task to change (list_tasks); without it a new task'), 'title' => Schema::text('what to do, up to 200 characters (required for a new task)'),
                    'target' => Schema::text('what it is about: enquiry:<id> | page:<id> | item:<id> | contact:<id> | company:<id> | deal:<id> (list_deals); on a fleet console also fleet_site:<id> | fleet_customer:<id> (list_sites); "none" = nothing'),
                    'assignee' => Schema::text('who does it: a user id, the e-mail or sign-in name of an active account, or "me" (default for a new task)'),
                    'due' => Schema::text('when it is due: YYYY-MM-DD (9:00), YYYY-MM-DD HH:MM, ISO 8601, "today", "tomorrow", "+3 days", "+1 week", "+2 hours"; "none" = no date'),
                    'remind' => Schema::text('an earlier reminder, the same forms as due; "none" = remind at the due moment')])],
            'complete_task' => ['Marks a task done, or open again with done false (3.27). The person it is for, the one who created it, or an administrator.',
                Schema::object(['id' => Schema::number('ID of the task (list_tasks)'), 'done' => ['type' => 'boolean', 'description' => 'true (default) = done, false = open again']], ['id'])],
        ];
    }

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
        // 3.34 review low (b): with applications_claude off no task about an application reaches Claude, on any filter
        $rows = $this->applicationsShut() ? array_values(array_filter($rows, fn (array $t): bool => !str_starts_with((string) ($t['target'] ?? ''), 'application:'))) : $rows;
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
                : (Tasks::cleanTarget($raw) ?? throw new \InvalidArgumentException('target must be enquiry:<id>, page:<id>, item:<id>, contact:<id>, company:<id>, deal:<id>, application:<id>, fleet_site:<id>, fleet_customer:<id> or "none".'));
            if ($fields['target'] !== null && str_starts_with($fields['target'], 'application:')) {
                $this->taskTargetOpen($fields['target']); // 3.34 B: the section Applications and the switch applications_claude
            }
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
        if (!$created) {
            $this->taskNotShut($id); // 3.34 review low (b)
        }
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
        $this->taskNotShut($id); // 3.34 review low (b)
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
                + ['admin_url' => $origin . $this->app->url($about['url'])] + \Kaleta\Mcp\Untrusted::mark(['title']) /* 3.44 (N128): an enquiry's or a deal's title is the visitor's text */ : ['missing' => true])] : [])
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

    /** Claude may not read job applications on this site (applications_claude off – 3.34 review low b). */
    private function applicationsShut(): bool
    {
        return !$this->app->settings()->bool('applications_claude');
    }

    /** A task about an application is refused while applications_claude is off (complete_task, save_task with an id). */
    private function taskNotShut(int $id): void
    {
        $task = $id > 0 ? Tasks::find($this->app->db(), $id) : null;
        if ($task !== null && $this->applicationsShut() && str_starts_with((string) ($task['target'] ?? ''), 'application:')) {
            throw new \DomainException('Claude may not read job applications on this site (Applications settings). Nothing changed.');
        }
    }

    /** The connected user must be able to open the record (its section, an active account) – otherwise an error says why. */
    private function taskTargetOpen(string $target): void
    {
        $section = \Kaleta\Core\Assignment::KINDS[explode(':', $target)[0]];
        if ($section === \Kaleta\Core\Applications::SECTION && !$this->app->settings()->bool('applications_claude')) {
            throw new \DomainException('Claude may not read job applications on this site (Applications settings).'); // 3.34 B
        }
        if (!Handover::mayOpen($this->app->db(), $this->app->settings(), $this->app->auth()->id(), $section)) {
            throw new \DomainException('Only a user who may open ' . (['pages' => 'Pages', 'collections' => 'Collections', 'enquiries' => 'Enquiries', 'contacts' => 'Customers', 'fleet' => 'the fleet console', 'pipeline' => 'Pipeline',
                'applications' => 'Applications', 'quotes' => 'Quotes'][$section]) . ' can see the tasks about it.'); // 3.40 review L2: the quotes too ($section is one of these keys)
        }
    }
}
