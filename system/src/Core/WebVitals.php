<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Front\Stats;

/**
 * Real-user speed (2.8): Core Web Vitals – LCP, CLS and INP – measured by image/vitals.js in visitors' browsers and sent
 * once per page view with navigator.sendBeacon to POST /vitals. The same cookie-free rules as the built-in statistics
 * (Front\Stats): nothing about the visitor is stored, only aggregated numbers per page path per day.
 *
 * Percentiles need the distribution, not an average, so each metric is kept as a small histogram with fixed buckets
 * (BUCKETS = the upper edge of every bucket, the last one is open): one row of ka_web_vitals per day, path, metric and
 * bucket, with the number of samples in it. The 75th percentile (the number Google rates a page by) is the upper edge of
 * the bucket the 75th sample falls into – an upper estimate, never flattering. Google's thresholds are bucket edges, so the
 * rating is exact. Rows older than 400 days are deleted like the rest of the statistics.
 */
final class WebVitals
{
    /** Metric => upper edges of the histogram buckets (LCP and INP in ms, CLS unitless); values above the last edge go to one open bucket. */
    public const array BUCKETS = [
        'lcp' => [500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 5000, 6000, 8000],
        'cls' => [0.01, 0.025, 0.05, 0.075, 0.1, 0.15, 0.2, 0.25, 0.3, 0.4, 0.5],
        'inp' => [50, 100, 150, 200, 250, 300, 400, 500, 600, 800, 1000],
    ];

    /** Google's thresholds: metric => [good up to, needs improvement up to]; above = poor. */
    public const array THRESHOLDS = ['lcp' => [2500, 4000], 'cls' => [0.1, 0.25], 'inp' => [200, 500]];

    /** The highest value a beacon may report (anything above is a broken clock or a forged request). */
    private const array MAXIMUM = ['lcp' => 60000, 'cls' => 10, 'inp' => 60000];

    /** The site audit flags a page whose p75 LCP got worse by more than this share against the previous period, with at least MIN_SAMPLES in both. */
    public const float WORSE_BY = 0.25;
    public const int MIN_SAMPLES = 30;

    /** Index of the bucket a value falls into (the open last bucket = count(BUCKETS[metric])). */
    public static function bucket(string $metric, float $value): int
    {
        foreach (self::BUCKETS[$metric] as $i => $edge) {
            if ($value <= $edge) {
                return $i;
            }
        }

        return count(self::BUCKETS[$metric]);
    }

