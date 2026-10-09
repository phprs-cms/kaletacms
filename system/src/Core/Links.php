<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;

/**
 * Check of broken links in published news items, published page builds and visible collection items (2.14: before, news
 * only). Runs in the background in small batches: one record per five minutes – the one checked longest ago, never
 * checked first – each record once per 30 days. Only links that do not work are stored (ka_odkazy_vadne with the kind of
 * record, its id and, in a build, the element id); the site-wide list is News → Broken links, the site audit and the
 * MCP tool list_broken_links.
 *
 * During the check the server connects to URLs from the content, so only http(s) to public addresses and standard ports,
 * without following redirects - a link in a text must not be abusable to probe the hosting's internal network.
 */
final class Links
{
    /** Codes that do not mean a broken link: sites use them only to refuse bots or the HEAD method. */
    private const array INCONCLUSIVE_CODES = [401, 403, 405, 406, 429, 999];

    /** What is checked: kind => [table, id column, "last checked" column]. */
    public const array KINDS = ['news' => ['novinky', 'idc', 'odkazy_cas'], 'page' => ['stranky', 'ids', 'links_checked'], 'item' => ['kolekce_polozky', 'idp', 'links_checked']];

    /** Links per record; more would eat the time budget of one run. */
    private const int MAX_LINKS = 40;

    public static function runInBackground(App $app): void
    {
        $s = $app->settings();
        if (!$s->bool('link_check') || !function_exists('curl_init') || time() - $s->int('link_check_time') < 300) {
            return;
        }
        $s->set('link_check_time', (string) time());
        $db = $app->db();
        $source = self::nextSource($app);
        if ($source === null) {
            return;
        }
        [$table, $idColumn, $checkedColumn] = self::KINDS[$source['kind']];
        $db->update($table, [$checkedColumn => date('Y-m-d H:i:s')], [$idColumn => $source['id']]);
        $db->delete('odkazy_vadne', ['kind' => $source['kind'], 'idc' => $source['id']]);
        $end = microtime(true) + 12; // at most 12 seconds per run
        foreach ($source['links'] as [$url, $element]) {
            if (microtime(true) > $end) {
                break;
            }
            $state = self::verify($app, $url);
            if ($state !== null) {
                $db->insert('odkazy_vadne', ['kind' => $source['kind'], 'idc' => $source['id'], 'url' => mb_substr($url, 0, 500), 'element' => mb_substr($element, 0, 40), 'stav' => $state, 'cas' => date('Y-m-d H:i:s')]);
            }
        }
    }

    /**
     * The record whose links were checked longest ago (never checked first) among the three kinds, with its links.
     *
     * @return array{kind: string, id: int, links: list<array{0: string, 1: string}>}|null
     */
    private static function nextSource(App $app): ?array
    {
        $db = $app->db();
        $due = '(%1$s IS NULL OR %1$s < NOW() - INTERVAL 30 DAY) ORDER BY %1$s IS NOT NULL, %1$s';
        $rows = [
            'news' => $db->one('SELECT idc AS id, uvod, text, odkazy_cas AS checked FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL AND ' . sprintf($due, 'odkazy_cas') . ', datum DESC LIMIT 1'),
            'page' => $db->one('SELECT ids AS id, text, stavba, links_checked AS checked FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND ' . sprintf($due, 'links_checked') . ', ids LIMIT 1'),
            'item' => $db->one('SELECT idp AS id, data, links_checked AS checked FROM {kolekce_polozky} WHERE zobrazit = 1 AND smazano IS NULL AND ' . sprintf($due, 'links_checked') . ', idp LIMIT 1'),
        ];
        $kind = null;
        $row = null;
        foreach ($rows as $k => $r) {
            if ($r !== null && ($row === null || (string) ($r['checked'] ?? '') < (string) ($row['checked'] ?? ''))) {
                [$kind, $row] = [$k, $r]; // NULL (never checked) sorts before any date
            }
        }
        if ($kind === null || $row === null) {
            return null;
        }

        return ['kind' => $kind, 'id' => (int) $row['id'], 'links' => self::collect($kind, $row)];
    }

