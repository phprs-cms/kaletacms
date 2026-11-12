<?php

declare(strict_types=1);

/* ---------- 3.23 (C12, C8): Settings → General split into tabs, the console tab renamed and shown only when it matters ---------- */
use Kaleta\Admin\Modules\Settings as S323;
$fields323 = (new ReflectionClassConstant(S323::class, 'FIELDS'))->getValue();
$split323 = ['general', ...S323::SPLIT_FROM_GENERAL];
$templates323 = array_map(fn (string $tab): string => (string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/' . $tab . '.php'), array_combine($split323, $split323));
$inTemplate323 = fn (string $key, string $tpl): bool => str_contains($tpl, "'" . $key . "'") || str_contains($tpl, 'name="' . $key) || isset(S323::SOCIAL_NETWORKS[$key]) && str_contains($tpl, 'SOCIAL_NETWORKS');
$misplaced323 = [];
foreach ($split323 as $tab) {
    foreach (array_keys($fields323[$tab]) as $key) {
        // every field is in the form of its own tab and in no other of the four (a field in two forms would be saved by both)
        $where = array_keys(array_filter($templates323, fn (string $tpl): bool => $inTemplate323($key, $tpl)));
        if ($where !== [$tab]) {
            $misplaced323[$key] = $where;
        }
    }
}
check('3.23 (C12): General, Languages, Security and Agency – each field in the form of its own tab only, the keys of 3.22 all kept', [
    $misplaced323,
    array_values(array_diff(['site_name', 'site_url', 'site_description', 'site_email', 'footer_text', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin',
        'home_page', 'news_per_page', 'news_slug', 'news_title', 'share_buttons', 'social_networks', 'link_check', 'article_outline', 'related_news_auto', 'page_cache', 'maintenance',
        'maintenance_text', 'require_2fa', 'screen_mode', 'screen_seconds', 'screen_news', 'screen_hours', 'screen_clock', 'auto_suspend', 'agency_name', 'agency_url', 'agency_email',
        'agency_phone', 'agency_logo', 'time_zone', 'site_language', 'german_register', 'additional_languages', 'slugs_per_language'], ...array_values(array_map(array_keys(...), array_intersect_key($fields323, array_flip($split323)))))),
    array_keys($fields323['languages']), array_keys($fields323['security']), array_keys($fields323['agency']),
    // MCP update_settings and the import validate them as before (the type is looked up in every tab)
    [S323::verifyValue('require_2fa', 'spravci'), S323::verifyValue('agency_email', 'x'), S323::verifyValue('site_language', 'de'), S323::verifyValue('screen_mode', 'true'), S323::checkable('agency_name')],
], [[], [], ['site_language', 'german_register', 'additional_languages', 'slugs_per_language'], ['require_2fa', 'signin_passkey_only', 'auto_suspend'],
    ['agency_name', 'agency_url', 'agency_email', 'agency_phone', 'agency_logo'], ['spravci', null, 'de', '1', true]]);
check('[source-pin] 3.23 (C12): the old single General form (no marker) still saves every field it showed; the split form sends the marker; old links move on', [
    (bool) preg_match("/tab === 'general'.*!\\\$this->request->postBool\('general_split'\).*SPLIT_FROM_GENERAL/s", (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php')),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/list.php'), 'name="general_split" value="1"'),
    array_intersect_key(S323::movedFields($reportSettings(['additional_languages' => 'de', 'extensions' => 'jazyky'])), array_flip(['additional_languages', 'require_2fa', 'auto_suspend', 'agency_logo', 'nazev_webu_de', 'site_name', 'time_zone'])),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/admin.js'), "querySelector('[data-pole-jinde]')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/extensions.php'), 'module=settings&tab=languages#additional_languages'),
    Kaleta\Core\Demo::blocksAdmin('settings', 'save', 'languages', true), Kaleta\Core\Demo::blocksAdmin('settings', 'save', 'security', true),
], [true, true, ['additional_languages' => 'languages', 'require_2fa' => 'security', 'auto_suspend' => 'security', 'agency_logo' => 'agency', 'nazev_webu_de' => 'languages'], true, true, false, true]);
check('[source-pin] 3.23 (C8): "Reporting to a console" is the last tab, shown with a named agency or a pairing – never hidden while paired', [
    array_slice(array_keys(array_diff_key(S323::TABS, S323::MOVED_TABS)), -3), S323::TABS['console'],
    isset(S323::visibleTabs($reportSettings([]))['console']), isset(S323::visibleTabs($reportSettings(['agency_name' => '  ']))['console']),
    isset(S323::visibleTabs($reportSettings(['agency_name' => 'Studio Kaleta']))['console']),
    isset(S323::visibleTabs($reportSettings(['fleet_console_url' => 'https://console.example', 'fleet_site_id' => '3']))['console']),
    array_keys(array_intersect_key(S323::visibleTabs($reportSettings([])), S323::MOVED_TABS)),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php'), 'Settings::visibleTabs($app->settings())'),
], [['firewall', 'agency', 'console'], 'Reporting to a console', false, false, true, true, [], true]);
check('3.23: the hints link the new tabs in the admin language', Kaleta\Core\Language::runWith('cs', fn (): array => [
    substr_count(Kaleta\Admin\MenuPaths::links('/admin.php', t('Blocking addresses, countries and probing of the public site is in Settings → Firewall.') . ' ' . t('Whether this site reports to the agency\'s console is set in Settings → Reporting to a console.')
        . ' ' . t('Your contact is not set – the client will not see whom to ask (Settings → Agency).') . ' ' . t('Settings → Languages') . ' ' . t('Unused accounts and Claude connections are only reported – switch on the automatic suspension (Settings → Security) so that leftover access closes itself.'), ['settings']), '<a href="/admin.php?module=settings&amp;tab='),
    t('Reporting to a console'), t('Agency'),
], 'admin-'), [5, 'Hlášení do konzole', 'Agentura']);

