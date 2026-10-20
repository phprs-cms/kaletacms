<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\Db;
use Kaleta\Core\PendingReview;

/**
 * The inbox of the fleet console (3.17): everything that waits across the sites, in one list – built only from what the
 * console already stores (each site's last heartbeat and the console's own uptime check). The console never reaches into
 * a site and holds no access to one: every row links with a plain link to the screen of that site's administration where
 * a person (signed in there with their own password) deals with it.
 *
 * What a row never carries: the content of an enquiry, an e-mail address or a phone number (a request title is masked
 * again here, Heartbeat::cleanTitle), anything about the whistleblowing channel (the heartbeat does not carry it).
 * A row of a site whose last heartbeat is older than STALE_AFTER is marked stale: it may have been dealt with already.
 * A heartbeat from a site before 3.16 has only the enquiries and the health parts – the rest is simply not there.
 */
final class Inbox
{
    /** kind => label (English, translated on the console's screens) – the filter bar, in this order. */
    public const array KINDS = [
        'site' => 'Site down or silent', 'updates' => 'Failed updates', 'security' => 'Security', 'expiry' => 'Certificate and domain',
        'health' => 'Health', 'requests' => 'Requests to Claude', 'enquiries' => 'Enquiries', 'bookings' => 'Bookings to confirm',
        'review' => 'Waiting for approval', 'audit' => 'Audit findings',
    ];

    /** What a row is about => label (English). The review kinds take their labels from Core\PendingReview::KINDS. */
    public const array WHAT = [
        'site_down' => 'The site does not answer', 'site_silent' => 'The site stopped reporting', 'update_failed' => 'An update failed',
        'sign_in_attacks' => 'Many failed sign-ins', 'token_reuse' => 'Connected applications signed out after a reused token', 'certificate_expiring' => 'The certificate expires',
        'domain_expiring' => 'The domain registration expires', 'errors' => 'The site reports errors', 'jobs_failing' => 'Background jobs fail',
        'no_backup' => 'No recent backup', 'request' => 'Request', 'requests_open' => 'Open requests', 'requests_done' => 'Done requests to review',
        'enquiries' => 'New enquiries', 'bookings' => 'Bookings to confirm', 'audit' => 'Audit findings',
    ];

    /** Urgency => weight; the list is sorted by it, then by age (the oldest first). */
    public const array URGENCY = ['urgent' => 3, 'normal' => 2, 'low' => 1];

    /** A row of a site whose last heartbeat is older than this may be out of date. */
    public const int STALE_AFTER = Console::SILENT_AFTER;

    /** A certificate or domain ending within this many days is urgent (from Console::CERTIFICATE_DAYS / DOMAIN_DAYS it waits). */
    public const int URGENT_DAYS = 7;

    /** How many rows the MCP tool returns at most. */
    public const int MCP_LIMIT = 200;

    /**
     * The rows of all sites, sorted (urgency, then the oldest first).
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites (heartbeat as stored JSON)
     * @param array<int, string> $customers id => name (Customers::names)
     * @return list<array{site_id: int, site: string, url: string, customer_id: ?int, customer: ?string, kind: string, what: string, title: ?string, status: ?string, count: ?int, since: ?int, expires: ?string, days: ?int, urgency: string, link: string, stale: bool}>
     */
    public static function build(array $sites, array $customers, int $now): array
    {
        $rows = [];
        foreach ($sites as $site) {
            array_push($rows, ...self::forSite($site, $customers, $now));
        }

        return self::sort($rows);
    }

    /** The rows of the sites in the console's database. @return list<array<string, mixed>> */
    public static function load(Db $db, int $now): array
    {
        return self::build(Console::sites($db), Customers::names($db), $now);
    }

    /**
     * The rows of one site (not sorted).
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     * @param array<int, string> $customers
     * @return list<array<string, mixed>>
     */
    public static function forSite(array $site, array $customers, int $now): array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
        $beat = is_array($beat) ? $beat : [];
        $url = rtrim((string) ($site['url'] ?? ''), '/');
        $url = preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~iD', $url) === 1 ? $url : '';
        $admin = $url !== '' ? $url . '/admin.php' : '';
        $lastSeen = self::time($site['last_seen'] ?? null);
        $stale = $lastSeen === null || $now - $lastSeen > self::STALE_AFTER;
        $customerId = isset($site['customer_id']) && (int) $site['customer_id'] > 0 ? (int) $site['customer_id'] : null;
        $base = ['site_id' => (int) $site['id'], 'site' => (string) ($site['name'] ?? '') !== '' ? (string) $site['name'] : $url, 'url' => $url,
            'customer_id' => $customerId, 'customer' => $customerId !== null ? ($customers[$customerId] ?? null) : null];
        $rows = [];
        $add = function (string $kind, string $what, string $urgency, string $path, array $extra = []) use (&$rows, $base, $admin, $stale): void {
            $rows[] = $extra + $base + ['kind' => $kind, 'what' => $what, 'title' => null, 'status' => null, 'count' => null, 'since' => null, 'expires' => null, 'days' => null,
                'urgency' => $urgency, 'link' => $admin !== '' ? $admin . $path : '', 'stale' => $stale];
        };

