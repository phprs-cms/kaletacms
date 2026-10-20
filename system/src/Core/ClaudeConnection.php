<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Whether Claude is connected to the site (3.18). MCP is pull-only: Claude calls the site when a person uses it or a
 * scheduled run fires – the site never knows whether Claude is "online". What it does know:
 *
 *  - state: off (the extension "claude" is switched off), none (no valid connection – no unexpired OAuth refresh token and
 *    no unexpired personal token of an account that is not blocked), idle (a valid connection, but no call in ACTIVE_DAYS
 *    or never), active (a call within ACTIVE_DAYS; recent = within RECENT_HOURS);
 *  - pickup: how requests (Core\Requests) reach Claude – automatic (an active scheduled run of the task "requests" that
 *    runs: its last run was not missed or failed and it is not overdue by more than AgentSchedules::MISSED_HOURS),
 *    stalled (such a schedule exists, but it missed, failed or is overdue), manual (no such schedule – requests are seen
 *    when someone asks Claude); null while the state is off or none;
 *  - expires: the earliest end of a valid connection, expiring when it is within EXPIRY_DAYS.
 *
 * The last call is the newest ka_api_tokeny.pouzit. An OAuth access token lives an hour and its row goes after it
 * expires, so every call also stamps the connection's refresh row (stamp(), at most once a STAMP_SECONDS) and a refresh
 * carries the value over to the new pair (Front\OAuth::refresh) – before 3.18 the last call of a connection idle for
 * over an hour was lost. A refresh recorded in ka_oauth_rotated (30 days) counts as a call too: Claude refreshes its token
 * only to call the site, and it covers connections that have not called since the update to 3.18.
 */
final class ClaudeConnection
{
    /** A call within this many days makes the connection active, an older one (or none) idle. */
    public const int ACTIVE_DAYS = 7;

    /** A call within this many hours is recent. */
    public const int RECENT_HOURS = 24;

    /** A connection that ends within this many days is expiring – connect Claude again before then. */
    public const int EXPIRY_DAYS = 14;

    /** A connection's refresh row is stamped at most this often (one write per minute, not per call). */
    public const int STAMP_SECONDS = 60;

    /** A new request waiting longer than this while nothing picks it up is highlighted (Requests list, health). */
    public const int WAIT_HOURS = 48;

    public const array STATES = ['off', 'none', 'idle', 'active'];
    public const array PICKUPS = ['automatic', 'manual', 'stalled'];

    /** @var array<int, array<string, mixed>> the status of this request by the Db object (the header asks on every screen) */
    private static array $cache = [];

    /**
     * The status of the site's Claude connection (once per request, fresh = ask again).
     *
     * @return array{state: string, last_call: ?string, recent: bool, pickup: ?string, stalled_since: ?string, expires: ?string, expiring: bool, waiting: int, oldest_waiting: ?string, ever: bool}
     */
    public static function status(Db $db, Settings $settings, bool $fresh = false): array
    {
        $key = spl_object_id($db);
        if ($fresh || !isset(self::$cache[$key])) {
            self::$cache[$key] = self::evaluate(self::facts($db, $settings), time());
        }

        /** @var array{state: string, last_call: ?string, recent: bool, pickup: ?string, stalled_since: ?string, expires: ?string, expiring: bool, waiting: int, oldest_waiting: ?string, ever: bool} */
        return self::$cache[$key];
    }

