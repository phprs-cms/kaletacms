<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * "Waiting for you" (3.2): everything on the site that waits for a person – drafts and proposals from Claude and from
 * colleagues. The dashboard shows it above the counters (Admin\Kernel::desktop, views/admin/pending_review.php) and
 * Claude reads the same list with the MCP tool list_pending_review. Only the kinds the person may open are listed, and
 * only kinds with something in them.
 */
final class PendingReview
{
    /** kind => [label, the admin section that opens it (Module::IDENT)] – in the order the dashboard shows them */
    public const array KINDS = [
        'requests_done' => ['Requests Claude finished – review the drafts', 'requests'],
        'page_drafts' => ['Pages with unpublished changes', 'pages'],
        'draft_comments' => ['Unresolved comments on drafts', 'pages'],
        'news_drafts' => ['News drafts to publish', 'news'],
        'hidden_items' => ['Hidden collection items changed in the last 30 days', 'collections'],
        'staged_media' => ['Files from Claude waiting for approval', 'media'], // 3.15, Core\MediaStaging
        'proposed_hours' => ['Proposed exceptions to the opening hours', 'business'],
        'part_drafts' => ['Site parts with unpublished changes', 'parts'],
        'look_draft' => ['Unpublished changes of the look', 'appearance'],
    ];

    /**
     * kind => the screen of the site's administration that opens it, relative to the site (3.17: the fleet console links
     * to the same screens on each site – Fleet\Inbox).
     */
    public const array URLS = [
        'requests_done' => 'admin.php?module=requests&status=done', 'page_drafts' => 'admin.php?module=pages', 'draft_comments' => 'admin.php?module=pages',
        'news_drafts' => 'admin.php?module=news&status=koncepty', 'hidden_items' => 'admin.php?module=collections', 'staged_media' => 'admin.php?module=media&staged=1',
        'proposed_hours' => 'admin.php?module=business#proposed-hours', 'part_drafts' => 'admin.php?module=parts', 'look_draft' => 'admin.php?module=appearance',
    ];

    /** How many example titles one kind carries. */
    public const int EXAMPLES = 5;

    /** Hidden collection items count as waiting this many days after their last change. */
    public const int ITEM_DAYS = 30;

    /** A request Claude finished waits for the requester's review this many days. */
    public const int REQUEST_DAYS = 14;

    /**
     * What waits for the signed-in person.
     *
     * @param array<string, string> $modules the sections the person may open (Admin\Kernel::modules(): ident => class)
     * @return list<array{kind: string, label: string, count: int, url: string, examples: list<string>}> url is relative to the site (admin.php?…)
     */
    public static function all(App $app, array $modules): array
    {
        $out = [];
        foreach (self::KINDS as $kind => [$label, $module]) {
            if (!isset($modules[$module])) {
                continue;
            }
            try {
                $found = self::find($app, $kind);
            } catch (\Throwable) {
                $found = null; // a table from a newer migration that has not run yet must not break the dashboard
            }
            if ($found !== null && $found[0] > 0) {
                $out[] = ['kind' => $kind, 'label' => $label, 'count' => $found[0], 'url' => $found[1], 'examples' => array_slice($found[2], 0, self::EXAMPLES)];
            }
        }

        return $out;
    }

