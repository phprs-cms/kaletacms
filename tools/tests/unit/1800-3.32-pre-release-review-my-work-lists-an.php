<?php

declare(strict_types=1);

/* ---------- 3.32 (pre-release review): My work lists an unassigned unanswered enquiry only while new or recent; count() skips the queries of skipped lists ---------- */
$r332Work = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/MyWork.php');
check('[source-pin] 3.32: unassigned enquiries in My work (and MCP my_work) – new or from the last 30 days; count() passes its skip list to lists(), which does not query a skipped list; the tool says so', [
    Kaleta\Core\MyWork::UNASSIGNED_DAYS, str_contains($r332Work, "'prirazeno IS NULL AND ' . self::UNANSWERED . ' AND (stav = 0 OR datum >= ?)'"),
    str_contains($r332Work, '$lists = self::lists($app, $modules, $userId, $skip);'), str_contains($r332Work, "if (!isset(\$modules[\$section]) || in_array(\$list, \$skip, true)) {"),
    (new ReflectionMethod(Kaleta\Core\MyWork::class, 'lists'))->getParameters()[3]->getDefaultValue(),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), 'read or not, while still new or no older than 30 days')],
    [30, true, true, true, [], true]);

