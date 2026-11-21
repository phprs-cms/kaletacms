<?php

declare(strict_types=1);

/* ---------- 3.50.1 C: the guide has real text for every section of the administration (CPO-c1) ---------- */
// Every module of the menu (except Settings, whose tabs stand for it), every Settings tab, the Dashboard ('') and My account
// map to a chapter of docs/guide.md and to its heading or a bold lead-in that opens a paragraph; at least 300 characters of
// text follow before the next heading or lead-in. docs/prirucka.md has the same chapters with as many lead-ins in each.
// Reading docs/ is no source pin: the guide is the product here.

// ident => [chapter, '**lead-in**' at the start of a paragraph, or a heading's text]
$g3501Modules = [
    '' => [1, '**The Dashboard.**'], 'account' => [19, '**My account.**'], 'my_work' => [19, '**My work.**'],
    'pages' => [2, 'Page builder'], 'news' => [9, '**News**'], 'categories' => [9, '**Categories and tags.**'], 'tags' => [9, '**Categories and tags.**'],
    'collections' => [6, '**Content → Collections**'], 'media' => [20, '**Content → Media**'], 'newsletters' => [7, '**Content → Newsletter**'],
    'subscribers' => [7, '**Subscribers.**'], 'business' => [8, '**Business details:**'], 'facts' => [8, '**Facts and claims.**'],
    'blueprints' => [22, '**Blueprints.**'], 'enquiries' => [7, '**Enquiries.**'], 'contacts' => [15, 'Customers'], 'pipeline' => [14, '**Pipeline**'],
    'quotes' => [14, '**Quotes**'], 'applications' => [16, '**Applications**'], 'bookings' => [7, '**Online bookings.**'], 'internal_files' => [17, '**Internal files**'],
    'appearance' => [3, '**Appearance → Site appearance**'], 'parts' => [4, '**Appearance → Site parts.**'], 'menu' => [4, '**Appearance → Menu**'],
    'components' => [5, 'Components'], 'popups' => [4, '**Appearance → Pop-ups**'], 'requests' => [10, '**Ask Claude.**'], 'claude_settings' => [10, '**Claude settings.**'],
    'schedules' => [22, '**Scheduled runs.**'], 'notebook' => [22, "**Claude's notebook.**"], 'audit' => [12, '**Administration → Site audit**'],
    'stats' => [21, '**Statistics.**'], 'redirects' => [12, '**Patterns, gone addresses and many redirects at once (3.6).**'], 'broken_links' => [12, '**Broken links.**'],
    'changelog' => [21, '**The change log.**'], 'status' => [12, '**System status.**'], 'users' => [1, '**Users and roles.**'], 'roles' => [1, '**Users and roles.**'],
    'connectors' => [22, '**Connections to outside services.**'], 'extensions' => [1, '**Features.**'], 'addons' => [24, '**Add-ons.**'],
    'transfer' => [11, '**Administration → Import and export → Import from a website**'], 'whistleblowing' => [18, '**Whistleblowing:**'], 'fleet' => [23, 'Fleet console'],
];
// Settings tab => [chapter, needle]
$g3501Tabs = [
    'general' => [24, '**Settings → General.**'], 'sections' => [24, '**Settings of sections.**'], 'languages' => [9, '**Settings → Languages.**'],
    'company' => [8, '**Business details:**'], 'seo' => [9, '**Settings → SEO and GEO.**'], 'analytics' => [21, '**Settings → Analytics.**'],
    'cookies' => [18, '**Cookie bar.**'], 'mail' => [24, '**Settings → Mail.**'], 'reports' => [24, '**Settings → Reports.**'], 'webhooks' => [7, '**Settings → Webhooks**'],
    'backups' => [13, '**Settings → Backups and updates:**'], 'security' => [24, '**Settings → Security.**'], 'firewall' => [12, '**Firewall.**'],
    'agency' => [1, '**Building the site for a client?**'], 'console' => [23, '**Reporting to a console.**'], 'health' => [12, '**System status.**'],
];