    /**
     * 3.16: how much of each kind waits on the whole site, whoever looks – for the fleet heartbeat (Fleet\Heartbeat), which
     * a background job sends without a signed-in person. Counts only, every kind (0 when nothing waits); a kind whose
     * table is missing counts 0.
     *
     * @return array<string, int> kind => count, in the order of KINDS
     */
    public static function counts(App $app): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) {
            try {
                $out[$kind] = (self::find($app, $kind, true) ?? [0])[0];
            } catch (\Throwable) {
                $out[$kind] = 0;
            }
        }

        return $out;
    }

    /**
     * One kind: [count, admin URL, example titles]. $siteWide (3.16): the whole site, not what the signed-in person may do.
     *
     * @return array{0: int, 1: string, 2: list<string>}|null
     */
    private static function find(App $app, string $kind, bool $siteWide = false): ?array
    {
        $db = $app->db();
        $limit = ' LIMIT ' . self::EXAMPLES;
        $titles = fn (array $rows, string $column): array => array_values(array_map(fn (array $r): string => (string) $r[$column], $rows));

        switch ($kind) {
            case 'requests_done':
                // marked done with a note from Claude: the requester reviews the drafts it links
                $where = "FROM {requests} r WHERE r.status = 'done' AND r.done_at >= ?"
                    . " AND EXISTS (SELECT 1 FROM {request_messages} m WHERE m.request_id = r.id AND m.sender = 'claude')";
                $since = [Clock::at('-' . self::REQUEST_DAYS . ' days')];

                return [(int) $db->value('SELECT COUNT(*) ' . $where, $since), self::URLS['requests_done'], $titles($db->all('SELECT r.title ' . $where . ' ORDER BY r.done_at DESC' . $limit, $since), 'title')];
            case 'page_drafts':
                $where = 'FROM {stranky} WHERE stavba_koncept IS NOT NULL AND smazano IS NULL';

                return [(int) $db->value('SELECT COUNT(*) ' . $where), self::URLS['page_drafts'], $titles($db->all('SELECT titulek ' . $where . ' ORDER BY zmeneno DESC' . $limit), 'titulek')];
            case 'draft_comments':
                $counts = DraftComments::unresolvedCounts($db);
                if ($counts === []) {
                    return null;
                }
                arsort($counts);
                $names = $db->pairs('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL AND ids IN (' . implode(',', array_map(intval(...), array_keys($counts))) . ')');
                $counts = array_intersect_key($counts, $names);
                $examples = array_map(fn (int $id, int $n): string => $names[$id] . ' (' . $n . ')', array_keys($counts), $counts);

                return [array_sum($counts), self::URLS['draft_comments'], $examples];
            case 'news_drafts':
                // drafts an editor can publish: recent ones, and those of news authors whatever their age (they wait for an editor)
                $auth = $siteWide ? null : $app->auth(); // site-wide: no signed-in person (the heartbeat job)
                if ($auth !== null && !$auth->isAdmin() && !$auth->isEditor()) {
                    return null; // an author's drafts wait for an editor, not for the author
                }
                $where = 'FROM {novinky} c WHERE c.visible = 0 AND c.smazano IS NULL' . ($auth !== null ? $auth->articleScope('c.') : '')
                    . ' AND (COALESCE(c.zmeneno, c.datum) >= ? OR ' . \Kaleta\Admin\Modules\News::AWAITING_PUBLICATION . ')';
                $since = [Clock::at('-' . self::ITEM_DAYS . ' days')];

                return [(int) $db->value('SELECT COUNT(*) ' . $where, $since), self::URLS['news_drafts'], $titles($db->all('SELECT c.titulek ' . $where . ' ORDER BY COALESCE(c.zmeneno, c.datum) DESC' . $limit, $since), 'titulek')];
            case 'hidden_items':
                // hidden and not scheduled: a draft of an item (Claude's, a testimonial that arrived, a colleague's)
                $where = 'FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.zobrazit = 0 AND p.smazano IS NULL AND p.zverejnit_od IS NULL'
                    . ' AND COALESCE(p.zmeneno, p.datum) >= ?';
                $since = [Clock::at('-' . self::ITEM_DAYS . ' days')];
                $collections = array_map(intval(...), array_column($db->all('SELECT DISTINCT p.idk ' . $where, $since), 'idk'));

                return [(int) $db->value('SELECT COUNT(*) ' . $where, $since), count($collections) === 1 ? 'admin.php?module=collections&action=items&id=' . $collections[0] : self::URLS['hidden_items'],
                    array_map(fn (array $r): string => $r['nazev'] . ' (' . $r['kolekce'] . ')', $db->all('SELECT p.nazev, k.nazev AS kolekce ' . $where . ' ORDER BY COALESCE(p.zmeneno, p.datum) DESC' . $limit, $since))];
            case 'staged_media':
                if (!$siteWide && !$app->auth()->canPublish()) {
                    return null; // approving makes the file public – whoever may publish does it
                }
                $rows = $db->all('SELECT obr_poloha, nazev FROM {media} WHERE staged_at IS NOT NULL ORDER BY ido DESC' . $limit);

                return [MediaStaging::count($db), self::URLS['staged_media'], array_map(fn (array $r): string => $r['nazev'] !== '' ? (string) $r['nazev'] : basename((string) $r['obr_poloha']), $rows)];
            case 'proposed_hours':
                $proposed = Hours::proposed($db);

                return [count($proposed), self::URLS['proposed_hours'], array_map(Hours::describe(...), $proposed)];
            case 'part_drafts':
                $rows = $db->all('SELECT typ, jazyk, varianta, nazev FROM {casti} WHERE stavba_koncept IS NOT NULL ORDER BY zmeneno DESC');

                return [count($rows), self::URLS['part_drafts'], array_map(fn (array $r): string => t(\Kaleta\Builder\SiteParts::TYPES[$r['typ']][0] ?? (string) $r['typ'])
                    . ($r['varianta'] !== '' ? ' – ' . ($r['nazev'] !== '' ? $r['nazev'] : $r['varianta']) : '') . ($r['jazyk'] !== '' ? ' (' . strtoupper((string) $r['jazyk']) . ')' : ''), $rows)];
            case 'look_draft':
                $summary = Look::hasDraft($app->settings()) ? Look::summary($db, $app->settings()) : [];

                return [count($summary), self::URLS['look_draft'], $summary];
        }

        return null;
    }
}
