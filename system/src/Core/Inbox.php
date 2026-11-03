<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Notifications of the administration (3.26, table ka_notifications, migration 0112): the bell in the header bar with the
 * unread count, the Notifications screen (action=inbox, Admin\InboxPage) and the e-mail each person chose in My account
 * (ka_uzivatele.notify_email): instant (one e-mail right away, the default – as before 3.26), daily (one summary in the
 * morning) or off (the bell only).
 *
 * A notification says who did what where: a mention, an assignment, a comment on something of mine, a review date, a task
 * that is due (3.27, Core\Tasks – target 'task:<id>' for a task about nothing, which opens My work). It
 * keeps the actor's name (it still reads after the account is removed) and at most EXCERPT characters of plain text –
 * never the content of an enquiry (an assignment carries no excerpt at all). Kept KEEP_DAYS days.
 *
 *  - notify(): the one entry point for everyone (comments and mentions, MCP) – records and, for "instant", e-mails.
 *  - record(): the row only, for the callers that send their own e-mail (Assignment::notify, Validity::remindOwners) and
 *    mark it with markEmailed() – so nothing is e-mailed twice.
 *  - unreadCount(): one COUNT over the key (user_id, read_at, id) – the bell on every admin page.
 *  - run(): the hourly background job – once a day after DIGEST_HOUR (site time) the summary for "daily" people, and the
 *    clean-up (older than KEEP_DAYS, removed accounts, enquiries that are gone).
 */
final class Inbox
{
    /** What a notification can be about. */
    public const array KINDS = ['mention', 'assigned', 'comment', 'review_due', 'task_due',
        'fleet_expiry']; // 3.30: a certificate or domain of a site in the fleet console ends soon (Fleet\Calendar, target 'fleet_site:<id>')

    /** The e-mail choices of ka_uzivatele.notify_email; the first is the default. */
    public const array EMAIL = ['instant', 'daily', 'off'];

    /** How long a notification is kept, in days. */
    public const int KEEP_DAYS = 90;

    /** The longest excerpt, in characters (plain text, like the draft comments). */
    public const int EXCERPT = 200;

    /** The daily summary goes out at the first background run after this hour of the site's clock. */
    public const int DIGEST_HOUR = 8;

    /** Notifications per screen. */
    public const int PAGE = 50;

    /** Prefixes of a target string ("enquiry:12", "page:5", "item:9", 3.28 "contact:3", "company:2", 3.30 "fleet_site:4", "fleet_customer:2", 3.31 "deal:7") => the kind of Core\Assignment that opens it. */
    public const array TARGETS = ['enquiry' => 'enquiry', 'page' => 'page', 'item' => 'item', 'contact' => 'contact', 'company' => 'company',
        'fleet_site' => 'fleet_site', 'fleet_customer' => 'fleet_customer', 'deal' => 'deal'];

    /**
     * Notifies a user: records the notification and, when they chose "instant", sends one e-mail now (queued, sent after
     * the response). Nothing for the actor themself, an unknown kind, or an account that is blocked or gone. Never throws –
     * a notification never undoes the work that caused it.
     *
     * @param string $kind mention | assigned | comment | review_due | task_due
     * @param string $target "enquiry:12", "page:5", "item:9", "task:3"
     * @param int|null $actorId who did it (null = Kaleta itself)
     * @param string $excerpt plain text, at most EXCERPT characters are kept (none for an assignment)
     */
    public static function notify(App $app, int $userId, string $kind, string $target, ?int $actorId, string $excerpt = ''): void
    {
        try {
            $note = self::record($app->db(), $userId, $kind, $target, $actorId, $excerpt);
            if ($note !== null && $note['email'] === 'instant') {
                self::mailNow($app, $note);
            }
        } catch (\Throwable) {
            // the bell and the e-mail are a courtesy; the comment or the assignment stands
        }
    }

