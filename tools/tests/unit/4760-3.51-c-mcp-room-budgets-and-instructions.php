<?php

declare(strict_types=1);

/* ---------- 3.51 C: room in the MCP work set and the server instructions – caps on what a connection is served ---------- */
use Kaleta\Mcp\Catalog as C760Catalog;
use Kaleta\Mcp\Discovery as C760Discovery;
use Kaleta\Mcp\Translator as C760Translator;

// the arithmetic of unit 2550: tools/list of a full connection with every feature on (Tools::definitions has them all)
$c760All = array_map(Kaleta\Mcp\Server::listed(...), C760Translator::listAll(Kaleta\Mcp\Tools::definitions()));
$c760Json = fn (mixed $v): int => strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$c760Bytes = fn (string $set): int => $c760Json(['tools' => C760Discovery::essentials($c760All, 'full', C760Catalog::listOf($set) ?? [])]);
// 3.50.1 measured work 53,998 (2 bytes free), essentials 48,132, migration 48,257, fleet 27,433 – 3.51 keeps 800 free in the work set
$c760Targets = ['work' => 53200, 'essentials' => 47900, 'migration' => 48000, 'fleet' => 27433];
check('3.51 C1: every focused set stays at or under its 3.51 target (work leaves 800 bytes of its 54,000 for the next tools)', array_keys(array_filter($c760Targets,
    fn (int $max, string $set): bool => $c760Bytes($set) > $max, ARRAY_FILTER_USE_BOTH)), []);
$c760Tagged = [];
foreach ($c760All as $c760Tool) {
    if (C760Catalog::setsOf($c760Tool['name']) !== []
        && preg_match('/(?<!before |from |WCAG )(?<![\w.])[123]\.\d{1,2}(?:\.\d{1,2})?(?![\w.%])/', (string) json_encode($c760Tool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
        $c760Tagged[] = $c760Tool['name'];
    }
}
check('3.51 C1: no tool of a focused set carries a release tag such as "(3.27)" or "(read-only, 2.8)" – only words that still mean something ("before 3.33")', $c760Tagged, []);

// the server instructions as served (Translator::instructions with the feature blocks of a site, joined by a space)
$c760Features = C760Translator::INSTRUCTION_FEATURES;
$c760Served = [
    'base' => strlen(C760Translator::instructions()),
    'all but fleet' => strlen(C760Translator::instructions('', array_diff_key($c760Features, ['fleet' => true]))),
    'fleet' => strlen(C760Translator::instructions('fleet', ['fleet' => $c760Features['fleet']])),
];
check('3.51 C2: the served instructions – the base no longer than in 3.50.1 (6,907), every block but the fleet one at most 8,000, the base with the fleet console at most 7,600', [
    $c760Served['base'] <= 6907, $c760Served['all but fleet'] <= 8000, $c760Served['fleet'] <= 7600, strlen($c760Features['quotes']) <= 420], [true, true, true, true]);
check('3.51 C2: the shorter QUOTES block still names the rule, the yes and the two runbooks; the prompts carry what it no longer says', [
    array_values(array_filter(['save_quote', 'list_prices', 'never invent or guess a price', 'mark_quote', 'explicit yes', 'office_day', 'set_up_quotes'], fn (string $w): bool => !str_contains($c760Features['quotes'], $w))),
    array_values(array_filter(['never invent', 'admin_url', 'mark_quote', 'send_quote'], fn (string $w): bool => !str_contains(Kaleta\Mcp\Prompts::get('office_day', [])['messages'][0]['content']['text'], $w))),
    str_contains(Kaleta\Mcp\Prompts::get('set_up_quotes', [])['messages'][0]['content']['text'], 'Never invent or guess a price')],
    [[], [], true]);
check('3.51 C2: the newsletter sentence of BUILDING A SITE names the three tools and that send_newsletter is final but can be paused', [
    preg_match('/Newsletters: draft_newsletter, send_test_newsletter; send_newsletter mails all subscribers for good; pause: true stops it\./', C760Translator::INSTRUCTION_TASKS['build'])],
    [1]);

// C3: a connection that lists every tool – the number in its hint stays honest
check('3.51 C3: ALL_TOOLS_KB is within 10 % of the measured tools/list of an "all" connection, and the hint names it and where to change the set', [
    abs($c760Json(['tools' => $c760All]) / 1000 - C760Translator::ALL_TOOLS_KB) <= C760Translator::ALL_TOOLS_KB / 10,
    str_contains(C760Translator::ALL_TOOLS_HINT, C760Translator::ALL_TOOLS_KB . ' kB'), str_contains(C760Translator::ALL_TOOLS_HINT, 'Change the tool set')], [true, true, true]);
$c760View = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$c760Consent = fn (string $set, string $lang): string => Kaleta\Core\Language::runWith($lang, fn (): string => $c760View->render('admin/connection-access', ['role' => 'Administrator', 'selected' => 'drafts', 'toolSet' => $set]), 'admin-');
check('3.51 C3: the choice of a tool set says what "everything at once" costs – once, on that card only, in English and Czech', [
    substr_count($c760Consent('essentials', 'en'), 'About ' . C760Translator::ALL_TOOLS_KB . ' kB in each conversation; the other sets load a fraction of it.'),
    substr_count($c760Consent('all', 'cs'), 'V každé konverzaci asi ' . C760Translator::ALL_TOOLS_KB . ' kB'),
    (bool) preg_match('/value="all"[^<]*>.*?About ' . C760Translator::ALL_TOOLS_KB . ' kB/s', $c760Consent('essentials', 'en'))], [1, 1, true]);
