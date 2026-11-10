<?php

declare(strict_types=1);

/* ---------- 3.1.1: what Claude is told to do as drafts matches what a drafts-only connection may call ---------- */
// every tool a drafts routine is told to use is allowed for drafts-only connections, unless its sentence says it needs
// full access or is conditional ("when this connection may"); the texts: the work_requests and scheduled_run prompts,
// the tasks of scheduled runs, and the next step list_requests hands out
// (3.13: without the closing list of runners – an index of where the tools are, not an instruction to use them)
$draftPrompt = fn (string $name): string => explode(Kaleta\Mcp\Prompts::RUNNERS_HEADING, Kaleta\Mcp\Prompts::get($name, [])['messages'][0]['content']['text'])[0];
$draftTexts = ['work_requests' => $draftPrompt('work_requests'), 'scheduled_run' => $draftPrompt('scheduled_run')]
    + array_map(fn (array $task): string => $task[1], Kaleta\Core\AgentSchedules::TASKS)
    + ['next of list_requests' => (string) (preg_match("/'next' => '([^']+)'/", (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/RequestTools.php'), $nextMatch) ? $nextMatch[1] : '')];
$draftGaps = [];
foreach ($draftTexts as $where => $text) {
    foreach (preg_split('/(?<=[.;:])\s+/', $text) ?: [] as $sentence) {
        preg_match_all('/\b[a-z]+(?:_[a-z]+)+\b/', $sentence, $names);
        foreach (array_unique($names[0]) as $tool) {
            if (isset(Kaleta\Mcp\Catalog::TOOLS[$tool]) && !Kaleta\Mcp\Catalog::allows('drafts', $tool) && !preg_match('/full access|when this connection may/', $sentence)) {
                $draftGaps[] = $where . ': ' . $tool;
            }
        }
    }
}
check('3.1.1: drafts routines are only told to use tools a drafts-only connection may call (or told what needs full access)', $draftGaps, []);

check('3.1.1: the Client and Enquiries only presets can ask Claude; Whistleblowing narrows who sees it; every module icon exists and no two menu sections share one by accident', [
    in_array('requests', Kaleta\Admin\Modules\Roles::PRESETS['client'][3], true), in_array('requests', Kaleta\Admin\Modules\Roles::PRESETS['office'][3], true), in_array('requests', Kaleta\Admin\Modules\Roles::PRESETS['writer'][3], true),
    (new ReflectionMethod(Kaleta\Admin\Modules\Whistleblowing::class, 'availableTo'))->getDeclaringClass()->getName(),
    array_values(array_diff(array_map(fn (string $c): string => $c::ICON, Kaleta\Admin\Kernel::MODULES), (function (): array { $icon = require KALETA_SYSTEM . '/views/admin/icons.php'; preg_match_all("/^    '([a-z-]+)' =>/m", (string) file_get_contents(KALETA_SYSTEM . '/views/admin/icons.php'), $m); return $m[1]; })()))],
    [true, true, false, Kaleta\Admin\Modules\Whistleblowing::class, []]);

