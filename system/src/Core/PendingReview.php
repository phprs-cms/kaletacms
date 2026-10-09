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
        'proposed_hours' => ['Proposed exceptions to the opening hours', 'business'],
        'part_drafts' => ['Site parts with unpublished changes', 'parts'],
        'look_draft' => ['Unpublished changes of the look', 'appearance'],
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
     * One kind: [count, admin URL, example titles].
     *
     * @return array{0: int, 1: string, 2: list<string>}|null
     */
    private static function find(App $app, string $kind): ?array
    {
        $db = $app->db();
        $limit = ' LIMIT ' . self::EXAMPLES;
        $titles = fn (array $rows, string $column): array => array_values(array_map(fn (array $r): string => (string) $r[$column], $rows));

        switch ($kind) {
            case 'requests_done':
                // marked done with a note from Claude: the requester reviews the drafts it links
                $where = "FROM {requests} r WHERE r.status = 'done' AND r.done_at >= NOW() - INTERVAL " . self::REQUEST_DAYS . ' DAY'
                    . " AND EXISTS (SELECT 1 FROM {request_messages} m WHERE m.request_id = r.id AND m.sender = 'claude')";

                return [(int) $db->value('SELECT COUNT(*) ' . $where), 'admin.php?module=requests&status=done', $titles($db->all('SELECT r.title ' . $where . ' ORDER BY r.done_at DESC' . $limit), 'title')];
            case 'page_drafts':
                $where = 'FROM {stranky} WHERE stavba_koncept IS NOT NULL AND smazano IS NULL';

                return [(int) $db->value('SELECT COUNT(*) ' . $where), 'admin.php?module=pages', $titles($db->all('SELECT titulek ' . $where . ' ORDER BY zmeneno DESC' . $limit), 'titulek')];
            case 'draft_comments':
                $counts = DraftComments::unresolvedCounts($db);
                if ($counts === []) {
                    return null;
                }
                arsort($counts);
                $names = $db->pairs('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL AND ids IN (' . implode(',', array_map(intval(...), array_keys($counts))) . ')');
                $counts = array_intersect_key($counts, $names);
                $examples = array_map(fn (int $id, int $n): string => $names[$id] . ' (' . $n . ')', array_keys($counts), $counts);

                return [array_sum($counts), 'admin.php?module=pages', $examples];
            case 'news_drafts':
                // drafts an editor can publish: recent ones, and those of news authors whatever their age (they wait for an editor)
                $auth = $app->auth();
                if (!$auth->isAdmin() && !$auth->isEditor()) {
                    return null; // an author's drafts wait for an editor, not for the author
                }
                $where = 'FROM {novinky} c WHERE c.visible = 0 AND c.smazano IS NULL' . $auth->articleScope('c.')
                    . ' AND (COALESCE(c.zmeneno, c.datum) >= NOW() - INTERVAL ' . self::ITEM_DAYS . ' DAY OR ' . \Kaleta\Admin\Modules\News::AWAITING_PUBLICATION . ')';

                return [(int) $db->value('SELECT COUNT(*) ' . $where), 'admin.php?module=news&status=koncepty', $titles($db->all('SELECT c.titulek ' . $where . ' ORDER BY COALESCE(c.zmeneno, c.datum) DESC' . $limit), 'titulek')];
            case 'hidden_items':
                // hidden and not scheduled: a draft of an item (Claude's, a testimonial that arrived, a colleague's)
                $where = 'FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.zobrazit = 0 AND p.smazano IS NULL AND p.zverejnit_od IS NULL'
                    . ' AND COALESCE(p.zmeneno, p.datum) >= NOW() - INTERVAL ' . self::ITEM_DAYS . ' DAY';
                $collections = array_map(intval(...), array_column($db->all('SELECT DISTINCT p.idk ' . $where), 'idk'));

                return [(int) $db->value('SELECT COUNT(*) ' . $where), count($collections) === 1 ? 'admin.php?module=collections&action=items&id=' . $collections[0] : 'admin.php?module=collections',
                    array_map(fn (array $r): string => $r['nazev'] . ' (' . $r['kolekce'] . ')', $db->all('SELECT p.nazev, k.nazev AS kolekce ' . $where . ' ORDER BY COALESCE(p.zmeneno, p.datum) DESC' . $limit))];
            case 'proposed_hours':
                $proposed = Hours::proposed($db);

                return [count($proposed), 'admin.php?module=business#proposed-hours', array_map(Hours::describe(...), $proposed)];
            case 'part_drafts':
                $rows = $db->all('SELECT typ, jazyk, varianta, nazev FROM {casti} WHERE stavba_koncept IS NOT NULL ORDER BY zmeneno DESC');

                return [count($rows), 'admin.php?module=parts', array_map(fn (array $r): string => t(\Kaleta\Builder\SiteParts::TYPES[$r['typ']][0] ?? (string) $r['typ'])
                    . ($r['varianta'] !== '' ? ' – ' . ($r['nazev'] !== '' ? $r['nazev'] : $r['varianta']) : '') . ($r['jazyk'] !== '' ? ' (' . strtoupper((string) $r['jazyk']) . ')' : ''), $rows)];
            case 'look_draft':
                $summary = Look::hasDraft($app->settings()) ? Look::summary($db, $app->settings()) : [];

                return [count($summary), 'admin.php?module=appearance', $summary];
        }

        return null;
    }
}
