<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The starter (sample) pages the installer puts on a new site (3.33, M2): Home, About us, Services and Contact with sample
 * texts – or, in an empty install for a move, only the Home placeholder. A site moved here from another platform must not
 * keep them: the old /contact would otherwise become /contact-2 next to a sample "Contact", and the migration report
 * would call the sample page "ok".
 *
 * "Untouched" is decided from the data, never from a flag a person could forget:
 *  - the installer records each starter page with a fingerprint of what it wrote (the setting starter_pages, JSON
 *    {"<ids>": "<sha1>"}); the page is untouched while it is not in the trash, nobody's edit is stamped on it (zmenil –
 *    every save by a person or a Claude connection stamps it, Modules\Pages::edited), it has no builder draft and its
 *    title, address, text, build, description, SEO title, image and visibility still give the same fingerprint;
 *  - a site installed before 3.33 (3.20–3.32: the installer stamped zmeneno, never zmenil) has no record: there the four
 *    starter titles and addresses of the installer in the site language count, on the first pages of the site, with the
 *    same conditions and no published version in the build history. A site from before 3.20 has none (nothing proves
 *    that nobody edited them).
 * The privacy policy skeleton is a legal template, not a sample page – it is never recorded and never replaced.
 *
 * An import that would create a page at the address of an untouched starter page replaces it (retire(): the sample
 * page goes to the trash under a free address, restorable for 30 days) – unless the preview's choice declined it. An
 * edited starter page is never replaced.
 */
final class StarterPages
{
    /** The setting with the record of the installer; '' = no record (a site from before 3.33). */
    public const string SETTING = 'starter_pages';

    /** The installer's starter titles (site dictionary) in the order it creates them – the first one is the home page. */
    public const array TITLES = ['Úvod', 'About us', 'Services', 'Contact'];

    /** What a retired sample page's address gets appended, so the imported page can take the address. */
    private const string RETIRED_SUFFIX = '-sample';

    /** The columns the fingerprint covers. */
    private const array COLUMNS = ['titulek', 'seo_link', 'text', 'stavba', 'popis', 'seo_titulek', 'obrazek', 'zobrazit', 'jazyk'];

