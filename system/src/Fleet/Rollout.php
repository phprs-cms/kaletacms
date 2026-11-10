<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\Db;
use Kaleta\Core\Settings;

/**
 * Update rings and maintenance windows of the fleet console (3.30). The site still asks (its hourly heartbeat) and the
 * console only answers which version it may install now – nothing here reaches into a site.
 *
 *  - Ring per site: pilot | normal | careful (default normal; the "test sites" of 2.9, stored as canary, are pilot). Each
 *    ring waits its delay (settings fleet_ring_*_hours, defaults 0 h, 48 h and 7 days) after the console first saw the
 *    release. With pilot sites, normal and careful sites also wait until every pilot that lets the console decide runs the
 *    release without errors, without being down and without a failed update – the delay then counts from the moment the
 *    last pilot started running it, minus the pilots' own delay (with the default 0 h: 48 hours after the pilots, as in 2.9).
 *    A pilot already on a newer version counts as running it; a pilot that stopped reporting while it looked healthy on an
 *    older version is left out (silentPilot), so no site waits for it forever.
 *  - Maintenance window per customer, optionally per site: ISO days of the week and a time range in the console's time
 *    zone ("12345|22:00-05:00" – a range past midnight belongs to the day it starts; the same start and end = the whole
 *    day; '' = any time). A site is told "you may update now" only inside its window, after its ring delay. On a night the
 *    clocks change, the wall clock decides: when they go forward, a time in the skipped hour moves on by that hour (02:30
 *    is 03:30 that night); when they go back, a window over the repeated hour lasts an hour longer.
 *  - The rule for security releases (unchanged from 2.9): a security release is allowed to every site at once, whatever
 *    its ring and window; so is a version an administrator allowed by hand ("Allow version now"). A site also installs a
 *    security release itself when its automatic security updates are on (Core\Updater) – the console never delays that.
 *  - A site that decides about its updates itself (manage_updates off) is told nothing.
 *
 *  - Today (3.31.1, heldItem): a rollout held back by the pilot sites for more than HELD_DAYS – counted from the moment the
 *    first held site would otherwise have been allowed (the console first saw the release + its ring's delay) – is one
 *    amber item of Today and fleet_today, with a plain link to the Rollout tab (each held site's page has "Allow version
 *    now"; fixing the pilot releases it for all).
 *
 * decide() and the window helpers are pure (tools/unit-tests.php has the decision table); Console::heartbeat calls them.
 */
final class Rollout
{
    public const array RINGS = ['pilot', 'normal', 'careful'];

    /** The default delay of each ring after the console first saw a release, in hours. */
    public const array DEFAULT_HOURS = ['pilot' => 0, 'normal' => 48, 'careful' => 168];

    /** The longest delay a ring may have (60 days). */
    public const int MAX_HOURS = 1440;

    /**
     * The shortest maintenance window, in minutes (N330-3): a site asks once an hour, so a shorter window could fall between
     * two of its heartbeats every day and the site would never be told it may update.
     */
    public const int MIN_WINDOW_MINUTES = 120;

    /** The setting of each ring's delay. */
    public const array SETTINGS = ['pilot' => 'fleet_ring_pilot_hours', 'normal' => 'fleet_ring_normal_hours', 'careful' => 'fleet_ring_careful_hours'];

    /** What a site's update is doing (the rollout view, MCP): the order of the summary. */
    public const array STATES = ['updated', 'allowed', 'waiting', 'blocked', 'failed', 'self'];

    /** The names of the rings and of the states on the screens (English, through t()). */
    public const array RING_LABELS = ['pilot' => 'Pilot', 'normal' => 'Normal', 'careful' => 'Careful'];
    public const array STATE_LABELS = ['updated' => 'Updated', 'allowed' => 'May update now', 'waiting' => 'Waiting', 'blocked' => 'Held back by the pilot sites',
        'failed' => 'Update failed', 'self' => 'Decides itself'];

    /** A rollout held back longer than this many days is an item of Today (3.31.1, heldItem). */
    public const int HELD_DAYS = 3;

