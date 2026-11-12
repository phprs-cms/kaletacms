<?php

declare(strict_types=1);

/* ---------- 3.34 review low (b): applications_claude on every task path of MCP ---------- */
$lbTools = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/TaskTools.php');
check('[source-pin] 3.34 review low (b): list_tasks filters application tasks on every filter; complete_task and save_task by id refuse them while the switch is off', [
    str_contains($lbTools, "\$rows = \$this->applicationsShut() ? array_values(array_filter(\$rows, fn (array \$t): bool => !str_starts_with((string) (\$t['target'] ?? ''), 'application:'))) : \$rows;"),
    substr_count($lbTools, '$this->taskNotShut($id);'),
    str_contains($lbTools, "private function taskNotShut(int \$id): void")],
    [true, 2, true]);
