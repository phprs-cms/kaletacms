<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\Db;

/**
 * Draft history of a build (3.25, U17): the last KEPT autosaves of the draft of a page, site part, collection template,
 * component or popup, next to its published versions in the builder's Versions dialog and in MCP list_build_versions.
 *
 * Stored in ka_stavba_revize like the published versions, told apart by the "cast" column (no migration): a page's draft
 * rows keep its ids (so they go with the page) and have cast "draft"; any other target has cast "draft:<its version key>".
 * A save through a Claude connection is marked "draft~mcp" instead, so a person's work and Claude's never merge into
 * one entry – "what did Claude change?" always has the state before Claude.
 *
 * Cheap and bounded: the saves of one person (or one connection) within BURST_SECONDS of an entry's start update that entry
 * in place – an entry is the state at the end of a burst of editing, labelled with the time the burst began – so one save
 * costs one extra write, and more than KEPT entries per target are pruned. A save that changes nothing adds nothing.
 * Restoring an entry (or a published version) always starts a new entry and first keeps the draft it replaces, so a
 * restore can itself be taken back, after a reload too.
 */
final class DraftHistory
{
    public const int KEPT = 10;

    public const int BURST_SECONDS = 180;

    private const string PERSON = 'draft';

    private const string CONNECTION = 'draft~mcp';

    /**
     * Records the draft that has just been saved. Never breaks the save.
     *
     * @param array{ids?: int|string, cast?: string} $target the target as Publisher knows it
     * @param string|null $before the draft (or the published build) the save replaced – kept first when $restore
     * @param bool $restore a restore: a new entry, and the replaced draft is kept if the history does not have it yet
     */
    public static function record(App $app, array $target, ?string $build, ?string $before = null, bool $restore = false): void
    {
        $casts = self::casts($target);
        if ($build === null || $casts === null) {
            return;
        }
        $db = $app->db();
        $user = $app->auth()->id() ?: null;
        $mine = $app->auth()->connection() !== null ? $casts[1] : $casts[0];
        try {
            [$where, $params] = self::where($target, $casts);
            // compared in the database (byte for byte, as the collation would ignore case) – the build itself is not fetched
            $latest = $db->one('SELECT idr, datum, kdo, cast, CAST(stavba AS BINARY) = CAST(? AS BINARY) AS same, CAST(stavba AS BINARY) = CAST(? AS BINARY) AS kept FROM {stavba_revize} WHERE ' . $where . ' ORDER BY idr DESC LIMIT 1',
                [$build, $before ?? '', ...$params]);
            if ($restore && $before !== null && $before !== $build && ($latest === null || !(int) $latest['kept'])) {
                // the draft being replaced was not in the history (written by something that does not record) – kept, author unknown
                self::insert($db, $target, $casts[0], null, $before);
                $latest = null;
            }
            if ($latest !== null && (int) $latest['same']) {
                return;
            }
            $started = $latest !== null ? Clock::fromDb((string) $latest['datum']) : null;
            if (!$restore && $latest !== null && $latest['cast'] === $mine && (int) ($latest['kdo'] ?? 0) === (int) $user
                && $started !== null && $started->getTimestamp() > time() - self::BURST_SECONDS) {
                $db->update('stavba_revize', ['stavba' => $build], ['idr' => (int) $latest['idr']]);

                return;
            }
            self::insert($db, $target, $mine, $user, $build);
            $boundary = $db->value('SELECT idr FROM {stavba_revize} WHERE ' . $where . ' ORDER BY idr DESC LIMIT 1 OFFSET ' . self::KEPT, $params);
            if ($boundary !== null) {
                $db->run('DELETE FROM {stavba_revize} WHERE ' . $where . ' AND idr <= ?', [...$params, (int) $boundary]);
            }
        } catch (\Throwable) {
            // the history must not break the draft it records
        }
    }

    /**
     * Entries, newest first: idr, datum, kdo (the name, "via Claude" for a connection), claude.
     *
     * @param array{ids?: int|string, cast?: string} $target
     * @return list<array{idr: int, datum: string, kdo: ?string, claude: bool}>
     */
    public static function listAll(Db $db, array $target): array
    {
        $casts = self::casts($target);
        if ($casts === null) {
            return [];
        }
        [$where, $params] = self::where($target, $casts);
        $out = [];
        foreach ($db->all("SELECT r.idr, r.datum, r.cast, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo FROM {stavba_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r."
            . str_replace(' AND cast', ' AND r.cast', $where) . ' ORDER BY r.idr DESC', $params) as $r) {
            $claude = $r['cast'] === $casts[1];
            $name = $r['kdo'] !== null && (string) $r['kdo'] !== '' ? (string) $r['kdo'] : null;
            $out[] = ['idr' => (int) $r['idr'], 'datum' => (string) $r['datum'], 'kdo' => $claude ? t('%s via %s', $name ?? t('(former user)'), 'Claude') : $name, 'claude' => $claude];
        }

        return $out;
    }

    /** @param array{ids?: int|string, cast?: string} $target */
    public static function load(Db $db, array $target, int $idr): ?string
    {
        $casts = self::casts($target);
        if ($casts === null || $idr <= 0) {
            return null;
        }
        [$where, $params] = self::where($target, $casts);
        $build = $db->value('SELECT stavba FROM {stavba_revize} WHERE idr = ? AND ' . $where, [$idr, ...$params]);

        return $build === null ? null : (string) $build;
    }

