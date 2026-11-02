<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Personal data requests (2.14): someone writes "what do you have about me" or "delete me". The administrator enters the
 * e-mail address once and sees everything the site keeps about it, can give it to the person as a file (the right of
 * access and portability) or erase it (the right to erasure) – instead of searching enquiries, subscribers and queues
 * one by one on each site.
 *
 *  - Found: enquiries with the address as the sender or anywhere in their fields, the subscription and its pending
 *    sync to the mailing service, e-mails still in the outgoing queue, testimonial requests, bookings of appointments
 *    (3.0, Core\Booking), and an account of the administration (shown only – accounts are removed in Users, never here).
 *  - 3.28: the customer records (Core\Contacts) of the address and of the enquiries, bookings and subscription found – with
 *    the company they work for (its name only: a company is not a person's data and is never erased here), the team's log
 *    of calls, meetings, e-mails and notes, and the team's comments and tasks about the contact. Erasing deletes them all.
 *  - Erasing deletes the enquiries with their attachments, internal notes (3.25) and tasks (3.27), the subscriber with the newsletter queue rows, the pending
 *    e-mails and the testimonial requests; when a mailing service is connected, the address is also removed there
 *    through the usual queue. A published testimonial stays – it is content the person agreed to publish; the result
 *    names it so the administrator can remove it too.
 *  - The change log and the event keep only a masked address and the counts, never the address itself.
 *  - The undo journal of Claude sessions (Core\AgentJournal) does not journal these tables; entries an older release
 *    journaled are redacted on erasure (3.3.2), so undoing a session never brings erased data back.
 */
final class PersonalData
{
    /** The address as stored: trimmed, lower case; null when it is not an e-mail address. */
    public static function normalise(string $email): ?string
    {
        $email = mb_strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** j***@example.com – enough to recognise a request in the log, not enough to read the address. */
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    /**
     * Everything the site keeps about the address.
     *
     * @return array{enquiries: list<array<string, mixed>>, subscriber: ?array<string, mixed>, sync: list<array<string, mixed>>, mail: list<array<string, mixed>>, testimonials: list<array<string, mixed>>, bookings: list<array<string, mixed>>, account: ?array<string, mixed>, contacts: list<array<string, mixed>>}
     */
    public static function find(Db $db, string $email): array
    {
        $like = '%' . addcslashes($email, '%_\\') . '%';
        $subscriber = $db->one('SELECT ido, email, stav, zdroj, kampan, vstup, datum, potvrzeno, sync FROM {odberatele} WHERE LOWER(email) = ?', [$email]);
        try {
            $bookings = $db->all('SELECT b.id, b.starts_at, b.ends_at, b.name, b.email, b.phone, b.note, b.status, b.created_at, s.name AS service, p.name AS staff FROM {bookings} b LEFT JOIN {booking_services} s ON s.id = b.service_id LEFT JOIN {booking_staff} p ON p.id = b.staff_id WHERE LOWER(b.email) = ? ORDER BY b.id', [$email]);
        } catch (\Throwable) {
            $bookings = []; // before the 3.0 migration
        }

        $enquiries = $db->all('SELECT idp, datum, formular, stranka, tema, email, data, kampan, vstup, odkud, replies FROM {poptavky} WHERE LOWER(email) = ? OR LOWER(data) LIKE ? ORDER BY idp', [$email, $like]);
        // 3.28: the customer records of the address and of what was found (Core\Contacts) – an enquiry that only mentions the
        // address in a field is not the person's own, so only those sent from it count
        $contactIds = Contacts::ofPerson($db, $email, array_map('intval', array_column(array_filter($enquiries, fn (array $r): bool => mb_strtolower((string) $r['email']) === $email), 'idp')),
            array_map('intval', array_column($bookings, 'id')), $subscriber !== null ? (int) $subscriber['ido'] : null);

        return [
            // the sender's address, or the address typed into any field of the form (a colleague's e-mail field)
            // the replies sent to the person (3.22) go with their enquiry: the subject, the text, who and when; so do the
            // team's internal notes on it (3.25, Core\Comments) – data about the person, part of the right of access
            'enquiries' => array_map(fn (array $r): array => ['data' => json_decode((string) $r['data'], true) ?: [],
                'replies' => array_map(fn (array $x): array => ['at' => $x['at'], 'by' => $x['by'], 'subject' => $x['subject'], 'text' => $x['text']], EnquiryReply::decode($r['replies'])),
                'notes' => array_map(fn (array $n): array => ['at' => $n['at'], 'by' => $n['by'], 'text' => $n['text']], Comments::of($db, Comments::enquiry((int) $r['idp']))),
                // 3.27: the team's tasks about it (a follow-up may name the person) – deleted with it like the notes
                'tasks' => array_map(fn (array $t): array => ['title' => $t['title'], 'due_at' => $t['due_at'], 'done_at' => $t['done_at'], 'created_at' => $t['created_at']],
                    Tasks::forTarget($db, Comments::enquiry((int) $r['idp'])))] + $r,
                $enquiries),
            'subscriber' => $subscriber,
            'sync' => $db->all('SELECT akce, vytvoreno, pokusy FROM {odber_fronta} WHERE LOWER(email) = ?', [$email]),
            'mail' => $db->all('SELECT idp, predmet, vytvoreno, odeslano FROM {posta} WHERE LOWER(komu) = ? ORDER BY idp', [$email]),
            'testimonials' => $db->all('SELECT id, idp, created_at, used_at, item_id, consent FROM {testimonial_requests} WHERE LOWER(email) = ? ORDER BY id', [$email]),
            'bookings' => $bookings,
            'account' => $db->one('SELECT idu, jmeno, email FROM {uzivatele} WHERE LOWER(email) = ?', [$email]),
            'contacts' => self::contacts($db, $contactIds),
        ];
    }

    /**
     * The customer records for the file (3.28): what the contact holds, its company's name, the team's log, comments and tasks.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function contacts(Db $db, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $c = Contacts::find($db, $id);
            if ($c === null) {
                continue;
            }
            $target = Comments::contact($id);
            $out[] = ['id' => $c['id'], 'email' => $c['email'], 'name' => $c['name'], 'phone' => $c['phone'], 'company' => $c['company_name'], 'language' => $c['language'],
                'source' => $c['source'], 'summary' => $c['summary'], 'first_seen_at' => $c['first_seen_at'], 'last_activity_at' => $c['last_activity_at'], 'created_at' => $c['created_at'],
                'activities' => array_map(fn (array $a): array => ['at' => $a['at'], 'kind' => $a['kind'], 'by' => $a['by'], 'text' => $a['text']], Contacts::activities($db, $id, 1000)),
                'notes' => array_map(fn (array $n): array => ['at' => $n['at'], 'by' => $n['by'], 'text' => $n['text']], Comments::of($db, $target)),
                'tasks' => array_map(fn (array $t): array => ['title' => $t['title'], 'due_at' => $t['due_at'], 'done_at' => $t['done_at'], 'created_at' => $t['created_at']], Tasks::forTarget($db, $target))];
        }

        return $out;
    }

    /** How many records of each kind – for the screen, for Claude and for the log (no content). @return array<string, int> */
    public static function counts(array $found): array
    {
        return [
            'enquiries' => count($found['enquiries']),
            'subscriber' => $found['subscriber'] !== null ? 1 : 0,
            'sync' => count($found['sync']),
            'mail' => count($found['mail']),
            'testimonials' => count($found['testimonials']),
            'bookings' => count($found['bookings']),
            'account' => $found['account'] !== null ? 1 : 0,
            'contacts' => count($found['contacts'] ?? []), // 3.28
        ];
    }

    /** The file for the person: what was found, readable JSON, with the date and the site. */
    public static function export(App $app, string $email): string
    {
        $found = self::find($app->db(), $email);
        unset($found['account']['idu']);
        \Kaleta\Admin\ChangeLog::write($app, 'enquiries', 'personal_data_export', self::mask($email));

        return (string) json_encode(['site' => $app->settings()->get('site_name'), 'email' => $email, 'exported_at' => date('c')] + $found,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Erases what the site keeps about the address (see the class comment); returns the counts of what was removed and
     * the ids of published testimonial items that stay.
     *
     * @return array{erased: array<string, int>, kept_testimonials: list<int>}
     */
    public static function erase(App $app, string $email): array
    {
        $db = $app->db();
        $found = self::find($db, $email);
        foreach ($found['contacts'] as $contact) {
            Contacts::delete($app, (int) $contact['id']); // 3.28: with its log, comments and tasks – before the records it links go
        }
        \Kaleta\Admin\Modules\Enquiries::deleteAttachments($found['enquiries'] === [] ? [] : $db->all('SELECT data FROM {poptavky} WHERE idp IN (' . implode(',', array_map('intval', array_column($found['enquiries'], 'idp'))) . ')'));
        foreach (array_column($found['enquiries'], 'idp') as $idp) {
            $db->delete('poptavky', ['idp' => (int) $idp]);
        }
        Comments::forgetEnquiries($db, array_map('intval', array_column($found['enquiries'], 'idp'))); // 3.25: the team's notes on them too
        if ($found['subscriber'] !== null) {
            if ((int) $found['subscriber']['stav'] === 1) {
                Newsletter::enqueue($app, (string) $found['subscriber']['email'], 'odebrat'); // gone from the mailing service too
            }
            $db->delete('newsletter_queue', ['subscriber_id' => (int) $found['subscriber']['ido']]);
            $db->delete('odberatele', ['ido' => (int) $found['subscriber']['ido']]);
        }
        $db->run('DELETE FROM {posta} WHERE LOWER(komu) = ?', [$email]);
        $db->run('DELETE FROM {testimonial_requests} WHERE LOWER(email) = ?', [$email]);
        if ($found['bookings'] !== []) {
            $db->run('DELETE FROM {bookings} WHERE LOWER(email) = ?', [$email]); // upcoming ones too – the person asked to be forgotten
        }
        AgentJournal::forget($db, $email);
        $erased = array_diff_key(self::counts($found), ['account' => 0, 'sync' => 0]);
        $kept = array_values(array_filter(array_map('intval', array_column($found['testimonials'], 'item_id'))));
        \Kaleta\Admin\ChangeLog::write($app, 'enquiries', 'personal_data_erase', self::mask($email) . ' ' . (string) json_encode($erased));
        Events::record($db, 'personal_data.erased', 'info', t('Personal data of one address were erased on request.'), $erased);

        return ['erased' => $erased, 'kept_testimonials' => $kept];
    }
}
