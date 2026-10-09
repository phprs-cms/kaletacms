<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Redirects;
use Kaleta\Admin\Modules\Pages;

/**
 * Import from WordPress: pages, posts (→ news), categories, tags, redirects from old URLs and (separately) images.
 * Comments are not carried over – a company site does not have them.
 *
 * How it holds together:
 *  - The file is read as a stream (Core\WpFile) and the work is done IN BATCHES – at most BATCH posts or SECONDS seconds per request,
 *    so that the import survives the time limits of shared hosting. Where it stopped (which <item>) is kept in the state file
 *    storage/import/stav-<hash>.json; the next request continues from there.
 *  - The table ka_import_mapa remembers which foreign record became which of ours. So the same file can be run again without
 *    duplicates (an already converted news item is skipped and later edits are not overwritten) and images are not downloaded twice.
 *  - There are three passes: preview (only counts, does not touch the database), content import and – only on explicit request –
 *    downloading images.
 *  - No accounts are created: a news item belongs to whoever runs the import – or, when the WordPress author's e-mail is the
 *    e-mail of an existing user here (or the options map the author to a user), to that user (3.6). News items have no
 *    free-text author, so the WordPress name itself is not kept; the summary says so.
 *  - Imported news items are "announced" right away – hundreds of old texts must not trigger the webhook or IndexNow.
 *  - Navigation menus (3.6) are collected while the file is read and built when the content is in, so their links point to
 *    the new addresses; they go to the draft look (Core\Look) and change the site only when the look is published.
 *  - The option "skryte" (always on over MCP, 3.6) brings everything in hidden: news as drafts, pages and items hidden.
 *  - The admin (Admin\Modules\Transfer) and MCP (import_wordpress) share one code path: options(), run(), advance() and summary().
 */
final class WpImport
{
    public const int BATCH = 100;
    public const int IMAGE_BATCH = 10;
    public const int SECONDS = 8;

    /**
     * Default import options (the Preview step). 3.6: menu = navigation menus into the draft look, menu_umisteni = WordPress
     * menu slug => hlavni | paticka | '' (skip), autori = WordPress login => our user id, skryte = everything arrives hidden,
     * obrazky = images are downloaded right after the content (MCP; the admin has its own button for it).
     */
    public const array DEFAULT_OPTIONS = ['jazyk' => '', 'koncepty' => true, 'stranky' => true, 'stavitel' => true, 'presmerovani' => true, 'rubrika' => 0, 'kolekce' => true,
        'menu' => true, 'menu_umisteni' => [], 'autori' => [], 'skryte' => false, 'obrazky' => false];

    /** At most this many menu items are kept from one file (Core\Menu takes 80 per menu). */
    public const int MAX_MENU_ITEMS = 500;

    /** At most this many redirects and skipped addresses are listed in the state (all of them are counted). */
    private const int MAX_LISTED = 200;

    /** Post types we can handle; the preview only lists the others (menus, custom types of add-ons…). */
    private const array TYPES = ['post', 'page', 'attachment'];

    private string $source = 'wp';

    /** @var array{nazev:string, adresa:string, autori:array<string,string>, emaily:array<string,string>, rubriky:array<string,array{nazev:string, predek:string}>, stitky:array<string,string>, menu:array<string,string>, terminy:array<int,array{0:string, 1:string}>} */
    private array $header = ['nazev' => '', 'adresa' => '', 'autori' => [], 'emaily' => [], 'rubriky' => [], 'stitky' => [], 'menu' => [], 'terminy' => []];

    /** @var array<string, int> WordPress author login => our user the news items belong to (3.6) */
    private array $authors = [];

    /** @var array<string, int> categories converted in this request (category slug in WordPress => our idt) */
    private array $categories = [];

    private int $downloadsLeft = 0;
    private float $end = 0.0;

