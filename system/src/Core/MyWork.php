<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * "My work" (3.24): what waits for one person – the admin screen Modules\MyWork and the MCP tool my_work read the same
 * lists. Each list only when the person may open its section (the modules of Admin\Kernel::modules()):
 *
 *  - enquiries assigned to them that are still open, and – with Enquiries – the ones nobody handles yet: unassigned and
 *    not answered, opened or not (3.32, UNANSWERED), while still new or no older than UNASSIGNED_DAYS (an old opened one
 *    nobody took is not today's work);
 *  - pages and collection items they are responsible for (owner_id) whose "review by" date is within REVIEW_DAYS or
 *    past, that have unpublished changes (a page's draft build, a hidden item changed lately) or unresolved comments on
 *    the draft (pages);
 *  - their requests to Claude where Claude wrote last (an answer or a question waits for them);
 *  - their open tasks (3.27, Core\Tasks), first on the screen, grouped overdue / today / upcoming / later – for everyone,
 *    no section needed. The tile and the badge count the overdue ones and today's (counts()).
 *  - (3.31) the open deals they are responsible for (Core\Deals::forOwner), by expected close – with the pipeline section;
 *    the tile counts only those whose expected close has passed (an open deal is not work due every day).
 *  - (3.34 B) with the section Applications: the undecided job applications they are responsible for, and the new ones
 *    nobody is responsible for yet – by number and position (the name only on the screen, never in the MCP result).
 *
 * Every query starts from an indexed column (prirazeno + stav, owner_id, requests of one author) and is capped by LIMIT.
 */
final class MyWork
{
    /** A "review by" date this many days ahead (or past) puts the page or item on the list. */
    public const int REVIEW_DAYS = 14;

    /** At most this many rows per list. */
    public const int LIMIT = 50;

    /** A hidden collection item counts as unpublished work this many days after its last change (as PendingReview). */
    public const int ITEM_DAYS = PendingReview::ITEM_DAYS;

    /** A request Claude finished or declined stays on the list this many days (as PendingReview). */
    public const int REQUEST_DAYS = PendingReview::REQUEST_DAYS;

    /**
     * An enquiry not answered yet (3.32, R1): not resolved (stav < 2) and no reply sent from Kaleta (ka_poptavky.replies –
     * Core\EnquiryReply writes every reply there, queued ones too). Read or not does not matter. A reply sent from someone's
     * own mail program is not known – marking the enquiry resolved, or choosing a responsible person, takes it off the list.
     */
    public const string UNANSWERED = "stav < 2 AND (replies IS NULL OR replies IN ('', '[]'))";

    /**
     * 3.32 (review): an unassigned, unanswered enquiry is listed while it is new (stav = 0) or arrived within this many
     * days – an opened one nobody took weeks ago stays in Enquiries, not in everyone's My work (and MCP my_work).
     */
    public const int UNASSIGNED_DAYS = 30;

    /** The lists and the section (Module::IDENT) each needs. */
    public const array LISTS = ['assigned' => 'enquiries', 'unassigned' => 'enquiries', 'pages' => 'pages', 'items' => 'collections', 'requests' => 'requests', 'deals' => 'pipeline',
        'applications' => 'applications', 'applications_new' => 'applications']; // 3.34 B: the undecided job applications one is responsible for, and the new ones nobody is

    /**
     * The lists of one person. Only the lists of sections in $modules are present; each row has id, title, url (relative
     * to the site, admin.php?…) and what makes it wait.
     *
     * @param array<string, string> $modules ident => class, the sections the person may open
     * @param list<string> $skip list keys (LISTS) not read at all (count() – their queries do not run)
     * @return array<string, list<array<string, mixed>>> list => rows
     */
    public static function lists(App $app, array $modules, int $userId, array $skip = []): array
    {
        $out = ['tasks' => self::tasks($app->db(), $userId)];
        foreach (self::LISTS as $list => $section) {
            if (!isset($modules[$section]) || in_array($list, $skip, true)) {
                continue;
            }
            try {
                $out[$list] = self::find($app, $list, $userId);
            } catch (\PDOException) {
                $out[$list] = []; // a column from a newer migration that has not run yet must not break the screen
            }
        }

        return $out;
    }

    /**
     * How many rows the lists have together (the dashboard tile), without the lists in $skip – the Dashboard counts the
     * enquiries assigned to the person in its own tile "Assigned to me", so its My work tile leaves 'assigned' out.
     *
     * @param array<string, string> $modules
     * @param list<string> $skip list keys (LISTS) not counted
     */
    public static function count(App $app, array $modules, int $userId, array $skip = []): int
    {
        $tasks = Tasks::counts($app->db(), $userId); // 3.27: of the tasks only what is due – overdue and today's
        $lists = self::lists($app, $modules, $userId, $skip); // a skipped list is not queried
        // 3.31: of the open deals only those whose expected close has passed
        $deals = in_array('deals', $skip, true) ? 0 : count(array_filter($lists['deals'] ?? [], fn (array $d): bool => (bool) ($d['overdue'] ?? false)));

        return array_sum(array_map(count(...), array_diff_key($lists, array_flip([...$skip, 'tasks', 'deals']))))
            + (in_array('tasks', $skip, true) ? 0 : $tasks['overdue'] + $tasks['today']) + $deals;
    }

    /**
     * The person's open tasks (3.27) as rows of the lists: overdue first, then today's, upcoming and later ones, each with
     * its group, due moment and what it is about (Tasks::label – the form name of an enquiry, never its fields).
     *
     * @return list<array<string, mixed>>
     */
    public static function tasks(Db $db, int $userId): array
    {
        $rows = Tasks::forUser($db, $userId, 'open', self::LIMIT);
        $order = ['overdue' => 0, 'today' => 1, 'upcoming' => 2, 'later' => 3];
        usort($rows, fn (array $a, array $b): int => $order[$a['group']] <=> $order[$b['group']]); // stable: by due date within a group
        $labels = Tasks::labels($db, array_column($rows, 'target'));

        return array_map(fn (array $t): array => ['kind' => 'task', 'id' => $t['id'], 'title' => $t['title'], 'group' => $t['group'], 'due_at' => $t['due_at'],
            'remind_at' => $t['remind_at'], 'about' => $labels[(string) $t['target']] ?? null, 'created_by' => $t['created_by'],
            'url' => 'admin.php?module=my_work#task-' . $t['id']], $rows);
    }

    /**
     * One list.
     *
     * @return list<array<string, mixed>>
     */
    private static function find(App $app, string $list, int $userId): array
    {
        $db = $app->db();
        $limit = ' LIMIT ' . self::LIMIT;
        switch ($list) {
            case 'assigned':
            case 'unassigned':
                // the key ix_poptavky_prirazeno (prirazeno, stav) of migration 0110 serves both
                // spam left out of both, as in the Enquiries list and the dashboard tiles (N324-5)
                // 3.32 (R1): "nobody handles it yet" = nobody assigned and not answered (UNANSWERED), whatever the read state –
                // opening an enquiry is not handling it – while it is new or arrived within UNASSIGNED_DAYS (3.32 review)
                $where = ($list === 'assigned' ? 'prirazeno = ? AND stav < 2' : 'prirazeno IS NULL AND ' . self::UNANSWERED . ' AND (stav = 0 OR datum >= ?)') . ' AND ' . \Kaleta\Admin\Modules\Enquiries::NOT_SPAM;
                $rows = $db->all('SELECT idp, datum, formular, tema, zdroj, email, data, anonymizovano, stav, priorita FROM {poptavky} WHERE ' . $where . ' ORDER BY idp DESC' . $limit,
                    $list === 'assigned' ? [$userId] : [Clock::at('-' . self::UNASSIGNED_DAYS . ' days')]);

                // 3.32: name = who and what (RecordTitle::enquiry) for the screen; the MCP tool my_work keeps title (the form) and leaves name out
                return array_map(fn (array $p): array => ['kind' => 'enquiry', 'id' => (int) $p['idp'], 'title' => (string) $p['formular'], 'name' => RecordTitle::enquiry($p), 'about' => (string) $p['tema'],
                    'status' => ['new', 'read', 'resolved'][(int) $p['stav']] ?? 'new', 'priority' => Triage::PRIORITIES[(int) $p['priorita']] ?? null, 'date' => (string) $p['datum'],
                    'url' => 'admin.php?module=enquiries&action=detail&id=' . (int) $p['idp']], $rows);
            case 'pages':
                $soon = Clock::day('+' . self::REVIEW_DAYS . ' days');
                $rows = $db->all('SELECT ids, titulek, review_by, stavba_koncept IS NOT NULL AS draft, zmeneno FROM {stranky} WHERE owner_id = ? AND smazano IS NULL ORDER BY zmeneno DESC LIMIT 500', [$userId]);
                $comments = self::comments($db, array_map(fn (array $r): int => (int) $r['ids'], $rows));
                $out = [];
                foreach ($rows as $r) {
                    $reasons = self::reviewReasons($r['review_by'], $soon);
                    if ($r['draft']) {
                        $reasons[] = 'draft';
                    }
                    $open = $comments[(int) $r['ids']] ?? 0;
                    if ($open > 0) {
                        $reasons[] = 'comments';
                    }
                    if ($reasons !== []) {
                        $out[] = ['kind' => 'page', 'id' => (int) $r['ids'], 'title' => (string) $r['titulek'], 'reasons' => $reasons, 'review_by' => $r['review_by'] !== null ? (string) $r['review_by'] : null,
                            'comments' => $open, 'url' => 'admin.php?module=pages&action=edit&id=' . (int) $r['ids']];
                    }
                }

                return array_slice(self::byUrgency($out), 0, self::LIMIT);
            case 'items':
                $soon = Clock::day('+' . self::REVIEW_DAYS . ' days');
                $rows = $db->all('SELECT p.idp, p.idk, p.nazev, p.review_by, (p.zobrazit = 0 AND p.zverejnit_od IS NULL AND COALESCE(p.zmeneno, p.datum) >= ?) AS hidden, k.nazev AS kolekce'
                    . ' FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.owner_id = ? AND p.smazano IS NULL'
                    . ' AND ((p.review_by IS NOT NULL AND p.review_by <= ?) OR (p.zobrazit = 0 AND p.zverejnit_od IS NULL AND COALESCE(p.zmeneno, p.datum) >= ?))'
                    . ' ORDER BY p.review_by IS NULL, p.review_by' . $limit, [Clock::at('-' . self::ITEM_DAYS . ' days'), $userId, $soon, Clock::at('-' . self::ITEM_DAYS . ' days')]);

                return self::byUrgency(array_map(fn (array $r): array => ['kind' => 'item', 'id' => (int) $r['idp'], 'title' => (string) $r['nazev'], 'collection' => (string) $r['kolekce'],
                    'reasons' => [...self::reviewReasons($r['review_by'], $soon), ...($r['hidden'] ? ['hidden'] : [])], 'review_by' => $r['review_by'] !== null ? (string) $r['review_by'] : null,
                    'url' => 'admin.php?module=collections&action=item&id=' . (int) $r['idk'] . '&item=' . (int) $r['idp']], $rows));
            case 'deals':
                return Deals::forOwner($app, $userId, self::LIMIT); // 3.31: kind deal, stage, stage_label, expected_close, overdue, contact, url
            case 'applications':
            case 'applications_new':
                // 3.34 B: the key ix_applications_assignee (assignee_id, status) serves both
                $rows = $list === 'applications'
                    ? $db->all("SELECT id, name, job_title, status, received_at FROM {applications} WHERE assignee_id = ? AND status NOT IN ('hired', 'rejected') ORDER BY id DESC" . $limit, [$userId])
                    : $db->all("SELECT id, name, job_title, status, received_at FROM {applications} WHERE assignee_id IS NULL AND status = 'new' ORDER BY id DESC" . $limit);

                return array_map(fn (array $r): array => ['kind' => 'application', 'id' => (int) $r['id'], 'title' => (string) $r['job_title'], 'name' => (string) $r['name'],
                    'status' => (string) $r['status'], 'date' => (string) $r['received_at'], 'url' => Applications::adminPath((int) $r['id'])], $rows);
            case 'requests':
                // the person's own requests where the last message is Claude's; a finished or declined one only for REQUEST_DAYS
                $rows = $db->all("SELECT r.id, r.title, r.status, r.updated_at FROM {requests} r WHERE r.author_id = ? AND (r.status IN ('new', 'in_progress') OR r.done_at >= ?)"
                    . " AND (SELECT m.sender FROM {request_messages} m WHERE m.request_id = r.id ORDER BY m.id DESC LIMIT 1) = 'claude' ORDER BY r.updated_at DESC" . $limit,
                    [$userId, Clock::at('-' . self::REQUEST_DAYS . ' days')]);

                return array_map(fn (array $r): array => ['kind' => 'request', 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'status' => (string) $r['status'],
                    'date' => (string) $r['updated_at'], 'url' => 'admin.php?module=requests&action=detail&id=' . (int) $r['id']], $rows);
        }

        return [];
    }

    /** 'overdue' (the review date has passed), 'review' (today or within REVIEW_DAYS), or nothing. @return list<string> */
    private static function reviewReasons(mixed $reviewBy, string $soon): array
    {
        if (!is_string($reviewBy) || $reviewBy === '' || $reviewBy > $soon) {
            return [];
        }

        return [$reviewBy < Clock::today() ? 'overdue' : 'review'];
    }

    /**
     * Unresolved comments on the drafts of these pages: page id => count (one query over the key of target and resolved_at).
     *
     * @param list<int> $pages
     * @return array<int, int>
     */
    private static function comments(Db $db, array $pages): array
    {
        if ($pages === []) {
            return [];
        }
        $targets = array_map(fn (int $id): string => 'stranka:' . $id, $pages);
        $out = [];
        foreach ($db->all('SELECT target, COUNT(*) AS n FROM {draft_comments} WHERE resolved_at IS NULL AND target IN (' . implode(',', array_fill(0, count($targets), '?')) . ') GROUP BY target', $targets) as $row) {
            $out[(int) substr((string) $row['target'], 8)] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Overdue reviews first, then the nearest review dates, then the rest in their order.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function byUrgency(array $rows): array
    {
        $rank = fn (array $r): string => (in_array('overdue', $r['reasons'], true) ? '0' : (in_array('review', $r['reasons'], true) ? '1' : '2')) . ($r['review_by'] ?? '9999-12-31');
        usort($rows, fn (array $a, array $b): int => $rank($a) <=> $rank($b)); // a stable sort: equal ones keep their order

        return $rows;
    }
}
