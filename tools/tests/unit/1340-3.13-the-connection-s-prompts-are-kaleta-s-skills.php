<?php

declare(strict_types=1);

/* ---------- 3.13: the connection's prompts are Kaleta's skills ---------- */
// Every prompt has a title and a description; every argument it declares is used and every {placeholder} is declared;
// every snake_case word of its text is a tool, a parameter, a setting, a prompt or one of its terms (so a renamed or
// invented tool name fails here); and every tool it names that an essentials connection does not list has its runner
// in the closing section. Earlier prompts stay callable (Prompts::HIDDEN) without being listed.
$promptProblems = [];
foreach (Kaleta\Mcp\Prompts::listAll() as $prompt) {
    $name = $prompt['name'];
    if (trim($prompt['title']) === '' || trim($prompt['description']) === '' || mb_strlen($prompt['description']) > 300) {
        $promptProblems[] = "{$name}: needs a title and a description of at most 300 characters";
    }
    $marked = [];
    foreach ($prompt['arguments'] as $argument) {
        if (trim($argument['description']) === '') {
            $promptProblems[] = "{$name}: argument {$argument['name']} has no description";
        }
        $marked[$argument['name']] = 'ARG' . strtoupper(str_replace('_', '', $argument['name'])) . 'VALUE';
    }
    $text = Kaleta\Mcp\Prompts::get($name, $marked)['messages'][0]['content']['text'];
    foreach ($marked as $argument => $marker) {
        if (!str_contains($text, $marker)) {
            $promptProblems[] = "{$name}: the argument {$argument} is declared but the text never uses it";
        }
    }
    // {name} is the booking e-mails' own token, {{…}} are site tokens
    preg_match_all('/(?<!\{)\{([a-z_]+)\}(?!\})/', $text, $placeholders);
    foreach (array_diff(array_unique($placeholders[1]), ['name']) as $placeholder) {
        $promptProblems[] = "{$name}: {{$placeholder}} is not a declared argument";
    }
    $empty = Kaleta\Mcp\Prompts::get($name, array_map(fn (array $a): string => $a['required'] ? 'x' : '', array_column($prompt['arguments'], null, 'name')))['messages'][0]['content']['text'];
    if (preg_match('/(?<!\{)\{[a-z_]+\}(?!\})/', str_replace('{name}', '', $empty)) === 1) {
        $promptProblems[] = "{$name}: an optional argument left empty leaves its placeholder in the text";
    }
    [$body, $runnerSection] = explode(Kaleta\Mcp\Prompts::RUNNERS_HEADING, $text, 2) + [1 => ''];
    preg_match_all('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/', $text, $words);
    foreach (array_unique($words[0]) as $word) {
        if (!isset(Kaleta\Mcp\Catalog::TOOLS[$word]) && !isset(Kaleta\Mcp\Catalog::META[$word]) && !isset($pluginParameters[$word]) && !$pluginIsSetting($word)
            && !in_array($word, Kaleta\Mcp\Prompts::names(), true) && !in_array($word, Kaleta\Mcp\Prompts::terms($name), true)) {
            $promptProblems[] = "{$name}: {$word} is no tool, parameter, setting or prompt of the Kaleta connection (a typo, or list it in the prompt's terms)";
        }
    }
    foreach (Kaleta\Mcp\Prompts::terms($name) as $term) {
        if (isset(Kaleta\Mcp\Catalog::TOOLS[$term]) || !in_array($term, $words[0], true)) {
            $promptProblems[] = "{$name}: the term {$term} is a tool or not in the text";
        }
    }
    preg_match_all('/\b[a-z]+(?:_[a-z]+)+\b/', $body, $named);
    foreach (array_unique($named[0]) as $tool) {
        if (isset(Kaleta\Mcp\Catalog::TOOLS[$tool]) && !in_array($tool, Kaleta\Mcp\Catalog::ESSENTIALS, true)
            && preg_match('/^- ' . Kaleta\Mcp\Discovery::runner($tool) . ' \([^)]*\): (?:[a-z_]+, )*' . $tool . '(?:,|$)/m', $runnerSection) !== 1) {
            $promptProblems[] = "{$name}: {$tool} is not an essential tool and the text does not name its runner " . Kaleta\Mcp\Discovery::runner($tool);
        }
    }
}
check('3.13 prompts: titles, descriptions, arguments declared and used, only real tools, parameters and settings, the runner of every non-essential tool', $promptProblems, []);
$weeklyCare = Kaleta\Mcp\Prompts::get('weekly_care', [])['messages'][0]['content']['text'];
check('3.13 prompts: the runbooks carry what 3.9–3.12 shipped, the earlier name weekly_review still works (not listed), arguments fill in', [
    // N313-1: weekly_review stays a read-only report (a routine built on it gains no write steps), not listed
    str_contains(Kaleta\Mcp\Prompts::get('weekly_review', [])['messages'][0]['content']['text'], 'do not change anything') && Kaleta\Mcp\Prompts::get('weekly_review', [])['messages'][0]['content']['text'] !== $weeklyCare, in_array('weekly_review', Kaleta\Mcp\Prompts::names(), true),
    array_values(array_filter(array_keys(Kaleta\Mcp\Prompts::HIDDEN), fn (string $a): bool => in_array($a, Kaleta\Mcp\Prompts::names(), true))),
    (bool) preg_match('/add_languages.*WooCommerce.*product catalogue.*Publish the menus.*410.*migration_report/s', Kaleta\Mcp\Prompts::get('migrate_site', ['old_url' => 'https://old.example'])['messages'][0]['content']['text']),
    str_contains(Kaleta\Mcp\Prompts::get('migrate_site', ['old_url' => 'https://old.example', 'platform' => 'WordPress'])['messages'][0]['content']['text'], 'https://old.example (WordPress) to this Kaleta site'),
    (bool) preg_match('/language version ar to this site.*right-to-left.*Latin.*translation_of.*hreflang/s', Kaleta\Mcp\Prompts::get('add_language_version', ['language' => 'ar'])['messages'][0]['content']['text']),
    (bool) preg_match('/double opt-in.*send_test_newsletter.*explicit yes: `send_newsletter`/s', Kaleta\Mcp\Prompts::get('newsletter_release', [])['messages'][0]['content']['text']),
    str_contains(Kaleta\Mcp\Prompts::get('newsletter_release', ['topic' => 'the spring offer'])['messages'][0]['content']['text'], 'the next newsletter about the spring offer.'),
    str_contains(Kaleta\Mcp\Prompts::get('launch_site', ['business' => 'a bakery'])['messages'][0]['content']['text'], 'The business: a bakery.'),
    (bool) preg_match('/- run_destructive_tool \([^)]*\): .*import_wordpress/', Kaleta\Mcp\Prompts::get('migrate_site', ['old_url' => 'x'])['messages'][0]['content']['text']),
    Kaleta\Mcp\Prompts::runners('site_info and list_pages only'), array_column(Kaleta\Mcp\Prompts::listAll(), 'title')[0]],
    [true, false, [], true, true, true, true, true, true, true, '', 'Build a page']);

