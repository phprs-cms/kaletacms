<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\Tasks;

/**
 * The fleet plan for Claude (3.30, MCP fleet_plan and the prompt fleet_fix): per site what needs doing – the open items of
 * Today (Checks::today) and the open console tasks (ConsoleTasks) – with the site's address, the address of the site's own
 * Claude connector (its /mcp endpoint, built here from the address the console knows – never a token) and a short
 * suggested order. "Fix the amber items on all sites of customer X": Claude works through each site's own connection, one
 * site at a time, and reports back by completing the console task or noting it. The console executes nothing and holds no
 * access to the sites – the plan is a read-only list.
 *
 * Pure: the MCP handler passes the rows of ka_fleet_sites (already narrowed to the customer or the site), the items of
 * Checks::today over them (already narrowed by severity and checks), the open console tasks and the customer names.
 */
final class Plan
{
    /** The severity filter: the console's words and the colours people use for them. */
    public const array SEVERITIES = ['bad' => 'bad', 'warn' => 'warn', 'red' => 'bad', 'amber' => 'warn'];

    /** At most this many sites in one plan (the rest: narrow by customer or site). */
    public const int MAX_SITES = 100;

    /** At most this many steps of the suggested order per site. */
    public const int MAX_STEPS = 12;

    /** The address of a site's own Claude connector (its MCP endpoint): its known address + /mcp; null for an address the console does not accept. */
    public static function connector(mixed $siteUrl): ?string
    {
        $url = Console::url(is_string($siteUrl) ? rtrim($siteUrl, '/') : null);

        return $url !== null && $url !== '' ? $url . '/mcp' : null;
    }

