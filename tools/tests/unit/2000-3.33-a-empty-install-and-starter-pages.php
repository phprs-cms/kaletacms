<?php

declare(strict_types=1);

use Kaleta\Core\StarterPages;

/* ---------- 3.33 part A (M2): an empty install for a move, the record of the starter pages ---------- */
$i333Row = ['titulek' => 'Kontakt', 'seo_link' => 'kontakt', 'text' => '<p>Adresa</p>', 'stavba' => null, 'popis' => '', 'seo_titulek' => '', 'obrazek' => '', 'zobrazit' => 1, 'jazyk' => ''];
check('3.33 M2: the starter fingerprint is stable and ignores columns a person does not edit (order, edit time)',
    StarterPages::fingerprint($i333Row) === StarterPages::fingerprint($i333Row + ['poradi' => 40, 'zmeneno' => '2026-11-11 10:00:00']), true);
check('3.33 M2: any edit of title, address, text, build, description, SEO title, image or visibility changes the fingerprint', array_map(
    fn (array $change): bool => StarterPages::fingerprint($change + $i333Row) !== StarterPages::fingerprint($i333Row),
    [['titulek' => 'Kontakty'], ['seo_link' => 'kontakty'], ['text' => '<p>Jinak</p>'], ['stavba' => '{"v":1,"deti":[]}'], ['popis' => 'x'], ['seo_titulek' => 'x'], ['obrazek' => 'media/a.jpg'], ['zobrazit' => 0]]),
    array_fill(0, 8, true));
check('3.33 M2: the installer\'s starter titles in the site language – the legacy rule of a site from before 3.33',
    [StarterPages::titles('cs'), StarterPages::titles('en'), StarterPages::titles('xx')],
    [['Úvod', 'O nás', 'Služby', 'Kontakt'], ['Home', 'About us', 'Services', 'Contact'], ['Úvod', 'O nás', 'Služby', 'Kontakt']]);
check('3.33 M2: the starter record is an internal setting (never exported, never set over MCP)', [
    array_key_exists(StarterPages::SETTING, Kaleta\Core\Settings::DEFAULTS),
    in_array(StarterPages::SETTING, Kaleta\Core\SiteExport::SETTINGS, true),
], [true, false]);
$i333Form = (string) file_get_contents(KALETA_ROOT . '/system/views/install/form.php');
check('3.33 M2: the installer offers the empty site for a move next to the starter sites and the export', [
    str_contains($i333Form, 'Kaleta\Install\Installer::MOVE'), Kaleta\Install\Installer::MOVE, isset(Kaleta\Builder\Library::SITES[Kaleta\Install\Installer::MOVE]),
], [true, 'move', false]);
