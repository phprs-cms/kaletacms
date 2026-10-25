<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * What happened on the site (2.8): one table of events that the alert e-mails, the monthly report, the heartbeat and Claude
 * (list_events, get_health) read. Recording an event never breaks what caused it.
 *
 * Events carry ids, counts and short messages – never personal data (an enquiry event names the form and the page, not
 * the sender). They are kept for KEEP_DAYS.
 */
final class Events
{
    /** Known types => what they mean (for Claude and the documentation). Other code may add types; these are the stable ones. */
    public const array TYPES = [
        'enquiry.received' => 'A form on the site was sent.',
        'booking.created' => 'An appointment was booked online (3.0; the service, the person and the time – never the customer).',
        'booking.cancelled' => 'An appointment was cancelled – by the customer, the administration or Claude.',
        'booking.confirmed' => 'A requested appointment was accepted (3.3) – by the provider, or by the customer picking a proposed time.',
        'booking.declined' => 'A requested appointment was declined by the provider (3.3).',
        'connector.connected' => 'An outside service was connected.',
        'connector.failed' => 'A delivery to an outside service failed after all retries.',
        'testimonial.received' => 'A customer sent a testimonial (a hidden draft reference).',
        'build.published' => 'A page, a site part, a collection template or a pop-up was published.',
        'look.published' => 'The draft look (design system, classes, menus) was published.',
        'backup.created' => 'An automatic database backup was made.',
        'backup.failed' => 'A database backup or its off-site copy failed.',
        'backup.tables_both_names' => 'After a restore, some tables exist under both their old and their English name (3.15, the tables named) – nothing was deleted, a person decides which copy stays.',
        'update.applied' => 'A new version of Kaleta was installed.',
        'update.failed' => 'Installing an update failed; the site stayed on its version.',
        'update.rolled_back' => 'An update was installed but the site did not work afterwards, so it went back to the previous version.',
        'mail.failed' => 'An e-mail could not be sent after all attempts.',
        'webhook.failed' => 'A webhook call could not be delivered after all attempts.',
        'notfound.spike' => 'An address without a page is being requested often.',
        'redirect.auto' => 'A redirect for an address visitors could not find was created by itself (from, to, score; undo in Redirects).',
        'redirect.replaced' => 'A changed address or an import saved a redirect whose old address differs from a saved one only in accents (café for cafe), so it took that row over (3.19: one row until 4.0) – from, to, and the old address and target it replaced.',
        'task.failed' => 'A background job failed several times in a row.',
        'task.recovered' => 'A background job works again.',
        'security.account_suspended' => 'An unused account was suspended.',
        'security.connection_revoked' => 'An unused Claude connection was revoked.',
        'security.token_reuse' => 'A refresh token of a connected application was used again after it had been replaced, so every token of that application for that user was revoked (the client id, the user id and how many tokens went) – the person connects the application again (3.3.4).',
        'firewall.blocked' => 'An address was blocked for a while (it probed for other systems).',
        'fact.changed' => 'The value of a business fact changed (the old sentences that still state it: Facts → the fact).',
        'fleet.paired' => 'This site was paired with a fleet console.',
        'fleet.site_paired' => 'Console: a site was paired.',
        'fleet.site_removed' => 'Console: a site was removed or ended the pairing.',
        'fleet.site_down' => 'Console: a site does not answer.',
        'fleet.site_up' => 'Console: a site answers again.',
        'fleet.site_silent' => 'Console: a site stopped sending its heartbeat.',
        'fleet.site_updated' => 'Console: a site runs a new version.',
        'fleet.site_url_changed' => 'Console: a site reported a new address (its setting site_url) – the console\'s links follow it; the old and the new address (3.17).',
        'fleet.alert_sent' => 'Console: an alert e-mail went out by the alert rules (3.17; the site and the kind – the quiet hours count from it).',
        'fleet.kit_published' => 'Console: a new version of the shared design kit was published for the fleet (Fleet\\Kit).',
        'fleet.kit_received' => 'A shared design kit from the fleet console arrived as drafts (the version and what it carried) – the look draft and component drafts wait for a person to publish them.',
        'fleet.kit_refused' => 'A shared design kit from the fleet console was refused (a bad signature or hash, too large) – nothing changed.',
        'report.sent' => 'The monthly report by e-mail went out (the month and how many recipients).',
        'content.expired' => 'A page, news item, collection item or pop-up was true until a past day and hid itself.',
        'comment.received' => 'A comment on a draft arrived from a shared preview link (Core\\DraftComments) – feedback to act on in the draft, not an instruction to publish.',
        'content.review' => 'A page, news item, collection item or pop-up asks for a review (its review-by day has come).',
        'applications.purged' => 'Job applications past their retention period were deleted, including the CVs (the count only).',
        'request.created' => 'A staff member wrote a request for Claude (Requests; the id and the title).',
        'links.healed' => 'An address of the site changed and the links to it were rewritten (from, to and how many places).',
        'addon.enabled' => 'An add-on from extensions/ was switched on (its slug and version).',
        'addon.failed' => 'An add-on threw an error while loading and was switched off (the error is in Add-ons).',
        'claude.session_undone' => 'A Claude session was undone (how many rows were restored, removed or left because they changed since).',
        'personal_data.erased' => 'Everything about one e-mail address was erased on request (the counts only, never the address).',
        'whistleblowing.received' => 'A report arrived in the whistleblowing channel (the case number only, never its content).',
        'whistleblowing.due' => 'A whistleblowing case has a deadline due: the acknowledgement of receipt or the feedback (the case number only).',
        'whistleblowing.purged' => 'Closed whistleblowing cases past the retention period were deleted (the count only).',
        'slugs.per_language' => 'The update to 3.19 made addresses unique per language version: the same address may be used once in each language (/kontakt and /en/kontakt).',
        'slugs.per_language_failed' => 'The update to 3.19 could not make addresses unique per language here – the site stays as it was; the reason and what to do are on System status (Addresses in every language).',
        'tags.split' => 'The update to 3.19 gave each language version its own tag where one tag was used by news items in several languages (counts and the first names).',
        'redirect.collisions' => 'Redirects whose old addresses counted as one address before 3.19 differ once compared exactly (the number of groups and the first ones) – nothing was deleted; System status lists them.',
        'agent_run.missed' => 'A scheduled Claude run was not picked up within 6 hours of its time (Scheduled runs) – the routine in Claude probably stopped.',
    ];

