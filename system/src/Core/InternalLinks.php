<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;

/**
 * Internal links (2.14): orphan pages and where a link to them would fit.
 *
 * An orphan is a published page (other than the home page), news item or item page that no published build, menu or text
 * links to: visitors and search engines reach it only by its address. A page in the navigation (v_menu) is linked; news
 * items are linked when the news listing is reachable (the home page, a News element, a menu item); items of a collection
 * are linked when a published build lists the collection. Everything is computed from stored content – on demand, cached
 * for an hour in storage/cache (any change in the administration clears the cache), never per visit.
 *
 * suggest_internal_links adds candidate source pages to each orphan: published pages whose title or text share words of
 * the orphan's title, so Claude can add the link as a draft with the usual build tools. Nothing is edited by itself.
 */
final class InternalLinks
{
    private const string CACHE = KALETA_ROOT . '/storage/cache/stranky/odkazy-sirotci.html';

    private const int CACHE_SECONDS = 3600;

    /** Words shorter than this say nothing about a page. */
    private const int MIN_WORD = 4;

    /**
     * @return list<array{kind: string, id: int, title: string, path: string, edit: string, target: array<string, int|string>}>
     */
    public static function orphans(App $app): array
    {
        if (is_file(self::CACHE) && filemtime(self::CACHE) >= time() - self::CACHE_SECONDS && ($json = file_get_contents(self::CACHE)) !== false && is_array($cached = json_decode($json, true))) {
            return $cached;
        }
        $orphans = self::compute($app);
        if (!is_dir(dirname(self::CACHE))) {
            @mkdir(dirname(self::CACHE), 0775, true);
        }
        @file_put_contents(self::CACHE, json_encode($orphans, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $orphans;
    }

    public static function forget(): void
    {
        @unlink(self::CACHE);
    }

    /** @return list<array{kind: string, id: int, title: string, path: string, edit: string, target: array<string, int|string>}> */
    public static function compute(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $home = (int) $s->get('home_page');
        $additional = Language::additional($s);
        $prefix = fn (string $language): string => in_array($language, $additional, true) ? $language . '/' : '';
        $linked = [];
        $listedCollections = [];
        $newsListed = $home === 0;
        $builds = '';
        $take = function (string $content) use (&$linked, &$builds, $app): void {
            $builds .= $content;
            foreach (self::paths($app, $content) as $path) {
                $linked[$path] = true;
            }
        };
        foreach ($db->all('SELECT seo_link, jazyk, v_menu, text, stavba FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL') as $p) {
            $take((string) ($p['stavba'] ?? $p['text']));
            if ($p['v_menu']) {
                $linked[$prefix((string) $p['jazyk']) . $p['seo_link']] = true; // the automatic navigation and the footer list it
            }
        }
        foreach ($db->all('SELECT stavba FROM {casti} WHERE stavba IS NOT NULL UNION ALL SELECT stavba FROM {komponenty} WHERE stavba IS NOT NULL UNION ALL SELECT stavba FROM {popupy} WHERE stavba IS NOT NULL AND aktivni = 1 UNION ALL SELECT stavba FROM {kolekce} WHERE stavba IS NOT NULL AND detail = 1') as $c) {
            $take((string) $c['stavba']);
        }
        foreach ($db->all('SELECT uvod, text FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL LIMIT 2000') as $c) {
            $take($c['uvod'] . ' ' . $c['text']);
        }
        foreach ($db->all('SELECT data FROM {kolekce_polozky} WHERE zobrazit = 1 AND smazano IS NULL LIMIT 5000') as $p) {
            $take((string) $p['data']);
        }
        $pageIds = [];
        foreach ($db->all('SELECT polozky FROM {menu}') as $m) {
            foreach (Menu::flatten(json_decode((string) $m['polozky'], true) ?: []) as $i) {
                if (($i['typ'] ?? '') === 'stranka') {
                    $pageIds[(int) ($i['ids'] ?? 0)] = true;
                } elseif (($i['typ'] ?? '') === 'odkaz') {
                    $take('"url":' . json_encode((string) ($i['url'] ?? '')));
                } elseif (($i['typ'] ?? '') === 'novinky') {
                    $newsListed = true;
                }
            }
        }
        if ($pageIds !== []) {
            foreach ($db->all('SELECT seo_link, jazyk FROM {stranky} WHERE ids IN (' . implode(',', array_map(intval(...), array_keys($pageIds))) . ')') as $p) {
                $linked[$prefix((string) $p['jazyk']) . $p['seo_link']] = true;
            }
        }
        // list elements reach every item of their collections and every news item
        if (preg_match_all('#"typ":"kolekce"[^}]*?"kolekce":"([^"]*)"#', $builds, $m)) {
            foreach ($m[1] as $list) {
                foreach (preg_split('/[\s,]+/', $list) ?: [] as $slug) {
                    if ($slug !== '') {
                        $listedCollections[$slug] = true;
                    }
                }
            }
        }
        $newsListed = $newsListed || str_contains($builds, '"typ":"novinky"') || isset($linked['novinky']) || isset($linked[Routes::publicPath('novinky', Language::defaults($s), $db)]);

        $out = [];
        foreach ($db->all('SELECT ids, titulek, seo_link, jazyk, stavba IS NOT NULL AS build FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND ids <> ? ORDER BY poradi, titulek', [$home]) as $p) {
            $path = $prefix((string) $p['jazyk']) . $p['seo_link'];
            if (!isset($linked[$path])) {
                $out[] = ['kind' => 'page', 'id' => (int) $p['ids'], 'title' => (string) $p['titulek'], 'path' => $path, 'edit' => 'admin.php?module=pages&action=' . ($p['build'] ? 'builder' : 'edit') . '&id=' . (int) $p['ids'], 'target' => ['page' => (int) $p['ids']]];
            }
        }
        if (!$newsListed && Extensions::isEnabled($s, 'novinky')) {
            $base = strlen($app->request->basePath());
            foreach ($db->all('SELECT idc, titulek, seo_link, jazyk FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL ORDER BY datum DESC LIMIT 1000') as $c) {
                $path = ltrim(substr($app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk']), $base), '/');
                if (!isset($linked[$path])) {
                    $out[] = ['kind' => 'news', 'id' => (int) $c['idc'], 'title' => (string) $c['titulek'], 'path' => $path, 'edit' => 'admin.php?module=news&action=edit&id=' . (int) $c['idc'], 'target' => ['news' => (int) $c['idc']]];
                }
            }
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.jazyk, k.seo_link AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.smazano IS NULL ORDER BY p.idk, p.poradi LIMIT 3000') as $p) {
            $path = $prefix((string) $p['jazyk']) . $p['kolekce'] . '/' . $p['seo_link'];
            if (!isset($listedCollections[(string) $p['kolekce']]) && !isset($linked[$path])) {
                $out[] = ['kind' => 'item', 'id' => (int) $p['idp'], 'title' => (string) $p['nazev'], 'path' => $path, 'edit' => 'admin.php?module=collections&action=item&id=' . (int) $p['idk'] . '&item=' . (int) $p['idp'], 'target' => ['collection' => (string) $p['kolekce'], 'item' => (int) $p['idp']]];
            }
        }

        return $out;
    }

    /**
     * Orphans with the published pages a link to them would fit on: pages whose title or text share words of the
     * orphan's title, best first.
     *
     * @return list<array{kind: string, id: int, title: string, path: string, target: array<string, int|string>, title_words: list<string>, candidates: list<array{id: int, title: string, path: string, shared_words: list<string>, target: array{page: int}}>}>
     */
    public static function suggestions(App $app, int $limit = 20): array
    {
        $orphans = array_slice(self::orphans($app), 0, max(1, min(100, $limit)));
        if ($orphans === []) {
            return [];
        }
        $home = (int) $app->settings()->get('home_page');
        $additional = Language::additional($app->settings());
        $sources = [];
        foreach ($app->db()->all('SELECT ids, titulek, seo_link, jazyk, text, stavba FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL LIMIT 1000') as $p) {
            $build = Build::fromJson(is_string($p['stavba']) ? $p['stavba'] : null);
            $text = $p['titulek'] . ' ' . strip_tags($build !== null ? Build::asText($build) : (string) $p['text']);
            $sources[] = ['id' => (int) $p['ids'], 'title' => (string) $p['titulek'], 'path' => (int) $p['ids'] === $home ? '' : (in_array((string) $p['jazyk'], $additional, true) ? $p['jazyk'] . '/' : '') . $p['seo_link'],
                'words' => array_fill_keys(self::words($text), true)];
        }
        $out = [];
        foreach ($orphans as $o) {
            $words = self::words($o['title']);
            $candidates = [];
            foreach ($sources as $src) {
                if ($o['kind'] === 'page' && $src['id'] === $o['id']) {
                    continue;
                }
                $shared = array_values(array_filter($words, fn (string $w): bool => isset($src['words'][$w])));
                if ($shared !== []) {
                    $candidates[] = ['id' => $src['id'], 'title' => $src['title'], 'path' => $src['path'], 'shared_words' => $shared, 'target' => ['page' => $src['id']]];
                }
            }
            usort($candidates, fn (array $a, array $b): int => count($b['shared_words']) <=> count($a['shared_words']) ?: $a['id'] <=> $b['id']);
            $out[] = ['kind' => $o['kind'], 'id' => $o['id'], 'title' => $o['title'], 'path' => $o['path'], 'target' => $o['target'], 'title_words' => $words, 'candidates' => array_slice($candidates, 0, 5)];
        }

        return $out;
    }

    /** @return list<string> distinct lower-case words without diacritics, at least MIN_WORD letters */
    public static function words(string $text): array
    {
        preg_match_all('/\p{L}{' . self::MIN_WORD . ',}/u', mb_strtolower(remove_diacritics(html_entity_decode($text, ENT_QUOTES | ENT_HTML5))), $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Internal paths linked from a build (JSON), HTML or item data: without the leading slash, as the site stores addresses.
     *
     * @return list<string>
     */
    private static function paths(App $app, string $content): array
    {
        preg_match_all('#"(?:odkaz|url|href)":"((?:[^"\\\\]|\\\\.)*)"|href=\\\\?"([^"\\\\]*)\\\\?"#', $content, $m, PREG_SET_ORDER);
        $origin = strtolower($app->request->origin() . $app->request->basePath());
        $out = [];
        foreach ($m as $match) {
            $link = stripslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
            if ($link === '' || str_contains($link, '{{') || preg_match('#^(\#|mailto:|tel:|javascript:)#i', $link)) {
                continue;
            }
            if (preg_match('#^https?://#i', $link)) {
                if (!str_starts_with(strtolower($link), $origin . '/')) {
                    continue;
                }
                $link = substr($link, strlen($origin));
            } elseif (str_starts_with($link, '/')) {
                $link = substr($link, strlen($app->request->basePath()));
            } else {
                continue;
            }
            $out[] = trim(rawurldecode((string) parse_url($link, PHP_URL_PATH)), '/');
        }

        return $out;
    }
}
