<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;
use Kaleta\Admin\Modules\Redirects;

/**
 * Redirects for addresses visitors could not find (2.14). For a pending 404 (Core\NotFound) it looks for the page, news
 * item or collection item the visitor most likely meant and says how sure it is (0–100):
 *
 *   100  the same address written differently (/O-nas/, /o-nas.html, /o-nas/index.php)
 *    95  the address without an old prefix (/novinky/x, /clanek/x, /blog/x) or with the language prefix moved (/en/x → /x)
 *    90  the same last segment elsewhere in the tree (/sluzby/weby → /weby)
 *   <90  a similar slug (a typo, a dropped year: /cenik-2019 → /cenik), nothing below MIN_SCORE
 *
 * The Redirects screen and list_redirects show the best candidate as a suggestion with one click to create the redirect.
 * With "Create redirects for missing addresses by themselves" on (off by default), the daily job creates a 301 for every
 * candidate at or above the threshold (default 90), records it (ChangeLog, event redirect.auto) and marks the redirect as
 * automatic, so the list shows "automatic, score N" and the administrator can undo it by deleting it. Two candidates with
 * the same best score mean the address is ambiguous: the score is capped so it is only ever a suggestion.
 */
final class RedirectMatcher
{
    public const int DEFAULT_THRESHOLD = 90;

    /** Below this a candidate is noise: not even suggested. */
    public const int MIN_SCORE = 60;

    /** A tie between two candidates never becomes a redirect by itself. */
    private const int AMBIGUOUS = 70;

    /** First segments other systems and older versions put before articles and pages. */
    private const array LEGACY_PREFIXES = ['novinky', 'news', 'clanek', 'clanky', 'article', 'articles', 'blog', 'aktuality', 'aktualne', 'stranka', 'stranky', 'page', 'pages', 'kategorie', 'category', 'rubrika'];

