<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * Tools on demand (3.10). An essentials connection (Catalog::TOOL_SETS) lists the core tools (Catalog::ESSENTIALS) and two
 * meta tools: find_tools tells Claude what else the connection may use and hands out the full definitions it asks for,
 * run_tool runs any of them by name (Mcp\Server, the same path as a direct tools/call). The site's tools stay the same;
 * only what every conversation carries in its context gets shorter.
 */
final class Discovery
{
    /** At most this many full definitions per find_tools call: enough for a task, small enough for the context. */
    public const int MAX_DEFINITIONS = 8;

    /** @return list<array<string, mixed>> the meta tools' definitions (English only, like the tools added in English) */
    public static function definitions(): array
    {
        return [
            ['name' => 'find_tools', 'description' => 'Finds the other tools of this site (3.10). This connection lists the essentials only; every other tool it may use – news, redirects, settings, '
                . 'pop-ups, trash and versions, bookings, newsletters, the notebook, imports and more – is found here and run with run_tool. Without arguments: a short index (name, access, one line each). '
                . 'With query (words, e.g. "redirect 404") or names (exact tool names): the full definitions, up to 8 – the parameters to pass to run_tool.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'words to look for in the tool names, titles and descriptions'],
                    'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'exact tool names, e.g. ["list_news", "create_news"]'],
                ], 'required' => []]],
            ['name' => 'run_tool', 'description' => 'Runs a tool of this site by its name (3.10) exactly as a direct call would: the same access of this connection, the same limits, '
                . 'change log and undo. For a tool that is not in your list – call find_tools first for its parameters. The rules of the tool still hold: publish, make visible, delete or send only when the user asks.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'description' => 'the tool, e.g. create_news (from find_tools)'],
                    'arguments' => ['type' => 'object', 'description' => 'the tool\'s parameters, as find_tools describes them'],
                ], 'required' => ['name']]],
        ];
    }

    /**
     * The tools/list of an essentials connection: the core tools it may use, then the meta tools.
     *
     * @param list<array<string, mixed>> $listed the connection's whole tools/list (Server::listed items it may use)
     * @return list<array<string, mixed>>
     */
    public static function essentials(array $listed): array
    {
        return [...array_values(array_filter($listed, fn (array $t): bool => in_array($t['name'], Catalog::ESSENTIALS, true))),
            ...array_map(Server::listed(...), self::definitions())];
    }

    /**
     * find_tools. $available = every tool the connection may use (Server::listed items), $inList = the names its tools/list
     * already holds. Without query and names: an index of the tools not in the list. With names: those tools in full (any
     * the connection may use). With query: the best matches outside the list in full, the next ones by name.
     *
     * @param list<array<string, mixed>> $available
     * @param list<string> $inList
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function find(array $available, array $inList, array $arguments): array
    {
        $byName = array_column($available, null, 'name');
        $others = array_values(array_filter($available, fn (array $t): bool => !in_array($t['name'], $inList, true)));
        $names = $arguments['names'] ?? [];
        if (is_string($names)) {
            // a client without the schema may send the list as text: "a, b" or ["a","b"]
            $names = is_array(json_decode($names, true)) ? json_decode($names, true) : preg_split('/[\s,]+/', $names, -1, PREG_SPLIT_NO_EMPTY);
        }
        $names = array_values(array_unique(array_filter(array_map(fn (mixed $n): string => is_scalar($n) ? trim((string) $n) : '', (array) $names), fn (string $n): bool => $n !== '')));
        $query = trim(is_scalar($arguments['query'] ?? null) ? (string) $arguments['query'] : '');
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

        return ['tools' => array_map(fn (array $t): array => ['name' => $t['name'], 'access' => Catalog::access((string) $t['name']), 'about' => self::summary($t)], $others),
            'note' => $others === [] ? 'Every tool this connection may use is already in your list.'
                : 'Call find_tools with names (or a query) for the parameters, then run_tool with name and arguments. Access: read changes nothing, draft saves drafts, write changes the site, destructive removes or sends.'];
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
