<?php

declare(strict_types=1);

use Kaleta\Mcp\Catalog;

/* ---------- 3.34 (B): the MCP tools of job applications – in the work set, never in the essentials, English on both sides ---------- */
$p339Tools = ['list_applications', 'get_application', 'update_application', 'reply_to_application'];
check('3.34 B MCP: four tools of the feature applications – read, read, draft (a drafts-only connection only adds a note), destructive; English names on both sides of the Translator', [
    array_map(fn (string $t): ?array => Catalog::TOOLS[$t] ?? null, $p339Tools), array_map(fn (string $t): ?string => Kaleta\Mcp\Translator::czech($t), $p339Tools),
    array_values(array_intersect($p339Tools, Catalog::ESSENTIALS)), array_values(array_diff($p339Tools, Catalog::WORK)),
    array_map(fn (string $t): bool => method_exists(Kaleta\Mcp\Tools::class, Catalog::method($t)), $p339Tools)],
    [[['read', 'applications'], ['read', 'applications'], ['draft', 'applications'], ['destructive', 'applications']], $p339Tools, [], [], [true, true, true, true]]);
check('3.34 B MCP: the reply reaches outside (open world), the update is idempotent, neither changes the site; a drafts-only connection never sends', [
    in_array('reply_to_application', Catalog::OPEN_WORLD, true), in_array('update_application', Catalog::IDEMPOTENT, true),
    Catalog::isSiteNeutral('update_application'), Catalog::isSiteNeutral('reply_to_application'), Catalog::allows('drafts', 'reply_to_application'), Catalog::allows('drafts', 'update_application'),
    Catalog::allows('read', 'list_applications')],
    [true, true, true, true, false, true, true]);
$p339All = array_map(Kaleta\Mcp\Server::listed(...), Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()));
$p339Json = fn (mixed $v): int => strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
check('3.34 B MCP: the work set stays under 49,000 bytes, the essentials unchanged and under their budget', [
    $p339Json(['tools' => Kaleta\Mcp\Discovery::essentials($p339All, 'full', Catalog::WORK)]) <= 49000, $p339Json(['tools' => Kaleta\Mcp\Discovery::essentials($p339All, 'full')]) <= 49000],
    [true, true]);
$p339Source = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/ApplicationTools.php');
check('3.34 B MCP: every tool checks the switch and the section first; a CV only by name and size; applicant text marked untrusted; the reply needs confirm', [
    substr_count($p339Source, '$this->applicationsOpen();'), str_contains($p339Source, "'attachments' => array_map(fn (array \$f): array => ['name' => \$f['name'], 'size' => \$f['size']], \$application['attachments']),"),
    substr_count($p339Source, '+ \\Kaleta\\Mcp\\Untrusted::mark(['), /* 3.35: the one marker of Mcp\Untrusted */ str_contains($p339Source, "if ((\$a['confirm'] ?? false) !== true) {"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/WorkTools.php'), 'return $this->assignApplication($a);'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/TaskTools.php'), "if (\$section === \\Kaleta\\Core\\Applications::SECTION && !\$this->app->settings()->bool('applications_claude')) {")],
    [4, true, 2, true, true, true]);