    /** The weight of that item in the Today ranking – as the check "Updates" (Checks::CHECKS). */
    public const int HELD_WEIGHT = 50;

    /** Where it is released or resumed: the Rollout tab of the console (relative). */
    public const string HELD_PATH = 'admin.php?module=fleet&action=rollout';

    /** The ring as stored: canary (before 3.30) is pilot, anything unknown is normal. Pure. */
    public static function ring(mixed $stored): string
    {
        return match ($stored) {
            'pilot', 'canary' => 'pilot',
            'careful' => 'careful',
            default => 'normal',
        };
    }

    /**
     * The delays in force, in hours (settings over DEFAULT_HOURS, each 0–MAX_HOURS).
     *
     * @return array{pilot: int, normal: int, careful: int}
     */
    public static function delays(Settings $s): array
    {
        $out = self::DEFAULT_HOURS;
        foreach (self::SETTINGS as $ring => $key) {
            $value = $s->get($key);
            if (preg_match('/^\d{1,5}$/D', $value) === 1) {
                $out[$ring] = min(self::MAX_HOURS, (int) $value);
            }
        }

        return $out;
    }

    /**
     * A maintenance window from its stored text, null for "any time" ('' or anything that is not a window). Pure.
     *
     * @return array{days: list<int>, from: int, to: int}|null days 1 (Monday) – 7 (Sunday), from/to in minutes after midnight
     */
    public static function parseWindow(?string $stored): ?array
    {
        if ($stored === null || preg_match('/^([1-7]{1,7})\|(\d{2}):(\d{2})-(\d{2}):(\d{2})$/D', $stored, $m) !== 1) {
            return null;
        }
        $days = array_values(array_unique(array_map('intval', str_split($m[1]))));
        sort($days);
        [$fromH, $fromM, $toH, $toM] = [(int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]];
        if ($fromH > 23 || $toH > 23 || $fromM > 59 || $toM > 59) {
            return null;
        }

        return ['days' => $days, 'from' => $fromH * 60 + $fromM, 'to' => $toH * 60 + $toM];
    }

    /**
     * A window as stored from the form: the ticked days (1–7) and the times "HH:MM". '' (any time) when no day is ticked
     * or a time is not one; the same start and end is the whole of each ticked day (every day: any time). Pure.
     *
     * @param array<mixed> $days
     */
    public static function windowFromInput(array $days, string $from, string $to): string
    {
        $days = array_values(array_unique(array_filter(array_map(static fn (mixed $d): int => is_numeric($d) ? (int) $d : 0, $days), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        $time = static fn (string $t): ?string => preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', trim($t), $m) === 1 ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : null;
        [$from, $to] = [$time($from), $time($to)];
        if ($days === [] || $from === null || $to === null || (count($days) === 7 && $from === $to)) {
            return '';
        }
        if ($from === $to) {
            [$from, $to] = ['00:00', '00:00']; // the whole of each ticked day
        }

        return implode('', $days) . '|' . $from . '-' . $to;
    }

    /** How long a window is open on each of its days, in minutes (the same start and end = the whole day). Pure. @param array{days: list<int>, from: int, to: int} $w */
    public static function windowMinutes(array $w): int
    {
        return $w['to'] > $w['from'] ? $w['to'] - $w['from'] : 1440 - $w['from'] + $w['to'];
    }

    /** Whether a stored window is long enough to be met by the hourly heartbeat ('' = any time is). Pure. */
    public static function longEnough(string $stored): bool
    {
        $w = self::parseWindow($stored);

        return $w === null || self::windowMinutes($w) >= self::MIN_WINDOW_MINUTES;
    }

    /** The window in words for the screens ("Mon–Fri 22:00–05:00", "Any time"). Pure apart from the dictionaries. */
    public static function describe(?string $stored): string
    {
        $w = self::parseWindow($stored);
        if ($w === null) {
            return t('Any time');
        }
        $names = [1 => t('Mon'), 2 => t('Tue'), 3 => t('Wed'), 4 => t('Thu'), 5 => t('Fri'), 6 => t('Sat'), 7 => t('Sun')];
        $runs = [];
        foreach ($w['days'] as $d) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][1] === $d - 1) {
                $runs[$last][1] = $d;
            } else {
                $runs[] = [$d, $d];
            }
        }
        $days = implode(', ', array_map(static fn (array $r): string => $r[0] === $r[1] ? $names[$r[0]] : $names[$r[0]] . ($r[1] - $r[0] > 1 ? '–' : ', ') . $names[$r[1]], $runs));
        $hm = static fn (int $min): string => sprintf('%02d:%02d', intdiv($min, 60), $min % 60);

        return $days . ' ' . ($w['from'] === $w['to'] ? t('all day') : $hm($w['from']) . '–' . $hm($w['to']));
    }