    /**
     * Every address a visitor can be sent to: published pages, published news items and item pages of visible items,
     * with the language prefix of their language version.
     *
     * @return list<string> paths without the leading slash
     */
    public static function targets(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $additional = Language::additional($s);
        $prefix = fn (string $language): string => in_array($language, $additional, true) ? $language . '/' : '';
        $out = [];
        foreach ($db->all('SELECT seo_link, jazyk FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL LIMIT 3000') as $p) {
            $out[] = $prefix((string) $p['jazyk']) . $p['seo_link'];
        }
        if (Extensions::isEnabled($s, 'novinky')) {
            $base = strlen($app->request->basePath());
            foreach ($db->all('SELECT seo_link, jazyk FROM {novinky} WHERE visible = 1 AND datum <= ? AND smazano IS NULL ORDER BY datum DESC LIMIT 3000', [Clock::now()]) as $c) {
                $out[] = ltrim(substr($app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk']), $base), '/');
            }
        }
        foreach ($db->all('SELECT p.seo_link, p.jazyk, k.seo_link AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.smazano IS NULL LIMIT 5000') as $p) {
            $out[] = $prefix((string) $p['jazyk']) . $p['kolekce'] . '/' . $p['seo_link'];
        }

        return array_values(array_unique($out));
    }

    /**
     * How likely the missing address meant the candidate, 0–100. Pure: the unit tests check the typical moved addresses.
     *
     * @param list<string> $languages language codes that may prefix an address (the site's languages)
     */
    public static function score(string $missing, string $candidate, array $languages = []): int
    {
        $a = self::normalize($missing, $languages);
        $b = self::normalize($candidate, $languages);
        if ($a['slug'] === '' || $b['slug'] === '' || mb_strlen($a['slug']) < 3) {
            return 0;
        }
        if ($a['path'] === $b['path']) {
            return $a['language'] === $b['language'] && !$a['legacy'] && !$b['legacy'] ? 100 : 95;
        }
        if ($a['slug'] === $b['slug']) {
            return 90;
        }
        similar_text($a['slug'], $b['slug'], $percent);
        $byDistance = 100 - (int) ceil(levenshtein($a['slug'], $b['slug']) * 100 / max(strlen($a['slug']), strlen($b['slug'])));
        $similarity = (int) round(max($percent, $byDistance));

        return $similarity >= self::MIN_SCORE ? min(85, $similarity) : 0;
    }

    /**
     * The best candidate for a missing address, or null when nothing comes close.
     *
     * @param list<string>|null $targets targets() – pass them when scoring many addresses
     * @return array{to: string, score: int}|null
     */
    public static function suggest(App $app, string $path, ?array $targets = null): ?array
    {
        $targets ??= self::targets($app);
        $languages = [Language::defaults($app->settings()), ...Language::additional($app->settings())];
        $best = null;
        $bestScore = 0;
        $tie = false;
        foreach ($targets as $target) {
            $score = self::score($path, $target, $languages);
            if ($score > $bestScore) {
                [$best, $bestScore, $tie] = [$target, $score, false];
            } elseif ($score === $bestScore && $score > 0 && $target !== $best) {
                $tie = true;
            }
        }
        if ($best === null || $bestScore < self::MIN_SCORE) {
            return null;
        }

        return ['to' => $best, 'score' => $tie ? min($bestScore, self::AMBIGUOUS) : $bestScore];
    }

    /**
     * Suggestions for the pending 404s (NotFound::pending rows), by path.
     *
     * @param list<array{cesta: string}> $pending
     * @return array<string, array{to: string, score: int}>
     */
    public static function suggestions(App $app, array $pending): array
    {
        if ($pending === []) {
            return [];
        }
        $targets = self::targets($app);
        $out = [];
        foreach ($pending as $n) {
            $found = self::suggest($app, (string) $n['cesta'], $targets);
            if ($found !== null) {
                $out[(string) $n['cesta']] = $found;
            }
        }

        return $out;
    }

    public static function threshold(Settings $s): int
    {
        $value = $s->int('redirect_auto_threshold');

        return $value >= 50 && $value <= 100 ? $value : self::DEFAULT_THRESHOLD;
    }

    /** The daily job (Core\Scheduler, redirects): creates the redirects the site is sure about. */
    public static function run(App $app): string
    {
        $s = $app->settings();
        if (!$s->bool('redirect_auto') || !Extensions::isEnabled($s, 'presmerovani')) {
            return 'off';
        }
        $db = $app->db();
        $threshold = self::threshold($s);
        $created = 0;
        foreach (self::suggestions($app, NotFound::pending($app, 30, 200)) as $from => $found) {
            if ($found['score'] < $threshold) {
                continue;
            }
            Redirects::add($db, $from, $found['to']);
            $db->run('UPDATE {presmerovani} SET auto_score = ? WHERE z_adresy = ?', [$found['score'], trim($from, '/ ')]);
            $db->delete('nenalezeno', ['cesta' => trim($from, '/')]);
            Events::record($db, 'redirect.auto', 'info', t('/%s now redirects to /%s by itself (score %d of 100). Undo: Administration → Redirects.', $from, $found['to'], $found['score']),
                ['from' => '/' . $from, 'to' => '/' . $found['to'], 'score' => $found['score']]);
            ChangeLog::write($app, 'redirects', 'auto', '/' . $from . ' → /' . $found['to'] . ' (' . $found['score'] . ')');
            $created++;
        }
        if ($created > 0) {
            \Kaleta\Front\Cache::clear();
        }

        return 'created ' . $created;
    }

    /**
     * @param list<string> $languages
     * @return array{path: string, slug: string, language: string, legacy: bool}
     */
    private static function normalize(string $path, array $languages): array
    {
        $path = mb_strtolower(trim(rawurldecode((string) parse_url(trim($path), PHP_URL_PATH)), '/ '));
        $path = (string) preg_replace('#(/index)?\.(html?|php|aspx?|jsp)$#', '', $path);
        $path = (string) preg_replace('#/index$#', '', $path);
        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));
        $language = '';
        if (count($segments) > 1 && in_array($segments[0], $languages, true)) {
            $language = array_shift($segments);
        }
        $legacy = false;
        if (count($segments) > 1 && in_array($segments[0], self::LEGACY_PREFIXES, true)) {
            $legacy = true;
            array_shift($segments);
        }

        return ['path' => implode('/', $segments), 'slug' => $segments === [] ? '' : $segments[count($segments) - 1], 'language' => $language, 'legacy' => $legacy];
    }
}
