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
 *    main content is taken out (main, article, the usual content containers; never the header, footer, navigation or
 *    cookie bars) and turned into a builder page by Builder\HtmlConverter. Images – also from the site's CDN –
 *    go into Media through Core\Images. Addresses that look like articles (/blog/, /news/, a date in the path, a
 *    publication date in the page) become news items when the News extension is on.
 *  - 3.33: the widgets of the old page (Builder\HtmlWidgets – forms, accordions and FAQ, tabs, video and map frames,
 *    embedded booking pages, counters) are taken out before the page is sanitized and become their elements; a form
 *    whose fields a script draws gets a placeholder and a line in the result. Files the page links to (PDF, documents,
 *    videos) go into Media within the download limits, and the old <title> and og:image into the page's SEO fields.
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

    /** Files one page may bring into Media (3.33): a price list, a brochure, a video – a page with more links them to the old site. */
    private const int FILES_PER_PAGE = 10;

    /** Widgets taken out of one page (3.33); the rest stays in the page as its text. */
    private const int WIDGETS_PER_PAGE = 60;

    /** Linked files that are downloaded into Media (3.33) – documents and media, the extensions Core\Files takes. */
    private const array FILE_LINKS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv', 'zip', 'epub', 'ics', 'gpx', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'webm'];

    /** File hosts of the builders besides the old site's own domain (Wix, Squarespace, Webflow keep the files there). */
    private const string FILE_HOSTS = '/(^|\.)(wixstatic\.com|usrfiles\.com|filesusr\.com|squarespace\.com|squarespace-cdn\.com|website-files\.com|webflow\.com)$/i';
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
    private const string NOISE_NAMES = '/cookie|consent|gdpr|popup|modal|newsletter|share|sharing|social|breadcrumb|comment|related|sidebar|(?<![\w-])widget|skip-link|screen-reader/i';

    /**
     * Elements that are noise wherever they are (NOISE without the widget tags – a form or a frame is taken out as a widget
     * first, 3.33). "widget" counts only as the start of a class (WordPress sidebars: widget, widget_text), never inside
     * one – every block of Elementor is an elementor-widget.
     */
    private const string NOISE_BLOCKS = 'nav, header, footer, aside, dialog, svg, script, style, noscript, template, [role="navigation"], [role="banner"], [role="contentinfo"], [aria-hidden="true"]';

    private float $end = 0.0;

    /** Requests to the old site that answered in this batch, and why the last one did not (3.33, M6: an unreachable site). */
    private int $answered = 0;
    private string $lastFailure = '';

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
                'presmerovani' => (bool) ($options['presmerovani'] ?? true), 'novinky' => (bool) ($options['novinky'] ?? true),
                'ponechat_vzory' => []], // 3.33 (M2): the starter pages the preview's choice keeps (Core\StarterPages)
            'vysledek' => ['stranky' => 0, 'clanky' => 0, 'obrazky' => 0, 'presmerovani' => 0, 'preskoceno' => 0, 'chyb' => 0],
            'chyby' => [], 'zalozeno' => date('Y-m-d H:i:s'),
            'vzory' => ['nahrazeno' => [], 'ponechano' => []], // 3.33 (M2): the starter pages replaced and kept (StarterPages::makeRoom)
            'zmenene_adresy' => [], // 3.33 (M3): [old, new, shortened | flattened] – an old path that could not be kept as it was
            // 3.33: what the pages kept (forms, FAQ, videos…, files, SEO titles) and what needs a person (an unreadable form)
            'zachovano' => [], 'pozor' => [],
        ];
    }

    /**
     * The starter pages the import's pages would land on, for the preview (3.33, M2): which ones it replaces, which ones
     * the person keeps or someone edited (StarterPages::choices).
     *
     * @param array<string, mixed> $state
     * @return list<array{old: string, page_id: int, title: string, address: string, replace: bool, why: string}>
     */
    public static function starterChoices(Db $db, Settings $settings, array $state): array
    {
        $language = Language::column($settings, (string) ($state['volby']['jazyk'] ?? ''));
        $news = ($state['volby']['novinky'] ?? true) && Extensions::isEnabled($settings, 'novinky');
        $targets = function () use ($state, $language, $news): \Generator {
            foreach (array_keys((array) $state['adresy']) as $url) {
                if ($news && preg_match(self::ARTICLE, (string) parse_url((string) $url, PHP_URL_PATH)) === 1) {
                    continue; // an article becomes a news item, never a page
                }
                $old = self::path((string) $url);
                yield ['old' => $old, 'slug' => self::plannedSlug($old, '', (string) ($state['volby']['jazyk'] ?? '')), 'language' => $language, 'home' => $old === ''];
            }
        };

        return StarterPages::choices($db, $settings, $targets(), self::keptStarters($state));
    }

    /**
     * @param array<string, mixed> $state
     * @return list<int>
     */
    public static function keptStarters(array $state): array
    {
        return array_values(array_map('intval', array_filter((array) ($state['volby']['ponechat_vzory'] ?? []), is_numeric(...))));
    }

    /**
     * The address an old path asks for here, before a number makes it free (createPage): 3.33 (M3) the whole old path,
     * parents and all (Core\ImportPath).
     */
    public static function plannedSlug(string $oldPath, string $title, string $languageCode = ''): string
    {
        return ImportPath::plan($oldPath, self::leafSlug($oldPath, $title), $languageCode)[0];
    }

    /** The slug of the page itself: its last part without a file extension, else its title ('home' for the home page). */
    public static function leafSlug(string $oldPath, string $title): string
    {
        $base = $oldPath !== '' ? basename($oldPath) : 'home';

        return slugify((string) preg_replace('/\.(html?|php|aspx?)$/i', '', $base) ?: $title, 110);
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

        return intval($v['stranky'] ?? 0) + intval($v['clanky'] ?? 0) + intval($v['obrazky'] ?? 0) + intval($v['presmerovani'] ?? 0)
            + intval($state['zachovano']['soubory'] ?? 0); // 3.33: the linked files brought into Media
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
        if (isset($state['nedostupny'])) {
            // 3.33 (M6): said plainly – an unreachable old site must not look like an empty one
            $notes[] = t('The old site could not be reached (%s) – nothing was found because nothing could be read, not because the site is empty. Check the address and that the site is online and reachable from this server.', t((string) $state['nedostupny']));
        }
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
        $this->answered = 0;
        $this->lastFailure = '';
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
        // 3.33 (M6): whether the old site answered at all – nothing found because nothing could be read is not an empty site
        $state['odpovedi'] = (int) ($state['odpovedi'] ?? 0) + $this->answered;
        if ($this->lastFailure !== '') {
            $state['posledni_chyba'] = mb_substr($this->lastFailure, 0, 200);
        }
        if ($state['mapy_hotovo'] && (($state['fronta'] === [] && $state['odkazy'] === []) || count($state['adresy']) >= self::MAX_PAGES)) {
            if ($state['odpovedi'] === 0) {
                $state['nedostupny'] = (string) ($state['posledni_chyba'] ?? '') !== '' ? (string) $state['posledni_chyba'] : 'The old site did not answer.';
            }
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
            ImportPath::linkParents($this->db); // 3.33 (M3): a child imported before its parent
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
        $widgets = $page['prvky'];
        if (trim(strip_tags($page['obsah'], '<img>')) === '' && $widgets === []) {
            throw new \RuntimeException('The page has no content to import.');
        }
        $old = self::path($url);
        $seo = ['seo_titulek' => $page['seo_titulek'], 'obrazek' => ''];
        if ($state['volby']['obrazky']) {
            // images and linked files into Media – in the page and in its widgets (an image in an accordion answer, a video file)
            $page['obsah'] = HtmlLimits::guard(function () use ($page, $url, &$state): string {
                return $this->files($this->images($page['obsah'], $state), $url, $state);
            });
            foreach ($widgets as $i => $widget) {
                $widgets[$i]['html'] = HtmlLimits::guard(function () use ($widget, $url, &$state): string {
                    return $this->files($this->images($widget['html'], $state), $url, $state);
                });
            }
            // the old page's share image (og:image) becomes the page's own
            $share = $page['obrazek'] !== '' ? $this->image($page['obrazek'], $page['titulek'], $state) : null;
            $seo['obrazek'] = $share !== null ? (string) $share['obr_poloha'] : '';
        }
        foreach ($widgets as $widget) {
            if ($widget['kind'] === 'unreadable_form') {
                self::attention($state, t('/%s – a form whose fields a script draws could not be read: a placeholder marks its place, add a Form element with the same fields.', $old));
            }
        }
        // sanitized once more as the very last step before it is stored; over a limit of HtmlLimits the page is skipped
        // with the reason (HtmlTooLarge, recorded by import()); the text of the widgets takes their place in the text
        $content = $page['obsah'];
        $page['obsah'] = HtmlLimits::guard(fn (): string => self::safeContent(self::widgetText($content, $widgets)));
        $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
        $article = $state['volby']['novinky'] && $page['clanek'] && Extensions::isEnabled($this->settings, 'novinky');
        if ($article) {
            $idc = $this->createArticle($page, $language, $seo);
            $this->map($source, 'clanek', $key, $idc);
            $state['vysledek']['clanky']++;
            $new = ($language !== '' ? $language . '/' : '') . 'novinky/' . (string) $this->db->value('SELECT seo_link FROM {novinky} WHERE idc = ?', [$idc]);
        } else {
            $build = $this->build($page['titulek'], HtmlLimits::guard(fn (): string => self::safeContent($content)), $widgets);
            // 3.33 (M2): an untouched starter page at the address (or the starter home page for the old home page) makes room
            $state['vzory'] = is_array($state['vzory'] ?? null) ? $state['vzory'] : [];
            // 3.33 (M3): the old path is kept, parents and all (Core\ImportPath)
            $target = ImportPath::target($this->db, $old, self::leafSlug($old, $page['titulek']), $language, (string) $state['volby']['jazyk']);
            $starter = StarterPages::makeRoom($this->db, $this->settings, $old, $target['slug'], $language, $old === '', self::keptStarters($state), $state['vzory']);
            $ids = $this->createPage($page, $target, $language, $build, $seo);
            if ($starter !== null && $starter['home']) {
                $this->settings->set('home_page', (string) $ids);
            }
            $this->map($source, 'stranka', $key, $ids);
            ImportLayout::remember($this->db, $ids, $build); // "layout not rebuilt yet" until the build is edited
            $kept = self::keptElements(\Kaleta\Builder\Build::fromJson($build) ?? []);
            foreach ($kept + ['seo' => $seo['seo_titulek'] !== '' || $seo['obrazek'] !== '' ? 1 : 0] as $type => $count) {
                $state['zachovano'][$type] = intval($state['zachovano'][$type] ?? 0) + $count;
            }
            $state['vysledek']['stranky']++;
            $new = ($language !== '' ? $language . '/' : '') . (string) $this->db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
            if ($target['note'] !== '' && count($state['zmenene_adresy'] ?? []) < 200) {
                $state['zmenene_adresy'][] = ['/' . $old, '/' . $new, $target['note']]; // shortened or flattened – listed in the result
            }
        }
        if ($state['volby']['presmerovani'] && $old !== '' && $old !== $new && Extensions::isEnabled($this->settings, 'presmerovani')) {
            Redirects::add($this->db, $old, $new);
            $state['vysledek']['presmerovani']++;
        }
    }

    /**
     * @param array{titulek: string, popis: string, obsah: string, datum: string, clanek: bool} $page
     * @param array{slug: string, parent: ?int, note: string} $target where it lands (Core\ImportPath::target)
     * @param array{seo_titulek: string, obrazek: string} $seo the old <title> and the share image in Media (3.33)
     */
    private function createPage(array $page, array $target, string $language, ?string $build, array $seo): int
    {
        $slug = Pages::freeSlug($this->db, $target['slug'], language: $language);

        return $this->db->insert('stranky', [
            'seo_link' => $slug, 'titulek' => mb_substr($page['titulek'], 0, 200), 'text' => $page['obsah'], 'stavba' => $build,
            'popis' => mb_substr($page['popis'], 0, 300), 'seo_titulek' => mb_substr($seo['seo_titulek'], 0, 200), 'obrazek' => mb_substr($seo['obrazek'], 0, 255),
            'zobrazit' => 0, // hidden until the administrator checks it – nothing changes for visitors
            'v_menu' => 0, 'zmeneno' => date('Y-m-d H:i:s'), 'jazyk' => $language,
            'nadrazena' => $target['parent'],
        ]);
    }

    /**
     * @param array{titulek: string, popis: string, obsah: string, datum: string, clanek: bool} $page
     * @param array{seo_titulek: string, obrazek: string} $seoFields the old <title> and the share image (3.33): the share image
     *        is the article's featured image unless the text already shows it
     */
    private function createArticle(array $page, string $language, array $seoFields): int
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
            'seo_titulek' => mb_substr($seoFields['seo_titulek'], 0, 255),
            'obrazek' => $seoFields['obrazek'] !== '' && !str_contains($page['obsah'], $seoFields['obrazek']) ? mb_substr($seoFields['obrazek'], 0, 255) : '',
            'datum' => $page['datum'] !== '' ? $page['datum'] : $now, 'visible' => 0, 'zmeneno' => $now, 'oznameno' => $now, // never announced (webhook, IndexNow)
        ]);
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', '', $page['obsah']);

        return $idc;
    }

    /**
     * The page's HTML as a build, the same way as pages from WordPress: a heading and the content in a narrow section.
     * 3.33: the widgets taken out of the page (<kaleta-widget> in $html) become their elements.
     *
     * @param list<array{html: string, kind: string}> $widgets
     */
    private function build(string $title, string $html, array $widgets = []): ?string
    {
        // the non-administrator converter: whatever site is imported never decides what goes into Custom HTML
        $conversion = \Kaleta\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, false, $widgets);
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
     * The page's text with each widget's text in place of its marker (the text column feeds search, llms.txt and going back
     * to text): the content of the element the widget becomes (Build::asText) – questions and answers, tab contents, a
     * video link; a form has none.
     *
     * @param list<array{html: string, kind: string}> $widgets
     */
    public static function widgetText(string $html, array $widgets): string
    {
        return (string) preg_replace_callback('#<kaleta-widget title="(\d+)"></kaleta-widget>#', function (array $m) use ($widgets): string {
            $widget = $widgets[(int) $m[1]] ?? null;
            if ($widget === null || $widget['kind'] === 'unreadable_form') {
                return '';
            }

            return \Kaleta\Builder\Build::asText(\Kaleta\Builder\HtmlConverter::convert($widget['html'], false)['stavba']);
        }, $html);
    }

    /**
     * What the import kept, in English for MCP (3.33): forms, FAQ, tabs, videos, maps, embedded pages, counters, files in
     * Media and pages with the old SEO title or share image.
     *
     * @param array<string, mixed> $state
     * @return array<string, int>
     */
    public static function keptSummary(array $state): array
    {
        $kept = is_array($state['zachovano'] ?? null) ? $state['zachovano'] : [];
        $names = ['formulare' => 'forms', 'faq' => 'faq', 'zalozky' => 'tabs', 'videa' => 'videos', 'mapy' => 'maps', 'vlozeni' => 'embeds',
            'pocitadla' => 'counters', 'soubory' => 'files', 'seo' => 'pages_with_seo_fields'];

        return array_filter(array_combine(array_values($names), array_map(fn (string $key): int => intval($kept[$key] ?? 0), array_keys($names))));
    }

    /**
     * How many elements of each kept kind a build has (3.33): forms, FAQ, tabs, videos, maps, embedded pages, counters.
     *
     * @param array<string, mixed> $build
     * @return array<string, int>
     */
    public static function keptElements(array $build): array
    {
        $kinds = ['formular' => 'formulare', 'faq' => 'faq', 'zalozky' => 'zalozky', 'video' => 'videa', 'mapa' => 'mapy', 'vlozeni' => 'vlozeni', 'pocitadlo' => 'pocitadla'];
        $count = [];
        $walk = function (array $children) use (&$walk, &$count, $kinds): void {
            foreach ($children as $p) {
                if (is_array($p) && isset($kinds[$p['typ'] ?? ''])) {
                    $count[$kinds[$p['typ']]] = ($count[$kinds[$p['typ']]] ?? 0) + 1;
                }
                if (is_array($p) && is_array($p['deti'] ?? null)) {
                    $walk($p['deti']);
                }
            }
        };
        $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);

        return $count;
    }

    /**
     * A line about what needs a person after the import (3.33): an unreadable form, a file that could not be downloaded.
     * The last 30 are kept.
     *
     * @param array<string, mixed> $state
     */
    private static function attention(array &$state, string $line): void
    {
        $state['pozor'] = array_slice([...(is_array($state['pozor'] ?? null) ? $state['pozor'] : []), mb_substr($line, 0, 300)], -30);
    }

    /**
     * Files the page links to – a price list, a brochure, a video – are downloaded into Media and the page points at them
     * (3.33): documents and media of the extensions Core\Files takes, from the old site's domain or its builder's file host,
     * at most FILES_PER_PAGE per page, each within the download limits of Core\ImageDownloader. A file that cannot be
     * downloaded keeps its link (a video is left out) and the result says so.
     *
     * @param array<string, mixed> $state
     */
    private function files(string $html, string $pageUrl, array &$state): string
    {
        if (!preg_match('/\b(href|src)=/i', $html)) {
            return $html;
        }
        $count = 0;
        $changed = false;
        $output = Html::transform($html, function (\Dom\HTMLElement $body) use (&$count, &$changed, &$state, $pageUrl): void {
            foreach (iterator_to_array($body->querySelectorAll('a[href], video[src], source[src]')) as $element) {
                if (!$body->contains($element)) {
                    continue; // a <source> of a video left out before
                }
                $attribute = strtolower($element->localName) === 'a' ? 'href' : 'src';
                $url = self::absolute(html_entity_decode(trim((string) $element->getAttribute($attribute)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $pageUrl);
                $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                if ($url === '' || !in_array($extension, self::FILE_LINKS, true)
                    || (ImageDownloader::domainFromUrl($url) !== $state['domena'] && !preg_match(self::FILE_HOSTS, (string) parse_url($url, PHP_URL_HOST)))) {
                    continue;
                }
                $file = ++$count <= self::FILES_PER_PAGE ? $this->linkedFile($url, $state) : null;
                $changed = true;
                if ($file !== null) {
                    $element->setAttribute($attribute, (string) $file['obr_poloha']);
                    continue;
                }
                $old = '/' . self::path($url);
                $video = $element->closest('video');
                if ($video !== null) {
                    $video->remove(); // a page never plays a video from the old site
                    self::attention($state, t('/%s – the video %s could not be downloaded: add it in the builder.', self::path($pageUrl), $old));
                    continue;
                }
                // a link within the old site becomes a path, as the other links do (the domain moves here)
                if (ImageDownloader::domainFromUrl($url) === $state['domena']) {
                    $element->setAttribute($attribute, $old);
                }
                self::attention($state, t('/%s – the file %s could not be downloaded into Media: it still links to the old site.', self::path($pageUrl), $old));
            }
        });

        return $changed ? $output : $html;
    }

    /**
     * A linked file in Media (3.33): once per address (ka_import_mapa, type soubor), through the polite and IP-pinned
     * download, saved by Core\Files (allowed extensions, the content checked against them).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null a ka_media row
     */
    private function linkedFile(string $url, array &$state): ?array
    {
        $source = 'web:' . mb_substr((string) $state['domena'], 0, 36);
        $key = sha1($url);
        $ido = $this->db->value("SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = 'soubor' AND cizi_id = ?", [$source, $key]);
        if ($ido !== null) {
            return (int) $ido === 0 ? null : $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $ido]);
        }
        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $temporary = WpFile::folder() . '/web-soubor-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            file_put_contents($temporary, self::politeDownload($this->downloader, $url, (array) ($state['robots'] ?? []), false, false));
            $saved = Files::saveFile($temporary, $name);
            $saved['ido'] = $this->db->insert('media', $saved + ['vlastnik' => $this->author, 'datum' => date('Y-m-d H:i:s')]);
            $this->map($source, 'soubor', $key, (int) $saved['ido']);
            $state['zachovano']['soubory'] = intval($state['zachovano']['soubory'] ?? 0) + 1;

            return $saved;
        } catch (\RuntimeException) {
            $this->map($source, 'soubor', $key, 0);

            return null;
        } finally {
            @unlink($temporary);
        }
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
        } catch (\RuntimeException $e) {
            // a page that does not exist is an answer too: only a site that never answers is unreachable (3.33, M6)
            if ($e->getCode() >= 400) {
                $this->answered++;
            } else {
                $this->lastFailure = $e->getMessage();
            }

            return null;
        }
        $this->answered++;

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
        // lines by CR/LF only: robots.txt may not be UTF-8 (a Latin-1 comment) and a failed split must never drop the rules (3.11, N311-1)
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
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
        // raw UTF-8 (an emoji in a sitemap) is percent-encoded first: parse_url() turns the bytes 0x80–0x9F into "_" (3.18.2)
        $url = (string) preg_replace_callback('/[^\x00-\x7F]+/', fn (array $m): string => rawurlencode($m[0]), $url);
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
     * clean HTML with absolute image addresses. 3.33: also the widgets taken out of the content before it was cleaned
     * (prvky – <kaleta-widget title="N"> in obsah stands for the N-th; Builder\HtmlConverter turns them into elements),
     * the page's <title> when it says more than the heading (seo_titulek) and its share image (obrazek, og:image).
     *
     * @return array{titulek: string, popis: string, obsah: string, datum: string, clanek: bool, prvky: list<array{html: string, kind: string}>, seo_titulek: string, obrazek: string}
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
        $documentTitle = trim((string) preg_replace('/\s+/u', ' ', (string) $doc->querySelector('title')?->textContent));
        if ($title === '') {
            $title = $meta('meta[property="og:title"]') ?: $documentTitle;
            $title = trim((string) preg_split('/\s+[|–—-]\s+/u', $title)[0]);
        }
        $description = $meta('meta[name="description"]') ?: $meta('meta[property="og:description"]');
        $date = $meta('meta[property="article:published_time"]') ?: trim((string) $doc->querySelector('article time[datetime], time[datetime]')?->getAttribute('datetime'));
        $timestamp = $date !== '' ? strtotime($date) : false;
        $shareImage = $meta('meta[property="og:image"]') ?: $meta('meta[property="og:image:url"]') ?: $meta('meta[name="twitter:image"]');
        $shareImage = $shareImage !== '' && !str_starts_with($shareImage, 'data:') ? self::absolute($shareImage, $url) : '';
        $seo = [
            // the old <title> is kept when it says more than the page heading ("Bespoke kitchens – Northwind Joinery")
            'seo_titulek' => $documentTitle !== '' && mb_strtolower($documentTitle) !== mb_strtolower($title) ? mb_substr($documentTitle, 0, 200) : '',
            'obrazek' => preg_match('#^https?://#i', $shareImage) ? $shareImage : '',
        ];

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
            return ['titulek' => $title, 'popis' => $description, 'obsah' => '', 'datum' => '', 'clanek' => false, 'prvky' => []] + $seo;
        }
        // lazy-loaded images get their real address, links and media absolute ones – also inside the widgets taken out below
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
        foreach (iterator_to_array($root->querySelectorAll('video[src], source[src]')) as $media) {
            $media->setAttribute('src', self::absolute((string) $media->getAttribute('src'), $url));
        }
        foreach (iterator_to_array($root->querySelectorAll('a[href]')) as $a) {
            $href = $a->getAttribute('href') ?? '';
            if (str_starts_with($href, '#')) {
                continue; // a link within the page stays as it is (3.33: tabs point at their panels this way)
            }
            $absolute = self::absolute($href, $url);
            // links within the old site become paths; its pages are redirected to the new addresses after the import. A link
            // to a file stays whole: the import downloads the file into Media (3.33)
            if ($absolute !== '' && ImageDownloader::domainFromUrl($absolute) === ImageDownloader::domainFromUrl($url)
                && !in_array(strtolower(pathinfo((string) parse_url($absolute, PHP_URL_PATH), PATHINFO_EXTENSION)), self::FILE_LINKS, true)) {
                $a->setAttribute('href', '/' . self::path($absolute));
            }
        }
        // 3.33: the widgets come out before the noise goes – a closed accordion panel is aria-hidden, a question a <button>,
        // a map an <iframe>, a form a <form>; the sanitizer would drop each of them
        $widgets = self::takeWidgets($root);
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
        // the title becomes the page heading
        $root->querySelector('h1')?->remove();
        $content = trim((string) preg_replace('/\s+/u', ' ', $root->innerHTML));
        $article = ($timestamp !== false && $doc->querySelector('article') !== null) || preg_match(self::ARTICLE, (string) parse_url($url, PHP_URL_PATH)) === 1;

        return [
            'titulek' => $title !== '' ? mb_substr($title, 0, 200) : t('(untitled)'),
            'popis' => mb_substr(trim($description), 0, 300),
            'obsah' => HtmlLimits::guard(fn (): string => self::safeContent($content)),
            'datum' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : '',
            'clanek' => $article,
            'prvky' => $widgets,
        ] + $seo;
    }

    /**
     * Takes the widgets out of the content (3.33, Builder\HtmlWidgets): each becomes <kaleta-widget title="N"> and its HTML,
     * as the old site wrote it, goes into the list for Builder\HtmlConverter. Top-down, so an element that holds nothing but
     * the widget is taken whole; never inside the noise (navigation, header, footer, cookie bars, pop-ups, newsletter boxes).
     * A form whose fields cannot be read is marked unreadable_form. A <kaleta-widget> the old page wrote itself is dropped.
     *
     * @return list<array{html: string, kind: string}>
     */
    private static function takeWidgets(\Dom\Element $root): array
    {
        foreach (iterator_to_array($root->querySelectorAll('kaleta-widget')) as $foreign) {
            if ($root->contains($foreign)) {
                $foreign->remove();
            }
        }
        $widgets = [];
        $walk = function (\Dom\Element $parent) use (&$walk, &$widgets): void {
            foreach (iterator_to_array($parent->childNodes) as $node) {
                if (!$node instanceof \Dom\Element || count($widgets) >= self::WIDGETS_PER_PAGE || $node->matches(self::NOISE_BLOCKS)
                    || preg_match(self::NOISE_NAMES, $node->getAttribute('class') . ' ' . $node->getAttribute('id'))) {
                    continue; // the noise goes with the rest below
                }
                $kind = \Kaleta\Builder\HtmlWidgets::kind($node);
                if ($kind === 'form' && \Kaleta\Builder\HtmlWidgets::formFields($node) === 0) {
                    $kind = 'unreadable_form';
                }
                $document = $node->ownerDocument;
                if ($kind === null || $document === null) {
                    $walk($node);
                    continue;
                }
                $widgets[] = ['html' => Html::outer($node), 'kind' => $kind];
                $marker = $document->createElement('kaleta-widget');
                $marker->setAttribute('title', (string) (count($widgets) - 1));
                $node->replaceWith($marker);
            }
        };
        $walk($root);

        return $widgets;
    }
}