    /**
     * Links of a record with the builder element they are in ('' outside builds).
     *
     * @param array<string, mixed> $row
     * @return list<array{0: string, 1: string}>
     */
    public static function collect(string $kind, array $row): array
    {
        $out = [];
        $add = function (string $url, string $element) use (&$out): void {
            $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);
            if ($url !== '' && !str_contains($url, '{{') && !preg_match('#^(mailto:|tel:|\#|javascript:)#i', $url) && !isset($out[$url]) && count($out) < self::MAX_LINKS) {
                $out[$url] = [$url, $element];
            }
        };
        if ($kind === 'news') {
            foreach (self::links((string) ($row['uvod'] ?? '') . (string) ($row['text'] ?? '')) as $url) {
                $add($url, '');
            }
        } elseif ($kind === 'page' && ($build = Build::fromJson(is_string($row['stavba'] ?? null) ? $row['stavba'] : null)) !== null) {
            self::buildLinks($build['deti'] ?? [], $add);
        } elseif ($kind === 'page') {
            foreach (self::links((string) ($row['text'] ?? '')) as $url) {
                $add($url, '');
            }
        } else {
            foreach (json_decode((string) ($row['data'] ?? ''), true) ?: [] as $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (preg_match('#^https?://\S+$#iD', trim($value))) {
                    $add($value, ''); // a link field
                }
                foreach (self::links($value) as $url) {
                    $add($url, '');
                }
            }
        }

