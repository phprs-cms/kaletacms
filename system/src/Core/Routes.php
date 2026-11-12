<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site's system URLs in the language of the version: the Czech version has /novinky, /novinky/kategorie/…,
 * /novinky/stitek/… and /hledani, every other one /news, /news/category/…, /news/tag/… and /search.
 *
 * Code inside the system works with the Czech (internal) paths; App::url() converts them to public ones and Front\Kernel
 * converts public ones back to internal. The other form of a URL (e.g. the old /novinky on an English site) redirects
 * permanently to the valid one, so links and search engine rankings stay. If the site has its own page with the slug news
 * or search, the page keeps it and the system uses the Czech word.
 *
 * The setting news_slug (Settings → General) replaces the first segment of the news URLs in every language (/blog,
 * /blog/category/x); the old forms /novinky and /news redirect to it.
 */
final class Routes
{
    /** internal (Czech) word => English one */
    private const array FIRST_SEGMENTS = ['novinky' => 'news', 'hledani' => 'search'];
    private const array SECOND_SEGMENTS = ['kategorie' => 'category', 'stitek' => 'tag'];

    /** @var array<string, bool>|null English words that a page of the site itself occupies */
    private static ?array $taken = null;

    /**
     * First path segments that the web server, index.php or Front\Kernel answer before the news routes. The news slug is
     * rewritten to the internal /novinky in the Kernel constructor, before any of them, so a news slug equal to one of these
     * would capture it – oauth, for example, would break every connected Claude app. tools/unit-tests.php reads
     * Front\Kernel::handle(), Front\OAuth and system/dev-router.php and fails when an early route is missing here.
     */
    public const array NEWS_RESERVED = [
        // Front\Kernel::handle() before the pages
        'index', 'hledani', 'search', 'rss', 'feed', 'manifest', 'favicon', 'og', 'robots', 'sitemap', 'llms', 'souhlas', 'popup', 'fleet', 'vitals', 'hit',
        'konverze', 'mcp', 'download', 'odber', 'formular', 'ulohy', 'screen', 'stav', 'quote', // 3.38: the customer's quote (Front\QuotePage)
        // the English system paths (3.7, SYSTEM_PATHS) – read in the Kernel constructor right after the news slug
        'tasks', 'subscription', 'form', 'consent', 'conversion', 'status',
        // Front\OAuth (the Claude connection)
        'oauth',
        // entry files and folders of the installation (index.php, admin.php, install.php, config.php, .htaccess, dev-router.php)
        'admin', 'install', 'config', 'media', 'image', 'storage', 'system', 'extensions', 'layout', 'tools', 'docs', 'dist', 'api',
    ];

    /**
     * 3.7 (GitHub #17): the system endpoints have an English public path next to the Czech one they have had since 1.x.
     * Both answer forever and neither redirects – cron jobs set up on hosting, links in e-mails already sent (subscription
     * confirm and unsubscribe, List-Unsubscribe), forms on cached pages and the consent log depend on the Czech ones.
     * Code inside the system keeps using the internal (Czech) path; App::url() writes the English one (publicSystemPath)
     * and Front\Kernel reads it back (systemPath). When a page of the site, or the news slug, already holds the English
     * word (a page "form" made before 3.7), it keeps it and the system keeps writing the Czech path (Health lists it).
     * The OAuth and MCP paths are English already and never move.
     */
    public const array SYSTEM_PATHS = [
        'ulohy' => 'tasks', 'odber' => 'subscription', 'formular' => 'form', 'souhlas' => 'consent', 'konverze' => 'conversion',
        'stav.json' => 'status.json', '_komentar' => '_comment',
    ];

    /** Internal previews with a part after the word: /_komponenta/<id>, /_sekce/<slug> (the builder's section preview). */
    public const array SYSTEM_PREFIXES = ['_komponenta' => '_component', '_sekce' => '_section'];

    /** How many earlier news slugs keep redirecting (setting news_slug_previous). */
    private const int PREVIOUS_KEPT = 10;

    /** The custom news slug (setting news_slug), null = not read yet. */
    private static ?string $news = null;

    /** @var list<string>|null earlier custom news slugs that redirect to the current one (setting news_slug_previous) */
    private static ?array $previous = null;

    /** The custom first segment of the news URLs ('' = the default word of the version's language). */
    public static function newsSlug(?Db $db): string
    {
        self::load($db);

        return self::$news ?? '';
    }

    /**
     * Earlier custom news slugs: /<old>/… redirects permanently to the current news URL, like /novinky and /news.
     *
     * @return list<string>
     */
    public static function previousNewsSlugs(?Db $db): array
    {
        self::load($db);

        return self::$previous ?? [];
    }

