<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * The quick actions of the command palette (Ctrl/⌘ K, views/admin/layout.php): the screens and commands a person may open
 * besides the sections themselves. Each belongs to a section and appears only for whoever may open it (the module list
 * of Kernel::modules() already follows the permissions); some are for administrators only, as their screens are.
 * 3.45 (U70): the sections of 3.34–3.42 – quotes, internal prices, applications, internal files, departments, the team.
 *
 * 3.52 (A4, A5): the records themselves – pages, news, collection items, media, enquiries… – are found on the server by
 * Core\RecordSearch (admin.php?action=palette_search, the same as MCP search); the palette lists them in groups.
 */
final class Palette
{
    /**
     * section => list of [label, query, who: true = administrators only, 'publish' = those who publish (Auth::canPublish,
     * 3.49 review C3), POST]. A POST action ("New quote" makes an empty draft) is sent by image/admin.js as a form with the
     * form token, never as a link.
     *
     * @var array<string, list<array{0: string, 1: string, 2?: bool|string, 3?: bool}>>
     */
    public const array QUICK = [
        'pages' => [['New page', 'module=pages&action=new']],
        'contacts' => [['New customer', 'module=contacts&action=new'], ['Companies', 'module=contacts&action=companies']], // 3.28
        'pipeline' => [['New deal', 'module=pipeline&action=new'], ['Deals as a list', 'module=pipeline&view=list'], ['Deal stages', 'module=pipeline&action=stages', true]], // 3.31
        'quotes' => [['New quote', 'module=quotes&action=create', false, true], ['Internal prices', 'module=quotes&action=prices'], ['Price list set-up', 'module=quotes&action=setup', true]], // 3.38, 3.45: set-up
        'applications' => [['Applications board', 'module=applications&action=board'], ['Talent pool', 'module=applications&action=pool']], // 3.34, 3.40
        'enquiries' => [['Saved replies', 'module=enquiries&action=saved_replies', 'publish']], // 3.49 A (administrators and editors change them)
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
     * 3.52 (A9): words a section or a settings tab is found by besides its name – searched, never shown. Palette entry
     * (its query) => keywords; the palette searches them translated and in English.
     */
    public const array KEYWORDS = [
        'module=settings&tab=mail' => 'SMTP, e-mail, mail server, sender',
        'module=settings&tab=cookies' => 'GDPR, cookies, consent, privacy',
        'module=settings&tab=backups' => 'backups, restore, updates',
        'module=settings&tab=security' => 'two-factor sign-in, 2FA, passwords',
        'module=settings&tab=seo' => 'Google, search engines, sitemap, robots.txt',
        'module=settings&tab=analytics' => 'Google Analytics, measuring, tracking code',
        'module=settings&tab=company' => 'company details, address, opening hours, registration number',
        'module=appearance' => 'logo, colours, fonts, design system',
        'module=redirects' => '301, 404, old addresses',
        'module=users' => 'accounts, permissions, roles',
        'action=account' => 'password, two-factor sign-in, 2FA, passkey, profile',
    ];

    /**
     * The quick actions a person may use: [label, query, section, POST], in the order of QUICK.
     *
     * @param list<string> $modules the sections the person may open
     * @param bool $publisher may the person publish (Auth::canPublish) – an author is not offered what then answers 403
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function quick(array $modules, bool $admin, bool $publisher = true): array
    {
        $out = [];
        foreach (self::QUICK as $ident => $items) {
            if (!in_array($ident, $modules, true)) {
                continue;
            }
            foreach ($items as $item) {
                $who = $item[2] ?? false;
                if (($who === true && !$admin) || ($who === 'publish' && !$admin && !$publisher)) {
                    continue;
                }
                $out[] = [$item[0], $item[1], $ident, $item[3] ?? false];
            }
        }

        return $out;
    }
}