// a guide file as chapter number => [title, text]; a lead-in is a bold run that opens a paragraph
$g3501Read = function (string $file): array {
    preg_match_all('/^## (\d+)\. (.+)\n/m', (string) file_get_contents(KALETA_ROOT . '/docs/' . $file), $m, PREG_OFFSET_CAPTURE);
    $text = (string) file_get_contents(KALETA_ROOT . '/docs/' . $file);
    $out = [];
    foreach ($m[0] as $i => $heading) {
        $start = $heading[1] + strlen($heading[0]);
        $end = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($text);
        $out[(int) $m[1][$i][0]] = [trim($m[2][$i][0]), "\n" . substr($text, $start, $end - $start)];
    }

    return $out;
};
$g3501LeadIns = fn (string $chapter): int => preg_match_all('/\n\n\*\*[^*\n]+\*\*/', $chapter);
// the text behind a needle up to the next heading or lead-in, or null when the needle is not where the map says
$g3501Behind = function (array $chapters, int $number, string $needle): ?string {
    [$title, $text] = $chapters[$number] ?? ['', ''];
    if (!str_starts_with($needle, '**')) {
        $from = $title === $needle ? 0 : (($p = strpos($text, "\n### " . $needle . "\n")) === false ? null : $p + strlen($needle) + 6);
    } else {
        $from = ($p = strpos($text, "\n\n" . $needle)) === false ? null : $p + 2 + strlen($needle);
    }
    if ($from === null) {
        return null;
    }
    $rest = substr($text, $from);
    preg_match('/\n\n\*\*[^*\n]+\*\*|\n#/', $rest, $next, PREG_OFFSET_CAPTURE);

    return trim(isset($next[0]) ? substr($rest, 0, $next[0][1]) : $rest);
};

$g3501En = $g3501Read('guide.md');
$g3501Cs = $g3501Read('prirucka.md');
$g3501Idents = array_values(array_diff(array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES), ['settings']));
$g3501Short = [];
foreach (['module' => $g3501Modules, 'tab' => $g3501Tabs] as $g3501Kind => $g3501Map) {
    foreach ($g3501Map as $g3501Key => [$g3501Number, $g3501Needle]) {
        $g3501Text = $g3501Behind($g3501En, $g3501Number, $g3501Needle);
        if ($g3501Text === null || mb_strlen($g3501Text) < 300) {
            $g3501Short[] = $g3501Kind . ' ' . $g3501Key . ': ' . ($g3501Text === null ? 'not found' : mb_strlen($g3501Text) . ' characters');
        }
    }
}
check('3.50.1 C (CPO-c1): the map names every section of the menu, the Dashboard, My account and every Settings tab – no more, no less', [
    array_values(array_diff([...$g3501Idents, '', 'account'], array_keys($g3501Modules))), array_values(array_diff(array_keys($g3501Modules), [...$g3501Idents, '', 'account'])),
    array_values(array_diff(array_keys(Kaleta\Admin\Modules\Settings::TABS), array_keys($g3501Tabs))), array_values(array_diff(array_keys($g3501Tabs), array_keys(Kaleta\Admin\Modules\Settings::TABS))),
], [[], [], [], []]);
check('3.50.1 C (CPO-c1): every section and Settings tab has its heading or lead-in in its chapter of docs/guide.md with at least 300 characters of text behind it', $g3501Short, []);
check('3.50.1 C (D7): chapters 1–18 keep their numbers and titles; 19–24 are the new ones', [
    array_keys($g3501En) === range(1, 24), $g3501En[1][0], $g3501En[18][0], array_column(array_slice($g3501En, 18, 6, true), 0),
], [true, 'Installation and first steps', 'Privacy and retention',
    ['My work and My account', 'Media library', 'Statistics and the change log', 'Claude\'s notebook, scheduled runs, blueprints and connections', 'Fleet console', 'Add-ons and the other settings']]);
check('3.50.1 C (CPO-l3–l7): docs/prirucka.md has the same chapters and as many lead-ins in each as docs/guide.md', [
    array_keys($g3501Cs) === array_keys($g3501En),
    array_filter(array_map(fn (int $n): ?string => $g3501LeadIns($g3501En[$n][1]) === $g3501LeadIns($g3501Cs[$n][1] ?? '') ? null
        : $n . ': ' . $g3501LeadIns($g3501En[$n][1]) . ' / ' . $g3501LeadIns($g3501Cs[$n][1] ?? ''), array_keys($g3501En))),
], [true, []]);
check('3.50.1 C (CPO-l4, l5): chapter 1 names the three install profiles, Choose yourself and the four ready roles; chapter 18 opens Enquiries → Personal data request before Claude and lists customers among the retention periods', [
    array_values(array_filter(['*Website*', '*Website with enquiries*', '*Front office*', '**Choose yourself**', '**Client**', '**Writer**', '**HR**', '**Office / sales**'],
        fn (string $w): bool => !str_contains($g3501En[1][1], $w))),
    count(array_filter(Kaleta\Admin\Modules\Roles::PRESETS, fn (array $p): bool => str_contains($g3501En[1][1], '**' . $p[0] . '**'))) === count(Kaleta\Admin\Modules\Roles::PRESETS),
    strpos($g3501En[18][1], 'Personal data') !== false && strpos($g3501En[18][1], 'Enquiries → Personal data') < strpos($g3501En[18][1], 'Claude does the same'),
    str_contains((string) $g3501Behind($g3501En, 18, '**Retention:**'), 'customers'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/docs/FEATURES.md'), 'pageVariant'),
], [[], true, true, true, false]);