    /**
     * The intervals of a window that touch the days around a moment, as [start, end) Unix times – from the day before (a
     * range past midnight reaches into the next day) to $days days after. Built on the wall clock of $tz, so a change of
     * the clocks moves them as the people of that zone expect. Pure.
     *
     * @param array{days: list<int>, from: int, to: int} $w
     * @return list<array{0: int, 1: int}>
     */
    private static function intervals(array $w, int $now, \DateTimeZone $tz, int $days = 8): array
    {
        $out = [];
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        for ($i = -1; $i <= $days; $i++) {
            $day = $today->modify(($i >= 0 ? '+' : '') . $i . ' days');
            if (!in_array((int) $day->format('N'), $w['days'], true)) {
                continue;
            }
            $start = $day->setTime(intdiv($w['from'], 60), $w['from'] % 60);
            $end = $w['to'] > $w['from'] ? $day->setTime(intdiv($w['to'], 60), $w['to'] % 60)
                : $day->modify('+1 day')->setTime(intdiv($w['to'], 60), $w['to'] % 60); // past midnight, or the whole day
            // a wall time the clocks skip ("02:30" on the night they go forward) is moved on by that hour (03:30); in the
            // hour they repeat, the first of the two is taken – the window that night is an hour longer
            $out[] = [$start->getTimestamp(), $end->getTimestamp()];
        }

        return $out;
    }

