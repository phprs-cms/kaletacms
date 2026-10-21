<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Icons;

/**
 * Site menu ("Vzhled → Menu", Appearance → Menu): main navigation and footer menu, separately for each language version.
 * Items: page (with custom text or the page title), custom link, news, group (text only) – each with an optional icon
 * (Builder\Icons) and a short description (shown in a mega menu) – and under each of them one level of submenu. A group inside
 * a submenu may have its own items: in a mega menu (Navigation element) it is a column with the group text as its heading.
 * Until someone saves the main menu, it builds itself from pages "in menu" (v_menu).
 */
final class Menu
{
    /** @var array<string, string> location => label */
    public const array LOCATIONS = ['hlavni' => 'Main menu', 'paticka' => 'Footer menu'];

    public const array TYPES = ['stranka', 'odkaz', 'novinky', 'skupina'];

    public const int MAX_ITEMS = 80;

    /** Characters of an item's description (under the label in a mega menu). */
    public const int MAX_DESCRIPTION = 120;

    /** Submenus rendered in this request – their ids (ka-podmenu-N) stay unique when a page shows two menus. */
    private static int $submenus = 0;

    /** @return list<array<string, mixed>>|null saved items, null = the menu is built automatically */
    public static function load(Db $db, string $location, string $language): ?array
    {
        [$inDraft, $items] = Look::activeMenu($location, $language); // a preview of the draft look shows the draft menu
        if ($inDraft) {
            return $items === null ? null : self::sanitize($items);
        }
        $json = $db->value('SELECT polozky FROM {menu} WHERE umisteni = ? AND jazyk = ?', [$location, $language]);

        return $json === null ? null : self::sanitize(json_decode((string) $json, true));
    }

