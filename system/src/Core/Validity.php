<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * True until and review by (2.10): content that is only true for a while hides itself, content that should be checked
 * asks for it. Pages, news items, collection items and pop-ups have two optional dates:
 *
 *  - valid_until: the day after it, the content hides itself – a page or an item gets zobrazit = 0, a news item
 *    visible = 0, a pop-up aktivni = 0. Every change goes to the change log (ChangeLog::write) and is recorded as the
 *    event content.expired; the page cache is cleared when something changed. The date stays, so the admin sees why.
 *  - review_by: on that day (and until the date is changed or removed) the site audit lists the content under
 *    "Review by" (Core\Audit) and the event content.review is recorded once per content and date. A page or a collection
 *    item with a responsible person (owner_id, 3.24) also reminds that person: a notification per item in their bell
 *    (3.26, Core\Inbox, kind review_due) and one e-mail a day at most, listing what of theirs is due and was not in an
 *    earlier reminder (remindOwners) – the e-mail as they chose in My account (instant, the daily summary, or none).
 *
 * run() is the hourly background job (Core\Scheduler, 'validity'). The selection helpers are pure and unit-tested.
 */
final class Validity
{
    /** The kinds that have a responsible person (owner_id, migration 0110) => the kind of Core\Assignment. */
    public const array OWNED = ['page' => 'page', 'collection_item' => 'item'];

    /** kind => [table, id column, title column, visibility column, change log module]. */
    public const array KINDS = [
        'page' => ['stranky', 'ids', 'titulek', 'zobrazit', 'pages'],
        'news' => ['novinky', 'idc', 'titulek', 'visible', 'news'],
        'collection_item' => ['kolekce_polozky', 'idp', 'nazev', 'zobrazit', 'collections'],
        'popup' => ['popupy', 'idpp', 'nazev', 'aktivni', 'popups'],
    ];

    /** The hourly run: hides what expired and asks for the reviews that are due. Returns a short result for System status. */
    public static function run(App $app): string
    {
        $db = $app->db();
        $today = date('Y-m-d');
        $hidden = 0;
        $reviews = 0;
        $asked = self::askedReviews($db);
        $owned = [];
        foreach (self::KINDS as $kind => [$table, $idColumn, $titleColumn, $visibleColumn, $module]) {
            $rows = $db->all('SELECT ' . $idColumn . ' AS id, ' . $titleColumn . ' AS title, ' . $visibleColumn . ' AS visible, valid_until, review_by'
                . (isset(self::OWNED[$kind]) ? ', owner_id' . ($kind === 'collection_item' ? ', idk' : '') : '') . ' FROM {' . $table . '}'
                . ' WHERE (valid_until IS NOT NULL OR review_by IS NOT NULL)' . ($kind === 'popup' ? '' : ' AND smazano IS NULL'));
            if (isset(self::OWNED[$kind])) {
                foreach (self::dueForReview($rows, $today, []) as $row) {
                    if (($row['owner_id'] ?? null) !== null) {
                        $owned[] = ['kind' => $kind] + $row;
                    }
                }
            }
            foreach (self::expired($rows, $today) as $row) {
                $db->update($table, [$visibleColumn => 0], [$idColumn => (int) $row['id']]);
                ChangeLog::write($app, $module, 'expired', sprintf('%s – true until %s', (string) $row['title'], (string) $row['valid_until']));
                Events::record($db, 'content.expired', 'warning', t('“%s” was true until %s and hid itself.', (string) $row['title'], format_date((string) $row['valid_until'])), ['kind' => $kind, 'id' => (int) $row['id']]);
                $hidden++;
            }
            foreach (self::dueForReview($rows, $today, $asked[$kind] ?? []) as $row) {
                Events::record($db, 'content.review', 'warning', t('“%s” asks for a review by %s.', (string) $row['title'], format_date((string) $row['review_by'])),
                    ['kind' => $kind, 'id' => (int) $row['id'], 'review_by' => (string) $row['review_by']]);
                $reviews++;
            }
        }
        if ($hidden > 0) {
            \Kaleta\Front\Cache::clear();
        }
        $mailed = $owned !== [] ? self::remindOwners($app, $owned, $today) : 0;

        return 'hidden ' . $hidden . ', reviews ' . $reviews . ($mailed > 0 ? ', reminders ' . $mailed : '');
    }