    /** Both settings in one query, once per request; invalid stored values are ignored (an import or a direct write must not point /mcp or /oauth at the news). */
    private static function load(?Db $db): void
    {
        if (self::$news !== null || $db === null) {
            return;
        }
        try {
            $stored = $db->pairs("SELECT promenna, hodnota FROM {nastaveni} WHERE promenna IN ('news_slug', 'news_slug_previous')");
            $slug = (string) ($stored['news_slug'] ?? '');
            self::$news = self::storedSlugValid($slug) ? $slug : '';
            self::$previous = self::parsePrevious((string) ($stored['news_slug_previous'] ?? ''), self::$news);
        } catch (\Throwable) {
            self::$news = ''; // site before installation
            self::$previous = [];
        }
    }

    /**
     * The stored list of earlier slugs, cleaned: without the current one, the default words and anything invalid.
     *
     * @return list<string>
     */
    private static function parsePrevious(string $list, string $current): array
    {
        return array_slice(array_values(array_unique(array_filter(explode(',', $list),
            fn (string $s): bool => $s !== '' && $s !== $current && !in_array($s, ['novinky', 'news'], true) && self::storedSlugValid($s)))), 0, self::PREVIOUS_KEPT);
    }

    /**
     * A stored news slug stays valid when only 3.7 reserved its word (an English system path): the site's news keep their
     * address, and the system keeps the Czech path for that endpoint instead (isTaken). A new value is checked by
     * systemSlugError, which refuses the English words too.
     */
    private static function storedSlugValid(string $slug): bool
    {
        return self::systemSlugError($slug) === null
            || (in_array($slug, self::englishWords(), true) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1);
    }

    /**
     * The first segments of the English system paths a page or the news could hold (tasks, form, … and status of status.json).
     *
     * @return list<string>
     */
    private static function englishWords(): array
    {
        return array_values(array_filter(array_map(fn (string $w): string => explode('.', $w)[0], self::SYSTEM_PATHS), fn (string $w): bool => !str_starts_with($w, '_')));
    }

    /**
     * Internal system path (without the leading slash, with an optional ?query or #anchor) to its public English form for
     * App::url(); null when the path is not a system endpoint. A word a page or the news hold stays Czech.
     */
    public static function publicSystemPath(string $path, ?Db $db): ?string
    {
        if (preg_match('#^(' . self::alternatives(array_keys(self::SYSTEM_PATHS)) . ')(?=$|[?\#])#', $path, $m)) {
            $english = self::SYSTEM_PATHS[$m[1]];
        } elseif (preg_match('#^(' . self::alternatives(array_keys(self::SYSTEM_PREFIXES)) . ')(?=$|[/?\#])#', $path, $m)) {
            $english = self::SYSTEM_PREFIXES[$m[1]];
        } else {
            return null;
        }

        return (self::holds($english, $db) ? $m[1] : $english) . substr($path, strlen($m[1]));
    }

    /**
     * Public request path (with the leading slash, after the language prefix and the news slug) to the internal one: the
     * English system paths answer exactly like the Czech ones, without a redirect (POST forms, cron, links from e-mails).
     */
    public static function systemPath(string $path, ?Db $db): string
    {
        $english = array_flip(self::SYSTEM_PATHS);
        if (preg_match('#^/(' . self::alternatives(array_keys($english)) . ')$#', $path, $m)) {
            return self::holds($m[1], $db) ? $path : '/' . $english[$m[1]];
        }
        $prefixes = array_flip(self::SYSTEM_PREFIXES);
        if (preg_match('#^/(' . self::alternatives(array_keys($prefixes)) . ')(?=$|/)#', $path, $m)) {
            return '/' . $prefixes[$m[1]] . substr($path, strlen($m[0]));
        }

        return $path;
    }

    /** @param list<string> $words */
    private static function alternatives(array $words): string
    {
        return implode('|', array_map(fn (string $w): string => preg_quote($w, '#'), $words));
    }

    /**
     * The new value of news_slug_previous when news_slug changes from $old to $new (Core\Settings::set): the old slug is
     * remembered first and the new one drops out, so changing back and forth – also back to empty – never loses an address.
     */
    public static function rememberSlug(string $previous, string $old, string $new): string
    {
        return implode(',', self::parsePrevious(($old !== $new ? $old . ',' : '') . $previous, $new));
    }

    /**
     * Sets the values without the database (tests); null forgets them, so they are read again from the database.
     *
     * @param list<string> $previous
     */
    public static function setNewsSlug(?string $slug, array $previous = []): void
    {
        self::$news = $slug;
        self::$previous = $slug === null ? null : $previous;
    }

