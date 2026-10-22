<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * Links from each part of the administration to its article in the guide on kaletacms.com (2.4). The articles have the
 * same address in every language; the admin language picks the version (/guide, /cs/guide, /de/guide). The anchors
 * are the English headings – in another language the link simply opens the top of the article.
 */
final class Guide
{
    public const string BASE = 'https://kaletacms.com';

    /** Languages the guide is written in; other admin languages get the English one. */
    public const array LANGUAGES = ['en', 'cs', 'de'];

    /** module => article#anchor; '' is the Dashboard, 'account' is My account */
    public const array MODULES = [
        '' => 'first-steps#the-dashboard',
        'account' => 'claude-connect',
        'pages' => 'page-settings',
        'news' => 'news#writing',
        'categories' => 'news',
        'tags' => 'news',
        'collections' => 'collections',
        'facts' => 'company-details#business-facts',
        'business' => 'company-details', // 3.2: the Business details hub (formerly Business details)
        'status' => 'site-health', // 3.2: System status (formerly a Settings tab)
        'claude_settings' => 'claude-connect', // 3.2: Claude settings (connecting, instructions, guardrails)
        'blueprints' => 'industry-blueprints',
        'connectors' => 'connections',
        'whistleblowing' => 'privacy-cookies', // 2.14: until the guide has its own article on the whistleblowing channel
        'enquiries' => 'forms#enquiries',
        'bookings' => 'bookings',
        'requests' => 'claude-operator', // 2.15: requests to Claude; 3.2: the article that describes Ask Claude
        'subscribers' => 'newsletter',
        'newsletters' => 'newsletter#send-newsletters-from-kaleta',
        'media' => 'media',
        'appearance' => 'site-appearance',
        'parts' => 'site-parts',
        'menu' => 'menus',
        'components' => 'components',
        'popups' => 'popups',
        'users' => 'users-roles',
        'roles' => 'users-roles#custom-roles',
        'stats' => 'statistics',
        'redirects' => 'seo#redirects-and-404s',
        'audit' => 'seo#site-audit',
        'changelog' => 'backups-updates',
        'notebook' => 'claude-capabilities',
        'schedules' => 'claude-capabilities', // 2.17: scheduled runs – what a routine in Claude does on the site is described with its capabilities
        'transfer' => 'wordpress-import',
        'extensions' => 'extensions',
        'addons' => 'addons',
        'fleet' => 'fleet-console',
    ];

    /** Settings tab => article#anchor */
    public const array SETTINGS = [
        'general' => 'languages#how-language-versions-work',
        'company' => 'company-details',
        'seo' => 'seo#settings-seo-and-geo',
        'analytics' => 'statistics#settings-analytics',
        'cookies' => 'privacy-cookies',
        'mail' => 'email',
        'reports' => 'email',
        'webhooks' => 'forms#connecting-other-tools',
        'backups' => 'backups-updates',
        'firewall' => 'site-health#firewall',
        'console' => 'fleet-console#pair-a-site',
        'health' => 'site-health',
    ];

    /** The builder of a page, news item, collection template, site part, component or pop-up */
    public const array BUILDER = [
        'parts' => 'site-parts',
        'components' => 'components',
        'popups' => 'popups',
        'collections' => 'collection-lists',
    ];

    public static function url(string $article, string $language): string
    {
        return self::BASE . (in_array($language, self::LANGUAGES, true) && $language !== 'en' ? '/' . $language : '') . '/guide/' . $article;
    }

    /** The guide to the open screen, or null. $module '' = Dashboard, 'account' = My account. */
    public static function forScreen(string $module, string $action, string $tab, string $language): ?string
    {
        if ($action === 'builder') {
            return self::url(self::BUILDER[$module] ?? 'builder-basics', $language);
        }
        if ($module === 'settings') {
            return self::url(self::SETTINGS[$tab] ?? self::SETTINGS['general'], $language);
        }
        $article = self::MODULES[$module] ?? null;

        return $article === null ? null : self::url($article, $language);
    }
}
