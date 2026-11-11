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