        // the site itself: the console's own check (current) and the reports it stopped sending
        if (isset($site['up']) && $site['up'] !== null && (int) $site['up'] === 0) {
            $add('site', 'site_down', 'urgent', '', ['since' => self::time($site['up_changed'] ?? null), 'link' => $url !== '' ? $url . '/' : '', 'stale' => false]);
        }
        if ($lastSeen !== null && $now - $lastSeen > Console::SILENT_AFTER) {
            $add('site', 'site_silent', 'urgent', '', ['since' => $lastSeen, 'link' => $url !== '' ? $url . '/' : '', 'stale' => false]);
        }
        if ($beat === []) {
            return $rows;
        }
        if (!empty($beat['update_problem'])) {
            $add('updates', 'update_failed', 'urgent', '?module=settings&tab=backups');
        }
        $security = is_array($beat['security_7_days'] ?? null) ? $beat['security_7_days'] : [];
        if (self::count($security['failed_sign_ins'] ?? null) >= Console::SIGN_IN_SPIKE) {
            $add('security', 'sign_in_attacks', 'urgent', '?module=settings&tab=firewall', ['count' => self::count($security['failed_sign_ins'])]);
        }
        if (self::count($security['events']['security.token_reuse'] ?? null) > 0) {
            $add('security', 'token_reuse', 'urgent', '?module=status', ['count' => self::count($security['events']['security.token_reuse'])]);
        }
        $watch = is_array($beat['domain_watch'] ?? null) && empty($beat['domain_watch']['local']) ? $beat['domain_watch'] : [];
        foreach (['certificate' => Console::CERTIFICATE_DAYS, 'domain' => Console::DOMAIN_DAYS] as $part => $limit) {
            $days = Console::daysLeft($watch[$part] ?? null, $now);
            if ($days !== null && $days < $limit) {
                $add('expiry', $part . '_expiring', $days < self::URGENT_DAYS ? 'urgent' : 'normal', '?module=status',
                    ['expires' => Console::date($watch[$part]['expires'] ?? null)?->format('Y-m-d'), 'days' => $days]);
            }
        }
        if (($site['status'] ?? '') === 'error') {
            $add('health', 'errors', 'urgent', '?module=status', ['count' => is_array($beat['problems'] ?? null) ? count(array_filter($beat['problems'], fn (mixed $p): bool => is_array($p) && ($p['status'] ?? '') === 'error')) ?: null : null]);
        }
        if (is_array($beat['jobs_failing'] ?? null) && $beat['jobs_failing'] !== []) {
            $add('health', 'jobs_failing', 'normal', '?module=status', ['count' => count($beat['jobs_failing'])]);
        }
        if (empty($beat['last_backup']) || $now - (int) $beat['last_backup'] > 8 * 86400) {
            $add('health', 'no_backup', 'normal', '?module=settings&tab=backups', ['since' => is_int($beat['last_backup'] ?? null) && $beat['last_backup'] > 0 ? $beat['last_backup'] : null]);
        }