    /**
     * The review reminder to the responsible people (3.24): what of theirs is due (review_by today or earlier) and was not
     * in a reminder yet, once per person and day at most – what becomes due after today's reminder waits for tomorrow's.
     * Only to an active account that can still open the section; the others are left to the site audit, as before. 3.26:
     * every item becomes a notification (review_due) in the person's bell; the e-mail goes as in 3.24 only when they chose
     * "instant" (the default) and have an address – "daily" leaves it to the morning summary (Inbox::run), "off" to the
     * bell. Recorded as the event content.review_mailed (the user id, the items and the choice – no address). Returns how
     * many people were reminded.
     *
     * @param list<array<string, mixed>> $due rows with kind, id, title, review_by, owner_id (and idk for an item)
     */
    public static function remindOwners(App $app, array $due, string $today): int
    {
        $db = $app->db();
        [$mailedItems, $mailedToday] = self::sentReminders($db, $today);
        $byOwner = self::remindersToSend($due, $mailedItems, $mailedToday);
        $sent = 0;
        $s = $app->settings();
        $base = rtrim($s->get('site_url') ?: $app->request->origin(), '/');
        foreach ($byOwner as $owner => $rows) {
            $user = $db->one('SELECT idu, email, jazyk, register FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$owner]);
            $rows = array_values(array_filter($rows, fn (array $r): bool => Assignment::allowed($db, self::OWNED[$r['kind']], $owner)));
            if ($user === null || $rows === []) {
                continue;
            }
            // 3.26: a notification per item; the e-mail below is theirs – only for "instant" (before migration 0112 there are
            // no rows and no choice: the e-mail goes, as in 3.24)
            $notes = array_values(array_filter(array_map(fn (array $r): ?array => Inbox::record($db, $owner, 'review_due',
                ($r['kind'] === 'page' ? 'page:' : 'item:') . (int) $r['id'], null), $rows)));
            $choice = $notes[0]['email'] ?? 'instant';
            $mail = $choice === 'instant' && count(Mail::addresses((string) $user['email'])) === 1;
            if (!$mail && $notes === []) {
                continue; // neither the bell nor an e-mail – left to the site audit
            }
            Events::record($db, 'content.review_mailed', 'info', mb_substr(t('A review reminder went to the responsible person of %d items.', count($rows)), 0, 255),
                ['user' => $owner, 'day' => $today, 'items' => array_map(fn (array $r): string => $r['kind'] . '|' . (int) $r['id'] . '|' . $r['review_by'], $rows), 'email' => $mail ? 'instant' : $choice]);
            $sent++;
            if (!$mail) {
                continue;
            }
            [$subject, $body] = Language::runWith((string) $user['jazyk'] ?: Language::defaults($s), function () use ($rows, $base, $app): array {
                $lines = array_map(fn (array $r): string => '- ' . $r['title'] . ' (' . t('review by %s', format_date((string) $r['review_by'])) . ")\n  " . $base . $app->url($r['kind'] === 'page'
                    ? 'admin.php?module=pages&action=edit&id=' . (int) $r['id'] : 'admin.php?module=collections&action=item&id=' . (int) ($r['idk'] ?? 0) . '&item=' . (int) $r['id']), $rows);

                return [count($rows) === 1 ? t('Review by today: “%s”', (string) $rows[0]['title']) : t('Review by today: %d things you are responsible for', count($rows)),
                    t('You are the responsible person for this content, and its review-by day has come. Check it is still true and change the date (or remove it) once it is done:') . "\n\n"
                    . implode("\n", $lines) . "\n"];
            }, 'admin-', Language::normalizeRegister((string) $user['register']));
            Mail::send($s, (string) $user['email'], $subject, $body); // a background job: nobody waits for it, a failure is queued for a retry
            Inbox::markEmailed($db, array_map(fn (array $n): int => $n['id'], $notes));
        }

        return $sent;
    }

    /**
     * Which reminders to send (pure, unit-tested): the due rows by their responsible person, without the rows already in a
     * reminder for the same date and without the people who got one today.
     *
     * @param list<array<string, mixed>> $due rows with kind, id, review_by, owner_id
     * @param array<string, true> $mailedItems keys "<kind>|<id>|<review_by>" already in a reminder
     * @param array<int, true> $mailedToday users who got a reminder today
     * @return array<int, list<array<string, mixed>>> owner id => rows
     */
    public static function remindersToSend(array $due, array $mailedItems, array $mailedToday): array
    {
        $out = [];
        foreach ($due as $r) {
            $owner = (int) ($r['owner_id'] ?? 0);
            if ($owner > 0 && !isset($mailedToday[$owner]) && !isset($mailedItems[$r['kind'] . '|' . (int) $r['id'] . '|' . $r['review_by']])) {
                $out[$owner][] = $r;
            }
        }

        return $out;
    }

    /** @return array{0: array<string, true>, 1: array<int, true>} the items already in a reminder, the users mailed today */
    private static function sentReminders(Db $db, string $today): array
    {
        $items = [];
        $users = [];
        foreach ($db->all("SELECT data FROM {events} WHERE type = 'content.review_mailed'") as $r) {
            $data = json_decode((string) $r['data'], true);
            if (!is_array($data)) {
                continue;
            }
            foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $key) {
                $items[(string) $key] = true;
            }
            if (($data['day'] ?? '') === $today) {
                $users[(int) ($data['user'] ?? 0)] = true;
            }
        }

        return [$items, $users];
    }