        return array_values($out);
    }

    /**
     * Every link in a build: link properties of elements (a button, a card, a pricing plan…) and links in texts, each
     * with the id of the element it belongs to.
     *
     * @param list<mixed> $nodes
     * @param callable(string, string): void $add
     */
    private static function buildLinks(array $nodes, callable $add): void
    {
        $scan = function (array $content, string $id) use (&$scan, $add): void {
            foreach ($content as $key => $value) {
                if (is_array($value)) {
                    $scan($value, $id);
                } elseif (is_string($value) && in_array($key, ['odkaz', 'url', 'href'], true)) {
                    $add($value, $id);
                } elseif (is_string($value) && str_contains($value, '<a')) {
                    foreach (self::links($value) as $url) {
                        $add($url, $id);
                    }
                }
            }
        };
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            $scan(is_array($n['obsah'] ?? null) ? $n['obsah'] : [], (string) ($n['id'] ?? ''));
            if (is_array($n['deti'] ?? null)) {
                self::buildLinks($n['deti'], $add);
            }
        }
    }

    /** @return list<string> unique links from the HTML (at most 25) */
    public static function links(string $html): array
    {
        preg_match_all('#<a\b[^>]*\bhref="([^"]+)"#i', $html, $m);
        $links = array_filter(array_map(fn (string $u): string => html_entity_decode(trim($u), ENT_QUOTES | ENT_HTML5), $m[1]), fn (string $u): bool => $u !== '' && !preg_match('#^(mailto:|tel:|\#|javascript:)#i', $u));

        return array_slice(array_values(array_unique($links)), 0, 25);
    }

    /**
     * Broken links found so far, newest first, with the record they are in (a deleted record drops out).
     *
     * @param string $newsScope SQL condition for news rows (Auth::articleScope('c.')): an author sees only their own news
     * @return list<array{kind: string, id: int, title: string, url: string, element: string, status: int, found: string, edit: string, page: string, target: array<string, int|string>, hint: string}>
     */
    public static function broken(App $app, int $limit = 300, string $newsScope = ''): array
    {
        $db = $app->db();
        $rows = $db->all('SELECT o.kind, o.idc, o.url, o.element, o.stav, o.cas, c.titulek AS news_title, c.seo_link AS news_slug, c.jazyk AS news_language, s.titulek AS page_title, s.seo_link AS page_slug, s.stavba IS NOT NULL AS page_build, s.jazyk AS page_language,'
            . ' p.nazev AS item_title, p.seo_link AS item_slug, p.jazyk AS item_language, p.idk, k.seo_link AS collection, k.detail FROM {odkazy_vadne} o'
            . ' LEFT JOIN {novinky} c ON o.kind = \'news\' AND c.idc = o.idc AND c.smazano IS NULL' . ($newsScope !== '' ? ' AND 1 = 1' . $newsScope : '')
            . ' LEFT JOIN {stranky} s ON o.kind = \'page\' AND s.ids = o.idc AND s.smazano IS NULL'
            . ' LEFT JOIN {kolekce_polozky} p ON o.kind = \'item\' AND p.idp = o.idc AND p.smazano IS NULL LEFT JOIN {kolekce} k ON k.idk = p.idk'
            . ' WHERE COALESCE(c.idc, s.ids, p.idp) IS NOT NULL ORDER BY o.cas DESC LIMIT ' . max(1, min(1000, $limit)));
        $additional = Language::additional($app->settings());
        $prefix = fn (?string $language): string => in_array((string) $language, $additional, true) ? $language . '/' : '';
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['idc'];
            [$title, $edit, $page, $target] = match ((string) $r['kind']) {
                'news' => [(string) $r['news_title'], 'admin.php?module=news&action=edit&id=' . $id, ltrim(substr($app->newsItemUrl((string) $r['news_slug'], (string) $r['news_language']), strlen($app->request->basePath())), '/'), ['news' => $id]],
                'page' => [(string) $r['page_title'], 'admin.php?module=pages&action=' . ($r['page_build'] ? 'builder' : 'edit') . '&id=' . $id, $prefix($r['page_language']) . $r['page_slug'], ['page' => $id]],
                default => [(string) $r['item_title'], 'admin.php?module=collections&action=item&id=' . (int) $r['idk'] . '&polozka=' . $id, $r['detail'] ? $prefix($r['item_language']) . $r['collection'] . '/' . $r['item_slug'] : '', ['collection' => (string) $r['collection'], 'item' => $id]],
            };
            $url = (string) $r['url'];
            $out[] = ['kind' => (string) $r['kind'], 'id' => $id, 'title' => $title, 'url' => $url, 'element' => (string) $r['element'], 'status' => (int) $r['stav'], 'found' => (string) $r['cas'],
                'edit' => $app->url($edit), 'page' => $page, 'target' => $target, 'hint' => self::hint($url, (int) $r['stav'])];
        }

        return $out;
    }

    /** What to try – a suggestion string only; the server never asks archive.org. */
    public static function hint(string $url, int $status): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return 'The news item at this address no longer exists – link to what replaced it or remove the link.';
        }

        return ($status === 0 ? 'The server did not respond. ' : 'The server answered HTTP ' . $status . '. ')
            . 'Try the archived copy https://web.archive.org/web/2020/' . $url . ' to find where the content moved; otherwise remove the link.';
    }

    /** "Check again": the record goes to the front of the queue and its findings are cleared. */
    public static function recheck(App $app, string $kind, int $id): bool
    {
        if (!isset(self::KINDS[$kind]) || $id <= 0) {
            return false;
        }
        [$table, $idColumn, $checkedColumn] = self::KINDS[$kind];
        $app->db()->update($table, [$checkedColumn => null], [$idColumn => $id]);
        $app->db()->delete('odkazy_vadne', ['kind' => $kind, 'idc' => $id]);

        return true;
    }

    /** @return int|null error code (0 = no response), null = the link is OK or cannot be judged */
    public static function verify(App $app, string $url): ?int
    {
        // a link to the site itself: looking into the database is enough
        $custom = $app->request->origin();
        $path = str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : (str_starts_with($url, $custom . '/') ? substr($url, strlen($custom)) : null);
        if ($path !== null) {
            $path = (string) parse_url(substr($path, strlen($app->request->basePath())), PHP_URL_PATH);
            if (preg_match('#^/(?:' . Language::TAG . '/)?novinky/([a-z0-9-]+)$#D', $path, $m)) {
                return $app->db()->value('SELECT idc FROM {novinky} WHERE seo_link = ?', [$m[1]]) === null
                    && $app->db()->value('SELECT idp FROM {presmerovani} WHERE z_adresy = ?', ['novinky/' . $m[1]]) === null ? 404 : null;
            }

            return null; // other URLs of the site itself (pages, items, files) are checked by the site audit
        }
        $target = self::target($url);
        if ($target === null) {
            return null;
        }
        if ($target === false) {
            return 0; // the name does not resolve: no request – an unpinned curl would look the name up on its own (3.3.3, N9)
        }
        // the address is resolved once and the connection is pinned to it - it cannot be spoofed between the check and the connection (DNS rebinding)
        $ch = curl_init($target['url']);
        Outbound::pin($ch, $target['host'], $target['port'], $target['ip']);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Kaleta kontrola odkazu)',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $code >= 200 && $code < 400 || in_array($code, self::INCONCLUSIVE_CODES, true) ? null : $code;
    }

    /**
     * Only http(s), a standard port and an address that does not lead into an internal network. The automated tests
     * point links at a local port nothing listens on (KALETA_LINKS_LOCAL=1); never set on a real site.
     */
    public static function isPublic(string $url): bool
    {
        return self::target($url) !== null;
    }

    /**
     * Where the check connects: the URL with the normalized host (Outbound::url), the host, the port and the public address
     * the connection is pinned to; false = the name does not resolve (nothing is requested); null = not checked at all –
     * not http(s), another port, a refused host, or an address in an internal network. Every address the name resolves
     * to counts, IPv4 and IPv6, by ImageDownloader::isPublicIp (3.3.3, N9: IPv6, CGNAT 100.64/10 and the rest).
     *
     * @return array{url: string, host: string, port: int, scheme: string, ip: string}|false|null
     */
    public static function target(string $url): array|false|null
    {
        $target = Outbound::url($url);
        if ($target === null) {
            return null;
        }
        if (getenv('KALETA_LINKS_LOCAL') === '1' && $target['host'] === '127.0.0.1') {
            return $target + ['ip' => '127.0.0.1'];
        }
        if (!in_array($target['port'], [80, 443], true)) {
            return null;
        }
        $addresses = Outbound::addresses($target['host']);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $ip) {
            if (!ImageDownloader::isPublicIp($ip)) {
                return null;
            }
        }

        return $target + ['ip' => $addresses[0]];
    }
}
