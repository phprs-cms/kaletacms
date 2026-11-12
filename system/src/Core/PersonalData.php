<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Personal data requests (2.14): someone writes "what do you have about me" or "delete me". The administrator enters the
 * e-mail address once and sees everything the site keeps about it, can give it to the person as a file (the right of
 * access and portability) or erase it (the right to erasure) – instead of searching enquiries, subscribers and queues
 * one by one on each site.
 *
 *  - Found: enquiries with the address as the sender or as the whole value of one of their fields (3.35, N94: exact
 *    match – an enquiry of someone else that merely mentions the address, or a longer address containing it, is not the
 *    person's and is never exported or erased with it), the subscription and its pending
 *    sync to the mailing service, e-mails still in the outgoing queue, testimonial requests, bookings of appointments
 *    (3.0, Core\Booking), and an account of the administration (shown only – accounts are removed in Users, never here).
 *  - 3.28: the customer records (Core\Contacts) of the address, of the enquiries, bookings and subscription found and of a
 *    merge note naming exactly the address (a merge's note keeps the address merged in – N328-6; 3.35, N94: Kaleta's own
 *    merge note only, Contacts::mergeNoteNames) – with
 *    the company they work for (its name only: a company is not a person's data and is never erased here), the team's log
 *    of calls, meetings, e-mails and notes, and the team's comments and tasks about the contact. Erasing deletes them all.
 *    3.31: the file lists the deals with the customer (Core\Deals) with the team's comments and tasks on each; erasing
 *    keeps a deal for the pipeline without the person – no contact, no lost note, a title that names them becomes "#<id>",
 *    the comments on it go and the titles of its tasks are blanked.
 *  - Erasing deletes the enquiries with their attachments, internal notes (3.25) and tasks (3.27), the subscriber with the newsletter queue rows, the pending
 *    e-mails and the testimonial requests; when a mailing service is connected, the address is also removed there
 *    through the usual queue. A published testimonial stays – it is content the person agreed to publish; the result
 *    names it so the administrator can remove it too.
 *  - 3.34: job applications (Core\Applications) sent from the address (their own e-mail field only) – with the replies, the
 *    team's notes and tasks, the status history and the CVs by name and size (never the file); erasing deletes them with
 *    their sealed files. Personal data requests are for administrators, who may open applications anyway (B-3).
 *  - 3.38: quotes (Core\QuoteDelivery::ofPerson) sent to the address or made for one of the person's customer records, with
 *    their lines – the person's own quotes; erasing deletes their drafts and keeps a numbered quote as the firm's record
 *    without the person (the customer block, the frozen document and the customer's link go).
 *  - The change log and the event keep only a masked address and the counts, never the address itself.
 *  - The undo journal of Claude sessions (Core\AgentJournal) does not journal these tables; entries an older release
 *    journaled are redacted on erasure (3.3.2), so undoing a session never brings erased data back.
 */
final class PersonalData
{
    /** The address as stored: trimmed, lower case; null when it is not an e-mail address. */
    public static function normalise(string $email): ?string
    {
        $email = self::cleanAddress($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * 3.35 review L5: an address as it is compared – without any space (also the no-break space, the narrow and the
     * zero-width ones and the other Unicode spaces, which an address never has but a pasted value often does) and in
     * lower case. Pure.
     */
    public static function cleanAddress(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{Z}\x{180E}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $value));
    }

    /** j***@example.com – enough to recognise a request in the log, not enough to read the address. */
    public static function mask(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    /**
     * 3.35 (N94): is an enquiry the person's – sent from the address, or with a field whose whole value is the address (a
     * colleague's e-mail field)? Letter case and surrounding spaces aside; a value that only contains it is not. Pure.
     *
     * @param string $dataJson the enquiry's fields as stored: [[label, value], …]
     */
    public static function ofEnquiry(string $sender, string $dataJson, string $email): bool
    {
        if ($email === '') {
            return false;
        }
        if (self::cleanAddress($sender) === $email) { // 3.35 review L5: Unicode spaces and case aside
            return true;
        }
        foreach ((array) (json_decode($dataJson, true) ?: []) as $field) {
            $value = is_array($field) ? ($field[1] ?? null) : null;
            if (is_string($value) && self::cleanAddress($value) === $email) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything the site keeps about the address.
     *
     * @return array{enquiries: list<array<string, mixed>>, subscriber: ?array<string, mixed>, sync: list<array<string, mixed>>, mail: list<array<string, mixed>>, testimonials: list<array<string, mixed>>, bookings: list<array<string, mixed>>, account: ?array<string, mixed>, contacts: list<array<string, mixed>>, applications: list<array<string, mixed>>, quotes: list<array<string, mixed>>}
     */
    public static function find(Db $db, string $email, ?App $app = null): array
    {
        $like = '%' . addcslashes($email, '%_\\') . '%';
        $subscriber = $db->one('SELECT ido, email, stav, zdroj, kampan, vstup, datum, potvrzeno, sync FROM {odberatele} WHERE LOWER(email) = ?', [$email]);
        try {
            $bookings = $db->all('SELECT b.id, b.starts_at, b.ends_at, b.name, b.email, b.phone, b.note, b.status, b.created_at, s.name AS service, p.name AS staff FROM {bookings} b LEFT JOIN {booking_services} s ON s.id = b.service_id LEFT JOIN {booking_staff} p ON p.id = b.staff_id WHERE LOWER(b.email) = ? ORDER BY b.id', [$email]);
        } catch (\Throwable) {
            $bookings = []; // before the 3.0 migration
        }

        // 3.35 (N94): the LIKE only narrows the rows – an enquiry is the person's when it came from the address or one of its
        // fields holds exactly the address (ofEnquiry), never because a longer address or a message contains it
        $enquiries = array_values(array_filter($db->all('SELECT idp, datum, formular, stranka, tema, email, data, kampan, vstup, odkud, replies FROM {poptavky} WHERE LOWER(email) = ? OR LOWER(email) LIKE ? OR LOWER(data) LIKE ? ORDER BY idp', [$email, $like, $like]),
            fn (array $r): bool => self::ofEnquiry((string) $r['email'], (string) $r['data'], $email)));
        // 3.28: the customer records of the address and of what was found (Core\Contacts) – an enquiry that only mentions the
        // address in a field is not the person's own, so only those sent from it count
        $contactIds = Contacts::ofPerson($db, $email, array_map('intval', array_column(array_filter($enquiries, fn (array $r): bool => self::cleanAddress((string) $r['email']) === $email), 'idp')),
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
            // 3.34: the job applications (their attachment list is sealed – opening it needs the settings of the App)
            'applications' => $app !== null ? Applications::ofEmail($app, $email) : [],
            // 3.38: the quotes made for the person (their address or one of their customer records), with the lines they were offered
            'quotes' => QuoteDelivery::ofPerson($db, $email, $contactIds),
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
        $deals = Deals::ofContacts($db, $ids); // 3.31
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
                'tasks' => array_map(fn (array $t): array => ['title' => $t['title'], 'due_at' => $t['due_at'], 'done_at' => $t['done_at'], 'created_at' => $t['created_at']], Tasks::forTarget($db, $target)),
                // 3.31: the deals with this customer – erasing keeps them for the pipeline, without the person (Deals::unlinkContacts);
                // the team's comments and tasks on each deal, as for the contact itself
                'deals' => array_map(fn (array $d): array => $d + [
                    'notes' => array_map(fn (array $n): array => ['at' => $n['at'], 'by' => $n['by'], 'text' => $n['text']], Comments::of($db, Comments::deal((int) $d['id']))),
                    'tasks' => array_map(fn (array $t): array => ['title' => $t['title'], 'due_at' => $t['due_at'], 'done_at' => $t['done_at'], 'created_at' => $t['created_at']],
                        Tasks::forTarget($db, Comments::deal((int) $d['id'])))], $deals[$id] ?? [])];
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
            'applications' => count($found['applications'] ?? []), // 3.34
            'quotes' => count($found['quotes'] ?? []), // 3.38
        ];
    }

    /** The file for the person: what was found, readable JSON, with the date and the site. */
    public static function export(App $app, string $email): string
    {
        $found = self::find($app->db(), $email, $app);
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
        $found = self::find($db, $email, $app);
        // 3.34: with their sealed CVs, notes and tasks; second review N7: one whose files cannot be located stays and is reported
        Applications::delete($app, array_map('intval', array_column($found['applications'], 'id')), true, $heldApplications);
        QuoteDelivery::erase($db, $found['quotes']); // 3.38: drafts go, a numbered quote stays without the person – before the contacts go
        foreach ($found['contacts'] as $contact) {
            Contacts::delete($app, (int) $contact['id']); // 3.28: with its log, comments and tasks – before the records it links go
        }
        \Kaleta\Admin\Modules\Enquiries::deleteAttachments($found['enquiries'] === [] ? [] : $db->all('SELECT idp, data FROM {poptavky} WHERE idp IN (' . implode(',', array_map('intval', array_column($found['enquiries'], 'idp'))) . ')'), $db);
        Deals::forgetEnquiries($db, array_map('intval', array_column($found['enquiries'], 'idp'))); // 3.31: a deal from one stays, without the link and (3.32) the person's words – before the rows go
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
        if ($heldApplications !== []) {
            $erased['applications'] = max(0, ($erased['applications'] ?? 0) - count($heldApplications));
            $erased['applications_held'] = count($heldApplications); // N7: additive – not erased, their files cannot be located
        }
        $kept = array_values(array_filter(array_map('intval', array_column($found['testimonials'], 'item_id'))));
        \Kaleta\Admin\ChangeLog::write($app, 'enquiries', 'personal_data_erase', self::mask($email) . ' ' . (string) json_encode($erased));
        Events::record($db, 'personal_data.erased', 'info', t('Personal data of one address were erased on request.'), $erased);

        return ['erased' => $erased, 'kept_testimonials' => $kept];
    }
}
