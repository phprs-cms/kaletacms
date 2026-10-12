<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Addresses visitors could not find (table ka_nenalezeno): what the administrator should redirect.
 *
 * The log keeps itself useful (1.9): probes of bots looking for other systems are never recorded, an address that works
 * again (the page was published, a translation added) or has a redirect drops out, and an address the administrator
 * ignored stays out of the warning and the list for good.
 */
final class NotFound
{
    /** Paths bots probe on every site: other systems' files, APIs and admin screens. */
    public const string BOTS = '#\.(php|asp|aspx|env|git|sql|bak|ini|xml|txt|js|css|map|png|jpe?g|gif|ico|webp)$|^(wp-|wp/|wordpress|oembed/|xmlrpc|\.|cgi-bin|vendor/|admin/|_next(/|$)|_nuxt|app$|api$|actuator|phpmyadmin|owa/|autodiscover|remote/|boaform|hnap1|solr|telescope|debug)'
        // ad-network crawlers (sellers.json next to ads.txt) and app APIs probed on every site (the public API of 1.x ended in 2.0)
        . '|^sellers\.json$|^api/#i';

    /** At least this many visits in the period make an address worth a warning. */
    public const int HITS = 3;

    /** After this many requests of an address without a page an event is recorded once (2.8, notfound.spike). */
    public const int SPIKE = 20;

    public static function isBot(string $path): bool
    {
        return preg_match(self::BOTS, $path) === 1;
    }

    /**
     * Addresses that still end in 404, were hit repeatedly in the last $days days and were not ignored. On the way, rows
     * that work again, have a redirect or are bot probes recorded by an older version are removed.
     *
     * @return list<array{cesta: string, pocet: int, naposledy: string}>
     */
    public static function pending(App $app, int $days = 7, int $limit = 100): array
    {
        $db = $app->db();
        $audit = new Audit($app);
        $out = [];
        foreach ($db->all('SELECT cesta, pocet, naposledy FROM {nenalezeno} WHERE ignorovano IS NULL AND naposledy > ? AND pocet >= ? ORDER BY pocet DESC, naposledy DESC LIMIT 500', [Clock::at('-' . $days . ' days'), self::HITS]) as $r) {
            $path = (string) $r['cesta'];
            if (self::isBot($path) || $audit->resolves('/' . $path)) {
                $db->delete('nenalezeno', ['cesta' => $path]); // nothing to do about it any more

                continue;
            }
            if (count($out) < $limit) {
                $out[] = ['cesta' => $path, 'pocet' => (int) $r['pocet'], 'naposledy' => (string) $r['naposledy']];
            }
        }

        return $out;
    }

    /** Hides addresses from the warning and the list; with no paths all addresses waiting now. Returns how many. */
    public static function ignore(App $app, ?array $paths = null): int
    {
        $db = $app->db();
        if ($paths === null) {
            $paths = array_column(self::pending($app, 60, 500), 'cesta');
        }
        $count = 0;
        foreach ($paths as $path) {
            $count += $db->run('UPDATE {nenalezeno} SET ignorovano = ? WHERE cesta = ? AND ignorovano IS NULL', [Clock::now(), trim((string) $path, '/')])->rowCount();
        }

        return $count;
    }
}