    /** Saves the items; null returns the menu to automatic mode. */
    public static function save(Db $db, string $location, string $language, ?array $items): void
    {
        if ($items === null) {
            $db->run('DELETE FROM {menu} WHERE umisteni = ? AND jazyk = ?', [$location, $language]);

            return;
        }
        $db->run('INSERT INTO {menu} (umisteni, jazyk, polozky, zmeneno) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE polozky = VALUES(polozky), zmeneno = VALUES(zmeneno)',
            [$location, $language, (string) json_encode(self::sanitize($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), Clock::now()]);
    }

    /**
     * The single item validator (admin and MCP): known types, valid URLs, icons from the set, one level of submenu – plus the
     * items of a group inside a submenu (a column of a mega menu).
     *
     * @return list<array<string, mixed>>
     */
    public static function sanitize(mixed $input, int $depth = 0, int &$count = 0): array
    {
        $result = [];
        foreach (is_array($input) ? $input : [] as $p) {
            if (!is_array($p) || $count >= self::MAX_ITEMS || !in_array($p['typ'] ?? null, self::TYPES, true)) {
                continue;
            }
            $item = ['typ' => $p['typ'], 'text' => mb_substr(trim((string) ($p['text'] ?? '')), 0, 80)];
            if (is_string($p['ikona'] ?? null) && $p['ikona'] !== '' && (isset(Icons::SET[$p['ikona']]) || self::isMediaIcon($p['ikona']))) {
                $item['ikona'] = $p['ikona'];
            }
            $description = is_scalar($p['popis'] ?? null) ? mb_substr(trim(strip_tags((string) $p['popis'])), 0, self::MAX_DESCRIPTION) : '';
            if ($description !== '') {
                $item['popis'] = $description;
            }
            if ($p['typ'] === 'stranka') {
                $item['ids'] = (int) ($p['ids'] ?? 0);
                if ($item['ids'] <= 0) {
                    continue;
                }
            } elseif ($p['typ'] === 'odkaz') {
                $item['url'] = trim((string) ($p['url'] ?? ''));
                // "//host" looks like a path but leaves the site: it is stored as the explicit https address (3.6, N36-4)
                if (str_starts_with($item['url'], '//')) {
                    $item['url'] = 'https:' . $item['url'];
                }
                $item['nove_okno'] = !empty($p['nove_okno']);
                if ($item['text'] === '' || !self::isValidUrl($item['url'])) {
                    continue;
                }
            } elseif ($p['typ'] === 'skupina' && $item['text'] === '') {
                continue;
            }
            $count++;
            // one level of submenu; inside it only a group may have items of its own (a column in a mega menu), nothing deeper
            if ($depth === 0 || ($depth === 1 && $p['typ'] === 'skupina')) {
                $children = self::sanitize($p['deti'] ?? [], $depth + 1, $count);
                if ($children !== []) {
                    $item['deti'] = $children;
                }
            }
            $result[] = $item;
        }

        return $result;
    }

    /** URL of a custom link: https, a path on the site (/…), an anchor, an e-mail or a phone number. */
    public static function isValidUrl(string $url): bool
    {
        // a path never starts with "//" and never holds a backslash ("/\\host" is another site in browsers) – 3.6, N36-4
        return (bool) preg_match('#^(https?://[^\s<>"\\\\]{1,500}|/(?![/\\\\])[^\s<>"\\\\]{0,500}|\#[A-Za-z0-9_-]{1,80}|mailto:[^\s<>"]{3,200}|tel:[+\d ()-]{3,40})$#D', $url);
    }

    /**
     * Items to render; hidden and deleted pages (including their submenu) drop out, and so does a group without a submenu.
     *
     * @return list<array{text: string, url: string, nove_okno: bool, deti: list<array<string, mixed>>, novinky?: bool, auto?: bool, ikona?: string, popis?: string}>  auto = link to news added by the automatic menu
     */
    public static function items(App $app, string $location, string $language, int $home): array
    {
        $db = $app->db();
        $pages = [];
        foreach ($db->all('SELECT ids, titulek, seo_link, v_menu FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND jazyk = ? ORDER BY poradi, ' . $db->sortText('titulek', $language === '' ? null : $language), [$language]) as $s) {
            $pages[(int) $s['ids']] = $s;
        }
        $url = fn (array $s): string => $app->url((int) $s['ids'] === $home ? '' : $s['seo_link']);
        $saved = self::load($db, $location, $language);
        if ($saved === null) {
            if ($location !== 'hlavni') {
                return [];
            }
            // automatic: pages "in menu" by their order and news at the end (the Navigation element can turn it off)
            $auto = array_map(fn (array $s): array => ['text' => $s['titulek'], 'url' => $url($s), 'nove_okno' => false, 'deti' => []],
                array_values(array_filter($pages, fn (array $s): bool => (bool) $s['v_menu'])));
            if (Extensions::isEnabled($app->settings(), 'novinky')) {
                $auto[] = ['text' => $app->settings()->newsTitle(), 'url' => $app->url('novinky'), 'nove_okno' => false, 'deti' => [], 'novinky' => true, 'auto' => true];
            }

            return $auto;
        }
        $withNews = Extensions::isEnabled($app->settings(), 'novinky');
        $convert = function (array $p) use (&$convert, $pages, $url, $app, $withNews): ?array {
            $children = array_values(array_filter(array_map($convert, $p['deti'] ?? [])));
            $extra = array_intersect_key($p, ['ikona' => 1, 'popis' => 1]); // icon and description go along unchanged
            $item = match ($p['typ']) {
                'stranka' => isset($pages[$p['ids']])
                    ? ['text' => $p['text'] !== '' ? $p['text'] : $pages[$p['ids']]['titulek'], 'url' => $url($pages[$p['ids']]), 'nove_okno' => false, 'deti' => $children]
                    : null,
                'novinky' => !$withNews ? null : ['text' => $p['text'] !== '' ? $p['text'] : $app->settings()->newsTitle(), 'url' => $app->url('novinky'), 'nove_okno' => false, 'deti' => $children, 'novinky' => true],
                'odkaz' => ['text' => $p['text'], 'url' => str_starts_with($p['url'], '/') ? $app->url($p['url']) : $p['url'], 'nove_okno' => $p['nove_okno'], 'deti' => $children],
                'skupina' => $children === [] ? null : ['text' => $p['text'], 'url' => '', 'nove_okno' => false, 'deti' => $children],
                default => null,
            };

            return $item === null ? null : $item + $extra;
        };

        return array_values(array_filter(array_map($convert, $saved)));
    }

    /**
     * May the icon be this SVG from Media (3.10)? Only a path under media/ that exists on disk – the file is cleaned at upload
     * (Svg::sanitize) and is shown with <img>, so it never runs anything. The built-in set (Builder\Icons) stays inline.
     */
    public static function isMediaIcon(string $icon): bool
    {
        return preg_match('#^media/[A-Za-z0-9_./-]+\.svg$#D', $icon) === 1 && !str_contains($icon, '..') && Files::isFile(KALETA_ROOT . '/' . $icon);
    }

    /**
     * List of <li> (without the wrapping <ul>) for the template and the Navigation element. An item with a submenu has the
     * class "podmenu" and a nested <ul>; the active link gets aria-current, its parent item the class "aktivni". An icon is an
     * inline SVG (class menu-ikona) before the label, an SVG from Media an <img> with the same class. A group inside a submenu with items of its own is a column (class
     * menu-sloupec): a heading (menu-nadpis) and its list.
     *
     * @param list<array<string, mixed>> $items from items()
     * @param string $path  path of the displayed page (e.g. /web/en/sluzby)
     * @param string $root  URL of the home page ($app->url('')) – the home page is active only on an exact match
     * @param bool   $mega  the submenu is a mega menu panel: submenu items show their description (menu-popis) under the label
     */
    public static function html(array $items, string $path, string $root, bool $mega = false): string
    {
        $active = function (string $url) use ($path, $root): bool {
            // a link with a fragment (/#produkty, /sluzby#cenik) points into a page, it is never the current page itself
            if ($url === '' || preg_match('#^[a-z]+:|\##i', $url)) {
                return false;
            }
            $target = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

            return $target === rtrim($path, '/') || ($target !== rtrim($root, '/') && $target !== '' && str_starts_with($path, $target . '/'));
        };
        $li = function (array $p, int $depth) use (&$li, $active, $mega, $root): string {
            $icon = match (true) {
                ($p['ikona'] ?? '') === '' => '',
                // an SVG from Media (3.10): the file as an image, the inline set stays for the built-in icons
                str_starts_with((string) $p['ikona'], 'media/') => '<img class="menu-ikona" src="' . e(rtrim($root, '/') . '/' . $p['ikona']) . '" alt="" width="20" height="20">',
                default => Icons::svg((string) $p['ikona'], 'menu-ikona'),
            };
            if ($p['deti'] !== [] && $depth === 1 && $p['url'] === '') {
                // a group inside a submenu: a heading with its items under it – a column of the mega menu, a labelled part of a plain submenu
                return '<li class="menu-sloupec"><span class="menu-nadpis">' . $icon . e($p['text']) . '</span><ul>' . implode('', array_map(fn (array $d): string => $li($d, 2), $p['deti'])) . '</ul></li>';
            }
            $label = $icon . e($p['text']) . ($mega && $depth > 0 && ($p['popis'] ?? '') !== '' ? '<small class="menu-popis">' . e((string) $p['popis']) . '</small>' : '');
            $isEnabled = $active($p['url']);
            $link = '<a href="' . e($p['url']) . '"' . ($isEnabled ? ' aria-current="page"' : '') . ($p['nove_okno'] ? ' target="_blank" rel="noopener"' : '') . '>' . $label . '</a>';
            if ($p['deti'] === []) {
                return '<li>' . ($p['url'] === '' ? '<button type="button" class="menu-skupina">' . $label . '</button>' : $link) . '</li>';
            }
            $inner = implode('', array_map(fn (array $d): string => $li($d, $depth + 1), $p['deti']));
            $branch = str_contains($inner, 'aria-current');
            // 3.6: the submenu is a disclosure – a real button controls it (image/web.js adds aria-expanded and opens it by click,
            // Enter or Space, on touch screens too); a group is that button, a linked item keeps its link and gets a toggle
            // next to it. Without the script the CSS still opens the submenu on hover and on keyboard focus.
            $id = 'ka-podmenu-' . ++self::$submenus;
            $toggle = $p['url'] === ''
                ? '<button type="button" class="menu-skupina" aria-controls="' . $id . '">' . $label . '</button>'
                : $link . '<button type="button" class="menu-rozbalit" aria-controls="' . $id . '" aria-label="' . e(t('Submenu: %s', $p['text'])) . '"></button>';

            return '<li class="podmenu' . ($branch ? ' aktivni' : '') . '">' . $toggle . '<ul id="' . $id . '">' . $inner . '</ul></li>';
        };

        return implode('', array_map(fn (array $p): string => $li($p, 0), $items));
    }

    /**
     * Adding a page to the menu from its form (checkbox "v navigaci", in navigation): in automatic mode the v_menu column is
     * enough, in a saved menu the page is added at the end or removed (its submenu moves one level up).
     */
    public static function setPage(Db $db, int $ids, string $language, bool $inMenu): void
    {
        $items = self::load($db, 'hlavni', $language);
        if ($items === null) {
            return;
        }
        $isEnabled = false;
        // the page at any level (a submenu, a column of a group); the items under a removed page move one level up
        $strip = function (array $items) use (&$strip, &$isEnabled, $ids, $inMenu): array {
            $out = [];
            foreach ($items as $p) {
                if (isset($p['deti'])) {
                    $p['deti'] = $strip($p['deti']);
                }
                if ($p['typ'] === 'stranka' && $p['ids'] === $ids) {
                    $isEnabled = true;
                    if (!$inMenu) {
                        array_push($out, ...($p['deti'] ?? []));
                        continue;
                    }
                }
                $out[] = $p;
            }

            return $out;
        };
        $without = $strip($items);
        if ($inMenu && !$isEnabled) {
            $without[] = ['typ' => 'stranka', 'ids' => $ids, 'text' => ''];
        }
        if ($inMenu !== $isEnabled || !$inMenu) {
            self::save($db, 'hlavni', $language, $without);
        }
    }

    /**
     * Is the page in the saved main menu – as a page, or as a link to its URL (/sluzby, /de/leistungen)?
     * null = the menu is automatic (the v_menu column applies).
     */
    public static function hasPage(Db $db, int $ids, string $language, ?string $seo = null): ?bool
    {
        $items = self::load($db, 'hlavni', $language);
        if ($items === null) {
            return null;
        }
        $seo ??= (string) $db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
        $path = '/' . ($language !== '' ? $language . '/' : '') . $seo;
        foreach (self::flatten($items) as $x) {
            if (($x['typ'] === 'stranka' && $x['ids'] === $ids) || ($x['typ'] === 'odkaz' && $seo !== '' && rtrim((string) $x['url'], '/') === $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All items of a menu at every level (submenus and group columns) in order.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function flatten(array $items): array
    {
        $out = [];
        foreach ($items as $p) {
            $out[] = $p;
            array_push($out, ...self::flatten(is_array($p['deti'] ?? null) ? $p['deti'] : []));
        }

        return $out;
    }
}