    /**
     * The p-th percentile estimated from bucket counts: the upper edge of the bucket holding the p-th sample; for the open
     * last bucket its lower edge (the value is at least that). Null without samples.
     *
     * @param array<int, int> $counts bucket index => samples
     */
    public static function percentile(string $metric, array $counts, float $p = 0.75): ?float
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return null;
        }
        $edges = self::BUCKETS[$metric];
        $rank = max(1, (int) ceil($total * $p));
        $seen = 0;
        for ($i = 0; $i <= count($edges); $i++) {
            $seen += $counts[$i] ?? 0;
            if ($seen >= $rank) {
                return (float) ($edges[$i] ?? $edges[count($edges) - 1]);
            }
        }

        return (float) $edges[count($edges) - 1];
    }

    /** good | needs_improvement | poor by Google's thresholds. */
    public static function rating(string $metric, float $value): string
    {
        [$good, $acceptable] = self::THRESHOLDS[$metric];

        return $value <= $good ? 'good' : ($value <= $acceptable ? 'needs_improvement' : 'poor');
    }

    /** The audit rule: a slowdown worth reporting – both periods measured enough, and p75 LCP worse by more than WORSE_BY. */
    public static function isRegression(?float $current, ?float $previous, int $samplesNow, int $samplesBefore): bool
    {
        return $current !== null && $previous !== null && $previous > 0 && $samplesNow >= self::MIN_SAMPLES && $samplesBefore >= self::MIN_SAMPLES
            && $current > $previous * (1 + self::WORSE_BY);
    }

    /** POST /vitals: one beacon per page view (image/vitals.js); always answers 204, a bad or unwanted beacon is simply not counted. */
    public static function record(App $app): Response
    {
        $request = $app->request;
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $path = $request->post('path');
        $values = [];
        foreach (array_keys(self::BUCKETS) as $metric) {
            $raw = $request->post($metric);
            if ($raw !== '' && is_numeric($raw) && (float) $raw >= 0 && (float) $raw <= self::MAXIMUM[$metric]) {
                $values[$metric] = (float) $raw;
            }
        }
        if (!Stats::isOn($app) || $values === [] || $ua === '' || Stats::isBot($ua) || !preg_match('#^/[^\s?\#]{0,254}$#D', $path)) {
            return new Response('', 204);
        }
        $db = $app->db();
        $antispam = new Antispam($db, $app->settings());
        if ($antispam->count($request->ip(), 'vitals', 0, 60) >= 60) {
            return new Response('', 204);
        }
        $antispam->write($request->ip(), 'vitals', 0);
        // only pages the statistics have seen (the page view is counted before the beacon arrives) – no rows for made-up addresses
        if ((int) $db->value('SELECT COUNT(*) FROM {stat_stranky} WHERE cesta = ? AND den >= ?', [$path, Clock::day('-1 day')]) === 0) {
            return new Response('', 204);
        }
        $today = date('Y-m-d');
        foreach ($values as $metric => $value) {
            $db->run('INSERT INTO {web_vitals} (day, path, metric, bucket, samples) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE samples = samples + 1',
                [$today, $path, $metric, self::bucket($metric, $value)]);
        }
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {web_vitals} WHERE day < ?', [Clock::day('-400 days')]);
        }

        return new Response('', 204);
    }

    /**
     * Histograms per page for a period: path => metric => bucket index => samples.
     *
     * @return array<string, array<string, array<int, int>>>
     */
    public static function histograms(Db $db, string $since, ?string $until = null): array
    {
        $rows = $db->all('SELECT path, metric, bucket, SUM(samples) AS n FROM {web_vitals} WHERE day >= ?' . ($until !== null ? ' AND day < ?' : '') . ' GROUP BY path, metric, bucket',
            $until !== null ? [$since, $until] : [$since]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['path']][$r['metric']][(int) $r['bucket']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Pages with their p75 values and ratings for the Statistics screen and get_stats – the most measured first.
     *
     * @return list<array{path: string, samples: int, lcp_p75: ?float, lcp_rating: ?string, cls_p75: ?float, cls_rating: ?string, inp_p75: ?float, inp_rating: ?string}>
     */
    public static function pages(Db $db, string $since, int $limit = 20): array
    {
        $out = [];
        foreach (self::histograms($db, $since) as $path => $metrics) {
            $row = ['path' => $path, 'samples' => (int) array_sum($metrics['lcp'] ?? [])];
            foreach (array_keys(self::BUCKETS) as $metric) {
                $p75 = self::percentile($metric, $metrics[$metric] ?? []);
                $row[$metric . '_p75'] = $p75;
                $row[$metric . '_rating'] = $p75 === null ? null : self::rating($metric, $p75);
            }
            $out[] = $row;
        }
        usort($out, fn (array $a, array $b): int => [$b['samples'], $a['path']] <=> [$a['samples'], $b['path']]);

        return array_slice($out, 0, $limit);
    }

    /**
     * Pages whose p75 LCP got worse against the previous period of the same length (the site audit).
     *
     * @return list<array{path: string, current: float, previous: float, samples: int}>
     */
    public static function regressions(Db $db, int $days = 30): array
    {
        $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $before = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));
        $now = self::histograms($db, $since);
        $out = [];
        foreach (self::histograms($db, $before, $since) as $path => $metrics) {
            $previousCounts = $metrics['lcp'] ?? [];
            $currentCounts = $now[$path]['lcp'] ?? [];
            $current = self::percentile('lcp', $currentCounts);
            $previous = self::percentile('lcp', $previousCounts);
            if (self::isRegression($current, $previous, (int) array_sum($currentCounts), (int) array_sum($previousCounts))) {
                $out[] = ['path' => $path, 'current' => (float) $current, 'previous' => (float) $previous, 'samples' => (int) array_sum($currentCounts)];
            }
        }
        usort($out, fn (array $a, array $b): int => $b['current'] / $b['previous'] <=> $a['current'] / $a['previous']);

        return $out;
    }
}
