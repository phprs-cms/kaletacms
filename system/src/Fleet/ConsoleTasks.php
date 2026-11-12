<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\Clock;
use Kaleta\Core\Db;
use Kaleta\Core\Tasks;

/**
 * Console tasks (3.30): the 3.27 tasks (Core\Tasks, ka_tasks) kept on a fleet console and linked to a site
 * ('fleet_site:<id>') or a customer ('fleet_customer:<id>') – "renew Lindner's domain", "check prices at Fysiomed before
 * January". They are ordinary tasks: My work lists them with their reminders (the job "tasks", Core\Inbox kind task_due),
 * the site and customer screens have a task panel (Admin\TaskActions in Admin\Modules\Fleet), the lists of sites and
 * customers count the open ones, Today ranks the overdue ones among the checks (kind "task") and the MCP tool fleet_plan
 * hands them to Claude with the sites' own connectors. They stay on the console – a site never learns them.
 *
 * A task follows its record: a site removed from the console takes its tasks along (Console::remove), a customer is kept
 * while it has open tasks and its done ones go with it (Customers::forgetUnused), and merging two customers moves them
 * (Customers::rename). Core\Tasks::sweep catches any other path. Pure apart from the database readers.
 */
final class ConsoleTasks
{
    /** The target kinds of console tasks (Core\Tasks::cleanTarget, Core\Assignment::KINDS – the section "fleet"). */
    public const array KINDS = ['fleet_site', 'fleet_customer'];

    /** The weight of an overdue console task in the Today ranking – between the domain (40) and two-step sign-in (30) of Checks::CHECKS. */
    public const int WEIGHT = 35;

    /** Its state on Today: amber – planned work is late; red stays for what is broken (the checks). */
    public const string STATE = 'warn';

    /** The ranks of the states, as Checks::today ranks them. */
    private const array RANK = ['bad' => 2, 'warn' => 1, 'ok' => 0, 'unknown' => 0];

    public static function site(int $id): string
    {
        return 'fleet_site:' . $id;
    }

    public static function customer(int $id): string
    {
        return 'fleet_customer:' . $id;
    }

    /** The admin path of a site's or a customer's tasks on the console (relative, admin.php?…). */
    public static function path(string $kind, int $id): string
    {
        return $kind === 'fleet_customer' ? 'admin.php?module=fleet&action=customer_detail&id=' . $id . '#tasks' : 'admin.php?module=fleet&action=detail&id=' . $id . '#tasks';
    }

