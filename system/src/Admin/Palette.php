<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * The quick actions of the command palette (Ctrl/⌘ K, views/admin/layout.php): the screens and commands a person may open
 * besides the sections themselves. Each belongs to a section and appears only for whoever may open it (the module list
 * of Kernel::modules() already follows the permissions); some are for administrators only, as their screens are.
 * 3.45 (U70): the sections of 3.34–3.42 – quotes, internal prices, applications, internal files, departments, the team.
 */
final class Palette
{
    /**
     * section => list of [label, query, administrators only, POST]. A POST action ("New quote" makes an empty draft) is sent
     * by image/admin.js as a form with the form token, never as a link.
     *
     * @var array<string, list<array{0: string, 1: string, 2?: bool, 3?: bool}>>
     */
    public const array QUICK = [
        'pages' => [['New page', 'module=pages&action=new']],
        'contacts' => [['New customer', 'module=contacts&action=new'], ['Companies', 'module=contacts&action=companies']], // 3.28
        'pipeline' => [['New deal', 'module=pipeline&action=new'], ['Deals as a list', 'module=pipeline&view=list'], ['Deal stages', 'module=pipeline&action=stages', true]], // 3.31
        'quotes' => [['New quote', 'module=quotes&action=create', false, true], ['Internal prices', 'module=quotes&action=prices'], ['Price list set-up', 'module=quotes&action=setup', true]], // 3.38, 3.45: set-up
        'applications' => [['Applications board', 'module=applications&action=board'], ['Talent pool', 'module=applications&action=pool']], // 3.34, 3.40
        'internal_files' => [['New folder', 'module=internal_files&action=folder', true]], // 3.42
        'my_work' => [['Team summary', 'module=my_work&action=summary'], ['Tasks of the team', 'module=my_work&action=team', true]], // 3.24, 3.27, 3.41
        'news' => [['New news item', 'module=news&action=new'], ['Broken links', 'module=news&action=links']],
        'categories' => [['New category', 'module=categories&action=new']],
        'users' => [['New user', 'module=users&action=new'], ['Departments', 'module=users&action=departments']], // 3.42: departments
        'transfer' => [['Import from WordPress', 'module=transfer']],
        'settings' => [['Settings of sections', 'module=settings&tab=sections'], ['Retention periods', 'module=settings&tab=cookies#retention']], // 3.48 B (U70, U40)
    ];

    /** The screens of every signed-in user outside the sections: My account and its tabs (3.42: the team, absences). */
    public const array EVERYONE = [
        ['My account', 'action=account'],
        ['My absences', 'action=account&tab=absence'], // 3.47 (U68): My account in tabs
        ['Colleagues', 'action=account&tab=colleagues'],
        ['Org chart', 'action=account&tab=colleagues&view=departments'],
        ['Notifications', 'action=inbox'],
        ['Guide to the office', 'action=guide'],
    ];

    /**
     * The quick actions a person may use: [label, query, section, POST], in the order of QUICK.
     *
     * @param list<string> $modules the sections the person may open
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function quick(array $modules, bool $admin): array
    {
        $out = [];
        foreach (self::QUICK as $ident => $items) {
            if (!in_array($ident, $modules, true)) {
                continue;
            }
            foreach ($items as $item) {
                if (($item[2] ?? false) && !$admin) {
                    continue;
                }
                $out[] = [$item[0], $item[1], $ident, $item[3] ?? false];
            }
        }

        return $out;
    }
}
