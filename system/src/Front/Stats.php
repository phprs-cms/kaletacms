<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;

/**
 * Own traffic measurement without cookies.
 * Visitor = hash (IP + browser + salt valid for one day); the IP itself is not stored anywhere
 * and hashes older than two days are deleted, so readers cannot be tracked over time.
 */
final class Stats
{
    private const string BOTS = '/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|java\/|http|scan|check|feed|lighthouse|headless/i';

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

    public static function record(App $app, ?int $idc): void
    {
        $server = $_SERVER;
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (!self::isOn($app) || $ua === '' || self::isBot($ua) || $app->request->get('preview') !== '') {
            return;
        }
        $db = $app->db();
        $today = date('Y-m-d');
        $salt = (new Antispam($db, $app->settings()))->key() . $today;
        $hash = substr(hash('sha256', $salt . '|' . $app->request->ip() . '|' . $ua), 0, 32);

        $new = $db->run('INSERT IGNORE INTO {stat_navstevnici} (den, otisk) VALUES (?, ?)', [$today, $hash])->rowCount() === 1;
        $db->run('INSERT INTO {stat_dny} (den, navstevy, zobrazeni) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + VALUES(navstevy), zobrazeni = zobrazeni + 1', [$today, (int) $new]);
        if ($idc !== null) {
            $db->run('INSERT INTO {stat_novinky} (den, idc, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $idc]);
        }
        // views per URL (including the language version) – most read pages in the administration
        $path = mb_substr((string) parse_url($app->url(ltrim($app->request->path(), '/')), PHP_URL_PATH), 0, 255);
        $db->run('INSERT INTO {stat_stranky} (den, cesta, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, $path]);
        $source = strtolower((string) parse_url((string) ($server['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
        $source = preg_replace('/^www\./', '', $source) ?? '';
        // the own site is the configured site URL, not the Host header – the client can write that however it wants
        $custom = preg_replace('/^www\./', '', strtolower((string) parse_url($app->request->origin(), PHP_URL_HOST))) ?? '';
        if ($new && $source !== '' && $source !== $custom) {
            $db->run('INSERT INTO {stat_zdroje} (den, zdroj, pocet) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE pocet = pocet + 1', [$today, mb_substr($source, 0, 100)]);
        }
        if ($new) {
            // 2.3: the device of the visit, and the campaign of the page it started on – both from the request itself
            $db->run('INSERT INTO {stat_zarizeni} (den, zarizeni, navstevy) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + 1', [$today, self::device($ua)]);
            $campaign = Forms::campaignText(Forms::campaign($app->request->origin() . ($server['REQUEST_URI'] ?? '/'), $app->request->origin()));
            if ($campaign !== '') {
                $db->run('INSERT INTO {stat_kampane} (den, kampan, navstevy) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE navstevy = navstevy + 1', [$today, mb_substr($campaign, 0, 255)]);
            }
        }
        if (random_int(1, 200) === 1) {
            $db->run('DELETE FROM {stat_navstevnici} WHERE den < CURDATE() - INTERVAL 1 DAY');
            $db->run('DELETE FROM {stat_stranky} WHERE den < CURDATE() - INTERVAL 400 DAY');
            $db->run('DELETE FROM {stat_kampane} WHERE den < CURDATE() - INTERVAL 400 DAY');
        }
    }
}
