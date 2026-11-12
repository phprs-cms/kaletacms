<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * "Layout not rebuilt yet" (3.33): a page an import created (from a website or a WordPress export) keeps the plain imported
 * text as its build until someone rebuilds it – the work queue of a move across sessions (MCP list_pages, the admin list
 * of pages). The import remembers a fingerprint of the build it stored in ka_import_mapa (source "layout", type "stranka",
 * foreign id "<page id>:<sha256 of the build>", our id = the page) – no migration. A page waits while its published build
 * still has that fingerprint and it has no draft: any edit (a draft from the builder or MCP, a published build) clears
 * the flag by itself; discarding the draft of an untouched page brings it back, because nothing was rebuilt.
 */
final class ImportLayout
{
    private const string SOURCE = 'layout';

    /** Remembers the build an import stored for the page (null = a page without a build: nothing to rebuild from). */
    public static function remember(Db $db, int $page, ?string $build): void
    {
        $db->run("DELETE FROM {import_mapa} WHERE zdroj = ? AND typ = 'stranka' AND nase_id = ?", [self::SOURCE, $page]);
        if ($build !== null) {
            $db->run("INSERT INTO {import_mapa} (zdroj, typ, cizi_id, nase_id) VALUES (?, 'stranka', ?, ?)", [self::SOURCE, $page . ':' . hash('sha256', $build), $page]);
        }
    }

    /**
     * The import stored a new version of a page's build (WordPress: the build again once the images are in Media): the
     * fingerprint follows it only while the page still waited – a page someone rebuilt in between stays rebuilt.
     */
    public static function replaced(Db $db, int $page, ?string $before, ?string $after): void
    {
        if ($before !== null && $db->value("SELECT 1 FROM {import_mapa} WHERE zdroj = ? AND typ = 'stranka' AND cizi_id = ?", [self::SOURCE, $page . ':' . hash('sha256', $before)]) !== null) {
            self::remember($db, $page, $after);
        }
    }

