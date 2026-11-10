<?php

declare(strict_types=1);

/* ---------- 3.3.4: OAuth for the Claude connection (N13, N14, N20, N65, N66) ---------- */
check('3.3.4 N65: Claude\'s own hosts are claude.ai, claude.com and their subdomains, and loopback for Claude Code; look-alikes are not', array_map(Kaleta\Front\OAuth::isClaudeHost(...),
    ['claude.ai', 'mcp.claude.ai', 'CLAUDE.COM.', 'x.claude.com', 'localhost', '127.0.0.1', '[::1]', 'claude.ai.evil.example', 'evilclaude.ai', 'claude.ai-login.example', 'evil.example', '']),
    [true, true, true, true, true, true, true, false, false, false, false, false]);
check('3.3.4 N65: OAuth::host reads the host of a redirect_uri, IPv6 loopback in brackets', array_map(Kaleta\Front\OAuth::host(...),
    ['https://claude.ai/api/mcp/auth_callback', 'http://[::1]:33418/callback', 'https://Claude.AI.evil.example/cb', 'nonsense']), ['claude.ai', '[::1]', 'claude.ai.evil.example', '']);
$oauthView = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$consentFor = fn (bool $claude, string $selected): string => $oauthView->render('admin/connection-access', ['role' => 'Administrator', 'selected' => $selected, 'claude' => $claude]);
$foreignAccess = $consentFor(false, 'drafts');
$claudeAccess = $consentFor(true, 'full');
check('3.3.4 N65: for an application outside Claude\'s hosts the access texts speak of the application, never of Claude; the preselection is the one given', [
    str_contains($foreignAccess, 'Claude'), (bool) preg_match('/value="drafts" checked/', $foreignAccess), (bool) preg_match('/value="full" checked/', $foreignAccess),
    str_contains($claudeAccess, 'Claude'), (bool) preg_match('/value="full" checked/', $claudeAccess),
    // My account (personal tokens) renders it without the flag: as before
    str_contains($oauthView->render('admin/connection-access', ['role' => 'Administrator', 'selected' => 'full']), 'Claude')],
    [false, true, false, true, true, true]);
$kernelSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
check('3.3.4 N65, 3.11 UX310-2: drafts only is preselected for every new connection (Claude too); the consent view shows the host, the warning and the new mark', [
    str_contains($kernelSource, "'selected' => 'drafts',"),
    array_map(fn (string $needle): bool => str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/oauth.php'), $needle),
        ['class="oauth-navrat"', "if (!\$claudeHost)", 'hlaska-varovani', "if (!\$approved)", 'name="request" value="<?= e($nonce) ?>"'])],
    [true, [true, true, true, true, true]]);
check('3.3.4 N65: the setting claude_apps_only is off by default, a checkbox in Claude settings, never over MCP or in an export; its texts are translated', [
    Kaleta\Core\Settings::DEFAULTS['claude_apps_only'], str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/claude.php'), 'name="claude_apps_only"'),
    (bool) preg_match((new ReflectionClassConstant(Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue(), 'claude_apps_only'), in_array('claude_apps_only', Kaleta\Core\SiteExport::SETTINGS, true),
    array_values(array_filter(['Only allow Claude’s own apps to connect', 'What may the application do?', 'new, not verified', 'After you allow it, the application returns to',
        '%s is not an address of Claude’s own apps. Allow access only if you started this connection yourself and know this application – if a message or someone else sent you here, choose Deny.'],
        fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k])))],
    ['0', true, false, false, []]);
$successor = new ReflectionMethod(Kaleta\Front\OAuth::class, 'successor');
$n13Token = 'kaleta_or_' . bin2hex(random_bytes(24));
$n13Salt = bin2hex(random_bytes(32));
$n13Pair = $successor->invoke(null, $n13Token, $n13Salt);
check('3.3.4 N13: the pair a refresh token rotates into is the same for the same token and salt, different with another salt, and in the bearer format /mcp accepts', [
    $n13Pair === $successor->invoke(null, $n13Token, $n13Salt), $n13Pair === $successor->invoke(null, $n13Token, bin2hex(random_bytes(32))),
    (bool) preg_match('/^kaleta_oa_[a-f0-9]{48}$/', $n13Pair[0]), (bool) preg_match('/^kaleta_or_[a-f0-9]{48}$/', $n13Pair[1]), $n13Pair[0] !== $n13Pair[1]],
    [true, false, true, true, true]);
$oauthSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/OAuth.php');
check('3.3.4 N13/N20/N66: redemption needs the DELETE of one row, the verifier has 43–128 characters, registrations count the visitor by /64, the daily job removes unused registrations', [
    str_contains($oauthSource, "\$db->delete('oauth_kody', ['otisk' => \$code['otisk']]) === 1"), str_contains($oauthSource, "['idt' => (int) \$refresh['idt'], 'druh' => 'obnova']) !== 1"),
    str_contains($oauthSource, "preg_match('/^[A-Za-z0-9._~-]{43,128}\$/D', \$verifier)"), str_contains($oauthSource, 'Firewall::visitorKey($r, $this->app->settings())'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Scheduler.php'), 'OAuth::purgeUnusedClients('), Kaleta\Core\Scheduler::JOBS['security'][0],
    isset(Kaleta\Core\Events::TYPES['security.token_reuse']), Kaleta\Front\OAuth::REFRESH_GRACE],
    [true, true, true, true, true, 86400, true, 30]);
$schemaSql = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
$migration76 = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0076-oauth-rotation.sql');
check('3.3.4: migration 0076 adds approved (every client from before counts as approved) and ka_oauth_rotated, as the schema does', [
    KALETA_DB_VERSION >= 76, str_contains($migration76, 'ADD COLUMN approved DATETIME NULL AFTER vytvoren'), str_contains($migration76, 'SET approved = vytvoren'),
    str_contains($schemaSql, 'approved     DATETIME     NULL'), substr_count($schemaSql . $migration76, 'CREATE TABLE ka_oauth_rotated')],
    [true, true, true, true, 2]);