    /**
     * Records a notification without e-mailing it. Null when nothing was recorded: the actor themself, an unknown kind, a
     * blocked or removed account, or the table missing (before migration 0112).
     *
     * @return array{id: int, user: array<string, mixed>, kind: string, target: string, actor_name: string, excerpt: string, email: string}|null
     */
    public static function record(Db $db, int $userId, string $kind, string $target, ?int $actorId, string $excerpt = ''): ?array
    {
        $actorId = $actorId !== null && $actorId > 0 ? $actorId : null;
        // a reminder of my own task is still mine to get (3.27): only task_due reaches the actor themself
        if ($userId <= 0 || ($actorId === $userId && $kind !== 'task_due') || !in_array($kind, self::KINDS, true)) {
            return null;
        }
        try {
            $user = $db->one('SELECT idu, email, jazyk, register, notify_email FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$userId]);
            if ($user === null) {
                return null;
            }
            $actor = $actorId !== null ? (string) $db->value("SELECT IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE idu = ?", [$actorId]) : '';
            $excerpt = $kind === 'assigned' ? '' : self::excerpt($excerpt);
            $target = mb_substr($target, 0, 40);
            $id = $db->insert('notifications', ['user_id' => $userId, 'kind' => $kind, 'target' => $target, 'actor_id' => $actorId,
                'actor_name' => mb_substr($actor, 0, 100), 'excerpt' => $excerpt, 'created_at' => Clock::now()]);
        } catch (\PDOException) {
            return null;
        }

        return ['id' => $id, 'user' => $user, 'kind' => $kind, 'target' => $target, 'actor_name' => mb_substr($actor, 0, 100), 'excerpt' => $excerpt,
            'email' => self::emailMode((string) ($user['notify_email'] ?? ''))];
    }

    /** The e-mail choice of a user as stored: an unknown or empty value is the default (instant). Pure. */
    public static function emailMode(string $value): string
    {
        return in_array($value, self::EMAIL, true) ? $value : self::EMAIL[0];
    }

    /**
     * An excerpt as stored: the caller's plain text on one line (a "<" is text, escaped wherever it is shown), without
     * control characters, at most EXCERPT characters (an ellipsis when cut). Pure.
     */
    public static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) preg_replace('/\s+/u', ' ', $text)));

        return mb_strlen($text) > self::EXCERPT ? rtrim(mb_substr($text, 0, self::EXCERPT - 1)) . '…' : $text;
    }

    /**
     * Marks notifications as e-mailed (the caller sent the e-mail itself).
     *
     * @param list<int> $ids
     */
    public static function markEmailed(Db $db, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        try {
            $db->run('UPDATE {notifications} SET emailed_at = ? WHERE id IN (' . implode(',', $ids) . ')', [Clock::now()]);
        } catch (\PDOException) {
            // before the migration
        }
    }

    /** How many unread notifications the user has – one COUNT over the key (user_id, read_at, id); 0 before the migration. */
    public static function unreadCount(Db $db, int $userId): int
    {
        try {
            return (int) $db->value('SELECT COUNT(*) FROM {notifications} WHERE user_id = ? AND read_at IS NULL', [$userId]);
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * The user's notifications, newest first, a page of PAGE at a time, each with the place it is about (place()).
     *
     * @return array{rows: list<array{id: int, kind: string, target: string, actor_id: int|null, actor_name: string, excerpt: string, created_at: string, read_at: string|null, place: array<string, mixed>|null}>, more: bool}
     */
    public static function list(Db $db, int $userId, int $page = 1): array
    {
        $page = max(1, $page);
        try {
            $rows = $db->all('SELECT id, kind, target, actor_id, actor_name, excerpt, created_at, read_at FROM {notifications} WHERE user_id = ? ORDER BY id DESC LIMIT '
                . (self::PAGE + 1) . ' OFFSET ' . (($page - 1) * self::PAGE), [$userId]);
        } catch (\PDOException) {
            return ['rows' => [], 'more' => false];
        }
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        $places = self::places($db, array_map(fn (array $r): string => (string) $r['target'], $rows));

        return ['rows' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'target' => (string) $r['target'],
            'actor_id' => $r['actor_id'] !== null ? (int) $r['actor_id'] : null, 'actor_name' => (string) $r['actor_name'], 'excerpt' => (string) $r['excerpt'],
            'created_at' => (string) $r['created_at'], 'read_at' => $r['read_at'] !== null ? (string) $r['read_at'] : null,
            'place' => $places[(string) $r['target']] ?? null], $rows), 'more' => $more];
    }

    /**
     * One notification of the user (for opening it) with its place; null when it is not theirs or does not exist.
     *
     * @return array{id: int, target: string, read_at: string|null, place: array<string, mixed>|null}|null
     */
    public static function one(Db $db, int $userId, int $id): ?array
    {
        try {
            $row = $db->one('SELECT id, target, read_at FROM {notifications} WHERE id = ? AND user_id = ?', [$id, $userId]);
        } catch (\PDOException) {
            return null;
        }

        return $row === null ? null : ['id' => (int) $row['id'], 'target' => (string) $row['target'], 'read_at' => $row['read_at'] !== null ? (string) $row['read_at'] : null,
            'place' => self::places($db, [(string) $row['target']])[(string) $row['target']] ?? null];
    }

    /** Marks one notification of the user as read. Returns whether it changed. */
    public static function markRead(Db $db, int $userId, int $id): bool
    {
        try {
            return $db->run('UPDATE {notifications} SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL', [Clock::now(), $id, $userId])->rowCount() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /** Marks every notification of the user as read. Returns how many changed. */
    public static function markAllRead(Db $db, int $userId): int
    {
        try {
            return $db->run('UPDATE {notifications} SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Clock::now(), $userId])->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Notifications about the given targets – deleted ($blank false) or with the excerpt blanked ($blank true, an
     * anonymised enquiry: its comments lose their text, so do the notifications quoting them). Called by
     * Comments::forgetEnquiries and anonymiseEnquiries.
     *
     * @param list<string> $targets
     */
    public static function forgetTargets(Db $db, array $targets, bool $blank = false): int
    {
        $targets = array_values(array_unique(array_filter($targets, fn (string $t): bool => $t !== '')));
        $changed = 0;
        try {
            foreach (array_chunk($targets, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $changed += $blank ? $db->run("UPDATE {notifications} SET excerpt = '' WHERE target IN (" . $in . ')', $chunk)->rowCount()
                    : $db->run('DELETE FROM {notifications} WHERE target IN (' . $in . ')', $chunk)->rowCount();
            }
        } catch (\PDOException) {
            // before the migration
        }

        return $changed;
    }

    /**
     * A comment was edited or deleted (Core\Comments): the notifications of its thread that quote its old text quote the new
     * one ('' for a deleted comment). The quote is compared as stored (excerpt()). Returns how many changed.
     */
    public static function requote(Db $db, string $target, string $old, string $new): int
    {
        $old = self::excerpt($old);
        if ($old === '') {
            return 0;
        }
        try {
            return $db->run("UPDATE {notifications} SET excerpt = ? WHERE target = ? AND excerpt = ? AND kind IN ('mention', 'comment')", [self::excerpt($new), mb_substr($target, 0, 40), $old])->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Where notifications point: per target the kind (enquiry | page | item), the id, a title (the form name of an enquiry
     * – never its fields –, the title of a page, the name and the collection of an item) and the admin path. A target that
     * no longer exists (deleted, in the trash) or is unknown is left out. Batched: one query per kind.
     *
     * @param list<string> $targets
     * @return array<string, array{kind: string, id: int, title: string, collection?: string, path: string}>
     */
    public static function places(Db $db, array $targets): array
    {
        $ids = [];
        foreach (array_unique($targets) as $target) {
            $parsed = self::parse($target);
            if ($parsed !== null) {
                $ids[$parsed[0]][] = $parsed[1];
            }
        }
        $out = [];
        if (isset($ids['enquiry'])) {
            foreach ($db->all('SELECT idp, formular FROM {poptavky} WHERE idp IN (' . implode(',', array_unique($ids['enquiry'])) . ')') as $r) {
                $out['enquiry:' . (int) $r['idp']] = ['kind' => 'enquiry', 'id' => (int) $r['idp'], 'title' => (string) $r['formular'],
                    'path' => 'admin.php?module=enquiries&action=detail&id=' . (int) $r['idp']];
            }
        }
        if (isset($ids['page'])) {
            foreach ($db->all('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL AND ids IN (' . implode(',', array_unique($ids['page'])) . ')') as $r) {
                $out['page:' . (int) $r['ids']] = ['kind' => 'page', 'id' => (int) $r['ids'], 'title' => (string) $r['titulek'], 'path' => 'admin.php?module=pages&action=edit&id=' . (int) $r['ids']];
            }
        }
        if (isset($ids['item'])) {
            foreach ($db->all('SELECT p.idp, p.idk, p.nazev, k.nazev AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NULL AND p.idp IN ('
                . implode(',', array_unique($ids['item'])) . ')') as $r) {
                $out['item:' . (int) $r['idp']] = ['kind' => 'item', 'id' => (int) $r['idp'], 'title' => (string) $r['nazev'], 'collection' => (string) $r['kolekce'],
                    'path' => 'admin.php?module=collections&action=item&id=' . (int) $r['idk'] . '&item=' . (int) $r['idp']];
            }
        }
        if (isset($ids['contact']) || isset($ids['company'])) {
            $out += Contacts::places($db, $ids['contact'] ?? [], $ids['company'] ?? []); // 3.28: a customer, a company
        }
        if (isset($ids['deal'])) {
            $out += Deals::places($db, $ids['deal']); // 3.31: a deal, named by its number
        }
        if (isset($ids['fleet_site']) || isset($ids['fleet_customer'])) {
            $out += \Kaleta\Fleet\ConsoleTasks::places($db, $ids['fleet_site'] ?? [], $ids['fleet_customer'] ?? []); // 3.30: a site, a customer of a fleet console
        }
        if (isset($ids['task'])) {
            // 3.27: a task about nothing (Core\Tasks) – it is done in My work; a deleted task is gone
            try {
                foreach ($db->all('SELECT id, title FROM {tasks} WHERE id IN (' . implode(',', array_unique($ids['task'])) . ')') as $r) {
                    $out['task:' . (int) $r['id']] = ['kind' => 'task', 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'path' => 'admin.php?module=my_work#task-' . (int) $r['id']];
                }
            } catch (\PDOException) {
                // before migration 0113
            }
        }

        return $out;
    }

    /**
     * A target string as [kind, id] – "enquiry:12", "page:5", "item:9", "task:3" (3.27), "contact:4", "company:2" (3.28),
     * "fleet_site:4", "fleet_customer:2" (3.30: console tasks, calendar reminders), "deal:7" (3.31); null for anything else. Pure.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parse(string $target): ?array
    {
        return preg_match('/^(enquiry|page|item|task|contact|company|fleet_site|fleet_customer|deal):([1-9]\d{0,9})$/D', $target, $m) === 1 ? [$m[1], (int) $m[2]] : null;
    }

    /**
     * What happened, for the list and the e-mails, in the current language: "Jana mentioned you". Pure.
     */
    public static function what(string $kind, string $actor): string
    {
        $actor = $actor !== '' ? $actor : 'Kaleta';

        return match ($kind) {
            'mention' => t('%s mentioned you', $actor),
            'comment' => t('%s commented', $actor),
            'assigned' => t('%s assigned it to you', $actor),
            'review_due' => t('The review date has come'),
            'task_due' => t('A task is due'),
            'fleet_expiry' => t('A date of a site comes up'),
            default => $kind,
        };
    }

    /**
     * Where it happened, in the current language: "Enquiry #12 · Contact", "Page “Services”", "“Oak table” in Products";
     * "(no longer available)" when the place is gone. Pure.
     *
     * @param array<string, mixed>|null $place a value of places()
     */
    public static function where(?array $place): string
    {
        return match ($place['kind'] ?? null) {
            'enquiry' => t('Enquiry #%s · %s', (string) $place['id'], (string) $place['title']),
            'page' => t('Page “%s”', (string) $place['title']),
            'item' => t('“%s” in %s', (string) $place['title'], (string) ($place['collection'] ?? '')),
            'task' => t('Task “%s”', (string) $place['title']),
            'contact' => t('Customer #%s', (string) $place['id']), // 3.28: a number only – an e-mail of the bell never carries a customer's name
            'company' => t('Company “%s”', (string) $place['title']),
            'fleet_site' => t('Site “%s” in the fleet console', (string) $place['title']), // 3.30: console tasks, calendar reminders
            'fleet_customer' => t('Console customer “%s”', (string) $place['title']),
            'deal' => t('Deal #%s', (string) $place['id']), // 3.31: a number only – a deal's title may name a customer
            default => t('(no longer available)'),
        };
    }

    /**
     * The instant e-mail – subject and text in the recipient's administration language (the site's when they chose none):
     * who did what where, the excerpt in quotes when there is one (never for an assignment), the link, and where to change
     * the choice. Pure apart from the dictionaries.
     *
     * @param array<string, mixed> $recipient a row of ka_uzivatele with jazyk and register
     * @param array{kind: string, actor_name: string, excerpt: string} $note
     * @param array<string, mixed>|null $place
     * @return array{0: string, 1: string}
     */
    public static function mail(Settings $s, array $recipient, array $note, ?array $place, string $url, string $accountUrl): array
    {
        return Language::runWith((string) ($recipient['jazyk'] ?? '') ?: Language::defaults($s), function () use ($note, $place, $url, $accountUrl): array {
            $actor = $note['actor_name'] !== '' ? $note['actor_name'] : 'Kaleta';
            $where = self::where($place);
            $subject = match ($note['kind']) {
                'mention' => t('%s mentioned you: %s', $actor, $where),
                'comment' => t('%s commented on %s', $actor, $where),
                'assigned' => t('%s assigned you %s', $actor, $where),
                'task_due' => t('Task due: %s', $note['excerpt'] !== '' ? $note['excerpt'] : $where),
                default => t('Review by today: “%s”', (string) ($place['title'] ?? $where)),
            };
            $text = self::what($note['kind'], $note['actor_name']) . ': ' . $where . "\n";
            if ($note['excerpt'] !== '' && $note['kind'] !== 'assigned' && mb_strlen($note['excerpt']) <= self::EXCERPT) {
                $text .= "\n“" . $note['excerpt'] . "”\n";
            }
            $text .= "\n" . t('Open it in the administration:') . "\n" . $url . "\n\n-- \n"
                . t('This e-mail came right away because of your choice in My account. You can have one summary a day instead, or none:') . "\n" . $accountUrl . "\n";

            return [$subject, $text];
        }, 'admin-', Language::normalizeRegister((string) ($recipient['register'] ?? '')));
    }

    /**
     * The daily summary – subject and text in the recipient's administration language: each notification on its own lines
     * (what, where, the excerpt, the link), then the Notifications screen and where to change the choice. Pure apart from
     * the dictionaries.
     *
     * @param array<string, mixed> $recipient
     * @param list<array{kind: string, actor_name: string, excerpt: string, url: string, place: array<string, mixed>|null}> $notes
     * @return array{0: string, 1: string}
     */
    public static function digest(Settings $s, array $recipient, array $notes, string $inboxUrl, string $accountUrl): array
    {
        return Language::runWith((string) ($recipient['jazyk'] ?? '') ?: Language::defaults($s), function () use ($s, $notes, $inboxUrl, $accountUrl): array {
            $lines = [];
            foreach ($notes as $n) {
                $lines[] = '- ' . self::what($n['kind'], $n['actor_name']) . ' – ' . self::where($n['place'])
                    . ($n['excerpt'] !== '' && $n['kind'] !== 'assigned' ? "\n  “" . $n['excerpt'] . '”' : '') . ($n['url'] !== '' ? "\n  " . $n['url'] : '');
            }
            $site = $s->get('site_name');

            return [count($notes) === 1 ? t('1 new notification – %s', $site) : t('%d new notifications – %s', count($notes), $site),
                t('What happened since the last summary:') . "\n\n" . implode("\n\n", $lines) . "\n\n" . t('All notifications:') . "\n" . $inboxUrl . "\n\n-- \n"
                . t('You chose one summary a day in My account. You can have each notification right away instead, or none:') . "\n" . $accountUrl . "\n"];
        }, 'admin-', Language::normalizeRegister((string) ($recipient['register'] ?? '')));
    }

    /**
     * The hourly background job (Core\Scheduler, 'inbox'): once a day, at the first run after DIGEST_HOUR on the site's
     * clock, the clean-up and the summary for everyone who chose "daily" – one e-mail with their unread notifications not
     * e-mailed yet, which are then marked e-mailed. The day is written first, so a failure never sends a summary twice.
     * $now only for tests (tools/test.sh runs it at a fixed hour).
     */
    public static function run(App $app, ?\DateTimeImmutable $now = null): string
    {
        $s = $app->settings();
        $now ??= new \DateTimeImmutable();
        if (!self::due($s->get('inbox_digest_day'), $now)) {
            return 'not due';
        }
        $s->set('inbox_digest_day', $now->format('Y-m-d'));
        $db = $app->db();
        $purged = self::purge($db);
        if (Demo::active()) {
            return 'purged ' . $purged;
        }
        $base = rtrim($s->get('site_url') ?: $app->request->origin(), '/');
        $sent = 0;
        try {
            $users = $db->all("SELECT idu, email, jazyk, register FROM {uzivatele} WHERE notify_email = 'daily' AND blokovat = 0");
        } catch (\PDOException) {
            return 'purged ' . $purged;
        }
        foreach ($users as $user) {
            if (count(Mail::addresses((string) $user['email'])) !== 1) {
                continue; // the bell only; they stay "not e-mailed" until the person adds an address
            }
            $rows = $db->all('SELECT id, kind, target, actor_name, excerpt FROM {notifications} WHERE user_id = ? AND read_at IS NULL AND emailed_at IS NULL ORDER BY id LIMIT 100', [(int) $user['idu']]);
            if ($rows === []) {
                continue;
            }
            $places = self::places($db, array_map(fn (array $r): string => (string) $r['target'], $rows));
            $notes = array_map(fn (array $r): array => ['kind' => (string) $r['kind'], 'actor_name' => (string) $r['actor_name'], 'excerpt' => (string) $r['excerpt'],
                'place' => $places[(string) $r['target']] ?? null, 'url' => isset($places[(string) $r['target']]) ? $base . $app->url('admin.php?action=inbox&open=' . (int) $r['id']) : ''], $rows);
            [$subject, $text] = self::digest($s, $user, $notes, $base . $app->url('admin.php?action=inbox'), $base . $app->url('admin.php?action=account#notifications'));
            Mail::send($s, (string) $user['email'], $subject, $text); // a background job: a failure is queued for a retry
            self::markEmailed($db, array_map(fn (array $r): int => (int) $r['id'], $rows));
            $sent++;
        }

        return 'purged ' . $purged . ', summaries ' . $sent;
    }

    /** Is the daily run due: DIGEST_HOUR or later on the site's clock, and not yet today. Pure. */
    public static function due(string $lastDay, \DateTimeImmutable $now): bool
    {
        return (int) $now->format('G') >= self::DIGEST_HOUR && $lastDay !== $now->format('Y-m-d');
    }

    /**
     * The clean-up: notifications older than KEEP_DAYS, of accounts that were removed, and about enquiries that are gone
     * (deleted by a path that did not call forgetTargets). Returns how many rows went.
     */
    public static function purge(Db $db): int
    {
        try {
            $deleted = $db->run('DELETE FROM {notifications} WHERE created_at < ?', [Clock::at('-' . self::KEEP_DAYS . ' days')])->rowCount();
            $deleted += $db->run('DELETE FROM {notifications} WHERE NOT EXISTS (SELECT 1 FROM {uzivatele} u WHERE u.idu = user_id)')->rowCount();
            $targets = array_map(fn (array $r): string => (string) $r['target'], $db->all("SELECT DISTINCT target FROM {notifications} WHERE target LIKE 'enquiry:%'"));
            $ids = array_values(array_filter(array_map(fn (string $t): int => self::parse($t)[1] ?? 0, $targets)));
            $existing = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($db->all('SELECT idp FROM {poptavky} WHERE idp IN (' . implode(',', $chunk) . ')') as $r) {
                    $existing['enquiry:' . (int) $r['idp']] = true;
                }
            }

            return $deleted + self::forgetTargets($db, array_values(array_filter($targets, fn (string $t): bool => !isset($existing[$t]))));
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * The instant e-mail of one recorded notification: only to a single valid address, and only when its place exists
     * (a notification about something deleted meanwhile stays in the bell). Queued and sent after the response.
     *
     * @param array{id: int, user: array<string, mixed>, kind: string, target: string, actor_name: string, excerpt: string, email: string} $note
     */
    private static function mailNow(App $app, array $note): void
    {
        $email = (string) ($note['user']['email'] ?? '');
        if (count(Mail::addresses($email)) !== 1 || Demo::active()) {
            return;
        }
        $db = $app->db();
        $place = self::places($db, [$note['target']])[$note['target']] ?? null;
        if ($place === null) {
            return;
        }
        $s = $app->settings();
        $base = rtrim($s->get('site_url') ?: $app->request->origin(), '/');
        [$subject, $text] = self::mail($s, $note['user'], $note, $place, $base . $app->url('admin.php?action=inbox&open=' . $note['id']),
            $base . $app->url('admin.php?action=account#notifications'));
        Mail::later($s, $email, $subject, $text);
        self::markEmailed($db, [$note['id']]);
    }
}
