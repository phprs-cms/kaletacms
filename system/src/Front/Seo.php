<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;

/**
 * SEO, GEO, analytics and consent: robots.txt, sitemap.xml, llms.txt, Markdown version of a news item,
 * tags for <head> (verification, structured data, tracking codes) and the cookie bar before </body>.
 * Everything is driven by Settings; layouts only print the variables $hlava and $pata.
 */
final class Seo
{
    /** Bots of AI services that the switch in Settings applies to. */
    private const array AI_BOTS = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'cohere-ai'];

    private readonly string $siteSettings;

    /** Site root without the language version prefix. */
    private readonly string $root;

    public function __construct(private readonly App $app)
    {
        $this->siteSettings = $app->request->origin() . $app->url('');
        $this->root = $app->request->origin() . $app->request->basePath() . '/';
    }

    /** System path in the language of the currently shown version (/news outside Czech) – Core\Routes. */
    private function path(string $path): string
    {
        return \Kaleta\Core\Routes::publicPath($path, $this->app->languagePrefix !== '' ? $this->app->languagePrefix : \Kaleta\Core\Language::defaults($this->app->settings()), $this->app->db());
    }

    /**
     * security.txt (RFC 9116, 2.1): whom to tell about a security problem of this site. Expires half a year ahead – the
     * file is generated, so it never goes stale.
     *
     * @param list<string> $languages
     */
    public static function securityTxt(string $contact, string $siteUrl, array $languages, int $now): string
    {
        return 'Contact: ' . (str_contains($contact, '@') && !str_starts_with($contact, 'https://') ? 'mailto:' . $contact : $contact) . "\n"
            . 'Expires: ' . gmdate('Y-m-d\T00:00:00\Z', $now + 183 * 86400) . "\n"
            . ($languages !== [] ? 'Preferred-Languages: ' . implode(', ', $languages) . "\n" : '')
            . 'Canonical: ' . rtrim($siteUrl, '/') . "/.well-known/security.txt\n";
    }

    public function robotsTxt(): string
    {
        $s = $this->app->settings();
        if (\Kaleta\Core\Demo::active()) {
            return "# The public demo of Kaleta is not indexed.\nUser-agent: *\nDisallow: /\n";
        }
        if (!$s->bool('indexing')) {
            return "# Indexing of the site is switched off in Settings.\nUser-agent: *\nDisallow: /\n";
        }
        $rows = ['User-agent: *', 'Disallow: /admin.php', 'Disallow: /hledani', 'Disallow: /search', 'Disallow: /*?preview=', ...array_map(fn (string $old): string => 'Disallow: /*?' . $old . '=', \Kaleta\Core\Request::LEGACY_QUERY['preview']), '']; // the old name still opens a preview
        if ($s->get('ai_crawlers') === 'zakazat') {
            foreach (self::AI_BOTS as $bot) {
                $rows[] = 'User-agent: ' . $bot;
            }
            array_push($rows, 'Disallow: /', '');
        }
        if (trim($s->get('robots_extra')) !== '') {
            array_push($rows, trim($s->get('robots_extra')), '');
        }
        $rows[] = 'Sitemap: ' . $this->siteSettings . 'sitemap.xml';

        return implode("\n", $rows) . "\n";
    }

    /** Absolute URL of a page-like path in the form of the url_slash setting; $after goes behind it (a news item's ".md"). */
    private function page(string $path, string $after = ''): string
    {
        $suffix = \Kaleta\Core\Routes::pageLike('/' . $path, $this->app->db()) && $after === '' ? \Kaleta\Core\Routes::suffix($this->app->settings()->get('url_slash')) : '';

        return $this->siteSettings . $path . $suffix . $after;
    }

    public function sitemapXml(): string
    {
        $db = $this->app->db();
        // there is one sitemap for all language versions: the URL gets a prefix by the language of the record
        $suffix = \Kaleta\Core\Routes::suffix($this->app->settings()->get('url_slash'));
        $url = fn (string $path, ?string $change = null, string $priority = '0.5', string $language = ''): string => '<url><loc>'
            . e($this->root . ($language !== '' ? $language . '/' : '') . ($public = \Kaleta\Core\Routes::publicPath($path, $language !== '' ? $language : \Kaleta\Core\Language::defaults($this->app->settings()), $db))
                . (\Kaleta\Core\Routes::pageLike('/' . $public, $this->app->db()) ? $suffix : '')) . '</loc>'
            . ($change !== null ? '<lastmod>' . date('c', strtotime($change)) . '</lastmod>' : '') . '<priority>' . $priority . '</priority></url>';

        // only enabled and published language versions; content of a disabled or unfinished language is not in the sitemap
        $languages = ['', ...\Kaleta\Core\Language::published($this->app->settings(), $db)];
        $inLanguages = ' AND jazyk IN (' . implode(',', array_fill(0, count($languages), '?')) . ')';
        $xml = [$url('', null, '1.0')];
        foreach (array_slice($languages, 1) as $language) {
            $xml[] = $url('', null, '0.9', $language);
        }
        $home = $this->app->settings()->int('home_page');
        foreach ($db->all('SELECT seo_link, zmeneno, jazyk FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND heslo_hash IS NULL AND smazano IS NULL AND ids <> ? AND (preklad_z IS NULL OR preklad_z <> ?)' . $inLanguages, [$home, $home, ...$languages]) as $r) {
            $xml[] = $url($r['seo_link'], $r['zmeneno'], '0.8', $r['jazyk']);
        }
        foreach ($db->all('SELECT k.seo_link AS kolekce, p.seo_link, p.jazyk, COALESCE(p.zmeneno, p.datum) AS zmena FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.smazano IS NULL AND p.jazyk IN (' . implode(',', array_fill(0, count($languages), '?')) . ') LIMIT 5000', $languages) as $r) {
            $xml[] = $url($r['kolekce'] . '/' . $r['seo_link'], $r['zmena'], '0.5', $r['jazyk']);
        }
        // category pages of collections (3.7): visible ones, a subcategory under a visible parent with an address in the language
        foreach ($db->all('SELECT k.seo_link AS kolekce, t.slug, t.language, pt.slug AS parent_slug, c.updated_at FROM {collection_category_texts} t
                JOIN {collection_categories} c ON c.id = t.category_id JOIN {kolekce} k ON k.idk = c.idk
                LEFT JOIN {collection_categories} p ON p.id = c.parent_id LEFT JOIN {collection_category_texts} pt ON pt.category_id = c.parent_id AND pt.language = t.language
                WHERE c.visible = 1 AND (c.parent_id IS NULL OR (p.visible = 1 AND pt.slug IS NOT NULL)) AND t.language IN (' . implode(',', array_fill(0, count($languages), '?')) . ')
                ORDER BY k.seo_link, c.parent_id IS NOT NULL, c.sort_order LIMIT 5000', $languages) as $r) {
            $xml[] = $url($r['kolekce'] . '/' . ($r['parent_slug'] !== null ? $r['parent_slug'] . '/' : '') . $r['slug'], $r['updated_at'], '0.6', (string) $r['language']);
        }
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky')) {
            return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $xml) . "\n</urlset>\n";
        }
        // news listing, categories and tags only where there is some published news item
        $published = 'visible = 1 AND datum <= NOW() AND smazano IS NULL';
        foreach ($db->all("SELECT jazyk, MAX(COALESCE(zmeneno, datum)) AS zmena FROM {novinky} WHERE {$published}{$inLanguages} GROUP BY jazyk", $languages) as $r) {
            $xml[] = $url('novinky', $r['zmena'], '0.6', $r['jazyk']);
        }
        foreach ($db->all("SELECT k.seo_link, k.jazyk FROM {kategorie} k WHERE EXISTS (SELECT 1 FROM {novinky} n WHERE n.tema = k.idt AND n.{$published}) AND k.jazyk IN (" . implode(',', array_fill(0, count($languages), '?')) . ')', $languages) as $r) {
            $xml[] = $url('novinky/kategorie/' . $r['seo_link'], null, '0.4', $r['jazyk']);
        }
        foreach ($db->all("SELECT seo_link, jazyk, COALESCE(zmeneno, datum) AS zmena FROM {novinky} WHERE {$published} AND noindex = 0{$inLanguages} ORDER BY datum DESC LIMIT 45000", $languages) as $r) {
            $xml[] = $url('novinky/' . $r['seo_link'], $r['zmena'], '0.5', $r['jazyk']);
        }

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $xml) . "\n</urlset>\n";
    }

    /**
     * JSON Feed 1.1 (https://jsonfeed.org) - a modern counterpart of RSS with full text.
     *
     * @param list<array<string, mixed>> $news
     * @return array<string, mixed>
     */
    public function jsonFeed(array $news): array
    {
        $s = $this->app->settings();

        return [
            'version' => 'https://jsonfeed.org/version/1.1', 'title' => $s->get('site_name'), 'description' => $s->get('site_description'),
            'home_page_url' => $this->siteSettings, 'feed_url' => $this->siteSettings . 'feed.json', 'language' => \Kaleta\Core\Language::code(),
            'items' => array_map(fn (array $c): array => array_filter([
                'id' => 'novinka-' . $c['idc'], 'url' => $this->page($this->path('novinky/') . $c['seo_link']), 'title' => $c['titulek'],
                'summary' => trim(strip_tags($c['uvod'])), 'content_html' => $c['uvod'] . $c['text'],
                'image' => $c['obrazek'] !== '' ? $this->absoluteUrl($c['obrazek']) : null,
                'date_published' => date('c', strtotime($c['datum'])), 'date_modified' => $c['zmeneno'] ? date('c', strtotime($c['zmeneno'])) : null,
                'authors' => $c['autor_jm'] !== null ? [['name' => $c['autor_jm']]] : null, 'tags' => [$c['tema_jm']],
            ]), $news),
        ];
    }

    /**
     * IndexNow: notifies search engines (Bing, Seznam, Yandex) of a new or changed URL.
     * Called after a published news item is saved; a failure is ignored, the site must not wait because of it.
     */
    /** @param string $url URL on the site from the server root (App::newsItemUrl()) */
    public function indexNow(string $url): void
    {
        if (\Kaleta\Core\Demo::active()) {
            return;
        }
        $s = $this->app->settings();
        $host = (string) parse_url($this->siteSettings, PHP_URL_HOST);
        if (!$s->bool('indexnow') || $s->get('indexnow_key') === '' || !$s->bool('indexing') || in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test')) {
            return;
        }
        $data = json_encode(['host' => $host, 'key' => $s->get('indexnow_key'), 'keyLocation' => $this->app->request->origin() . $this->app->request->basePath() . '/' . $s->get('indexnow_key') . '.txt', 'urlList' => [$this->app->request->origin() . $url]]);
        @file_get_contents('https://api.indexnow.org/indexnow', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json; charset=utf-8\r\n", 'content' => $data, 'timeout' => 3, 'ignore_errors' => true,
        ]]));
    }

    /** llms.txt - a guide to the site for language models (https://llmstxt.org). */
    public function llmsTxt(): string
    {
        $s = $this->app->settings();
        $db = $this->app->db();
        $md = $s->bool('markdown_news') ? '.md' : '';
        $rows = ['# ' . $s->get('site_name'), ''];
        if ($s->get('site_description') !== '') {
            array_push($rows, '> ' . str_replace("\n", ' ', $s->get('site_description')), '');
        }
        // business facts (2.10): what the company states about itself, kept in one place
        $facts = array_filter(\Kaleta\Core\Facts::all($this->app, \Kaleta\Core\Language::siteColumn()), fn (array $f): bool => !$f['builtIn'] && $f['display'] !== '');
        if ($facts !== []) {
            $rows[] = '## ' . t('Facts');
            foreach ($facts as $f) {
                $rows[] = '- ' . $f['label'] . ': ' . $f['display'];
            }
            $rows[] = '';
        }
        $rows[] = '## ' . t('Pages');
        $home = $s->int('home_page');
        foreach ($db->all('SELECT ids, titulek, seo_link, popis FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND heslo_hash IS NULL AND smazano IS NULL AND jazyk = ? ORDER BY poradi, titulek', [\Kaleta\Core\Language::siteColumn()]) as $r) {
            $rows[] = '- [' . $r['titulek'] . '](' . $this->page((int) $r['ids'] === $home ? '' : $r['seo_link']) . ')' . ($r['popis'] !== '' ? ': ' . $r['popis'] : '');
        }
        // collections with their own item pages (guide, team, products…): item with the first longer text as its description
        foreach ($db->all('SELECT idk, nazev, seo_link, pole FROM {kolekce} WHERE detail = 1 ORDER BY nazev') as $k) {
            $field = json_decode((string) $k['pole'], true) ?: [];
            $descriptiveFields = array_column(array_filter($field, fn (array $f): bool => in_array($f['typ'] ?? '', ['radky', 'html', 'text'], true)), 'klic');
            $items = $db->all('SELECT nazev, seo_link, data, popis FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND noindex = 0 AND jazyk = ? ORDER BY poradi, nazev LIMIT 200', [$k['idk'], \Kaleta\Core\Language::siteColumn()]);
            if ($items === []) {
                continue;
            }
            array_push($rows, '', '## ' . $k['nazev']);
            foreach ($items as $p) {
                $data = json_decode((string) $p['data'], true) ?: [];
                $description = (string) $p['popis']; // the item's own description (1.9) first
                foreach ($description === '' ? $descriptiveFields : [] as $key) {
                    if (is_string($data[$key] ?? null) && trim(strip_tags($data[$key])) !== '') {
                        $description = mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($data[$key]), ENT_QUOTES | ENT_HTML5))), 0, 200, '…');
                        break;
                    }
                }
                $rows[] = '- [' . $p['nazev'] . '](' . $this->page($k['seo_link'] . '/' . $p['seo_link']) . ')' . ($description !== '' ? ': ' . $description : '');
            }
        }
        if (!\Kaleta\Core\Extensions::isEnabled($s, 'novinky')) {
            return \Kaleta\Core\Facts::fillText(implode("\n", $rows) . "\n", $this->app);
        }
        array_push($rows, '', '## ' . t('Novinky'));
        foreach ($db->all('SELECT titulek, seo_link, uvod FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND noindex = 0 AND smazano IS NULL AND jazyk = ? ORDER BY datum DESC LIMIT 30', [\Kaleta\Core\Language::siteColumn()]) as $c) {
            $rows[] = '- [' . $c['titulek'] . '](' . $this->page($this->path('novinky/') . $c['seo_link'], $md) . '): ' . mb_strimwidth(trim(strip_tags($c['uvod'])), 0, 200, '…');
        }

        return \Kaleta\Core\Facts::fillText(implode("\n", $rows) . "\n", $this->app);
    }

    /** @param array<string, mixed> $newsItem */
    public function newsItemMarkdown(array $newsItem): string
    {
        $head = ['# ' . $newsItem['titulek'], ''];
        $head[] = '- ' . t('Author') . ': ' . ($newsItem['autor_jm'] ?? $this->app->settings()->get('site_name'));
        $head[] = '- ' . t('Vydáno') . ': ' . date('Y-m-d', strtotime($newsItem['datum'])) . ($newsItem['zmeneno'] ? ', ' . t('updated') . ': ' . date('Y-m-d', strtotime($newsItem['zmeneno'])) : '');
        $head[] = '- ' . t('Categories') . ': ' . $newsItem['tema_jm'];
        $head[] = '- ' . t('Source') . ': ' . $this->page($this->path('novinky/') . $newsItem['seo_link']);

        return implode("\n", $head) . "\n\n" . self::htmlToMarkdown($newsItem['uvod']) . "\n\n" . self::htmlToMarkdown($newsItem['text']) . "\n";
    }

    /**
     * Tags before </head>.
     *
     * @param array<string, mixed> $meta     page meta data (typ, popis, obrazek, noindex...)
     * @param array<string, mixed>|null $newsItem the full news item, if this is a news item page
     */
    public function head(string $title, array $meta, ?array $newsItem): string
    {
        $s = $this->app->settings();
        $h = [];
        // a private page (the whistleblowing channel, 2.14): no consent service, no tracking codes, no site-wide head code –
        // nothing that could tell a third party who opened it
        $private = !empty($meta['soukroma']);
        if (!$private && $s->get('cookies_mode') === 'externi' && trim($s->get('cookies_external_code')) !== '') {
            $h[] = $s->get('cookies_external_code');
        }
        if (!$s->bool('indexing')) {
            $h[] = '<meta name="robots" content="noindex, nofollow">';
        } // noindex of an individual page or news item is printed by the template from $meta['noindex'] (and it omits the canonical URL)
        if ($s->get('verification_google') !== '') {
            $h[] = '<meta name="google-site-verification" content="' . e($s->get('verification_google')) . '">';
        }
        if ($s->get('verification_bing') !== '') {
            $h[] = '<meta name="msvalidate.01" content="' . e($s->get('verification_bing')) . '">';
        }
        $generated = null;
        if (($meta['obrazek'] ?? '') === '' && $s->get('share_image') !== '') {
            $h[] = '<meta property="og:image" content="' . e($this->absoluteUrl($s->get('share_image'))) . '">';
        } elseif (($meta['obrazek'] ?? '') === '' && ($generated = ShareImage::url($this->app, $title)) !== null) {
            // nothing to share at all: a picture with the title in the site's colours, drawn by the site (2.12)
            $h[] = '<meta property="og:image" content="' . e($generated) . '">';
            $h[] = '<meta property="og:image:width" content="' . ShareImage::WIDTH . '">';
            $h[] = '<meta property="og:image:height" content="' . ShareImage::HEIGHT . '">';
        }
        // language versions: hreflang only to existing translations (news item, page, category, collection item), on the home
        // page to the home page of each version
        $defaults = \Kaleta\Core\Language::defaults($s);
        foreach ($meta['jazyky'] ?? [] as $code => $j) {
            if ($j['preklad'] || ($meta['hlavni'] ?? false)) {
                $h[] = '<link rel="alternate" hreflang="' . e($code) . '" href="' . e($this->app->request->origin() . $j['url']) . '">';
                if ($code === $defaults) {
                    // a visitor in a language the site does not have gets the default version
                    $h[] = '<link rel="alternate" hreflang="x-default" href="' . e($this->app->request->origin() . $j['url']) . '">';
                }
            }
        }
        $h[] = '<meta property="og:locale" content="' . \Kaleta\Core\Language::AVAILABLE[\Kaleta\Core\Language::code()][1] . '">';
        if (($meta['popis'] ?? '') !== '') {
            $h[] = '<meta property="og:description" content="' . e($meta['popis']) . '">';
        }
        $h[] = '<meta name="twitter:card" content="' . (($meta['obrazek'] ?? '') !== '' || $s->get('share_image') !== '' || $generated !== null ? 'summary_large_image' : 'summary') . '">';
        if ($newsItem !== null && $s->bool('markdown_news')) {
            $h[] = '<link rel="alternate" type="text/markdown" href="' . e($this->siteSettings . $this->path('novinky/') . $newsItem['seo_link'] . '.md') . '">';
        }
        if ($s->bool('schema_org')) {
            $h[] = '<script type="application/ld+json">' . json_encode($this->structuredData($title, $meta, $newsItem), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        }
        // the site's own fonts that render text above the fold (the body and the heading face) start downloading right away
        $designSystem = \Kaleta\Builder\DesignSystem::load($s);
        $h[] = \Kaleta\Builder\DesignSystem::fontPreloads($designSystem, $this->app->request->basePath());
        // design system (tokens and cascade layer order) and the style of the page build, if it is a page from the builder
        $h[] = '<style>' . \Kaleta\Builder\DesignSystem::css($designSystem, $this->app->request->basePath()) . ($meta['css'] ?? '') . '</style>';
        $h[] = SiteIdentity::head($s, $this->app->request->basePath());
        // shared site elements (photo gallery, photo viewer, video, sharing…) for all templates
        $version = rawurlencode(KALETA_VERSION);
        $h[] = '<link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '?v=' . $version . '">';
        // blocking="render": the page is first rendered only with the script loaded (it loads in parallel with the styles, which
        // block rendering anyway). Without it Chrome aborts the transition between pages (View Transitions) while the deferred
        // script is downloading, and prints the unhandled error „Transition was aborted because of invalid state“ to the console.
        // contact clicks (2.12, Core\Conversions) follow the same switch as the speed beacon below: the script counts a click on a
        // phone number, an e-mail address or a WhatsApp link only when the tag names the endpoint in data-konverze
        $clicks = !empty($meta['vitals']) ? ' data-konverze="' . e($this->app->url('konverze')) . '"' : '';
        $h[] = '<script src="' . e($this->app->url('image/web.js')) . '?v=' . $version . '" defer blocking="render"' . self::scriptTextsAttribute() . $clicks . '></script>';
        if (!empty($meta['vitals'])) {
            // real-user speed (2.8, Core\WebVitals): a small deferred script of its own, so pages without image/web.js stay
            // without it; the beacon goes to POST /vitals without cookies or identifiers
            $h[] = '<script src="' . e($this->app->url('image/vitals.js')) . '?v=' . $version . '" defer data-vitals="' . e($this->app->url('vitals')) . '"></script>';
        }
        if (!$private) {
            $h[] = $this->analyticsCode();
            if (trim($s->get('head_code')) !== '') {
                $h[] = $s->get('head_code');
            }
        }
        if (trim((string) ($meta['kod_hlavicky'] ?? '')) !== '') {
            $h[] = (string) $meta['kod_hlavicky']; // this page only, after the code for the whole site (2.3)
        }

        return implode("\n", array_filter($h)) . "\n";
    }

    /**
     * Texts that image/web.js shows to the visitor (wrapped in T() or A() there); the site dictionary translates
     * them like any other text.
     */
    public const array SCRIPT_TEXTS = ['Previous photo', 'Next photo', 'Close',
        'Added to the enquiry.', 'Show the enquiry', 'Enquiry', 'Compare', 'Clear', 'Quantity', 'Remove', 'You can compare up to four products.', 'Back', 'Next', 'Step',
        'Previous month', 'Next month', 'No free times on this day.', 'Choose a service first.', 'Loading…', 'Chosen time: %s'];

    /**
     * The data-texty attribute for the <script> tag with image/web.js: translations of the script texts (source => translation)
     * as JSON. No extra request and no inline script; the English version needs nothing – the script has English built in.
     */
    private static function scriptTextsAttribute(): string
    {
        $translations = [];
        foreach (self::SCRIPT_TEXTS as $source) {
            if (t($source) !== $source) {
                $translations[$source] = t($source);
            }
        }

        return $translations === [] ? '' : ' data-texty="' . e((string) json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    }

    /** Cookie bar of the built-in solution and marketing codes; inserted before </body>. */
    public function foot(): string
    {
        $s = $this->app->settings();
        $mode = $s->get('cookies_mode');
        $marketing = trim($s->get('marketing_code'));
        $html = $marketing === '' ? '' : self::deferUntilConsent($marketing, $mode);
        if (\Kaleta\Core\Demo::active()) {
            // the public demo (2.6): a badge that leads to the admin; inline styles, so no site class can hide it
            $html .= '<a href="' . e($this->app->url('admin.php')) . '" style="position:fixed;left:12px;bottom:12px;z-index:2147483000;padding:8px 12px;border-radius:999px;background:#121212;color:#fff;font:600 13px/1.2 system-ui,sans-serif;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.25)">'
                . e(t('Kaleta demo – try the admin')) . '</a>';
        }
        // remembering where leads came from needs the visitor's consent to marketing (2.3)
        $hasMarketing = $marketing !== '' || $s->bool('lead_attribution') || $s->get('gtm_id') !== ''; // GTM usually also runs ad tags
        if ($mode !== 'vestavena' || (!$this->usesAnalyticsCookies() && !$hasMarketing)) {
            return $html;
        }
        $view = new \Kaleta\Core\View([KALETA_SYSTEM . '/views/front']);

        return $html . $view->render('cookies', [
            'text' => $s->get('cookies_text'),
            'zasady' => \Kaleta\Core\Privacy::policyUrl($s),
            'analytika' => $this->usesAnalyticsCookies(),
            'marketing' => $hasMarketing,
            'evidence' => $s->bool('cookies_log') ? $this->app->url('souhlas') : '',
        ]);
    }

    /**
     * Marketing code by cookie mode: without a bar it is printed directly; the built-in bar unpacks it from the <template>
     * tag after consent; with an external service the scripts get markup understood by Cookiebot and services compatible
     * with it (the same as for tracking codes) – only that service runs them.
     */
    public static function deferUntilConsent(string $code, string $mode): string
    {
        return match ($mode) {
            'zadna' => $code,
            'externi' => (string) preg_replace('/<script(?![^>]*\btype\s*=)/i', '<script type="text/plain" data-cookieconsent="marketing"', $code),
            default => '<template data-souhlas="marketing">' . $code . '</template>',
        };
    }

    private function usesAnalyticsCookies(): bool
    {
        $s = $this->app->settings();

        return $s->get('ga4_id') !== '' || $s->get('gtm_id') !== '' || ($s->get('matomo_url') !== '' && $s->int('matomo_id') > 0);
    }

    private function analyticsCode(): string
    {
        $s = $this->app->settings();
        // with consent: the script is "text/plain" until the bar (built-in or Cookiebot) enables it
        $pending = $s->get('cookies_mode') !== 'zadna';
        $attributes = $pending ? ' type="text/plain" data-souhlas="analytika" data-cookieconsent="statistics"' : '';
        $code = '';
        if ($s->get('ga4_id') !== '') {
            $id = $s->get('ga4_id');
            $code .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
                . ($pending ? "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});" : '')
                . "gtag('js',new Date());gtag('config','{$id}');</script>\n"
                . "<script async{$attributes} src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>\n";
        }
        if ($s->get('gtm_id') !== '') {
            $code .= self::tagManager($s->get('gtm_id'), $s->get('cookies_mode'), $s->get('ga4_id') === '');
        }
        if ($s->get('matomo_url') !== '' && $s->int('matomo_id') > 0) {
            $url = json_encode(rtrim($s->get('matomo_url'), '/') . '/', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
            $code .= "<script{$attributes}>var _paq=window._paq=window._paq||[];_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);(function(){var u={$url};_paq.push(['setTrackerUrl',u+'matomo.php']);_paq.push(['setSiteId','{$s->int('matomo_id')}']);var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();</script>\n";
        }
        if ($s->get('plausible_domain') !== '') {
            $code .= '<script defer data-domain="' . e($s->get('plausible_domain')) . '" src="https://plausible.io/js/script.js"></script>' . "\n";
        }

        return $code;
    }

    /**
     * Google Tag Manager (2.6). Consent mode starts with everything denied; with the built-in cookie bar the container
     * loads after the first consent to analytics or marketing (the bar enables scripts marked data-gtm), with an external
     * consent service right away – that service updates the consent itself – and without a bar right away.
     */
    public static function tagManager(string $id, string $cookiesMode, bool $defineGtag = true): string
    {
        $id = preg_match('/^GTM-[A-Z0-9]{4,12}$/D', $id) ? $id : '';
        if ($id === '') {
            return '';
        }
        $consent = $cookiesMode !== 'zadna'
            ? "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});" : '';
        $loader = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');";

        return '<script>window.dataLayer=window.dataLayer||[];' . ($defineGtag ? 'function gtag(){dataLayer.push(arguments);}' . $consent : '') . "</script>\n"
            . ($cookiesMode === 'vestavena' ? '<script type="text/plain" data-gtm>' . $loader . '</script>' : '<script>' . $loader . '</script>') . "\n";
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $newsItem
     * @return array<string, mixed>
     */
    private function structuredData(string $title, array $meta, ?array $newsItem): array
    {
        $s = $this->app->settings();
        // company from "Nastavení → Firma" (Business details) (Organization or LocalBusiness with address, opening hours and map)
        $issuer = Company::schema($s, $this->siteSettings, $this->absoluteUrl(...)) + \Kaleta\Core\Facts::schema($this->app); // + facts with a schema property (2.10)
        if (($issuer['@type'] ?? 'Organization') !== 'Organization' && ($special = \Kaleta\Core\Hours::schema(\Kaleta\Core\Hours::exceptions($this->app->db()))) !== []) {
            $issuer['specialOpeningHoursSpecification'] = $special; // holidays and other exceptions to the opening hours (2.10)
        }
        if ($newsItem === null) {
            $chart = [
                ['@type' => 'WebSite', '@id' => $this->siteSettings . '#web', 'name' => $s->get('site_name'), 'url' => $this->siteSettings,
                    'description' => $s->get('site_description'), 'inLanguage' => \Kaleta\Core\Language::code(), 'publisher' => ['@id' => $issuer['@id']],
                    'potentialAction' => ['@type' => 'SearchAction', 'target' => $this->siteSettings . $this->path('hledani?q={q}'), 'query-input' => 'required name=q']],
                $issuer,
            ];
            if (!empty($meta['faq'])) {
                // a builder page with questions and answers – next to the site and company details, not instead of them
                $chart[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $d): array => [
                    '@type' => 'Question', 'name' => $d[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $d[1]],
                ], $meta['faq'])];
            }
            if (!empty($meta['polozka'])) {
                // a collection item page: its schema.org type from the collection (service, person, product, event, question)
                $node = \Kaleta\Builder\CollectionSchema::forItem($meta['polozka']['kolekce'], $meta['polozka']['polozka'], $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')),
                    (string) ($meta['popis'] ?? ''), (string) ($meta['obrazek'] ?? ''), (string) $issuer['@id'], $issuer); // the whole company node: the hiring organization of a job posting (2.11)
                if ($node !== null) {
                    $chart[] = $node;
                }
            }
            if (!empty($meta['kategorie_kolekce'])) {
                // a category page of a collection (3.7): a collection of the site's items, part of the site
                $pageUrl = $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/'));
                $chart[] = array_filter(['@type' => 'CollectionPage', '@id' => $pageUrl . '#kategorie', 'url' => $pageUrl, 'name' => (string) $meta['kategorie_kolekce']['nazev'],
                    'description' => (string) $meta['kategorie_kolekce']['popis'], 'image' => (string) ($meta['obrazek'] ?? ''), 'inLanguage' => \Kaleta\Core\Language::code(),
                    'isPartOf' => ['@id' => $this->siteSettings . '#web']], fn (mixed $v): bool => $v !== '');
            }
            if (count($meta['drobecky'] ?? []) > 1) {
                $chart[] = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn (array $d, int $i): array => array_filter([
                    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $d[0], 'item' => $d[1] !== '' ? $this->app->request->origin() . $d[1] : null,
                ]), $meta['drobecky'], array_keys($meta['drobecky']))];
            }

            return ['@context' => 'https://schema.org', '@graph' => $chart];
        }

        return ['@context' => 'https://schema.org', '@graph' => [
            array_filter([
                '@type' => 'BlogPosting',
                'headline' => mb_substr($newsItem['titulek'], 0, 110),
                'description' => $meta['popis'] ?? '',
                'image' => $newsItem['obrazek'] !== '' ? [$this->absoluteUrl($newsItem['obrazek'])] : null,
                'datePublished' => date('c', strtotime($newsItem['datum'])),
                'dateModified' => date('c', strtotime($newsItem['aktualizovano'] ?? $newsItem['zmeneno'] ?? $newsItem['datum'])),
                'author' => $newsItem['autor_jm'] !== null ? array_filter(['@type' => 'Person', 'name' => $newsItem['autor_jm'],
                    'jobTitle' => $newsItem['autor_pozice'] ?? '', 'description' => trim((string) ($newsItem['autor_bio'] ?? '')), 'image' => ($newsItem['autor_foto'] ?? '') !== '' ? $this->absoluteUrl($newsItem['autor_foto']) : '',
                    'sameAs' => ($newsItem['autor_url'] ?? '') !== '' ? $newsItem['autor_url'] : '']) : $issuer,
                'publisher' => $issuer,
                'articleSection' => $newsItem['tema_jm'],
                'keywords' => implode(', ', array_column($newsItem['stitky'] ?? [], 'nazev')) ?: null,
                'mainEntityOfPage' => $this->page($this->path('novinky/') . $newsItem['seo_link']),
                'inLanguage' => \Kaleta\Core\Language::code(),
            ]),
            ...($this->faqData($newsItem)),
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $s->get('site_name'), 'item' => $this->siteSettings],
                ['@type' => 'ListItem', 'position' => 2, 'name' => t('Novinky'), 'item' => $this->page($this->path('novinky'))],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $newsItem['tema_jm'], 'item' => $this->page($this->path('novinky/kategorie/') . $newsItem['tema_seo'])],
                ['@type' => 'ListItem', 'position' => 4, 'name' => $newsItem['titulek']],
            ]],
        ]];
    }

    /**
     * Questions and answers of a news item: text "question \n answer \n\n ..." -> pairs.
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function faq(?string $text): array
    {
        $pairs = [];
        foreach (preg_split('/\R\s*\R/', trim((string) $text)) ?: [] as $block) {
            $rows = preg_split('/\R/', trim($block), 2) ?: [];
            if (count($rows) === 2 && trim($rows[0]) !== '' && trim($rows[1]) !== '') {
                $pairs[] = [trim($rows[0]), trim($rows[1])];
            }
        }

        return $pairs;
    }

    /** @return list<array<string, mixed>> */
    private function faqData(array $newsItem): array
    {
        $faq = self::faq($newsItem['faq'] ?? '');

        return $faq === [] ? [] : [['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $d): array => [
            '@type' => 'Question', 'name' => $d[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $d[1]],
        ], $faq)]];
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return str_starts_with($url, '/') ? $this->app->request->origin() . $url : $this->root . $url;
    }

    /** Simple conversion of HTML to Markdown - headings, paragraphs, lists, links, quotes, images. */
    private static function htmlToMarkdown(string $html): string
    {
        $md = preg_replace('/\s+/', ' ', $html) ?? $html;
        $replacements = [
            '#<h2[^>]*>(.*?)</h2>#i' => "\n\n## $1\n\n", '#<h3[^>]*>(.*?)</h3>#i' => "\n\n### $1\n\n", '#<h4[^>]*>(.*?)</h4>#i' => "\n\n#### $1\n\n",
            '#<(strong|b)>(.*?)</\1>#i' => '**$2**', '#<(em|i)>(.*?)</\1>#i' => '*$2*',
            '#<a [^>]*href="([^"]*)"[^>]*>(.*?)</a>#i' => '[$2]($1)',
            '#<img [^>]*src="([^"]*)"[^>]*alt="([^"]*)"[^>]*>#i' => '![$2]($1)', '#<img [^>]*src="([^"]*)"[^>]*>#i' => '![]($1)',
            '#<figcaption[^>]*>(.*?)</figcaption>#i' => "\n*$1*\n",
            '#<li[^>]*>(.*?)</li>#i' => "\n- $1", '#</(ul|ol)>#i' => "\n\n",
            '#<blockquote[^>]*>(.*?)</blockquote>#i' => "\n\n> $1\n\n",
            '#<br\s*/?>#i' => "\n", '#</p>#i' => "\n\n", '#<hr[^>]*>#i' => "\n\n---\n\n",
        ];
        $md = preg_replace(array_keys($replacements), array_values($replacements), $md) ?? $md;
        $md = html_entity_decode(strip_tags($md), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $md = preg_replace(['/[ \t]+\n/', '/\n{3,}/', '/^[ \t]+/m'], ["\n", "\n\n", ''], $md) ?? $md;

        return trim($md);
    }
}
