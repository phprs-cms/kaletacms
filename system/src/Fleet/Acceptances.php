<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\Db;

/**
 * States accepted on the console (3.39, table ka_fleet_acceptances): the operator accepts one check of one site
 * (Fleet\Checks) in the state it is in – amber or red – with a short note and a last day ("the customer will not pay for an
 * off-site backup", until 31 March). An accepted check leaves the open groups of Today for the group Accepted, sends no alert
 * (Fleet\AlertRules, the expiry reminders of Fleet\Calendar) and leaves the digest's attention, until its last day or until
 * the check's state changes: a worse state re-opens it at once, and the job fleet_alerts drops an acceptance whose check
 * changed (to ok or to another state) or whose last day passed, so a problem that comes back is open again.
 *
 * Stored on the console only – nothing is sent to the site, the site is never touched (the console never reaches into
 * sites). The note is the operator's own text, bounded (NOTE_LENGTH, one line); the state accepted is taken from the
 * console's own evaluation of the check, never from the form.
 */
final class Acceptances
{
    public const int NOTE_LENGTH = 200;

    /** The last day when none is given: a month from today. */
    public const int DEFAULT_DAYS = 30;

    /** The furthest last day: a year from today – an acceptance is a decision to look at again, never for ever. */
    public const int MAX_DAYS = 365;

    /** The states that can be accepted. */
    public const array STATES = ['warn', 'bad'];

    /**
     * Checks that are never accepted (3.39 review M3): an outage is never a known state to live with – each one must reach
     * the operator. An expired certificate or domain (Checks::EXPIRED) is never accepted either.
     */
    public const array NEVER = ['uptime'];

    /** The longest reason stored (Checks::forSite 'reason'). */
    public const int REASON_LENGTH = 64;

    /** May this check in this state with this reason be accepted at all? */
    public static function acceptable(string $check, string $state, string $reason): bool
    {
        return isset(Checks::CHECKS[$check]) && !in_array($check, self::NEVER, true) && in_array($state, self::STATES, true)
            && $reason !== '' && $reason !== Checks::EXPIRED;
    }

    /**
     * Every stored acceptance; [] before migration 0125.
     *
     * @return array<string, array{site_id: int, check: string, state: string, reason: string, note: string, until: string, by: ?int, at: string}> "site:check" => acceptance
     */
    public static function load(Db $db): array
    {
        try {
            $rows = $db->all('SELECT site_id, check_id, state, reason, note, ends_on, accepted_by, accepted_at FROM {fleet_acceptances}');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0125 (the table) or 0127 (its reason)
        }
        $out = [];
        foreach ($rows as $row) {
            $out[self::key((int) $row['site_id'], (string) $row['check_id'])] = ['site_id' => (int) $row['site_id'], 'check' => (string) $row['check_id'], 'state' => (string) $row['state'],
                'reason' => (string) $row['reason'], 'note' => (string) $row['note'], 'until' => substr((string) $row['ends_on'], 0, 10), 'by' => $row['accepted_by'] !== null ? (int) $row['accepted_by'] : null,
                'at' => (string) $row['accepted_at']];
        }

        return $out;
    }

    public static function key(int $siteId, string $check): string
    {
        return $siteId . ':' . $check;
    }

    /**
     * Whether an acceptance holds for a check in $state with $reason (Checks::forSite 'reason') on $today ('Y-m-d'): the
     * same state and the same specific problem (3.39 review M3 – "the last update failed" is not the version accepted as
     * waiting, another job failing is not the one accepted), its last day not passed, and the check still acceptable
     * (never an outage, never an expired certificate or domain). Pure.
     *
     * @param array<string, mixed>|null $acceptance load() row
     */
    public static function holds(?array $acceptance, string $state, string $today, string $reason): bool
    {
        return $acceptance !== null && $acceptance['state'] === $state && (string) ($acceptance['reason'] ?? '') === $reason && $acceptance['until'] >= $today
            && self::acceptable((string) $acceptance['check'], $state, $reason);
    }

    /**
     * Today's items split into the open ones and the accepted ones (an accepted item carries 'accepted'). Only checks of
     * a site can be accepted – an overdue task or a held rollout stays open. Pure.
     *
     * @param list<array<string, mixed>> $items Checks::today (and the other kinds of Today)
     * @param array<string, array<string, mixed>> $acceptances load()
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function split(array $items, array $acceptances, string $today): array
    {
        $open = [];
        $accepted = [];
        foreach ($items as $item) {
            $acceptance = ($item['kind'] ?? 'check') === 'check' && isset(Checks::CHECKS[$item['check'] ?? '']) ? ($acceptances[self::key((int) $item['site_id'], (string) $item['check'])] ?? null) : null;
            if ($acceptance !== null && self::holds($acceptance, (string) $item['state'], $today, (string) ($item['reason'] ?? ''))) {
                $accepted[] = $item + ['accepted' => $acceptance];
            } else {
                $open[] = $item;
            }
        }

        return [$open, $accepted];
    }

    /**
     * The acceptances that hold now: the check of the site is in the state accepted and the last day has not passed. Pure
     * apart from the dictionaries (Checks::forRow).
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param array<string, array<string, mixed>> $acceptances load()
     * @param array{security_version?: string} $context
     * @return array<string, array<string, mixed>> "site:check" => acceptance
     */
    public static function active(array $sites, array $acceptances, int $now, array $context = []): array
    {
        if ($acceptances === []) {
            return [];
        }
        $today = date('Y-m-d', $now);
        $out = [];
        foreach ($sites as $site) {
            foreach (Checks::forRow($site, $now, $context) as $check) {
                $key = self::key((int) ($site['id'] ?? 0), $check['id']);
                if (self::holds($acceptances[$key] ?? null, $check['state'], $today, $check['reason'])) {
                    $out[$key] = $acceptances[$key];
                }
            }
        }

        return $out;
    }