    /**
     * Where a console task points (Core\Inbox::places): the site's name (its address when it has none) or the customer's
     * name, and the admin path of its task panel. A removed site or a forgotten customer is left out.
     *
     * @param list<int> $siteIds
     * @param list<int> $customerIds
     * @return array<string, array{kind: string, id: int, title: string, path: string}>
     */
    public static function places(Db $db, array $siteIds, array $customerIds): array
    {
        $out = [];
        $in = fn (array $ids): string => implode(',', array_unique(array_map('intval', $ids)));
        try {
            if ($siteIds !== []) {
                foreach ($db->all('SELECT id, name, url FROM {fleet_sites} WHERE id IN (' . $in($siteIds) . ')') as $r) {
                    $name = Console::oneLine($r['name'], Console::NAME_LENGTH);
                    $out[self::site((int) $r['id'])] = ['kind' => 'fleet_site', 'id' => (int) $r['id'], 'title' => $name !== '' ? $name : (string) $r['url'], 'path' => self::path('fleet_site', (int) $r['id'])];
                }
            }
            if ($customerIds !== []) {
                foreach ($db->all('SELECT id, name FROM {fleet_customers} WHERE id IN (' . $in($customerIds) . ')') as $r) {
                    $out[self::customer((int) $r['id'])] = ['kind' => 'fleet_customer', 'id' => (int) $r['id'], 'title' => (string) $r['name'], 'path' => self::path('fleet_customer', (int) $r['id'])];
                }
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // a site without the console's tables
        }

        return $out;
    }

    /**
     * How many open tasks each site and each customer has (the Tasks columns of Sites and Customers).
     *
     * @return array{sites: array<int, int>, customers: array<int, int>}
     */
    public static function openCounts(Db $db): array
    {
        $out = ['sites' => [], 'customers' => []];
        try {
            $rows = $db->pairs("SELECT target, COUNT(*) FROM {tasks} WHERE done_at IS NULL AND (target LIKE 'fleet_site:%' OR target LIKE 'fleet_customer:%') GROUP BY target");
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return $out;
        }
        foreach ($rows as $target => $n) {
            [$kind, $id] = explode(':', (string) $target) + ['', '0'];
            $out[$kind === 'fleet_site' ? 'sites' : 'customers'][(int) $id] = (int) $n;
        }

        return $out;
    }

    /**
     * Every open console task (Core\Tasks rows, by due date).
     *
     * @return list<array<string, mixed>>
     */
    public static function open(Db $db): array
    {
        return Tasks::openAbout($db, self::KINDS);
    }

    /**
     * The overdue console tasks as items of Today, in the shape of Checks::today (kind "task", check "task", state STATE,
     * weight WEIGHT, since = the due moment) plus task_id, title, due_at and assignee_id. A site's task carries the site and
     * its customer; a customer's task carries the customer's name in place of the site (site_id 0). The link is the task
     * panel on the console (relative). Pure apart from the clock ($today = the site's day).
     *
     * @param list<array<string, mixed>> $tasks open console tasks (open())
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param array<int, string> $customers id => name (Customers::names)
     * @return list<array<string, mixed>>
     */
    public static function todayItems(array $tasks, array $sites, array $customers, ?string $today = null): array
    {
        $byId = array_column($sites, null, 'id');
        $items = [];
        foreach ($tasks as $task) {
            if (Tasks::group($task['due_at'] ?? null, $today) !== 'overdue') {
                continue;
            }
            [$kind, $id] = explode(':', (string) $task['target']) + ['', '0'];
            $id = (int) $id;
            if ($kind === 'fleet_site') {
                $site = $byId[$id] ?? null;
                if ($site === null) {
                    continue; // removed meanwhile – the sweep takes the task
                }
                $url = Console::url(rtrim((string) $site['url'], '/')) ?? '';
                $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
                $customer = Console::customerIdOf($site);
                $where = ['site_id' => $id, 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => $customer > 0 ? $customer : null];
            } elseif ($kind === 'fleet_customer' && isset($customers[$id])) {
                $where = ['site_id' => 0, 'site' => $customers[$id], 'url' => '', 'customer_id' => $id];
            } else {
                continue;
            }
            $items[] = ['kind' => 'task'] + $where + ['check' => 'task', 'label' => (string) $task['title'], 'state' => self::STATE, 'detail' => '',
                'path' => self::path($kind, $id), 'link' => self::path($kind, $id), 'weight' => self::WEIGHT,
                'since' => Clock::fromDb($task['due_at'])?->getTimestamp(), 'task_id' => (int) $task['id'], 'title' => (string) $task['title'],
                'due_at' => (string) $task['due_at'], 'assignee_id' => $task['assignee_id'], 'target' => (string) $task['target']];
        }

        return $items;
    }

    /**
     * The items of Today ranked together – the checks (Checks::today) and the overdue tasks (todayItems) – the way
     * Checks::today ranks: red before amber, then the weight, the oldest first (none last), then the site's name.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function rank(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [self::RANK[$b['state']] ?? 0, $b['weight'], $a['since'] === null ? 1 : 0, $a['since'] ?? 0, mb_strtolower((string) $a['site']), $a['site_id']]
            <=> [self::RANK[$a['state']] ?? 0, $a['weight'], $b['since'] === null ? 1 : 0, $b['since'] ?? 0, mb_strtolower((string) $b['site']), $b['site_id']]);

        return $items;
    }

    /** Merging two customers (Customers::rename): the tasks of the one that goes move to the one that stays. */
    public static function moveCustomer(Db $db, int $from, int $to): void
    {
        try {
            $db->run('UPDATE {tasks} SET target = ? WHERE target = ?', [self::customer($to), self::customer($from)]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0113
        }
    }

    /**
     * The customers kept although they have no site and no kit: those with an open task (a customer is never forgotten
     * under its open work). Their ids.
     *
     * @return list<int>
     */
    public static function customersWithOpenTasks(Db $db): array
    {
        try {
            return array_map(fn (mixed $t): int => (int) substr((string) $t, strlen('fleet_customer:')),
                array_column($db->all("SELECT DISTINCT target FROM {tasks} WHERE done_at IS NULL AND target LIKE 'fleet_customer:%'"), 'target'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }
    }
}
