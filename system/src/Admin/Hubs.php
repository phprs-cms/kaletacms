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
 */
final class Hubs
{
    /** hub => list of [module ident, action ('' = the list), label]; a tab shows only when the person may open its module */
    public const array TABS = [
        'business' => [['business', '', 'Company and opening hours'], ['facts', '', 'Facts'], ['facts', 'claims', 'Claims'], ['blueprints', '', 'Blueprints']],
        'features' => [['extensions', '', 'Features'], ['addons', '', 'Add-ons']],
        'claude' => [['claude_settings', '', 'Claude settings'], ['requests', '', 'Ask Claude'], ['schedules', '', 'Scheduled runs'], ['notebook', '', 'Notebook'], ['changelog', 'sessions', 'Claude sessions']],
        'newsletter' => [['newsletters', '', 'Newsletters'], ['subscribers', '', 'Subscribers']], // 3.25
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
        // the current tab: the same module and action, or the module's list when no tab has the action
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
        $html = '<nav class="zalozky zalozky-hub" aria-label="' . e(t('Sections')) . '">';
        foreach ($tabs as $i => [$module, $tabAction, $label]) {
            $html .= '<a href="' . e($url('admin.php?module=' . $module . ($tabAction !== '' ? '&action=' . $tabAction : ''))) . '"' . ($i === $current ? ' class="aktivni" aria-current="page"' : '') . '>' . e(t($label)) . '</a>';
        }

        return $html . '</nav>';
    }
}