    /* ---------- selection (pure, unit-tested) ---------- */

    /**
     * Rows that are still visible although their valid_until day has passed (today is already after it).
     *
     * @param list<array<string, mixed>> $rows with visible, valid_until (YYYY-MM-DD or null)
     * @return list<array<string, mixed>>
     */
    public static function expired(array $rows, string $today): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => !empty($r['visible']) && is_string($r['valid_until'] ?? null) && $r['valid_until'] !== '' && $r['valid_until'] < $today));
    }

    /**
     * Rows whose review_by is today or earlier and that have not been asked yet for this date.
     *
     * @param list<array<string, mixed>> $rows with id, review_by (YYYY-MM-DD or null)
     * @param array<string, true> $asked keys "<id>|<review_by>" of the reviews already recorded (see askedReviews)
     * @return list<array<string, mixed>>
     */
    public static function dueForReview(array $rows, string $today, array $asked): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => is_string($r['review_by'] ?? null) && $r['review_by'] !== '' && $r['review_by'] <= $today
            && !isset($asked[(int) $r['id'] . '|' . $r['review_by']])));
    }

    /**
     * A date from a form or from Claude: YYYY-MM-DD (a datetime is cut to its day), null when empty or not a date.
     * Claude's input is checked with isDate() first, so a typo is an error there and not a silently dropped date.
     */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = substr(trim($value), 0, 10);

        return self::isDate($value) ? $value : null;
    }

    /** Whether a value is a real YYYY-MM-DD date (the 30th of February is not). */
    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /* ---------- reading ---------- */

    /**
     * Reviews already recorded as events, by kind: kind => ["<id>|<review_by>" => true]. A changed review date asks again.
     *
     * @return array<string, array<string, true>>
     */
    private static function askedReviews(Db $db): array
    {
        $asked = [];
        foreach ($db->all("SELECT data FROM {events} WHERE type = 'content.review'") as $r) {
            $data = json_decode((string) $r['data'], true);
            if (is_array($data) && isset($data['kind'], $data['id'])) {
                $asked[(string) $data['kind']][(int) $data['id'] . '|' . (string) ($data['review_by'] ?? '')] = true;
            }
        }

        return $asked;
    }
}
