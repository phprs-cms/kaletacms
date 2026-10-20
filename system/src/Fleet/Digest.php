<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Demo;
use Kaleta\Core\Language;
use Kaleta\Core\Mail;
use Kaleta\Core\Settings;

/**
 * The weekly digest of the fleet console (3.17, Fleet → Alerts and digest; off by default): on Monday morning one e-mail
 * about the week before, grouped by customer (Console::customerOf) – the sites that need attention and why, what waits
 * (counts), the down periods and the uptime of the week, the versions installed. Plain text with plain links to the
 * console and to each site's administration.
 *
 * The titles of requests to Claude are left out unless the owner turns them on (fleet_digest_titles, off by default):
 * the e-mail leaves the console, the titles were written on the sites. Never an enquiry, a name or an e-mail address
 * from a site – the console does not have them.
 */
final class Digest
{
    /** From this hour on Monday (the console's time zone) the digest of the week before goes out. */
    public const int HOUR = 7;

    /** Whether the digest is due: on, Monday from HOUR, and not sent this ISO week yet. Pure. */
    public static function due(Settings $s, \DateTimeImmutable $now): bool
    {
        return $s->bool('fleet_digest') && (int) $now->format('N') === 1 && (int) $now->format('G') >= self::HOUR && $s->get('fleet_digest_sent') !== $now->format('o-\WW');
    }

    /** The background job fleet_digest (hourly). */
    public static function runIfDue(App $app): string
    {
        $s = $app->settings();
        $now = new \DateTimeImmutable();
        if (!self::due($s, $now) || Demo::active()) {
            return 'not due';
        }
        $recipients = AlertRules::recipients($s);
        $s->set('fleet_digest_sent', $now->format('o-\WW')); // once a week, also when there is nobody to send it to
        if ($recipients === []) {
            return 'no address';
        }
        $end = $now->setTime(0, 0);
        [$subject, $text] = self::compose(self::build($app, $end->modify('-7 days'), $end), $s, AlertRules::consoleUrl($app), $s->bool('fleet_digest_titles'));
        foreach ($recipients as $recipient) {
            Mail::send($s, $recipient, $subject, $text);
        }

        return 'sent to ' . count($recipients);
    }