    /** Whether a moment is inside a window (null = any time). Pure. @param array{days: list<int>, from: int, to: int}|null $w */
    public static function inWindow(?array $w, int $now, \DateTimeZone $tz): bool
    {
        if ($w === null) {
            return true;
        }
        foreach (self::intervals($w, $now, $tz) as [$start, $end]) {
            if ($now >= $start && $now < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first moment at or after $from inside the window (null = any time: $from itself; null when the window never
     * opens in the next 8 days, which only a window shorter than the hour the clocks skip can do). Pure.
     *
     * @param array{days: list<int>, from: int, to: int}|null $w
     */
    public static function nextOpening(?array $w, int $from, \DateTimeZone $tz): ?int
    {
        if ($w === null || self::inWindow($w, $from, $tz)) {
            return $from;
        }
        $next = null;
        foreach (self::intervals($w, $from, $tz) as [$start, $end]) {
            if ($start >= $from && $end > $start && ($next === null || $start < $next)) {
                $next = $start;
            }
        }

        return $next;
    }

    /**
     * The window that applies to a site: its own (update_window not null), otherwise its customer's. Pure.
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     * @param array<int, string> $customerWindows customer id => window
     */
    public static function windowOf(array $site, array $customerWindows): string
    {
        if (is_string($site['update_window'] ?? null)) {
            return $site['update_window'];
        }

        return $customerWindows[Console::customerIdOf($site)] ?? '';
    }

    /** @return array<int, string> customer id => its window ('' = any time); [] before migration 0116 */
    public static function customerWindows(Db $db): array
    {
        try {
            return array_map('strval', $db->pairs("SELECT id, update_window FROM {fleet_customers} WHERE update_window <> ''"));
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Which version a site may install now and why (see the class description). Pure.
     *
     * @param array{id: int, version: string, ring: string, manage_updates: bool, update_allowed: string, status: string, up: ?bool, version_since: ?int, update_problem: bool, last_seen?: ?int} $site Console::row
     * @param list<array{id: int, version: string, ring: string, manage_updates: bool, update_allowed: string, status: string, up: ?bool, version_since: ?int, update_problem: bool, last_seen?: ?int}> $sites
     * @param array{pilot: int, normal: int, careful: int} $delays hours
     * @param array{days: list<int>, from: int, to: int}|null $window
     * @return array{version: string, state: string, from: ?int} state updated | allowed | waiting (from = when) | blocked (the pilots) | self
     */
    public static function decide(array $site, array $sites, string $latest, bool $security, int $firstSeen, int $now, array $delays, ?array $window, \DateTimeZone $tz): array
    {
        if (!$site['manage_updates']) {
            return ['version' => '', 'state' => 'self', 'from' => null];
        }
        if ($latest === '' || ($site['version'] !== '' && !version_compare($latest, $site['version'], '>'))) {
            return ['version' => '', 'state' => 'updated', 'from' => null];
        }
        if ($site['update_allowed'] === $latest || $security) {
            return ['version' => $latest, 'state' => 'allowed', 'from' => $now];
        }
        $ring = self::ring($site['ring']);
        $hours = static fn (string $r): int => max(0, min(self::MAX_HOURS, (int) ($delays[$r] ?? self::DEFAULT_HOURS[$r])));
        if ($ring === 'pilot') {
            $start = max(0, $firstSeen) + $hours('pilot') * 3600;
        } else {
            $pilots = array_filter($sites, static fn (array $p): bool => self::ring($p['ring']) === 'pilot' && $p['manage_updates'] && $p['id'] !== $site['id']
                && !self::silentPilot($p, $latest, $now));
            if ($pilots === []) {
                if ($firstSeen <= 0) {
                    return ['version' => '', 'state' => 'blocked', 'from' => null];
                }
                $start = $firstSeen + $hours($ring) * 3600;
            } else {
                $since = 0;
                foreach ($pilots as $p) {
                    // a pilot already past the release (another update channel, a version allowed by hand) has run it
                    if (version_compare($p['version'], $latest, '<') || $p['version_since'] === null || $p['status'] === 'error' || $p['up'] === false || $p['update_problem']) {
                        return ['version' => '', 'state' => 'blocked', 'from' => null];
                    }
                    $since = max($since, $p['version_since']);
                }
                $start = $since + max(0, $hours($ring) - $hours('pilot')) * 3600;
            }
        }
        if ($now < $start) {
            return ['version' => '', 'state' => 'waiting', 'from' => self::nextOpening($window, $start, $tz)];
        }
        if (!self::inWindow($window, $now, $tz)) {
            return ['version' => '', 'state' => 'waiting', 'from' => self::nextOpening($window, $now, $tz)];
        }

        return ['version' => $latest, 'state' => 'allowed', 'from' => $start];
    }

    /**
     * A pilot that has stopped reporting (no heartbeat for Console::SILENT_AFTER since its last one or its pairing) while it
     * still runs an older version and its last report showed no problem: it would hold back every other site forever, so
     * the gate leaves it out (N330-2). A silent pilot that is down, reported errors or a failed update, or runs the release
     * still counts. A row without last_seen (an older caller) is never silent. Pure.
     *
     * @param array<string, mixed> $p Console::row
     */
    public static function silentPilot(array $p, string $latest, int $now): bool
    {
        $seen = $p['last_seen'] ?? null;

        return is_int($seen) && $now - $seen > Console::SILENT_AFTER && $p['up'] !== false && $p['status'] !== 'error' && !$p['update_problem']
            && version_compare((string) $p['version'], $latest, '<');
    }

    /**
     * The rollout of the newest release across the sites (the Rollout tab, MCP): per site its ring, window, state and
     * the moment it may update (waiting), and per ring how many sites are in each state. A site whose last update failed
     * and that still runs an older version is "failed". Pure.
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param array<int, string> $customerWindows
     * @param array{pilot: int, normal: int, careful: int} $delays
     * @return array{version: string, security: bool, first_seen: int, sites: list<array<string, mixed>>, rings: array<string, array<string, int>>, next: array<string, ?int>}
     */
    public static function overview(array $sites, array $customerWindows, string $latest, bool $security, int $firstSeen, int $now, array $delays, \DateTimeZone $tz): array
    {
        $rows = array_map(Console::row(...), $sites);
        $out = [];
        $rings = array_fill_keys(self::RINGS, array_fill_keys(self::STATES, 0));
        $next = array_fill_keys(self::RINGS, null);
        foreach ($sites as $i => $site) {
            $window = self::windowOf($site, $customerWindows);
            $decision = self::decide($rows[$i], $rows, $latest, $security, $firstSeen, $now, $delays, self::parseWindow($window), $tz);
            $state = $decision['state'];
            if ($rows[$i]['update_problem'] && in_array($state, ['allowed', 'waiting', 'blocked'], true)) {
                $state = 'failed';
            }
            $ring = self::ring($site['ring'] ?? '');
            $rings[$ring][$state]++;
            if ($state === 'waiting' && $decision['from'] !== null) {
                $next[$ring] = $next[$ring] === null ? $decision['from'] : min($next[$ring], $decision['from']);
            }
            $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
            $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
            $out[] = ['site_id' => (int) ($site['id'] ?? 0), 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => Console::customerIdOf($site) ?: null,
                'ring' => $ring, 'version' => (string) ($site['version'] ?? ''), 'state' => $state, 'from' => $state === 'waiting' ? $decision['from'] : null,
                'window' => $window, 'own_window' => is_string($site['update_window'] ?? null)];
        }
        $order = array_flip(self::RINGS);
        $stateOrder = array_flip(self::STATES);
        usort($out, static fn (array $a, array $b): int => [$order[$a['ring']], $stateOrder[$a['state']], mb_strtolower($a['site']), $a['site_id']]
            <=> [$order[$b['ring']], $stateOrder[$b['state']], mb_strtolower($b['site']), $b['site_id']]);

        return ['version' => $latest, 'security' => $security, 'first_seen' => $firstSeen, 'sites' => $out, 'rings' => $rings, 'next' => $next];
    }

    /**
     * The item of Today for a rollout held back by the pilot sites more than HELD_DAYS (3.31.1), or null: one item for the
     * release (never one per site), amber, in the shape of Checks::today (kind and check "rollout", site_id 0) plus the
     * version and how many sites are held. Held since = the earliest moment a held site would otherwise have been allowed
     * (the console first saw the release + the delay of the site's ring). Pure apart from the dictionaries.
     *
     * @param array{version: string, security: bool, first_seen: int, sites: list<array<string, mixed>>, rings: array<string, array<string, int>>, next: array<string, ?int>} $overview overview()
     * @param array{pilot: int, normal: int, careful: int} $delays hours
     * @return array<string, mixed>|null
     */
    public static function heldItem(array $overview, array $delays, int $now): ?array
    {
        if ($overview['first_seen'] <= 0 || $overview['security']) {
            return null;
        }
        $since = null;
        $held = 0;
        foreach ($overview['sites'] as $site) {
            if ($site['state'] !== 'blocked') {
                continue;
            }
            $ring = self::ring($site['ring'] ?? '');
            $at = $overview['first_seen'] + max(0, min(self::MAX_HOURS, (int) ($delays[$ring] ?? self::DEFAULT_HOURS[$ring]))) * 3600;
            $since = $since === null ? $at : min($since, $at);
            $held++;
        }
        if ($since === null || $now - $since <= self::HELD_DAYS * 86400) {
            return null;
        }

        return ['kind' => 'rollout', 'site_id' => 0, 'site' => '', 'url' => '', 'customer_id' => null, 'check' => 'rollout', 'label' => t('Update rollout held back'), 'state' => 'warn',
            'detail' => t('Version %s – sites held back by the pilot sites: %s', $overview['version'], format_count($held)), 'path' => self::HELD_PATH, 'link' => self::HELD_PATH,
            'weight' => self::HELD_WEIGHT, 'since' => $since, 'version' => $overview['version'], 'held' => $held];
    }
}
