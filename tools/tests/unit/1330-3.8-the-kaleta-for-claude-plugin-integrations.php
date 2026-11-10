<?php

declare(strict_types=1);

/* ---------- 3.8: the "Kaleta for Claude" plugin (integrations/claude-plugin) names only what the connection has ---------- */
// Every SKILL.md follows the Agent Skills format (name = folder, description ≤ 1024 characters, simple YAML), lists the
// tools it uses in metadata.kaleta-tools, and every snake_case word in it is a Catalog tool (and then listed), a
// parameter of a tool, a setting update_settings accepts, or a term the skill marks in metadata.kaleta-terms – so a
// renamed or invented tool name fails here instead of in a user's conversation.
$pluginRoot = KALETA_ROOT . '/integrations/claude-plugin';
$pluginParameters = [];
foreach (Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()) as $pluginTool) {
    $pluginParameters += array_fill_keys(array_keys((array) $pluginTool['inputSchema']['properties']), true);
}
$pluginSettingsPattern = (string) (new ReflectionClassConstant(Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue();
$pluginIsSetting = fn (string $word): bool => array_key_exists($word, Kaleta\Core\Settings::DEFAULTS)
    && (preg_match($pluginSettingsPattern, $word) === 1 || in_array($word, ['extensions', 'additional_languages'], true));
/**
 * The frontmatter of a SKILL.md as the simple YAML the skills use: "key: value" lines and one "metadata:" map of
 * "  key: value" lines; anything else (a nested list, an unquoted ": " or " #") is a problem, not a guess.
 *
 * @return array{0: array<string, string>, 1: array<string, string>, 2: string, 3: list<string>} fields, metadata, body, problems
 */
$pluginFrontmatter = function (string $text): array {
    if (!preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', str_replace("\r\n", "\n", $text), $m)) {
        return [[], [], $text, ['no frontmatter between --- lines']];
    }
    $fields = $metadata = [];
    $problems = [];
    $inMetadata = false;
    foreach (explode("\n", $m[1]) as $line) {
        if (!preg_match('/^(  )?([a-z][a-z0-9-]*):(?: (.*))?$/D', $line, $kv)) {
            $problems[] = 'not a "key: value" line: ' . $line;
            continue;
        }
        $value = $kv[3] ?? '';
        if (preg_match('/^"(.*)"$/D', $value, $quoted)) {
            $value = $quoted[1];
        } elseif (str_contains($value, ': ') || str_contains($value, ' #') || str_starts_with($value, '"')) {
            $problems[] = 'quote the value of ' . $kv[2];
        }
        if ($kv[1] === '  ' && $inMetadata) {
            $metadata[$kv[2]] = $value;
        } elseif ($kv[1] === '  ') {
            $problems[] = 'an indented line outside metadata: ' . $line;
        } else {
            $inMetadata = $kv[2] === 'metadata' && $value === '';
            if (!$inMetadata) {
                $fields[$kv[2]] = $value;
            }
        }
    }

    return [$fields, $metadata, $m[2], $problems];
};
$pluginProblems = [];
$pluginSkills = glob($pluginRoot . '/skills/*/SKILL.md') ?: [];
foreach ($pluginSkills as $skillFile) {
    $skill = basename(dirname($skillFile));
    [$fields, $metadata, $body, $problems] = $pluginFrontmatter((string) file_get_contents($skillFile));
    foreach ($problems as $problem) {
        $pluginProblems[] = "{$skill}: {$problem}";
    }
    if (($fields['name'] ?? '') !== $skill || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $skill) || strlen($skill) > 64) {
        $pluginProblems[] = "{$skill}: name must equal the folder name (a-z, 0-9 and single hyphens, at most 64 characters)";
    }
    $description = $fields['description'] ?? '';
    if ($description === '' || mb_strlen($description) > 1024) {
        $pluginProblems[] = "{$skill}: description must have 1–1024 characters";
    }
    if (substr_count($body, "\n") > 500) {
        $pluginProblems[] = "{$skill}: keep SKILL.md under 500 lines";
    }
    $listed = preg_split('/\s+/', trim($metadata['kaleta-tools'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = preg_split('/\s+/', trim($metadata['kaleta-terms'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_diff($listed, array_keys(Kaleta\Mcp\Catalog::TOOLS)) as $unknown) {
        $pluginProblems[] = "{$skill}: kaleta-tools lists {$unknown}, which is not a tool of Mcp\\Catalog";
    }
    foreach (array_intersect($terms, array_keys(Kaleta\Mcp\Catalog::TOOLS)) as $tool) {
        $pluginProblems[] = "{$skill}: {$tool} is a tool – list it in kaleta-tools, not kaleta-terms";
    }
    preg_match_all('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/', $description . "\n" . $body, $words);
    $words = array_values(array_unique($words[0]));
    $mentioned = array_values(array_filter($words, fn (string $w): bool => isset(Kaleta\Mcp\Catalog::TOOLS[$w])));
    foreach (array_diff($mentioned, $listed) as $tool) {
        $pluginProblems[] = "{$skill}: uses {$tool} but kaleta-tools does not list it";
    }
    foreach (array_diff($listed, $mentioned) as $tool) {
        $pluginProblems[] = "{$skill}: kaleta-tools lists {$tool} but the skill never mentions it";
    }
    foreach ($words as $word) {
        // 3.10: the meta tools find_tools and the runners reach the rest from an essentials connection – known words, not site tools
        // 3.13: and the prompts' names – a runbook points to another one ("the prompt add_language_version")
        if (!isset(Kaleta\Mcp\Catalog::TOOLS[$word]) && !isset(Kaleta\Mcp\Catalog::META[$word]) && !isset($pluginParameters[$word]) && !$pluginIsSetting($word) && !in_array($word, $terms, true)
            && !in_array($word, Kaleta\Mcp\Prompts::names(), true)) {
            $pluginProblems[] = "{$skill}: {$word} is no tool, parameter or setting of the Kaleta connection (a typo, or mark it in kaleta-terms)";
        }
    }
}
check('3.8 plugin: the five skills are there', array_map(fn (string $f): string => basename(dirname($f)), $pluginSkills),
    ['compliance-check', 'launch-site', 'migrate-from-wordpress', 'set-up-bookings', 'weekly-care']);
// 3.13: the skills are generated from the connection's prompts – the files in the repository are exactly what
// tools/build-plugin.php writes (php tools/build-plugin.php --skills after a prompt changes), one per Prompts::SKILLS
check('3.13 plugin: every SKILL.md is generated from its prompt and up to date (php tools/build-plugin.php --skills)', [
    array_values(array_filter(array_keys(Kaleta\Mcp\Prompts::SKILLS), fn (string $folder): bool => (string) @file_get_contents($pluginRoot . '/skills/' . $folder . '/SKILL.md') !== Kaleta\Mcp\Prompts::skill($folder))),
    array_map(fn (string $f): string => basename(dirname($f)), $pluginSkills) === (function (): array { $k = array_keys(Kaleta\Mcp\Prompts::SKILLS); sort($k); return $k; })(),
    array_values(array_diff(array_column(Kaleta\Mcp\Prompts::SKILLS, 0), Kaleta\Mcp\Prompts::names()))], [[], true, []]);
check('3.8 plugin: every SKILL.md is valid and names only tools, parameters and settings the Kaleta connection has', $pluginProblems, []);
check('3.8 plugin: the frontmatter check refuses an invented tool, an unlisted tool and an unquoted colon', (function () use ($pluginFrontmatter): array {
    [$fields, $metadata, $body, $problems] = $pluginFrontmatter("---\nname: x\ndescription: Do this: then that\nmetadata:\n  kaleta-tools: \"site_info\"\n---\nCall `site_info`, then `list_pagez`.\n");
    preg_match_all('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/', $body, $words);

    return [$fields['name'] ?? '', $metadata, $problems, array_values(array_filter($words[0], fn (string $w): bool => !isset(Kaleta\Mcp\Catalog::TOOLS[$w])))];
})(), ['x', ['kaleta-tools' => 'site_info'], ['quote the value of description'], ['list_pagez']]);

// the manifest: valid JSON with the fields Claude Code and claude.ai need, the version of this release, and the site's
// connection as a remote http server whose address is the user's own (userConfig) – never a credential in the plugin
$pluginManifest = json_decode((string) file_get_contents($pluginRoot . '/.claude-plugin/plugin.json'), true);
$pluginMcp = json_decode((string) file_get_contents($pluginRoot . '/.mcp.json'), true);
$pluginOption = is_array($pluginManifest) ? ($pluginManifest['userConfig']['mcp_url'] ?? null) : null;
check('3.8 plugin: plugin.json and .mcp.json are valid and the connection address comes from the user', [
    is_array($pluginManifest), $pluginManifest['name'] ?? null, $pluginManifest['version'] ?? null,
    is_string($pluginManifest['description'] ?? null) && $pluginManifest['description'] !== '', is_string($pluginManifest['author']['name'] ?? null),
    is_array($pluginOption) ? [$pluginOption['type'] ?? null, is_string($pluginOption['title'] ?? null), is_string($pluginOption['description'] ?? null), $pluginOption['sensitive'] ?? false] : null,
    $pluginMcp['mcpServers'] ?? null, is_dir($pluginRoot . '/bin') || is_dir($pluginRoot . '/hooks') || is_dir($pluginRoot . '/agents'), is_file($pluginRoot . '/README.md')],
    [true, 'kaleta', KALETA_VERSION, true, true, ['string', true, true, false], ['kaleta' => ['type' => 'http', 'url' => '${user_config.mcp_url}']], false, true]);

// tools/build-plugin.php packs exactly the plugin's files under one top-level folder, named by KALETA_VERSION
$pluginOut = sys_get_temp_dir() . '/kaleta-plugin-' . bin2hex(random_bytes(4));
$pluginBuild = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(KALETA_ROOT . '/tools/build-plugin.php') . ' ' . escapeshellarg('--out=' . $pluginOut) . ' 2>&1');
$pluginZip = new ZipArchive();
$pluginEntries = [];
if ($pluginZip->open($pluginOut . '/kaleta-claude-plugin-' . KALETA_VERSION . '.zip') === true) {
    for ($i = 0; $i < $pluginZip->numFiles; $i++) {
        $pluginEntries[] = (string) $pluginZip->getNameIndex($i);
    }
    $pluginZip->close();
}
array_map('unlink', glob($pluginOut . '/*') ?: []);
@rmdir($pluginOut);
sort($pluginEntries);
check('3.8 plugin: tools/build-plugin.php zips the manifest, .mcp.json, the README and the five skills under kaleta/', [str_starts_with($pluginBuild, 'Done: '), $pluginEntries],
    [true, ['kaleta/.claude-plugin/plugin.json', 'kaleta/.mcp.json', 'kaleta/README.md', 'kaleta/skills/compliance-check/SKILL.md', 'kaleta/skills/launch-site/SKILL.md',
        'kaleta/skills/migrate-from-wordpress/SKILL.md', 'kaleta/skills/set-up-bookings/SKILL.md', 'kaleta/skills/weekly-care/SKILL.md']]);
