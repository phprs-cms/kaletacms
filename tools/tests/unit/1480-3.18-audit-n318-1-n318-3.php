<?php

declare(strict_types=1);

use Kaleta\Core\Migration;

/* ---------- 3.18 audit (N318-1 … N318-3) ---------- */
// N318-1: a connection counts only when its account can call – not blocked, with two-factor sign-in when the site requires it
check('3.18 N318-1 ClaudeConnection::canCall – blocked accounts never, 2FA required of everyone or of administrators narrows it', [
    Kaleta\Core\ClaudeConnection::canCall($reportSettings([])), Kaleta\Core\ClaudeConnection::canCall($reportSettings(['require_2fa' => 'vsichni'])),
    Kaleta\Core\ClaudeConnection::canCall($reportSettings(['require_2fa' => 'spravci']))],
    // 3.35: a passkey counts as the second factor (Auth::isMissingRequired2fa)
    ['u.blokovat = 0', "u.blokovat = 0 AND (u.totp_tajemstvi <> '' OR EXISTS (SELECT 1 FROM {uzivatele_klice} k2 WHERE k2.idu = u.idu))",
        "u.blokovat = 0 AND (u.admin <> 2 OR (u.totp_tajemstvi <> '' OR EXISTS (SELECT 1 FROM {uzivatele_klice} k2 WHERE k2.idu = u.idu)))"]);
// the MCP server records a call only after every refusal: the stamp is not in user() and comes after the 2FA check
$server318 = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php');
$user318 = substr($server318, (int) strpos($server318, 'private function user()'), (int) strpos($server318, 'private function served(') - (int) strpos($server318, 'private function user()'));
check('3.18 N318-1 Mcp\Server – the last use, the refresh row and claude_first_used are written after the 2FA check, not when the token is looked up', [
    str_contains($user318, 'pouzit'), str_contains($user318, 'ClaudeConnection::stamp'), str_contains($user318, 'claude_first_used'),
    (int) strpos($server318, '$this->served($user)') > (int) strpos($server318, 'isMissingRequired2fa')], [false, false, false, true]);
// N318-2: refreshes of one connection are limited – an hourly refresh, a burst and a retry pass, a loop waits
$now318 = strtotime('2026-10-20 12:00:00');
check('3.18 N318-2 OAuth::refreshWait – under 30 refreshes an hour no wait; at 30 until the oldest leaves the hour, at least a second, at most an hour', [
    Kaleta\Front\OAuth::refreshWait(0, null, $now318), Kaleta\Front\OAuth::refreshWait(1, '2026-10-20 11:05:00', $now318), Kaleta\Front\OAuth::refreshWait(29, '2026-10-20 11:01:00', $now318),
    Kaleta\Front\OAuth::refreshWait(30, '2026-10-20 11:10:00', $now318), Kaleta\Front\OAuth::refreshWait(45, '2026-10-20 11:00:00', $now318),
    Kaleta\Front\OAuth::refreshWait(30, null, $now318), Kaleta\Front\OAuth::refreshWait(30, '2026-10-20 13:30:00', $now318), Kaleta\Front\OAuth::REFRESH_LIMIT, Kaleta\Front\OAuth::REFRESH_WINDOW],
    [0, 0, 0, 600, 1, 3600, 3600, 30, 3600]);
// N318-2: the indexes of the status queries – a migration for existing sites, the same keys in schema.sql for new ones
$migration318 = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0102-claude-status-indexes.sql');
$schema318 = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
check('3.18 N318-2 migration 0102 adds the status indexes, schema.sql has them, KALETA_DB_VERSION is at least 102', [
    str_contains($migration318, 'ALTER TABLE ka_api_tokeny ADD KEY ix_tokeny_druh_expirace (druh, expirace);'), str_contains($migration318, 'ALTER TABLE ka_oauth_rotated ADD KEY ix_oauth_rotated_at (rotated_at);'),
    str_contains($schema318, 'KEY ix_tokeny_druh_expirace (druh, expirace)'), str_contains($schema318, 'KEY ix_oauth_rotated_at (rotated_at)'), KALETA_DB_VERSION >= 102, Migration::latest() === KALETA_DB_VERSION],
    [true, true, true, true, true, true]);
// N318-3: every revocation of an OAuth connection forgets its rotation records
$forget318 = [];
foreach (['src/Front/OAuth.php', 'src/Admin/Account.php', 'src/Admin/Modules/Users.php', 'src/Admin/PasswordReset.php', 'src/Core/SecurityHygiene.php'] as $file318) {
    $forget318[] = substr_count((string) file_get_contents(KALETA_SYSTEM . '/' . $file318), 'forgetRotations(');
}
check('3.18 N318-3 the rotation records go with a revoked connection: password change, disconnect, admin revoke and account delete, reset, auto-revoke', $forget318, [2, 1, 2, 1, 1]);

// every 3.18 text of the indicator, the notices, the health check and the console is in every admin language
$texts318 = [];
foreach (['src/Core/ClaudeConnection.php', 'src/Admin/Modules/Requests.php', 'views/admin/claude_chip.php', 'views/admin/claude_notice.php', 'views/admin/claude_panel.php',
    'views/admin/ask_claude.php', 'views/admin/requests/list.php', 'views/admin/fleet/reasons.php', 'views/admin/fleet/detail.php'] as $file318) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_SYSTEM . '/' . $file318), $m318);
    foreach ($m318[1] as $text318) {
        $texts318[stripslashes($text318)] = true;
    }
}
$texts318 = [...array_keys($texts318), ...array_values(Kaleta\Fleet\Inbox::WHAT), 'Claude connection', 'switched off (Features)', 'not connected', 'connected, last call %s', 'connected, no call yet',
    'connected, no call in the last %d days (last call %s)', 'a connection ends %s, connect Claude again before then', '%d request(s) wait, but Claude is not connected to the site – connect it in Claude settings',
    '%d request(s) wait, but the scheduled run that picks them up is not running (last missed or failed %s) – check the routine in Claude (Scheduled runs)', 'connected, no call in the last 7 days', 'connected', 'requests are picked up by a scheduled run', 'requests are picked up when someone asks Claude', 'the scheduled run that picks up requests is not running'];
$missing318 = [];
foreach (['cs', 'de', 'es'] as $code318) {
    $dictionary318 = require KALETA_SYSTEM . '/jazyky/admin-' . $code318 . '.php';
    $english318 = $code318 === 'cs' ? require KALETA_SYSTEM . '/jazyky/admin-en.php' : [];
    $missing318 = [...$missing318, ...array_map(static fn (string $k): string => $code318 . ': ' . $k, array_values(array_filter($texts318, static fn (string $k): bool => !isset($dictionary318[$k]) && !isset($english318[$k]))))];
}
check('3.18: the texts of the Claude connection status are translated into Czech, German and Spanish', array_slice($missing318, 0, 5), []);
