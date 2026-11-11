<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;

/**
 * The migration parity report (2.7): before a moved site goes live, every address of the old site must still work on
 * the new one and nothing important may get lost on the way.
 *
 * How it works:
 *  - The old site's pages are found the same way as for the website import (Core\WebImport: sitemaps, else its links).
 *  - Each old address is looked up on this site without a request: a page, a news item or a collection item at the
 *    same path, or a redirect (one hop is fine, two are a warning). A page that exists but is not published yet is
 *    reported, because it will end in 404 for visitors.
 *  - The old page is downloaded once (Core\ImageDownloader: public addresses only, limits) and compared with the new
 *    one: a search engine description, a form, the number of images in the content.
 *  - At the end come the checks of the whole site from the site audit (Before handing over) and whether the Redirects
 *    extension is on – without it no redirect works.
 * The work runs in batches of SECONDS like the import; the state is a file in storage/import. Nothing is changed.
 * 3.7: thousands of old addresses (WebImport::MAX_PAGES), read through the sitemaps across batches; the old site's
 * robots.txt and the gap between requests are kept (WebImport::politeDownload) – a page robots.txt disallows is only
 * looked up here, never downloaded.
 */
final class MigrationReport
{
    private const float SECONDS = 15.0;

    /** Problems by code => severity (error = visitors or search engines lose something, warning = check it). */
    public const array PROBLEMS = [
        'missing' => 'error', 'form_missing' => 'error', 'hidden' => 'warning', 'chain' => 'warning',
        'no_description' => 'warning', 'fewer_images' => 'warning', 'not_read' => 'info', 'redirect_out' => 'info', 'robots' => 'info',
        'too_large' => 'info',
        // 3.33 (M5): the address lands on an untouched sample page of the installation; the page title changed on the way
        'sample_page' => 'error', 'title_changed' => 'info',
    ];

    /** A form found on at least this share of the old pages read (and on SITE_WIDE_MIN of them) is the site's own – a pop-up or the footer (3.33, M5). */
    public const float SITE_WIDE_SHARE = 0.5;
    public const int SITE_WIDE_MIN = 3;

    /** @var array<int, true>|null the untouched starter pages of this site (Core\StarterPages), read once per batch */
    private ?array $samples = null;

    private float $end = 0.0;

    /** @var list<array<string, mixed>>|null pattern redirects of the site (3.6), read once per report */
    private ?array $patterns = null;

    /** @var array<string, array{0: int, 1: int}> forms and images of a component (db:id:depth), counted once per batch (N3182-4) */
    private static array $componentCounts = [];

    public function __construct(private readonly App $app, private readonly ImageDownloader $downloader)
    {
    }

