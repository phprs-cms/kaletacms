<?php

declare(strict_types=1);

/* ---------- 3.3.2: security release, workstream C ---------- */
$mcpSettings = (new ReflectionClassConstant(Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue();
check('[source-pin] 3.3.2 (N27): Claude cannot set GTM or Matomo (script chosen by their owner); GA4 and Plausible load from a fixed host and stay; the tool says why',
    [array_map(fn (string $key): int => preg_match($mcpSettings, $key), ['gtm_id', 'matomo_url', 'matomo_id', 'ga4_id', 'plausible_domain']),
        str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/SettingsTools.php'), "['gtm_id', 'matomo_url', 'matomo_id']"), Kaleta\Admin\Modules\Settings::verifyValue('gtm_id', 'GTM-<x>')],
    [[0, 0, 0, 1, 1], true, null]);
check('3.3.2 (N29): visitors\' personal data are never journaled for undo; content still is', array_map(Kaleta\Core\AgentJournal::journaled(...),
    ['poptavky', 'testimonial_requests', 'bookings', 'odberatele', 'posta', 'stranky', 'nastaveni']), [false, false, false, false, false, true, true]);
$settingsScreens = array_values(array_filter(Kaleta\Admin\Kernel::MODULES, fn (string $c): bool => is_a($c, Kaleta\Admin\Modules\Settings::class, true)));
check('3.3.2 (N24): the demo filters every Settings screen by its class – no backup, restore, download, pairing or firewall action through any of them; looking is allowed', [
    count($settingsScreens) >= 5,
    array_values(array_filter(array_map(fn (string $c): string => $c::IDENT, $settingsScreens), fn (string $ident): bool => !Kaleta\Core\Demo::blocksAdmin($ident, 'backup', '', true)
        || !Kaleta\Core\Demo::blocksAdmin($ident, 'download_backup', '', false) || !Kaleta\Core\Demo::blocksAdmin($ident, 'restore_backup', '', true)
        || !Kaleta\Core\Demo::blocksAdmin($ident, 'delete_backup', '', true) || !Kaleta\Core\Demo::blocksAdmin($ident, 'fleet_pair', '', true)
        || !Kaleta\Core\Demo::blocksAdmin($ident, 'firewall_unblock', '', true) || Kaleta\Core\Demo::blocksAdmin($ident, 'list', '', false))),
    Kaleta\Core\Demo::blocksAdmin('settings', 'save', 'general', true), Kaleta\Core\Demo::blocksAdmin('settings', 'save', 'mail', true), Kaleta\Core\Demo::blocksAdmin('business', 'hours_add', '', true),
    Kaleta\Core\Demo::blocksAdmin('status', 'save', '', true), Kaleta\Core\Demo::blocksAdmin('claude_settings', 'save', '', true), Kaleta\Core\Demo::blocksAdmin('pages', 'save', '', true)],
    [true, [], false, true, false, true, true, false]);
check('3.3.2 (N24): the demo saves only allow-listed settings – never script hosts, code, secrets, the policy link or maintenance', array_map(fn (array $k): bool => Kaleta\Core\Demo::blocksSetting($k[0], $k[1]),
    [['site_name', 'text'], ['company_city', 'text'], ['ga4_id', 'vzor'], ['matomo_url', 'url'], ['gtm_id', 'vzor'], ['head_code', 'kod'], ['captcha_secret', 'tajne'], ['cookies_policy_url', 'text'], ['maintenance', 'ano'], ['update_url', 'url'], ['site_email', 'email']]),
    [false, false, false, true, true, true, true, true, true, true, true]);
check('3.3.2 (N17): the privacy policy link is a path or https on saving, and a path or http(s) when printed – never javascript:, data: or //host', [
    array_map(fn (string $v): ?string => Kaleta\Admin\Modules\Settings::verifyValue('cookies_policy_url', $v), ['/privacy-policy', 'https://example.com/p', '', 'javascript:alert(1)', '//evil.example', 'http://example.com/p', 'data:text/html,x']),
    array_map(fn (string $v): string => Kaleta\Core\Privacy::policyUrl($reportSettings(['cookies_policy_url' => $v])), ['/zasady', 'http://old.example/p', 'javascript:alert(1)', 'JaVaScRiPt:x', '//evil.example', '/\\evil', ' /x '])],
    [['/privacy-policy', 'https://example.com/p', '', null, null, null, null], ['/zasady', 'http://old.example/p', '', '', '', '', '/x']]);
check('3.3.2 (N31): an empty other build target does not hide the page from the protected-pages guardrail; update_page and trash_page have no other target', [
    Kaleta\Core\Guardrails::targetPage('stavba_uloz', ['id' => 5, 'popup' => 0]), Kaleta\Core\Guardrails::targetPage('stavba_uprav', ['id' => 5, 'cast' => '', 'komponenta' => '0', 'kolekce' => null]),
    Kaleta\Core\Guardrails::targetPage('stavba_uloz', ['id' => 5, 'popup' => 3]), Kaleta\Core\Guardrails::targetPage('publikuj_stavbu', ['id' => 5, 'cast' => 'hlavicka']),
    Kaleta\Core\Guardrails::targetPage('uprav_stranku', ['id' => 5, 'popup' => 3]), Kaleta\Core\Guardrails::targetPage('smaz_stranku', ['id' => 5, 'kolekce' => 'x'])],
    [5, 5, null, null, 5, 5]);
check('3.3.2 (N32): with deleting switched off, deleting a redirect or a part variant, restoring an item version and e-mailing a testimonial request count as destructive', [
    (new ReflectionClassConstant(Kaleta\Core\Guardrails::class, 'DESTRUCTIVE_CALLS'))->getValue(),
    array_map(fn (string $tool): ?string => Kaleta\Mcp\Translator::czech($tool) ?? $tool, ['save_redirect', 'save_part_variant', 'restore_item_version', 'request_testimonial'])],
    [['uloz_presmerovani' => 'smazat', 'uloz_variantu' => 'smazat', 'restore_item_version' => '', 'request_testimonial' => 'send', 'confirm_booking' => '', 'propose_booking_times' => ''], ['uloz_presmerovani', 'uloz_variantu', 'restore_item_version', 'request_testimonial']]);
check('3.3.2 (N34): tries are counted per IPv4 address and per IPv6 /64 (an IPv4 address written as IPv6 counts as itself)', array_map(Kaleta\Core\Antispam::network(...),
    ['203.0.113.7', '2001:db8:1:2:3:4:5:6', '2001:db8:1:2:ffff::1', '::ffff:203.0.113.7', 'unknown']), ['203.0.113.7', '2001:db8:1:2::/64', '2001:db8:1:2::/64', '203.0.113.7', 'unknown']);
check('[source-pin] 3.3.2 (N34): a page password has a limit per address and one per page across all addresses', [Kaleta\Core\PageLock::ATTEMPTS, Kaleta\Core\PageLock::PAGE_ATTEMPTS > Kaleta\Core\PageLock::ATTEMPTS,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/PageLock.php'), 'Antispam::network(')], [10, true, true]);
check('[source-pin] 3.3.2 (N28, N35): the whistleblowing channel caps reports per hour, per day and attachment storage, and keeps only a short keyed bucket of an address', [
    Kaleta\Core\Whistleblowing::REPORTS_PER_HOUR, Kaleta\Core\Whistleblowing::REPORTS_PER_DAY, Kaleta\Core\Whistleblowing::MAX_STORAGE >= 512 * 1048576,
    (new ReflectionClassConstant(Kaleta\Core\Whistleblowing::class, 'BUCKET_LENGTH'))->getValue() <= 5,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Whistleblowing.php'), 'Antispam($app->db(), $app->settings()))->write')],
    [20, 5, true, true, false]);
$htaccess332 = (string) file_get_contents(KALETA_ROOT . '/.htaccess');
$router332 = (string) file_get_contents(KALETA_SYSTEM . '/dev-router.php');
preg_match("/if \\(preg_match\\('(#\\^\\/\\(system.+?#i)', \\\$path\\)\\)/", $router332, $routerRule);
check('3.3.2 (N36): .htaccess and the development router serve only extensions/<slug>/public/ and no PHP from it', [
    str_contains($htaccess332, 'RewriteRule ^extensions/(?![^/]+/public/) - [F,L,NC]'), str_contains($htaccess332, 'RewriteRule ^extensions/[^/]+/public/.+\.(php\d?|pht|phps|phtml|phar)(\.|/|$) - [F,L,NC]'),
    array_map(fn (string $path): int => preg_match($routerRule[1] ?? '/$^/', $path), ['/extensions/hello/Extension.php', '/extensions/hello/extension.json', '/extensions/README.md', '/extensions/hello/public/x.PHP',
        '/extensions/hello/public/x.pht', '/extensions/hello/public/x.PHPS', '/Extensions/hello/Extension.php', '/extensions/hello/public/app.css', '/media/a.jpg'])],
    [true, true, [1, 1, 1, 1, 1, 1, 1, 0, 0]]); // 3.3.3 (N64): .pht and .phps too, and the second rule ignores case
check('3.3.2 (N39): add-on tools may name a role or a section; without it read and draft are open, write needs an editor, destructive an administrator', [
    Kaleta\Extension\Api::TOOL_ROLES, (new ReflectionClassConstant(Kaleta\Extension\Api::class, 'DEFAULT_ROLE'))->getValue(),
    array_map(fn (ReflectionParameter $p): string => $p->getName() . ($p->isOptional() ? '?' : ''), (new ReflectionMethod(Kaleta\Extension\Api::class, 'mcpTool'))->getParameters())],
    [['author', 'editor', 'admin'], ['read' => 'author', 'draft' => 'author', 'write' => 'editor', 'destructive' => 'admin'], ['name', 'description', 'schema', 'access', 'handler', 'requires?', 'title?', 'openWorld?', 'idempotent?']]);
check('3.3.2 (N40): the installation takes the version and the security flag it was decided for', array_map(fn (ReflectionParameter $p): string => $p->getName(),
    (new ReflectionMethod(Kaleta\Core\Updater::class, 'install'))->getParameters()), ['db', 'expectedVersion', 'expectedSecurity']);
check('3.3.2 (N43): an API fetch that carries a token is not redirected from https to plain http', [
    Kaleta\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api', ['Accept: application/json', 'Authorization: Bearer x']),
    Kaleta\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api?key=abc', ['Accept: application/json']),
    Kaleta\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api', ['Accept: application/vnd.api+json']),
    Kaleta\Import\Fetch::downgradesCredentials('https://old.example/api', 'https://www.old.example/api', ['Authorization: Bearer x']),
    Kaleta\Import\Fetch::downgradesCredentials('http://old.example/api', 'http://old.example/api2', ['X-Joomla-Token: x'])],
    [true, true, false, false, false]);