    /**
     * The plan. A site appears when it has an item or an open task; the tasks about a whole customer are listed once under
     * customer_tasks with the ids of that customer's sites in the plan – for every customer ($customerScope 0), one
     * customer (its id) or none (null: the plan of one site). Sites with something red first, then overdue tasks, then the
     * amber items, then the name. $consoleUrl prefixes the console's own admin links of the tasks ('' = relative).
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param list<array<string, mixed>> $items Checks::today over those sites
     * @param list<array<string, mixed>> $tasks open console tasks (ConsoleTasks::open)
     * @param array<int, string> $customers id => name
     * @return array{sites: list<array<string, mixed>>, customer_tasks: list<array<string, mixed>>, sites_listed: int, sites_clear: int, more_sites: int}
     */
    public static function build(array $sites, array $items, array $tasks, array $customers, ?int $customerScope = 0, string $consoleUrl = '', ?string $today = null): array
    {
        $bySite = [];
        foreach ($items as $item) {
            $bySite[(int) $item['site_id']][] = $item;
        }
        $siteTasks = [];
        $customerTasks = [];
        foreach ($tasks as $task) {
            [$kind, $id] = explode(':', (string) $task['target']) + ['', '0'];
            if ($kind === 'fleet_site') {
                $siteTasks[(int) $id][] = $task;
            } elseif ($kind === 'fleet_customer') {
                $customerTasks[(int) $id][] = $task;
            }
        }
        $plan = [];
        $customerSites = [];
        foreach ($sites as $site) {
            $id = (int) ($site['id'] ?? 0);
            $customer = Console::customerIdOf($site);
            $customerSites[$customer][] = $id;
            $siteItems = $bySite[$id] ?? [];
            $open = array_map(fn (array $t): array => self::task($t, $consoleUrl, $today), $siteTasks[$id] ?? []);
            if ($siteItems === [] && $open === []) {
                continue;
            }
            $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
            $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
            $beat = Console::storedBeat($site);
            $bad = count(array_filter($siteItems, fn (array $i): bool => $i['state'] === 'bad'));
            $overdue = count(array_filter($open, fn (array $t): bool => $t['overdue']));
            $plan[] = ['sort' => [-$bad, -$overdue, -(count($siteItems) - $bad), mb_strtolower($name !== '' ? $name : $url), $id], 'site' => array_filter([
                'id' => $id, 'name' => $name !== '' ? $name : $url, 'url' => $url, 'customer' => $customer > 0 ? ($customers[$customer] ?? null) : null,
                'connector' => self::connector($url),
                // the site's own report of its Claude connection (3.18): off | none | idle | active; null = the site does not say
                'connection' => is_array($beat['claude_connection'] ?? null) ? ($beat['claude_connection']['state'] ?? null) : null,
                'items' => array_map(fn (array $i): array => array_filter(['check' => $i['check'], 'label' => $i['label'], 'state' => $i['state'], 'detail' => $i['detail'] !== '' ? $i['detail'] : null,
                    'link' => $i['link'] !== '' ? $i['link'] : null], fn (mixed $v): bool => $v !== null), $siteItems),
                'tasks' => $open,
                'order' => self::order($siteItems, $open),
            ], fn (mixed $v): bool => $v !== null)];
        }
        usort($plan, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $listed = array_column(array_slice($plan, 0, self::MAX_SITES), 'site');
        $listedIds = array_column($listed, 'id');
        $forCustomers = [];
        foreach ($customerTasks as $customerId => $list) {
            if (!isset($customers[$customerId]) || $customerScope === null || ($customerScope > 0 && $customerId !== $customerScope)) {
                continue; // a customer outside the filter, or forgotten meanwhile
            }
            foreach ($list as $task) {
                $forCustomers[] = ['customer' => $customers[$customerId]] + self::task($task, $consoleUrl, $today)
                    + ['site_ids' => array_values(array_intersect($customerSites[$customerId] ?? [], $listedIds))];
            }
        }

        return ['sites' => $listed, 'customer_tasks' => $forCustomers, 'sites_listed' => count($listed), 'sites_clear' => count($sites) - count($plan),
            'more_sites' => max(0, count($plan) - self::MAX_SITES)];
    }

    /**
     * The suggested order on one site: what is red first (as ranked), then the overdue tasks, then the amber items, then
     * the other tasks by their due date. Short English lines.
     *
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $tasks task() rows
     * @return list<string>
     */
    public static function order(array $items, array $tasks): array
    {
        $item = fn (array $i): string => 'Fix "' . $i['label'] . '" (' . ($i['state'] === 'bad' ? 'red' : 'amber') . ')';
        $task = fn (array $t): string => 'Task #' . $t['id'] . ' "' . $t['title'] . '"' . ($t['overdue'] ? ' (overdue)' : (isset($t['due']) ? ' (due ' . substr((string) $t['due'], 0, 10) . ')' : ''));
        $steps = [
            ...array_map($item, array_values(array_filter($items, fn (array $i): bool => $i['state'] === 'bad'))),
            ...array_map($task, array_values(array_filter($tasks, fn (array $t): bool => $t['overdue']))),
            ...array_map($item, array_values(array_filter($items, fn (array $i): bool => $i['state'] !== 'bad'))),
            ...array_map($task, array_values(array_filter($tasks, fn (array $t): bool => !$t['overdue']))),
        ];

        return array_slice($steps, 0, self::MAX_STEPS);
    }

    /**
     * A console task as the plan shows it: id, title, due (to the minute), overdue, and the console's admin link of its panel.
     *
     * @param array<string, mixed> $task a Core\Tasks row
     * @return array{id: int, title: string, due?: string, overdue: bool, admin_url: string}
     */
    private static function task(array $task, string $consoleUrl, ?string $today): array
    {
        [$kind, $id] = explode(':', (string) $task['target']) + ['', '0'];

        return ['id' => (int) $task['id'], 'title' => (string) $task['title']] + ($task['due_at'] !== null ? ['due' => substr((string) $task['due_at'], 0, 16)] : [])
            + ['overdue' => Tasks::group($task['due_at'], $today) === 'overdue', 'admin_url' => $consoleUrl . ConsoleTasks::path($kind, (int) $id)];
    }
}
