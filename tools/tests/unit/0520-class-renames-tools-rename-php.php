<?php

declare(strict_types=1);

/* ---------- class renames (tools/rename.php) ---------- */
// 2.0: the per-page Modal element becomes a site pop-up (Builder\ModalConversion)
[$withoutModal, $modals] = Kaleta\Builder\ModalConversion::extract(['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'tlacitko', 'obsah' => ['odkaz' => '#nabidka']],
    ['id' => 'o1', 'typ' => 'okno', 'kotva' => 'nabidka', 'deti' => [['typ' => 'nadpis']]]]]]]);
check('Modal → pop-up: vyjmutí z hloubky stavby, kotva, přepsání odkazů', [count($withoutModal['deti'][0]['deti']), count($modals), Kaleta\Builder\ModalConversion::anchor($modals[0]),
    Kaleta\Builder\ModalConversion::anchor(['id' => 'x1']), Kaleta\Builder\ModalConversion::anchor(['id' => 'x1', 'atributy' => ['id' => 'vlastni']]),
    Kaleta\Builder\ModalConversion::rewriteLinks('{"odkaz":"#nabidka","html":"<a href=\\"#nabidka\\">x</a>","jiny":"#nabidka-2"}', ['nabidka' => 'nabidka'])],
    [1, 1, 'nabidka', 'okno-x1', 'vlastni', '{"odkaz":"#popup-nabidka","html":"<a href=\\"#popup-nabidka\\">x</a>","jiny":"#nabidka-2"}']);
check('2.0: staré klíče nastavení jen na hranici (MCP, import, aktualizace)', [Kaleta\Core\OldSettingsKeys::current('nazev_webu'), Kaleta\Core\OldSettingsKeys::current('nazev_webu_de'),
    Kaleta\Core\OldSettingsKeys::current('site_name'), Kaleta\Core\OldSettingsKeys::current('verze_db')], ['site_name', 'site_name_de', 'site_name', 'db_version']);
// 2.0: the old (Czech) class names and helpers of 1.3 are gone; 2.0.1 dropped the empty alias file (it rides along in packages only)
check('2.0: old class names and helpers no longer exist', [is_file(KALETA_SYSTEM . '/class-aliases.php'), class_exists('Kaleta\\Jadro\\Nastaveni'), function_exists('datum_slovy'),
    class_exists('Kaleta\\Admin\\LegacyUrls'), class_exists('Kaleta\\Front\\Api'), class_exists('Kaleta\\Builder\\Elements\\Modal')], [false, false, false, false, false, false]);
exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/rename.php') . ' --self-test', $renameOutput, $renameCode);
check('tools/rename.php self-test', $renameCode, 0);

