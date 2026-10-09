<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Admin\Modules\Redirects;

/**
 * Import from any website by its address (2.6): Wix, Webnode, Jimdo, Squarespace, Joomla, Drupal, a WordPress site
 * without an export – whatever serves HTML.
 *
 * How it holds together:
 *  - Finding the pages: the sitemap (robots.txt, /sitemap.xml and the usual variants, sitemap indexes); a site without one
 *    is crawled from the home page along its own links. At most MAX_PAGES addresses (3.7: thousands, read through the
 *    sitemaps across batches), only the site's own domain.
 *  - Politeness (3.7): it is the owner's old site, but still someone's server. robots.txt is read first – a page it
 *    disallows for Kaleta-import (or for every robot) is never downloaded, its Crawl-delay is kept (at most MAX_DELAY
 *    seconds), and without one there is DEFAULT_DELAY between two requests.
 *  - Each page is downloaded through Core\ImageDownloader (public addresses only, no redirects elsewhere, limits), its
 *    main content is taken out (main, article, the usual content containers; never the header, footer, navigation,
 *    cookie bars or forms) and turned into a builder page by Builder\HtmlConverter. Images – also from the site's CDN –
 *    go into Media through Core\Images. Addresses that look like articles (/blog/, /news/, a date in the path, a
 *    publication date in the page) become news items when the News extension is on.
 *  - Pages are created hidden and outside the menu, so nothing changes for visitors until the administrator looks at
 *    them; old addresses redirect to the new ones.
 *  - The work runs in batches of SECONDS (shared hosting), the state is a file in storage/import, and ka_import_mapa
 *    remembers what was imported, so running it again skips finished pages.
 * The design is not copied: the pages take the site's design system; Claude can match the look afterwards.
 */
final class WebImport
{
    /** Addresses one import or report goes through (3.7: was 300 – a shop with two languages has a thousand and more). */
    public const int MAX_PAGES = 3000;
    private const float SECONDS = 15.0;
    private const int IMAGES_PER_PAGE = 40;
    private const int MAX_SITEMAPS = 100;

    /**
     * robots.txt of a hostile or broken old site (3.7, N37-23): at most ROBOTS_BYTES of it are read (search engines read
     * 500 KB) and ROBOTS_RULES rules of the group for Kaleta are kept – the result says when it was cut.
     */
    public const int ROBOTS_BYTES = 512 * 1024;
    public const int ROBOTS_RULES = 500;

    /** Seconds between two saves of the state within one batch: a batch killed by the time limit never starts over (N37-23). */
    private const float CHECKPOINT_SECONDS = 2.0;

    /** Seconds between two requests to the old site without a Crawl-delay, and the longest Crawl-delay kept (a longer one would stall the batches). */
    public const float DEFAULT_DELAY = 0.25;
    public const float MAX_DELAY = 2.0;

    /** The reason a page is not downloaded: robots.txt of the old site disallows it. */
    public const string ROBOTS_REFUSAL = 'The robots.txt of the old site asks robots not to read this page.';

    /** When the last request to the old site ended (the politeness gap holds across the import, the report and their images). */
    private static float $lastRequest = 0.0;

    /** Addresses that are not content pages. */
    private const string SKIP = '#/(wp-admin|wp-json|wp-login|feed|tag|tags|author|category|kategorie|search|hledat|cart|kosik|checkout|login|account|my-account)(/|$)|/page/\d+/?$|\.(xml|json|rss|atom|pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|mp3|mp4|css|js)$#i';

    /** Addresses that look like articles. */
    private const string ARTICLE = '#/(blog|news|novinky|aktuality|clanky|clanek|articles?|posts?|aktuelles|neuigkeiten|actualites|noticias|notizie|aktualnosci|novinky-a-akce)/[^/]+|/\d{4}/\d{2}/#i';

    /** Containers with the main content, in order of preference. */
    private const array CONTENT = ['main', 'article', '[role="main"]', '#content', '#main', '.entry-content', '.post-content', '.page-content', '.content'];

    /** Parts of a page that are never content. */
    private const string NOISE = 'script, style, noscript, template, iframe, form, nav, header, footer, aside, svg, button, dialog, [role="navigation"], [role="banner"], [role="contentinfo"], [aria-hidden="true"]';

    /** Classes and ids of cookie bars, pop-ups, sharing and comment blocks. */
    private const string NOISE_NAMES = '/cookie|consent|gdpr|popup|modal|newsletter|share|sharing|social|breadcrumb|comment|related|sidebar|widget|skip-link|screen-reader/i';

    private float $end = 0.0;

    /** When the state was last handed to the checkpoint of this batch. */
    private float $saved = 0.0;

    /** @var (\Closure(array<string, mixed>): mixed)|null saves the state between the heavy parts of a batch */
    private ?\Closure $checkpoint = null;

