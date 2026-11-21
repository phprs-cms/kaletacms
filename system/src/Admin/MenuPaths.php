<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * Menu paths as links. Messages, the system health page and field hints say "Nastavení → Zálohy a aktualizace"
 * (Settings → Backups and updates) - instead of the user looking for the path in the menu, it becomes a link. The texts
 * stay plain sentences (and plain dictionary keys); the link is added only when rendering, in the language the text is
 * currently shown in.
 */
final class MenuPaths
{
    /**
     * Known paths: path parts as they appear in the menu (the English source texts = dictionary keys) => [module required for access, URL query].
     * Multi-word paths take precedence over shorter ones (sorted by length).
     *
     * @var list<array{0: list<string>, 1: string, 2: string}>
     */
    private const array PATHS = [
        [['Settings', 'Backups and updates'], 'settings', 'module=settings&tab=backups'],
        [['Settings', 'Privacy and cookies', 'How long data is kept'], 'settings', 'module=settings&tab=cookies#retention'], // 3.48 B (U40)
        [['Settings', 'Privacy and cookies'], 'settings', 'module=settings&tab=cookies'],
        [['Settings', 'Settings of sections'], 'settings', 'module=settings&tab=sections'], // 3.48 B (U70)
        [['Settings', 'SEO and GEO'], 'settings', 'module=settings&tab=seo'],
        [['Settings', 'General'], 'settings', 'module=settings&tab=general'],
        // 3.23 (C12, C8): the tabs that left General, and the console tab under its new name
        [['Settings', 'Languages'], 'settings', 'module=settings&tab=languages'],
        [['Settings', 'Security'], 'settings', 'module=settings&tab=security'],
        [['Settings', 'Firewall'], 'settings', 'module=settings&tab=firewall'],
        [['Settings', 'Agency'], 'settings', 'module=settings&tab=agency'],
        [['Settings', 'Analytics'], 'settings', 'module=settings&tab=analytics'],
        [['Settings', 'Mail'], 'settings', 'module=settings&tab=mail'],
        [['Settings', 'Reports'], 'settings', 'module=settings&tab=reports'], // 3.16
        [['Appearance', 'Site appearance'], 'appearance', 'module=appearance'],
        [['Appearance', 'Menu'], 'menu', 'module=menu'],
        [['Administration', 'Integrations'], 'connectors', 'module=connectors'], // 3.19.2 (C3): one name for the screen, as a link
        [['Integrations'], 'connectors', 'module=connectors'],
        [['Features'], 'extensions', 'module=extensions'], // 3.19.2 (C7)
        [['Backups and updates'], 'settings', 'module=settings&tab=backups'],
        [['News', 'Trash'], 'news', 'module=news&status=kos'],
        [['Site appearance'], 'appearance', 'module=appearance'],
        [['System status'], 'status', 'module=status'],
        // 3.2: the screens that left Settings
        [['Business details'], 'business', 'module=business'],
        [['Claude settings', 'Guardrails for Claude'], 'claude_settings', 'module=claude_settings'],
        [['Claude settings'], 'claude_settings', 'module=claude_settings'],
        [['My account'], '', 'action=account'],
        // 3.20 (C4): the screens the hints of the modules name - Bookings, Pages, Enquiries, the audit, the fleet
        [['Enquiries', 'Personal data request'], 'enquiries', 'module=enquiries&action=personal'],
        [['Enquiries', 'settings'], 'enquiries', 'module=enquiries&action=settings'],
        [['Enquiries'], 'enquiries', 'module=enquiries'],
        [['Workspace', 'Bookings'], 'bookings', 'module=bookings'], // 3.49 B: the absence flash
        [['Pages'], 'pages', 'module=pages'],
        [['Users', 'Roles'], 'roles', 'module=roles'],
        [['Users', 'Departments'], 'users', 'module=users&action=departments'], // 3.42
        [['Settings', 'Reporting to a console'], 'settings', 'module=settings&tab=console'],
        [['Settings', 'Webhooks'], 'settings', 'module=settings&tab=webhooks'],
        [['Scheduled runs'], 'schedules', 'module=schedules'],
        [['Notebook'], 'notebook', 'module=notebook'],
        // 3.23.1 (C4, N-C5, N-C6): the last plain paths – Broken links under Site care, the campaigns, the parts of My account
        [['Site care', 'Broken links'], 'broken_links', 'module=broken_links'],
        // 3.48 (decision 6): the tabs of the two hubs the menu names Sales and Addresses (never the bare word – it is a common one)
        [['Sales', 'Internal prices'], 'quotes', 'module=quotes&action=prices'],
        [['Sales', 'Quotes'], 'quotes', 'module=quotes'],
        [['Sales', 'Pipeline'], 'pipeline', 'module=pipeline'],
        [['Site care', 'Addresses', 'Broken links'], 'broken_links', 'module=broken_links'], // 3.48 review: the place as an administrator sees it
        [['Addresses', 'Broken links'], 'broken_links', 'module=broken_links'],
        [['Addresses', 'Redirects'], 'redirects', 'module=redirects'],
        [['Statistics', 'Campaigns'], 'stats', 'module=stats'],
        [['My account', 'Claude connection'], '', 'action=account&tab=claude'], // 3.47 (U68): the tabs of My account
        [['My account', 'My e-mail signature'], '', 'action=account&tab=signature'],
        [['My account', 'Org chart'], '', 'action=account&tab=colleagues&view=departments'], // 3.42
    ];

    /**
     * Returns the text prepared for HTML (escaped) with known paths turned into links.
     *
     * @param string $adminUrl URL of admin.php (e.g. $app->url('admin.php'))
     * @param list<string> $modules identifiers of modules the signed-in user can access - nothing else is linked
     */
    public static function links(string $adminUrl, string $text, array $modules): string
    {
        $html = e($text);
        $replacements = [];
        foreach (self::PATHS as [$parts, $module, $query]) {
            if ($module !== '' && !in_array($module, $modules, true)) {
                continue;
            }
            $phrase = e(implode(' → ', array_map(static fn (string $c): string => t($c), $parts)));
            $replacements[$phrase] = '<a href="' . e($adminUrl . '?' . $query) . '">' . $phrase . '</a>';
        }
        uksort($replacements, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        // in one pass: the longer phrase wins and an already inserted link is not rewritten again
        $pattern = '/' . implode('|', array_map(static fn (string $f): string => preg_quote($f, '/'), array_keys($replacements))) . '/u';

        return $replacements === [] ? $html : (string) preg_replace_callback($pattern, static fn (array $m): string => $replacements[$m[0]], $html);
    }
}
