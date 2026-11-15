<?php

declare(strict_types=1);

use Kaleta\Core\Language;

/* ---------- 3.42 T8 (audit Q15): t() calls with a Czech source text moved to the English one – every reader sees the same text ---------- */

// [the Czech source the call had, the English source it has now, where the calls are read: admin- | install- | ? (any set)]
$q15Moved = [
    ['Adresa', 'URL', 'admin-'],
    ['E-mail', 'Email', 'admin-'],
    ['Filtrovat', 'Filter', 'admin-'],
    ['Heslo znovu', 'Password again', 'install-'],
    ['Heslo znovu', 'Repeat password', 'admin-'],
    ['Jazyk webu', 'Site language', 'admin-'],
    ['Jazyk webu', 'Website language', 'install-'],
    ['Jméno a příjmení', 'First and last name', 'admin-'],
    ['Nastavení', 'Settings', 'admin-'],
    ['Nezařazené', 'Uncategorized', 'admin-'],
    ['Novinka', 'News item', 'admin-'],
    ['Název stránky', 'Page title', 'admin-'],
    ['Obnova se nezdařila: %s', 'The restore failed: %s', 'admin-'],
    ['Označit jako vyřízené', 'Mark as resolved', 'admin-'],
    ['Podrobnost', 'Detail', 'admin-'],
    ['Popis pro nevidomé (alt)', 'Description for screen readers (alt)', 'admin-'],
    ['Použito', 'Used', 'admin-'],
    ['Pořadí', 'Order', 'admin-'],
    ['Pro pokročilé', 'Advanced', 'admin-'],
    ['Přidat', 'Add', 'admin-'],
    ['Přihlašovací jméno', 'User name', 'admin-'],
    ['Přihlašovací jméno', 'Username', 'install-'],
    ['Přihlášení', 'Sign in', 'admin-'],
    ['Sekce', 'Section', 'admin-'],
    ['Seznam', 'List', 'admin-'],
    ['Smazat', 'Delete', 'admin-'],
    ['Styl', 'Style', 'admin-'],
    ['Titulek', 'Headline', 'admin-'],
    ['Typ', 'Type', 'admin-'],
    ['Uložit', 'Save', 'admin-'],
    ['Velikost', 'Size', 'admin-'],
    ['Zabezpečení', 'Security', 'admin-'],
    ['Založit a otevřít v builderu', 'Create and open in the builder', 'admin-'],
    ['Zařízení', 'Devices', 'admin-'],
    ['Změna hesla', 'Change password', 'admin-'],
    ['Zobrazit web', 'View site', 'admin-'],
    ['Zobrazit web', 'View the site', 'install-'],
    ['Zobrazuje se u novinek.', 'Shown with news items.', 'admin-'],
    ['Zobrazuje se u novinek.', 'Shown with news posts.', 'install-'],
    ['nepublikované', 'unpublished', 'admin-'],
    ['vypnuté', 'off', 'admin-'],
    ['výběr', 'select', 'admin-'],
    ['zapnuté', 'on', 'admin-'],
    ['zatím nepoužita', 'not used yet', 'admin-'],
    ['zobrazit', 'show', 'admin-'],
    ['Štítek', 'Tag', 'admin-'],
];
// the sets a pair is checked in: the admin's or the installer's for calls in their views and modules, every set for the
// rest (Core code runs under the site's dictionaries for MCP and the public pages and under the admin's on a screen)
$q15Relevant = fn (string $where, string $czech): array => str_contains($where, '?') ? ['', 'admin-', 'install-'] : explode(',', $where);
$q15Changed = [];
$q15Merged = [];
foreach (['', 'admin-', 'install-'] as $q15Set) {
    $q15Pairs = array_values(array_filter($q15Moved, fn (array $m): bool => in_array($q15Set, $q15Relevant($m[2], $m[0]), true)));
    $q15Cs = require KALETA_SYSTEM . '/jazyky/' . $q15Set . 'cs.php';
    foreach ($q15Pairs as [$q15Czech, $q15English]) {
        if (($q15Cs[$q15English] ?? $q15Czech) !== ($q15Cs[$q15Czech] ?? $q15Czech)) {
            $q15Merged[] = $q15Set . 'cs: ' . $q15English . ' → ' . $q15Cs[$q15English];
        }
    }
    $q15Codes = array_values(array_unique(array_map(fn (string $f): string => preg_replace('/-du$/', '', substr(basename($f, '.php'), strlen($q15Set))),
        array_filter(glob(KALETA_SYSTEM . '/jazyky/' . $q15Set . '*.php') ?: [], fn (string $f): bool => $q15Set !== '' || !preg_match('/^(admin|install)-/', basename($f))))));
    foreach ($q15Codes as $q15Code) {
        foreach (['formal', 'informal'] as $q15Register) {
            $q15Read = Language::runWith($q15Code, fn (): array => array_map(fn (array $m): array => [t($m[0]), t($m[1])], $q15Pairs), $q15Set, $q15Register);
            foreach ($q15Read as $q15i => [$q15Old, $q15New]) {
                if ($q15Old !== $q15New) {
                    $q15Changed[] = $q15Set . $q15Code . ' ' . $q15Register . ': ' . $q15Pairs[$q15i][0] . ' → ' . $q15Old . ' / ' . $q15New;
                }
            }
        }
    }
}
check('3.42 Q15: each of the ' . count($q15Moved) . ' texts moved to an English source reads the same in every language, dictionary set and form of address as before', $q15Changed, []);
check('3.42 Q15: an English source the change gave a text is never the translation of a different Czech text in the Czech dictionaries (no two texts merged)', $q15Merged, []);
