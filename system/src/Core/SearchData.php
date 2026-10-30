<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Connectors\Bing;
use Kaleta\Connectors\Google;

/**
 * Search data (2.13): what Google Search Console and Bing Webmaster Tools know about the site – the queries people
 * typed, the pages they landed on, with clicks, impressions, CTR and the average position – and for Google the sitemaps
 * with their submitted and indexed counts as a light index coverage.
 *
 * The daily job search_data (Core\Scheduler) asks every connected engine for the last 28 days (Search Console's own
 * "last 28 days" ends three days back, where the data is final) and stores the answer as one snapshot per day in
 * ka_search_stats; snapshots are kept 16 months, so a later version can draw trends. The Statistics screen, get_stats and
 * the monthly report show the latest snapshot of the period. Nothing here is personal: queries and page addresses only.
 *
 * The response → rows mapping is pure (googleRows, bingRows) and tested without a network; the fake services in
 * tools/fake/search.php answer the same paths in tests.
 */
final class SearchData
{
    public const int DAYS = 28;
    public const int ROW_LIMIT = 250;
    public const int KEEP_MONTHS = 16;
    /** Rows shown per table in Statistics and get_stats. */
    public const int TOP = 25;

    public const array ENGINES = [Google::KEY, Bing::KEY];

    private const string GOOGLE_API = 'https://www.googleapis.com/webmasters/v3/sites';
    private const string BING_API = 'https://ssl.bing.com/webmaster/api.svc/json/';

    /* ---------- the job ---------- */

    /**
     * One run: every connected engine is asked, its snapshot of today replaced, old snapshots pruned. An engine that fails
     * is reported on its connection (Connections shows it) and does not stop the other; the job fails only when every
     * connected engine failed, so the scheduler's alert fires for a broken key, not for a site with nothing connected.
     */
    public static function run(App $app): string
    {
        $db = $app->db();
        $today = date('Y-m-d');
        $results = [];
        $errors = [];
        foreach (self::ENGINES as $engine) {
            if (!Connectors::isConnected($db, $engine)) {
                continue;
            }
            try {
                $rows = $engine === Google::KEY ? self::fetchGoogle($app) : self::fetchBing($app);
            } catch (\RuntimeException $e) {
                $errors[] = $engine . ': ' . $e->getMessage();
                $db->update('connectors', ['last_error' => mb_substr(t('Search data could not be loaded: %s', $e->getMessage()), 0, 255)], ['service' => $engine]);
                continue;
            }
            self::store($db, $today, $engine, $rows);
            $db->update('connectors', ['last_error' => ''], ['service' => $engine]);
            $results[] = $engine . ' ' . count($rows);
        }
        $db->run('DELETE FROM {search_stats} WHERE day < ?', [date('Y-m-d', strtotime('-' . self::KEEP_MONTHS . ' months'))]);
        if ($results === [] && $errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }

        return $results === [] ? 'nothing connected' : implode(', ', [...$results, ...$errors]);
    }

    /** The Search Console property from the Google connection's settings, or this site's address as a URL-prefix property. */
    public static function googleProperty(App $app): string
    {
        $site = trim(Connectors::config($app->db(), Google::KEY)['search_console_site'] ?? '');
        if ($site === '') {
            $site = self::ownAddress($app);
        }
        if ($site === '') {
            throw new \RuntimeException(t('Choose the Search Console property in Administration → Integrations.'));
        }

        return $site;
    }

    /** The site as it is registered in Bing Webmaster Tools, or this site's address. */
    public static function bingSite(App $app): string
    {
        $site = trim(Connectors::config($app->db(), Bing::KEY)['site_url'] ?? '');
        if ($site === '') {
            $site = self::ownAddress($app);
        }
        if ($site === '') {
            throw new \RuntimeException(t('Enter the site address in Administration → Integrations.'));
        }

        return $site;
    }

    private static function ownAddress(App $app): string
    {
        $url = trim($app->settings()->get('site_url'));

        return $url === '' ? '' : rtrim($url, '/') . '/';
    }

    /**
     * The Search Console properties the connected account may read – for the "Load my properties" button.
     *
     * @return list<string>
     */
    public static function properties(App $app): array
    {
        $answer = Connectors::request($app, Google::KEY, 'GET', self::GOOGLE_API, null, [], 'search.sites');
        if ($answer['error'] !== '') {
            throw new \RuntimeException($answer['error']);
        }
        $sites = [];
        foreach ((array) ($answer['json']['siteEntry'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['siteUrl'] ?? null) && $entry['siteUrl'] !== '') {
                $sites[] = mb_substr($entry['siteUrl'], 0, 500);
            }
        }
        sort($sites);

        return array_values(array_unique($sites));
    }

