<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * Hubs (3.2): related screens behind one menu item, with tabs across the top. The screens stay separate modules with
 * their own idents and URLs (nothing breaks); the menu shows only the hub, and the tabs lead between its screens.
 *
 *  - Business details: the company and its opening hours, the facts, the claims, the blueprints
 *  - Features: the built-in features and the add-ons
 *  - Claude: the settings (instructions, guardrails, connections), Ask Claude, scheduled runs, the notebook, the sessions
 *  - Newsletter (3.25): the newsletters and their subscribers
 *  - Users (3.47, U67): the users, the roles and the departments
 *  - Sales (3.48, decision 6): the Pipeline, the Quotes and the internal prices – the menu item is "Sales" while both
 *    Pipeline and Quotes are open to the person (Module::menuName)
 *  - Addresses (3.48): the redirects and the broken links – "Addresses" while both are open
 */
final class Hubs
{
    /**
     * hub => list of [module ident, action ('' = the list), label, optional: the actions whose screens belong to the tab (3.48)];
     * a tab shows only when the person may open its module
     */
    public const array TABS = [
        'business' => [['business', '', 'Company and opening hours'], ['facts', '', 'Facts'], ['facts', 'claims', 'Claims'], ['blueprints', '', 'Blueprints']],
        'features' => [['extensions', '', 'Features'], ['addons', '', 'Add-ons']],
        'claude' => [['claude_settings', '', 'Claude settings'], ['requests', '', 'Ask Claude'], ['schedules', '', 'Scheduled runs'], ['notebook', '', 'Notebook'], ['changelog', 'sessions', 'Claude sessions']],
        'newsletter' => [['newsletters', '', 'Newsletters'], ['subscribers', '', 'Subscribers']], // 3.25
        'users' => [['users', '', 'Users'], ['roles', '', 'Roles'], ['users', 'departments', 'Departments']], // 3.47 (U67)
        // 3.48 (decision 6): every screen of the price list highlights "Internal prices"
        'sales' => [['pipeline', '', 'Pipeline'], ['quotes', '', 'Quotes'],
            ['quotes', 'prices', 'Internal prices', ['prices', 'prices_save', 'item_prices_save', 'prices_export', 'prices_import', 'prices_import_run', 'list_save', 'list_archive', 'list_default']]],
        'addresses' => [['redirects', '', 'Redirects'], ['broken_links', '', 'Broken links']], // 3.48
    ];

    /**
     * The tab bar of a hub for the screen being shown; '' when fewer than two of its tabs are open to the person.
     *
     * @param array<string, class-string<Module>> $modules the modules the person may open (Kernel::modules())
     */
    public static function tabs(string $hub, array $modules, string $ident, string $action, callable $url): string
    {
        $tabs = array_values(array_filter(self::TABS[$hub] ?? [], fn (array $tab): bool => isset($modules[$tab[0]])));
        if (count($tabs) < 2) {
            return '';
        }
        $current = self::current($tabs, $ident, $action);
        $html = '<nav class="zalozky zalozky-hub" aria-label="' . e(t('Sections')) . '">';
        foreach ($tabs as $i => [$module, $tabAction, $label]) {
            $html .= '<a href="' . e($url('admin.php?module=' . $module . ($tabAction !== '' ? '&action=' . $tabAction : ''))) . '"' . ($i === $current ? ' class="aktivni" aria-current="page"' : '') . '>' . e(t($label)) . '</a>';
        }

        return $html . '</nav>';
    }

    /**
     * Which of the tabs the screen belongs to (its index, null for none): the tab whose own actions list the action (3.48),
     * else the same module and action, else the module's list. Pure.
     *
     * @param list<array<int, mixed>> $tabs rows of TABS
     */
    public static function current(array $tabs, string $ident, string $action): ?int
    {
        foreach ($tabs as $i => $tab) {
            if ($tab[0] === $ident && in_array($action, $tab[3] ?? [], true)) {
                return $i;
            }
        }
        $current = null;
        foreach ($tabs as $i => [$module, $tabAction]) {
            if ($module === $ident && $tabAction === $action) {
                $current = $i;
            }
        }
        foreach ($tabs as $i => [$module, $tabAction]) {
            if ($current === null && $module === $ident && $tabAction === '') {
                $current = $i;
            }
        }

        return $current;
    }
}
