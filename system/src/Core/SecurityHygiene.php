<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * Security hygiene that runs itself (2.8): a daily look at accounts and access.
 *
 *  - administrators who sign in without two-step sign-in or a passkey;
 *  - accounts nobody has used for ACCOUNT_DAYS – the last completed sign-in, the last use of a Claude connection of the
 *    account, or the moment an administrator created or confirmed the account (ka_uzivatele.potvrzeno), whichever is latest;
 *  - Claude connections (personal tokens from My account and applications connected via OAuth) nobody has used for
 *    CONNECTION_DAYS, and personal tokens that never expire.
 *
 * findings() only reads and feeds System status (Core\Health) and the site audit (Core\Audit). run() is called once a day
 * by the scheduler: when the administrator switched the automatic suspension on (setting auto_suspend), it blocks the
 * unused accounts (never the last active administrator, never the signed-in user) and revokes the unused connections,
 * and writes every action to the change log. A blocked account is reactivated in Users; a revoked connection has to be
 * connected again. Nothing happens while the setting is off, and nothing ever happens in the public demo.
 */
final class SecurityHygiene
{
    /** An account with no sign-in, no Claude activity and no confirmation by an administrator for this many days is unused. */
    public const int ACCOUNT_DAYS = 90;

    /** A Claude connection not used for this many days is unused (one never used counts from its creation). */
    public const int CONNECTION_DAYS = 60;

    /** Values of the setting auto_suspend (a comma-separated list). */
    public const string SUSPEND_ACCOUNTS = 'ucty';
    public const string SUSPEND_CONNECTIONS = 'napojeni';

    /** @return list<string> the choices of the automatic suspension that are switched on */
    public static function autoSuspend(Settings $settings): array
    {
        return array_values(array_intersect(explode(',', $settings->get('auto_suspend')), [self::SUSPEND_ACCOUNTS, self::SUSPEND_CONNECTIONS]));
    }

