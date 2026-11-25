<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.54 (C): what a newsletter did, as one aggregate line – sent, failed, skipped, unsubscribed through its links and
 * visits from it. Counts only, never who: the unsubscriptions are a counter on the newsletter (ka_newsletters.
 * unsubscribed_count), the visits are the cookie-free campaign statistics (ka_stat_kampane) of its campaign name.
 *
 * Visits are null – not 0 – when they cannot be known: the frozen e-mail carries no campaign name (fewer than
 * Mailing::TAG_MIN reachable recipients, or no link to the site), the statistics are off, the newsletter has not started,
 * or it started longer ago than the statistics are kept (STATS_DAYS, Front\Stats deletes older days).
 */
final class NewsletterResults
{
    /** How long the campaign statistics are kept (Front\Stats deletes ka_stat_kampane rows older than this). */
    public const int STATS_DAYS = 400;

    /** The campaign text of a newsletter's visits as the statistics store it (source / medium / campaign). */
    private const string PREFIX = 'newsletter / email / ';

    /**
     * The results of one newsletter.
     *
     * @param array<string, mixed> $n a row of ka_newsletters (with html, or with the tagged flag of Mailing::all)
     * @return array{sent: int, failed: int, skipped: int, unsubscribed: int, visits: ?int}
     */
    public static function of(App $app, array $n): array
    {
        $visits = null;
        $since = self::since($app, $n);
        if ($since !== null) {
            $visits = (int) $app->db()->value('SELECT COALESCE(SUM(navstevy), 0) FROM {stat_kampane} WHERE den >= ? AND kampan = ?', [$since, self::campaignText($n)]);
        }

        return self::line($n, $visits);
    }

    /**
     * The results of several newsletters (list_newsletters) with one grouped query for every visit count.
     *
     * @param list<array<string, mixed>> $rows rows of Mailing::all()
     * @return array<int, array{sent: int, failed: int, skipped: int, unsubscribed: int, visits: ?int}> by newsletter id
     */
    public static function ofMany(App $app, array $rows): array
    {
        $days = [];
        foreach ($rows as $n) {
            $since = self::since($app, $n);
            if ($since !== null) {
                $days[(int) $n['id']] = $since;
            }
        }
        $visits = $days === [] ? [] : $app->db()->pairs('SELECT kampan, SUM(navstevy) FROM {stat_kampane} WHERE den >= ? AND kampan LIKE ? GROUP BY kampan', [min($days), self::PREFIX . '%']);
        $results = [];
        foreach ($rows as $n) {
            $id = (int) $n['id'];
            $results[$id] = self::line($n, isset($days[$id]) ? (int) ($visits[self::campaignText($n)] ?? 0) : null);
        }

        return $results;
    }

    /**
     * The first day whose visits belong to the newsletter, or null when its visits cannot be known.
     *
     * @param array<string, mixed> $n
     */
    private static function since(App $app, array $n): ?string
    {
        $started = (string) ($n['started_at'] ?? '');
        $tagged = array_key_exists('html', $n) ? str_contains((string) $n['html'], self::campaignNeedle($n)) : (bool) ($n['tagged'] ?? false);
        if ($started === '' || !$tagged || !StatsGate::isOn($app)) {
            return null;
        }
        $day = substr($started, 0, 10);

        return $day >= Clock::day('-' . self::STATS_DAYS . ' days') ? $day : null;
    }

    /**
     * 3.54 review: what the frozen e-mail carries when Mailing::campaignUrl() tagged its links with the newsletter's own
     * campaign name – any other utm_campaign= (a link the author tagged himself) says nothing about its visits.
     *
     * @param array<string, mixed> $n
     */
    public static function campaignNeedle(array $n): string
    {
        return 'utm_campaign=' . rawurlencode(Mailing::campaignName((string) $n['subject'], (int) $n['id']));
    }

    /**
     * The campaign text of the newsletter's visits – what Front\Stats stores for a visit through one of its links.
     *
     * @param array<string, mixed> $n
     */
    public static function campaignText(array $n): string
    {
        return self::PREFIX . Mailing::campaignName((string) $n['subject'], (int) $n['id']);
    }

    /**
     * @param array<string, mixed> $n
     * @return array{sent: int, failed: int, skipped: int, unsubscribed: int, visits: ?int}
     */
    private static function line(array $n, ?int $visits): array
    {
        return ['sent' => (int) ($n['sent_count'] ?? 0), 'failed' => (int) ($n['failed_count'] ?? 0), 'skipped' => (int) ($n['skipped_count'] ?? 0),
            'unsubscribed' => (int) ($n['unsubscribed_count'] ?? 0), 'visits' => $visits];
    }
}