    /** Forgets the remembered status (after a change within the same request). */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /**
     * What the status is made of, read from the database. Times are DATETIME values in the site's time.
     *
     * @return array{enabled: bool, connections: int, last_call: ?string, expires: ?string, schedules: list<array{next_due: ?string, last_status: ?string, last_at: ?string}>, waiting: int, oldest_waiting: ?string, first_used: string}
     */
    public static function facts(Db $db, Settings $settings): array
    {
        $now = Clock::now();
        $valid = $db->one("SELECT COUNT(*) AS n, MIN(t.expirace) AS expires FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu
            WHERE u.blokovat = 0 AND ((t.druh = 'obnova' AND t.expirace > ?) OR (t.druh = 'token' AND (t.expirace IS NULL OR t.expirace > ?)))", [$now, $now]) ?? [];
        $last = $db->value('SELECT MAX(pouzit) FROM {api_tokeny}');
        try {
            $refreshed = $db->value('SELECT MAX(rotated_at) FROM {oauth_rotated}');
        } catch (\Throwable) {
            $refreshed = null; // before the 3.3.4 migration
        }
        $lastCall = max((string) ($last ?? ''), (string) ($refreshed ?? ''));
        $schedules = [];
        try {
            foreach ($db->all("SELECT id, next_due FROM {agent_schedules} WHERE active = 1 AND task = 'requests' ORDER BY id") as $s) {
                $run = $db->one("SELECT status, COALESCE(finished_at, started_at, due_at) AS at FROM {agent_runs} WHERE schedule_id = ? AND status <> 'running' ORDER BY id DESC LIMIT 1", [(int) $s['id']]);
                $schedules[] = ['next_due' => $s['next_due'] !== null ? (string) $s['next_due'] : null, 'last_status' => $run !== null ? (string) $run['status'] : null,
                    'last_at' => $run !== null && $run['at'] !== null ? (string) $run['at'] : null];
            }
        } catch (\Throwable) {
            $schedules = []; // before the 2.17 migration
        }
        try {
            $waiting = $db->one("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM {requests} WHERE status = 'new'") ?? [];
        } catch (\Throwable) {
            $waiting = []; // before the 2.15 migration
        }

        return [
            'enabled' => Extensions::isEnabled($settings, 'claude'),
            'connections' => (int) ($valid['n'] ?? 0),
            'last_call' => $lastCall !== '' ? $lastCall : null,
            'expires' => isset($valid['expires']) ? (string) $valid['expires'] : null,
            'schedules' => $schedules,
            'waiting' => (int) ($waiting['n'] ?? 0),
            'oldest_waiting' => isset($waiting['oldest']) ? (string) $waiting['oldest'] : null,
            'first_used' => $settings->get('claude_first_used'),
        ];
    }

    /**
     * The state machine (pure): facts() and the current Unix time → the status.
     *
     * @param array{enabled: bool, connections: int, last_call: ?string, expires: ?string, schedules: list<array{next_due: ?string, last_status: ?string, last_at: ?string}>, waiting: int, oldest_waiting: ?string, first_used: string} $facts
     * @return array{state: string, last_call: ?string, recent: bool, pickup: ?string, stalled_since: ?string, expires: ?string, expiring: bool, waiting: int, oldest_waiting: ?string, ever: bool}
     */
    public static function evaluate(array $facts, int $now): array
    {
        $time = static fn (?string $moment): ?int => $moment !== null && ($t = strtotime($moment)) !== false ? $t : null;
        $lastCall = $time($facts['last_call']);
        $state = match (true) {
            !$facts['enabled'] => 'off',
            $facts['connections'] < 1 => 'none',
            $lastCall !== null && $lastCall >= $now - self::ACTIVE_DAYS * 86400 => 'active',
            default => 'idle',
        };
        $pickup = null;
        $stalledSince = null;
        if ($state === 'idle' || $state === 'active') {
            $pickup = $facts['schedules'] === [] ? 'manual' : 'stalled';
            foreach ($facts['schedules'] as $s) {
                $due = $time($s['next_due']);
                $since = match (true) {
                    in_array($s['last_status'], ['missed', 'failed'], true) => $s['last_at'] ?? $s['next_due'],
                    $due !== null && $due < $now - AgentSchedules::MISSED_HOURS * 3600 => $s['next_due'],
                    default => null,
                };
                if ($since === null) {
                    $pickup = 'automatic';
                    $stalledSince = null;
                    break;
                }
                $stalledSince = $stalledSince === null ? $since : max($stalledSince, $since);
            }
        }
        $expires = $state === 'off' || $state === 'none' ? null : $facts['expires'];
        $expiresAt = $time($expires);

        return [
            'state' => $state,
            'last_call' => $facts['last_call'],
            'recent' => $lastCall !== null && $lastCall >= $now - self::RECENT_HOURS * 3600,
            'pickup' => $pickup,
            'stalled_since' => $stalledSince,
            'expires' => $expires,
            'expiring' => $expiresAt !== null && $expiresAt < $now + self::EXPIRY_DAYS * 86400,
            'waiting' => max(0, $facts['waiting']),
            'oldest_waiting' => $facts['waiting'] > 0 ? $facts['oldest_waiting'] : null,
            'ever' => $facts['first_used'] !== '' || $facts['last_call'] !== null,
        ];
    }

    /**
     * Whether a valid connection exists now (state idle or active) – Claude can pick requests up.
     *
     * @param array{state: string} $status
     */
    public static function reachable(array $status): bool
    {
        return $status['state'] === 'idle' || $status['state'] === 'active';
    }

    /**
     * Whether nothing picks new requests up: no connection (off, none), or the scheduled run that should does not run.
     *
     * @param array{state: string, pickup: ?string} $status
     */
    public static function blocked(array $status): bool
    {
        return !self::reachable($status) || $status['pickup'] === 'stalled';
    }

    /**
     * Whether the dashboard leads with "Connect Claude": no valid connection, or one nobody has used yet (a personal token
     * created but never called with) on a site Claude never called.
     *
     * @param array{state: string, last_call: ?string, ever: bool} $status
     */
    public static function needsConnecting(array $status): bool
    {
        return $status['state'] === 'none' || ($status['state'] === 'idle' && $status['last_call'] === null && !$status['ever']);
    }

    /**
     * The status as the fleet heartbeat and get_health carry it (3.18): the state, the last call and the earliest expiry as
     * ISO 8601 moments, how requests are picked up.
     *
     * @param array{state: string, last_call: ?string, pickup: ?string, expires: ?string} $status
     * @return array{state: string, last_call: ?string, pickup: ?string, expires: ?string}
     */
    public static function summary(array $status): array
    {
        $iso = static fn (?string $value): ?string => Clock::fromDb($value)?->format(DATE_ATOM);

        return ['state' => $status['state'], 'last_call' => $iso($status['last_call']), 'pickup' => $status['pickup'], 'expires' => $iso($status['expires'])];
    }

    /**
     * Stamps the refresh row of the OAuth connection a call came through (Mcp\Server): the access row goes an hour after it
     * expires, the refresh row lives a year – without this the last call of a connection idle for an hour is lost. At most
     * once a STAMP_SECONDS (a call within that window changes no row). A personal token (no client) has one long-lived row,
     * stamped by the caller. Never fails the call.
     */
    public static function stamp(Db $db, int $userId, ?string $client): void
    {
        if ($client === null || $client === '') {
            return;
        }
        try {
            $db->run("UPDATE {api_tokeny} SET pouzit = ? WHERE idu = ? AND klient = ? AND druh = 'obnova' AND (pouzit IS NULL OR pouzit < ?)",
                [Clock::now(), $userId, $client, Clock::at('-' . self::STAMP_SECONDS . ' seconds')]);
        } catch (\Throwable) {
            // the last call is information only – the call itself goes on
        }
    }

    /**
     * The last call of a user's OAuth connection (every row of the client, the refresh row stamped by stamp()) – what a
     * refresh carries over to the new pair. Null when it never called.
     */
    public static function lastUse(Db $db, int $userId, string $client): ?string
    {
        $last = $db->value('SELECT MAX(pouzit) FROM {api_tokeny} WHERE idu = ? AND klient = ?', [$userId, $client]);

        return $last === null ? null : (string) $last;
    }
}
