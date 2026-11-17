<?php

declare(strict_types=1);

use Kaleta\Admin\Guide;
use Kaleta\Admin\Palette;
use Kaleta\Mcp\Translator;

/* ---------- 3.45 B: the guide to the office, the palette's quick actions, English values in update_settings ---------- */
// CPO 5: a chapter in the administration for each office section instead of the placeholder links; U70: the palette knows
// the screens of 3.34–3.42; update_settings takes English values besides the stored Czech ones. Smoke 4030 opens the pages,
// posts New quote from the palette's data and calls update_settings over MCP.
$b345Idents = array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES);
$b345Chapters = array_merge(...array_map(fn (array $c): array => $c['modules'], array_values(Guide::CHAPTERS)));
check('3.45 B: every chapter of the guide belongs to real sections, each section to one chapter; the four placeholders are gone and their sections open their chapter', [
    array_values(array_diff($b345Chapters, $b345Idents)), count($b345Chapters) === count(array_unique($b345Chapters)),
    array_values(array_intersect(['quotes', 'applications', 'internal_files', 'my_work'], array_keys(Guide::MODULES))),
    array_map(fn (string $m): ?string => Guide::forScreen($m, '', '', 'cs'), ['quotes', 'applications', 'internal_files', 'my_work', 'contacts', 'pipeline', 'fleet']),
    Guide::forScreen('quotes', 'builder', '', 'en'), Guide::forScreen('stats', '', '', 'en'), Guide::chapterFor('pages'),
], [[], true, [], ['admin.php?action=guide&chapter=quotes', 'admin.php?action=guide&chapter=applications', 'admin.php?action=guide&chapter=internal-files',
    'admin.php?action=guide&chapter=my-work', 'admin.php?action=guide&chapter=customers', 'admin.php?action=guide&chapter=pipeline', 'admin.php?action=guide&chapter=fleet'],
    'https://kaletacms.com/guide/builder-basics', 'https://kaletacms.com/guide/statistics', null]);
check('3.45 B: a chapter has a title, a summary, steps and tips; its online article exists; the index lists only the sections a person may open', [
    array_keys(array_filter(Guide::CHAPTERS, fn (array $c): bool => $c['title'] === '' || $c['summary'] === '' || count($c['steps']) < 3 || $c['tips'] === [])),
    array_values(array_filter(array_column(Guide::CHAPTERS, 'online'), fn (?string $a): bool => $a !== null && !isset(array_flip(array_map(fn (string $t): string => strtok($t, '#'), Guide::MODULES))[$a]))),
    Guide::chaptersFor(['my_work', 'pages']), Guide::chaptersFor(['quotes', 'contacts', 'news']), Guide::chaptersFor([]),
], [[], [], ['my-work'], ['customers', 'quotes'], []]);

// every text of the guide and of the palette in Czech, German and Spanish, with the same placeholders; German without a form of address
$b345Texts = ['Guide to the office', 'Guide', 'Step by step', 'Good to know', 'Open %s', 'More in the online guide', 'opens in a new tab', 'All chapters of the guide',
    'No chapter for your sections yet.', 'The chapters follow the sections you may open. Ask an administrator for access.'];
foreach (Guide::CHAPTERS as $b345C) {
    $b345Texts = [...$b345Texts, $b345C['title'], $b345C['summary'], ...$b345C['steps'], ...$b345C['tips']];
}
foreach (Palette::QUICK as $b345Items) {
    $b345Texts = [...$b345Texts, ...array_column($b345Items, 0)];
}
$b345Texts = [...$b345Texts, ...array_column(Palette::EVERYONE, 0)];
preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_SYSTEM . '/views/admin/guide.php'), $b345View);
$b345Gaps = [];
foreach (['admin-cs', 'admin-de', 'admin-es'] as $b345File) {
    $b345Dict = require KALETA_SYSTEM . '/jazyky/' . $b345File . '.php';
    foreach (array_unique([...$b345Texts, ...array_map('stripslashes', $b345View[1])]) as $b345Key) {
        if (!isset($b345Dict[$b345Key])) {
            $b345Gaps[] = $b345File . ': ' . $b345Key;
        } elseif (substr_count($b345Key, '%') !== substr_count((string) $b345Dict[$b345Key], '%')) {
            $b345Gaps[] = $b345File . ' placeholders: ' . $b345Key;
        }
    }
}
$b345De = require KALETA_SYSTEM . '/jazyky/admin-de.php';
check('3.45 B: the guide and the palette speak Czech, German and Spanish; the German chapters address nobody (no du overlay needed)', [
    array_slice($b345Gaps, 0, 5),
    array_values(array_filter($b345Texts, fn (string $k): bool => preg_match('/[^.!?:„“"»(–]\s+(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen)\b/u', (string) ($b345De[$k] ?? '')) === 1)),
], [[], []]);

