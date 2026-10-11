<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * Tools on demand (3.10). An essentials connection (Catalog::TOOL_SETS) lists the core tools (Catalog::ESSENTIALS) and the
 * meta tools: find_tools tells Claude what else the connection may use and hands out the full definitions it asks for,
 * and three runners run a tool by name (Mcp\Server, the same path as a direct tools/call) – each only its own class of
 * tools, so its MCP annotations hold for everything it runs (runner()). The site's tools stay the same; only what every
 * conversation carries in its context gets shorter.
 */
final class Discovery
{
    /** At most this many full definitions per find_tools call: enough for a task, small enough for the context. */
    public const int MAX_DEFINITIONS = 8;

    /** Limits of a find_tools call (3.10 audit, N310-8): a query of a few words and a short list of names. */
    public const int MAX_QUERY = 200;
    public const int MAX_WORDS = 20;
    public const int MAX_NAMES = 30;

    /**
     * The runners by the class of tools each runs (runner()), in the order tools/list shows them; the text says what the
     * class is in the error a runner gives for a tool of another class.
     */
    public const array RUNNERS = [
        'run_read_tool' => 'read-only tools that stay inside the site',
        'run_tool' => 'tools that save drafts or change the site without removing, sending or reaching outside it',
        'run_destructive_tool' => 'tools that remove, overwrite or send something, or reach outside the site',
    ];