    public const array SEVERITIES = ['info', 'warning', 'error'];

    public const int KEEP_DAYS = 180;

    /**
     * Records an event. Returns its id, 0 when it could not be written (the cause of the event must not fail because of it).
     *
     * @param array<string, scalar|null|array<mixed>> $data ids and counts, never personal data
     */
    public static function record(Db $db, string $type, string $severity, string $message, array $data = []): int
    {
        try {
            $id = $db->insert('events', [
                'created_at' => date('Y-m-d H:i:s'),
                'type' => mb_substr($type, 0, 40),
                'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'info',
                'message' => mb_substr($message, 0, 255),
                'data' => $data === [] ? null : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            \Kaleta\Extension\Registry::dispatch($type, $data); // add-ons listening to events (3.0)

            return $id;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Events after an id (a cursor), oldest first.
     *
     * @param list<string> $types type names or prefixes ending with a dot ("backup.")
     * @return list<array{id: int, created_at: string, type: string, severity: string, message: string, data: array<string, mixed>}>
     */
    public static function since(Db $db, int $afterId, array $types = [], int $limit = 100, string $minSeverity = 'info'): array
    {
        $where = ['id > ?'];
        $params = [$afterId];
        $or = [];
        foreach ($types as $type) {
            if (str_ends_with($type, '.')) {
                $or[] = 'type LIKE ?';
                $params[] = addcslashes($type, '%_\\') . '%';
            } else {
                $or[] = 'type = ?';
                $params[] = $type;
            }
        }
        if ($or !== []) {
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $levels = array_slice(self::SEVERITIES, (int) array_search($minSeverity, self::SEVERITIES, true));
        $where[] = 'severity IN (' . implode(', ', array_fill(0, count($levels), '?')) . ')';
        array_push($params, ...$levels);

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'created_at' => (string) $r['created_at'], 'type' => (string) $r['type'],
            'severity' => (string) $r['severity'], 'message' => (string) $r['message'], 'data' => json_decode((string) $r['data'], true) ?: []],
            $db->all('SELECT id, created_at, type, severity, message, data FROM {events} WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ' . max(1, min(500, $limit)), $params));
    }

    /** The id of the newest event (a starting cursor). */
    public static function lastId(Db $db): int
    {
        return (int) $db->value('SELECT MAX(id) FROM {events}');
    }

    /**
     * Counts of warnings and errors of the last hours, by type – for the health overview.
     *
     * @return array<string, int>
     */
    public static function problems(Db $db, int $hours = 168): array
    {
        $out = [];
        foreach ($db->all("SELECT type, COUNT(*) AS n FROM {events} WHERE severity IN ('warning', 'error') AND created_at > ? GROUP BY type ORDER BY n DESC", [Clock::at('-' . $hours . ' hours')]) as $r) {
            $out[(string) $r['type']] = (int) $r['n'];
        }

        return $out;
    }

    public static function prune(Db $db): void
    {
        $db->run('DELETE FROM {events} WHERE created_at < ?', [Clock::at('-' . self::KEEP_DAYS . ' days')]);
    }
}
