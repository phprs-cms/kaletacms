<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * What is working (2.3): traffic, campaigns and devices from the own statistics, and the leads – enquiries, newsletter
 * sign-ups and pop-up conversions – with the pages, first pages of visits, campaigns and sites they came from.
 * Real-user speed (2.8, Core\WebVitals), contact clicks – calls, e-mails, WhatsApp (2.12, Core\Conversions) – and what the
 * search engines show the site for (2.13, Core\SearchData) join them, and what visitors search for on the site (3.52, B5).
 * One report for the Statistics screen and for Claude (MCP get_stats).
 * Counts only: no personal data leaves here.
 */
final class Report
{
    public const array PERIODS = [7, 30, 90, 365];

    /** @return array<string, mixed> */
    public static function build(Db $db, int $days): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $sinceTime = $since . ' 00:00:00';
        $sum = fn (string $sql, array $params = []): int => (int) $db->value($sql, $params);

        $traffic = $db->pairs('SELECT den, CONCAT(navstevy, ":", zobrazeni) FROM {stat_dny} WHERE den >= ?', [$since]);
        $enquiriesByDay = $db->pairs('SELECT DATE(datum), COUNT(*) FROM {poptavky} WHERE datum >= ? GROUP BY DATE(datum)', [$sinceTime]);
        $signupsByDay = $db->pairs('SELECT DATE(datum), COUNT(*) FROM {odberatele} WHERE datum >= ? AND stav = 1 GROUP BY DATE(datum)', [$sinceTime]);
        $daysOut = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            [$visits, $views] = array_map(intval(...), explode(':', $traffic[$day] ?? '0:0'));
            $daysOut[] = ['day' => $day, 'visits' => $visits, 'views' => $views, 'enquiries' => (int) ($enquiriesByDay[$day] ?? 0), 'signups' => (int) ($signupsByDay[$day] ?? 0)];
        }
        $visits = array_sum(array_column($daysOut, 'visits'));
        $enquiries = array_sum(array_column($daysOut, 'enquiries'));
        $signups = array_sum(array_column($daysOut, 'signups'));

        // leads by where they came from; the page of the form counts both enquiries and sign-ups
        $leadsBy = function (string $enquiryColumn, ?string $signupColumn) use ($db, $sinceTime): array {
            $rows = [];
            foreach ($db->all("SELECT {$enquiryColumn} AS k, COUNT(*) AS n FROM {poptavky} WHERE datum >= ? AND {$enquiryColumn} <> '' GROUP BY {$enquiryColumn}", [$sinceTime]) as $r) {
                $rows[$r['k']]['enquiries'] = (int) $r['n'];
            }
            if ($signupColumn !== null) {
                foreach ($db->all("SELECT {$signupColumn} AS k, COUNT(*) AS n FROM {odberatele} WHERE datum >= ? AND stav = 1 AND {$signupColumn} <> '' GROUP BY {$signupColumn}", [$sinceTime]) as $r) {
                    $rows[$r['k']]['signups'] = (int) $r['n'];
                }
            }

            return $rows;
        };
        $views = $db->pairs('SELECT cesta, SUM(pocet) FROM {stat_stranky} WHERE den >= ? GROUP BY cesta', [$since]);
        $pages = [];
        foreach ($leadsBy('stranka', 'zdroj') as $path => $n) {
            $path = (string) parse_url((string) $path, PHP_URL_PATH);
            $pages[$path] = ['enquiries' => ($pages[$path]['enquiries'] ?? 0) + ($n['enquiries'] ?? 0), 'signups' => ($pages[$path]['signups'] ?? 0) + ($n['signups'] ?? 0)];
        }
        // contact clicks (2.12, Core\Conversions): calls, e-mails and WhatsApp next to the form leads of every page – a page with
        // nothing but a phone number brings leads the forms never see
        $clicks = Conversions::summary($db, $since);
        $noClicks = ['calls' => 0, 'emails' => 0, 'whatsapp' => 0];
        $clicksByPage = array_map(fn (array $p): array => array_intersect_key($p, $noClicks), array_column($clicks['by_page'], null, 'path'));
        $topPages = [];
        foreach ($views as $path => $n) {
            $topPages[$path] = ['path' => $path, 'views' => (int) $n] + ($pages[$path] ?? ['enquiries' => 0, 'signups' => 0]) + ($clicksByPage[$path] ?? $noClicks);
        }
        foreach ($pages as $path => $n) {
            $topPages[$path] ??= ['path' => $path, 'views' => 0] + $n + ($clicksByPage[$path] ?? $noClicks);
        }
        foreach ($clicksByPage as $path => $n) {
            $topPages[$path] ??= ['path' => $path, 'views' => 0, 'enquiries' => 0, 'signups' => 0] + $n;
        }
        usort($topPages, fn (array $a, array $b): int => [$b['enquiries'] + $b['signups'] + Conversions::total($b), $b['views']] <=> [$a['enquiries'] + $a['signups'] + Conversions::total($a), $a['views']]);
        $topPages = array_map(fn (array $p): array => $p + ['conversion' => $p['views'] > 0 ? round(100 * ($p['enquiries'] + $p['signups']) / $p['views'], 1) : null], array_slice($topPages, 0, 20));

        $campaignVisits = $db->pairs('SELECT kampan, SUM(navstevy) FROM {stat_kampane} WHERE den >= ? GROUP BY kampan', [$since]);
        $campaigns = [];
        foreach ($leadsBy('kampan', 'kampan') as $utm => $n) {
            $name = Campaign::text((string) $utm);
            $campaigns[$name] = ['enquiries' => ($campaigns[$name]['enquiries'] ?? 0) + ($n['enquiries'] ?? 0), 'signups' => ($campaigns[$name]['signups'] ?? 0) + ($n['signups'] ?? 0)];
        }
        $campaignRows = [];
        foreach (array_unique([...array_keys($campaignVisits), ...array_keys($campaigns)]) as $name) {
            $campaignRows[] = ['campaign' => (string) $name, 'visits' => (int) ($campaignVisits[$name] ?? 0)] + ($campaigns[$name] ?? ['enquiries' => 0, 'signups' => 0]);
        }
        usort($campaignRows, fn (array $a, array $b): int => [$b['enquiries'] + $b['signups'], $b['visits']] <=> [$a['enquiries'] + $a['signups'], $a['visits']]);

        $list = fn (array $rows, string $key): array => array_map(fn (string $k, array $n): array => [$key => $k] + $n + ['enquiries' => 0, 'signups' => 0], array_keys($rows), $rows);

        return [
            'period_days' => $days,
            'totals' => ['visits' => $visits, 'views' => array_sum(array_column($daysOut, 'views')), 'enquiries' => $enquiries, 'signups' => $signups,
                'conversion' => $visits > 0 ? round(100 * ($enquiries + $signups) / $visits, 2) : null],
            'days' => $daysOut,
            'pages' => $topPages,
            'landing_pages' => $list($leadsBy('vstup', 'vstup'), 'path'),
            'campaigns' => array_slice($campaignRows, 0, 20),
            'referrers' => array_map(fn (array $r): array => ['site' => $r['zdroj'], 'visits' => (int) $r['n']],
                $db->all('SELECT zdroj, SUM(pocet) AS n FROM {stat_zdroje} WHERE den >= ? GROUP BY zdroj ORDER BY n DESC LIMIT 15', [$since])),
            'referrers_of_enquiries' => $list($leadsBy('odkud', null), 'site'),
            'devices' => array_map(fn (array $r): array => ['device' => $r['zarizeni'], 'visits' => (int) $r['n']],
                $db->all('SELECT zarizeni, SUM(navstevy) AS n FROM {stat_zarizeni} WHERE den >= ? GROUP BY zarizeni ORDER BY n DESC', [$since])),
            'forms' => array_map(fn (array $r): array => ['form' => $r['formular'], 'enquiries' => (int) $r['n']],
                $db->all('SELECT formular, COUNT(*) AS n FROM {poptavky} WHERE datum >= ? GROUP BY formular ORDER BY n DESC LIMIT 20', [$sinceTime])),
            // pop-up counters are kept since the pop-up was made (or reset), not per day
            'popups' => array_map(fn (array $r): array => ['popup' => $r['nazev'], 'active' => (bool) $r['aktivni'], 'views' => (int) $r['zobrazeni'], 'closes' => (int) $r['zavreni'],
                'conversions' => (int) $r['konverze'], 'conversion' => (int) $r['zobrazeni'] > 0 ? round(100 * (int) $r['konverze'] / (int) $r['zobrazeni'], 1) : null],
                $db->all('SELECT nazev, aktivni, zobrazeni, zavreni, konverze FROM {popupy} ORDER BY konverze DESC, zobrazeni DESC LIMIT 20')),
            'news' => array_map(fn (array $r): array => ['id' => (int) $r['idc'], 'title' => $r['titulek'], 'views' => (int) $r['n']],
                $db->all('SELECT c.idc, c.titulek, SUM(s.pocet) AS n FROM {stat_novinky} s JOIN {novinky} c ON c.idc = s.idc WHERE s.den >= ? GROUP BY c.idc, c.titulek ORDER BY n DESC LIMIT 10', [$since])),
            // real-user speed (2.8): p75 of LCP (ms), CLS and INP (ms) per page with Google's rating – good | needs_improvement | poor
            'web_vitals' => WebVitals::pages($db, $since),
            // contact clicks (2.12): calls, e-mails and WhatsApp in total and by page, each counted once per visitor, page and day
            'contact_clicks' => array_intersect_key($clicks, $noClicks) + ['by_page' => array_slice($clicks['by_page'], 0, 20)],
            // search engines (2.13, Core\SearchData): the latest snapshot of the period per engine – queries, pages, Google's sitemaps
            'search' => SearchData::report($db, $since),
            // 3.52 (B5): what visitors search for on the site – the most searched queries and those that found nothing
            'site_search' => SiteSearch::queries($db, $since),
        ];
    }
}
