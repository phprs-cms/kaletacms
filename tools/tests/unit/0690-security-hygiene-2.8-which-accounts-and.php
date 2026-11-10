<?php

declare(strict_types=1);

/* ---------- security hygiene (2.8): which accounts and connections count as unused, whom the automatic suspension never blocks ---------- */
$hygieneNow = new DateTimeImmutable('2026-10-02 12:00:00');
$account = fn (int $idu, int $admin, ?string $signIn, ?string $confirmed = null, ?string $claude = null, int $blocked = 0): array =>
    ['idu' => $idu, 'user' => 'u' . $idu, 'jmeno' => '', 'admin' => $admin, 'blokovat' => $blocked, 'posledni_login' => $signIn, 'potvrzeno' => $confirmed, 'pouzit' => $claude];
$hygieneAccounts = [
    $account(1, 2, '2026-10-01 10:00:00'),                                 // the active administrator
    $account(2, 2, '2026-06-01 10:00:00'),                                 // an administrator unused for 123 days
    $account(3, 1, '2026-07-04 11:59:59'),                                 // one second over 90 days
    $account(4, 1, '2026-07-04 12:00:00'),                                 // exactly 90 days – still fine
    $account(5, 0, null, '2026-05-01 00:00:00'),                           // never signed in, created long ago
    $account(6, 0, null, '2026-09-20 00:00:00'),                           // invited recently, not signed in yet
    $account(7, 1, '2026-01-01 00:00:00', '2026-02-01 00:00:00', '2026-09-30 08:00:00'), // old sign-in, but Claude used the account
    $account(8, 1, '2026-01-01 00:00:00', null, null, 1),                  // already blocked
    $account(9, 1, null),                                                   // nothing is known – never treated as unused
];
$hygieneUnused = Kaleta\Core\SecurityHygiene::unusedAccounts($hygieneAccounts, $hygieneNow);
check('Hygiene: unused accounts by sign-in, confirmation and Claude use, over 90 days only', array_column($hygieneUnused, 'idu'), [2, 3, 5]);
check('Hygiene: the last activity is the latest of the three moments', Kaleta\Core\SecurityHygiene::lastActivity($hygieneAccounts[6]), '2026-09-30 08:00:00');
check('Hygiene: an account with no record is unknown, not unused', Kaleta\Core\SecurityHygiene::lastActivity($hygieneAccounts[8]), null);
check('Hygiene: the signed-in user is never blocked', array_column(Kaleta\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 3), 'idu'), [2, 5]);
check('Hygiene: an unused administrator is blocked while another administrator stays active', array_column(Kaleta\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 0), 'idu'), [2, 3, 5]);
$hygieneAllOld = $hygieneAccounts;
$hygieneAllOld[0] = $account(1, 2, '2026-06-15 10:00:00'); // now every administrator is unused: the most recently active one stays
$hygieneUnusedAll = Kaleta\Core\SecurityHygiene::unusedAccounts($hygieneAllOld, $hygieneNow);
check('Hygiene: every administrator unused – the last active one is kept', [array_column($hygieneUnusedAll, 'idu'), array_column(Kaleta\Core\SecurityHygiene::blockable($hygieneUnusedAll, $hygieneAllOld, 0), 'idu')], [[1, 2, 3, 5], [2, 3, 5]]);
$hygieneOnlyAdmin = [$account(1, 2, '2026-01-01 00:00:00'), $account(2, 0, '2026-01-01 00:00:00')];
check('Hygiene: the only administrator is never blocked', array_column(Kaleta\Core\SecurityHygiene::blockable(Kaleta\Core\SecurityHygiene::unusedAccounts($hygieneOnlyAdmin, $hygieneNow), $hygieneOnlyAdmin, 0), 'idu'), [2]);
$hygieneConnections = [
    ['kind' => 'token', 'id' => 1, 'idu' => 1, 'user' => 'a', 'name' => 'laptop', 'last' => '2026-09-30 00:00:00', 'expiry' => null],
    ['kind' => 'token', 'id' => 2, 'idu' => 1, 'user' => 'a', 'name' => 'old', 'last' => '2026-08-03 11:59:59', 'expiry' => null],
    ['kind' => 'app', 'id' => 'abc', 'idu' => 2, 'user' => 'b', 'name' => 'Claude', 'last' => '2026-08-01 00:00:00', 'expiry' => '2026-10-20 00:00:00'],
    ['kind' => 'app', 'id' => 'def', 'idu' => 2, 'user' => 'b', 'name' => 'Claude', 'last' => '2026-08-03 12:00:00', 'expiry' => null],
];
check('Hygiene: connections unused for over 60 days', array_map(fn (array $c): string => $c['kind'] . ':' . $c['id'], Kaleta\Core\SecurityHygiene::unusedConnections($hygieneConnections, $hygieneNow)), ['token:2', 'app:abc']);
check('Hygiene: days ago for messages', Kaleta\Core\SecurityHygiene::daysAgo('2026-06-01 10:00:00', $hygieneNow), 123);
check('Hygiene: thresholds are constants', [Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS, Kaleta\Core\SecurityHygiene::CONNECTION_DAYS], [90, 60]);

