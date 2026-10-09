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
     * Known paths: path parts as they appear in the menu (Czech = dictionary keys) => [module required for access, URL query].
     * Multi-word paths take precedence over shorter ones (sorted by length).
     *
     * @var list<array{0: list<string>, 1: string, 2: string}>
     */
    private const array PATHS = [
        [['Nastavení', 'Backups and updates'], 'settings', 'module=settings&tab=backups'],
        [['Nastavení', 'Privacy and cookies'], 'settings', 'module=settings&tab=cookies'],
        [['Nastavení', 'SEO and GEO'], 'settings', 'module=settings&tab=seo'],
        [['Nastavení', 'General'], 'settings', 'module=settings&tab=general'],
        [['Nastavení', 'Analytics'], 'settings', 'module=settings&tab=analytics'],
        [['Nastavení', 'Mail'], 'settings', 'module=settings&tab=mail'],
        [['Appearance', 'Site appearance'], 'appearance', 'module=appearance'],
        [['Appearance', 'Menu'], 'menu', 'module=menu'],
        [['Backups and updates'], 'settings', 'module=settings&tab=backups'],
        [['Novinky', 'Trash'], 'news', 'module=news&status=kos'],
        [['Site appearance'], 'appearance', 'module=appearance'],
        [['System status'], 'status', 'module=status'],
        // 3.2: the screens that left Settings
        [['Business details'], 'business', 'module=business'],
        [['Claude settings', 'Guardrails for Claude'], 'claude_settings', 'module=claude_settings'],
        [['Claude settings'], 'claude_settings', 'module=claude_settings'],
        [['My account'], '', 'action=account'],
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
