<?php

declare(strict_types=1);

/* ---------- 3.3.3: authentication, sessions, whistleblowing and page passwords ---------- */
$authSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php');
preg_match('/public function login\(.*?\n    }\n/s', $authSource, $loginSource);
check('3.3.3 (N51): sign-in checks the lock and the block before the password, verifies the dummy hash for them, and answers every failure with one text', [
    // the account's own hash is used only when the account is neither locked nor blocked
    str_contains($loginSource[0] ?? '', '$closed = $user !== null && (!empty($user[\'blokovat\']) || self::isLocked($user));')
        && str_contains($loginSource[0] ?? '', '$user !== null && !$closed ? (string) $user[\'password\'] : self::dummyHash()'),
    substr_count($loginSource[0] ?? '', 'return t('), str_contains($loginSource[0] ?? '', "t('The account is"),
    isset($adminCs[Kaleta\Core\Auth::SIGN_IN_FAILED], $adminDe[Kaleta\Core\Auth::SIGN_IN_FAILED]), str_contains(Kaleta\Core\Auth::SIGN_IN_FAILED, 'reset your password')],
    [true, 2, false, true, true]);
check('3.3.3 (N61): the typed password is checked as it is; one saved trimmed before still opens', [
    Kaleta\Core\Auth::matchingPassword(' space-around-1 ', password_hash(' space-around-1 ', PASSWORD_DEFAULT)),
    Kaleta\Core\Auth::matchingPassword(' old-trimmed-12 ', password_hash('old-trimmed-12', PASSWORD_DEFAULT)),
    Kaleta\Core\Auth::matchingPassword('space-around-1', password_hash(' space-around-1 ', PASSWORD_DEFAULT)),
    Kaleta\Core\Auth::matchingPassword('wrong-password', password_hash('right-password', PASSWORD_DEFAULT))],
    [' space-around-1 ', 'old-trimmed-12', null, null]);