    /** @return list<array<string, mixed>> the meta tools' definitions (English only, like the tools added in English) */
    public static function definitions(): array
    {
        $runner = fn (string $what): array => ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'description' => 'the tool, e.g. ' . $what . ' (from find_tools)'],
            'arguments' => ['type' => 'object', 'description' => 'the tool\'s parameters, as find_tools describes them'],
        ], 'required' => ['name']];

        return [
            ['name' => 'find_tools', 'description' => 'Finds the other tools of this site (3.10). This connection lists the essentials only; every other tool it may use (news, redirects, settings, '
                . 'health, bookings, newsletters…) is found here. Without arguments: a short index (name, access, runner, one line each). '
                . 'With query (a few words, e.g. "redirect 404") or names (exact tool names): the full definitions, up to 8, each with the runner to call it with.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'words to look for in the tool names, titles and descriptions (at most 200 characters)'],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'exact tool names, e.g. ["list_news", "create_news"]'],
                ], 'required' => []]],
            ['name' => 'run_read_tool', 'description' => 'Runs a read-only tool of this site by its name (3.10), exactly as a direct call: it changes nothing and stays inside the site. '
                . 'Any other tool is refused. Call find_tools first – it names the runner of each tool.', 'inputSchema' => $runner('list_news')],
            ['name' => 'run_tool', 'description' => 'Runs a tool of this site that saves drafts or changes the site by its name (3.10), exactly as a direct call: the same access, limits, '
                . 'change log and undo. A tool that removes, sends or reaches outside the site is refused. Call find_tools first. Publish or make visible only when the user asks.',
                'inputSchema' => $runner('create_news')],
            ['name' => 'run_destructive_tool', 'description' => 'Runs a tool of this site that removes, overwrites or sends something, or reaches outside the site (3.10) – a delete, '
                . 'a newsletter, a booking decision, a download from an address – exactly as a direct call. Confirm the exact action with the user before every call. Call find_tools first.',
                'inputSchema' => $runner('delete_media')],
        ];
    }

    /**
     * The runner that takes a tool (3.10 audit, N310-2 and N310-4): run_destructive_tool for a destructive tool or one
     * that reaches outside the site (Catalog::isOpenWorld – also a read such as migration_report with an address),
     * run_read_tool for any other read, run_tool for the rest (drafts and writes).
     */
    public static function runner(string $name): string
    {
        return match (true) {
            Catalog::access($name) === 'destructive' || Catalog::isOpenWorld($name) => 'run_destructive_tool',
            Catalog::access($name) === 'read' => 'run_read_tool',
            default => 'run_tool',
        };
    }

    /**
     * The runners an essentials connection lists (3.10 audit, N310-6): only those that some tool outside its list needs,
     * and never run_destructive_tool on a read-only connection – its annotations would promise changes the connection
     * can never make (the open-world reads there are still callable directly by name).
     *
     * @param list<array<string, mixed>> $available every tool the connection may use (Server::listed items)
     * @return list<string>
     */
    public static function runners(array $available, string $access): array
    {
        $needed = array_unique(array_map(fn (array $t): string => self::runner((string) $t['name']),
            array_filter($available, fn (array $t): bool => !in_array($t['name'], Catalog::ESSENTIALS, true))));

        return array_values(array_filter(array_keys(self::RUNNERS), fn (string $r): bool => in_array($r, $needed, true) && !($access === 'read' && $r === 'run_destructive_tool')));
    }

    /**
     * The tools/list of an essentials connection: the core tools it may use, then find_tools and the runners it needs.
     *
     * @param list<array<string, mixed>> $available the connection's whole tools/list (Server::listed items it may use)
     * @return list<array<string, mixed>>
     */
    public static function essentials(array $available, string $access): array
    {
        $meta = ['find_tools', ...self::runners($available, $access)];

        return [...array_values(array_filter($available, fn (array $t): bool => in_array($t['name'], Catalog::ESSENTIALS, true))),
            ...array_map(Server::listed(...), array_values(array_filter(self::definitions(), fn (array $d): bool => in_array($d['name'], $meta, true))))];
    }

    /**
     * Why a runner refuses a tool of another class, naming the runner that takes it.
     */
    public static function refusal(string $runner, string $name): string
    {
        $right = self::runner($name);
        $what = match (true) {
            Catalog::access($name) === 'destructive' => 'removes, overwrites or sends something',
            Catalog::isOpenWorld($name) => 'reaches outside the site',
            Catalog::access($name) === 'read' => 'only reads',
            default => 'saves drafts or changes the site',
        };

        return $runner . ' runs ' . (self::RUNNERS[$runner] ?? 'tools') . ' – ' . mb_substr($name, 0, 100) . ' ' . $what . '. Use ' . $right . ' for it'
            . ($right === 'run_destructive_tool' ? ', after the user confirmed this exact action.' : '.');
    }

    /**
     * find_tools. $available = every tool the connection may use (Server::listed items), $inList = the names its tools/list
     * already holds. Without query and names: an index of the tools not in the list. With names: those tools in full (any
     * the connection may use). With query: the best matches outside the list in full, the next ones by name. Every tool
     * names its runner – one of $runners (what the connection lists), or "direct call" for a tool in the list or one whose
     * runner the connection does not list. A query or a list of names over the limits is refused (InvalidArgumentException).
     *
     * @param list<array<string, mixed>> $available
     * @param list<string> $inList
     * @param array<string, mixed> $arguments
     * @param list<string> $runners the runners the connection lists
     * @return array<string, mixed>
     */
    public static function find(array $available, array $inList, array $arguments, array $runners = ['run_read_tool', 'run_tool', 'run_destructive_tool']): array
    {
        $runnerOf = fn (string $name): string => !in_array($name, $inList, true) && in_array(self::runner($name), $runners, true) ? self::runner($name) : 'direct call';
        $byName = array_map(fn (array $t): array => $t + ['runner' => $runnerOf((string) $t['name'])], array_column($available, null, 'name'));
        $others = array_values(array_filter($available, fn (array $t): bool => !in_array($t['name'], $inList, true)));
        $names = $arguments['names'] ?? [];
        if (is_string($names)) {
            // a client without the schema may send the list as text: "a, b" or ["a","b"]
            $names = is_array(json_decode($names, true)) ? json_decode($names, true) : preg_split('/[\s,]+/', $names, -1, PREG_SPLIT_NO_EMPTY);
        }
        $names = array_values(array_unique(array_filter(array_map(fn (mixed $n): string => is_scalar($n) ? mb_substr(trim((string) $n), 0, 100) : '', (array) $names), fn (string $n): bool => $n !== '')));
        $query = trim(is_scalar($arguments['query'] ?? null) ? (string) $arguments['query'] : '');
        // 3.10 audit (N310-8): every word is matched against every tool – a few words are enough, a flood is refused
        if (count($names) > self::MAX_NAMES) {
            throw new \InvalidArgumentException('find_tools takes at most ' . self::MAX_NAMES . ' names at a time (' . count($names) . ' given) – ask for the tools you need next.');
        }
        if (mb_strlen($query) > self::MAX_QUERY || count(preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: []) > self::MAX_WORDS) {
            throw new \InvalidArgumentException('The find_tools query is too long – at most ' . self::MAX_QUERY . ' characters and ' . self::MAX_WORDS . ' words, e.g. "redirect 404".');
        }
        if ($names !== []) {
            $found = array_values(array_filter($names, fn (string $n): bool => isset($byName[$n])));

            return ['tools' => array_map(fn (string $n): array => $byName[$n], array_slice($found, 0, self::MAX_DEFINITIONS))]
                + (count($found) > self::MAX_DEFINITIONS ? ['more' => array_slice($found, self::MAX_DEFINITIONS)] : [])
                + (count($found) < count($names) ? ['not_available' => array_values(array_diff($names, $found)),
                    'note' => 'Not a tool of this site, a tool of a feature that is switched off, or beyond what this connection may do (see site_info → connection). Call find_tools without arguments for the index.'] : []);
        }
        if ($query !== '') {
            $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [], fn (string $w): bool => mb_strlen($w) > 1)));
            $scores = [];
            foreach ($others as $t) {
                $name = str_replace('_', ' ', (string) $t['name']);
                $title = mb_strtolower((string) ($t['title'] ?? ''));
                $description = mb_strtolower((string) ($t['description'] ?? ''));
                $score = 0;
                foreach ($words as $w) {
                    $score += (str_contains($name, $w) ? 4 : 0) + (str_contains($title, $w) ? 2 : 0) + (str_contains($description, $w) ? 1 : 0);
                }
                if ($score > 0) {
                    $scores[(string) $t['name']] = $score;
                }
            }
            arsort($scores); // a stable sort: equal scores keep the catalog order
            $matches = array_keys($scores);

            return ['tools' => array_map(fn (string $n): array => $byName[$n], array_slice($matches, 0, self::MAX_DEFINITIONS))]
                + (count($matches) > self::MAX_DEFINITIONS ? ['more' => array_slice($matches, self::MAX_DEFINITIONS, 12)] : [])
                + ($matches === [] ? ['note' => 'No tool outside your list matches “' . mb_substr($query, 0, 100) . '”. Call find_tools without arguments for the index, or use a tool from your list.'] : []);
        }

        return ['tools' => array_map(fn (array $t): array => ['name' => $t['name'], 'access' => Catalog::access((string) $t['name']), 'runner' => $runnerOf((string) $t['name']), 'about' => self::summary($t)], $others),
            'note' => $others === [] ? 'Every tool this connection may use is already in your list.'
                : 'Call find_tools with names (or a query) for the parameters, then the runner with name and arguments; confirm with the user before run_destructive_tool. '
                    . '"direct call" = call the tool by its name. Access: read changes nothing, draft saves drafts, write changes the site, destructive removes or sends.'];
    }

    /**
     * One short line about a tool for the index: the first sentence of its description without the "(2.15)"-style release
     * notes, at most 110 characters; its title when the description has none.
     *
     * @param array<string, mixed> $tool
     */
    public static function summary(array $tool): string
    {
        $description = trim((string) preg_replace('/\s*\((?:[^()]*\b\d\.\d+\b[^()]*)\)/u', '', (string) ($tool['description'] ?? '')));
        $first = preg_split('/(?<=[.:])\s/u', $description, 2)[0] ?? '';
        $first = rtrim($first, '.: ');
        if ($first === '') {
            return (string) ($tool['title'] ?? $tool['name']);
        }

        return mb_strlen($first) > 110 ? rtrim(mb_substr($first, 0, 109)) . '…' : $first;
    }
}