    /** @return array<string, mixed> */
    public static function newState(string $url): array
    {
        $discovery = WebImport::newState($url);

        return ['id' => $discovery['id'], 'web' => $discovery['web'], 'faze' => 'hledani', 'hledani' => $discovery,
            'adresy' => [], 'pozice' => 0, 'radky' => [], 'zalozeno' => date('Y-m-d H:i:s')];
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
        // an old sitemap may hold an address that is not valid UTF-8 (a cut percent-escaped emoji): it is substituted, otherwise
        // json_encode() returns false and the report is saved as an empty file that the next batch cannot find (3.18.2)
        file_put_contents(self::file((string) $state['id']), (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    }

    public static function delete(string $id): void
    {
        if (preg_match('/^[a-f0-9]{16}$/D', $id)) {
            @unlink(self::file($id));
        }
    }

    /** @return list<array<string, mixed>> saved reports, newest first */
    public static function listAll(): array
    {
        $all = array_values(array_filter(array_map(fn (string $f): ?array => self::load(substr(basename($f, '.json'), 7)), glob(WpFile::folder() . '/parita-*.json') ?: [])));
        usort($all, fn (array $a, array $b): int => strcmp((string) $b['zalozeno'], (string) $a['zalozeno']));

        return $all;
    }

    private static function file(string $id): string
    {
        return WpFile::folder() . '/parita-' . $id . '.json';
    }

    /* ---------- one batch ---------- */

    /**
     * One batch. $checkpoint saves the state between the heavy parts (3.7, N37-23): after robots.txt, then every few
     * seconds – a batch the server's time limit kills resumes where it was instead of repeating the same work forever.
     *
     * @param array<string, mixed> $state
     * @param (\Closure(array<string, mixed>): mixed)|null $checkpoint
     */
    public function step(array &$state, ?\Closure $checkpoint = null): void
    {
        $this->end = microtime(true) + self::SECONDS;
        self::forgetComponents();
        if ($state['faze'] === 'hledani') {
            $discovery = $state['hledani'];
            $outer = $state;
            (new WebImport($this->app->db(), $this->app->settings(), 0, $this->downloader))
                ->step($discovery, $checkpoint !== null ? fn (array $d): mixed => $checkpoint(['hledani' => $d] + $outer) : null);
            $state['hledani'] = $discovery;
            if ($discovery['faze'] !== 'hledani') {
                $state['adresy'] = array_keys($discovery['adresy']);
                $state['robots'] = $discovery['robots'] ?? [];
                // what the result says about how the old site was read (WebImport::notes) stays with the report
                $state['hledani'] = ['adresy' => count($state['adresy'])] + array_intersect_key($discovery, ['mapy_cizi' => 1, 'mapy_navic' => 1, 'nedostupny' => 1]);
                $state['faze'] = 'kontrola';
            }
        }
        $saved = microtime(true);
        while ($state['faze'] === 'kontrola' && $state['pozice'] < count($state['adresy']) && microtime(true) < $this->end) {
            $url = $state['adresy'][$state['pozice']];
            $state['radky'][] = $this->check($url, (array) ($state['robots'] ?? []));
            $state['pozice']++;
            if ($checkpoint !== null && microtime(true) - $saved >= 2.0) {
                $checkpoint($state);
                $saved = microtime(true);
            }
        }
        if ($state['faze'] === 'kontrola' && $state['pozice'] >= count($state['adresy'])) {
            $state['faze'] = 'hotovo';
            $state['dokonceno'] = date('Y-m-d H:i:s');
        }
    }

    /**
     * One old address: where it leads on this site and what got lost.
     *
     * @return array{stara: string, nova: string, stav: string, problemy: list<string>, titulek_stary: string, titulek_novy: string}
     */
    /** @param array{disallow?: list<string>, allow?: list<string>, delay?: ?float} $robots */
    private function check(string $url, array $robots = []): array
    {
        $path = WebImport::path($url);
        $target = $this->resolve($path);
        $problems = [];
        $status = $target['stav'];
        if (in_array($status, ['missing', 'hidden', 'chain', 'redirect_out'], true)) {
            $problems[] = $status;
        }
        if ($status === 'sample') {
            $problems[] = 'sample_page'; // 3.33 (M5): never "ok" – the visitor would see the installer's sample text
        }
        $old = null;
        $html = WebImport::robotsAllow($robots, $url) ? $this->fetch($url, $robots) : null;
        if ($html !== null) {
            try {
                $old = self::analyse($html, $url);
            } catch (HtmlTooLarge) {
                $problems[] = 'too_large'; // over a limit of HtmlLimits (3.8): never parsed, only the address is checked
            }
        } else {
            $problems[] = WebImport::robotsAllow($robots, $url) ? 'not_read' : 'robots';
        }
        if ($old !== null && $target['typ'] !== '') {
            if ($old['popis'] !== '' && $target['popis'] === '') {
                $problems[] = 'no_description';
            }
            if ($old['formular'] && !$target['formular'] && $target['typ'] !== 'news') {
                $problems[] = 'form_missing';
            }
            if ($old['obrazky'] >= 3 && $target['obrazky'] * 2 < $old['obrazky']) {
                $problems[] = 'fewer_images';
            }
            if (in_array($target['typ'], ['page', 'news', 'item', 'category'], true) && $status !== 'sample' && !self::sameTitle($old['titulek'], $target['titulek'])) {
                $problems[] = 'title_changed';
            }
        }

        // formulare: the old page's forms by their fields – a form on most old pages is reported once (result(), 3.33 M5)
        return ['stara' => '/' . $path, 'nova' => $target['adresa'], 'stav' => $status, 'problemy' => $problems,
            'titulek_stary' => $old['titulek'] ?? '', 'titulek_novy' => $target['titulek'], 'formulare' => $old['formulare'] ?? []];
    }

    /**
     * Where a path leads on this site: ok (published content), redirect (one hop to published content), chain (more hops),
     * redirect_out (to another site), hidden (content that is not published) or missing.
     *
     * 3.33 (M5): sample = an untouched sample page of the installation (Core\StarterPages), never "ok".
     *
     * @return array{stav: string, adresa: string, typ: string, titulek: string, popis: string, formular: bool, obrazky: int}
     */
    public function resolve(string $path, int $hops = 0): array
    {
        $none = ['stav' => 'missing', 'adresa' => '', 'typ' => '', 'titulek' => '', 'popis' => '', 'formular' => false, 'obrazky' => 0];
        $path = trim(rawurldecode($path), '/');
        $db = $this->app->db();
        $content = $this->content($path);
        if ($content !== null) {
            $this->samples ??= array_fill_keys(array_keys(StarterPages::untouched($db, $this->app->settings())), true);
            $sample = ($content['ids'] ?? 0) > 0 && isset($this->samples[$content['ids']]);
            unset($content['ids']);

            return $content + ['adresa' => '/' . $path, 'stav' => $sample ? 'sample' : ($content['zobrazeno'] ? ($hops === 0 ? 'ok' : ($hops === 1 ? 'redirect' : 'chain')) : 'hidden')];
        }
        $rule = RedirectRules::isPattern($path) ? null : $db->one('SELECT na_adresu, typ FROM {presmerovani} WHERE source_norm = ? ORDER BY idp LIMIT 1', [RedirectSources::normalize($path)]); // 3.19: the exact address
        if ($rule === null) {
            // a pattern rule (3.6) answers what no exact redirect does, as on the site
            $this->patterns ??= RedirectRules::patternRows($db);
            $found = RedirectRules::resolve($this->patterns, [$path]);
            $rule = $found !== null ? ['na_adresu' => $found['to'], 'typ' => $found['row']['typ'] ?? 301] : null;
        }
        // a 410 rule (3.6) means the address is gone on purpose: nothing answers it
        $to = $rule !== null && (int) $rule['typ'] !== RedirectRules::GONE ? $rule['na_adresu'] : null;
        if ($to === null || $hops >= 3) {
            return $none;
        }
        $to = (string) $to;
        if (preg_match('#^https?://#i', $to)) {
            $origin = $this->app->request->origin();
            if (!str_starts_with($to, $origin . '/') && $to !== $origin) {
                return ['stav' => 'redirect_out', 'adresa' => $to] + $none;
            }
            $to = substr($to, strlen($origin));
        }
        $next = $this->resolve((string) parse_url($to, PHP_URL_PATH), $hops + 1);

        return $next['stav'] === 'missing' ? ['adresa' => $to] + $next : $next;
    }

    /**
     * Published or hidden content at a path, without redirects.
     *
     * @return array{typ: string, titulek: string, popis: string, formular: bool, obrazky: int, zobrazeno: bool, ids?: int}|null
     */
    private function content(string $path): ?array
    {
        $db = $this->app->db();
        $settings = $this->app->settings();
        $segments = $path === '' ? [] : explode('/', $path);
        $language = Language::defaults($settings);
        if ($segments !== [] && in_array($segments[0], Language::additional($settings), true)) {
            $language = array_shift($segments);
        }
        [$internal] = Routes::internalPath('/' . implode('/', $segments), $language, $db);
        $s = $internal === '/' ? [] : explode('/', ltrim((string) $internal, '/'));
        $column = Language::column($settings, $language); // 3.9: with slugs per language the version of the address wins (/en/kontakt)
        if ($s === []) {
            $home = (int) $settings->get('home_page');
            $page = $home > 0 ? $db->one('SELECT ids, titulek, seo_titulek, popis, zobrazit, stavba, stavba_koncept, text FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$home]) : null;

            return $page !== null ? self::page($page, $db) : ['typ' => 'home', 'titulek' => (string) $settings->get('site_name'), 'popis' => (string) $settings->get('site_description'), 'formular' => false, 'obrazky' => 0, 'zobrazeno' => true];
        }
        if ($s[0] === 'novinky' && (count($s) === 1 || (count($s) === 3 && $s[1] === 'kategorie'))) {
            // the news list and a category list (not pages, but content on the site when the extension is on)
            if (!Extensions::isEnabled($settings, 'novinky')) {
                return null;
            }
            $topic = count($s) === 3 ? $db->one('SELECT nazev, popis FROM {kategorie} WHERE seo_link = ? ORDER BY jazyk = ? DESC LIMIT 1', [$s[2], $column]) : [];
            if ($topic === null) {
                return null;
            }

            return ['typ' => 'news_list', 'titulek' => (string) ($topic['nazev'] ?? t('News')), 'popis' => trim((string) ($topic['popis'] ?? '')), 'formular' => false, 'obrazky' => 0, 'zobrazeno' => true];
        }
        if ($s[0] === 'novinky' && count($s) === 2) {
            $n = $db->one('SELECT titulek, seo_titulek, seo_popis, uvod, text, visible FROM {novinky} WHERE seo_link = ? AND smazano IS NULL ORDER BY jazyk = ? DESC LIMIT 1', [$s[1], $column]);

            return $n === null ? null : ['typ' => 'news', 'titulek' => (string) ($n['seo_titulek'] ?: $n['titulek']),
                'popis' => trim((string) ($n['seo_popis'] ?: strip_tags((string) $n['uvod']))), 'formular' => false,
                'obrazky' => substr_count(strtolower((string) $n['text']), '<img'), 'zobrazeno' => (bool) $n['visible']];
        }
        $page = $db->one('SELECT ids, titulek, seo_titulek, popis, zobrazit, stavba, stavba_koncept, text FROM {stranky} WHERE seo_link = ? AND smazano IS NULL ORDER BY jazyk = ? DESC LIMIT 1', [implode('/', $s), $column]);
        if ($page !== null) {
            return self::page($page, $db);
        }
        if (count($s) === 2) {
            $item = $db->one('SELECT p.nazev, p.seo_titulek, p.popis, p.zobrazit, p.data, k.stavba AS sablona FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND k.detail = 1 AND p.seo_link = ? AND p.smazano IS NULL ORDER BY p.jazyk = ? DESC LIMIT 1', [$s[0], $s[1], $column]);
            if ($item !== null) {
                // 3.18.2: the item page is drawn by the collection's template – its form (also one inside a component) and images count
                $template = $item['sablona'] !== null ? Build::fromJson((string) $item['sablona']) : null;
                [$forms, $images] = self::countElements($template['deti'] ?? [], $db);

                return ['typ' => 'item', 'titulek' => (string) ($item['seo_titulek'] ?: $item['nazev']), 'popis' => trim((string) $item['popis']), 'formular' => $forms > 0,
                    'obrazky' => $images + preg_match_all('#\.(jpe?g|png|webp|gif|avif)"#i', (string) $item['data']), 'zobrazeno' => (bool) $item['zobrazit']];
            }
        }
        // 3.12: a category page of a collection (a moved shop's product categories)
        $category = \Kaleta\Builder\CollectionCategories::atPath($db, $s, $column);
        if ($category !== null) {
            return ['typ' => 'category', 'titulek' => $category['seo_title'] !== '' ? $category['seo_title'] : $category['name'],
                'popis' => $category['seo_description'] !== '' ? $category['seo_description'] : trim(strip_tags($category['description'])), 'formular' => false, 'obrazky' => 0,
                'zobrazeno' => \Kaleta\Builder\CollectionCategories::isPublic($category)];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $p a row of ka_stranky
     * @return array{typ: string, titulek: string, popis: string, formular: bool, obrazky: int, zobrazeno: bool, ids: int}
     */
    private static function page(array $p, ?Db $db = null): array
    {
        // a hidden imported page is still being worked on: count its draft
        $json = $p['zobrazit'] ? ($p['stavba'] ?? null) : ($p['stavba_koncept'] ?? $p['stavba'] ?? null);
        [$forms, $images] = [0, substr_count(strtolower((string) $p['text']), '<img')];
        if ($json !== null) {
            $build = Build::fromJson((string) $json);
            [$forms, $images] = self::countElements($build['deti'] ?? [], $db);
        }

        return ['typ' => 'page', 'titulek' => (string) ($p['seo_titulek'] ?: $p['titulek']), 'popis' => trim((string) $p['popis']),
            'formular' => $forms > 0, 'obrazky' => $images, 'zobrazeno' => (bool) $p['zobrazit'], 'ids' => (int) ($p['ids'] ?? 0)];
    }

    /**
     * Forms and images in a build: the Form element, and the Image element plus the photos of galleries and carousels.
     * With the database, a Component element counts what its published build holds (3.18.2) – a form shared by pages
     * as a component is still a form. A component already on the way in is not followed again (a component that holds
     * itself, A → B → A), and each component is read and counted once per report batch (N3182-4): before, 800 uses of a
     * component inside itself made one page cost 800⁴ lookups.
     *
     * @param list<array<string, mixed>> $children
     * @param list<int> $stack the components the count is inside
     * @return array{0: int, 1: int}
     */
    public static function countElements(array $children, ?Db $db = null, int $depth = 0, array $stack = []): array
    {
        $forms = 0;
        $images = 0;
        foreach ($children as $el) {
            $type = (string) ($el['typ'] ?? '');
            if ($type === 'formular') {
                $forms++;
            } elseif ($type === 'obrazek') {
                $images++;
            } elseif ($type === 'galerie') {
                $images += count((array) ($el['obsah']['fotky'] ?? []));
            } elseif ($type === 'text') {
                $images += substr_count(strtolower((string) ($el['obsah']['html'] ?? '')), '<img');
            } elseif ($type === \Kaleta\Builder\Elements\Component::TYPE && $db !== null && $depth < \Kaleta\Builder\Components::MAX_NESTING) {
                $id = (int) ($el['obsah']['komponenta'] ?? 0);
                if ($id > 0 && !in_array($id, $stack, true)) {
                    $key = spl_object_id($db) . ':' . $id . ':' . $depth;
                    if (!isset(self::$componentCounts[$key])) {
                        $component = \Kaleta\Builder\Components::byId($db, $id);
                        $inner = $component !== null && $component['stavba'] !== null ? Build::fromJson((string) $component['stavba']) : null;
                        self::$componentCounts[$key] = self::countElements($inner['deti'] ?? [], $db, $depth + 1, [...$stack, $id]);
                    }
                    [$f, $i] = self::$componentCounts[$key];
                    $forms += $f;
                    $images += $i;
                }
            }
            [$f, $i] = self::countElements(is_array($el['deti'] ?? null) ? $el['deti'] : [], $db, $depth, $stack);
            $forms += $f;
            $images += $i;
        }

        return [$forms, $images];
    }

    /** Forgets the component counts (a new batch reads the components again – they may have changed). */
    public static function forgetComponents(): void
    {
        self::$componentCounts = [];
    }

    /**
     * What the old page had: its full title, the search engine description, a form (not just a search box) and the
     * number of images in the main content.
     *
     * @return array{titulek: string, popis: string, formular: bool, obrazky: int, formulare: list<string>}
     * @throws HtmlTooLarge when the page is over a limit of HtmlLimits
     */
    public static function analyse(string $html, string $url): array
    {
        $doc = HtmlLimits::document($html);
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $doc->querySelector('title')?->textContent));
        $description = trim((string) $doc->querySelector('meta[name="description"]')?->getAttribute('content'));
        $form = false;
        $signatures = [];
        foreach ($doc->querySelectorAll('form') as $f) {
            $role = strtolower($f->getAttribute('role') ?? '');
            $fields = $f->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="search"]), textarea, select');
            if ($role !== 'search' && $fields->length > 0 && !preg_match('/search|hledat|suche/i', ($f->getAttribute('class') ?? '') . ' ' . ($f->getAttribute('action') ?? ''))) {
                $form = true;
                // 3.33 (M5): the form by its fields (names, else types), so the same newsletter form on every page is one form
                $names = [];
                foreach ($fields as $field) {
                    $names[] = mb_substr(strtolower(trim((string) ($field->getAttribute('name') ?: $field->getAttribute('type') ?: $field->localName))), 0, 40);
                }
                sort($names);
                $signatures[] = implode(',', array_slice(array_values(array_unique($names)), 0, 12));
                if (count($signatures) >= 5) {
                    break;
                }
            }
        }
        $main = WebImport::extract($html, $url);

        return ['titulek' => mb_substr($title, 0, 200), 'popis' => mb_substr($description, 0, 320), 'formular' => $form,
            'obrazky' => substr_count(strtolower($main['obsah']), '<img'), 'formulare' => array_values(array_unique($signatures))];
    }

    /* ---------- the result ---------- */

    /**
     * Counts, the rows with a problem first, and the checks of the whole site.
     *
     * @param array<string, mixed> $state
     * @return array{souhrn: array<string, int>, radky: list<array<string, mixed>>, web: list<array{zprava: string, uprava: string}>, poznamky: list<string>, formulare_webu: array<string, int>}
     */
    public function result(array $state): array
    {
        $summary = ['adres' => count($state['adresy']), 'zkontrolovano' => count($state['radky']), 'ok' => 0, 'presmerovano' => 0, 'skryto' => 0, 'chybi' => 0, 'chyb' => 0, 'varovani' => 0,
            'vzor' => 0]; // 3.33 (M5): old addresses on an untouched sample page
        // 3.33 (M5): a form on most old pages (a newsletter pop-up, a form in the footer) is reported once, not on every page
        $siteWide = self::siteWideForms($state['radky']);
        $rows = array_map(fn (array $r): array => self::withoutSiteWideForm($r, array_keys($siteWide)), $state['radky']);
        foreach ($rows as $r) {
            match ($r['stav']) {
                'ok' => $summary['ok']++,
                'redirect', 'chain', 'redirect_out' => $summary['presmerovano']++,
                'hidden' => $summary['skryto']++,
                'sample' => $summary['vzor']++,
                default => $summary['chybi']++,
            };
            foreach ($r['problemy'] as $p) {
                match (self::PROBLEMS[$p] ?? 'info') {
                    'error' => $summary['chyb']++,
                    'warning' => $summary['varovani']++,
                    default => null,
                };
            }
        }
        $rank = fn (array $r): int => min(array_map(fn (string $p): int => ['error' => 0, 'warning' => 1, 'info' => 2][self::PROBLEMS[$p] ?? 'info'], $r['problemy'] ?: ['ok'])) + ($r['problemy'] === [] ? 3 : 0);
        usort($rows, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        $site = [];
        if (!Extensions::isEnabled($this->app->settings(), 'presmerovani')) {
            $site[] = ['zprava' => t('The Redirects feature is off: no redirect from an old address works.'), 'uprava' => 'admin.php?module=extensions'];
        }
        if ($siteWide !== [] && !$this->siteHasSharedForm()) {
            foreach ($siteWide as $fields => $pages) {
                $site[] = ['zprava' => t('A form is on %s of the old pages (a pop-up or the footer, fields: %s) and nothing here has one: add it once – a Form element in the footer (Site parts) or a pop-up.', $pages, $fields !== '' ? $fields : '–'),
                    'uprava' => 'admin.php?module=parts'];
            }
        }
        if ($state['faze'] === 'hotovo') {
            foreach ((new Audit($this->app))->handoverFindings() as $f) {
                $site[] = ['zprava' => (string) $f['message'], 'uprava' => (string) $f['edit']];
            }
        }

        // how the old site was read: a robots.txt that was cut, sitemaps on other hosts that were skipped (3.7, N37-23, N37-24)
        $notes = WebImport::notes(['robots' => $state['robots'] ?? ($state['hledani']['robots'] ?? [])] + (is_array($state['hledani'] ?? null) ? $state['hledani'] : []));

        $read = array_filter($state['radky'], fn (array $r): bool => !in_array('robots', $r['problemy'], true));
        if ($state['faze'] === 'hotovo' && $read !== [] && array_filter($read, fn (array $r): bool => !in_array('not_read', $r['problemy'], true) && !in_array('too_large', $r['problemy'], true)) === []) {
            // 3.33 (M6): every old page failed to download – said plainly, the comparison could not be made
            $notes[] = t('None of the old pages could be read, so only the addresses were checked – the old site may be down or refuse this server.');
        }

        return ['souhrn' => $summary, 'radky' => $rows, 'web' => $site, 'poznamky' => $notes, 'formulare_webu' => $siteWide];
    }

    /**
     * The forms found on most old pages read (SITE_WIDE_SHARE, at least SITE_WIDE_MIN pages): fields => on how many pages.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public static function siteWideForms(array $rows): array
    {
        $read = 0;
        $counts = [];
        foreach ($rows as $r) {
            $problems = (array) ($r['problemy'] ?? []);
            if (in_array('not_read', $problems, true) || in_array('robots', $problems, true) || in_array('too_large', $problems, true)) {
                continue;
            }
            $read++;
            foreach (array_unique(array_map('strval', (array) ($r['formulare'] ?? []))) as $signature) {
                $counts[$signature] = ($counts[$signature] ?? 0) + 1;
            }
        }
        $threshold = max(self::SITE_WIDE_MIN, (int) ceil($read * self::SITE_WIDE_SHARE));

        return array_filter($counts, fn (int $n): bool => $n >= $threshold);
    }

    /**
     * A row without form_missing when every form of its old page is a site-wide one (reported once, siteWideForms).
     *
     * @param array<string, mixed> $row
     * @param list<string> $siteWide
     * @return array<string, mixed>
     */
    public static function withoutSiteWideForm(array $row, array $siteWide): array
    {
        $forms = array_map('strval', (array) ($row['formulare'] ?? []));
        if ($siteWide !== [] && $forms !== [] && array_diff($forms, $siteWide) === [] && in_array('form_missing', (array) $row['problemy'], true)) {
            $row['problemy'] = array_values(array_diff((array) $row['problemy'], ['form_missing']));
        }

        return $row;
    }

    /** Has this site a form every page shares – in a site part (header, footer…) or a pop-up, published or draft? */
    private function siteHasSharedForm(): bool
    {
        $db = $this->app->db();
        foreach ([...$db->all('SELECT stavba, stavba_koncept FROM {casti}'), ...$db->all('SELECT stavba, NULL AS stavba_koncept FROM {popupy}')] as $row) {
            foreach ([$row['stavba'], $row['stavba_koncept']] as $json) {
                $build = $json !== null ? Build::fromJson((string) $json) : null;
                if ($build !== null && self::countElements($build['deti'] ?? [], $db)[0] > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is the new title the old one? The old <title> usually carries the site name ("Kitchens | Northwind"), so each part
     * between the usual separators counts; letter case and spacing do not. An empty title compares as the same.
     */
    public static function sameTitle(string $old, string $new): bool
    {
        if (trim($old) === '' || trim($new) === '') {
            return true;
        }
        $parts = function (string $title): array {
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $all = [$title, ...(preg_split('/\s+[|–—\-:·»]\s+/u', $title) ?: [])];

            return array_filter(array_map(fn (string $t): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $t))), $all), fn (string $t): bool => $t !== '');
        };

        return array_intersect($parts($old), $parts($new)) !== [];
    }

    /** A problem code in words, for the admin and for Claude. */
    public static function describe(string $code): string
    {
        return match ($code) {
            'missing' => t('Nothing at this address and no redirect – it will end in 404.'),
            'hidden' => t('The page is here but not published yet.'),
            'chain' => t('Redirected more than once – point the redirect straight at the final page.'),
            'redirect_out' => t('Redirected to another site.'),
            'no_description' => t('The old page had a search engine description, the new one has none.'),
            'form_missing' => t('The old page had a form, the new one has none.'),
            'fewer_images' => t('The new page has less than half of the old page’s images.'),
            'not_read' => t('The old page could not be read, so only the address was checked.'),
            'robots' => t('The robots.txt of the old site asks robots not to read this page, so only the address was checked.'),
            'too_large' => t('The old page is over a safety limit for HTML (too large or nested too deeply), so only the address was checked.'),
            'sample_page' => t('The address leads to a sample page of the installation that nobody has changed – visitors would see sample text. Import the old page over it or replace its content.'),
            'title_changed' => t('The page title changed – check that the new one is what search engines should show.'),
            default => $code,
        };
    }

    /** @param array{disallow?: list<string>, allow?: list<string>, delay?: ?float} $robots */
    private function fetch(string $url, array $robots): ?string
    {
        try {
            $data = WebImport::politeDownload($this->downloader, $url, $robots);
        } catch (\RuntimeException) {
            return null;
        }

        return $data !== '' && strlen($data) < 5_000_000 ? $data : null;
    }
}