    /** @return list<array{kind: string, key: string, clicks: int, impressions: int, ctr: float, position: float}> */
    private static function fetchGoogle(App $app): array
    {
        $base = self::GOOGLE_API . '/' . rawurlencode(self::googleProperty($app));
        $end = date('Y-m-d', strtotime('-3 days'));
        $start = date('Y-m-d', strtotime($end . ' -' . (self::DAYS - 1) . ' days'));
        $rows = [];
        foreach (['query', 'page'] as $kind) {
            $answer = Connectors::request($app, Google::KEY, 'POST', $base . '/searchAnalytics/query', ['startDate' => $start, 'endDate' => $end, 'dimensions' => [$kind], 'rowLimit' => self::ROW_LIMIT], [], 'search.' . $kind);
            if ($answer['error'] !== '') {
                throw new \RuntimeException($answer['error']);
            }
            $rows = [...$rows, ...self::googleRows($answer['json'] ?? [], $kind)];
        }
        // the sitemaps are a bonus: when only they fail, the queries and pages still count (the call is in the log)
        $answer = Connectors::request($app, Google::KEY, 'GET', $base . '/sitemaps', null, [], 'search.sitemaps');
        if ($answer['error'] === '') {
            $rows = [...$rows, ...self::googleSitemaps($answer['json'] ?? [])];
        }

        return $rows;
    }

    /** @return list<array{kind: string, key: string, clicks: int, impressions: int, ctr: float, position: float}> */
    private static function fetchBing(App $app): array
    {
        $site = self::bingSite($app);
        $since = strtotime('-' . self::DAYS . ' days');
        $rows = [];
        foreach (['query' => 'GetQueryStats', 'page' => 'GetPageStats'] as $kind => $method) {
            $answer = Connectors::request($app, Bing::KEY, 'GET', self::BING_API . $method . '?' . http_build_query(['siteUrl' => $site]), null, [], 'search.' . $kind);
            if ($answer['error'] !== '') {
                throw new \RuntimeException($answer['error']);
            }
            $rows = [...$rows, ...self::bingRows($answer['json'] ?? [], $kind, $since)];
        }

        return $rows;
    }

    /* ---------- response → rows (pure) ---------- */