    /**
     * @param string $base the site folder for image URLs in the text (Request::basePath(), empty for a site in the root)
     * @param int $author the account the imported articles will belong to
     */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $base, private readonly int $author)
    {
    }

    /* ---------- state file ---------- */

    /** @return array<string, mixed> */
    public static function newState(string $file): array
    {
        return [
            'soubor' => $file, 'otisk' => '', 'faze' => 'analyza', 'pozice' => 0, 'celkem' => 0, 'web' => ['nazev' => '', 'adresa' => ''],
            'prehled' => ['clanky' => [], 'stranky' => [], 'rubriky' => 0, 'stitky' => 0, 'autori' => 0, 'prilohy' => 0, 'obrazky' => 0, 'jine' => [], 'zkratky' => [], 'seo' => [], 'typy' => [],
                'menu' => [], 'menu_bez' => 0, 'prispevky_autoru' => [], 'stavitele' => []],
            'prilohy' => [], 'volby' => self::DEFAULT_OPTIONS, 'nahledy' => [],
            'menu' => [], // items of the navigation menus (tallyMenuItem), built after the content (menus())
            'vysledek' => ['clanky' => 0, 'stranky' => 0, 'rubriky' => 0, 'presmerovani' => 0, 'preskoceno' => 0, 'seo' => 0, 'polozky' => 0, 'skryto' => 0],
            // 3.6: what was left out and why (reason => count), the redirects made and refused, the menus and authors
            'vynechano' => [], 'presmerovani' => ['nove' => [], 'odmitnute' => []], 'menu_vysledek' => [], 'autori_vysledek' => [],
            'obr' => ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => 0, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function loadState(string $file): ?array
    {
        $path = self::stateFile($file);
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($state) && ($state['soubor'] ?? '') === $file ? array_replace_recursive(self::newState($file), $state) : null;
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        $path = self::stateFile((string) $state['soubor']);
        // first next to it, then rename: an interrupted write must not leave a half-written file
        file_put_contents($path . '.tmp', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($path . '.tmp', $path);
    }

    public static function deleteState(string $file): void
    {
        @unlink(self::stateFile($file));
    }

    /**
     * The records the import has created so far – news items, pages, categories, collections and their items, redirects
     * and downloaded images; MCP counts the difference of one step against Claude's hourly change limit (3.7, N37-26).
     *
     * @param array<string, mixed> $state
     */
    public static function created(array $state): int
    {
        $v = is_array($state['vysledek'] ?? null) ? $state['vysledek'] : [];
        $images = is_array($state['obr'] ?? null) ? $state['obr'] : [];

        return array_sum(array_map(intval(...), array_values(array_intersect_key($v, array_flip(['clanky', 'stranky', 'rubriky', 'polozky', 'kolekce', 'presmerovani'])))))
            + intval($images['stazeno'] ?? 0);
    }

    private static function stateFile(string $file): string
    {
        return WpFile::folder() . '/stav-' . substr(sha1($file), 0, 16) . '.json';
    }

    /* ---------- pass 1: preview (writes nothing to the database) ---------- */

    /**
     * Goes through the next part of the file and adds it to the overview. When it reaches the end, it switches the phase to "nahled".
     *
     * @param array<string, mixed> $state
     */
    public static function analyze(array &$state, float $seconds = self::SECONDS, ?string $path = null): void
    {
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['soubor'])); // $path only for tests; otherwise always the file from storage/import
        if ($state['pozice'] === 0) {
            $h = $wp->header();
            $state['web'] = ['nazev' => $h['nazev'], 'adresa' => $h['adresa']];
            $state['prehled']['rubriky'] = count($h['rubriky']);
            $state['prehled']['stitky'] = count($h['stitky']);
            $state['prehled']['autori'] = count($h['autori']);
        }
        $end = microtime(true) + $seconds;
        foreach ($wp->items((int) $state['pozice']) as $order => $p) {
            self::tally($state, $p);
            $state['pozice'] = $order + 1;
            if (microtime(true) > $end) {
                return;
            }
        }
        $state['celkem'] = $state['pozice'];
        $state['pozice'] = 0;
        $state['faze'] = 'nahled';
        arsort($state['prehled']['zkratky']);
        $state['prehled']['zkratky'] = array_slice($state['prehled']['zkratky'], 0, 15, true);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $p a post from WpFile::item()
     */
    private static function tally(array &$state, array $p): void
    {
        $overview = &$state['prehled'];
        if ($p['typ'] === 'attachment') {
            $overview['prilohy']++;
            if ($p['priloha_url'] !== '') {
                $state['prilohy'][(int) $p['id']] = $p['priloha_url']; // for [gallery ids] and the featured images of articles
            }
        } elseif ($p['typ'] === 'nav_menu_item') {
            self::tallyMenuItem($state, $p);
        } elseif ($p['typ'] === 'post' || $p['typ'] === 'page') {
            $destination = $p['typ'] === 'post' ? 'clanky' : 'stranky';
            $overview[$destination][$p['stav']] = ($overview[$destination][$p['stav']] ?? 0) + 1;
            if ($p['typ'] === 'post' && $p['autor'] !== '' && self::articleStatus($p['stav']) !== null) {
                $overview['prispevky_autoru'][$p['autor']] = ($overview['prispevky_autoru'][$p['autor']] ?? 0) + 1;
            }
            if ($p['stavitel'] !== '') {
                // a page builder's layout is not in the post text – only the text there is comes over (3.6)
                $overview['stavitele'][$p['stavitel']] = ($overview['stavitele'][$p['stavitel']] ?? 0) + 1;
            }
            $overview['obrazky'] += substr_count(strtolower($p['obsah']), '<img');
            foreach (WpContent::unknownShortcodes($p['obsah']) as $shortcode) {
                $overview['zkratky'][$shortcode] = ($overview['zkratky'][$shortcode] ?? 0) + 1;
            }
            // SEO plugin data (Core\WpSeo): how many items carry a custom title, description, noindex or canonical URL, per plugin
            $seo = WpSeo::raw($p['meta'] ?? []);
            if ($seo['plugin'] !== '') {
                $counts = $overview['seo'][$seo['plugin']] ?? ['title' => 0, 'description' => 0, 'noindex' => 0, 'canonical' => 0];
                $counts['title'] += (int) ($seo['title'] !== '' && !WpSeo::isDefaultPattern($seo['title']));
                $counts['description'] += (int) ($seo['description'] !== '' && !WpSeo::isDefaultPattern($seo['description']));
                $counts['noindex'] += (int) $seo['noindex'];
                $counts['canonical'] += (int) ($seo['canonical'] !== '');
                $overview['seo'][$seo['plugin']] = $counts;
            }
        } elseif (WpTypes::isCustomType($p['typ']) && self::articleStatus($p['stav']) !== null) {
            // a custom post type becomes a collection (2.7): count its items, vote on the type of each field, remember the address
            $t = $overview['typy'][$p['typ']] ?? ['pocet' => 0, 'predpony' => [], 'pole' => [], 'vynechano' => [], 'obsah' => false, 'perex' => false];
            $t['pocet']++;
            $prefix = WpTypes::prefix($p['odkaz']);
            if ($prefix !== '') {
                $t['predpony'][$prefix] = ($t['predpony'][$prefix] ?? 0) + 1;
            }
            $t['obsah'] = $t['obsah'] || trim(strip_tags($p['obsah'])) !== '' || str_contains($p['obsah'], '<img');
            $t['perex'] = $t['perex'] || trim($p['perex']) !== '';
            foreach (WpTypes::fields($p['pole'] ?? []) as $key => $value) {
                if (!isset($t['pole'][$key]) && count($t['pole']) >= WpTypes::MAX_FIELDS) {
                    continue;
                }
                $type = WpTypes::guessType($key, $value, $state['prilohy']);
                if ($type === null) {
                    $t['vynechano'][$key] = true;
                    continue;
                }
                $t['pole'][$key][$type] = ($t['pole'][$key][$type] ?? 0) + ($value === '' ? 0 : 1);
            }
            $overview['typy'][$p['typ']] = $t;
        } elseif (!in_array($p['typ'], self::TYPES, true)) {
            $overview['jine'][$p['typ']] = ($overview['jine'][$p['typ']] ?? 0) + 1;
        }
    }

    /**
     * One item of a navigation menu (3.6): kept in the state as it is in the file, because the pages and posts it points to
     * may come later in the file. menus() builds the menus once the content is in. Items not in a menu, unpublished
     * ones (left over in the Customizer) and items over MAX_MENU_ITEMS are only counted.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $p a post from WpFile::item()
     */
    private static function tallyMenuItem(array &$state, array $p): void
    {
        $meta = $p['meta'];
        if ($p['menu'] === '' || $p['stav'] !== 'publish' || count($state['menu']) >= self::MAX_MENU_ITEMS) {
            $state['prehled']['menu_bez']++;

            return;
        }
        $state['menu'][] = [
            'id' => (int) $p['id'], 'menu' => (string) $p['menu'], 'nadrazena' => (int) ($meta['_menu_item_menu_item_parent'] ?? 0), 'poradi' => (int) $p['poradi'],
            'druh' => (string) ($meta['_menu_item_type'] ?? 'custom'), 'objekt' => (string) ($meta['_menu_item_object'] ?? ''),
            'objekt_id' => (int) ($meta['_menu_item_object_id'] ?? 0), 'url' => trim((string) ($meta['_menu_item_url'] ?? '')),
            'text' => mb_substr((string) $p['titulek'], 0, 80), 'nove_okno' => ($meta['_menu_item_target'] ?? '') === '_blank',
        ];
        $menu = $state['prehled']['menu'][$p['menu']] ?? ['nazev' => (string) $p['menu_nazev'], 'polozky' => 0];
        $menu['polozky']++;
        $state['prehled']['menu'][$p['menu']] = $menu;
    }

    /* ---------- pure conversions (covered by tools/unit-tests.php) ---------- */

    /**
     * Post status in WordPress → our news item; null = not imported (private, trash, auto-drafts, revisions).
     * A scheduled post is a published news item with a future date for us; "pending review" is a draft.
     *
     * @return array{visible:int}|null
     */
    public static function articleStatus(string $wpStatus, bool $passwordProtected = false): ?array
    {
        $state = match ($wpStatus) {
            'publish', 'future' => ['visible' => 1],
            'draft', 'pending' => ['visible' => 0],
            default => null,
        };

        // a password-protected post has no equivalent here – it must not be published silently, it stays a draft
        return $state !== null && $passwordProtected ? ['visible' => 0] : $state;
    }

    /**
     * Publish date: the old site's local time; drafts often have it zeroed, then the GMT time, the RSS date and finally today are used.
     *
     * @param array<string, mixed> $p
     */
    public static function date(array $p, ?int $now = null): string
    {
        foreach ([$p['datum'] ?? '', $p['datum_gmt'] ?? '', $p['vydano'] ?? ''] as $i => $value) {
            $time = $value === '' || str_starts_with((string) $value, '0000') ? false : strtotime($value . ($i === 1 ? ' UTC' : ''));
            if ($time !== false && $time > 0) {
                return date('Y-m-d H:i:s', $time);
            }
        }

        return date('Y-m-d H:i:s', $now ?? time());
    }

    /**
     * A free slug (seo_link): when the base is taken, it gets a sequence number – the same as in the admin.
     *
     * @param callable(string): bool $isTaken
     */
    public static function availableSlug(string $base, callable $isTaken): string
    {
        return Slug::makeUnique($base, $isTaken, 120);
    }

    /** Path of the old URL for a redirect (without the domain and the slashes at the ends); empty = nothing to redirect. */
    public static function oldPath(string $link): string
    {
        $path = trim(rawurldecode((string) parse_url($link, PHP_URL_PATH)), '/ ');

        return mb_strlen($path) > 255 || !mb_check_encoding($path, 'UTF-8') ? '' : $path;
    }

    /** Image URL without the thumbnail size and without parameters: foto-300x200.jpg?x=1 → foto.jpg (WordPress inserts downsized copies into the text). */
    public static function withoutSize(string $url): string
    {
        $url = (string) preg_replace('/[?#].*$/', '', $url);

        return (string) preg_replace('#-\d{2,5}x\d{2,5}(?=\.(?:jpe?g|png|gif|webp)$)#i', '', $url);
    }

    /** Source label in ka_import_mapa: two different old sites have the same post numbers, which is why it contains the domain. */
    public static function source(string $siteUrl): string
    {
        $domain = ImageDownloader::domainFromUrl($siteUrl);

        return mb_substr($domain === '' ? 'wp' : 'wp:' . $domain, 0, 40);
    }

    /* ---------- pass 2: content import ---------- */

    /**
     * Converts the next batch of posts. Each post is one transaction: it is either in the database completely (with comments and the map), or not at all.
     *
     * @param array<string, mixed> $state
     */
    public function import(array &$state, ?string $path = null, int $batch = self::BATCH): void
    {
        $wp = new WpFile($path ?? (string) WpFile::path((string) $state['soubor']));
        $this->header = $wp->header();
        $this->source = self::source((string) $state['web']['adresa']);
        $this->authors = $this->authorMap($state);
        $end = microtime(true) + self::SECONDS;
        $count = 0;
        foreach ($wp->items((int) $state['pozice']) as $order => $p) {
            $before = $state;
            try {
                $this->db->transaction(function () use ($p, &$state): void {
                    // HTML over a limit of HtmlLimits stops the post at once (3.8): nothing of it is stored
                    HtmlLimits::guard(function () use ($p, &$state): void {
                        match ($p['typ']) {
                            'post' => $this->article($p, $state),
                            'page' => $state['volby']['stranky'] ? $this->page($p, $state) : self::leftOut($state, 'pages_off'),
                            default => !isset($state['prehled']['typy'][$p['typ']]) ? null
                                : (($state['volby']['kolekce'] ?? true) ? $this->collectionItem($p, $state) : self::leftOut($state, 'collections_off')),
                        };
                    });
                });
            } catch (HtmlTooLarge $e) {
                $state = $before; // counted as left out, with its title and the limit in the report
                self::leftOut($state, 'too_large');
                $state['prilis_velke'] = array_slice([...(array) ($state['prilis_velke'] ?? []),
                    ['titulek' => mb_substr($p['titulek'] !== '' ? $p['titulek'] : '#' . $p['id'], 0, 120), 'limit' => $e->violation]], -15);
            }
            $state['pozice'] = $order + 1;
            if ((++$count >= $batch || microtime(true) > $end) && $state['pozice'] < $state['celkem']) {
                return; // the rest next time; the import knows the number of posts (celkem) from the preview
            }
        }
        // the menus last: now every page and post they point to has its new address
        $state['menu_vysledek'] = ($state['volby']['menu'] ?? true) ? $this->menus($state) : [];
        $state['faze'] = 'hotovo';
    }

    /**
     * Counts a record that is not imported, by its reason (summary() explains each reason).
     *
     * @param array<string, mixed> $state
     */
    private static function leftOut(array &$state, string $reason): void
    {
        $state['vynechano'][$reason] = ($state['vynechano'][$reason] ?? 0) + 1;
    }

    /**
     * The visibility a new record gets: as on the old site, unless the import brings everything in hidden (skryte – always
     * over MCP). A record that was public on the old site and arrives hidden is counted, so the summary can say how many
     * wait for publishing.
     *
     * @param array{visible:int} $status
     * @param array<string, mixed> $state
     * @return array{visible:int}
     */
    private static function hidden(array $status, array &$state): array
    {
        if ($status['visible'] === 1 && ($state['volby']['skryte'] ?? false)) {
            $state['vysledek']['skryto']++;

            return ['visible' => 0];
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function article(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['stav'], $p['heslo'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['volby']['koncepty'])) {
            self::leftOut($state, $articleStatus === null ? 'status' : 'drafts_off');

            return;
        }
        $idc = $this->convertedId('clanek', (string) $p['id'], 'novinky', 'idc');
        if ($idc !== null) {
            $state['vysledek']['preskoceno']++; // an already converted news item stays as it is – someone may have edited it in the meantime
        } else {
            $idc = $this->createArticle($p, self::hidden($articleStatus, $state), $state);
        }
        // for now we only note the featured image – it is downloaded in a separate step (also for a previously converted news item that does not have it yet)
        $preview = (string) ($state['prilohy'][$p['nahled']] ?? '');
        if ($preview !== '' && (string) $this->db->value('SELECT obrazek FROM {novinky} WHERE idc = ?', [$idc]) === '') {
            $state['nahledy'][$idc] = $preview;
        }
    }

    /**
     * @param array<string, mixed> $p
     * @param array{visible:int} $articleStatus
     * @param array<string, mixed> $state
     */
    private function createArticle(array $p, array $articleStatus, array &$state): int
    {
        [$home, $text] = WpContent::introAndText($p['perex'], $p['obsah'], $state['prilohy']);
        $colorScheme = $p['rubriky'] === [] ? $this->defaultCategory($state) : $this->category((string) array_key_first($p['rubriky']), (string) reset($p['rubriky']), $state);
        $language = (string) $this->db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$colorScheme]); // the news item takes over the category's language, as when saving in the admin
        $title = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(untitled)'), 0, 255);
        $now = date('Y-m-d H:i:s');

        $seo = self::availableSlug(
            slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $title, 150),
            fn (string $url): bool => Slug::taken($this->db, 'novinky', $url, $language),
        );
        $plugin = $this->seo($p, (string) (reset($p['rubriky']) ?: ''), 255, 320, $state);
        $idc = $this->db->insert('novinky', [
            'seo_link' => $seo, 'titulek' => $title, 'uvod' => $home, 'text' => $text, 'tema' => $colorScheme, 'jazyk' => $language,
            'autor' => $this->authors[(string) $p['autor']] ?? $this->author,
            'datum' => self::date($p),
            'visible' => $articleStatus['visible'],
            'seo_titulek' => $plugin['title'], 'seo_popis' => $plugin['description'], 'noindex' => $plugin['noindex'],
            'zmeneno' => $now,
            'oznameno' => $now, // an old news item is not announced (webhook, IndexNow)
        ]);
        foreach (array_slice($p['stitky'], 0, 20, true) as $url => $name) {
            $this->tag($idc, (string) $url, $name !== '' ? $name : (string) ($this->header['stitky'][$url] ?? $url));
        }
        Search::index($this->db, $idc);
        Media::recordUsage($this->db, $idc, '', $home, $text);
        $this->writeMap('clanek', (string) $p['id'], $idc);
        $state['vysledek']['clanky']++;

        if ($state['volby']['presmerovani']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . 'novinky/' . $seo, $state);
        }

        return $idc;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function page(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['stav'], $p['heslo'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['volby']['koncepty'])) {
            self::leftOut($state, $articleStatus === null ? 'status' : 'drafts_off');

            return;
        }
        if ($this->convertedId('stranka', (string) $p['id'], 'stranky', 'ids') !== null) {
            $state['vysledek']['preskoceno']++;

            return;
        }
        $articleStatus = self::hidden($articleStatus, $state);
        $title = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(untitled)'), 0, 200);
        $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
        // a page has its slug directly under the site root, so it must not take a slug the system uses
        $seo = Pages::freeSlug($this->db, slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $title, 110), language: $language);
        $text = WpContent::sanitize($p['obsah'], $state['prilohy']);
        $plugin = $this->seo($p, '', 200, 300, $state);
        $ids = $this->db->insert('stranky', [
            'seo_link' => $seo, 'titulek' => $title, 'text' => $text,
            'stavba' => ($state['volby']['stavitel'] ?? false) ? self::pageBuild($this->db, $title, $text) : null,
            'popis' => $plugin['description'] !== '' ? $plugin['description'] : mb_substr(trim(html_entity_decode(strip_tags($p['perex']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 300),
            'seo_titulek' => $plugin['title'], 'noindex' => $plugin['noindex'],
            'zobrazit' => $articleStatus['visible'],
            'v_menu' => 0, // dozens of old pages would flood the navigation; the administrator adds them to the menu themselves
            'zmeneno' => date('Y-m-d H:i:s'), 'jazyk' => $language,
        ]);
        $this->writeMap('stranka', (string) $p['id'], $ids);
        $state['vysledek']['stranky']++;
        if ($state['volby']['presmerovani']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . $seo, $state);
        }
    }

    /**
     * An item of a custom post type as a collection item (2.7). The collection is created with the first item: fields from the
     * preview's votes, the old address prefix as its address (so the items keep their addresses), item pages on.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function collectionItem(array $p, array &$state): void
    {
        $articleStatus = self::articleStatus($p['stav'], $p['heslo'] !== '');
        if ($articleStatus === null || (!$articleStatus['visible'] && !$state['volby']['koncepty'])) {
            self::leftOut($state, $articleStatus === null ? 'status' : 'drafts_off');

            return;
        }
        if ($this->convertedId('polozka', (string) $p['id'], 'kolekce_polozky', 'idp') !== null) {
            $state['vysledek']['preskoceno']++;

            return;
        }
        $articleStatus = self::hidden($articleStatus, $state);
        $collection = $this->collectionFor((string) $p['typ'], $state);
        $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
        $input = [];
        foreach ($collection['mapa'] as $old => $new) {
            $value = (string) ($p['pole'][$old] ?? '');
            $input[$new] = match ($collection['typy'][$new]) {
                'datum' => WpTypes::date($value),
                'obrazek' => ctype_digit(trim($value)) ? (string) ($state['prilohy'][(int) $value] ?? '') : trim($value),
                'html' => WpContent::sanitize($value, $state['prilohy']),
                default => $value,
            };
        }
        if (isset($collection['typy']['obsah'])) {
            $input['obsah'] = WpContent::sanitize($p['obsah'], $state['prilohy']);
        }
        if (isset($collection['typy']['perex'])) {
            $input['perex'] = trim(html_entity_decode(strip_tags($p['perex']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $data = \Kaleta\Builder\Collections::sanitizeData($collection['pole'], $input);
        $title = mb_substr($p['titulek'] !== '' ? $p['titulek'] : t('(untitled)'), 0, 200);
        $idk = (int) $collection['idk'];
        $seo = \Kaleta\Builder\CollectionCategories::freeItemSlug($this->db, $idk, $language, slugify(rawurldecode($p['adresa']) !== '' ? rawurldecode($p['adresa']) : $title, 150), 0, '', 120);
        $plugin = $this->seo($p, '', 200, 300, $state);
        $row = [
            'idk' => $idk, 'nazev' => $title, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'seo_titulek' => $plugin['title'], 'popis' => $plugin['description'], 'noindex' => $plugin['noindex'],
            'zobrazit' => $articleStatus['visible'], 'jazyk' => $language, 'datum' => self::date($p), 'zmeneno' => date('Y-m-d H:i:s'),
        ];
        $idp = $this->db->insert('kolekce_polozky', $row);
        $this->writeMap('polozka', (string) $p['id'], $idp);
        // an imported notice of an official notice board (2.11, Core\Notices) starts its audit trail
        $board = \Kaleta\Builder\Collections::byId($this->db, $idk);
        if ($board !== null && Notices::isNotices($board)) {
            Notices::log($this->db, $idp, 'created', Notices::changes($board, null, $row), 'import');
        }
        if ($p['nahled'] > 0 && isset($state['prilohy'][(int) $p['nahled']])) {
            $state['nahledy']['p' . $idp] = $state['prilohy'][(int) $p['nahled']]; // the featured image as the item's share image
        }
        $state['vysledek']['polozky']++;
        if ($state['volby']['presmerovani']) {
            $state['vysledek']['presmerovani'] += $this->redirect($p, ($language !== '' ? $language . '/' : '') . $collection['seo_link'] . '/' . $seo, $state);
        }
    }

    /**
     * The collection of a custom post type: created on first use, remembered in the state.
     *
     * @param array<string, mixed> $state
     * @return array{idk: int, seo_link: string, pole: list<array{klic: string, popisek: string, typ: string}>, mapa: array<string, string>, typy: array<string, string>}
     */
    private function collectionFor(string $type, array &$state): array
    {
        if (isset($state['kolekce'][$type])) {
            return $state['kolekce'][$type];
        }
        $t = $state['prehled']['typy'][$type];
        $definitions = [];
        $oldKeys = [];
        foreach ($t['pole'] as $old => $votes) {
            $definitions[] = ['popisek' => WpTypes::label((string) $old), 'typ' => WpTypes::fieldType($votes)];
            $oldKeys[] = (string) $old;
        }
        if ($t['perex']) {
            $definitions[] = ['klic' => 'perex', 'popisek' => t('Excerpt'), 'typ' => 'radky'];
        }
        if ($t['obsah']) {
            $definitions[] = ['klic' => 'obsah', 'popisek' => t('Content'), 'typ' => 'html'];
        }
        $fields = \Kaleta\Builder\Collections::sanitizeFields($definitions);
        $map = [];
        foreach ($oldKeys as $i => $old) {
            if (isset($fields[$i])) {
                $map[$old] = $fields[$i]['klic'];
            }
        }
        arsort($t['predpony']);
        $wanted = (string) (array_key_first($t['predpony']) ?? '') ?: slugify($type, 100);
        $earlier = $this->convertedId('kolekce', $type, 'kolekce', 'idk');
        $existing = $earlier !== null ? $this->db->one('SELECT idk, seo_link, pole FROM {kolekce} WHERE idk = ?', [$earlier]) : null;
        if ($existing !== null) {
            $idk = (int) $existing['idk']; // the same collection from an earlier run of this import
            $seo = (string) $existing['seo_link'];
            $fields = json_decode((string) $existing['pole'], true) ?: $fields;
        } else {
            $seo = self::availableSlug($wanted, fn (string $url): bool => in_array($url, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$url])
                || $this->db->value('SELECT idk FROM {kolekce} WHERE seo_link = ?', [$url]) !== null || $this->db->value('SELECT ids FROM {stranky} WHERE seo_link = ?', [$url]) !== null);
            $idk = $this->db->insert('kolekce', ['nazev' => mb_substr(WpTypes::label($type), 0, 100), 'seo_link' => $seo, 'detail' => 1,
                'pole' => (string) json_encode($fields, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]);
            $this->writeMap('kolekce', $type, $idk);
            $state['vysledek']['kolekce'] = ($state['vysledek']['kolekce'] ?? 0) + 1;
        }

        return $state['kolekce'][$type] = ['idk' => $idk, 'seo_link' => $seo, 'pole' => $fields, 'mapa' => $map,
            'typy' => array_column($fields, 'typ', 'klic')];
    }

    /**
     * SEO title, description and noindex from the SEO plugin meta of the post (Core\WpSeo), with the plugin variables filled in
     * from the new site and the post; trimmed to the columns of the target table.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     * @return array{title:string, description:string, noindex:int}
     */
    private function seo(array $p, string $category, int $titleLimit, int $descriptionLimit, array &$state): array
    {
        $raw = WpSeo::raw($p['meta'] ?? []);
        if ($raw['plugin'] === '') {
            return ['title' => '', 'description' => '', 'noindex' => 0];
        }
        $plain = fn (string $html): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('/\[[^\]]*\]/', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $excerpt = $plain($p['perex']);
        $context = [
            'title' => $p['titulek'], 'sitename' => $this->settings->get('site_name'), 'sitedesc' => $this->settings->get('site_description'),
            'excerpt' => mb_strimwidth($excerpt !== '' ? $excerpt : $plain($p['obsah']), 0, 160, '…'), 'category' => $category,
        ];
        $result = ['title' => WpSeo::title($raw['title'], $context, $titleLimit), 'description' => WpSeo::description($raw['description'], $context, $descriptionLimit), 'noindex' => (int) $raw['noindex']];
        if ($result['title'] !== '' || $result['description'] !== '' || $result['noindex'] === 1) {
            $state['vysledek']['seo']++;
        }

        return $result;
    }

    /**
     * Category by the category slug in WordPress (the tree is flattened); creates it when it does not exist yet. Only categories with posts are created.
     *
     * @param array<string, mixed> $state
     */
    private function category(string $url, string $name, array &$state): int
    {
        if (isset($this->categories[$url])) {
            return $this->categories[$url];
        }
        $idt = $this->convertedId('rubrika', $url, 'kategorie', 'idt');
        if ($idt === null) {
            $description = $this->header['rubriky'][$url] ?? ['nazev' => $name, 'predek' => ''];
            $name = mb_substr($description['nazev'] !== '' ? $description['nazev'] : ($name !== '' ? $name : $url), 0, 100);
            $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
            $seo = slugify(rawurldecode($url), 110);
            // the same slug, name and language = the same category that is already on the site; otherwise a new one with a free slug
            $idt = $this->db->value('SELECT idt FROM {kategorie} WHERE seo_link = ? AND jazyk = ? AND LOWER(nazev) = LOWER(?)', [$seo, $language, $name]);
            if ($idt === null) {
                $idt = $this->db->insert('kategorie', [
                    'nazev' => $name, 'popis' => '', 'jazyk' => $language,
                    'seo_link' => self::availableSlug($seo, fn (string $a): bool => Slug::taken($this->db, 'kategorie', $a, $language)),
                ]);
                $state['vysledek']['rubriky']++;
            }
            $this->writeMap('rubrika', $url, (int) $idt);
        }

        return $this->categories[$url] = (int) $idt;
    }

    /**
     * Category for posts without a category: the one chosen in the preview, otherwise 'Nezařazené' (Uncategorized) is created.
     *
     * @param array<string, mixed> $state
     */
    private function defaultCategory(array &$state): int
    {
        $idt = (int) $state['volby']['rubrika'];
        if ($idt > 0 && $this->db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$idt]) !== null) {
            return $idt;
        }

        return $state['volby']['rubrika'] = $this->category('nezarazene', t('Nezařazené'), $state);
    }

    /** A tag is looked up by the slug made from its name and an unknown one is created – the same as when saving a news item in the admin. */
    private function tag(int $idc, string $wpSlug, string $name): void
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            return;
        }
        $seo = slugify($name, 90);
        $ids = $this->db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]);
        $ids = $ids !== null ? (int) $ids : $this->db->insert('stitky', ['nazev' => $name, 'seo_link' => $seo]);
        $this->db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$idc, $ids]);
        $this->writeMap('stitek', $wpSlug, $ids);
    }

    /**
     * Redirect from the old URL (both the pretty and the numeric /?p=123) to the new one. Redirects::add skips an identical URL by itself.
     * 3.6: an old address that already is a page or an item of this site, or that already redirects somewhere else, is left
     * alone and listed – Redirects::add would otherwise take it over and rewrite every link to it on the live site
     * (Core\LinkHealing) to the new, possibly hidden record.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $state
     */
    private function redirect(array $p, string $newVersion, array &$state): int
    {
        $count = 0;
        foreach (array_unique([self::oldPath($p['odkaz']), $p['id'] > 0 ? '?p=' . (int) $p['id'] : '']) as $old) {
            if ($old === '' || $old === $newVersion) {
                continue;
            }
            $existing = $this->db->value('SELECT na_adresu FROM {presmerovani} WHERE z_adresy = ?', [$old]);
            $refused = match (true) {
                $existing !== null && trim((string) $existing, '/') !== trim($newVersion, '/') => 'redirect_exists',
                $existing === null && $this->addressInUse($old) => 'address_in_use',
                default => '',
            };
            if ($refused !== '') {
                self::listRedirect($state, 'odmitnute', ['/' . $old, '/' . $newVersion, $refused]);
                continue;
            }
            if ($existing === null) {
                Redirects::add($this->db, $old, $newVersion);
                self::listRedirect($state, 'nove', ['/' . $old, '/' . $newVersion]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Does the old path already lead somewhere here – a page or an item (also hidden ones), or anything the site routes: a page
     * of a language version, the news list, a news item, a category or tag (3.6, N36-1)? Then it must not become a redirect
     * (see redirect()).
     */
    private function addressInUse(string $path): bool
    {
        // with slugs per language (3.9) an old /en/… address is looked up in the English version only (Polylang, WPML)
        $prefix = Slug::perLanguage($this->db) ? Language::splitPrefix('/' . $path) : null;
        $language = $prefix !== null && in_array($prefix[0], Language::additional($this->settings), true) ? $prefix[0] : '';
        $slug = $language !== '' ? trim((string) $prefix[1], '/') : $path;
        [$sameLanguage, $languageParams] = Slug::scope($this->db, $language);
        [$sameItemLanguage] = Slug::scope($this->db, $language, 'p.jazyk');
        if ($this->db->value('SELECT 1 FROM {stranky} WHERE seo_link = ? AND smazano IS NULL' . $sameLanguage, [$slug, ...$languageParams]) !== null
            || (!str_starts_with($path, '?') && Audit::pathResolves($this->db, $this->settings, '/' . $path))) {
            return true;
        }
        $parts = explode('/', $slug);

        return count($parts) === 2 && $this->db->value('SELECT 1 FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND p.seo_link = ? AND p.smazano IS NULL' . $sameItemLanguage, [...$parts, ...$languageParams]) !== null;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $row [old, new] or [old, new, reason]
     */
    private static function listRedirect(array &$state, string $list, array $row): void
    {
        if (count($state['presmerovani'][$list]) < self::MAX_LISTED) {
            $state['presmerovani'][$list][] = $row;
        }
    }

    /* ---------- pass 3: images from the old site ---------- */

    /**
     * Start (or restart) of downloading images: counters from zero; what failed to download last time gets a second chance.
     *
     * @param array<string, mixed> $state
     */
    public function startImages(array &$state): void
    {
        $this->source = self::source((string) $state['web']['adresa']);
        $this->db->run("DELETE FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND nase_id = 0", [$this->source]);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {import_mapa} WHERE zdroj = ? AND typ IN ('clanek', 'stranka', 'polozka')", [$this->source]);
        $state['obr'] = ['typ' => 'clanek', 'id' => 0, 'hotovo' => 0, 'celkem' => $total, 'stazeno' => 0, 'chyb' => 0, 'chyby' => []];
        $state['faze'] = 'obrazky';
    }

    /**
     * Downloads the next batch of images: the article's featured image and the images in the text that are on the old site's domain.
     * Works on already converted records (not on the file); position = the last finished article or page.
     *
     * @param array<string, mixed> $state
     */
    public function images(array &$state, ImageDownloader $downloader): void
    {
        $this->source = self::source((string) $state['web']['adresa']);
        $this->downloadsLeft = self::IMAGE_BATCH;
        $this->end = microtime(true) + self::SECONDS;
        while (true) {
            $id = $this->db->value('SELECT MIN(nase_id) FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND nase_id > ?', [$this->source, $state['obr']['typ'], (int) $state['obr']['id']]);
            if ($id === null && $state['obr']['typ'] !== 'polozka') {
                $state['obr'] = ['typ' => $state['obr']['typ'] === 'clanek' ? 'stranka' : 'polozka', 'id' => 0] + $state['obr']; // pages after the articles, collection items last
                continue;
            }
            if ($id === null) {
                $state['faze'] = 'obrazky-hotovo';

                return;
            }
            if (!$this->recordImages((string) $state['obr']['typ'], (int) $id, $state, $downloader)) {
                return; // the batch ran out in the middle of a record – next time it continues with the same one
            }
            $state['obr']['id'] = (int) $id;
            $state['obr']['hotovo']++;
            if ($this->downloadsLeft <= 0 || microtime(true) > $this->end) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return bool false = the batch budget ran out, the record is not complete yet
     */
    private function recordImages(string $type, int $id, array &$state, ImageDownloader $downloader): bool
    {
        if ($type === 'polozka') {
            return $this->itemImages($id, $state, $downloader);
        }
        $record = $type === 'clanek'
            ? $this->db->one('SELECT idc, titulek, uvod, text, obrazek FROM {novinky} WHERE idc = ?', [$id])
            : $this->db->one("SELECT ids, titulek, '' AS uvod, text, '' AS obrazek FROM {stranky} WHERE ids = ?", [$id]);
        if ($record === null) {
            return true; // someone deleted the record in the meantime
        }
        $complete = true;
        $newItems = ['uvod' => (string) $record['uvod'], 'text' => (string) $record['text'], 'obrazek' => (string) $record['obrazek']];
        foreach (['uvod', 'text'] as $field) {
            $newItems[$field] = self::rewriteImages($newItems[$field], function (string $src, string $alt) use ($downloader, &$state, &$complete, $record): ?array {
                if (!$complete || !$downloader->isAllowedUrl($src)) {
                    return null; // foreign images (another domain) stay as they are – they are never downloaded
                }
                $image = $this->image($src, $alt !== '' ? $alt : (string) $record['titulek'], $state, $downloader);
                if ($image === false) {
                    $complete = false;
                }

                return is_array($image) ? self::mediaImage($this->base, $image, $alt) : null;
            });
        }
        $preview = (string) ($state['nahledy'][$id] ?? '');
        if ($type === 'clanek' && $complete && $preview !== '') {
            $image = $this->image($preview, (string) $record['titulek'], $state, $downloader);
            $complete = $image !== false;
            if (is_array($image) && $newItems['obrazek'] === '') {
                $newItems['obrazek'] = (string) $image['obr_poloha'];
            }
            if ($complete) {
                unset($state['nahledy'][$id]);
            }
        }
        if ($type === 'clanek' && $newItems !== ['uvod' => $record['uvod'], 'text' => $record['text'], 'obrazek' => $record['obrazek']]) {
            $this->db->update('novinky', $newItems, ['idc' => $id]);
            Media::recordUsage($this->db, $id, $newItems['obrazek'], $newItems['uvod'], $newItems['text']);
        } elseif ($type === 'stranka' && $newItems['text'] !== $record['text']) {
            // the images are already in Media: the build of the imported page is converted again so that it refers to them
            $build = $this->db->value('SELECT stavba FROM {stranky} WHERE ids = ?', [$id]) !== null && $this->db->value('SELECT stavba_koncept FROM {stranky} WHERE ids = ?', [$id]) === null
                ? ['stavba' => self::pageBuild($this->db, (string) $record['titulek'], $newItems['text'])] : [];
            $this->db->update('stranky', ['text' => $newItems['text']] + $build, ['ids' => $id]);
        }

        return $complete;
    }

    /**
     * Images of a collection item (2.7): image fields and images in its formatted fields from the old site go to Media, the
     * featured image of the post becomes the item's share image.
     *
     * @param array<string, mixed> $state
     * @return bool false = the batch budget ran out, the item is not complete yet
     */
    private function itemImages(int $id, array &$state, ImageDownloader $downloader): bool
    {
        $item = $this->db->one('SELECT p.idp, p.nazev, p.data, p.obrazek, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.idp = ?', [$id]);
        if ($item === null) {
            return true;
        }
        $data = json_decode((string) $item['data'], true) ?: [];
        $share = (string) $item['obrazek'];
        $complete = true;
        foreach (json_decode((string) $item['pole'], true) ?: [] as $field) {
            $key = (string) $field['klic'];
            $value = (string) ($data[$key] ?? '');
            if ($field['typ'] === 'obrazek' && $value !== '' && $complete && $downloader->isAllowedUrl($value)) {
                $image = $this->image($value, (string) $item['nazev'], $state, $downloader);
                $complete = $image !== false;
                $data[$key] = is_array($image) ? (string) $image['obr_poloha'] : ($image === null ? '' : $value);
            } elseif ($field['typ'] === 'html' && str_contains($value, '<img')) {
                $data[$key] = self::rewriteImages($value, function (string $src, string $alt) use ($downloader, &$state, &$complete, $item): ?array {
                    if (!$complete || !$downloader->isAllowedUrl($src)) {
                        return null;
                    }
                    $image = $this->image($src, $alt !== '' ? $alt : (string) $item['nazev'], $state, $downloader);
                    $complete = $image !== false;

                    return is_array($image) ? self::mediaImage($this->base, $image, $alt) : null;
                });
            }
        }
        $preview = (string) ($state['nahledy']['p' . $id] ?? '');
        if ($complete && $preview !== '') {
            $image = $this->image($preview, (string) $item['nazev'], $state, $downloader);
            $complete = $image !== false;
            if (is_array($image) && $share === '') {
                $share = (string) $image['obr_poloha'];
            }
            if ($complete) {
                unset($state['nahledy']['p' . $id]);
            }
        }
        $this->db->update('kolekce_polozky', ['data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'obrazek' => $share], ['idp' => $id]);

        return $complete;
    }

    /**
     * Points the images of imported HTML at Media on the DOM (Core\Html::rewriteImages – never a regular expression over the
     * markup) and, when anything changed, sanitizes the result once more as the very last step before it is stored.
     * Shared with the structured importers (Import\Batch).
     *
     * @param callable(string, string): (array<string, string|int>|false|null) $image gets src and alt, see Html::rewriteImages
     */
    public static function rewriteImages(string $html, callable $image): string
    {
        try {
            return HtmlLimits::guard(function () use ($html, $image): string {
                $rewritten = Html::rewriteImages($html, $image);

                return $rewritten === $html ? $html : WpContent::safeHtml($rewritten);
            });
        } catch (HtmlTooLarge) {
            return $html; // stored content over a limit of HtmlLimits (an import before 3.8) stays as it is, with its images
        }
    }

    /**
     * The attributes of an imported image that is in Media now.
     *
     * @param array<string, mixed> $image a ka_media row
     * @return array<string, string|int>
     */
    public static function mediaImage(string $base, array $image, string $alt): array
    {
        return ['src' => $base . '/' . $image['obr_poloha'], 'alt' => $alt, 'width' => (int) $image['obr_width'], 'height' => (int) $image['obr_height'],
            'loading' => 'lazy', 'data-id' => (int) $image['ido']];
    }

    /**
     * An imported page as a build (Builder\HtmlConverter): heading and content in a narrow section, Gutenberg blocks as elements,
     * WordPress classes without a style removed. Converted as for a non-administrator: imported content never becomes Custom HTML,
     * whoever runs the import. Shared with the structured importers (Import\Batch), so every imported page looks the same in the builder.
     */
    public static function pageBuild(Db $db, string $title, string $html): ?string
    {
        $conversion = \Kaleta\Builder\HtmlConverter::convert('<h1>' . e($title) . '</h1>' . $html, false);
        $build = \Kaleta\Builder\HtmlConverter::withoutClasses($conversion['stavba'], array_column($db->all('SELECT nazev FROM {tridy}'), 'nazev'));
        foreach ($build['deti'] as &$section) {
            if ($section['typ'] === 'sekce' && !isset($section['kotva'])) {
                $section['obsah']['sirka'] = 'uzka'; // page text reads better in a narrower column
            }
        }
        unset($section);
        [$clean] = \Kaleta\Builder\Build::sanitize($build, false);

        return $clean['deti'] === [] ? null : \Kaleta\Builder\Build::toJson($clean);
    }

    /**
     * One image: from the map (already downloaded), or from the old site through Core\Images into Media.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null|false a ka_media row; null = cannot be downloaded; false = the batch has run out
     */
    private function image(string $url, string $name, array &$state, ImageDownloader $downloader): array|null|false
    {
        $original = self::withoutSize($url);
        $key = sha1($original);
        $ido = $this->db->value("SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = 'obrazek' AND cizi_id = ?", [$this->source, $key]);
        $row = $ido === null ? null : $this->db->one('SELECT * FROM {media} WHERE ido = ?', [(int) $ido]);
        if ($row !== null || ($ido !== null && (int) $ido === 0)) {
            return $row; // done earlier, or it already failed once (null)
        }
        if ($this->downloadsLeft <= 0 || microtime(true) > $this->end) {
            return false;
        }
        $this->downloadsLeft--;
        $temporary = WpFile::folder() . '/obrazek-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            try {
                $data = $downloader->download($original);
            } catch (\RuntimeException $e) {
                if ($original === $url) {
                    throw $e;
                }
                $data = $downloader->download((string) preg_replace('/[?#].*$/', '', $url)); // the original is missing, try at least the downsized copy from the text
            }
            file_put_contents($temporary, $data);
            $saved = Images::saveFile($temporary, basename((string) parse_url($original, PHP_URL_PATH)));
            $saved['nazev'] = mb_substr($name !== '' ? $name : $saved['nazev'], 0, 150);
            $saved['ido'] = $this->db->insert('media', $saved + ['vlastnik' => $this->author, 'datum' => date('Y-m-d H:i:s')]);
            $this->writeMap('obrazek', $key, (int) $saved['ido']);
            $state['obr']['stazeno']++;

            return $saved;
        } catch (\RuntimeException $e) {
            $this->writeMap('obrazek', $key, 0); // do not retry for every article that uses the image
            $state['obr']['chyb']++;
            $state['obr']['chyby'] = array_slice(array_merge($state['obr']['chyby'], [mb_substr($original, 0, 200) . ' – ' . t($e->getMessage()) . ($e->getCode() > 0 ? ' ' . $e->getCode() : '')]), -10);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /* ---------- the flow shared by the admin and MCP import_wordpress (3.6) ---------- */

    /** The largest export downloaded from an address (MCP import_wordpress); a larger one goes over FTP or the admin form. */
    public const int MAX_DOWNLOAD = 50 * 1024 * 1024;

    /**
     * A new import of a file in storage/import: the first step reads the file (the preview).
     *
     * @return array<string, mixed>
     */
    public static function begin(string $file): array
    {
        $state = self::newState($file);
        // the fingerprint of the file that was previewed: every later batch refuses a file replaced in the meantime (3.6, N36-2)
        $path = WpFile::path($file);
        $state['otisk'] = $path !== null ? (string) hash_file('sha256', $path) : '';
        self::saveState($state);

        return $state;
    }

    /**
     * Valid import options from the admin form or from MCP, in the internal keys of DEFAULT_OPTIONS: a language only of a
     * language version the site has, the default category only an existing one, authors only existing users, menu
     * locations only those of Core\Menu. Missing keys keep their defaults.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function options(array $input, Db $db, Settings $settings): array
    {
        $options = self::DEFAULT_OPTIONS;
        foreach (['koncepty', 'stranky', 'stavitel', 'presmerovani', 'kolekce', 'menu', 'skryte', 'obrazky'] as $key) {
            if (array_key_exists($key, $input)) {
                $options[$key] = (bool) $input[$key];
            }
        }
        $language = (string) ($input['jazyk'] ?? '');
        $options['jazyk'] = in_array($language, Language::additional($settings), true) ? $language : '';
        $category = (int) ($input['rubrika'] ?? 0);
        $options['rubrika'] = $category > 0 && $db->value('SELECT idt FROM {kategorie} WHERE idt = ?', [$category]) !== null ? $category : 0;
        $locations = [];
        foreach (is_array($input['menu_umisteni'] ?? null) ? $input['menu_umisteni'] : [] as $slug => $location) {
            if (is_string($location) && ($location === '' || isset(Menu::LOCATIONS[$location])) && count($locations) < 50) {
                $locations[mb_substr((string) $slug, 0, 190)] = $location;
            }
        }
        $authors = [];
        foreach (is_array($input['autori'] ?? null) ? $input['autori'] : [] as $login => $user) {
            $user = is_int($user) || (is_string($user) && ctype_digit($user)) ? (int) $user : 0;
            if ($user > 0 && count($authors) < 200 && $db->value('SELECT idu FROM {uzivatele} WHERE idu = ?', [$user]) !== null) {
                $authors[mb_substr((string) $login, 0, 60)] = $user;
            }
        }
        $options['menu_umisteni'] = $locations;
        $options['autori'] = $authors;

        return $options;
    }

    /**
     * After the preview: the chosen options, and the content import starts from the first item.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options from options()
     */
    public static function run(array &$state, array $options): void
    {
        $fresh = self::newState((string) $state['soubor']);
        $state['volby'] = $options;
        $state['faze'] = 'import';
        $state['pozice'] = 0;
        foreach (['vysledek', 'vynechano', 'presmerovani', 'menu_vysledek', 'autori_vysledek'] as $key) {
            $state[$key] = $fresh[$key];
        }
    }

    /**
     * One request's worth of work under the import lock: reading the file, importing the content or downloading images,
     * by the phase. The admin progress page and MCP import_wordpress both call it; when another window or connection holds
     * the lock, the state comes back unchanged.
     *
     * @param array<string, mixed> $state
     * @return array{0: array<string, mixed>, 1: ?\RuntimeException} the fresh state and the error that stopped the batch
     */
    public static function advance(Db $db, Settings $settings, string $base, int $author, array $state): array
    {
        if (!in_array($state['faze'], ['analyza', 'import', 'obrazky'], true)) {
            return [$state, null];
        }
        $lock = fopen(WpFile::folder() . '/import.zamek', 'c');
        if ($lock === false) {
            return [$state, null];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return [$state, null];
        }
        $error = null;
        try {
            @set_time_limit(60);
            $state = self::loadState((string) $state['soubor']) ?? $state; // fresh state only under the lock
            $path = WpFile::path((string) $state['soubor']);
            if ($state['otisk'] !== '' && ($path === null || !hash_equals((string) $state['otisk'], (string) hash_file('sha256', $path)))) {
                throw new \RuntimeException(t('The export file changed after the preview, so the import stopped. Start it again with the file you want to import.'));
            }
            $import = new self($db, $settings, $base, $author);
            match ($state['faze']) {
                'analyza' => self::analyze($state),
                'import' => $import->import($state),
                'obrazky' => $import->images($state, new ImageDownloader((string) $state['web']['adresa'])),
                default => null,
            };
        } catch (\RuntimeException $e) {
            $error = $e;
        } finally {
            self::saveState($state);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return [$state, $error];
    }

    /**
     * Downloads a WordPress export from an address into storage/import (MCP import_wordpress): only http(s) and only from
     * public addresses, through Core\ImageDownloader with its SSRF rules (verified and pinned IP, every redirect checked
     * again), at most MAX_DOWNLOAD; the file is kept only when it is a WordPress export (WpFile::verifyContent).
     *
     * @return string the file name in storage/import
     * @throws \RuntimeException with an English message
     */
    public static function download(string $url): string
    {
        $domain = ImageDownloader::domainFromUrl($url);
        if (preg_match('#^https?://#i', $url) !== 1 || $domain === '') {
            throw new \RuntimeException('Give the http(s) address of the WordPress export file.');
        }
        $data = (new ImageDownloader($url, true, self::MAX_DOWNLOAD, 60))->download($url, false);
        $name = WpFile::uploadName($domain . '-' . pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
        $temporary = WpFile::folder() . '/stahovani-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            file_put_contents($temporary, $data);
            unset($data);
            (new WpFile($temporary))->verifyContent();
            if (!rename($temporary, WpFile::folder() . '/' . $name)) {
                throw new \RuntimeException('The file could not be saved – check write permissions for storage/import.');
            }
        } finally {
            @unlink($temporary);
        }

        return $name;
    }

    /** Reasons a record is left out (leftOut()), in English for the summary. */
    private const array LEFT_OUT = [
        'status' => 'private posts, trash, revisions and auto-drafts are never imported',
        'drafts_off' => 'drafts and posts pending review – the drafts option is off',
        'pages_off' => 'pages – the pages option is off',
        'collections_off' => 'items of custom post types – the collections option is off',
        'too_large' => 'posts whose HTML is over a safety limit (size, nesting or number of elements) – nothing of them was imported; too_large lists them',
    ];

    /** Why a redirect was not made or a menu was not put into the draft look, in English for the summary. */
    private const array REFUSED = [
        'address_in_use' => 'the old address is a page or item of this site already – it keeps working and no link is rewritten',
        'redirect_exists' => 'the old address already redirects elsewhere – that redirect was kept',
        'no_location' => 'this site has a main and a footer menu, and the other WordPress menus took them; choose with menu_locations',
        'empty' => 'none of its links lead to anything that was imported',
        'draft_taken' => 'the draft look already holds a different menu for this location – nothing was overwritten; the items are here for save_menu',
        'already_imported' => 'imported by an earlier run of this file; the items are here for save_menu if you want them again',
    ];

    /**
     * What an import found and did, in English (MCP import_wordpress). The admin shows the same state in its own views.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public static function summary(array $state): array
    {
        $p = $state['prehled'];
        $v = $state['vysledek'];
        $skipped = [];
        foreach ($state['vynechano'] as $reason => $count) {
            $skipped[] = ['what' => $reason, 'count' => (int) $count, 'why' => self::LEFT_OUT[$reason] ?? $reason];
        }
        if ((int) $v['preskoceno'] > 0) {
            $skipped[] = ['what' => 'already_imported', 'count' => (int) $v['preskoceno'], 'why' => 'converted by an earlier run of this file and left as they are, so later edits stay'];
        }
        foreach ($p['jine'] as $type => $count) {
            $skipped[] = ['what' => 'type:' . $type, 'count' => (int) $count, 'why' => 'a content type of WordPress or of a plugin without a counterpart here'];
        }
        foreach ($p['stavitele'] ?? [] as $builder => $count) {
            $skipped[] = ['what' => 'layout:' . $builder, 'count' => (int) $count, 'why' => 'the ' . $builder . ' layout is not in the export – only the text in the post content came over; rebuild these pages from the live site (build_from_html)'];
        }
        foreach ($p['zkratky'] as $code => $count) {
            $skipped[] = ['what' => 'shortcode:' . $code, 'count' => (int) $count, 'why' => 'a plugin shortcode – the tag is removed, the text inside stays'];
        }
        if ((int) ($p['menu_bez'] ?? 0) > 0) {
            $skipped[] = ['what' => 'menu_items', 'count' => (int) $p['menu_bez'], 'why' => 'menu items outside a menu, not published, or over ' . self::MAX_MENU_ITEMS];
        }
        $skipped[] = ['what' => 'comments', 'count' => null, 'why' => 'comments are not imported – a company site has none'];
        $skipped[] = ['what' => 'author_names', 'count' => (int) $p['autori'], 'why' => 'news items have no free-text author here, so the WordPress author name is not kept: each item belongs to a user of this site (see authors); no sign-in account is created for a WordPress author'];
        if ((int) $p['prilohy'] > 0) {
            $skipped[] = ['what' => 'media_library', 'count' => (int) $p['prilohy'], 'why' => 'the media library is not copied as such: the images used in texts and the featured images are downloaded in the images phase'];
        }
        $redirect = fn (array $r): array => ['from' => (string) $r[0], 'to' => (string) $r[1]] + (isset($r[2]) ? ['why' => self::REFUSED[$r[2]] ?? (string) $r[2]] : []);
        $locations = ['hlavni' => 'main', 'paticka' => 'footer'];

        return [
            'found' => [
                'posts' => $p['clanky'], 'pages' => $p['stranky'], 'categories' => (int) $p['rubriky'], 'tags' => (int) $p['stitky'], 'authors' => (int) $p['autori'],
                'media_files' => (int) $p['prilohy'], 'images_in_texts' => (int) $p['obrazky'],
                'menus' => array_map(fn (string $slug, array $m): array => ['slug' => $slug, 'name' => (string) $m['nazev'], 'items' => (int) $m['polozky']], array_keys($p['menu']), array_values($p['menu'])),
                'custom_post_types' => array_map(fn (array $t): int => (int) $t['pocet'], $p['typy']),
                'seo_plugins' => array_keys($p['seo']),
            ],
            'result' => [
                'news' => (int) $v['clanky'], 'pages' => (int) $v['stranky'], 'categories' => (int) $v['rubriky'], 'collection_items' => (int) $v['polozky'],
                'collections' => (int) ($v['kolekce'] ?? 0), 'seo_fields' => (int) $v['seo'], 'redirects' => (int) $v['presmerovani'],
                'already_imported' => (int) $v['preskoceno'], 'hidden_but_public_on_wordpress' => (int) $v['skryto'],
            ],
            'skipped' => $skipped,
            'redirects' => [
                'created' => array_map($redirect, array_slice($state['presmerovani']['nove'], 0, 50)),
                'more_created' => max(0, (int) $v['presmerovani'] - 50),
                'not_created' => array_map($redirect, array_slice($state['presmerovani']['odmitnute'], 0, 50)),
            ],
            'menus' => array_map(fn (array $m): array => [
                'name' => $m['nazev'], 'slug' => $m['slug'], 'location' => $locations[$m['umisteni']] ?? null, 'language' => $m['jazyk'], 'items' => $m['polozky'],
                'status' => $m['stav'] === 'koncept' ? 'in_draft_look' : 'skipped', 'why' => $m['duvod'] !== '' ? (self::REFUSED[$m['duvod']] ?? $m['duvod']) : null,
                'replaces' => $m['nahrazuje'] ?? null, 'warnings' => $m['upozorneni'],
            ] + (isset($m['deti']) ? ['items_for_save_menu' => $m['deti']] : []), $state['menu_vysledek']),
            'authors' => array_map(fn (string $login, array $a): array => ['login' => $login, 'name' => $a['jmeno'], 'posts' => $a['prispevky'],
                'news_belong_to' => ['user_id' => $a['uzivatel'], 'name' => $a['uzivatel_jmeno']],
                'why' => ['volba' => 'chosen in the options', 'email' => 'a user here has the same e-mail', 'import' => 'no user here has the author\'s e-mail – the user who runs the import'][$a['jak']] ?? $a['jak']],
                array_map('strval', array_keys($state['autori_vysledek'])), array_values($state['autori_vysledek'])),
            'images' => ['downloaded' => (int) $state['obr']['stazeno'], 'failed' => (int) $state['obr']['chyb'], 'recent_failures' => $state['obr']['chyby']],
        ] + (($state['prilis_velke'] ?? []) !== [] ? ['too_large' => self::tooLarge($state, HtmlLimits::english(...))] : []);
    }

    /**
     * The posts left out because their HTML is over a limit of HtmlLimits (3.8), as "title – reason".
     *
     * @param array<string, mixed> $state
     * @param callable(array{limit: string, value: int, max: int}): string $describe HtmlLimits::english or HtmlLimits::message
     * @return list<string>
     */
    public static function tooLarge(array $state, callable $describe): array
    {
        $rows = [];
        foreach ((array) ($state['prilis_velke'] ?? []) as $row) {
            if (is_array($row) && is_array($row['limit'] ?? null)) {
                $limit = $row['limit'];
                $rows[] = (string) ($row['titulek'] ?? '') . ' – ' . $describe(['limit' => (string) ($limit['limit'] ?? ''), 'value' => (int) ($limit['value'] ?? 0), 'max' => (int) ($limit['max'] ?? 0)]);
            }
        }

        return $rows;
    }

    /* ---------- navigation menus (3.6) ---------- */

    /**
     * The menus of the file into the draft look (Core\Look), so the live navigation changes only when the look is published.
     * Each WordPress menu goes to a menu location here (menuLocations); links to imported pages, posts, categories and tags
     * point to their new addresses, custom links stay (a link to the old site's own domain becomes a path here, through the
     * redirect when there is one), and the hierarchy is kept as deep as Core\Menu allows. A location whose draft already
     * holds a different menu is left alone, and so is a menu imported by an earlier run – their items come back for save_menu.
     *
     * @param array<string, mixed> $state
     * @return list<array<string, mixed>> per WordPress menu: slug, nazev, umisteni, jazyk, polozky, stav (koncept | preskoceno), duvod, nahrazuje, upozorneni, deti
     */
    private function menus(array $state): array
    {
        $byMenu = [];
        foreach ($state['menu'] as $item) {
            $byMenu[(string) $item['menu']][] = $item;
        }
        $names = [];
        foreach (array_keys($byMenu) as $slug) {
            $names[(string) $slug] = (string) (($this->header['menu'][$slug] ?? '') ?: ($state['prehled']['menu'][$slug]['nazev'] ?? $slug));
        }
        $locations = self::menuLocations($names, array_map('count', $byMenu), (array) ($state['volby']['menu_umisteni'] ?? []));
        $language = Language::column($this->settings, (string) $state['volby']['jazyk']);
        $draft = Look::draft($this->settings)['menus'] ?? [];
        $result = [];
        foreach ($byMenu as $slug => $items) {
            $slug = (string) $slug;
            $warnings = [];
            $tree = Menu::sanitize($this->menuTree($items, $state, $warnings));
            $location = $locations[$slug];
            $key = $location . '|' . $language;
            $earlier = $this->db->value("SELECT 1 FROM {import_mapa} WHERE zdroj = ? AND typ = 'menu' AND cizi_id = ?", [$this->source, mb_substr($slug, 0, 190)]) !== null;
            $reason = match (true) {
                $location === '' => 'no_location',
                $tree === [] => 'empty',
                $earlier => 'already_imported',
                array_key_exists($key, $draft) && $draft[$key] !== $tree => 'draft_taken',
                default => '',
            };
            $live = $location === '' ? null : $this->db->value('SELECT polozky FROM {menu} WHERE umisteni = ? AND jazyk = ?', [$location, $language]);
            if ($reason === '') {
                Look::setMenu($this->settings, $location, $language, $tree);
                $this->writeMap('menu', $slug, 1);
            }
            $result[] = ['slug' => $slug, 'nazev' => $names[$slug], 'umisteni' => $location, 'jazyk' => $language, 'polozky' => count(Menu::flatten($tree)),
                'stav' => $reason === '' ? 'koncept' : 'preskoceno', 'duvod' => $reason,
                // publishing the look replaces what visitors see now: a saved menu, or the automatic one built from the pages
                'nahrazuje' => $location === '' ? null : ($live === null ? 'the automatic menu' : sprintf('a saved menu with %d items', count(Menu::flatten(Menu::sanitize(json_decode((string) $live, true)))))),
                'upozorneni' => $warnings] + ($reason !== '' && $tree !== [] ? ['deti' => $tree] : []);
        }

        return $result;
    }

    /**
     * Which location each WordPress menu goes to: the options first (hlavni | paticka | '' = leave out), then by its name or
     * slug (footer, patička, Fußzeile… / main, primary, header…), and the largest menu still without a place becomes the
     * main menu. Each location takes one menu; the rest stay without a place. WordPress keeps its theme locations in the
     * theme settings, which an export does not contain – hence the names.
     *
     * @param array<string, string> $names slug => name
     * @param array<string, int> $counts slug => number of items
     * @param array<array-key, mixed> $chosen slug => location from the options
     * @return array<string, string> slug => hlavni | paticka | ''
     */
    public static function menuLocations(array $names, array $counts, array $chosen): array
    {
        $result = array_fill_keys(array_map('strval', array_keys($names)), '');
        $taken = [];
        $decided = [];
        foreach (array_keys($result) as $slug) {
            $location = $chosen[$slug] ?? null;
            if (is_string($location) && ($location === '' || isset(Menu::LOCATIONS[$location]))) {
                $decided[$slug] = true;
                if ($location !== '' && !isset($taken[$location])) {
                    $result[$slug] = $location;
                    $taken[$location] = true;
                }
            }
        }
        foreach ($names as $slug => $name) {
            $slug = (string) $slug;
            $text = mb_strtolower($slug . ' ' . $name);
            $location = match (true) {
                isset($decided[$slug]) => '',
                preg_match('/foot|pati[cč]k|zápatí|zapati|fu(ss|ß)|bottom/u', $text) === 1 => 'paticka',
                preg_match('/main|primary|hlavn|header|haupt|navig/u', $text) === 1 => 'hlavni',
                default => '',
            };
            if ($location !== '') {
                $decided[$slug] = true; // a footer menu never becomes the main menu, even when the footer is taken
                if (!isset($taken[$location])) {
                    $result[$slug] = $location;
                    $taken[$location] = true;
                }
            }
        }
        if (!isset($taken['hlavni'])) {
            $rest = array_diff_key($counts, $decided);
            arsort($rest);
            $first = array_key_first($rest);
            if ($first !== null) {
                $result[(string) $first] = 'hlavni';
            }
        }

        return $result;
    }

    /**
     * The items of one WordPress menu as Core\Menu items, in their order and hierarchy. An item that cannot be carried over
     * hands its sub-items to its parent; levels deeper than Core\Menu allows move up.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $state
     * @param list<string> $warnings what could not be carried over
     * @return list<array<string, mixed>>
     */
    private function menuTree(array $items, array $state, array &$warnings): array
    {
        usort($items, fn (array $a, array $b): int => [(int) $a['poradi'], (int) $a['id']] <=> [(int) $b['poradi'], (int) $b['id']]);
        $ids = array_flip(array_map(fn (array $i): int => (int) $i['id'], $items));
        $children = [];
        foreach ($items as $item) {
            $parent = (int) $item['nadrazena'];
            $children[isset($ids[$parent]) && $parent !== (int) $item['id'] ? $parent : 0][] = $item;
        }
        $convert = function (int $parent, int $depth) use (&$convert, $children, $state, &$warnings): array {
            $out = [];
            foreach ($depth > 10 ? [] : $children[$parent] ?? [] as $item) {
                $converted = $this->menuItem($item, $state, $warnings);
                $sub = $convert((int) $item['id'], $depth + 1);
                if ($converted === null) {
                    array_push($out, ...$sub);
                    continue;
                }
                $out[] = $converted + ($sub !== [] ? ['deti' => $sub] : []);
            }

            return $out;
        };

        return self::fitMenuDepth($convert(0, 0), $warnings);
    }

    /**
     * Core\Menu has one level of submenu, and below it only a group (a column of a mega menu) has items of its own. Deeper
     * WordPress levels move up: the sub-items of a link in a submenu follow it in the same submenu.
     *
     * @param list<array<string, mixed>> $tree
     * @param list<string> $warnings
     * @return list<array<string, mixed>>
     */
    public static function fitMenuDepth(array $tree, array &$warnings): array
    {
        $flatten = function (array $items) use (&$flatten): array {
            $out = [];
            foreach ($items as $item) {
                $sub = is_array($item['deti'] ?? null) ? $item['deti'] : [];
                unset($item['deti']);
                $out[] = $item;
                array_push($out, ...$flatten($sub));
            }

            return $out;
        };
        $moved = false;
        foreach ($tree as &$top) {
            $level = [];
            foreach (is_array($top['deti'] ?? null) ? $top['deti'] : [] as $child) {
                $sub = is_array($child['deti'] ?? null) ? $child['deti'] : [];
                unset($child['deti']);
                if ($sub !== [] && $child['typ'] === 'skupina') {
                    $flat = $flatten($sub);
                    $moved = $moved || count($flat) !== count($sub);
                    $level[] = $child + ['deti' => $flat];
                    continue;
                }
                $level[] = $child;
                if ($sub !== []) {
                    array_push($level, ...$flatten($sub));
                    $moved = true;
                }
            }
            unset($top['deti']);
            if ($level !== []) {
                $top['deti'] = $level;
            }
        }
        unset($top);
        if ($moved) {
            $warnings[] = 'Menu levels deeper than this site shows were moved up into the submenu above them.';
        }

        return $tree;
    }

    /**
     * One WordPress menu item as a Core\Menu item; null = it cannot be carried over (the reason goes to $warnings).
     *
     * @param array<string, mixed> $item from tallyMenuItem()
     * @param array<string, mixed> $state
     * @param list<string> $warnings
     * @return array<string, mixed>|null
     */
    private function menuItem(array $item, array $state, array &$warnings): ?array
    {
        $text = trim((string) $item['text']);
        $object = (string) $item['objekt'];
        $id = (string) $item['objekt_id'];
        $link = fn (string $url, string $fallback): array => ['typ' => 'odkaz', 'url' => $url, 'text' => $text !== '' ? $text : $fallback, 'nove_okno' => (bool) $item['nove_okno']];
        $found = match ((string) $item['druh']) {
            'post_type' => match (true) {
                $object === 'page' => ($ids = $this->convertedId('stranka', $id, 'stranky', 'ids')) !== null ? ['typ' => 'stranka', 'ids' => $ids, 'text' => $text] : null,
                $object === 'post' => ($row = $this->db->one('SELECT seo_link, titulek FROM {novinky} WHERE idc = ?', [(int) $this->convertedId('clanek', $id, 'novinky', 'idc')])) !== null
                    ? $link('/novinky/' . $row['seo_link'], (string) $row['titulek']) : null,
                default => ($row = $this->db->one('SELECT p.seo_link, p.nazev, k.seo_link AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.idp = ?',
                    [(int) $this->convertedId('polozka', $id, 'kolekce_polozky', 'idp')])) !== null ? $link('/' . $row['kolekce'] . '/' . $row['seo_link'], (string) $row['nazev']) : null,
            },
            'taxonomy' => $this->menuTerm($this->header['terminy'][(int) $id] ?? ['', ''], $link),
            'post_type_archive' => $object === 'post' ? ['typ' => 'novinky', 'text' => $text]
                : (($row = $this->db->one('SELECT seo_link, nazev FROM {kolekce} WHERE idk = ?', [(int) $this->convertedId('kolekce', $object, 'kolekce', 'idk')])) !== null
                    ? $link('/' . $row['seo_link'], (string) $row['nazev']) : null),
            default => $this->menuCustom($item, $text, $state),
        };
        if ($found === null) {
            $warnings[] = sprintf('“%s” was left out: what it links to was not imported.', $text !== '' ? $text : $object . ' ' . $id);
        } elseif (($found['typ'] === 'odkaz' && ($found['text'] === '' || !Menu::isValidUrl((string) $found['url']))) || ($found['typ'] === 'skupina' && $found['text'] === '')) {
            $warnings[] = sprintf('“%s” was left out: the link %s cannot be used in a menu.', $text, (string) ($found['url'] ?? ''));
            $found = null;
        }

        return $found;
    }

    /**
     * A menu item linking to a category or a tag.
     *
     * @param array{0: string, 1: string} $term [rubrika | stitek, WordPress slug]
     * @param callable(string, string): array<string, mixed> $link
     * @return array<string, mixed>|null
     */
    private function menuTerm(array $term, callable $link): ?array
    {
        [$kind, $slug] = $term;
        $row = match ($kind) {
            'rubrika' => $this->db->one('SELECT seo_link, nazev FROM {kategorie} WHERE idt = ?', [(int) $this->convertedId('rubrika', $slug, 'kategorie', 'idt')]),
            'stitek' => $this->db->one('SELECT seo_link, nazev FROM {stitky} WHERE ids = ?', [(int) $this->convertedId('stitek', $slug, 'stitky', 'ids')]),
            default => null,
        };

        return $row === null ? null : $link('/novinky/' . ($kind === 'rubrika' ? 'kategorie/' : 'stitek/') . $row['seo_link'], (string) $row['nazev']);
    }

    /**
     * A custom link: a link without a target (#) becomes a group; the old site's own address becomes a path here – the new
     * address when the import redirected it – and any other link stays as it is.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function menuCustom(array $item, string $text, array $state): array
    {
        $url = (string) $item['url'];
        if ($url === '' || $url === '#') {
            return ['typ' => 'skupina', 'text' => $text];
        }
        $domain = ImageDownloader::domainFromUrl((string) $state['web']['adresa']);
        $own = (str_starts_with($url, '/') && !str_starts_with($url, '//'))
            || (preg_match('#^https?://#i', $url) === 1 && $domain !== '' && ImageDownloader::domainFromUrl($url) === $domain);
        if ($own) {
            $parts = parse_url($url);
            $path = trim(rawurldecode((string) ($parts['path'] ?? '')), '/');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $target = $path . $query === '' ? null : $this->db->value('SELECT na_adresu FROM {presmerovani} WHERE z_adresy = ?', [$path . $query]);
            $url = '/' . ($target !== null ? trim((string) $target, '/') : $path . $query) . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        }

        return ['typ' => 'odkaz', 'url' => $url, 'text' => $text, 'nove_okno' => (bool) $item['nove_okno']];
    }

    /* ---------- authors (3.6) ---------- */

    /**
     * WordPress author login => the user here the news items belong to: the user the options name, else the user with the
     * author's e-mail – an existing account, none is ever created – else whoever runs the import. Kept in the state for the summary.
     *
     * @param array<string, mixed> $state
     * @return array<string, int>
     */
    private function authorMap(array &$state): array
    {
        $users = [];
        $byEmail = [];
        foreach ($this->db->all('SELECT idu, user, jmeno, email FROM {uzivatele} WHERE blokovat = 0') as $u) {
            $users[(int) $u['idu']] = (string) ($u['jmeno'] !== '' ? $u['jmeno'] : $u['user']);
            $email = mb_strtolower(trim((string) $u['email']));
            if ($email !== '' && !isset($byEmail[$email])) {
                $byEmail[$email] = (int) $u['idu'];
            }
        }
        $map = [];
        $result = [];
        foreach ($this->header['autori'] as $login => $name) {
            $login = (string) $login;
            $chosen = (int) ($state['volby']['autori'][$login] ?? 0);
            $email = (string) ($this->header['emaily'][$login] ?? '');
            [$user, $how] = match (true) {
                $chosen > 0 && isset($users[$chosen]) => [$chosen, 'volba'],
                $email !== '' && isset($byEmail[$email]) => [$byEmail[$email], 'email'],
                default => [$this->author, 'import'],
            };
            $map[$login] = $user;
            $result[$login] = ['jmeno' => $name, 'uzivatel' => $user, 'uzivatel_jmeno' => $users[$user] ?? '', 'jak' => $how,
                'prispevky' => (int) ($state['prehled']['prispevky_autoru'][$login] ?? 0)];
        }
        $state['autori_vysledek'] = $result;

        return $map;
    }

    /* ---------- map of foreign and our records ---------- */

    /** The id of our record the foreign one was already converted into – only if it still exists (a deleted one is imported again). */
    private function convertedId(string $type, string $foreignId, string $table, string $key): ?int
    {
        $ourId = $this->db->value('SELECT nase_id FROM {import_mapa} WHERE zdroj = ? AND typ = ? AND cizi_id = ?', [$this->source, $type, mb_substr($foreignId, 0, 190)]);
        if ($ourId === null || $this->db->value('SELECT ' . $key . ' FROM {' . $table . '} WHERE ' . $key . ' = ?', [(int) $ourId]) === null) {
            return null;
        }

        return (int) $ourId;
    }

    private function writeMap(string $type, string $foreignId, int $ourId): void
    {
        $this->db->run(
            'INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE nase_id = VALUES(nase_id)',
            [$this->source, $type, mb_substr($foreignId, 0, 190), $ourId],
        );
    }
}