    /**
     * The fingerprint of a starter page: what the installer wrote. Any change of these columns makes the page edited.
     *
     * @param array<string, mixed> $row a row of ka_stranky
     */
    public static function fingerprint(array $row): string
    {
        $values = [];
        foreach (self::COLUMNS as $column) {
            $values[] = $column === 'zobrazit' ? (int) ($row[$column] ?? 0) : (string) ($row[$column] ?? '');
        }

        return sha1((string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The record the installer writes (the value of SETTING) for the pages it has just created.
     *
     * @param list<int> $ids
     */
    public static function record(Db $db, array $ids): string
    {
        $record = [];
        foreach ($ids as $id) {
            $row = $db->one('SELECT ' . implode(', ', self::COLUMNS) . ' FROM {stranky} WHERE ids = ?', [$id]);
            if ($row !== null) {
                $record[(string) $id] = self::fingerprint($row);
            }
        }

        return $record === [] ? '{}' : (string) json_encode($record);
    }

    /**
     * The recorded starter pages: ids => fingerprint; null = the site has no record (installed before 3.33).
     *
     * @return array<int, string>|null
     */
    public static function recorded(Settings $settings): ?array
    {
        $raw = $settings->get(self::SETTING);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $id => $hash) {
            if ((int) $id > 0 && is_string($hash)) {
                $out[(int) $id] = $hash;
            }
        }

        return $out;
    }

    /**
     * The starter pages nobody has touched since the installation: ids => [ids, seo_link, jazyk, titulek, home].
     *
     * @return array<int, array{ids: int, seo_link: string, jazyk: string, titulek: string, home: bool}>
     */
    public static function untouched(Db $db, Settings $settings): array
    {
        $home = $settings->int('home_page');
        $recorded = self::recorded($settings);
        $rows = $recorded !== null ? self::recordedRows($db, $recorded) : self::legacyRows($db, $settings);
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['ids'];
            $out[$id] = ['ids' => $id, 'seo_link' => (string) $row['seo_link'], 'jazyk' => (string) $row['jazyk'], 'titulek' => (string) $row['titulek'], 'home' => $id === $home];
        }

        return $out;
    }

    /**
     * The recorded starter pages someone has edited since (they are never replaced; an import names them).
     *
     * @return list<int>
     */
    public static function edited(Db $db, Settings $settings): array
    {
        $recorded = self::recorded($settings) ?? [];

        return array_values(array_diff(array_keys($recorded), array_keys(self::untouched($db, $settings))));
    }

    /**
     * @param array<int, string> $recorded
     * @return list<array<string, mixed>>
     */
    private static function recordedRows(Db $db, array $recorded): array
    {
        if ($recorded === []) {
            return [];
        }
        $rows = $db->all('SELECT ids, ' . implode(', ', self::COLUMNS) . ' FROM {stranky} WHERE ids IN (' . implode(', ', array_keys($recorded)) . ')
            AND smazano IS NULL AND zmenil IS NULL AND stavba_koncept IS NULL ORDER BY ids');

        return array_values(array_filter($rows, fn (array $r): bool => hash_equals($recorded[(int) $r['ids']] ?? '', self::fingerprint($r))));
    }

    /**
     * A site installed before 3.33 (and after 3.20, which stamps zmeneno at the installation): the installer's starter
     * titles with their addresses in the site language, on the first five pages of the site, never edited, no draft and
     * no published version in the build history.
     *
     * @return list<array<string, mixed>>
     */
    private static function legacyRows(Db $db, Settings $settings): array
    {
        $language = $settings->get('site_language') ?: 'cs';
        $wanted = [];
        foreach (self::titles($language) as $title) {
            $wanted[$title] = slugify($title);
        }
        $first = array_map('intval', array_column($db->all('SELECT ids FROM {stranky} ORDER BY ids LIMIT 5'), 'ids'));
        if ($first === []) {
            return [];
        }
        $rows = $db->all("SELECT s.ids, s.seo_link, s.jazyk, s.titulek FROM {stranky} s WHERE s.ids IN (" . implode(', ', $first) . ") AND s.jazyk = '' AND s.smazano IS NULL
            AND s.zmenil IS NULL AND s.zmeneno IS NOT NULL AND s.stavba_koncept IS NULL
            AND NOT EXISTS (SELECT 1 FROM {stavba_revize} r WHERE r.ids = s.ids) ORDER BY s.ids");

        return array_values(array_filter($rows, fn (array $r): bool => ($wanted[(string) $r['titulek']] ?? null) === (string) $r['seo_link']));
    }

    /**
     * The installer's starter titles in a site language (the site dictionary, as the installer writes them).
     *
     * @return array<int|string, string>
     */
    public static function titles(string $language): array
    {
        $code = isset(Language::AVAILABLE[$language]) ? $language : 'cs';

        return array_map('strval', (array) Language::runWith($code, fn (): array => array_map(fn (string $t): string => t($t), self::TITLES)));
    }

    /**
     * The untouched starter page an imported page would land on: the one at this address of this language version, or –
     * for the old site's home page – the home page itself. Null = none (or the preview's choice keeps it: $keep).
     *
     * @param list<int> $keep starter pages the person chose to keep
     * @return array{ids: int, seo_link: string, jazyk: string, titulek: string, home: bool}|null
     */
    public static function at(Db $db, Settings $settings, string $slug, string $language, bool $home, array $keep = []): ?array
    {
        foreach (self::untouched($db, $settings) as $id => $page) {
            if (in_array($id, $keep, true)) {
                continue;
            }
            if (($home && $page['home'] && $page['jazyk'] === $language) || ($slug !== '' && $page['seo_link'] === $slug && $page['jazyk'] === $language)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Retires an untouched starter page so an imported page can take its place: into the trash (restorable for 30 days, as
     * every deleted page) under a free address with RETIRED_SUFFIX, out of the record. A retired home page leaves the
     * setting home_page empty – the caller points it at the imported page.
     */
    public static function retire(Db $db, Settings $settings, int $ids): void
    {
        $page = $db->one('SELECT seo_link, jazyk FROM {stranky} WHERE ids = ?', [$ids]);
        if ($page === null) {
            return;
        }
        $language = (string) $page['jazyk'];
        $free = Slug::makeUnique(mb_substr((string) $page['seo_link'], 0, 120 - strlen(self::RETIRED_SUFFIX) - 4) . self::RETIRED_SUFFIX,
            fn (string $a): bool => Slug::taken($db, 'stranky', $a, $language, $ids), 120);
        $db->update('stranky', ['seo_link' => $free, 'smazano' => Clock::now(), 'zobrazit' => 0, 'show_on_publish' => 0, 'v_menu' => 0], ['ids' => $ids]);
        if ($settings->int('home_page') === $ids) {
            $settings->set('home_page', '0');
        }
        $recorded = self::recorded($settings);
        if ($recorded !== null) {
            unset($recorded[$ids]);
            $settings->set(self::SETTING, $recorded === [] ? '{}' : (string) json_encode(array_combine(array_map('strval', array_keys($recorded)), $recorded)));
        }
    }

    /**
     * The step for a site that was installed with sample pages and is now a move (Import and export → Sample pages): the
     * untouched starter pages except the home page go to the trash (the home page stays until an import of the old home
     * page replaces it – the site needs one). Returns their titles.
     *
     * @return list<string>
     */
    public static function removeSamples(Db $db, Settings $settings): array
    {
        $removed = [];
        foreach (self::untouched($db, $settings) as $id => $page) {
            if (!$page['home']) {
                self::retire($db, $settings, $id);
                $removed[] = $page['titulek'];
            }
        }

        return $removed;
    }
}
