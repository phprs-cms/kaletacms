<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Builder\Build;
use Kaleta\Builder\Elements\Form;
use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Language;
use Kaleta\Core\Settings;

/**
 * Where an enquiry came from, as links into the administration (3.20, C2): the Form element and the page, item template,
 * site part or pop-up that carries it. The enquiry row keeps the source ("stranka:4", zdroj), the element id (prvek) and the
 * form name (formular); an older or imported row has only the form name and the public path, so the page is then found by
 * its address and the form by its name. Statistics use the same path → page mapping for its "edit" links.
 */
final class EnquiryOrigin
{
    /**
     * The id of the Form element an enquiry came from, in a build: the stored id when the build still has that form, else
     * the form with the stored name (the element was replaced and has a new id), else the only form of the build; null when
     * nothing fits – the builder then opens without a selection.
     *
     * @param array<string, mixed>|null $build
     */
    public static function findForm(?array $build, string $id, string $name): ?string
    {
        $forms = [];
        $walk = function (array $children) use (&$walk, &$forms): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                if (($p['typ'] ?? '') === Form::TYPE && is_string($p['id'] ?? null) && $p['id'] !== '') {
                    $forms[] = ['id' => $p['id'], 'name' => mb_substr((string) ($p['obsah']['nazev'] ?? ''), 0, 120)];
                }
                if (is_array($p['deti'] ?? null)) {
                    $walk($p['deti']);
                }
            }
        };
        $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);
        if ($forms === []) {
            return null;
        }
        foreach ($forms as $f) {
            if ($id !== '' && $f['id'] === $id) {
                return $f['id'];
            }
        }
        foreach ($forms as $f) {
            if ($name !== '' && $f['name'] === $name) {
                return $f['id'];
            }
        }

        return count($forms) === 1 ? $forms[0]['id'] : null;
    }

    /**
     * The page a public path points to: "/" is the home page, "/en/contact" the page "contact" of the English version. Only
     * pages of the site (not news, not collection items); null when nothing matches.
     *
     * @return array{ids: int, titulek: string, seo_link: string, jazyk: string, zobrazit: int}|null
     */
    public static function pageByPath(Db $db, Settings $settings, string $path): ?array
    {
        // 3.20 (N320-3): a site with url_slash = 'html' stores /contact.html – the suffix is not part of the slug
        $path = preg_replace('~\.html$~', '', trim((string) parse_url($path, PHP_URL_PATH), '/'));
        $segments = $path === '' ? [] : explode('/', $path);
        $language = '';
        if ($segments !== [] && isset(Language::AVAILABLE[$segments[0]]) && in_array($segments[0], Language::additional($settings), true)) {
            $language = array_shift($segments);
        }
        $row = match (count($segments)) {
            0 => $language === ''
                ? $db->one('SELECT ids, titulek, seo_link, jazyk, zobrazit FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$settings->int('home_page')])
                : $db->one('SELECT ids, titulek, seo_link, jazyk, zobrazit FROM {stranky} WHERE preklad_z = ? AND jazyk = ? AND smazano IS NULL', [$settings->int('home_page'), $language]),
            1 => $db->one('SELECT ids, titulek, seo_link, jazyk, zobrazit FROM {stranky} WHERE seo_link = ? AND jazyk = ? AND smazano IS NULL', [$segments[0], $language]),
            default => null,
        };

        return $row === null ? null : ['ids' => (int) $row['ids'], 'titulek' => (string) $row['titulek'], 'seo_link' => (string) $row['seo_link'], 'jazyk' => (string) $row['jazyk'], 'zobrazit' => (int) $row['zobrazit']];
    }

    /**
     * The collection item a public path points to (/team/jane-doe): collection id, item id and name; null when it is not one.
     *
     * @return array{idk: int, idp: int, nazev: string}|null
     */
    public static function collectionItemByPath(Db $db, Settings $settings, string $path): ?array
    {
        // 3.20 (N320-3): the .html suffix of url_slash = 'html' is not part of the slug
        $segments = explode('/', preg_replace('~\.html$~', '', trim((string) parse_url($path, PHP_URL_PATH), '/')));
        $language = '';
        if (isset(Language::AVAILABLE[$segments[0]]) && in_array($segments[0], Language::additional($settings), true)) {
            $language = array_shift($segments);
        }
        if (count($segments) !== 2) {
            return null;
        }
        // 3.20 (N320-7): /en/team/jane is the English item, not the default-language one with the same slug
        $row = $db->one('SELECT p.idk, p.idp, p.nazev FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND p.seo_link = ? AND p.jazyk = ? AND p.smazano IS NULL LIMIT 1', [$segments[0], $segments[1], $language]);

        return $row === null ? null : ['idk' => (int) $row['idk'], 'idp' => (int) $row['idp'], 'nazev' => (string) $row['nazev']];
    }

    /**
     * The admin screen where a public path is edited – page settings or the collection item – for the signed-in user's
     * permissions; null when the path is not a page or item, or the user may not edit it (Statistics, 3.20 C2).
     */
    public static function editLink(App $app, string $path): ?string
    {
        $auth = $app->auth();
        if ($auth->hasModule('pages') && ($page = self::pageByPath($app->db(), $app->settings(), $path)) !== null) {
            return $app->url('admin.php?module=pages&action=edit&id=' . $page['ids']);
        }
        if ($auth->hasModule('collections') && ($item = self::collectionItemByPath($app->db(), $app->settings(), $path)) !== null) {
            return $app->url('admin.php?module=collections&action=item&id=' . $item['idk'] . '&item=' . $item['idp']);
        }

        return null;
    }

    /**
     * The origin of an enquiry for its detail: the form name, what carries it (title and kind) and the links – the builder with
     * the form selected (#element=<id>, image/stavitel.js) and the settings of the page or template. Null when the source is
     * gone or the user may not edit it; the detail then shows the public address only.
     *
     * @param array<string, mixed> $enquiry a row of ka_poptavky
     * @return array{form: string, kind: string, title: string, builder: string, settings: ?string}|null
     */
    public static function resolve(App $app, array $enquiry): ?array
    {
        $db = $app->db();
        $auth = $app->auth();
        $source = (string) ($enquiry['zdroj'] ?? '');
        $elementId = (string) ($enquiry['prvek'] ?? '');
        $form = (string) ($enquiry['formular'] ?? '');
        $admin = 'admin.php?module=';
        // the form is looked up in the published build first (that is where the enquiry was sent from), then in the draft
        $element = fn (?string $published, ?string $draft): string => (string) (self::findForm(Build::fromJson($published), $elementId, $form) ?? self::findForm(Build::fromJson($draft), $elementId, $form) ?? '');
        $anchor = fn (string $id): string => $id !== '' ? '#element=' . $id : '';

        if (preg_match('/^stranka:(\d+)$/D', $source, $m) || $source === '') {
            if (!$auth->hasModule('pages')) {
                return null;
            }
            $page = $source !== ''
                ? $db->one('SELECT ids, titulek, stavba, stavba_koncept FROM {stranky} WHERE ids = ? AND smazano IS NULL', [(int) $m[1]])
                : (($found = self::pageByPath($db, $app->settings(), (string) ($enquiry['stranka'] ?? ''))) === null ? null
                    : $db->one('SELECT ids, titulek, stavba, stavba_koncept FROM {stranky} WHERE ids = ?', [$found['ids']]));
            if ($page === null) {
                return null;
            }
            // 3.20 (N320-11): an old row whose form sits inside a component instance is not found by findForm – the page is
            // still known, so its links are shown without the #element= anchor (null only when the page itself is gone)
            $id = $element($page['stavba'], $page['stavba_koncept']);

            return ['form' => $form, 'kind' => 'page', 'title' => (string) $page['titulek'],
                'builder' => $app->url($admin . 'pages&action=builder&id=' . (int) $page['ids']) . $anchor($id),
                'settings' => $app->url($admin . 'pages&action=edit&id=' . (int) $page['ids'])];
        }
        if (preg_match('/^(kolekce|kategorie):(\d+)$/D', $source, $m)) {
            // the item (or category) template of a collection: its builder and fields are the administrator's
            if (!$auth->isAdmin()) {
                return null;
            }
            $collection = $db->one('SELECT idk, nazev, stavba, stavba_koncept FROM {kolekce} WHERE idk = ?', [(int) $m[2]]);
            if ($collection === null) {
                return null;
            }

            return ['form' => $form, 'kind' => 'collection', 'title' => (string) $collection['nazev'],
                'builder' => $app->url($admin . 'collections&action=builder&id=' . (int) $collection['idk']) . $anchor($m[1] === 'kolekce' ? $element($collection['stavba'], $collection['stavba_koncept']) : ''),
                'settings' => $app->url($admin . 'collections&action=edit&id=' . (int) $collection['idk'])];
        }
        if (preg_match('/^popup:(\d+)$/D', $source, $m)) {
            if (!$auth->isAdmin()) {
                return null;
            }
            $popup = $db->one('SELECT idpp, nazev, stavba, stavba_koncept FROM {popupy} WHERE idpp = ?', [(int) $m[1]]);
            if ($popup === null) {
                return null;
            }

            return ['form' => $form, 'kind' => 'popup', 'title' => (string) $popup['nazev'],
                'builder' => $app->url($admin . 'popups&action=builder&id=' . (int) $popup['idpp']) . $anchor($element($popup['stavba'], $popup['stavba_koncept'])),
                'settings' => $app->url($admin . 'popups&action=edit&id=' . (int) $popup['idpp'])];
        }
        if (preg_match('/^cast:([a-z]+):([a-z]{0,2})(?::([a-z0-9-]{1,40}))?$/D', $source, $m)) {
            if (!$auth->isAdmin() || !isset(\Kaleta\Builder\SiteParts::TYPES[$m[1]])) {
                return null;
            }
            $part = $db->one('SELECT stavba, stavba_koncept FROM {casti} WHERE typ = ? AND jazyk = ? AND varianta = ?', [$m[1], $m[2], $m[3] ?? '']);

            return ['form' => $form, 'kind' => 'part', 'title' => t(\Kaleta\Builder\SiteParts::TYPES[$m[1]][0]),
                'builder' => $app->url($admin . 'parts&action=builder&' . http_build_query(['type' => $m[1], 'language' => $m[2], 'variant' => $m[3] ?? ''])) . $anchor($part === null ? '' : $element($part['stavba'], $part['stavba_koncept'])),
                'settings' => null];
        }

        return null;
    }
}