    /**
     * Search Console searchAnalytics.query: rows with keys [the query or page], clicks, impressions, ctr (a fraction) and
     * position. The CTR is stored in per cent.
     *
     * @param array<mixed> $json
     * @return list<array{kind: string, key: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public static function googleRows(array $json, string $kind): array
    {
        $rows = [];
        foreach ((array) ($json['rows'] ?? []) as $r) {
            $key = is_array($r) && is_array($r['keys'] ?? null) ? (string) ($r['keys'][0] ?? '') : '';
            if ($key === '') {
                continue;
            }
            $rows[] = self::row($kind, $key, (int) round((float) ($r['clicks'] ?? 0)), (int) round((float) ($r['impressions'] ?? 0)), round(100 * (float) ($r['ctr'] ?? 0), 2), round((float) ($r['position'] ?? 0), 1));
        }

        return $rows;
    }

    /**
     * Search Console sitemaps.list: each sitemap with what it submitted and what got indexed (summed over its content
     * types – web, image, video); kind sitemap keeps them as impressions and clicks.
     *
     * @param array<mixed> $json
     * @return list<array{kind: string, key: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public static function googleSitemaps(array $json): array
    {
        $rows = [];
        foreach ((array) ($json['sitemap'] ?? []) as $s) {
            if (!is_array($s) || !is_string($s['path'] ?? null) || $s['path'] === '') {
                continue;
            }
            $submitted = 0;
            $indexed = 0;
            foreach ((array) ($s['contents'] ?? []) as $c) {
                $submitted += (int) ($c['submitted'] ?? 0);
                $indexed += (int) ($c['indexed'] ?? 0);
            }
            $rows[] = self::row('sitemap', $s['path'], $indexed, $submitted, $submitted > 0 ? round(100 * $indexed / $submitted, 2) : 0.0, 0.0);
        }

        return $rows;
    }

    /**
     * Bing GetQueryStats / GetPageStats: {"d": [{Query, Clicks, Impressions, AvgImpressionPosition, Date}]} – one row per
     * query (or page) and day, the date as /Date(ms)/ or ISO. Days before $since are left out, the rest is summed per key
     * with the position weighted by impressions, so it means the same as Google's.
     *
     * @param array<mixed> $json
     * @return list<array{kind: string, key: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public static function bingRows(array $json, string $kind, ?int $since = null): array
    {
        $sums = [];
        $list = is_array($json['d'] ?? null) ? $json['d'] : (array_is_list($json) ? $json : []);
        foreach ($list as $r) {
            if (!is_array($r)) {
                continue;
            }
            $key = (string) ($r['Query'] ?? $r['Page'] ?? $r['Url'] ?? '');
            if ($key === '' || ($since !== null && ($day = self::bingDate($r['Date'] ?? null)) !== null && $day < $since)) {
                continue;
            }
            $clicks = (int) round((float) ($r['Clicks'] ?? 0));
            $impressions = (int) round((float) ($r['Impressions'] ?? 0));
            $sums[$key] ??= ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0];
            $sums[$key]['clicks'] += $clicks;
            $sums[$key]['impressions'] += $impressions;
            $sums[$key]['weighted'] += (float) ($r['AvgImpressionPosition'] ?? $r['AvgClickPosition'] ?? 0) * max(1, $impressions);
        }
        $rows = [];
        foreach ($sums as $key => $s) {
            $rows[] = self::row($kind, (string) $key, $s['clicks'], $s['impressions'], $s['impressions'] > 0 ? round(100 * $s['clicks'] / $s['impressions'], 2) : 0.0, round($s['weighted'] / max(1, $s['impressions']), 1));
        }
        usort($rows, fn (array $a, array $b): int => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);

        return array_slice($rows, 0, self::ROW_LIMIT);
    }

    /** Bing's dates: "/Date(1696291200000-0700)/" (WCF) or an ISO string; null when unreadable. */
    public static function bingDate(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/Date\((-?\d+)/', $value, $m) === 1) {
            return intdiv((int) $m[1], 1000);
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    /** @return array{kind: string, key: string, clicks: int, impressions: int, ctr: float, position: float} */
    private static function row(string $kind, string $key, int $clicks, int $impressions, float $ctr, float $position): array
    {
        return ['kind' => $kind, 'key' => mb_substr(trim($key), 0, 255), 'clicks' => max(0, $clicks), 'impressions' => max(0, $impressions), 'ctr' => max(0.0, min(100.0, $ctr)), 'position' => max(0.0, min(9999.9, $position))];
    }

    /* ---------- storage and reading ---------- */

    /** Today's snapshot of an engine is replaced as a whole. @param list<array{kind: string, key: string, clicks: int, impressions: int, ctr: float, position: float}> $rows */
    private static function store(Db $db, string $day, string $engine, array $rows): void
    {
        $db->run('DELETE FROM {search_stats} WHERE engine = ? AND day = ?', [$engine, $day]);
        foreach (array_chunk($rows, 100) as $chunk) {
            $params = [];
            foreach ($chunk as $r) {
                array_push($params, $day, $engine, $r['kind'], $r['key'], $r['clicks'], $r['impressions'], $r['ctr'], $r['position']);
            }
            $db->run('INSERT INTO {search_stats} (day, engine, kind, `key`, clicks, impressions, ctr, position) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        }
    }

    /**
     * For Statistics and get_stats: per engine the latest snapshot of the period – its day, the top queries and pages,
     * for Google the sitemaps; null for an engine without data.
     *
     * @return array<string, array{day: string, covers_days: int, queries: list<array<string, mixed>>, pages: list<array<string, mixed>>, sitemaps?: list<array{path: string, submitted: int, indexed: int}>}|null>
     */
    public static function report(Db $db, string $since): array
    {
        $out = array_fill_keys(self::ENGINES, null);
        foreach (self::ENGINES as $engine) {
            try {
                $day = $db->value('SELECT MAX(day) FROM {search_stats} WHERE engine = ? AND day >= ?', [$engine, $since]);
            } catch (\Throwable) {
                return $out; // before the 2.13 migration
            }
            if ($day === null) {
                continue;
            }
            $top = fn (string $kind, string $name): array => array_map(fn (array $r): array => [$name => (string) $r['key'], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'ctr' => (float) $r['ctr'], 'position' => (float) $r['position']],
                $db->all('SELECT `key`, clicks, impressions, ctr, position FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY clicks DESC, impressions DESC, `key` LIMIT ' . self::TOP, [$engine, $kind, $day]));
            $out[$engine] = ['day' => (string) $day, 'covers_days' => self::DAYS, 'queries' => $top('query', 'query'), 'pages' => $top('page', 'page')];
            if ($engine === Google::KEY) {
                $out[$engine]['sitemaps'] = array_map(fn (array $r): array => ['path' => (string) $r['key'], 'submitted' => (int) $r['impressions'], 'indexed' => (int) $r['clicks']],
                    $db->all('SELECT `key`, impressions, clicks FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? ORDER BY `key` LIMIT 50', [$engine, 'sitemap', $day]));
            }
        }

        return $out;
    }

    /**
     * For the monthly report: the top queries of the month's last snapshot, per engine – counts and words only.
     *
     * @return list<array{engine: string, query: string, clicks: int}>
     */
    public static function topQueries(Db $db, string $fromDay, string $toDay, int $limit = 3): array
    {
        $out = [];
        foreach (self::ENGINES as $engine) {
            try {
                $day = $db->value('SELECT MAX(day) FROM {search_stats} WHERE engine = ? AND kind = ? AND day >= ? AND day < ?', [$engine, 'query', $fromDay, $toDay]);
            } catch (\Throwable) {
                return [];
            }
            if ($day === null) {
                continue;
            }
            foreach ($db->all('SELECT `key`, clicks FROM {search_stats} WHERE engine = ? AND kind = ? AND day = ? AND clicks > 0 ORDER BY clicks DESC, impressions DESC, `key` LIMIT ' . max(1, $limit), [$engine, 'query', $day]) as $r) {
                $out[] = ['engine' => $engine, 'query' => (string) $r['key'], 'clicks' => (int) $r['clicks']];
            }
        }

        return $out;
    }
}
