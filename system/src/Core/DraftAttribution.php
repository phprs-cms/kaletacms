<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * Who made the draft of a page (3.24, N-U6): builder autosaves are not stamped on the page (Modules\Pages::edited – the
 * sitemap reads zmeneno and a draft changes nothing on the site), so the draft's author lives in the change log: one
 * "draft edited" line per person, page and hour (a later save in the same hour moves the line's time). The Pages list,
 * the builder's top bar, "Waiting for you" and MCP list_pending_review read the latest line since the page was last
 * edited or published – "Draft by Jana, 21:05". A line older than the page's last edit says nothing, so a published or
 * re-edited page never shows a stale name. The change log keeps half a year; an older draft simply has no name.
 */
final class DraftAttribution
{
    /** ka_protokol.akce of the line (translated in the change log like every action). */
    public const string ACTION = 'draft edited';

    /** The signed-in person (or their Claude connection) saved the draft of the page. Never breaks the save. */
    public static function record(App $app, int $pageId, string $title): void
    {
        $user = $app->auth()->user();
        if ($user === null || $pageId <= 0) {
            return;
        }
        $prefix = '#' . $pageId . ' ';
        $via = mb_substr((string) ($app->auth()->connection()['name'] ?? ''), 0, 100);
        try {
            $db = $app->db();
            $line = $db->value("SELECT idp FROM {protokol} WHERE kdo = ? AND via = ? AND modul = 'pages' AND akce = ? AND popis LIKE ? AND cas >= ? ORDER BY idp DESC LIMIT 1",
                [(int) $user['idu'], $via, self::ACTION, addcslashes($prefix, '%_\\') . '%', date('Y-m-d H:00:00')]);
            if ($line !== null) {
                $db->update('protokol', ['cas' => date('Y-m-d H:i:s')], ['idp' => (int) $line]);

                return;
            }
        } catch (\Throwable) {
            return; // the log must not break the draft it records
        }
        ChangeLog::write($app, 'pages', self::ACTION, $prefix . mb_substr($title, 0, 80));
    }

    /**
     * The author of each page's draft: the latest "draft edited" line since the page was last edited (zmeneno – a publish
     * stamps it too). Only pages that have a draft.
     *
     * @param list<array<string, mixed>> $pages rows with ids, zmeneno and stavba_koncept
     * @return array<int, array{by: string, at: string}> page id => who (the name at that moment, with the Claude connection) and when
     */
    public static function forPages(Db $db, array $pages): array
    {
        $since = [];
        foreach ($pages as $p) {
            if (($p['stavba_koncept'] ?? null) !== null) {
                $since[(int) $p['ids']] = (string) ($p['zmeneno'] ?? '') ?: '1970-01-01 00:00:00';
            }
        }
        if ($since === []) {
            return [];
        }
        $out = [];
        try {
            $rows = $db->all('SELECT jmeno, via, cas, popis FROM {protokol} WHERE modul = ? AND akce = ? AND cas >= ? ORDER BY cas DESC, idp DESC LIMIT 2000', ['pages', self::ACTION, min($since)]);
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $r) {
            if (!preg_match('/^#(\d+) /', (string) $r['popis'], $m)) {
                continue;
            }
            $id = (int) $m[1];
            if (isset($since[$id]) && !isset($out[$id]) && (string) $r['cas'] >= $since[$id]) {
                $name = (string) $r['jmeno'] !== '' ? (string) $r['jmeno'] : t('(former user)');
                $out[$id] = ['by' => (string) $r['via'] !== '' ? t('%s via %s', $name, (string) $r['via']) : $name, 'at' => (string) $r['cas']];
            }
        }

        return $out;
    }

    /** "Draft by Jana, 21:05" – the time alone today, with the date before. @param array{by: string, at: string} $author */
    public static function label(array $author): string
    {
        $at = (string) $author['at'];

        return t('Draft by %s, %s', $author['by'], substr($at, 0, 10) === date('Y-m-d') ? substr($at, 11, 5) : format_date($at, true));
    }
}