// the palette: per section, administrators' screens only for them, "New quote" as a POST, every query an action of its section
$b345Quick = fn (array $modules, bool $admin): array => array_map(fn (array $q): string => $q[0] . ($q[3] ? ' (POST)' : ''), Palette::quick($modules, $admin));
$b345Bad = [];
foreach (Palette::QUICK as $b345Ident => $b345Items) {
    $b345Class = array_values(array_filter(Kaleta\Admin\Kernel::MODULES, fn (string $c): bool => $c::IDENT === $b345Ident))[0] ?? null;
    foreach ($b345Items as $b345Item) {
        parse_str($b345Item[1], $b345Q);
        $b345Action = (string) ($b345Q['action'] ?? 'list');
        if ($b345Class === null || ($b345Q['module'] ?? '') !== $b345Ident
            || !method_exists($b345Class, 'action' . str_replace('_', '', ucwords($b345Action, '_')))) {
            $b345Bad[] = $b345Item[1];
        }
    }
}
check('3.45 B (U70): the quick actions of 3.34–3.42 follow the sections and the administrator; each opens a real action; New quote posts', [
    $b345Quick(['quotes', 'applications', 'internal_files', 'users'], false), $b345Quick(['quotes', 'applications', 'internal_files', 'users'], true),
    $b345Quick(['my_work'], false), $b345Quick(['pages'], true), $b345Bad,
    array_column(Palette::EVERYONE, 1),
], [['New quote (POST)', 'Internal prices', 'Applications board', 'Talent pool', 'New user', 'Departments'],
    ['New quote (POST)', 'Internal prices', 'Applications board', 'Talent pool', 'New folder', 'New user', 'Departments'],
    ['Team summary'], ['New page'], [],
    ['action=account', 'action=account#absences', 'action=account&tab=colleagues', 'action=account&tab=org_chart', 'action=inbox', 'action=guide']]);
// update_settings: English values besides the Czech ones – each English value lands on a valid Czech choice
$b345Valid = [];
foreach (Translator::SETTING_VALUES as $b345Key => $b345Map) {
    foreach ($b345Map as $b345English => $b345Czech) {
        if (Kaleta\Admin\Modules\Settings::verifyValue($b345Key, $b345Czech) !== $b345Czech || Kaleta\Admin\Modules\Settings::verifyValue($b345Key, $b345English) !== null) {
            $b345Valid[] = $b345Key . '=' . $b345English;
        }
    }
}
check('3.45 B: update_settings takes dark, allow, without, built_in… (and DARK, " none ") as the stored Czech values; Czech and other values pass unchanged', [
    Translator::settingValue('dark_mode', 'dark'), Translator::settingValue('dark_mode', 'off'), Translator::settingValue('dark_mode', 'auto'), Translator::settingValue('dark_mode', 'tmavy'),
    Translator::settingValue('ai_crawlers', 'block'), Translator::settingValue('url_slash', 'with'), Translator::settingValue('url_slash', 'html'),
    Translator::settingValue('cookies_mode', 'built_in'), Translator::settingValue('cookies_mode', ' None '), Translator::settingValue('site_name', 'dark'),
    Translator::settingValue('dark_mode', true), $b345Valid,
], ['tmavy', 'vypnuto', 'auto', 'tmavy', 'zakazat', 's', 'html', 'vestavena', 'zadna', 'dark', true, []]);
check('3.45 B: a yes/no setting takes yes and on besides 1, true and ano; no and anything else is 0', [
    Kaleta\Admin\Modules\Settings::verifyValue('share_buttons', 'yes'), Kaleta\Admin\Modules\Settings::verifyValue('share_buttons', 'On'),
    Kaleta\Admin\Modules\Settings::verifyValue('share_buttons', 'ano'), Kaleta\Admin\Modules\Settings::verifyValue('share_buttons', 'no'),
    Kaleta\Admin\Modules\Settings::verifyValue('share_buttons', 'maybe'),
], ['1', '1', '1', '0', '0']);
