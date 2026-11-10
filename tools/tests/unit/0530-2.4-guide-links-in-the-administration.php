<?php

declare(strict_types=1);

/* ---------- 2.4: guide links in the administration ---------- */
// the articles of the guide on kaletacms.com; a new admin module or settings tab needs its article here and in Admin\Guide
$guideArticles = ['install', 'first-steps', 'extensions', 'builder-basics', 'styling-responsive', 'elements', 'page-settings', 'site-appearance', 'classes', 'components',
    'site-parts', 'popups', 'menus', 'collections', 'collection-lists', 'site-search', 'news', 'forms', 'customers', 'pipeline', 'newsletter', 'company-details', 'seo', 'languages',
    'claude-connect', 'claude-capabilities', 'ai-assistant', 'users-roles', 'wordpress-import', 'backups-updates', 'media', 'statistics', 'privacy-cookies', 'email', 'site-health', 'fleet-console', 'industry-blueprints', 'connections', 'addons', 'bookings', 'claude-operator'];
$guideTargets = [...Kaleta\Admin\Guide::MODULES, ...Kaleta\Admin\Guide::SETTINGS, ...Kaleta\Admin\Guide::BUILDER, ...Kaleta\Admin\Guide::ACCOUNT];
check('2.4: every admin module and settings tab links to an existing guide article', [
    array_values(array_diff(array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES), array_keys(Kaleta\Admin\Guide::MODULES), ['settings'])),
    array_values(array_diff(array_keys(Kaleta\Admin\Modules\Settings::TABS), array_keys(Kaleta\Admin\Guide::SETTINGS))),
    array_values(array_filter($guideTargets, fn (string $a): bool => !in_array(strtok($a, '#'), $guideArticles, true))),
], [[], [], []]);
check('2.4: guide link follows the admin language', [
    Kaleta\Admin\Guide::forScreen('settings', '', 'backups', 'cs'), Kaleta\Admin\Guide::forScreen('pages', 'builder', '', 'de'),
    Kaleta\Admin\Guide::forScreen('popups', 'builder', '', 'en'), Kaleta\Admin\Guide::forScreen('stats', '', '', 'pl'), Kaleta\Admin\Guide::forScreen('nothing', '', '', 'en'),
], ['https://kaletacms.com/cs/guide/backups-updates', 'https://kaletacms.com/de/guide/builder-basics', 'https://kaletacms.com/guide/popups', 'https://kaletacms.com/guide/statistics', null]);

