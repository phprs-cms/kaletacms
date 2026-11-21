<?php

declare(strict_types=1);

/* ---------- 3.50 C: the guide catch-up – every menu section resolves to an existing chapter ---------- */
use Kaleta\Admin\Guide as C350Guide;
use Kaleta\Admin\Kernel as C350Kernel;

// the articles of the online guide (unit 0530 keeps the list) and the chapters in the administration
$c350Articles = array_unique(array_map(fn (string $a): string => (string) strtok($a, '#'), [...C350Guide::MODULES, ...C350Guide::SETTINGS]));
$c350Unresolved = [];
foreach (C350Kernel::MODULES as $c350Class) {
    $c350Ident = $c350Class::IDENT;
    if ($c350Ident === 'settings') {
        continue; // its tabs have their own articles (Guide::SETTINGS)
    }
    $c350Target = C350Guide::forScreen($c350Ident, '', '', 'en');
    $c350Chapter = C350Guide::chapterFor($c350Ident);
    if ($c350Target === null || ($c350Chapter !== null ? !isset(C350Guide::CHAPTERS[$c350Chapter]) : !in_array(strtok((string) (C350Guide::MODULES[$c350Ident] ?? ''), '#'), $c350Articles, true))) {
        $c350Unresolved[] = $c350Ident;
    }
}
check('3.50 C: every section of the menu resolves to an existing chapter – its online article or its chapter in the administration; no stand-in rows (Whistleblowing has its chapter, Broken links the article on redirects)', [
    $c350Unresolved, C350Guide::forScreen('whistleblowing', '', '', 'en'), C350Guide::MODULES['broken_links'], C350Guide::CHAPTERS['privacy']['online'],
    str_contains(implode(' ', C350Guide::CHAPTERS['my-work']['tips']), 'My account → Org chart'),
], [[], 'admin.php?action=guide&chapter=privacy', 'seo#redirects-and-404s', 'privacy-cookies', false]);

// docs/guide.md and its Czech mirror docs/prirucka.md: the same numbered chapters, the five of the office part among them
$c350Headings = function (string $file): array {
    preg_match_all('/^## (\d+)\. (.+)$/m', (string) file_get_contents(KALETA_ROOT . '/docs/' . $file), $m);

    return array_combine(array_map('intval', $m[1]), $m[2]);
};
$c350En = $c350Headings('guide.md');
$c350Cs = $c350Headings('prirucka.md');
check('3.50 C: docs/guide.md has chapters for Sales, Customers, Applications, Internal files and Privacy; docs/prirucka.md mirrors every chapter', [
    array_keys($c350En) === range(1, count($c350En)), array_keys($c350Cs) === array_keys($c350En),
    array_slice($c350En, 13, 5, true), array_slice($c350Cs, 13, 5, true),
], [true, true, [14 => 'Sales: pipeline and quotes', 15 => 'Customers', 16 => 'Job applications', 17 => 'Internal files', 18 => 'Privacy and retention'],
    [14 => 'Obchod: pipeline a nabídky', 15 => 'Zákazníci', 16 => 'Žádosti o práci', 17 => 'Interní soubory', 18 => 'Soukromí a doba uchování']]);