    /** Does a page or collection slug collide with the custom news slug? */
    public static function isNewsSlug(string $slug, ?Db $db): bool
    {
        return $slug !== '' && $slug === self::newsSlug($db);
    }

    /** Format and system addresses only (no database): the slug is one lowercase segment and not a path of the system. */
    public static function systemSlugError(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }
        if (strlen($slug) > 40 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            return 'Use only lowercase letters without accents, digits and single hyphens (e.g. blog), at most 40 characters.';
        }
        if (in_array($slug, self::NEWS_RESERVED, true) || (in_array($slug, \Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true) && !in_array($slug, ['novinky', 'news'], true))
            || isset(Language::AVAILABLE[$slug]) || in_array($slug, explode('|', Language::CODES), true)) {
            return 'This URL is used by the system, choose another one.';
        }

        return null;
    }

    /** Why a news slug cannot be used (the message for the administrator), null = it is free. */
    public static function slugError(string $slug, Db $db): ?string
    {
        if (($error = self::systemSlugError($slug)) !== null || $slug === '') {
            return $error;
        }
        if ($db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND smazano IS NULL', [$slug]) !== null
            || $db->value('SELECT 1 FROM {kolekce} WHERE seo_link = ?', [$slug]) !== null) {
            return 'A page or a collection already uses this URL.';
        }
        if ($db->value("SELECT 1 FROM {nastaveni} WHERE promenna = 'indexnow_key' AND hodnota = ?", [$slug]) !== null) {
            return 'This URL is used by the system, choose another one.'; // /<key>.txt for IndexNow
        }

        return null;
    }

    /** English words are used for every language except Czech. */
    public static function isEnglish(string $language): bool
    {
        return $language !== 'cs';
    }

    /** Internal path (without the leading slash, including a ?query) to the public one for the given version language. */
    public static function publicPath(string $path, string $language, ?Db $db): string
    {
        if (!preg_match('#^(novinky|hledani)(?=$|[/?.])#', $path, $m)) {
            return $path;
        }
        $custom = $m[1] === 'novinky' ? self::newsSlug($db) : '';
        if ($custom === '' && (!self::isEnglish($language) || self::isTaken(self::FIRST_SEGMENTS[$m[1]], $db))) {
            return $path;
        }
        $rest = substr($path, strlen($m[1]));
        if ($m[1] === 'novinky' && self::isEnglish($language) && preg_match('#^/(kategorie|stitek)(?=/)#', $rest, $d)) {
            $rest = '/' . self::SECOND_SEGMENTS[$d[1]] . substr($rest, strlen($d[0]));
        }

        return ($custom !== '' ? $custom : self::FIRST_SEGMENTS[$m[1]]) . $rest;
    }

    /**
     * Public request path (with the leading slash, without the language prefix) to the internal one. Returns [internal, canonical]:
     * canonical is the form the URL should have in this language; when it differs from the requested one, Front\Kernel redirects.
     *
     * @return array{0: string, 1: string}
     */
    public static function internalPath(string $path, string $language, ?Db $db): array
    {
        $custom = self::newsSlug($db);
        $previous = self::previousNewsSlugs($db);
        $words = ['novinky', 'hledani', 'news', 'search', ...($custom !== '' ? [$custom] : []), ...$previous];
        if (!preg_match('#^/(' . implode('|', array_map(fn (string $w): string => preg_quote($w, '#'), $words)) . ')(?=$|[/.])#', $path, $m)) {
            return [$path, $path];
        }
        $word = $m[1];
        if ($custom !== '' && $word === $custom) {
            $czech = 'novinky';
        } elseif (in_array($word, $previous, true)) {
            if (self::isUsedBySite($word, $db)) {
                return [$path, $path]; // a page or a collection took the old news slug later
            }
            $czech = 'novinky'; // an earlier news slug redirects to the current one
        } else {
            $czech = array_search($word, self::FIRST_SEGMENTS, true);
            if ($czech !== false && self::isTaken($word, $db)) {
                return [$path, $path]; // the site's own page
            }
        }
        $internal = '/' . ($czech !== false ? $czech : $word) . substr($path, strlen($m[0]));
        if (($czech !== false ? $czech : $word) === 'novinky') {
            $internal = (string) preg_replace_callback('#^/novinky/(category|tag|kategorie|stitek)(?=/)#',
                fn (array $d): string => '/novinky/' . (array_search($d[1], self::SECOND_SEGMENTS, true) ?: $d[1]), $internal);
        }

        return [$internal, '/' . self::publicPath(ltrim($internal, '/'), $language, $db)];
    }

    /** Does a page (not in the trash) or a collection use the slug? Asked only for an earlier news slug. */
    private static function isUsedBySite(string $slug, ?Db $db): bool
    {
        if ($db === null) {
            return false;
        }
        try {
            return $db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND smazano IS NULL', [$slug]) !== null
                || $db->value('SELECT 1 FROM {kolekce} WHERE seo_link = ?', [$slug]) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Is it a page-like URL (no file extension other than .html, not a system address)? Only those follow the url_slash setting.
     * System addresses keep one fixed form whatever the setting: the Claude connection (mcp, oauth, .well-known – OAuth
     * discovery must never be redirected), the cron (ulohy), the endpoints of forms and beacons, and the links sent out in
     * e-mails or shown once (odber – also the one-click List-Unsubscribe-Post –, download, screen). Called with internal
     * paths: the English forms (SYSTEM_PATHS) are read back first, and App::url() never adds a suffix to them. Their words
     * (/tasks.html, /form/) are system addresses too (3.7, N37-6) – unless a page of the site holds the word (heldSystemPaths):
     * that page keeps following the setting like any other. Without the database the words count as the system's.
     */
    public static function pageLike(string $path, ?Db $db = null): bool
    {
        $path = (string) preg_replace('#\.html$#D', '', $path);
        if ($path === '' || $path === '/' || str_contains(basename($path), '.')
            || preg_match('#^/(api|mcp|oauth|\.well-known|popup|formular|vitals|hit|ulohy|odber|download|screen|fleet|souhlas|konverze|_[^/]*)(/|$)#D', $path)) {
            return false;
        }
        $english = array_filter(self::englishWords(), fn (string $w): bool => $w !== 'status'); // status.json has a dot, never a page

        return !preg_match('#^/(' . self::alternatives($english) . ')(/|$)#D', $path, $m) || ($db !== null && self::holds($m[1], $db));
    }

    /** Ending of a page URL by the url_slash setting: bez | s | html. */
    public static function suffix(string $mode): string
    {
        return ['s' => '/', 'html' => '.html'][$mode] ?? '';
    }

    /** Redirect target (path + query) when the request URI is not in the preferred form, else null. $internal = path without language prefix. */
    public static function slashRedirect(string $internal, string $requestUri, string $mode, ?Db $db = null): ?string
    {
        // the raw path, not parse_url: "//a//evil.example/x/" would read as host "a" and path "//evil.example/x/", and a Location
        // starting with two slashes (or a backslash) sends the browser to another site (3.4.2, N34-1)
        [$path, $query] = explode('?', $requestUri, 2) + [1 => ''];
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }
        if (!self::pageLike($internal, $db) || $path === '/' || str_ends_with($path, '//')) {
            return null;
        }
        $target = preg_replace('#(\.html|/)$#', '', $path) . self::suffix($mode);
        if ($target === $path) {
            return null;
        }

        return $target . ($query !== '' ? '?' . $query : '');
    }

    /** Does a page of the site itself occupy the English word (e.g. a page "news" from before 1.2, "form" from before 3.7)? */
    private static function isTaken(string $word, ?Db $db): bool
    {
        if ($db === null) {
            return false;
        }
        if (self::$taken === null) {
            self::$taken = [];
            try {
                $words = ['news', 'search', ...self::englishWords()];
                foreach ($db->all('SELECT seo_link FROM {stranky} WHERE seo_link IN (' . implode(',', array_fill(0, count($words), '?')) . ') AND smazano IS NULL', $words) as $r) {
                    self::$taken[$r['seo_link']] = true;
                }
            } catch (\Throwable) {
                // site before installation or without the table – no own page
            }
        }
        return isset(self::$taken[$word]);
    }

    /**
     * Does the site hold the English system path: a page with its word (status.json cannot collide with a page), or the
     * custom news slug now or earlier – the Kernel rewrites the news slug before the system paths are read.
     */
    private static function holds(string $english, ?Db $db): bool
    {
        if ($db === null || str_starts_with($english, '_')) {
            return false;
        }
        $word = explode('.', $english)[0];

        return $word === self::newsSlug($db) || in_array($word, self::previousNewsSlugs($db), true) || (!str_contains($english, '.') && self::isTaken($word, $db));
    }

    /**
     * The English system paths a page of the site holds, so the system keeps the Czech path for them (Core\Health).
     *
     * @return array<string, string> English path => Czech path
     */
    public static function heldSystemPaths(Db $db): array
    {
        return array_filter(array_flip(self::SYSTEM_PATHS), fn (string $english): bool => self::holds($english, $db), ARRAY_FILTER_USE_KEY);
    }
}