    /** "today 14:02", otherwise the date with the time – how the Versions dialog and MCP name an entry. */
    public static function when(string $datum): string
    {
        return substr($datum, 0, 10) === Clock::today() ? t('today') . ' ' . substr($datum, 11, 5) : format_date($datum, true);
    }

    /**
     * Per-section text comparison of a version with the current draft (3.25, C21): the top-level sections are matched by
     * their element id; a section only in the version was removed, one only in the draft was added. The text of a section
     * is Build::asText (content without the layout) compared with Core\Diff – a section whose text is the same but whose
     * layout or style changed says so without a text diff.
     *
     * @return array{sekce: list<array{nazev: string, stav: string, html: string, pridano: int, smazano: int}>, pridano: int, smazano: int}
     */
    public static function compare(?string $version, ?string $current): array
    {
        $old = self::sections($version);
        $new = self::sections($current);
        $out = [];
        $added = $deleted = 0;
        $position = 0;
        foreach ($new as $id => $section) {
            $position++;
            $name = self::sectionName($section, $position);
            $text = Build::asText(['deti' => [$section]]);
            if (!isset($old[$id])) {
                $diff = \Kaleta\Core\Diff::html('', $text);
                $out[] = ['nazev' => $name, 'stav' => 'added', 'html' => $diff['html'], 'pridano' => $diff['pridano'], 'smazano' => 0];
                $added += $diff['pridano'];
                continue;
            }
            $before = Build::asText(['deti' => [$old[$id]]]);
            if ($before === $text) {
                $out[] = ['nazev' => $name, 'stav' => self::json($old[$id]) === self::json($section) ? 'same' : 'layout', 'html' => '', 'pridano' => 0, 'smazano' => 0];
                continue;
            }
            $diff = \Kaleta\Core\Diff::html($before, $text);
            $out[] = ['nazev' => $name, 'stav' => 'changed', 'html' => $diff['html'], 'pridano' => $diff['pridano'], 'smazano' => $diff['smazano']];
            $added += $diff['pridano'];
            $deleted += $diff['smazano'];
        }
        $position = 0;
        foreach ($old as $id => $section) {
            $position++;
            if (!isset($new[$id])) {
                $diff = \Kaleta\Core\Diff::html(Build::asText(['deti' => [$section]]), '');
                $out[] = ['nazev' => self::sectionName($section, $position), 'stav' => 'removed', 'html' => $diff['html'], 'pridano' => 0, 'smazano' => $diff['smazano']];
                $deleted += $diff['smazano'];
            }
        }

        return ['sekce' => $out, 'pridano' => $added, 'smazano' => $deleted];
    }

    /** @return array<string, array<string, mixed>> top-level elements by id (one without an id gets its position) */
    private static function sections(?string $json): array
    {
        $out = [];
        foreach ((Build::fromJson($json) ?? [])['deti'] ?? [] as $i => $p) {
            if (is_array($p)) {
                $out[is_string($p['id'] ?? null) && $p['id'] !== '' ? $p['id'] : '#' . $i] = $p;
            }
        }

        return $out;
    }

    /** The name the editor's tree shows, else the first heading in the section, else "Section 3". */
    private static function sectionName(array $section, int $position): string
    {
        if (is_string($section['popis'] ?? null) && trim($section['popis']) !== '') {
            return trim($section['popis']);
        }
        $heading = null;
        $walk = function (array $children) use (&$walk, &$heading): void {
            foreach ($children as $p) {
                if ($heading !== null || !is_array($p)) {
                    return;
                }
                if (($p['typ'] ?? '') === 'nadpis' && trim(strip_tags((string) ($p['obsah']['text'] ?? ''))) !== '') {
                    $heading = trim(html_entity_decode(strip_tags((string) $p['obsah']['text']), ENT_QUOTES | ENT_HTML5));

                    return;
                }
                $walk(is_array($p['deti'] ?? null) ? $p['deti'] : []);
            }
        };
        $walk([$section]);

        return $heading !== null ? mb_substr($heading, 0, 60) : t('Section %s', (string) $position);
    }

    private static function json(array $section): string
    {
        return (string) json_encode($section, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The two cast values of a target: a person's entries, a connection's entries. Null when the key does not fit the column.
     *
     * @param array{ids?: int|string, cast?: string} $target
     * @return array{0: string, 1: string}|null
     */
    private static function casts(array $target): ?array
    {
        if (isset($target['ids'])) {
            return (int) $target['ids'] > 0 ? [self::PERSON, self::CONNECTION] : null;
        }
        $key = (string) ($target['cast'] ?? '');
        if ($key === '' || strlen(self::CONNECTION . ':' . $key) > 80) {
            return null;
        }

        return [self::PERSON . ':' . $key, self::CONNECTION . ':' . $key];
    }

    /** @param array{0: string, 1: string} $casts @return array{0: string, 1: list<int|string>} */
    private static function where(array $target, array $casts): array
    {
        return isset($target['ids']) ? ['ids = ? AND cast IN (?, ?)', [(int) $target['ids'], $casts[0], $casts[1]]] : ['ids IS NULL AND cast IN (?, ?)', [$casts[0], $casts[1]]];
    }

    private static function insert(Db $db, array $target, string $cast, ?int $user, string $build): void
    {
        $db->insert('stavba_revize', (isset($target['ids']) ? ['ids' => (int) $target['ids']] : []) + ['cast' => $cast, 'datum' => Clock::now(), 'kdo' => $user, 'stavba' => $build]);
    }
}