    /**
     * The acceptances to drop: the last day passed, the site is gone, or the check is in another state or has another reason
     * than the one accepted (better or worse; "unknown" – a site that says nothing about it now – keeps it), or it may not be
     * accepted at all (an outage, an expired certificate or domain). Pure.
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param array<string, array<string, mixed>> $acceptances load()
     * @param array{security_version?: string} $context
     * @return list<string> "site:check"
     */
    public static function stale(array $sites, array $acceptances, int $now, array $context = []): array
    {
        $today = date('Y-m-d', $now);
        $states = [];
        foreach ($sites as $site) {
            foreach (Checks::forRow($site, $now, $context) as $check) {
                $states[self::key((int) ($site['id'] ?? 0), $check['id'])] = [$check['state'], $check['reason']];
            }
        }
        $out = [];
        foreach ($acceptances as $key => $acceptance) {
            [$state, $reason] = $states[$key] ?? [null, ''];
            if ($acceptance['until'] < $today || $state === null || in_array($acceptance['check'], self::NEVER, true)
                || ($state !== 'unknown' && ($state !== $acceptance['state'] || $reason !== (string) ($acceptance['reason'] ?? '') || !self::acceptable((string) $acceptance['check'], $state, $reason)))) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /** Drops the stale acceptances (stale()); run by the job fleet_alerts before it looks at the sites. Returns how many. */
    public static function prune(App $app): int
    {
        $db = $app->db();
        $acceptances = self::load($db);
        if ($acceptances === []) {
            return 0;
        }
        $dropped = 0;
        foreach (self::stale(Console::sites($db), $acceptances, Clock::time(), Health::context($app)) as $key) {
            $dropped += self::withdraw($db, $acceptances[$key]['site_id'], $acceptances[$key]['check']) ? 1 : 0;
        }

        return $dropped;
    }

    /**
     * The last day from the form: '' = DEFAULT_DAYS from today; otherwise a real calendar day from today to MAX_DAYS
     * ahead. null for anything else.
     */
    public static function until(string $input, string $today): ?string
    {
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if ($base === false) {
            return null;
        }
        $input = trim($input);
        if ($input === '') {
            return $base->modify('+' . self::DEFAULT_DAYS . ' days')->format('Y-m-d');
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if ($day === false || $day->format('Y-m-d') !== $input || $input < $today || $input > $base->modify('+' . self::MAX_DAYS . ' days')->format('Y-m-d')) {
            return null;
        }

        return $input;
    }

    /** The note on one line, at most NOTE_LENGTH characters. */
    public static function note(string $note): string
    {
        return Console::oneLine($note, self::NOTE_LENGTH);
    }

    /**
     * Accepts one check of one site in $state with $reason (the console's own evaluation, Checks::forSite) until $until
     * (until()); replaces an earlier acceptance of the same check.
     */
    public static function accept(Db $db, int $siteId, string $check, string $state, string $note, string $until, ?int $userId, string $reason): void
    {
        $reason = mb_substr($reason, 0, self::REASON_LENGTH);
        if (!self::acceptable($check, $state, $reason)) {
            throw new \InvalidArgumentException('Only an amber or red check can be accepted – never an outage or an expired certificate or domain.');
        }
        $db->transaction(function () use ($db, $siteId, $check, $state, $reason, $note, $until, $userId): void {
            $db->delete('fleet_acceptances', ['site_id' => $siteId, 'check_id' => $check]);
            $db->insert('fleet_acceptances', ['site_id' => $siteId, 'check_id' => $check, 'state' => $state, 'reason' => $reason, 'note' => self::note($note), 'ends_on' => $until,
                'accepted_by' => $userId !== null && $userId > 0 ? $userId : null, 'accepted_at' => Clock::now()]);
        });
    }

    /** Withdraws the acceptance of one check of one site (the check is open again). */
    public static function withdraw(Db $db, int $siteId, string $check): bool
    {
        try {
            return $db->delete('fleet_acceptances', ['site_id' => $siteId, 'check_id' => $check]) > 0;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return false; // before migration 0125
        }
    }

    /** A site removed from the console takes its acceptances along. */
    public static function forget(Db $db, int $siteId): void
    {
        try {
            $db->delete('fleet_acceptances', ['site_id' => $siteId]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0125
        }
    }

    /**
     * The acceptances of one site that hold now, for its screen: check id => acceptance with the check's label.
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     * @param array{security_version?: string} $context
     * @return array<string, array<string, mixed>>
     */
    public static function ofSite(Db $db, array $site, int $now, array $context = []): array
    {
        $out = [];
        foreach (self::active([$site], self::load($db), $now, $context) as $acceptance) {
            $out[(string) $acceptance['check']] = $acceptance + ['label' => t(Checks::CHECKS[$acceptance['check']][0])];
        }

        return $out;
    }
}
