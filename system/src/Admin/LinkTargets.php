<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\Extensions;
use Kaleta\Core\Language;

/**
 * Where a link in a text can lead (3.22, U6): the link dialog of the text editor (image/editor.js) searches pages, news,
 * collection item pages, files in Media and the language versions of the site as the user types (admin.php?action=link_targets).
 * Each source follows the rules of the lists for a connection (Core\Auth::pageListScope and the others): what the user may not
 * see in the admin, they see only when visitors see it; files with the Media section (open to every user).
 */
final class LinkTargets
{
    /** Results of one kind at most. */
    public const int LIMIT = 8;

    /**
     * @return list<array{label: string, items: list<array{url: string, title: string, note: string}>}> groups that found something
     */
    public static function search(App $app, string $query): array
    {
        $q = mb_substr(trim($query), 0, 80);
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $db = $app->db();
        $auth = $app->auth();
        $settings = $app->settings();
        $languages = array_merge([''], Language::additional($settings));
        $languageName = static fn (string $code): string => Language::AVAILABLE[Language::ofContent($settings, $code)][0] ?? $code;
        $several = count($languages) > 1;
        $groups = [];

        // pages: the user's own list without the Pages section is only what visitors see; the home page is the site's address
        $home = $settings->int('home_page');
        $pages = [];
        foreach ($db->all('SELECT ids, titulek, seo_link, jazyk, zobrazit, preklad_z FROM {stranky} WHERE smazano IS NULL'
            . ($q !== '' ? ' AND (titulek LIKE ? OR seo_link LIKE ?)' : '') . $auth->pageListScope()
            . ' ORDER BY jazyk, poradi, ' . $db->sortText('titulek') . ' LIMIT ' . self::LIMIT, $q !== '' ? [$like, $like] : []) as $s) {
            $prefix = $s['jazyk'] !== '' ? $s['jazyk'] . '/' : '';
            $isHome = (int) $s['ids'] === $home || ($s['jazyk'] !== '' && (int) $s['preklad_z'] === $home && $home > 0);
            $pages[] = ['url' => $app->url($prefix . ($isHome ? '' : $s['seo_link'])), 'title' => (string) $s['titulek'],
                'note' => self::note($several ? $languageName((string) $s['jazyk']) : '', $s['zobrazit'] ? '' : t('hidden'))];
        }
        $newsOn = Extensions::isEnabled($settings, 'novinky');
        if ($newsOn && ($q === '' || mb_stripos(t('News'), $q) !== false || mb_stripos('news', $q) !== false)) {
            $pages[] = ['url' => $app->url('novinky'), 'title' => t('News'), 'note' => t('the list of news')];
        }
        if ($pages !== []) {
            $groups[] = ['label' => t('Pages'), 'items' => $pages];
        }

        if ($q !== '' && $newsOn) {
            // published news of anyone; the rest by the user's own list (an author's drafts)
            $news = $db->all('SELECT titulek, seo_link, jazyk, visible AND datum <= ? AS vydany FROM {novinky} WHERE smazano IS NULL AND titulek LIKE ?'
                . ' AND ((visible = 1 AND datum <= ?) OR (1 = 1' . $auth->newsListScope() . ')) ORDER BY datum DESC LIMIT ' . self::LIMIT, [Clock::now(), $like, Clock::now()]);
            if ($news !== []) {
                $groups[] = ['label' => t('News'), 'items' => array_map(fn (array $c): array => ['url' => $app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk']),
                    'title' => (string) $c['titulek'], 'note' => self::note($several ? $languageName((string) $c['jazyk']) : '', $c['vydany'] ? '' : t('not published'))], $news)];
            }
        }

        if ($q !== '') {
            // items of collections that have their own pages (Collections → "Each item has its own page")
            $items = $db->all('SELECT p.nazev, p.seo_link, p.jazyk, p.zobrazit, k.nazev AS kolekce, k.seo_link AS kolekce_seo FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk'
                . ' WHERE k.detail = 1 AND p.smazano IS NULL AND p.seo_link <> \'\' AND p.nazev LIKE ?' . $auth->collectionItemListScope('p.')
                . ' ORDER BY p.zobrazit DESC, ' . $db->sortText('p.nazev') . ' LIMIT ' . self::LIMIT, [$like]);
            if ($items !== []) {
                $groups[] = ['label' => t('Collection items'), 'items' => array_map(fn (array $p): array => [
                    'url' => $app->url(($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $p['kolekce_seo'] . '/' . $p['seo_link']),
                    'title' => (string) $p['nazev'], 'note' => self::note((string) $p['kolekce'], $p['zobrazit'] ? '' : t('hidden')),
                ], $items)];
            }
        }

        if ($q !== '' && $auth->hasModule('media', Modules\Media::FOR_ALL_USERS)) {
            // files in Media (a price list in PDF, a photo) – never one still waiting for approval outside the web root
            $files = $db->all('SELECT nazev, obr_poloha FROM {media} WHERE staged_at IS NULL AND (nazev LIKE ? OR obr_poloha LIKE ?) ORDER BY datum DESC LIMIT ' . self::LIMIT, [$like, $like]);
            if ($files !== []) {
                $groups[] = ['label' => t('Files in Media'), 'items' => array_map(fn (array $m): array => ['url' => $app->url((string) $m['obr_poloha']),
                    'title' => $m['nazev'] !== '' ? (string) $m['nazev'] : basename((string) $m['obr_poloha']), 'note' => strtoupper(pathinfo((string) $m['obr_poloha'], PATHINFO_EXTENSION))], $files)];
            }
        }

        if ($several) {
            // the home page of each language version (the language switcher's targets)
            $versions = [];
            foreach ($languages as $code) {
                $name = $languageName($code);
                if ($q === '' || mb_stripos($name, $q) !== false || ($code !== '' && stripos($code, $q) !== false)) {
                    $versions[] = ['url' => $app->url($code !== '' ? $code . '/' : ''), 'title' => t('Home page – %s', $name), 'note' => $code !== '' ? '/' . $code . '/' : ''];
                }
            }
            if ($versions !== []) {
                $groups[] = ['label' => t('Language versions'), 'items' => $versions];
            }
        }

        return $groups;
    }

    /** "English · hidden" – the parts that are not empty. */
    private static function note(string ...$parts): string
    {
        return implode(' · ', array_filter($parts, fn (string $p): bool => $p !== ''));
    }
}
