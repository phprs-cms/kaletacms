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
    ];

    private float $end = 0.0;

    /** @var list<array<string, mixed>>|null pattern redirects of the site (3.6), read once per report */
    private ?array $patterns = null;

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
        file_put_contents(self::file((string) $state['id']), (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
                $state['hledani'] = ['adresy' => count($state['adresy'])] + array_intersect_key($discovery, ['mapy_cizi' => 1, 'mapy_navic' => 1]);
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
        }

        return ['stara' => '/' . $path, 'nova' => $target['adresa'], 'stav' => $status, 'problemy' => $problems,
            'titulek_stary' => $old['titulek'] ?? '', 'titulek_novy' => $target['titulek']];
    }

    /**
     * Where a path leads on this site: ok (published content), redirect (one hop to published content), chain (more hops),
     * redirect_out (to another site), hidden (content that is not published) or missing.
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
            return $content + ['adresa' => '/' . $path, 'stav' => $content['zobrazeno'] ? ($hops === 0 ? 'ok' : ($hops === 1 ? 'redirect' : 'chain')) : 'hidden'];
        }
        $rule = RedirectRules::isPattern($path) ? null : $db->one('SELECT na_adresu, typ FROM {presmerovani} WHERE z_adresy = ?', [$path]);
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
     * @return array{typ: string, titulek: string, popis: string, formular: bool, obrazky: int, zobrazeno: bool}|null
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
            $page = $home > 0 ? $db->one('SELECT titulek, seo_titulek, popis, zobrazit, stavba, stavba_koncept, text FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$home]) : null;

            return $page !== null ? self::page($page) : ['typ' => 'home', 'titulek' => (string) $settings->get('site_name'), 'popis' => (string) $settings->get('site_description'), 'formular' => false, 'obrazky' => 0, 'zobrazeno' => true];
        }
        if ($s[0] === 'novinky' && count($s) === 2) {
            $n = $db->one('SELECT titulek, seo_titulek, seo_popis, uvod, text, visible FROM {novinky} WHERE seo_link = ? AND smazano IS NULL ORDER BY jazyk = ? DESC LIMIT 1', [$s[1], $column]);

            return $n === null ? null : ['typ' => 'news', 'titulek' => (string) ($n['seo_titulek'] ?: $n['titulek']),
                'popis' => trim((string) ($n['seo_popis'] ?: strip_tags((string) $n['uvod']))), 'formular' => false,
                'obrazky' => substr_count(strtolower((string) $n['text']), '<img'), 'zobrazeno' => (bool) $n['visible']];
        }
        $page = $db->one('SELECT titulek, seo_titulek, popis, zobrazit, stavba, stavba_koncept, text FROM {stranky} WHERE seo_link = ? AND smazano IS NULL ORDER BY jazyk = ? DESC LIMIT 1', [implode('/', $s), $column]);
        if ($page !== null) {
            return self::page($page);
        }
        if (count($s) === 2) {
            $item = $db->one('SELECT p.nazev, p.seo_titulek, p.popis, p.zobrazit, p.data FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND k.detail = 1 AND p.seo_link = ? AND p.smazano IS NULL ORDER BY p.jazyk = ? DESC LIMIT 1', [$s[0], $s[1], $column]);
            if ($item !== null) {
                return ['typ' => 'item', 'titulek' => (string) ($item['seo_titulek'] ?: $item['nazev']), 'popis' => trim((string) $item['popis']), 'formular' => false,
                    'obrazky' => preg_match_all('#\.(jpe?g|png|webp|gif|avif)"#i', (string) $item['data']), 'zobrazeno' => (bool) $item['zobrazit']];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $p a row of ka_stranky
     * @return array{typ: string, titulek: string, popis: string, formular: bool, obrazky: int, zobrazeno: bool}
     */
    private static function page(array $p): array
    {
        // a hidden imported page is still being worked on: count its draft
        $json = $p['zobrazit'] ? ($p['stavba'] ?? null) : ($p['stavba_koncept'] ?? $p['stavba'] ?? null);
        [$forms, $images] = [0, substr_count(strtolower((string) $p['text']), '<img')];
        if ($json !== null) {
            $build = Build::fromJson((string) $json);
            [$forms, $images] = self::countElements($build['deti'] ?? []);
        }

        return ['typ' => 'page', 'titulek' => (string) ($p['seo_titulek'] ?: $p['titulek']), 'popis' => trim((string) $p['popis']),
            'formular' => $forms > 0, 'obrazky' => $images, 'zobrazeno' => (bool) $p['zobrazit']];
    }

    /**
     * Forms and images in a build: the Form element, and the Image element plus the photos of galleries and carousels.
     *
     * @param list<array<string, mixed>> $children
     * @return array{0: int, 1: int}
     */
    public static function countElements(array $children): array
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
            }
            [$f, $i] = self::countElements(is_array($el['deti'] ?? null) ? $el['deti'] : []);
            $forms += $f;
            $images += $i;
        }

        return [$forms, $images];
    }

    /**
     * What the old page had: its full title, the search engine description, a form (not just a search box) and the
     * number of images in the main content.
     *
     * @return array{titulek: string, popis: string, formular: bool, obrazky: int}
     * @throws HtmlTooLarge when the page is over a limit of HtmlLimits
     */
    public static function analyse(string $html, string $url): array
    {
        $doc = HtmlLimits::document($html);
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $doc->querySelector('title')?->textContent));
        $description = trim((string) $doc->querySelector('meta[name="description"]')?->getAttribute('content'));
        $form = false;
        foreach ($doc->querySelectorAll('form') as $f) {
            $role = strtolower($f->getAttribute('role') ?? '');
            $fields = $f->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="search"]), textarea, select');
            if ($role !== 'search' && $fields->length > 0 && !preg_match('/search|hledat|suche/i', ($f->getAttribute('class') ?? '') . ' ' . ($f->getAttribute('action') ?? ''))) {
                $form = true;
                break;
            }
        }
        $main = WebImport::extract($html, $url);

        return ['titulek' => mb_substr($title, 0, 200), 'popis' => mb_substr($description, 0, 320), 'formular' => $form,
            'obrazky' => substr_count(strtolower($main['obsah']), '<img')];
    }

    /* ---------- the result ---------- */

    /**
     * Counts, the rows with a problem first, and the checks of the whole site.
     *
     * @param array<string, mixed> $state
     * @return array{souhrn: array<string, int>, radky: list<array<string, mixed>>, web: list<array{zprava: string, uprava: string}>, poznamky: list<string>}
     */
    public function result(array $state): array
    {
        $summary = ['adres' => count($state['adresy']), 'zkontrolovano' => count($state['radky']), 'ok' => 0, 'presmerovano' => 0, 'skryto' => 0, 'chybi' => 0, 'chyb' => 0, 'varovani' => 0];
        foreach ($state['radky'] as $r) {
            match ($r['stav']) {
                'ok' => $summary['ok']++,
                'redirect', 'chain', 'redirect_out' => $summary['presmerovano']++,
                'hidden' => $summary['skryto']++,
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
        $rows = $state['radky'];
        usort($rows, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        $site = [];
        if (!Extensions::isEnabled($this->app->settings(), 'presmerovani')) {
            $site[] = ['zprava' => t('The Redirects feature is off: no redirect from an old address works.'), 'uprava' => 'admin.php?module=extensions'];
        }
        if ($state['faze'] === 'hotovo') {
            foreach ((new Audit($this->app))->handoverFindings() as $f) {
                $site[] = ['zprava' => (string) $f['message'], 'uprava' => (string) $f['edit']];
            }
        }

        // how the old site was read: a robots.txt that was cut, sitemaps on other hosts that were skipped (3.7, N37-23, N37-24)
        $notes = WebImport::notes(['robots' => $state['robots'] ?? ($state['hledani']['robots'] ?? [])] + (is_array($state['hledani'] ?? null) ? $state['hledani'] : []));

        return ['souhrn' => $summary, 'radky' => $rows, 'web' => $site, 'poznamky' => $notes];
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