    /**
     * What the digest says about the week [$from, $to): customer => list of sites, each with its attention now, what waits,
     * the down periods, the uptime of the week and the versions installed. Every site is in it – a quiet week is news too.
     *
     * @return array{from: string, to: string, customers: array<string, list<array<string, mixed>>>}
     */
    public static function build(App $app, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $db = $app->db();
        $now = time();
        $customers = [];
        foreach (Console::overview($db, $now) as $site) {
            $id = (int) $site['id'];
            $beat = $site['beat'];
            $requests = is_array($beat['requests'] ?? null) ? $beat['requests'] : null;
            $days = History::days($db, $id, $from->format('Y-m-d'), $to->modify('-1 day')->format('Y-m-d'));
            $customers[Console::customerOf($site)][] = [
                'id' => $id, 'name' => (string) $site['name'], 'url' => (string) $site['url'], 'reasons' => $site['reasons'],
                'enquiries' => Console::enquiriesWaiting($beat),
                'requests' => $requests === null ? null : ['new' => (int) $requests['new'], 'in_progress' => (int) $requests['in_progress'], 'done_to_review' => (int) $requests['done_to_review']],
                'request_titles' => array_values(array_map(fn (array $item): string => (string) $item['title'],
                    array_filter(is_array($requests['items'] ?? null) ? $requests['items'] : [], fn (mixed $item): bool => is_array($item) && in_array($item['status'] ?? '', ['new', 'in_progress'], true)))),
                'review' => is_array($beat['pending_review'] ?? null) ? (int) array_sum(array_filter($beat['pending_review'], 'is_int')) : null,
                'bookings' => is_int($beat['bookings_pending'] ?? null) ? $beat['bookings_pending'] : null,
                'uptime' => History::uptime($days),
                'incidents' => History::incidents($db, $id, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $now),
                'versions' => History::versions($db, $id, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')),
            ];
        }
        ksort($customers, SORT_STRING); // the sites without a customer ('') first

        return ['from' => $from->format('Y-m-d'), 'to' => $to->modify('-1 day')->format('Y-m-d'), 'customers' => $customers];
    }

    /**
     * The subject and the plain text in the console's default language. Pure; request titles only with $titles.
     *
     * @param array{from: string, to: string, customers: array<string, list<array<string, mixed>>>} $data from build()
     * @return array{0: string, 1: string}
     */
    public static function compose(array $data, Settings $s, string $consoleUrl, bool $titles): array
    {
        return Language::runWith(Language::defaults($s), function () use ($data, $s, $consoleUrl, $titles): array {
            $labels = require KALETA_SYSTEM . '/views/admin/fleet/reasons.php'; // in the e-mail's language
            $sites = array_merge(...array_values($data['customers']) ?: [[]]);
            $needing = count(array_filter($sites, fn (array $site): bool => $site['reasons'] !== []));
            $period = format_date($data['from']) . ' – ' . format_date($data['to']);
            $lines = [t('The week of your sites, %s.', $period), $needing === 0 ? t('No site needs attention.') : t('%d of %d sites need attention.', $needing, count($sites)), ''];
            foreach ($data['customers'] as $customer => $customerSites) {
                if (count($data['customers']) > 1 || $customer !== '') {
                    $lines[] = '== ' . ($customer !== '' ? $customer : t('Sites without a customer')) . ' ==';
                    $lines[] = '';
                }
                foreach ($customerSites as $site) {
                    $lines[] = ($site['name'] !== '' ? $site['name'] . ' – ' : '') . $site['url'];
                    $lines[] = '  ' . ($site['reasons'] === [] ? t('Nothing needs attention.') : t('Needs attention:') . ' ' . implode(', ', array_map(fn (string $r): string => $labels[$r] ?? $r, $site['reasons'])));
                    $waits = array_filter([
                        $site['enquiries'] !== null && $site['enquiries'] > 0 ? t('%d enquiries waiting', $site['enquiries']) : null,
                        $site['requests'] !== null && $site['requests']['new'] + $site['requests']['in_progress'] > 0 ? t('%d requests open', $site['requests']['new'] + $site['requests']['in_progress']) : null,
                        $site['requests'] !== null && $site['requests']['done_to_review'] > 0 ? t('%d done requests to review', $site['requests']['done_to_review']) : null,
                        $site['review'] !== null && $site['review'] > 0 ? t('%d waiting for review', $site['review']) : null,
                        $site['bookings'] !== null && $site['bookings'] > 0 ? t('%d bookings to confirm', $site['bookings']) : null,
                    ]);
                    $lines[] = '  ' . t('Waiting:') . ' ' . ($waits === [] ? t('nothing') : implode(', ', $waits));
                    if ($titles) {
                        foreach (array_slice($site['request_titles'], 0, 10) as $title) {
                            $lines[] = '    · ' . $title;
                        }
                    }
                    $uptime = $site['uptime']['percent'] === null ? t('not measured') : format_number($site['uptime']['percent'], 2) . ' %';
                    $minutes = array_sum(array_column($site['incidents'], 'minutes'));
                    $lines[] = '  ' . t('Uptime of the week: %s', $uptime) . ' · ' . ($site['incidents'] === [] ? t('no outage') : t('%d outages, %d minutes down', count($site['incidents']), $minutes));
                    if ($site['versions'] !== []) {
                        $lines[] = '  ' . t('Updated to %s', implode(', ', array_column($site['versions'], 'version')));
                    }
                    $lines[] = '  ' . $consoleUrl . '/admin.php?module=fleet&action=detail&id=' . $site['id'];
                    $lines[] = '  ' . rtrim($site['url'], '/') . '/admin.php';
                    $lines[] = '';
                }
            }
            $lines[] = t('You get this digest every Monday. Switch it off in Fleet → Alerts and digest:') . ' ' . $consoleUrl . '/admin.php?module=fleet&action=alerts';

            return [t('%s: the week of your sites (%s)', $s->get('site_name'), $period), implode("\n", $lines)];
        }, 'admin-');
    }
}
