<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\Response;

/**
 * Own traffic measurement without cookies.
 * Visitor = hash (IP + browser + salt valid for one day); the IP itself is not stored anywhere
 * and hashes older than two days are deleted, so readers cannot be tracked over time.
 *
 * 3.29 – visits confirmed by a browser: a page view no longer counts when the server renders the page (or serves it from the
 * page cache), but when the visitor's browser reports that the page is really shown to a person. image/vitals.js sends one
 * beacon to POST /hit once the page is visible (never while it is prerendered or opened in a background tab that nobody
 * looks at, never under browser automation), and hit() checks everything again on the server: the statistics are on, the
 * browser is not a known bot, the request is not a prefetch, it comes from a page of this site (Origin, Sec-Fetch-Site),
 * nobody is signed in, and the page path is one the server itself rendered (signed with the installation's key – a made-up
 * address never gets a row). Scrapers, AI crawlers, SEO tools, link previews of chat apps and social networks, uptime
 * checks and the team's own browsing therefore stay out of the numbers. The trade-off: visitors with JavaScript switched off
 * are not counted any more – a small share of people, against a large share of machines that render nothing.
 * Settings `stats_confirmed_since` holds the day this counting started (the Statistics screen and the monthly report say
 * that older numbers include some machines); old rows are never rewritten.
 */
final class Stats
{
    /**
     * Who is never counted, by the browser's own description (case-insensitive). Generic words first – most crawlers,
     * monitors and link previewers call themselves a bot, crawler, spider or preview – then well-known names without
     * such a word: HTTP libraries and headless browsers, search engines' and AI companies' fetchers, link previews of chat
     * apps and social networks, performance and uptime tools. tools/unit-tests.php checks it against real user agents
     * (bots matched, Chrome, Safari, Firefox, Edge, Samsung Internet and Android WebView not).
     */
    private const string BOTS = '/'
        . 'bot|crawl|spider|slurp|preview|monitor|scan|check|feed|headless|lighthouse'                       // generic words
        . '|curl|wget|python|java\/|http|okhttp|axios|node-fetch|phantomjs|puppeteer|playwright|selenium'      // libraries, headless and test browsers (Go-http-client, python-requests)
        . '|facebookexternalhit|meta-externalagent|bytespider|google-inspectiontool|google-extended|googleother'
        . '|mediapartners-google|google-read-aloud|applebot|dataprovider|chatgpt-user|perplexity-user|claude-user'
        . '|claude-web|anthropic-ai|cohere-ai|mistralai-user|ccbot|amazonbot'                                // search engines, ads and AI fetchers
        . '|whatsapp|telegrambot|slackbot|discordbot|skypeuripreview|linkedinbot|twitterbot|pinterest|embedly|iframely|vkshare|viber' // link previews
        . '|chrome-lighthouse|ptst|gtmetrix|pingdom|uptimerobot|statuscake|site24x7|newrelic|datadog'        // speed and uptime tools
        . '/i';

    /** A visitor (daily hash) adds no more page views after this many in one day – nobody reads 300 pages of a company site a day. */
    public const int DAILY_VIEWS = 300;

    /**
     * A visitor whose BURST_VIEWS page views (every block of them) came within BURST_SECONDS is a machine, not a person
     * reading: it adds no more page views that day (its counter jumps to DAILY_VIEWS).
     */
    public const int BURST_VIEWS = 10;
    public const int BURST_SECONDS = 10;

