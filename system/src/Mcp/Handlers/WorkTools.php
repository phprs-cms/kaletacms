<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Handover;
use Kaleta\Mcp\Schema;

/**
 * MCP tools of the team's work (3.24): my_work – the lists of the admin screen My work for the connected user – and
 * assign – the responsible person of a page or a collection item, or who handles an enquiry. Responsibility only: it
 * never limits access; the person must be able to open the section. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait WorkTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function workToolsDefinitions(): array
    {
        return [
            'my_work' => ['What waits for the connected user (read-only) – My work of the administration, each list only for a section the user may open: open tasks (tasks: overdue | today | upcoming | later); enquiries assigned to them or handled by nobody, read or not, while still new or no older than 30 days (form, about, status – no personal data); pages and items they are responsible for with a review due within 14 days, unpublished changes or unresolved comments; requests where Claude wrote last; open deals (deals); with Quotes, own and unowned drafts (quotes_drafts), sent ones unanswered a week (quotes_waiting), validity ending within 7 days (quotes_expiring) – no amounts. Every row has admin_url.',
                Schema::object([])],
            'assign' => ['Makes a person responsible for a page or a collection item, or the one who handles an enquiry (3.24; the connected user must be able to open that section). Responsibility only – it shows in that person\'s My work and e-mails them a link when it is someone else; it never changes who may edit. The person must be able to open the section too, otherwise nothing changes. user "none" removes the responsible person.',
                Schema::object(['target' => Schema::text('page | item | enquiry | application'), 'id' => Schema::number('ID of the page (list_pages), the collection item (list_collection_items), the enquiry (list_enquiries) or the job application (list_applications)'),
                    'user' => Schema::text('a user id, the e-mail of an active account, "me" or "none"')], ['target', 'id', 'user'])],
            'list_colleagues' => ['The colleagues of the connected user (read-only, 3.37): everyone with an active account on the site – name, position, work phone and e-mail as each of them entered it in My account; 3.42: department, reports_to (a colleague id), and while away today absent_until and deputy (who stands in), plus departments (the org chart: id, name, parent, head). Blocked accounts are not listed; nothing else of the accounts.', Schema::object([])],
            'team_summary' => ['How much of the team\'s work happened in a period (read-only, 3.41) – counts only, never hours, money or deal values: open enquiries each person handles and enquiries answered, open, overdue and done tasks, deal moves, deals won and lost, job applications handled, quotes sent and accepted – each only for a section the user may open. team = the totals; you = the user\'s own; people (administrators only) = the counts of each person.',
                Schema::object(['period' => Schema::text('7_days | 30_days (default) | this_month | last_month | 90_days')])],
        ];
    }

    /**
     * list_colleagues (3.37, Core\Colleagues): who works here – name, position, work phone, e-mail; empty values left out.
     * 3.42: each one's department, reports_to and (away today) absent_until and deputy, and the departments (the org chart:
     * id, name, parent, head) – work data only, never the reason of an absence.
     */
    private function toolListColleagues(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $all = \Kaleta\Core\Colleagues::all($db);
        $people = \Kaleta\Core\Colleagues::compact($all);
        $departments = \Kaleta\Core\Colleagues::compactDepartments(\Kaleta\Core\Colleagues::chart($db, $all));

        return ['count' => count($people), 'you' => $this->app->auth()->id(), 'colleagues' => $people] + ($departments !== [] ? ['departments' => $departments] : []);
    }

    /**
     * team_summary (3.41, Core\TeamSummary): the team's counts of a period for the sections the user may open – job
     * applications only while the site lets Claude read them; the counts of each person only for an administrator.
     */
    private function toolTeamSummary(string $name, array $a): mixed
    {
        $userId = $this->app->auth()->id();
        $sections = \Kaleta\Core\TeamSummary::sectionsFor((new \Kaleta\Admin\Kernel($this->app))->modules());
        if (!$this->app->settings()->bool('applications_claude')) {
            $sections = array_values(array_diff($sections, ['applications']));
        }
        $admin = $this->app->auth()->isAdmin();
        $summary = \Kaleta\Core\TeamSummary::build($this->app, $sections, is_string($a['period'] ?? null) ? $a['period'] : '', $userId, $admin, false);

        return \Kaleta\Core\TeamSummary::compact($summary) + ['note' => 'Counts only. enquiries_open, tasks_open and tasks_overdue are counted as they are now, the rest within the period.'
            . ($admin ? ' people: administrators only – never put the counts of a person into anything that leaves the administration.' : ' The counts of each person are for administrators only.'),
            'admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=my_work&action=summary&period=' . $summary['period'])];
    }

    /** my_work */
    private function toolMyWork(string $name, array $a): mixed
    {
        $userId = $this->app->auth()->id();
        $lists = \Kaleta\Core\MyWork::lists($this->app, (new \Kaleta\Admin\Kernel($this->app))->modules(), $userId);
        $origin = $this->app->request->origin();
        $row = function (array $r) use ($origin): array {
            $out = ['id' => $r['id'], 'title' => $r['title']];
            foreach (['collection', 'about', 'status', 'priority', 'reasons', 'review_by', 'valid_until', 'date'] as $key) {
                if (isset($r[$key]) && $r[$key] !== '' && $r[$key] !== []) {
                    $out[$key] = $key === 'date' ? substr((string) $r[$key], 0, 16) : $r[$key];
                }
            }
            if (($r['comments'] ?? 0) > 0) {
                $out['unresolved_comments'] = $r['comments'];
            }

            return $out + ['admin_url' => $origin . $this->app->url($r['url'])];
        };
        // 3.27: the open tasks as list_tasks shows them (only additive keys: tasks)
        $tasks = $lists['tasks'] ?? [];
        unset($lists['tasks']);
        $keys = ['assigned' => 'enquiries_assigned', 'unassigned' => 'enquiries_unassigned', 'pages' => 'pages', 'items' => 'items', 'requests' => 'requests', 'deals' => 'deals',
            'applications' => 'applications_assigned', 'applications_new' => 'applications_unassigned', // 3.34 B: additive keys
            'quotes_drafts' => 'quotes_drafts', 'quotes_waiting' => 'quotes_waiting', 'quotes_expiring' => 'quotes_expiring']; // 3.44: by number and title, never an amount or the customer
        // 3.34 B: job applications only while the site lets Claude read them; by number, position and status – never the name
        if (!$this->app->settings()->bool('applications_claude')) {
            unset($lists['applications'], $lists['applications_new']);
        }
        foreach (['applications', 'applications_new'] as $applicationList) {
            if (isset($lists[$applicationList])) {
                $lists[$applicationList] = array_map(fn (array $r): array => ['id' => $r['id'], 'title' => $r['title'] !== '' ? $r['title'] : 'no position', 'status' => $r['status'], 'date' => $r['date'], 'url' => $r['url']], $lists[$applicationList]);
            }
        }
        $out = ['user' => Handover::person($this->app->db(), $userId)];
        // 3.31: the open deals the user is responsible for (additive key deals) – the stage and expected close, no customer
        $deals = $lists['deals'] ?? null;
        unset($lists['deals']);
        if ($tasks !== []) {
            $out['tasks'] = array_map(fn (array $t): array => ['id' => $t['id'], 'title' => $t['title'], 'group' => $t['group']]
                + ($t['due_at'] !== null ? ['due' => substr((string) $t['due_at'], 0, 16)] : [])
                + ($t['about'] !== null ? ['about' => ['title' => $t['about']['title'], 'admin_url' => $origin . $this->app->url($t['about']['url'])]] : []), $tasks);
        }
        foreach ($lists as $list => $rows) {
            $out[$keys[$list]] = array_map($row, $rows);
        }
        if ($deals !== null) {
            $out['deals'] = array_map(fn (array $d): array => ['id' => $d['id'], 'title' => $d['title'], 'stage' => $d['stage'], 'stage_label' => $d['stage_label']]
                + ($d['expected_close'] !== null ? ['expected_close' => $d['expected_close']] : []) + ($d['overdue'] ? ['overdue' => true] : [])
                + ['admin_url' => $origin . $this->app->url($d['url'])], $deals);
        }
        $total = array_sum(array_map(count(...), $lists)) + count($tasks) + count($deals ?? []);
        // 3.44 (N128): enquiries are titled by the visitor's name and words, deals made from them too, tasks name what they are about;
        // quotes prepared from enquiries are titled from the customer's words (N144)
        $marked = array_values(array_intersect(['tasks', 'enquiries_assigned', 'enquiries_unassigned', 'deals', 'quotes_drafts', 'quotes_waiting', 'quotes_expiring'], array_keys($out)));

        return $out + ($marked !== [] ? \Kaleta\Mcp\Untrusted::mark($marked) : []) + ['total' => $total,
            'reasons' => 'tasks: group overdue | today | upcoming (7 days) | later (complete_task, save_task to snooze); overdue = the review date has passed, review = review due within ' . \Kaleta\Core\MyWork::REVIEW_DAYS . ' days, draft = unpublished changes, comments = unresolved comments on the draft, hidden = a hidden item changed lately; quotes (3.44): prepared = a draft Kaleta made from an enquiry, unowned = nobody is responsible yet, no_answer = sent ' . \Kaleta\Core\MyWork::QUOTE_WAITING_DAYS . ' or more days ago without a decision, expires = the validity ends within ' . \Kaleta\Core\MyWork::QUOTE_EXPIRING_DAYS . ' days (get_quote, mark_quote)',
            'next' => $total === 0 ? 'Nothing waits for this user.' : 'Tell the user what waits and where (admin_url). Enquiries contain personal data – open one with list_enquiries only when the user asks.'];
    }

    /** assign */
    private function toolAssign(string $name, array $a): mixed
    {
        $kind = (string) ($a['target'] ?? '');
        if ($kind === 'application') {
            return $this->assignApplication($a); // 3.34 B: the section Applications and the switch applications_claude
        }
        $section = Handover::KINDS[$kind][0] ?? throw new \InvalidArgumentException('target must be page, item or enquiry (or application).');
        if (!isset((new \Kaleta\Admin\Kernel($this->app))->modules()[$section])) {
            throw new \DomainException('Only a user who may open ' . ['pages' => 'Pages', 'collections' => 'Collections', 'enquiries' => 'Enquiries'][$section] . ' can change who is responsible there.');
        }
        if (!array_key_exists('user', $a)) {
            throw new \InvalidArgumentException('user is required: a user id, the e-mail of an active account, "me" or "none".');
        }
        $id = (int) ($a['id'] ?? 0);
        $userId = Handover::resolve($this->app->db(), $a['user'], $this->app->auth()->id());
        $result = Handover::assign($this->app, $kind, $id, $userId);

        return ['target' => $kind, 'id' => $id, ($kind === 'enquiry' ? 'assignee' : 'owner') => Handover::person($this->app->db(), $userId), 'changed' => $result['changed'],
            'previous' => Handover::person($this->app->db(), $result['previous'])];
    }

    /**
     * assign with target application (3.34 B, Core\Applications::assign): the connected user and the person must be able to
     * open Applications, and the site must let Claude read applications; the person is notified by number and position.
     */
    private function assignApplication(array $a): array
    {
        $this->applicationsOpen();
        if (!array_key_exists('user', $a)) {
            throw new \InvalidArgumentException('user is required: a user id, the e-mail of an active account, "me" or "none".');
        }
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $previous = $db->value('SELECT assignee_id FROM {applications} WHERE id = ?', [$id]);
        if ($db->value('SELECT 1 FROM {applications} WHERE id = ?', [$id]) === null) {
            throw new \InvalidArgumentException('The application does not exist. Use list_applications.');
        }
        $userId = Handover::resolve($db, $a['user'], $this->app->auth()->id());
        if ($userId !== null && !Handover::mayOpen($db, $this->app->settings(), $userId, \Kaleta\Core\Applications::SECTION)) {
            throw new \DomainException('This user cannot open Applications (or the account is blocked) – give the work to someone who can, or ask an administrator to give them the section.');
        }
        $changed = \Kaleta\Core\Language::runWith('en', fn (): bool => \Kaleta\Core\Applications::assign($this->app, $id, $userId, $this->app->auth()->id()), 'admin-');

        return ['target' => 'application', 'id' => $id, 'assignee' => Handover::person($db, $userId), 'changed' => $changed, 'previous' => Handover::person($db, $previous)];
    }
}
