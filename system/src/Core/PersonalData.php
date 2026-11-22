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
 *  - 3.46: waiting or failed webhook and connector deliveries of the person lose their body (N161); the restore ledger keeps
 *    the address's keyed fingerprint, so restoring an older backup erases it again (N156); the result states how long the
 *    data can still be in the backups (N157).
 *  - The undo journal of Claude sessions (Core\AgentJournal) does not journal these tables; entries an older release
 *    journaled are redacted on erasure (3.3.2), so undoing a session never brings erased data back.
 */
final class PersonalData
{
    /** 3.46 (N161): days a given-up webhook or connector delivery keeps its body for the Retry button. */
    public const int RETRY_DAYS = 7;

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
     * @return array{enquiries: list<array<string, mixed>>, subscriber: ?array<string, mixed>, sync: list<array<string, mixed>>, mail: list<array<string, mixed>>, testimonials: list<array<string, mixed>>, bookings: list<array<string, mixed>>, account: ?array<string, mixed>, contacts: list<array<string, mixed>>, applications: list<array<string, mixed>>, quotes: list<array<string, mixed>>, register: list<string>}
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
            // 3.51: the send register (Core\SendRegister) – the address bounced or refused our e-mails; only a fingerprint is kept
            'register' => self::registerKinds($db, $email),
        ];
    }

    /**
     * 3.51: the kinds of the send register an address is on (bounced, refused) – an erasure keeps them, so an erased person
     * is not written to again. @return list<string>
     */
    public static function registerKinds(Db $db, string $email): array
    {
        try {
            $kinds = array_map('strval', array_column($db->all("SELECT kind FROM {restore_ledger} WHERE hash = ? AND kind IN ('bounced', 'refused') ORDER BY kind", [RestoreLedger::fingerprint($db, $email)]), 'kind'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0133

            return [];
        }

        return array_values(array_intersect(SendRegister::KINDS, $kinds));
    }

    /** 3.46.1 (N1): rows per query of candidates() – a restore of a site with many enquiries never holds them all at once. */
    public const int CANDIDATE_BATCH = 500;

    /**
     * 3.46.1 (re-audit M1): every address find() can meet on the site – the candidates a restore checks against the ledger's
     * fingerprints before it erases again (RestoreLedger::people; the erasure itself is erase(), so find() decides what goes).
     * It follows find(): the senders of enquiries and the whole values of their fields, subscribers, customer records and
     * the addresses their merge notes name (the marker merged_email, and the address in Kaleta's older wording), bookings,
     * testimonial requests, the mail queue, job applications, quotes, and any value of a waiting or kept webhook or
     * connector body (forgetQueued). Cleaned, only values with an "@"; an address may come more than once.
     *
     * N1: each source is read in batches of CANDIDATE_BATCH rows by its primary key and the addresses are handed out as they
     * come – nothing is collected, so a site with tens of thousands of enquiries stays within a small memory limit. N4: a
     * source that cannot be read (a table an older backup lacks, a column a migration has not added yet) is logged and
     * skipped; the others are still read.
     *
     * 3.50 N170: a source skipped for any other database error (a lost connection, a lock wait time-out) is named in
     * $skipped, so the restore reports that it could not apply every erasure again; only a missing table or column is
     * skipped silently.
     *
     * @param list<string> $skipped filled with the tables skipped for another error than a missing table or column
     * @return \Generator<int, string>
     */
    public static function candidates(Db $db, array &$skipped = []): \Generator
    {
        $sources = [];
        foreach (['poptavky' => ['idp', 'email'], 'odberatele' => ['ido', 'email'], 'contacts' => ['id', 'email'], 'bookings' => ['id', 'email'],
            'testimonial_requests' => ['id', 'email'], 'posta' => ['idp', 'komu'], 'applications' => ['id', 'email'], 'quotes' => ['id', 'customer_email'],
            'contact_activities' => ['id', 'merged_email']] as $table => [$key, $column]) {
            $sources[] = [$table, $key, $column . " <> ''", $column, fn (string $value): array => [$value]];
        }
        // an enquiry is the person's when one of its fields is exactly the address (ofEnquiry) – a form whose e-mail is an
        // ordinary text field stores no sender
        $sources[] = ['poptavky', 'idp', "data LIKE '%@%'", 'data', fn (string $value): array => array_map(fn (mixed $field): mixed => is_array($field) ? ($field[1] ?? null) : null, (array) (json_decode($value, true) ?: []))];
        // Kaleta's merge note from before the marker: "Merged with the contact #12 (jana@example.com)." in any language
        $sources[] = ['contact_activities', 'id', "kind = 'note' AND text LIKE '%@%'", 'text', fn (string $value): array => preg_match_all('/\(([^\s()]+@[^\s()]+)\)/u', $value, $m) > 0 ? $m[1] : []];
        foreach ([['webhook_deliveries', 'body'], ['connector_queue', 'payload']] as [$table, $column]) {
            $sources[] = [$table, 'id', $column . " LIKE '%@%'", $column, function (string $value): array {
                $data = json_decode($value, true);
                $values = [];
                if (is_array($data)) {
                    array_walk_recursive($data, function (mixed $v) use (&$values): void {
                        $values[] = $v;
                    });
                }

                return $values;
            }];
        }
        foreach ($sources as [$table, $key, $where, $column, $values]) {
            $last = 0;
            do {
                try {
                    $rows = $db->all('SELECT ' . $key . ' AS k, ' . $column . ' AS v FROM {' . $table . '} WHERE ' . $key . ' > ? AND ' . $where . ' ORDER BY ' . $key . ' LIMIT ' . self::CANDIDATE_BATCH, [$last]);
                } catch (\PDOException $e) {
                    // N4: one source that cannot be read (a missing table or column of a half-restored database, or any other
                    // database error) is logged and skipped – the erasures still reach the addresses of the others
                    ErrorLog::write(ErrorLog::exception($e));
                    if (!Db::tableMissing($e)) {
                        $skipped[] = $table;
                    }
                    break;
                }
                foreach ($rows as $row) {
                    $last = (int) $row['k'];
                    foreach ($values((string) $row['v']) as $value) {
                        if (is_string($value) && str_contains($value, '@')) {
                            $email = self::cleanAddress($value);
                            if ($email !== '' && strlen($email) <= 320) {
                                yield $email;
                            }
                        }
                    }
                }
            } while (count($rows) === self::CANDIDATE_BATCH);
        }
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
        \Kaleta\Core\ChangeLog::write($app, 'enquiries', 'personal_data_export', self::mask($email));

        return (string) json_encode(['site' => $app->settings()->get('site_name'), 'email' => $email, 'exported_at' => date('c')] + $found,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Erases what the site keeps about the address (see the class comment); returns the counts of what was removed and
     * the ids of published testimonial items that stay.
     *
     * @return array{erased: array<string, int>, kept_testimonials: list<int>, backup_horizon: array{local_days: int, local_files: int, offsite: bool, offsite_days: int, text: string}, kept: list<array{what: string, until: string, reason: string}>}
     */
    public static function erase(App $app, string $email): array
    {
        $db = $app->db();
        $found = self::find($db, $email, $app);
        // 3.34: with their sealed CVs, notes and tasks; second review N7: one whose files cannot be located stays and is reported
        Applications::delete($app, array_map('intval', array_column($found['applications'], 'id')), true, $heldApplications);
        // 3.49 B: documents linked to the person's customer records stay (company documents) – the links go with the records
        $linkedFiles = InternalFileLinks::countFor($db, 'contact', array_map('intval', array_column($found['contacts'], 'id')));
        QuoteDelivery::erase($db, $found['quotes']); // 3.38: drafts go, a numbered quote stays without the person – before the contacts go
        foreach ($found['contacts'] as $contact) {
            Contacts::delete($app, (int) $contact['id']); // 3.28: with its log, comments and tasks – before the records it links go
        }
        EnquiryFiles::delete($found['enquiries'] === [] ? [] : $db->all('SELECT idp, data FROM {poptavky} WHERE idp IN (' . implode(',', array_map('intval', array_column($found['enquiries'], 'idp'))) . ')'), $db);
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
        // 3.46 (N161): a webhook or connector delivery still waiting (or failed and kept for a retry) loses its body – a
        // retry must never send the erased enquiry on; (N156) the ledger remembers the address, so a restore erases it again
        $blanked = self::forgetQueued($db, $email, array_map('intval', array_column($found['enquiries'], 'idp')));
        RestoreLedger::record($db, 'erased', $email);
        $erased = array_diff_key(self::counts($found), ['account' => 0, 'sync' => 0]);
        if ($blanked > 0) {
            $erased['deliveries'] = $blanked; // additive
        }
        if ($heldApplications !== []) {
            $erased['applications'] = max(0, ($erased['applications'] ?? 0) - count($heldApplications));
            $erased['applications_held'] = count($heldApplications); // N7: additive – not erased, their files cannot be located
        }
        $kept = array_values(array_filter(array_map('intval', array_column($found['testimonials'], 'item_id'))));
        \Kaleta\Core\ChangeLog::write($app, 'enquiries', 'personal_data_erase', self::mask($email) . ' ' . (string) json_encode($erased));
        Events::record($db, 'personal_data.erased', 'info', t('Personal data of one address were erased on request.'), $erased);

        // 3.48 B (legal hold): what stays despite the request – [{what, until, reason}]; 3.49 B: the internal files that were
        // linked to the person's customer records (the links went, the files are the firm's documents – checked by hand)
        // 3.51: an address on the send register keeps its one-way fingerprint there – it is never written to again
        return ['erased' => $erased, 'kept_testimonials' => $kept, 'backup_horizon' => self::backupHorizon($app->settings()),
            'kept' => array_merge($linkedFiles > 0 ? [['what' => 'internal files', 'until' => '', 'reason' => 'company documents – check them by hand']] : [],
                $found['register'] !== [] ? [['what' => 'send register (' . implode(', ', $found['register']) . ')', 'until' => '', 'reason' => 'a one-way fingerprint only, so the address is never written to again']] : [])];
    }

    /**
     * 3.46 (N157): how long the erased data can still be in a backup – the local backups (Backup::KEEP files, none older than
     * Backup::KEEP_DAYS days) and the off-site copies (backup_remote_days; 0 = Kaleta deletes none). A restore erases the
     * address again (Core\RestoreLedger), so the data never comes back to the site.
     *
     * @return array{local_days: int, local_files: int, offsite: bool, offsite_days: int, text: string}
     */
    public static function backupHorizon(Settings $s): array
    {
        $offsite = RemoteBackup::isOn($s);
        $days = $s->int('backup_remote_days');

        return ['local_days' => Backup::KEEP_DAYS, 'local_files' => Backup::KEEP, 'offsite' => $offsite, 'offsite_days' => $offsite ? max(0, $days) : 0,
            'text' => self::horizonText($offsite, $days)];
    }

    /** The backup horizon in words (the erase result, the processing record). */
    public static function horizonText(bool $offsite, int $offsiteDays): string
    {
        return t('The erased data can stay in the backups for up to %d days (at most %d backups on this server).', Backup::KEEP_DAYS, Backup::KEEP)
            . ($offsite ? ' ' . ($offsiteDays > 0 ? t('Off-site copies – the database and the private files removed from the site – are deleted after %d days.', $offsiteDays) : t('Off-site copies are not deleted by Kaleta – the lifecycle rule of the storage decides.')) : '')
            . ' ' . t('A restore of an older backup erases the address again.');
    }

    /**
     * 3.46 (N161): blanks the body of every webhook delivery and connector delivery that carries the address (any value of
     * its JSON is exactly the address) or one of the enquiries, and stops it – waiting, failed or given up. Returns how many.
     *
     * @param list<int> $enquiryIds
     */
    public static function forgetQueued(Db $db, string $email, array $enquiryIds): int
    {
        $email = self::cleanAddress($email);
        $ids = array_flip($enquiryIds);
        $count = 0;
        foreach ([['webhook_deliveries', 'body', 'next_attempt = NULL, body = NULL'], ['connector_queue', 'payload', 'next_attempt = NULL, payload = NULL']] as [$table, $column, $blank]) {
            try {
                $rows = $db->all('SELECT id, ' . $column . ' AS b FROM {' . $table . '} WHERE ' . $column . ' IS NOT NULL');
            } catch (\PDOException $e) {
                Db::rethrowUnlessMissing($e);
                continue;
            }
            foreach ($rows as $row) {
                $data = json_decode((string) $row['b'], true);
                if (is_array($data) && self::queuedFor($data, $email, $ids)) {
                    $count += $db->run('UPDATE {' . $table . '} SET ' . $blank . ' WHERE id = ?', [(int) $row['id']])->rowCount();
                }
            }
        }

        return $count;
    }

    /**
     * Does a queued body belong to the person (pure)? Its enquiry id ("id" of an enquiry webhook, "enquiry" of a connector
     * delivery) is one of theirs, or any of its values is exactly the address.
     *
     * @param array<mixed> $data
     * @param array<int, mixed> $enquiryIds ids as keys
     */
    public static function queuedFor(array $data, string $email, array $enquiryIds): bool
    {
        $enquiry = ($data['udalost'] ?? '') === 'nova_poptavka' ? ($data['id'] ?? null) : ($data['enquiry'] ?? null);
        if (is_int($enquiry) && $enquiry > 0 && isset($enquiryIds[$enquiry])) {
            return true;
        }
        if ($email === '') {
            return false;
        }
        $found = false;
        array_walk_recursive($data, function (mixed $value) use ($email, &$found): void {
            $found = $found || (is_string($value) && self::cleanAddress($value) === $email);
        });

        return $found;
    }

    /**
     * 3.46 (N161): the daily sweep of the delivery queues – a delivered row has no body already; a given-up one keeps its
     * body for RETRY_DAYS (the Retry button), then loses it; every row goes after 30 days.
     */
    public static function sweepQueues(Db $db): int
    {
        $count = 0;
        foreach ([['webhook_deliveries', 'body', 'created', 'delivered'], ['connector_queue', 'payload', 'created_at', 'delivered_at']] as [$table, $column, $created, $delivered]) {
            try {
                $count += $db->run('UPDATE {' . $table . '} SET ' . $column . ' = NULL WHERE ' . $column . ' IS NOT NULL AND next_attempt IS NULL AND (' . $delivered . ' IS NOT NULL OR ' . $created . ' < ?)', [Clock::at('-' . self::RETRY_DAYS . ' days')])->rowCount();
                $db->run('DELETE FROM {' . $table . '} WHERE ' . $created . ' < ?', [Clock::at('-30 days')]);
            } catch (\PDOException $e) {
                Db::rethrowUnlessMissing($e);
            }
        }

        return $count;
    }
}
