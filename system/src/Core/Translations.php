<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Translation overview (2.14): every page, news item and collection item in the default language against the site's
 * other languages – the translation is present, missing, or older than the original (the original changed after the
 * translation was last saved). Pages and news items are linked by preklad_z, collection items by the same address in
 * the same collection (the switcher and hreflang pair them the same way).
 *
 * Read-only: the admin screen Pages → Translations and the MCP tool translation_status both draw on matrix().
 *
 * 3.10.1 (the audit's N33): the overview lists only what the user's list tools list – Auth::pageListScope,
 * newsListScope and collectionItemListScope, the conditions of list_pages, list_news and list_collection_items. A
 * translation outside that scope still counts for the status of an original the user sees (so nobody translates it
 * twice), but its id is left out.
 */
final class Translations
{
    public const string PRESENT = 'present';
    public const string MISSING = 'missing';
    public const string OUTDATED = 'outdated';

    /**
     * The status of one translation against its original: the dates are the rows' zmeneno (or datum when never changed).
     *
     * @param array<string, mixed>|null $translation
     */
    public static function status(?array $translation, ?string $originalChanged): string
    {
        if ($translation === null) {
            return self::MISSING;
        }
        $translated = $translation['zmeneno'] ?? $translation['datum'] ?? null;
        if ($originalChanged !== null && $translated !== null && strtotime((string) $translated) < strtotime($originalChanged)) {
            return self::OUTDATED;
        }

        return self::PRESENT;
    }

    /**
     * @return array{languages: list<string>, rows: list<array{type: string, id: int, title: string, changed: ?string, collection?: array{idk: int, nazev: string, seo_link: string},
     *     translations: array<string, array{status: string, id: ?int}>}>} languages = the site's additional languages ([] = a single-language site);
     *     id = null for a missing translation and for one outside the user's scope
     */
    public static function matrix(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $auth = $app->auth();
        $languages = Language::additional($s);
        if ($languages === []) {
            return ['languages' => [], 'rows' => []];
        }
        $rows = [];
        $cells = function (array $byLanguage, ?string $changed) use ($languages): array {
            $out = [];
            foreach ($languages as $code) {
                $translation = $byLanguage[$code] ?? null;
                $out[$code] = ['status' => self::status($translation, $changed), 'id' => $translation === null || !$translation['in_scope'] ? null : (int) $translation['id']];
            }

            return $out;
        };

        $pageTranslations = [];
        foreach ($db->all("SELECT ids AS id, preklad_z, jazyk, zmeneno, (1" . $auth->pageListScope() . ") AS in_scope FROM {stranky} WHERE smazano IS NULL AND jazyk <> '' AND preklad_z IS NOT NULL") as $t) {
            $pageTranslations[(int) $t['preklad_z']][$t['jazyk']] = $t;
        }
        foreach ($db->all("SELECT ids, titulek, zmeneno FROM {stranky} WHERE smazano IS NULL AND jazyk = ''" . $auth->pageListScope() . ' ORDER BY poradi, titulek') as $p) {
            $rows[] = ['type' => 'page', 'id' => (int) $p['ids'], 'title' => $p['titulek'], 'changed' => $p['zmeneno'], 'translations' => $cells($pageTranslations[(int) $p['ids']] ?? [], $p['zmeneno'])];
        }

        if (Extensions::isEnabled($s, 'novinky')) {
            $newsTranslations = [];
            foreach ($db->all("SELECT idc AS id, preklad_z, jazyk, zmeneno, datum, (1" . $auth->newsListScope() . ") AS in_scope FROM {novinky} WHERE smazano IS NULL AND jazyk <> '' AND preklad_z IS NOT NULL") as $t) {
                $newsTranslations[(int) $t['preklad_z']][$t['jazyk']] = $t;
            }
            foreach ($db->all("SELECT idc, titulek, zmeneno, datum FROM {novinky} WHERE smazano IS NULL AND jazyk = ''" . $auth->newsListScope() . ' ORDER BY datum DESC LIMIT 500') as $c) {
                $changed = $c['zmeneno'] ?? $c['datum'];
                $rows[] = ['type' => 'news', 'id' => (int) $c['idc'], 'title' => $c['titulek'], 'changed' => $changed, 'translations' => $cells($newsTranslations[(int) $c['idc']] ?? [], $changed)];
            }
        }

        $itemTranslations = [];
        foreach ($db->all("SELECT idp AS id, idk, seo_link, jazyk, zmeneno, datum, (1" . $auth->collectionItemListScope() . ") AS in_scope FROM {kolekce_polozky} WHERE smazano IS NULL AND jazyk <> ''") as $t) {
            $itemTranslations[(int) $t['idk']][$t['seo_link']][$t['jazyk']] = $t;
        }
        foreach ($db->all("SELECT p.idp, p.idk, p.nazev, p.seo_link, p.zmeneno, p.datum, k.nazev AS kolekce, k.seo_link AS kolekce_adresa FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk
            WHERE p.smazano IS NULL AND p.jazyk = ''" . $auth->collectionItemListScope('p.') . ' ORDER BY k.nazev, p.poradi, p.nazev') as $p) {
            $changed = $p['zmeneno'] ?? $p['datum'];
            $rows[] = ['type' => 'collection_item', 'id' => (int) $p['idp'], 'title' => $p['nazev'], 'changed' => $changed,
                'collection' => ['idk' => (int) $p['idk'], 'nazev' => $p['kolekce'], 'seo_link' => $p['kolekce_adresa']],
                'translations' => $cells($itemTranslations[(int) $p['idk']][$p['seo_link']] ?? [], $changed)];
        }

        return ['languages' => $languages, 'rows' => $rows];
    }
}