    /**
     * The robots.txt rules compiled for matching, with the rules they came from (compiled once per crawl, N37-23).
     *
     * @var array{0: array{0: array<mixed>, 1: array<mixed>}, 1: list<array{0: string, 1: list<string>|null, 2: bool, 3: int}>, 2: list<array{0: string, 1: list<string>|null, 2: bool, 3: int}>}|null
     */
    private static ?array $matcher = null;

    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly int $author, private readonly ImageDownloader $downloader)
    {
    }

    /* ---------- state ---------- */

    /**
     * @param array{jazyk?: string, obrazky?: bool, presmerovani?: bool, novinky?: bool} $options
     * @return array<string, mixed>
     */
    public static function newState(string $url, array $options = []): array
    {
        $c = parse_url(trim($url));
        $origin = strtolower((string) ($c['scheme'] ?? '')) . '://' . strtolower((string) ($c['host'] ?? '')) . (isset($c['port']) ? ':' . $c['port'] : '');

        return [
            'id' => substr(sha1($origin . microtime()), 0, 16), 'web' => $origin, 'domena' => ImageDownloader::domainFromUrl($origin), 'faze' => 'hledani',
            'fronta' => [$origin . '/'], 'mapy' => [], 'mapy_hotovo' => false, 'adresy' => [], 'pozice' => 0,
            'volby' => ['jazyk' => (string) ($options['jazyk'] ?? ''), 'obrazky' => (bool) ($options['obrazky'] ?? true),
                'presmerovani' => (bool) ($options['presmerovani'] ?? true), 'novinky' => (bool) ($options['novinky'] ?? true)],
            'vysledek' => ['stranky' => 0, 'clanky' => 0, 'obrazky' => 0, 'presmerovani' => 0, 'preskoceno' => 0, 'chyb' => 0],
            'chyby' => [], 'zalozeno' => date('Y-m-d H:i:s'),
        ];
    }

    /** Whether the address can be imported at all: http(s), a domain, no user name. */
    public static function validUrl(string $url): bool
    {
        $c = parse_url(trim($url));

        return is_array($c) && in_array(strtolower((string) ($c['scheme'] ?? '')), ['http', 'https'], true) && ($c['host'] ?? '') !== '' && !isset($c['user']) && !isset($c['pass']);
    }

    /** @return array<string, mixed>|null */
    public static function load(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id) || !is_file(self::file($id))) {
            return null;
        }
        $state = json_decode((string) file_get_contents(self::file($id)), true);

        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    public static function save(array $state): void
    {
        file_put_contents(self::file((string) $state['id']), (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function delete(string $id): void
    {
        if (preg_match('/^[a-f0-9]{16}$/D', $id)) {
            @unlink(self::file($id));
        }
    }

    private static function file(string $id): string
    {
        return WpFile::folder() . '/web-' . $id . '.json';
    }

    /* ---------- one batch ---------- */

    /**
     * The records the import has created so far (pages, news items, images, redirects); MCP counts the difference of one
     * step against Claude's hourly change limit (3.7, N37-26).
     *
     * @param array<string, mixed> $state
     */
    public static function created(array $state): int
    {
        $v = is_array($state['vysledek'] ?? null) ? $state['vysledek'] : [];

        return intval($v['stranky'] ?? 0) + intval($v['clanky'] ?? 0) + intval($v['obrazky'] ?? 0) + intval($v['presmerovani'] ?? 0);
    }

    /**
     * What the administrator and Claude should know about how the old site was read (3.7, N37-23, N37-24): a robots.txt
     * that was cut, sitemaps on other hosts or over the limit that were not read.
     *
     * @param array<string, mixed> $state the import's state (or a report's discovery with its robots)
     * @return list<string>
     */
    public static function notes(array $state): array
    {
        $notes = [];
        $robots = is_array($state['robots'] ?? null) ? $state['robots'] : [];
        if (!empty($robots['omezeno'])) {
            $notes[] = t('The robots.txt of the old site is very long: only its first %s KB and %s rules for Kaleta were read.', self::ROBOTS_BYTES >> 10, self::ROBOTS_RULES);
        }
        $foreign = is_array($state['mapy_cizi'] ?? null) ? $state['mapy_cizi'] : [];
        if (intval($foreign['pocet'] ?? 0) > 0) {
            $notes[] = t('%s sitemaps on other hosts were not read (%s): only the old site’s own sitemaps are followed.', intval($foreign['pocet']),
                implode(', ', array_map(strval(...), is_array($foreign['hostitele'] ?? null) ? $foreign['hostitele'] : [])));
        }
        if (intval($state['mapy_navic'] ?? 0) > 0) {
            $notes[] = t('%s more sitemaps were not read: at most %s are read.', intval($state['mapy_navic']), self::MAX_SITEMAPS);
        }

        return $notes;
    }

    /**
     * One batch. $checkpoint saves the state between its heavy parts (after robots.txt, every few seconds), so a batch the
     * server's time limit kills resumes where it was instead of repeating the same work forever (3.7, N37-23).
     *
     * @param array<string, mixed> $state
     * @param (\Closure(array<string, mixed>): mixed)|null $checkpoint
     */
    public function step(array &$state, ?\Closure $checkpoint = null): void
    {
        $this->end = microtime(true) + self::SECONDS;
        $this->saved = microtime(true);
        $this->checkpoint = $checkpoint;
        match ($state['faze']) {
            'hledani' => $this->discover($state),
            'import' => $this->import($state),
            default => null,
        };
    }

    /** @param array<string, mixed> $state */
    private function discover(array &$state): void
    {
        // first the sitemaps; a site without them is crawled from the home page
        if (!$state['mapy_hotovo']) {
            if (!isset($state['robots'])) {
                // robots.txt is read once, at most ROBOTS_BYTES of it, and the state is saved right after (3.7, N37-23)
                $robots = (string) $this->fetch($state['web'] . '/robots.txt', [], false, self::ROBOTS_BYTES);
                $state['robots'] = self::robots($robots);
                preg_match_all('/^\s*sitemap:\s*(\S+)/mi', $robots, $m);
                $state['mapy'] = [];
                $state['mapy_prectene'] = [];
                foreach (array_unique([...$m[1], $state['web'] . '/sitemap.xml', $state['web'] . '/sitemap_index.xml', $state['web'] . '/wp-sitemap.xml']) as $map) {
                    $this->queueSitemap($state, $map);
                }
                $this->checkpoint($state, true);
            }
            // 3.7 (N37-24): no further sitemap once MAX_PAGES addresses are known
            while ($state['mapy'] !== [] && microtime(true) < $this->end && count($state['mapy_prectene']) < self::MAX_SITEMAPS && count($state['adresy']) < self::MAX_PAGES) {
                $map = array_shift($state['mapy']);
                if (in_array($map, $state['mapy_prectene'], true) || !$this->downloader->isAllowedUrl($map)) {
                    continue;
                }
                $state['mapy_prectene'][] = $map;
                try {
                    [$pages, $maps] = self::sitemap((string) $this->fetch($map, (array) ($state['robots'] ?? []), false));
                } catch (HtmlTooLarge $e) {
                    [$pages, $maps] = [[], []]; // a sitemap over a limit is skipped with the reason in the report
                    $state['chyby'] = array_slice([...$state['chyby'], mb_substr(self::path($map) ?: '/', 0, 120) . ' – ' . $e->localized()], -15);
                }
                foreach ($maps as $child) {
                    $this->queueSitemap($state, $child);
                }
                foreach ($pages as $url) {
                    if (count($state['adresy']) >= self::MAX_PAGES) {
                        break;
                    }
                    $this->add($state, $url);
                }
                $this->checkpoint($state);
            }
            if ($state['mapy'] === [] || count($state['mapy_prectene']) >= self::MAX_SITEMAPS || count($state['adresy']) >= self::MAX_PAGES) {
                $state['mapy'] = [];
                $state['mapy_hotovo'] = true;
                if ($state['adresy'] !== []) {
                    $state['fronta'] = []; // the sitemap is enough
                }
                $this->add($state, $state['web'] . '/');
            }
        }
        // crawling along the site's own links (also adds pages the sitemap forgot about, when there is none); the links of
        // a page wait in the state, so the time budget holds within a page with thousands of links too (3.7, N37-23)
        $state['odkazy'] = is_array($state['odkazy'] ?? null) ? array_values($state['odkazy']) : [];
        while (($state['odkazy'] !== [] || $state['fronta'] !== []) && microtime(true) < $this->end && count($state['adresy']) < self::MAX_PAGES) {
            if ($state['odkazy'] === []) {
                $url = array_shift($state['fronta']);
                $html = $this->fetch($url, (array) ($state['robots'] ?? []));
                $state['odkazy'] = $html !== null ? self::links($html, $url) : [];
                continue;
            }
            $links = $state['odkazy'];
            $state['odkazy'] = [];
            foreach ($links as $i => $link) {
                if (count($state['adresy']) >= self::MAX_PAGES) {
                    break;
                }
                if (microtime(true) >= $this->end) {
                    $state['odkazy'] = array_slice($links, $i); // the rest in the next batch
                    break;
                }
                // the site's own links are followed only where its robots.txt lets robots go (its sitemap lists what it wants found)
                if (self::robotsAllow((array) ($state['robots'] ?? []), (string) $link) && $this->add($state, (string) $link)) {
                    $state['fronta'][] = $link;
                }
            }
            $this->checkpoint($state);
        }
        if ($state['mapy_hotovo'] && (($state['fronta'] === [] && $state['odkazy'] === []) || count($state['adresy']) >= self::MAX_PAGES)) {
            $state['faze'] = 'nahled';
            $state['fronta'] = [];
            $state['odkazy'] = [];
        }
    }

    /**
     * A sitemap to read (3.7, N37-24): only on the old site's own host or its www twin – a sitemap elsewhere is never
     * downloaded, only counted for the result – and at most MAX_SITEMAPS of them in the queue.
     *
     * @param array<string, mixed> $state
     */
    private function queueSitemap(array &$state, string $map): void
    {
        $map = trim($map);
        if ($map === '' || in_array($map, $state['mapy'], true) || in_array($map, $state['mapy_prectene'], true)) {
            return;
        }
        if (ImageDownloader::domainFromUrl($map) !== $state['domena']) {
            $hosts = is_array($state['mapy_cizi']['hostitele'] ?? null) ? $state['mapy_cizi']['hostitele'] : [];
            $host = mb_substr((string) parse_url($map, PHP_URL_HOST), 0, 100);
            if ($host !== '' && count($hosts) < 5 && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
            $state['mapy_cizi'] = ['pocet' => intval($state['mapy_cizi']['pocet'] ?? 0) + 1, 'hostitele' => $hosts];

            return;
        }
        if (count($state['mapy']) + count($state['mapy_prectene']) >= self::MAX_SITEMAPS) {
            $state['mapy_navic'] = intval($state['mapy_navic'] ?? 0) + 1;

            return;
        }
        $state['mapy'][] = $map;
    }

    /**
     * Hands the state to the batch's checkpoint – right away, or when CHECKPOINT_SECONDS have passed since the last time.
     *
     * @param array<string, mixed> $state
     */
    private function checkpoint(array $state, bool $now = false): void
    {
        if ($this->checkpoint !== null && ($now || microtime(true) - $this->saved >= self::CHECKPOINT_SECONDS)) {
            ($this->checkpoint)($state);
            $this->saved = microtime(true);
        }
    }

    /** @param array<string, mixed> $state */
    private function add(array &$state, string $url): bool
    {
        $url = self::normalize($url);
        if ($url === '' || isset($state['adresy'][$url]) || count($state['adresy']) >= self::MAX_PAGES || !$this->downloader->isAllowedUrl($url)
            || ImageDownloader::domainFromUrl($url) !== $state['domena'] || preg_match(self::SKIP, (string) parse_url($url, PHP_URL_PATH))) {
            return false;
        }
        $state['adresy'][$url] = 1;

        return true;
    }

    /** @param array<string, mixed> $state */
    private function import(array &$state): void
    {
        $urls = array_keys($state['adresy']);
        while ($state['pozice'] < count($urls) && microtime(true) < $this->end) {
            $url = $urls[$state['pozice']];
            try {
                $this->importPage($url, $state);
            } catch (\RuntimeException | HtmlTooLarge $e) {
                $state['vysledek']['chyb']++;
                $reason = $e instanceof HtmlTooLarge ? $e->localized() : t($e->getMessage());
                $state['chyby'] = array_slice([...$state['chyby'], mb_substr(self::path($url) ?: '/', 0, 120) . ' – ' . $reason], -15);
            }
            $state['pozice']++;
            $this->checkpoint($state);
        }
        if ($state['pozice'] >= count($urls)) {
            $state['faze'] = 'hotovo';
        }
    }

    /** @param array<string, mixed> $state */
    private function importPage(string $url, array &$state): void
    {
        $source = 'web:' . mb_substr((string) $state['domena'], 0, 36);
        $key = sha1($url);
        if ($this->db->value('SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ IN (\'stranka\', \'clanek\') AND cizi_id = ?', [$source, $key]) !== null) {
            $state['vysledek']['preskoceno']++;

            return;
        }
        if (!self::robotsAllow((array) ($state['robots'] ?? []), $url)) {
            throw new \RuntimeException(self::ROBOTS_REFUSAL);
        }
        $html = $this->fetch($url, (array) ($state['robots'] ?? []));
        if ($html === null) {
            throw new \RuntimeException('The page could not be downloaded.');
        }
        $page = self::extract($html, $url);
        if (trim(strip_tags($page['obsah'], '<img>')) === '') {
            throw new \RuntimeException('The page has no content to import.');
        }
        if ($state['volby']['obrazky']) {
            $page['obsah'] = HtmlLimits::guard(function () use ($page, &$state): string {
                return $this->images($page['obsah'], $state);
            });
        }
        // sanitized once more as the very last step before it is stored; over a limit of HtmlLimits the page is skipped
        // with the reason (HtmlTooLarge, recorded by import())
        $page['obsah'] = HtmlLimits::guard(fn (): string => self::safeContent($page['obsah']));
        $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
        $article = $state['volby']['novinky'] && $page['clanek'] && Extensions::isEnabled($this->settings, 'novinky');
        $old = self::path($url);
        if ($article) {
            $idc = $this->createArticle($page, $language);
            $this->map($source, 'clanek', $key, $idc);
            $state['vysledek']['clanky']++;
            $new = ($language !== '' ? $language . '/' : '') . 'novinky/' . (string) $this->db->value('SELECT seo_link FROM {novinky} WHERE idc = ?', [$idc]);
        } else {
            $ids = $this->createPage($page, $old, $language);
            $this->map($source, 'stranka', $key, $ids);
            $state['vysledek']['stranky']++;
            $new = ($language !== '' ? $language . '/' : '') . (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
        }
        if ($state['volby']['presmerovani'] && $old !== '' && $old !== $new && Extensions::isEnabled($this->settings, 'presmerovani')) {
            Redirects::add($this->db, $old, $new);
            $state['vysledek']['presmerovani']++;
        }
    }

    /** @param array{titulek: string, popis: string, obsah: string, datum: string, clanek: bool} $page */
    private function createPage(array $page, string $oldPath, string $language): int
    {
        $base = $oldPath !== '' ? basename($oldPath) : 'home';
        $seo = Pages::freeSlug($this->db, slugify((string) preg_replace('/\.(html?|php|aspx?)$/i', '', $base) ?: $page['titulek'], 110), language: $language);
        $build = $this->build($page['titulek'], $page['obsah']);

        return $this->db->insert('stranky', [
            'seo_link' => $seo, 'titulek' => mb_substr($page['titulek'], 0, 200), 'text' => $page['obsah'], 'stavba' => $build,
            'popis' => mb_substr($page['popis'], 0, 300),
            'zobrazit' => 0, // hidden until the administrator checks it – nothing changes for visitors
            'v_menu' => 0, 'zmeneno' => date('Y-m-d H:i:s'), 'jazyk' => $language,
        ]);
    }

    /** @param array{titulek: string, popis: string, obsah: string, datum: string, clanek: bool} $page */
    private function createArticle(array $page, string $language): int
    {
        $category = (int) $this->db->value('SELECT idt FROM {kategorie} WHERE jazyk = ? ORDER BY idt LIMIT 1', [$language]);
        if ($category === 0) {
            $name = Language::runWith($language !== '' ? $language : Language::defaults($this->settings), fn (): string => t('Aktuality'));
            $category = $this->db->insert('kategorie', ['nazev' => $name, 'seo_link' => Slug::makeUnique(slugify($name), fn (string $a): bool => Slug::taken($this->db, 'kategorie', $a, $language), 120),
                'popis' => '', 'jazyk' => $language]);
        }
        $seo = WpImport::availableSlug(slugify($page['titulek'], 150), fn (string $url): bool => Slug::taken($this->db, 'novinky', $url, $language));
        $now = date('Y-m-d H:i:s');
        $idc = $this->db->insert('novinky', [
            'seo_link' => $seo, 'titulek' => mb_substr($page['titulek'], 0, 255), 'uvod' => $page['popis'] !== '' ? '<p>' . e($page['popis']) . '</p>' : '',
            'text' => $page['obsah'], 'tema' => $category, 'jazyk' => $language, 'autor' => $this->author,
            'datum' => $page['datum'] !== '' ? $page['datum'] : $now, 'visible' => 0, 'zmeneno' => $now, 'oznameno' => $now, // never announced (webhook, IndexNow)
        ]);
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', '', $page['obsah']);

        return $idc;
    }

    /** The page's HTML as a build, the same way as pages from WordPress: a heading and the content in a narrow section. */
    private function build(string $title, string $html): ?string
    {
        // the non-administrator converter: whatever site is imported never decides what goes into Custom HTML
        $conversion = \Kaleta\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, false);
        $build = \Kaleta\Builder\HtmlConverter::withoutClasses($conversion['stavba'], array_column($this->db->all('SELECT nazev FROM {tridy}'), 'nazev'));
        foreach ($build['deti'] as &$section) {
            if ($section['typ'] === 'sekce' && !isset($section['kotva'])) {
                $section['obsah']['sirka'] = 'uzka';
            }
        }
        unset($section);
        [$clean] = \Kaleta\Builder\Build::sanitize($build, false);

        return $clean['deti'] === [] ? null : \Kaleta\Builder\Build::toJson($clean);
    }

    /**
     * Downloads the images of a page into Media and points the page at them; an image that cannot be downloaded is
     * left out (a page must not keep loading images from the old site).
     *
     * @param array<string, mixed> $state
     */
    private function images(string $html, array &$state): string
    {
        $count = 0;

        // on the DOM, not with a regular expression over the markup: an attribute's text must never become a tag
        return Html::rewriteImages($html, function (string $url, string $alt) use (&$count, &$state): array|false {
            if (++$count > self::IMAGES_PER_PAGE || $url === '') {
                return false;
            }
            $image = $this->image($url, $alt, $state);

            return $image === null ? false : ['src' => (string) $image['obr_poloha'], 'alt' => $alt, 'width' => (int) $image['obr_width'], 'height' => (int) $image['obr_height']];
        });
    }

    /**
     * Safe HTML without the old site's classes, ids and responsive image sets (they mean nothing here and could collide with
     * Kaleta's). Done on the DOM, never with a regular expression over the sanitized markup.
     */
    public static function safeContent(string $html): string
    {
        return Html::transform(Html::safe($html), function (\Dom\HTMLElement $body): void {
            // a <picture> keeps just its <img>: the image goes to Media, which makes its own sizes
            foreach (iterator_to_array($body->querySelectorAll('picture')) as $picture) {
                foreach (iterator_to_array($picture->querySelectorAll('source')) as $source) {
                    $source->remove();
                }
                $picture->replaceWith(...iterator_to_array($picture->childNodes));
            }
            foreach (iterator_to_array($body->querySelectorAll('[class], [id], [srcset], [sizes]')) as $element) {
                foreach (['class', 'id', 'srcset', 'sizes'] as $name) {
                    $element->removeAttribute($name);
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null a ka_media row
     */
    private function image(string $url, string $alt, array &$state): ?array
    {
        $source = 'web:' . mb_substr((string) $state['domena'], 0, 36);
        $key = sha1($url);
        $ido = $this->db->value("SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND cizi_id = ?", [$source, $key]);
        if ($ido !== null) {
            return (int) $ido === 0 ? null : $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $ido]);
        }
        $temporary = WpFile::folder() . '/web-obrazek-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            file_put_contents($temporary, self::politeDownload($this->downloader, $url, (array) ($state['robots'] ?? []), true, false));
            $saved = Images::saveFile($temporary, basename((string) parse_url($url, PHP_URL_PATH)) ?: 'image.jpg');
            $saved['nazev'] = mb_substr($alt !== '' ? $alt : $saved['nazev'], 0, 150);
            $saved['ido'] = $this->db->insert('media', $saved + ['vlastnik' => $this->author, 'datum' => date('Y-m-d H:i:s')]);
            $this->map($source, 'obrazek', $key, (int) $saved['ido']);
            $state['vysledek']['obrazky']++;

            return $saved;
        } catch (\RuntimeException) {
            $this->map($source, 'obrazek', $key, 0);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    private function map(string $source, string $type, string $key, int $id): void
    {
        $this->db->run('INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nase_id = VALUES(nase_id)', [$source, $type, $key, $id]);
    }

    /** @param array{disallow?: list<string>, allow?: list<string>, delay?: ?float} $robots */
    private function fetch(string $url, array $robots, bool $checkRobots = true, int $readAtMost = 0): ?string
    {
        try {
            $data = self::politeDownload($this->downloader, $url, $robots, false, $checkRobots, $readAtMost);
        } catch (\RuntimeException) {
            return null;
        }

        return $data !== '' && strlen($data) < 5_000_000 ? $data : null;
    }

    /**
     * A request to the old site with the politeness rules: a page robots.txt disallows is refused (ROBOTS_REFUSAL), and
     * the gap since the previous request (Crawl-delay, else DEFAULT_DELAY) is waited out first. Shared with the report.
     *
     * @param array{disallow?: list<string>, allow?: list<string>, delay?: ?float} $robots self::robots()
     * @throws \RuntimeException why it was not downloaded
     */
    public static function politeDownload(ImageDownloader $downloader, string $url, array $robots, bool $imagesOnly = false, bool $checkRobots = true, int $readAtMost = 0): string
    {
        if ($checkRobots && !self::robotsAllow($robots, $url)) {
            throw new \RuntimeException(self::ROBOTS_REFUSAL);
        }
        $wait = self::$lastRequest + self::delay($robots) - microtime(true);
        if ($wait > 0) {
            usleep((int) round(min($wait, self::MAX_DELAY) * 1_000_000));
        }
        try {
            return $downloader->download($url, $imagesOnly, $readAtMost);
        } finally {
            self::$lastRequest = microtime(true);
        }
    }

    /* ---------- robots.txt (3.7) ---------- */

    /**
     * The rules of robots.txt that apply to Kaleta: the group for "Kaleta-import" (or "kaleta") when there is one,
     * else the group for every robot (*). Allow and Disallow paths may use * and a closing $ (as search engines read them).
     * 3.7 (N37-23): at most ROBOTS_BYTES of the text and ROBOTS_RULES rules per group are kept; omezeno says it was cut.
     *
     * @return array{disallow: list<string>, allow: list<string>, delay: ?float, omezeno: bool}
     */
    public static function robots(string $text): array
    {
        $cut = strlen($text) >= self::ROBOTS_BYTES;
        if ($cut) {
            $text = substr($text, 0, self::ROBOTS_BYTES);
            $text = substr($text, 0, (int) strrpos($text, "\n")); // the last line may be cut in the middle of a rule
        }
        $groups = []; // agent => rules
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (!preg_match('/^([a-z-]+)\s*:\s*(.*)$/i', $line, $m)) {
                continue;
            }
            [$key, $value] = [strtolower($m[1]), trim($m[2])];
            if ($key === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
                continue;
            }
            if (!in_array($key, ['allow', 'disallow', 'crawl-delay'], true) || $agents === []) {
                continue;
            }
            $inRules = true;
            foreach ($agents as $agent) {
                $groups[$agent] ??= ['disallow' => [], 'allow' => [], 'delay' => null, 'omezeno' => $cut];
                if ($key === 'crawl-delay') {
                    $groups[$agent]['delay'] = is_numeric($value) ? (float) $value : $groups[$agent]['delay'];
                } elseif ($value !== '') {
                    if (count($groups[$agent]['disallow']) + count($groups[$agent]['allow']) >= self::ROBOTS_RULES) {
                        $groups[$agent]['omezeno'] = true;
                        continue;
                    }
                    $groups[$agent][$key][] = mb_substr($value, 0, 500);
                }
            }
        }

        return $groups['kaleta-import'] ?? $groups['kaleta'] ?? $groups['*'] ?? ['disallow' => [], 'allow' => [], 'delay' => null, 'omezeno' => $cut];
    }

    /**
     * Whether robots.txt lets Kaleta read the address: the longest matching rule decides, Allow wins a tie.
     * 3.7 (N37-23): the rules are compiled once per crawl into plain prefix and wildcard parts and matched without regular
     * expressions – a hostile pattern can neither burn the CPU nor fail and be skipped (no rule ever fails open).
     *
     * @param array{disallow?: list<string>, allow?: list<string>, delay?: ?float} $robots
     */
    public static function robotsAllow(array $robots, string $url): bool
    {
        $c = parse_url($url);
        $path = (is_array($c) ? ($c['path'] ?? '/') : '/') . (isset($c['query']) ? '?' . $c['query'] : '');
        $rules = [(array) ($robots['disallow'] ?? []), (array) ($robots['allow'] ?? [])];
        if (self::$matcher === null || self::$matcher[0] !== $rules) {
            self::$matcher = [$rules, self::compileRules($rules[0]), self::compileRules($rules[1])];
        }
        $decoded = rawurldecode($path);
        $disallow = self::longestMatch(self::$matcher[1], $path, $decoded);

        return $disallow < 0 || self::longestMatch(self::$matcher[2], $path, $decoded) >= $disallow;
    }

    /**
     * Rules as [the part before the first *, the parts after it (null = no *), anchored by $, the length of the rule].
     *
     * @param array<mixed> $rules
     * @return list<array{0: string, 1: list<string>|null, 2: bool, 3: int}>
     */
    private static function compileRules(array $rules): array
    {
        $compiled = [];
        foreach (array_unique(array_map(strval(...), array_filter($rules, is_scalar(...)))) as $rule) {
            $anchored = str_ends_with($rule, '$');
            $parts = explode('*', $anchored ? substr($rule, 0, -1) : $rule);
            $prefix = (string) array_shift($parts);
            $compiled[] = [$prefix, $parts === [] ? null : $parts, $anchored, strlen($rule)];
        }

        return $compiled;
    }

    /**
     * The length of the longest rule matching the path (as sent or decoded), -1 = none.
     *
     * @param list<array{0: string, 1: list<string>|null, 2: bool, 3: int}> $rules
     */
    private static function longestMatch(array $rules, string $path, string $decoded): int
    {
        $best = -1;
        foreach ($rules as $rule) {
            if ($rule[3] > $best && (self::ruleMatches($rule, $path) || ($decoded !== $path && self::ruleMatches($rule, $decoded)))) {
                $best = $rule[3];
            }
        }

        return $best;
    }

    /**
     * A robots.txt rule against a path: the prefix at the start, then every part after a * in order (the leftmost place
     * of each part leaves the most room for the rest), a closing $ makes the last part the end of the path.
     *
     * @param array{0: string, 1: list<string>|null, 2: bool, 3: int} $rule
     */
    private static function ruleMatches(array $rule, string $path): bool
    {
        [$prefix, $parts, $anchored] = $rule;
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        if ($parts === null) {
            return !$anchored || $path === $prefix;
        }
        $position = strlen($prefix);
        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($i === $last && $anchored) {
                return $part === '' || (str_ends_with($path, $part) && strlen($path) - strlen($part) >= $position);
            }
            if ($part === '') {
                continue;
            }
            $found = strpos($path, $part, $position);
            if ($found === false) {
                return false;
            }
            $position = $found + strlen($part);
        }

        return true;
    }
    /** @param array{delay?: ?float} $robots the seconds between two requests */
    public static function delay(array $robots): float
    {
        $delay = $robots['delay'] ?? null;

        return $delay === null ? self::DEFAULT_DELAY : max(0.0, min(self::MAX_DELAY, (float) $delay));
    }

    /* ---------- pure helpers (unit-tested) ---------- */

    /**
     * Page addresses and further sitemaps from a sitemap or a sitemap index.
     *
     * @return array{0: list<string>, 1: list<string>}
     * @throws HtmlTooLarge when the XML is over a limit of HtmlLimits
     */
    public static function sitemap(string $xml): array
    {
        if ($xml === '' || !str_contains($xml, '<')) {
            return [[], []];
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = HtmlLimits::xml($dom, $xml, LIBXML_NONET);
        } finally {
            libxml_use_internal_errors($previous);
        }
        $doc = $ok ? simplexml_import_dom($dom) : null;
        if ($doc === null) {
            return [[], []];
        }
        $pages = [];
        $maps = [];
        foreach ($doc->children() as $child) {
            $loc = trim((string) ($child->loc ?? $child->children('http://www.sitemaps.org/schemas/sitemap/0.9')->loc ?? ''));
            if ($loc === '') {
                continue;
            }
            $child->getName() === 'sitemap' ? $maps[] = $loc : $pages[] = $loc;
        }

        return [$pages, $maps];
    }

    /**
     * Links of a page to other pages, as absolute addresses without the fragment.
     *
     * @return list<string>
     */
    public static function links(string $html, string $base): array
    {
        preg_match_all('/<a\b[^>]*\bhref="([^"#][^"]*)"/i', $html, $m);
        $links = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^(mailto|tel|javascript|data):#i', $href)) {
                continue;
            }
            $links[] = self::absolute($href, $base);
        }

        return array_values(array_unique(array_filter($links)));
    }

    /** An address without the fragment, tracking parameters and a trailing index file. */
    public static function normalize(string $url): string
    {
        $c = parse_url($url);
        if (!is_array($c) || !isset($c['scheme'], $c['host']) || !in_array(strtolower($c['scheme']), ['http', 'https'], true)) {
            return '';
        }
        $path = (string) preg_replace('#/index\.(html?|php)$#i', '/', $c['path'] ?? '/');
        $query = '';
        if (isset($c['query'])) {
            parse_str($c['query'], $params);
            $params = array_filter($params, fn (string $k): bool => !preg_match('/^(utm_|fbclid|gclid|ref$|lang$)/i', $k), ARRAY_FILTER_USE_KEY);
            $query = $params === [] ? '' : '?' . http_build_query($params);
        }

        return strtolower($c['scheme']) . '://' . strtolower($c['host']) . (isset($c['port']) ? ':' . $c['port'] : '') . ($path === '' ? '/' : $path) . $query;
    }

    /** The path of an address without slashes at the ends: https://a.cz/o-nas/ → o-nas */
    public static function path(string $url): string
    {
        return WpImport::oldPath($url);
    }

    public static function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (!is_array($b) || !isset($b['scheme'], $b['host'])) {
            return '';
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin . $dir . $href;
    }

    /**
     * The content of a page: title, description, publication date, whether it is an article, and the main content as
     * clean HTML with absolute image addresses.
     *
     * @return array{titulek: string, popis: string, obsah: string, datum: string, clanek: bool}
     * @throws HtmlTooLarge when the page or its content is over a limit of HtmlLimits
     */
    public static function extract(string $html, string $url): array
    {
        $doc = HtmlLimits::document($html);
        $meta = function (string $selector) use ($doc): string {
            return trim((string) $doc->querySelector($selector)?->getAttribute('content'));
        };
        $h1 = $doc->querySelector('h1');
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $h1?->textContent));
        if ($title === '') {
            $title = $meta('meta[property="og:title"]') ?: trim((string) $doc->querySelector('title')?->textContent);
            $title = trim((string) preg_split('/\s+[|–—-]\s+/u', $title)[0]);
        }
        $description = $meta('meta[name="description"]') ?: $meta('meta[property="og:description"]');
        $date = $meta('meta[property="article:published_time"]') ?: trim((string) $doc->querySelector('article time[datetime], time[datetime]')?->getAttribute('datetime'));
        $timestamp = $date !== '' ? strtotime($date) : false;

        $root = null;
        foreach (self::CONTENT as $selector) {
            $candidate = $doc->querySelector($selector);
            if ($candidate !== null && mb_strlen(trim($candidate->textContent)) > 80) {
                $root = $candidate;
                break;
            }
        }
        $root ??= $doc->body;
        if ($root === null) {
            return ['titulek' => $title, 'popis' => $description, 'obsah' => '', 'datum' => '', 'clanek' => false];
        }
        // an element inside one removed earlier is already gone with it
        foreach (iterator_to_array($root->querySelectorAll(self::NOISE)) as $node) {
            if ($root->contains($node)) {
                $node->remove();
            }
        }
        foreach (iterator_to_array($root->querySelectorAll('[class], [id]')) as $node) {
            if ($root->contains($node) && preg_match(self::NOISE_NAMES, $node->getAttribute('class') . ' ' . $node->getAttribute('id'))) {
                $node->remove();
            }
        }
        // the title becomes the page heading; lazy-loaded images get their real address
        $root->querySelector('h1')?->remove();
        foreach (iterator_to_array($root->querySelectorAll('img')) as $img) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('src') ?: '';
            if ($src === '' || str_starts_with($src, 'data:')) {
                $srcset = $img->getAttribute('data-srcset') ?: $img->getAttribute('srcset') ?: '';
                $src = $srcset !== '' ? trim((string) preg_replace('/\s+\S+$/', '', trim((string) explode(',', $srcset)[array_key_last(explode(',', $srcset))]))) : '';
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                $img->remove();
                continue;
            }
            $img->setAttribute('src', self::absolute($src, $url));
        }
        foreach (iterator_to_array($root->querySelectorAll('a[href]')) as $a) {
            $href = $a->getAttribute('href') ?? '';
            $absolute = self::absolute($href, $url);
            // links within the old site become paths; its pages are redirected to the new addresses after the import
            if ($absolute !== '' && ImageDownloader::domainFromUrl($absolute) === ImageDownloader::domainFromUrl($url)) {
                $a->setAttribute('href', '/' . self::path($absolute));
            }
        }
        $content = trim((string) preg_replace('/\s+/u', ' ', $root->innerHTML));
        $article = ($timestamp !== false && $doc->querySelector('article') !== null) || preg_match(self::ARTICLE, (string) parse_url($url, PHP_URL_PATH)) === 1;

        return [
            'titulek' => $title !== '' ? mb_substr($title, 0, 200) : t('(untitled)'),
            'popis' => mb_substr(trim($description), 0, 300),
            'obsah' => HtmlLimits::guard(fn (): string => self::safeContent($content)),
            'datum' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '',
            'clanek' => $article,
        ];
    }
}