    /** phone | tablet | computer, by the browser's own description (nothing is stored about the device itself) */
    public static function device(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet|Kindle|Silk|(Android(?!.*Mobile))/i', $userAgent) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $userAgent) => 'phone',
            default => 'computer',
        };
    }

    /**
     * The built-in statistics are on: the Statistics feature is the only switch (3.2 – the old "stats" setting is no longer
     * read; migration 0073 switched the feature off where that setting was off). Real-user speed follows the same switch.
     */
    public static function isOn(App $app): bool
    {
        return self::enabled($app->settings());
    }

    /** The same, from the settings alone (the privacy text, the fleet heartbeat, MCP). */
    public static function enabled(\Kaleta\Core\Settings $settings): bool
    {
        return \Kaleta\Core\Extensions::isEnabled($settings, 'statistika');
    }

    /** A crawler, a monitoring tool or a test browser by its own description – never counted. */
    public static function isBot(string $userAgent): bool
    {
        return preg_match(self::BOTS, $userAgent) === 1;
    }

    /**
     * The path of the page being rendered as the statistics store it (ka_stat_stranky.cesta): with the installation's folder
     * and the language version, without the query string, at most 255 characters.
     */
    public static function pagePath(App $app): string
    {
        return mb_substr((string) parse_url($app->url(ltrim($app->request->path(), '/')), PHP_URL_PATH), 0, 255);
    }

    /**
     * The signature of a page view the server rendered: its path and the news item on it (0 = none), signed with the
     * installation's key. Front\Seo writes it next to the path into the beacon script's tag; hit() counts only what it signed.
     */
    public static function pageKey(App $app, string $path, int $idc): string
    {
        return substr(hash_hmac('sha256', 'hit|' . $path . '|' . $idc, (new Antispam($app->db(), $app->settings()))->key()), 0, 32);
    }

    /**
     * Why a beacon is not from a person looking at a page of this site – null when it may be. Pure (the request's server
     * values and the site's origin only), so tools/unit-tests.php checks every rule:
     *  - 'agent': no user agent, or a known bot (BOTS);
     *  - 'prefetch': the browser fetches ahead for a page nobody has seen yet (Sec-Purpose / Purpose: prefetch, prerender);
     *  - 'origin': the request comes from another site – the Origin header names a different host than this request's own
     *    (the Host header, a proxy's X-Forwarded-Host) or the configured site address. 'null' is no host to compare: a real
     *    browser sends it on its own same-origin beacon when the page's referrer policy is no-referrer (a server's or a
     *    meta tag's hardening, the private whistleblowing page), so it is left to Sec-Fetch-Site (N329-1) – a sandboxed
     *    frame of another site gets cross-site there;
     *  - 'site': Sec-Fetch-Site, when the browser sends it, is not same-origin.
     *
     * @param array<string, mixed> $server
     */
    public static function refusal(array $server, string $siteOrigin): ?string
    {
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (trim($ua) === '' || self::isBot($ua)) {
            return 'agent';
        }
        if (preg_match('/prefetch|prerender/i', (string) ($server['HTTP_SEC_PURPOSE'] ?? '') . ' ' . (string) ($server['HTTP_PURPOSE'] ?? '') . ' ' . (string) ($server['HTTP_X_PURPOSE'] ?? '') . ' ' . (string) ($server['HTTP_X_MOZ'] ?? ''))) {
            return 'prefetch';
        }
        $origin = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            $host = static fn (string $value): string => strtolower((string) (parse_url(str_contains($value, '//') ? $value : '//' . $value, PHP_URL_HOST) ?? ''));
            $own = array_filter([$host((string) ($server['HTTP_HOST'] ?? '')), $host((string) ($server['HTTP_X_FORWARDED_HOST'] ?? '')), $host($siteOrigin)], fn (string $h): bool => $h !== '');
            if ($host($origin) === '' || !in_array($host($origin), $own, true)) {
                return 'origin';
            }
        }
        $site = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));

        return $site !== '' && $site !== 'same-origin' ? 'site' : null;
    }

    /**
     * POST /hit: one beacon per page view from image/vitals.js, sent when the page is really shown. Always answers 204 –
     * a beacon that is not counted gets the same empty answer, so nobody learns which rule left it out.
     */
    public static function hit(App $app): Response
    {
        $none = new Response('', 204);
        $request = $app->request;
        $server = $request->serverValues();
        $path = $request->post('path');
        $idc = max(0, $request->postInt('news'));
        if (!self::isOn($app) || self::refusal($server, $request->origin()) !== null || \Kaleta\Core\Conversions::path($path) !== $path
            || !hash_equals(self::pageKey($app, $path, $idc), $request->post('key')) || $app->auth()->user() !== null) {
            return $none;
        }
        $query = $request->post('query');
        self::count($app, (string) ($server['HTTP_USER_AGENT'] ?? ''), $path, $idc > 0 ? $idc : null, $request->post('referrer'), str_starts_with($query, '?') ? $query : '');

        return $none;
    }

    /**
     * Counts one confirmed page view: a new visitor of the day is a visit, every view raises the day's views, the page's
     * views and the news item's reads; the device, the referring site and the campaign belong to the visit (its first view).
     * A visitor over DAILY_VIEWS or in a burst adds nothing.
     */
    private static function count(App $app, string $ua, string $path, ?int $idc, string $referrer, string $query): void
    {
        $db = $app->db();
        $today = date('Y-m-d');
        $antispam = new Antispam($db, $app->settings());
        $hash = substr(hash('sha256', $antispam->key() . $today . '|' . $app->request->ip() . '|' . $ua), 0, 32);

        $new = $db->run('INSERT IGNORE INTO {stat_navstevnici} (den, otisk, views, window_start) VALUES (?, ?, 1, ?)', [$today, $hash, time()])->rowCount() === 1;
        if (!$new && !self::addView($db, $today, $hash)) {
            return;
        }
        $settings = $app->settings();
        if ($settings->get('stats_confirmed_since') === '') {
            $settings->set('stats_confirmed_since', $today); // the first day of browser-confirmed counting (3.29)
        }
        $db->run('INSERT INTO {stat_dny} (den, navstevy, zobrazeni) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + VALUES(navstevy), zobrazeni = zobrazeni + 1', [$today, (int) $new]);
        if ($idc !== null) {
            // the news item's reads, only for a news item that is there (the id was signed together with the path)
            if ($db->run('UPDATE {novinky} SET visit = visit + 1 WHERE idc = ?', [$idc])->rowCount() === 1) {
                $db->run('INSERT INTO {stat_novinky} (den, idc, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $idc]);
            }
        }
        // views per URL (including the language version) – most read pages in the administration
        $db->run('INSERT INTO {stat_stranky} (den, cesta, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $path]);
        if ($new) {
            $source = strtolower((string) parse_url(mb_substr($referrer, 0, 2000), PHP_URL_HOST));
            $source = preg_replace('/^www\./', '', $source) ?? '';
            // the own site is the configured site URL, not the Host header – the client can write that however it wants
            $custom = preg_replace('/^www\./', '', strtolower((string) parse_url($app->request->origin(), PHP_URL_HOST))) ?? '';
            if ($source !== '' && $source !== $custom && preg_match('/^[a-z0-9.-]{1,100}$/D', $source)) {
                $db->run('INSERT INTO {stat_zdroje} (den, zdroj, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $source]);
            }
            // 2.3: the device of the visit, and the campaign of the page it started on – the page's own address as the browser shows it
            $db->run('INSERT INTO {stat_zarizeni} (den, zarizeni, navstevy) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + 1', [$today, self::device($ua)]);
            $campaign = Forms::campaignText(Forms::campaign($app->request->origin() . $path . mb_substr($query, 0, 2000), $app->request->origin()));
            if ($campaign !== '') {
                $db->run('INSERT INTO {stat_kampane} (den, kampan, navstevy) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + 1', [$today, mb_substr($campaign, 0, 255)]);
            }
        }
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {stat_navstevnici} WHERE den < ?', [Clock::day('-1 day')]);
            $db->run('DELETE FROM {stat_stranky} WHERE den < ?', [Clock::day('-400 days')]);
            $db->run('DELETE FROM {stat_kampane} WHERE den < ?', [Clock::day('-400 days')]);
        }
    }

    /**
     * The flood guard of a visitor seen before today: false when it may add no view (over DAILY_VIEWS, or a burst – then its
     * counter jumps to DAILY_VIEWS for the rest of the day). Every BURST_VIEWS views the window starts again, so a burst later
     * in the day is caught too.
     */
    private static function addView(\Kaleta\Core\Db $db, string $today, string $hash): bool
    {
        $row = $db->one('SELECT views, window_start FROM {stat_navstevnici} WHERE den = ? AND otisk = ?', [$today, $hash]);
        $views = (int) ($row['views'] ?? self::DAILY_VIEWS);
        if ($views >= self::DAILY_VIEWS) {
            return false;
        }
        $now = time();
        $windowStart = (int) ($row['window_start'] ?? 0);
        if (($views + 1) % self::BURST_VIEWS === 0) {
            if ($windowStart > 0 && $now - $windowStart < self::BURST_SECONDS) {
                $db->run('UPDATE {stat_navstevnici} SET views = ? WHERE den = ? AND otisk = ?', [self::DAILY_VIEWS, $today, $hash]);

                return false;
            }
            $windowStart = $now; // the next block of views starts now
        }
        $db->run('UPDATE {stat_navstevnici} SET views = views + 1, window_start = ? WHERE den = ? AND otisk = ?', [$windowStart > 0 ? $windowStart : $now, $today, $hash]);

        return true;
    }

    /**
     * The first day of browser-confirmed counting (3.29) when there are older numbers it changes the meaning of – for the
     * note on the Statistics screen and in the monthly report; null on a site that has counted this way from the start.
     */
    public static function confirmedSince(\Kaleta\Core\Settings $settings, \Kaleta\Core\Db $db): ?string
    {
        $since = $settings->get('stats_confirmed_since');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $since)) {
            return null;
        }

        return (int) $db->value('SELECT COUNT(*) FROM {stat_dny} WHERE den < ?', [$since]) > 0 ? $since : null;
    }
}