    /**
     * What deserves attention, read only.
     *
     * @return array{two_step: list<array<string, mixed>>, unused_accounts: list<array<string, mixed>>, unused_connections: list<array<string, mixed>>, no_expiry: list<array<string, mixed>>}
     */
    public static function findings(App $app): array
    {
        $db = $app->db();
        $now = new \DateTimeImmutable();
        $accounts = self::accounts($db);
        $connections = self::connections($db);

        return [
            'two_step' => array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['admin'] === Auth::ADMIN && !$a['blokovat'] && (string) $a['totp_tajemstvi'] === '' && (int) $a['klice'] === 0)),
            'unused_accounts' => self::unusedAccounts($accounts, $now),
            'unused_connections' => self::unusedConnections($connections, $now),
            'no_expiry' => array_values(array_filter($connections, static fn (array $c): bool => $c['kind'] === 'token' && $c['expiry'] === null)),
        ];
    }

    /**
     * The daily run: applies the automatic suspension when it is switched on. Returns what it did (display names).
     *
     * @return array{blocked: list<string>, revoked: list<string>}
     */
    public static function run(App $app): array
    {
        $done = ['blocked' => [], 'revoked' => []];
        $choices = self::autoSuspend($app->settings());
        if ($choices === [] || Demo::active()) {
            return $done;
        }
        $db = $app->db();
        $now = new \DateTimeImmutable();
        if (in_array(self::SUSPEND_ACCOUNTS, $choices, true)) {
            $accounts = self::accounts($db);
            foreach (self::blockable(self::unusedAccounts($accounts, $now), $accounts, $app->auth()->id()) as $a) {
                $db->update('uzivatele', ['blokovat' => 1, 'blokovano_automaticky' => $now->format('Y-m-d H:i:s')], ['idu' => (int) $a['idu']]);
                ChangeLog::write($app, 'users', 'auto_block', sprintf('%s – not used for %d days (last activity %s)', self::displayName($a), self::ACCOUNT_DAYS, (string) $a['last']));
                Events::record($db, 'security.account_suspended', 'warning', t('An account not used for %d days was suspended (user #%d).', self::ACCOUNT_DAYS, (int) $a['idu']), ['user' => (int) $a['idu']]);
                $done['blocked'][] = self::displayName($a);
            }
        }
        if (in_array(self::SUSPEND_CONNECTIONS, $choices, true)) {
            foreach (self::unusedConnections(self::connections($db), $now) as $c) {
                if ($c['kind'] === 'token') {
                    $db->delete('api_tokeny', ['idt' => (int) $c['id']]);
                } else {
                    $db->delete('api_tokeny', ['idu' => (int) $c['idu'], 'klient' => (string) $c['id']]);
                    \Kaleta\Front\OAuth::forgetRotations($db, (int) $c['idu'], (string) $c['id']); // N318-3
                }
                ChangeLog::write($app, 'claude', 'auto_revoke', sprintf('%s (%s) – not used for %d days (last activity %s)', (string) $c['name'], (string) $c['user'], self::CONNECTION_DAYS, (string) $c['last']));
                Events::record($db, 'security.connection_revoked', 'warning', t('A Claude connection not used for %d days was revoked (user #%d).', self::CONNECTION_DAYS, (int) $c['idu']), ['user' => (int) $c['idu'], 'kind' => (string) $c['kind']]);
                $done['revoked'][] = $c['name'] . ' (' . $c['user'] . ')';
            }
        }

        return $done;
    }

    /* ---------- selection (pure, unit-tested) ---------- */

    /**
     * The moment the account was last known to be wanted: the last completed sign-in, the last use of one of its Claude
     * connections, or the creation / confirmation by an administrator. Null = nothing is known (an account from before
     * the record existed) – such an account is never treated as unused.
     *
     * @param array<string, mixed> $account row of ka_uzivatele with pouzit = MAX(ka_api_tokeny.pouzit) of the account
     */
    public static function lastActivity(array $account): ?string
    {
        $moments = array_filter([$account['posledni_login'] ?? null, $account['potvrzeno'] ?? null, $account['pouzit'] ?? null], static fn (mixed $m): bool => is_string($m) && $m !== '');

        return $moments === [] ? null : max($moments);
    }

    /**
     * Accounts that are not blocked and have not been used for ACCOUNT_DAYS, each with 'last' = the last activity.
     *
     * @param list<array<string, mixed>> $accounts see lastActivity()
     * @return list<array<string, mixed>>
     */
    public static function unusedAccounts(array $accounts, \DateTimeImmutable $now): array
    {
        $limit = $now->modify('-' . self::ACCOUNT_DAYS . ' days')->format('Y-m-d H:i:s');
        $out = [];
        foreach ($accounts as $a) {
            $last = self::lastActivity($a);
            if (!$a['blokovat'] && $last !== null && $last < $limit) {
                $out[] = ['last' => $last] + $a;
            }
        }

        return $out;
    }

    /**
     * Which of the unused accounts the automatic suspension may block: never the signed-in user, and never the last active
     * administrator – when blocking them all would leave the site without one, the most recently active administrator stays.
     *
     * @param list<array<string, mixed>> $unused result of unusedAccounts()
     * @param list<array<string, mixed>> $accounts all accounts (admin, blokovat)
     * @param int $currentUser id of the signed-in user, 0 = nobody (the scheduler)
     * @return list<array<string, mixed>>
     */
    public static function blockable(array $unused, array $accounts, int $currentUser): array
    {
        $unused = array_values(array_filter($unused, static fn (array $a): bool => (int) $a['idu'] !== $currentUser));
        $candidates = array_map(intval(...), array_column($unused, 'idu'));
        $remainingAdmins = array_filter($accounts, static fn (array $a): bool => (int) $a['admin'] === Auth::ADMIN && !$a['blokovat'] && !in_array((int) $a['idu'], $candidates, true));
        if ($remainingAdmins === []) {
            $keep = null;
            foreach ($unused as $a) {
                if ((int) $a['admin'] === Auth::ADMIN && ($keep === null || (string) $a['last'] > (string) $keep['last'])) {
                    $keep = $a;
                }
            }
            if ($keep !== null) {
                $unused = array_values(array_filter($unused, static fn (array $a): bool => (int) $a['idu'] !== (int) $keep['idu']));
            }
        }

        return $unused;
    }

    /**
     * Claude connections not used for CONNECTION_DAYS (a connection never used counts from its creation).
     *
     * @param list<array<string, mixed>> $connections see connections(): kind token | app, id, idu, user, name, last, expiry
     * @return list<array<string, mixed>>
     */
    public static function unusedConnections(array $connections, \DateTimeImmutable $now): array
    {
        $limit = $now->modify('-' . self::CONNECTION_DAYS . ' days')->format('Y-m-d H:i:s');

        return array_values(array_filter($connections, static fn (array $c): bool => is_string($c['last']) && $c['last'] < $limit));
    }

    /* ---------- reading ---------- */

    /**
     * All accounts with what the checks need: the last use of their Claude connections and the number of passkeys.
     *
     * @return list<array<string, mixed>>
     */
    public static function accounts(Db $db): array
    {
        return $db->all('SELECT u.idu, u.user, u.jmeno, u.admin, u.blokovat, u.blokovano_automaticky, u.posledni_login, u.potvrzeno, u.totp_tajemstvi,
            (SELECT MAX(t.pouzit) FROM {api_tokeny} t WHERE t.idu = u.idu) AS pouzit, (SELECT COUNT(*) FROM {uzivatele_klice} k WHERE k.idu = u.idu) AS klice
            FROM {uzivatele} u ORDER BY u.user');
    }

    /**
     * Live Claude connections: every personal token (kind token, id = idt) and every application connected via OAuth that
     * still has a valid token (kind app, id = client_id), one item per application and account. 'last' = the last use, or
     * the creation when it was never used; 'expiry' = when the connection ends by itself (null = never).
     *
     * @return list<array<string, mixed>>
     */
    public static function connections(Db $db): array
    {
        $out = [];
        $apps = [];
        $now = date('Y-m-d H:i:s');
        $rows = $db->all('SELECT t.idt, t.idu, t.nazev, t.klient, t.druh, t.access, t.expirace, t.vytvoren, t.pouzit, u.user, u.jmeno FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu ORDER BY t.idt');
        foreach ($rows as $r) {
            $user = self::displayName($r);
            if ($r['klient'] === null) {
                if ($r['druh'] !== 'token') {
                    continue;
                }
                $out[] = ['kind' => 'token', 'id' => (int) $r['idt'], 'idu' => (int) $r['idu'], 'user' => $user, 'name' => (string) $r['nazev'], 'access' => (string) $r['access'],
                    'last' => (string) ($r['pouzit'] ?? $r['vytvoren']), 'used' => $r['pouzit'] !== null, 'expiry' => $r['expirace'], 'created' => (string) $r['vytvoren']];
                continue;
            }
            $key = $r['idu'] . '|' . $r['klient'];
            $app = $apps[$key] ?? ['kind' => 'app', 'id' => (string) $r['klient'], 'idu' => (int) $r['idu'], 'user' => $user, 'name' => (string) $r['nazev'], 'access' => (string) $r['access'],
                'last' => null, 'used' => false, 'expiry' => null, 'created' => (string) $r['vytvoren'], 'alive' => false];
            $app['alive'] = $app['alive'] || $r['expirace'] === null || (string) $r['expirace'] > $now;
            $app['created'] = min($app['created'], (string) $r['vytvoren']);
            if ($r['pouzit'] !== null) {
                $app['used'] = true;
                $app['last'] = $app['last'] === null ? (string) $r['pouzit'] : max((string) $app['last'], (string) $r['pouzit']);
            }
            if ($r['druh'] === 'obnova' && $r['expirace'] !== null) {
                $app['expiry'] = $app['expiry'] === null ? (string) $r['expirace'] : max((string) $app['expiry'], (string) $r['expirace']);
            }
            $apps[$key] = $app;
        }
        foreach ($apps as $app) {
            if (!$app['alive']) {
                continue; // every token of the application has expired – the connection is over, the rows only wait for the cleanup
            }
            $app['last'] ??= $app['created'];
            unset($app['alive']);
            $out[] = $app;
        }

        return $out;
    }

    /** @param array<string, mixed> $account */
    public static function displayName(array $account): string
    {
        return (string) ($account['jmeno'] ?? '') !== '' ? (string) $account['jmeno'] : (string) ($account['user'] ?? '');
    }

    /** How many whole days ago a moment was (for messages). */
    public static function daysAgo(string $moment, ?\DateTimeImmutable $now = null): int
    {
        return max(0, (int) (new \DateTimeImmutable($moment))->diff($now ?? new \DateTimeImmutable())->days);
    }
}