    /**
     * The fingerprints that can never match again (3.33 review L4, from the Scheduler's cleanup job – never on a GET): the
     * page has a different published build and no draft (it was rebuilt), has no build any more, or is gone for good.
     * A page in the trash or with a draft keeps its row – restoring it or discarding the draft brings the flag back.
     * Returns how many rows were removed.
     */
    public static function prune(Db $db): int
    {
        $gone = 0;
        foreach ($db->all("SELECT m.cizi_id, s.ids, s.stavba IS NULL AS unbuilt, s.smazano IS NOT NULL AS trashed, s.stavba_koncept IS NOT NULL AS draft,
            m.cizi_id = CONCAT(s.ids, ':', SHA2(COALESCE(s.stavba, ''), 256)) AS same
            FROM {import_mapa} m LEFT JOIN {stranky} s ON s.ids = m.nase_id WHERE m.zdroj = ? AND m.typ = 'stranka' LIMIT 5000", [self::SOURCE]) as $r) {
            $stale = $r['ids'] === null || (!(int) $r['trashed'] && !(int) $r['draft'] && ((int) $r['unbuilt'] || !(int) $r['same']));
            if ($stale) {
                $gone += $db->run("DELETE FROM {import_mapa} WHERE zdroj = ? AND typ = 'stranka' AND cizi_id = ?", [self::SOURCE, (string) $r['cizi_id']])->rowCount();
            }
        }

        return $gone;
    }

    /**
     * Done by hand (3.36): a person (Pages → "Layout done") or Claude (MCP layout_queue with done) says the page's layout is
     * fine as it is – the import kept it well enough, or it was rebuilt elsewhere. The fingerprint goes, so the page leaves
     * the queue for good (nothing on the page changes). Returns whether the page had one.
     */
    public static function markRebuilt(Db $db, int $page): bool
    {
        return $db->run("DELETE FROM {import_mapa} WHERE zdroj = ? AND typ = 'stranka' AND nase_id = ?", [self::SOURCE, $page])->rowCount() > 0;
    }

    /** 3.36 review L3: the most pages one call of the queue lists (the rest by offset). */
    public const int QUEUE_PAGE = 50;

    /**
     * The work queue with what Claude needs to take the next page (3.36, MCP layout_queue): the page and where it stood on
     * the old site – the domain the import recorded (web:<domain>, wp:<domain>) and the old path (the source of a redirect
     * to the page when its address changed, else its own address, which the imports keep). The old address is a hint for
     * read_old_page; '' when the import recorded no domain. 3.36 review L3: QUEUE_PAGE pages from offset with the total,
     * and the redirects of all of them in one query – the oldest exact one, an import's own before a guess of the daily
     * job (auto_score) and a pretty address before a WordPress ?p=123.
     *
     * @return array{pages: list<array{id: int, title: string, slug: string, visible: bool, old_url: string}>, total: int}
     */
    public static function queue(Db $db, int $home = 0, int $offset = 0, int $limit = self::QUEUE_PAGE): array
    {
        $waiting = self::pending($db);
        if ($waiting === []) {
            return ['pages' => [], 'total' => 0];
        }
        $ids = implode(', ', array_keys($waiting));
        $rows = $db->all("SELECT ids, titulek, seo_link, zobrazit FROM {stranky} WHERE ids IN ($ids) ORDER BY jazyk, poradi, ids LIMIT "
            . max(1, min(self::QUEUE_PAGE, $limit)) . ' OFFSET ' . max(0, $offset));
        if ($rows === []) {
            return ['pages' => [], 'total' => count($waiting)];
        }
        $listed = implode(', ', array_map(fn (array $r): int => (int) $r['ids'], $rows));
        $domains = [];
        foreach ($db->all("SELECT nase_id, zdroj FROM {import_mapa} WHERE typ = 'stranka' AND nase_id IN ($listed) AND (zdroj LIKE 'web:%' OR zdroj LIKE 'wp:%') ORDER BY zdroj") as $r) {
            $domain = substr((string) $r['zdroj'], (int) strpos((string) $r['zdroj'], ':') + 1);
            if (preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/iD', $domain)) {
                $domains[(int) $r['nase_id']] ??= strtolower($domain);
            }
        }
        $targets = [];
        foreach ($rows as $r) {
            if (isset($domains[(int) $r['ids']]) && (int) $r['ids'] !== $home) {
                $targets[] = (string) $r['seo_link'];
                $targets[] = '/' . $r['seo_link'];
            }
        }
        $sources = [];
        if ($targets !== []) {
            $marks = implode(', ', array_fill(0, count($targets), '?'));
            foreach ($db->all("SELECT z_adresy, na_adresu FROM {presmerovani} WHERE na_adresu IN ($marks) AND z_adresy NOT LIKE '%*%' AND typ IN (301, 302)
                ORDER BY auto_score IS NOT NULL, z_adresy LIKE '?%', idp", $targets) as $r) {
                $sources[ltrim((string) $r['na_adresu'], '/')] ??= (string) $r['z_adresy'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['ids'];
            $slug = (string) $r['seo_link'];
            $old = '';
            if (isset($domains[$id])) {
                $from = $id === $home ? '' : ($sources[$slug] ?? $slug);
                $old = 'https://' . $domains[$id] . '/' . ltrim($from, '/');
            }
            $out[] = ['id' => $id, 'title' => (string) $r['titulek'], 'slug' => $slug, 'visible' => (bool) $r['zobrazit'], 'old_url' => $old];
        }

        return ['pages' => $out, 'total' => count($waiting)];
    }

    /**
     * The pages whose layout waits to be rebuilt, outside the trash – one query, the fingerprint compared in the database.
     *
     * @return array<int, true> page id => true
     */
    public static function pending(Db $db): array
    {
        $ids = array_column($db->all("SELECT m.nase_id FROM {import_mapa} m JOIN {stranky} s ON s.ids = m.nase_id
            WHERE m.zdroj = ? AND m.typ = 'stranka' AND s.smazano IS NULL AND s.stavba_koncept IS NULL AND s.stavba IS NOT NULL
            AND m.cizi_id = CONCAT(s.ids, ':', SHA2(s.stavba, 256))", [self::SOURCE]), 'nase_id');

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