$now333 = 1_800_000_000;
check('3.3.3 (N60): a sign-in ends after 8 idle hours or 24 hours in total, however often the admin keeps it alive', [
    Kaleta\Core\Auth::sessionValid($now333 - 3600, $now333 - 60, $now333), Kaleta\Core\Auth::sessionValid($now333 - 9 * 3600, $now333 - 8 * 3600, $now333),
    Kaleta\Core\Auth::sessionValid($now333 - 24 * 3600, $now333 - 60, $now333), Kaleta\Core\Auth::sessionValid($now333 - 23 * 3600, $now333 - 7 * 3600, $now333),
    Kaleta\Core\Auth::IDLE_LIMIT, Kaleta\Core\Auth::SESSION_LIMIT,
    // the tokens of Claude connections are not sessions: the MCP server signs the user in without one
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php'), '$this->app->auth()->signInAs($user);')],
    [true, false, false, true, 28800, 86400, true]);
$cloudflareSettings = $reportSettings(['firewall_proxy' => 'cloudflare']);
check('3.3.3 (N54): the sign-in, reset and MCP limits count the visitor behind Cloudflare, an IPv6 address by its /64', [
    Kaleta\Core\Firewall::visitorKey(new Kaleta\Core\Request([], [], ['REMOTE_ADDR' => '162.158.1.1', 'HTTP_CF_CONNECTING_IP' => '2001:db8:1:2:3:4:5:6']), $cloudflareSettings),
    Kaleta\Core\Firewall::visitorKey(new Kaleta\Core\Request([], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']), $cloudflareSettings),
    Kaleta\Core\Firewall::visitorKey(new Kaleta\Core\Request([], [], ['REMOTE_ADDR' => '162.158.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']), $reportSettings(['firewall_proxy' => ''])),
    array_map(fn (string $file): bool => str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/' . $file . '.php'), 'Firewall::visitorKey(') && !str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/' . $file . '.php'), "hash('sha256', 'kaleta|' . \$this->app->request->ip())"),
        ['Admin/Kernel', 'Admin/PasswordReset', 'Mcp/Server', 'Core/Whistleblowing'])],
    ['2001:db8:1:2::/64', '203.0.113.9', '162.158.1.1', [true, true, true, true]]);
check('3.3.3 (N62): the MCP wrong-token count only caps the rows it writes – it never refuses a request', [
    (bool) preg_match('/if \(\$token === null\) \{\s+if \(!\$limited\) \{\s+\$db->insert/', (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php')),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php'), 'it never refuses a request')], [true, true]);
$frontReport = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Whistleblowing.php');
check('3.3.3 (N53): the whistleblowing pages never load a third-party CAPTCHA, and the widget stays off on such a page', [
    str_contains($frontReport, 'Captcha::verify'), str_contains($frontReport, 'Captcha::widget'), str_contains($frontReport, 'Captcha::offOnThisPage()'),
    (function () use ($reportSettings): string {
        $off = new ReflectionProperty(Kaleta\Core\Captcha::class, 'off');
        $off->setValue(null, true);
        $widget = Kaleta\Core\Captcha::widget($reportSettings(['captcha_provider' => 'hcaptcha', 'captcha_site_key' => 'k', 'captcha_secret' => 's']));
        $off->setValue(null, false);

        return $widget;
    })(),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Whistleblowing.php'), 'no third-party scripts on that page – the site\'s')],
    [false, false, true, '', true]);
$wbSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Whistleblowing.php');
preg_match('/public static function acceptsReport\(.*?\n    }\n/s', $wbSource, $acceptsSource);
check('3.3.3 (N57): the hourly cap marks reports instead of refusing them, attachments over the storage cap are dropped instead of the report, and the checks run under a lock', [
    str_contains($acceptsSource[0] ?? '', 'REPORTS_PER_HOUR'), str_contains($wbSource, "'flood' => \$flood ? 1 : 0"),
    str_contains($wbSource, 'Attachments cannot be accepted right now'), str_contains($wbSource, 'GET_LOCK(?, 10)') && str_contains($wbSource, 'RELEASE_LOCK'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), 'flood           TINYINT(1) NOT NULL DEFAULT 0'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0075-whistleblowing-flood.sql'), 'ADD COLUMN flood'), KALETA_DB_VERSION >= 75,
    isset($adminCs['received during a flood'], $adminDe['received during a flood'])],
    [false, true, false, true, true, true, true, true]);
$pageLockSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/PageLock.php');
check('3.3.3 (N58): the page cap is checked only after a wrong password – the right one always opens the page', [
    strpos($pageLockSource, "'page-lock-all'") > strpos($pageLockSource, 'password_verify('), str_contains($pageLockSource, ", 'page-lock-all', self::WINDOW, false)")], [true, false]);
$mailSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Mail.php');
check('3.3.3 (N59): the reset link is queued and sent after the response; the queue claims a message before sending it', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/PasswordReset.php'), 'Mail::later('), str_contains((string) file_get_contents(KALETA_ROOT . '/admin.php'), 'Kaleta\Core\Mail::afterResponse($app);'),
    str_contains($mailSource, 'WHERE idp = ? AND pokusu = ? AND odeslano IS NULL')], [true, true, true]);
$accountSource = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Account.php');
// 3.31.2: turning two-factor sign-in on (N100) and creating a token (N101) ask for it too
check('3.3.3 (N56): a new e-mail and a new passkey need the current password; the old address hears about the change; the texts are translated', [
    // 3.35: passkey changes take a fresh confirmation – the password, or a passkey/backup-code step a few minutes ago
    substr_count($accountSource, '$this->passwordRefused(\'') + substr_count($accountSource, '$this->freshnessRefused(\''), str_contains($accountSource, 'noticeOfNewEmail($user, $email)'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/klice.js'), "soucasne: password ? password.value : ''"),
    array_values(array_filter(['Enter your current password to change the e-mail address. Nothing was saved.', 'Enter your current password to add a passkey.', 'The e-mail address of your account was changed'],
        fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k])))],
    [8, true, true, []]);

