<?php

declare(strict_types=1);

/* ---------- 3.50.1 C: the guide in the administration – chapters for whole tabs, chapters for everybody (CPO-l2, l3, l4, l9) ---------- */
use Kaleta\Admin\Guide as G3501Guide;

// one source for the online articles: the hand-kept list of unit 0530 ($guideArticles), never the targets compared with themselves
$g3501Articles = $guideArticles;
$g3501Online = array_values(array_filter(
    [...G3501Guide::MODULES, ...G3501Guide::SETTINGS, ...G3501Guide::ACCOUNT, ...G3501Guide::BUILDER, ...array_filter(array_column(G3501Guide::CHAPTERS, 'online'))],
    fn (string $a): bool => !in_array(strtok($a, '#'), $g3501Articles, true)));
$g3501Chapter = fn (string $key): string => 'admin.php?action=guide&chapter=' . $key;
check('3.50.1 C (CPO-l1): every online article a screen or a chapter links to is in the hand-kept list of unit 0530', $g3501Online, []);

// every screen a chapter claims opens that chapter, and every chapter is reachable from at least one screen
$g3501Unreached = [];
$g3501Wrong = [];
foreach (G3501Guide::CHAPTERS as $g3501Key => $g3501C) {
    $g3501Screens = array_map(fn (string $m): array => [$m, ''], $g3501C['modules']);
    foreach ($g3501C['tabs'] ?? [] as $g3501Screen => $g3501Tabs) {
        foreach ($g3501Tabs as $g3501Tab) {
            $g3501Screens[] = [$g3501Screen, $g3501Tab];
        }
    }
    if ($g3501Screens === []) {
        $g3501Unreached[] = $g3501Key;
    }
    foreach ($g3501Screens as [$g3501Module, $g3501Tab]) {
        if (G3501Guide::forScreen($g3501Module, '', $g3501Tab, 'cs') !== $g3501Chapter($g3501Key)) {
            $g3501Wrong[] = $g3501Key . ': ' . $g3501Module . '/' . $g3501Tab;
        }
    }
}
check('3.50.1 C (CPO-l9): each chapter opens from the sections and tabs it names; none is left without a screen', [$g3501Unreached, $g3501Wrong], [[], []]);
check('3.50.1 C (CPO-l9): My account details, absence, security and colleagues open the chapter My account; Claude and Signature keep their articles; four Settings tabs open Settings for the whole site, the others their articles', [
    G3501Guide::forScreen('account', '', 'absence', 'en'), G3501Guide::forScreen('account', '', '', 'en'), G3501Guide::forScreen('account', '', 'colleagues', 'de'),
    G3501Guide::forScreen('account', '', 'signature', 'en'), G3501Guide::forScreen('account', '', 'claude', 'cs'),
    array_map(fn (string $t): ?string => G3501Guide::forScreen('settings', '', $t, 'en'), ['sections', 'security', 'agency', 'reports']),
    G3501Guide::forScreen('settings', '', 'backups', 'en'), G3501Guide::forScreen('settings', '', '', 'en'),
    G3501Guide::forScreen('notebook', '', '', 'en'), G3501Guide::forScreen('schedules', '', '', 'en'), G3501Guide::forScreen('changelog', '', '', 'en'),
    G3501Guide::forScreen('pages', 'builder', '', 'en'), G3501Guide::forScreen('', '', '', 'en'),
], [$g3501Chapter('account'), $g3501Chapter('account'), $g3501Chapter('account'),
    'https://kaletacms.com/guide/email#your-e-mail-signature', 'https://kaletacms.com/cs/guide/claude-connect',
    array_fill(0, 4, $g3501Chapter('site-settings')),
    'https://kaletacms.com/guide/backups-updates', 'https://kaletacms.com/guide/first-steps',
    $g3501Chapter('claude-notes'), $g3501Chapter('claude-notes'), $g3501Chapter('changelog'),
    'https://kaletacms.com/guide/builder-basics', 'https://kaletacms.com/guide/first-steps#the-dashboard']);
check('3.50.1 C (CPO-l2): Privacy and My account are listed for everybody – also an administrator whose every feature is off; Settings for the whole site only for whoever may open Settings', [
    G3501Guide::chaptersFor([]), G3501Guide::chaptersFor(['users', 'settings', 'extensions']), in_array('site-settings', G3501Guide::chaptersFor(['pages']), true),
    G3501Guide::chapterFor('whistleblowing'), G3501Guide::chapterForTab('settings', 'cookies'), G3501Guide::chapterForTab('account', 'claude'),
], [['account', 'privacy'], ['account', 'privacy', 'site-settings'], false, 'privacy', null, null]);
check('3.50.1 C (CPO-l3, l4): the privacy chapter names Enquiries → Personal data request before Claude; the customers chapter names their own period', [
    str_contains(G3501Guide::CHAPTERS['privacy']['steps'][1], 'Enquiries → Personal data request'),
    strpos(G3501Guide::CHAPTERS['privacy']['steps'][1], 'Personal data request') < strpos(G3501Guide::CHAPTERS['privacy']['steps'][1], 'Claude'),
    count(array_filter(G3501Guide::CHAPTERS['customers']['tips'], fn (string $t): bool => str_contains($t, 'Settings → Privacy and cookies'))),
    array_keys(array_filter(G3501Guide::CHAPTERS, fn (array $c): bool => count($c['tips']) > 3)),
], [true, true, 1, []]);
