<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * The groups of the left menu (3.25): which items a person sees, in which group, and whether the menu is short enough to
 * be a flat list. The layout (views/admin/layout.php) draws what this returns; the unit tests check the shape for an
 * administrator with every feature on (groups contiguous, at most MAX_ITEMS items each, the order of ORDER).
 *
 *  - an item = a module the person may open that is not hidden under its parent (Categories under News, Subscribers under
 *    the Newsletter hub…); My work is not in a group – the layout puts it next to the Dashboard
 *  - each group is a collapsible <details>; the one with the current screen is always open (image/admin.js remembers the
 *    others per browser)
 *  - with FLAT_LIMIT items or fewer in all (Dashboard and My work counted) the menu has no group headers at all
 */
final class MenuGroups
{
    /** The groups in menu order (Kernel::MODULES follows it); the English labels are the dictionary keys. */
    public const array ORDER = ['Content', 'Workspace', 'Appearance', 'Claude', 'Site care', 'Administration'];

    /** A group never holds more items than this – related screens become tabs of a hub instead. */
    public const int MAX_ITEMS = 7;

    /** A person with this many items or fewer (Dashboard and My work included) gets a flat list without group headers. */
    public const int FLAT_LIMIT = 8;

    /**
     * The items of the menu by group, in the order of the modules.
     *
     * @param array<string, class-string<Module>> $modules the modules the person may open (Kernel::modules())
     * @return list<array{group: string, items: list<string>}> consecutive runs of one group; module idents
     */
    public static function groups(array $modules): array
    {
        $groups = [];
        foreach ($modules as $ident => $class) {
            if ($ident === 'my_work' || ($class::PARENT !== '' && isset($modules[$class::PARENT]))) {
                continue;
            }
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['group'] === $class::GROUP) {
                $groups[$last]['items'][] = $ident;
            } else {
                $groups[] = ['group' => $class::GROUP, 'items' => [$ident]];
            }
        }

        return $groups;
    }

    /**
     * How many items the person sees: the Dashboard, My work (when they have it) and the items of the groups.
     *
     * @param array<string, class-string<Module>> $modules
     */
    public static function count(array $modules): int
    {
        return 1 + (isset($modules['my_work']) ? 1 : 0) + array_sum(array_map(fn (array $g): int => count($g['items']), self::groups($modules)));
    }

    /**
     * Whether the menu is a flat list without group headers.
     *
     * @param array<string, class-string<Module>> $modules
     */
    public static function flat(array $modules): bool
    {
        return self::count($modules) <= self::FLAT_LIMIT;
    }

    /** The group's key for the browser's memory of open groups and for data attributes: "Site care" → "site-care". */
    public static function key(string $group): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($group)), '-');
    }
}