        // what waits for people (3.16): requests to Claude – with their titles when the site shares them
        $requests = is_array($beat['requests'] ?? null) ? $beat['requests'] : null;
        if ($requests !== null && is_array($requests['items'] ?? null)) {
            foreach ($requests['items'] as $item) {
                if (!is_array($item) || self::count($item['id'] ?? null) === 0 || !isset(\Kaleta\Core\Requests::STATUSES[$item['status'] ?? ''])) {
                    continue;
                }
                $done = $item['status'] === 'done';
                $since = self::time($done ? ($item['updated_at'] ?? null) : ($item['created_at'] ?? null));
                $urgency = $done ? 'low' : ($since !== null && $now - $since > Heartbeat::REQUEST_WAIT_HOURS * 3600 ? 'urgent' : 'normal');
                $add('requests', 'request', $urgency, '?module=requests&action=detail&id=' . self::count($item['id']),
                    ['title' => Heartbeat::cleanTitle(is_string($item['title'] ?? null) ? $item['title'] : ''), 'status' => (string) $item['status'], 'since' => $since]);
            }
        } elseif ($requests !== null) {
            $open = self::count($requests['new'] ?? null) + self::count($requests['in_progress'] ?? null);
            if ($open > 0) {
                $hours = is_int($requests['oldest_waiting_hours'] ?? null) ? $requests['oldest_waiting_hours'] : null;
                $add('requests', 'requests_open', $hours !== null && $hours > Heartbeat::REQUEST_WAIT_HOURS ? 'urgent' : 'normal', '?module=requests',
                    ['count' => $open, 'since' => $hours !== null && $lastSeen !== null ? $lastSeen - $hours * 3600 : null]);
            }
            if (self::count($requests['done_to_review'] ?? null) > 0) {
                $add('requests', 'requests_done', 'low', '?' . self::query(PendingReview::URLS['requests_done']), ['count' => self::count($requests['done_to_review'])]);
            }
        }
        $enquiries = Console::enquiriesWaiting($beat); // a site before 3.16 sends only enquiries_unanswered
        if ($enquiries !== null && $enquiries > 0) {
            $add('enquiries', 'enquiries', 'normal', '?module=enquiries', ['count' => $enquiries]);
        }
        if (self::count($beat['bookings_pending'] ?? null) > 0) {
            $add('bookings', 'bookings', 'normal', '?module=bookings&view=vse&status=pending', ['count' => self::count($beat['bookings_pending'])]);
        }
        foreach (is_array($beat['pending_review'] ?? null) ? $beat['pending_review'] : [] as $kind => $n) {
            // requests Claude finished are in the requests above
            if ($kind !== 'requests_done' && isset(PendingReview::URLS[$kind]) && self::count($n) > 0) {
                $add('review', (string) $kind, $kind === 'staged_media' ? 'normal' : 'low', '?' . self::query(PendingReview::URLS[$kind]), ['count' => self::count($n)]);
            }
        }
        if (is_array($beat['audit'] ?? null) && ($findings = array_sum(array_map(self::count(...), $beat['audit']))) > 0) {
            $add('audit', 'audit', 'low', '?module=audit', ['count' => $findings]);
        }

        return $rows;
    }

    /**
     * Rows narrowed by the filters: kind, site (id), customer (id) and urgent only. The kind is left out when counting
     * the kinds for the filter bar (counts()).
     *
     * @param list<array<string, mixed>> $rows
     * @param array{kind?: string, site?: int, customer?: int, urgent?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, array $filters): array
    {
        return array_values(array_filter($rows, fn (array $r): bool => (($filters['kind'] ?? '') === '' || $r['kind'] === $filters['kind'])
            && (($filters['site'] ?? 0) === 0 || $r['site_id'] === $filters['site'])
            && (($filters['customer'] ?? 0) === 0 || $r['customer_id'] === $filters['customer'])
            && (empty($filters['urgent']) || $r['urgency'] === 'urgent')));
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int> kind => rows, every kind of KINDS (0 when none) */
    public static function counts(array $rows): array
    {
        $out = array_fill_keys(array_keys(self::KINDS), 0);
        foreach ($rows as $r) {
            $out[$r['kind']]++;
        }

        return $out;
    }

    /**
     * How many things wait on each site – the rows, a row with a count counted by it (3 new enquiries = 3); the site's
     * own state (down, silent, errors, backups, expiry) counts 1 each.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, int> site id => waiting
     */
    public static function waitingBySite(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $countable = in_array($r['kind'], ['requests', 'enquiries', 'bookings', 'review'], true) && is_int($r['count']);
            $out[$r['site_id']] = ($out[$r['site_id']] ?? 0) + ($countable ? $r['count'] : 1);
        }

        return $out;
    }

    /** The label of a row's subject (English): WHAT, or the review kind's label. @param array<string, mixed> $row */
    public static function label(array $row): string
    {
        return self::WHAT[$row['what']] ?? (PendingReview::KINDS[$row['what']][0] ?? (string) $row['what']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function sort(array $rows): array
    {
        $kinds = array_flip(array_keys(self::KINDS));
        usort($rows, fn (array $a, array $b): int => [self::URGENCY[$b['urgency']], $a['since'] === null ? 1 : 0, $a['since'] ?? 0, $kinds[$a['kind']], mb_strtolower($a['site'])]
            <=> [self::URGENCY[$a['urgency']], $b['since'] === null ? 1 : 0, $b['since'] ?? 0, $kinds[$b['kind']], mb_strtolower($b['site'])]);

        return $rows;
    }

    /** A non-negative count from a heartbeat (anything else is 0). */
    private static function count(mixed $n): int
    {
        return is_int($n) && $n > 0 ? $n : 0;
    }

    /** A Unix time from a DATETIME column or an ISO 8601 moment, null for anything else. */
    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time !== false && $time > 0 ? $time : null;
    }

    /** The query of a relative admin URL ('admin.php?module=x&y=1' => 'module=x&y=1', the #fragment kept). */
    private static function query(string $relative): string
    {
        return (string) preg_replace('/^admin\.php\?/', '', $relative);
    }
}
