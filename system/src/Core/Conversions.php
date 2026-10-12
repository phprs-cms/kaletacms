<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Front\Stats;

/**
 * Contact clicks as leads (2.12): a click on a phone number, an e-mail address or a WhatsApp link is a lead like a sent form,
 * so image/web.js reports it with navigator.sendBeacon to POST /conversion (the older /konverze too) – the way image/vitals.js reports speed (Core\WebVitals).
 * The same cookie-free rules as the built-in statistics (Front\Stats): only while they are on, never for bots or signed-in
 * users (Front\Seo::head() hands the endpoint to the script only then, and the endpoint checks again), and nothing about
 * the visitor is stored – one row of ka_stat_konverze per day, page path and type with the count.
 *
 * A visitor who clicks the same number on the same page three times is one lead: a click counts once per type, page,
 * visitor and day. The visitor is told apart by the statistics' daily fingerprint (IP, browser and a salt that changes
 * every day), here hashed together with the page and the type; the mark lives among the visitor hashes in
 * ka_stat_navstevnici, is never stored with the counted row and is deleted with the hashes the next day.
 */
final class Conversions
{
    /** What a counted link points to: tel: | mailto: | whatsapp (wa.me, api.whatsapp.com or the whatsapp: scheme). */
    public const array TYPES = ['tel', 'mailto', 'whatsapp'];

    /** Type => the key the Statistics screen, get_stats (contact_clicks) and the monthly report use. */
    public const array KEYS = ['tel' => 'calls', 'mailto' => 'emails', 'whatsapp' => 'whatsapp'];

    /** A link that counts – Front\Kernel keeps image/web.js on a page with one when the statistics are on (the same test as in the script). */
    public const string LINK_PATTERN = '/href="(?:tel:|mailto:|https?:\/\/(?:wa\.me|api\.whatsapp\.com)\/|whatsapp:)/i';

    /** Rows older than this are deleted, like the rest of the statistics. */
    private const int KEEP_DAYS = 400;

    /** The type of a beacon; null when it is not one of TYPES (anything else is simply not counted). */
    public static function type(string $raw): ?string
    {
        $type = strtolower(trim($raw));

        return in_array($type, self::TYPES, true) ? $type : null;
    }

    /**
     * The page path of a beacon as the statistics store it (Front\Stats, ka_stat_stranky.cesta): an absolute path without
     * the query string and the fragment, at most 255 characters. Null for anything else – the script sends location.pathname,
     * so a relative address, whitespace or a control character means a forged request.
     */
    public static function path(string $raw): ?string
    {
        $path = trim($raw);
        $path = substr($path, 0, strcspn($path, '?#'));
        if ($path === '' || $path[0] !== '/' || preg_match('/[\s\x00-\x1f\x7f]/', $path) || mb_strlen($path) > 255) {
            return null;
        }

        return $path;
    }

    /** POST /conversion or /konverze: one beacon per click (image/web.js); always answers 204, a bad or unwanted beacon is simply not counted. */
    public static function record(App $app): Response
    {
        $request = $app->request;
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $type = self::type($request->post('type'));
        $path = self::path($request->post('path'));
        if ($type === null || $path === null || !Stats::isOn($app) || $ua === '' || Stats::isBot($ua) || $app->auth()->user() !== null) {
            return new Response('', 204);
        }
        $db = $app->db();
        $antispam = new Antispam($db, $app->settings());
        if ($antispam->count($request->ip(), 'konverze', 0, 60) >= 60) {
            return new Response('', 204);
        }
        $antispam->write($request->ip(), 'konverze', 0);
        // only pages the statistics have seen (the page view is counted before anyone can click) – no rows for made-up addresses
        if ((int) $db->value('SELECT COUNT(*) FROM {stat_stranky} WHERE cesta = ? AND den >= ?', [$path, Clock::day('-1 day')]) === 0) {
            return new Response('', 204);
        }
        $today = date('Y-m-d');
        // once per visitor, page and type a day: the statistics' daily fingerprint with the page and the type mixed in; the mark
        // is a different hash than the visitor's, so it never makes a visit "new" for Front\Stats
        $salt = $antispam->key() . $today;
        $mark = substr(hash('sha256', $salt . '|' . $request->ip() . '|' . $ua . '|' . $path . '|' . $type), 0, 32);
        if ($db->run('INSERT IGNORE INTO {stat_navstevnici} (den, otisk) VALUES (?, ?)', [$today, $mark])->rowCount() !== 1) {
            return new Response('', 204);
        }
        $db->run('INSERT INTO {stat_konverze} (den, cesta, typ, pocet) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $path, $type]);
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {stat_konverze} WHERE den < ?', [Clock::day('-' . self::KEEP_DAYS . ' days')]);
        }

        return new Response('', 204);
    }

    /**
     * The clicks of a period: totals per type and the pages they happened on, the most clicked first – for the Statistics
     * screen, get_stats (contact_clicks) and the monthly report.
     *
     * @return array{calls: int, emails: int, whatsapp: int, by_page: list<array{path: string, calls: int, emails: int, whatsapp: int}>}
     */
    public static function summary(Db $db, string $since, ?string $until = null): array
    {
        $rows = $db->all('SELECT cesta, typ, SUM(pocet) AS n FROM {stat_konverze} WHERE den >= ?' . ($until !== null ? ' AND den < ?' : '') . ' GROUP BY cesta, typ',
            $until !== null ? [$since, $until] : [$since]);
        $zero = ['calls' => 0, 'emails' => 0, 'whatsapp' => 0];
        $totals = $zero;
        $pages = [];
        foreach ($rows as $r) {
            $key = self::KEYS[$r['typ']] ?? null;
            if ($key === null) {
                continue;
            }
            $pages[$r['cesta']] ??= ['path' => (string) $r['cesta']] + $zero;
            $pages[$r['cesta']][$key] += (int) $r['n'];
            $totals[$key] += (int) $r['n'];
        }
        usort($pages, fn (array $a, array $b): int => [self::total($b), $a['path']] <=> [self::total($a), $b['path']]);

        return $totals + ['by_page' => $pages];
    }

    /** All clicks of a summary row (totals or one page). */
    public static function total(array $clicks): int
    {
        return (int) ($clicks['calls'] ?? 0) + (int) ($clicks['emails'] ?? 0) + (int) ($clicks['whatsapp'] ?? 0);
    }
}
