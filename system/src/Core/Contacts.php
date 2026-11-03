<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Contacts and companies (3.28, tables ka_contacts, ka_companies and ka_contact_activities, migration 0114) – the customer
 * memory of the site: one record per customer across every enquiry, booking and newsletter subscription that shares a
 * normalised e-mail address (PersonalData::normalise), with a timeline, a responsible person, the team's comments and tasks
 * (targets 'contact:<id>' and 'company:<id>' of Core\Comments and Core\Tasks) and the team's own log of calls, meetings,
 * e-mails and notes (activities). The CRM is core – no extension, no switch; the admin section is SECTION
 * (Admin\Modules\Contacts, "Customers" in the Workspace group). The public site never reads contacts.
 *
 *  - Linking: a new enquiry, booking or confirmed subscription is linked after the response (later() + afterResponse() in
 *    index.php and admin.php – the visitor never waits); the job "contacts" (backfill()) links the rows that existed before
 *    3.28 a few hundred at a time and anything a failed after-response link missed. link() creates or attaches by the
 *    normalised address only: two different addresses are never merged by Kaleta itself – a person merges them (merge()).
 *  - Companies: a new contact is attached to an existing company whose domain is its e-mail domain – never for a free-mail
 *    domain (FREE_MAIL, isFreeMail()); a company is never created by itself, suggestCompany() proposes one for the admin.
 *  - Retention (GDPR): the clean-up anonymises contacts with no activity for contacts_months (default = enquiries_months,
 *    migration 0114) – unless they have an open deal (hasOpenDeal(), the hook of 3.29). When an enquiry expires, its contact
 *    keeps "first contact on <date> through <form>" in the summary, without the message (enquiriesExpiring()).
 *  - Personal data: Core\PersonalData finds, exports and erases contacts with their activities, comments and tasks; the
 *    tables are never journaled for undo (AgentJournal::PERSONAL_TABLES) – Claude's changes to a contact cannot be undone.
 *    Core\Handover hands a leaver's contacts and companies over. The site export leaves them out (as it does requests).
 *
 * Messages of \DomainException are translated (t()); MCP calls them inside Language::runWith('en').
 */
final class Contacts
{
    /** The admin section (Module::IDENT of Admin\Modules\Contacts) and the permission row in ka_uzivatele_prava. */
    public const string SECTION = 'contacts';

    /** Where a contact came from (ka_contacts.source). */
    public const array SOURCES = ['enquiry', 'booking', 'subscriber', 'manual', 'import'];

    /** What the team logs on a contact (ka_contact_activities.kind) => the English label (t() in the admin). */
    public const array ACTIVITY_KINDS = ['call' => 'Call', 'meeting' => 'Meeting', 'email' => 'E-mail', 'note' => 'Note'];

    /**
     * The records a contact links to: kind => [table, id column, e-mail column, the date it counts from]. The kinds are also
     * the sources of a contact created by link().
     */
    public const array LINKED = [
        'enquiry' => ['poptavky', 'idp', 'email', 'datum'],
        'booking' => ['bookings', 'id', 'email', 'created_at'],
        'subscriber' => ['odberatele', 'ido', 'email', 'potvrzeno'],
    ];

    /**
     * Free-mail domains: an address there says nothing about the company a person works for, so no company is attached or
     * suggested (owner decision of 3 Nov 2026). The families in FREE_MAIL_FAMILIES cover their country domains.
     */
    public const array FREE_MAIL = ['gmail.com', 'googlemail.com', 'icloud.com', 'me.com', 'mac.com', 'proton.me', 'protonmail.com', 'protonmail.ch', 'pm.me',
        'tutanota.com', 'tutanota.de', 'tuta.io', 'tuta.com', 'zoho.com', 'zohomail.eu', 'mail.com', 'email.com', 'fastmail.com', 'hey.com', 'gmx.net', 'web.de',
        't-online.de', 'freenet.de', 'posteo.de', 'mailbox.org', 'seznam.cz', 'email.cz', 'post.cz', 'spoluzaci.cz', 'centrum.cz', 'atlas.cz', 'volny.cz',
        'tiscali.cz', 'quick.cz', 'azet.sk', 'centrum.sk', 'zoznam.sk', 'post.sk', 'pobox.sk', 'atlas.sk', 'wp.pl', 'o2.pl', 'onet.pl', 'interia.pl', 'op.pl',
        'libero.it', 'virgilio.it', 'tiscali.it', 'alice.it', 'orange.fr', 'wanadoo.fr', 'laposte.net', 'free.fr', 'sfr.fr', 'bluewin.ch', 'mail.ru', 'bk.ru',
        'inbox.ru', 'list.ru', 'rambler.ru', 'ukr.net', 'abv.bg', 'freemail.hu', 'citromail.hu', 'inbox.lv', 'qq.com', '163.com', '126.com', 'naver.com',
        'rediffmail.com', 'telefonica.net', 'terra.com', 'terra.es', 'movistar.es'];

    /** Free-mail providers with a domain per country (yahoo.de, hotmail.co.uk, outlook.cz, gmx.at, yandex.ru…). */
    public const string FREE_MAIL_FAMILIES = '/^(yahoo|ymail|rocketmail|hotmail|outlook|live|msn|windowslive|aol|gmx|yandex)\.[a-z]{2,3}(\.[a-z]{2,3})?$/D';

    /** The linking job links at most this many rows per run (cron); a visit links fewer. */
    public const int BACKFILL_BATCH = 300;

    /** A timeline has at most this many entries (newest first); timeline() takes less. */
    public const int TIMELINE_MAX = 500;

    /** The default length of a timeline. */
    public const int TIMELINE = 100;

    /** Contacts or companies per page of a list. */
    public const int PAGE = 50;

    /** The longest summary, activity text or company note. */
    public const int MAX_TEXT = 5000;

    /** The retention clean-up anonymises at most this many contacts per run (the rest the next day). */
    public const int PURGE_BATCH = 500;

    /** What list() can be sorted by => ORDER BY. */
    public const array SORTS = ['activity' => 'c.last_activity_at IS NULL, c.last_activity_at DESC, c.id DESC', 'name' => 'c.name = \'\', c.name, c.email, c.id',
        'created' => 'c.created_at DESC, c.id DESC'];

    /** @var list<array{0: string, 1: int}> records waiting for afterResponse() */
    private static array $pending = [];

    /* ---------- addresses and companies by domain ---------- */

    /** The address as a contact is keyed by (PersonalData::normalise): trimmed, lower case; null when it is not an e-mail address. */
    public static function normalise(string $email): ?string
    {
        return PersonalData::normalise($email);
    }

    /** The domain of an address, lower case ('' for none). Pure. */
    public static function domainOf(string $email): string
    {
        $norm = self::normalise($email);

        return $norm !== null ? substr($norm, (int) strrpos($norm, '@') + 1) : '';
    }

    /** Is this a free-mail domain (no company is attached or suggested)? Pure. */
    public static function isFreeMail(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        return $domain === '' || in_array($domain, self::FREE_MAIL, true) || preg_match(self::FREE_MAIL_FAMILIES, $domain) === 1;
    }

    /** The domain a company would be known by from this address: its domain, '' for a free-mail one or no address. Pure. */
    public static function companyDomain(string $email): string
    {
        $domain = self::domainOf($email);

        return self::isFreeMail($domain) ? '' : $domain;
    }

    /** A company domain as stored: lower case, without a scheme, "www." or a path; '' when it is not a domain. Pure. */
    public static function cleanDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = (string) preg_replace('#[/?\#].*$#s', '', $domain);
        $domain = (string) preg_replace('/^www\./', '', ltrim($domain, '@'));

        return preg_match('/^(?=.{3,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain) === 1 ? $domain : '';
    }

    /**
     * The company to suggest for an address: an existing company with its domain ({id, name, domain}), otherwise a new one
     * named after the domain ({id: null, name: "Acme", domain: "acme.cz"}); null for a free-mail domain or no address.
     *
     * @return array{id: ?int, name: string, domain: string}|null
     */
    public static function suggestCompany(Db $db, string $email): ?array
    {
        $domain = self::companyDomain($email);
        if ($domain === '') {
            return null;
        }
        $company = self::companyByDomain($db, $domain);
        if ($company !== null) {
            return ['id' => $company['id'], 'name' => $company['name'], 'domain' => $domain];
        }
        $label = explode('.', $domain)[max(0, count(explode('.', $domain)) - 2)];

        return ['id' => null, 'name' => mb_convert_case(str_replace('-', ' ', $label), MB_CASE_TITLE), 'domain' => $domain];
    }

    /**
     * The company with this domain (the oldest when there are more), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function companyByDomain(Db $db, string $domain): ?array
    {
        $domain = self::cleanDomain($domain);
        if ($domain === '') {
            return null;
        }
        try {
            $row = $db->one('SELECT * FROM {companies} WHERE domain = ? ORDER BY id LIMIT 1', [$domain]);
        } catch (\PDOException) {
            return null; // before migration 0114
        }

        return $row === null ? null : self::companyRow($row);
    }

    /* ---------- contacts ---------- */

    /**
     * The contact with this address (not an anonymised one – it has no address), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(Db $db, string $email): ?array
    {
        $norm = self::normalise($email);
        if ($norm === null) {
            return null;
        }
        try {
            $row = $db->one('SELECT c.*, co.name AS company_name FROM {contacts} c LEFT JOIN {companies} co ON co.id = c.company_id WHERE c.email_norm = ?', [$norm]);
        } catch (\PDOException) {
            return null;
        }

        return $row === null ? null : self::row($row);
    }

    /**
     * One contact with the name of its company, or null.
     *
     * @return array{id: int, email: string, email_norm: ?string, name: string, phone: string, company_id: ?int, company_name: ?string, owner_id: ?int, language: string, source: string, summary: string, first_seen_at: ?string, last_activity_at: ?string, created_at: string, updated_at: string, anonymised_at: ?string}|null
     */
    public static function find(Db $db, int $id): ?array
    {
        try {
            $row = $id > 0 ? $db->one('SELECT c.*, co.name AS company_name FROM {contacts} c LEFT JOIN {companies} co ON co.id = c.company_id WHERE c.id = ?', [$id]) : null;
        } catch (\PDOException) {
            return null; // before migration 0114
        }

        return $row === null ? null : self::row($row);
    }

    /**
     * Creates a contact by hand (the admin, MCP save_contact, an import) and returns its id. Fields: email (optional – a
     * contact known only by phone has none), name, phone, company_id, owner_id, language, summary, source (manual by
     * default). An address that already has a contact is refused – it is never a second record of the same person.
     * Notifies the responsible person when it is someone else.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function create(App $app, array $fields, int $byUserId): int
    {
        $db = $app->db();
        $data = self::clean($db, $fields, null);
        if (($data['email_norm'] ?? null) === null && ($data['name'] ?? '') === '' && ($data['phone'] ?? '') === '') {
            throw new \DomainException(t('Give the contact an e-mail address, a name or a phone number.'));
        }
        $source = in_array($fields['source'] ?? '', self::SOURCES, true) ? (string) $fields['source'] : 'manual';
        $now = Clock::now();
        try {
            $id = $db->insert('contacts', $data + ['source' => $source, 'first_seen_at' => $now, 'last_activity_at' => null, 'created_at' => $now, 'updated_at' => $now]);
        } catch (\PDOException $e) {
            throw self::duplicate($db, $e, (string) ($data['email_norm'] ?? ''));
        }
        if (($data['owner_id'] ?? null) !== null) {
            self::notifyOwner($app, 'contact', $id, (int) $data['owner_id'], $byUserId);
        }

        return $id;
    }

    /**
     * Changes the given fields of a contact (only the keys present: email, name, phone, company_id, owner_id, language,
     * summary). A new address must not belong to another contact. An anonymised contact is not changed. A new responsible
     * person is notified.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function update(App $app, int $id, array $fields, int $byUserId): void
    {
        $db = $app->db();
        $contact = self::find($db, $id) ?? throw new \DomainException(t('The contact does not exist.'));
        if ($contact['anonymised_at'] !== null) {
            throw new \DomainException(t('The contact was anonymised – it cannot be changed.'));
        }
        $data = self::clean($db, $fields, $contact);
        if ($data === []) {
            return;
        }
        try {
            $db->update('contacts', $data + ['updated_at' => Clock::now()], ['id' => $id]);
        } catch (\PDOException $e) {
            throw self::duplicate($db, $e, (string) ($data['email_norm'] ?? ''));
        }
        if (array_key_exists('email_norm', $data) && $contact['email_norm'] !== null) {
            self::markMerged($app->settings()); // its records of the old address now belong to a contact of another one
        }
        if (array_key_exists('owner_id', $data) && $data['owner_id'] !== null && $data['owner_id'] !== $contact['owner_id']) {
            self::notifyOwner($app, 'contact', $id, (int) $data['owner_id'], $byUserId);
        }
    }

    /**
     * A page of contacts. Filter keys: search (name, e-mail, phone or company), owner (a user id; 0 = anyone, -1 = nobody),
     * company (a company id), active_since (a DATETIME: last activity on or after it), inactive_before (no activity since),
     * sort (activity – the default –, name, created), anonymised (true = also the anonymised ones). Each row as find()
     * returns it, plus counts of its enquiries and bookings.
     *
     * @param array<string, mixed> $filter
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function list(Db $db, array $filter = [], int $page = 1, int $perPage = self::PAGE): array
    {
        $perPage = max(1, min(500, $perPage));
        $where = [];
        $params = [];
        if (empty($filter['anonymised'])) {
            $where[] = 'c.anonymised_at IS NULL';
        }
        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR co.name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $owner = (int) ($filter['owner'] ?? 0);
        if ($owner > 0) {
            $where[] = 'c.owner_id = ?';
            $params[] = $owner;
        } elseif ($owner < 0) {
            $where[] = 'c.owner_id IS NULL';
        }
        if ((int) ($filter['company'] ?? 0) > 0) {
            $where[] = 'c.company_id = ?';
            $params[] = (int) $filter['company'];
        }
        if (is_string($filter['active_since'] ?? null) && $filter['active_since'] !== '') {
            $where[] = 'c.last_activity_at >= ?';
            $params[] = $filter['active_since'];
        }
        if (is_string($filter['inactive_before'] ?? null) && $filter['inactive_before'] !== '') {
            $where[] = '(c.last_activity_at IS NULL OR c.last_activity_at < ?)';
            $params[] = $filter['inactive_before'];
        }
        $sql = ' FROM {contacts} c LEFT JOIN {companies} co ON co.id = c.company_id' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');
        try {
            $total = (int) $db->value('SELECT COUNT(*)' . $sql, $params);
            $pages = max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $pages));
            $rows = $db->all('SELECT c.*, co.name AS company_name' . $sql . ' ORDER BY ' . (self::SORTS[$filter['sort'] ?? ''] ?? self::SORTS['activity'])
                . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        } catch (\PDOException) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]; // before migration 0114
        }
        $rows = array_map(self::row(...), $rows);
        $counts = self::linkCounts($db, array_column($rows, 'id'));

        return ['rows' => array_map(fn (array $r): array => $r + ($counts[$r['id']] ?? ['enquiries' => 0, 'bookings' => 0, 'subscribed' => false]), $rows),
            'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * How many enquiries and bookings each contact has, and whether it has a confirmed subscription.
     *
     * @param list<int> $ids
     * @return array<int, array{enquiries: int, bookings: int, subscribed: bool}>
     */
    public static function linkCounts(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $out = array_fill_keys($ids, ['enquiries' => 0, 'bookings' => 0, 'subscribed' => false]);
        foreach ($db->pairs('SELECT contact_id, COUNT(*) FROM {poptavky} WHERE contact_id IN (' . $in . ') GROUP BY contact_id') as $id => $n) {
            $out[(int) $id]['enquiries'] = (int) $n;
        }
        try {
            foreach ($db->pairs('SELECT contact_id, COUNT(*) FROM {bookings} WHERE contact_id IN (' . $in . ') GROUP BY contact_id') as $id => $n) {
                $out[(int) $id]['bookings'] = (int) $n;
            }
        } catch (\PDOException) {
            // without the booking tables (before 3.0)
        }
        foreach ($db->all('SELECT contact_id FROM {odberatele} WHERE stav = 1 AND contact_id IN (' . $in . ')') as $r) {
            $out[(int) $r['contact_id']]['subscribed'] = true;
        }

        return $out;
    }

    /**
     * Deletes a contact for good (the admin's Delete, an erasure on request): its activities, the team's comments and tasks
     * about it and the notifications pointing to it go; its enquiries, bookings and subscription stay and are unlinked.
     * Returns whether it existed.
     */
    public static function delete(App $app, int $id): bool
    {
        $db = $app->db();
        if (self::find($db, $id) === null) {
            return false;
        }
        self::unlink($db, [$id]);
        $db->run('DELETE FROM {contact_activities} WHERE contact_id = ?', [$id]);
        Comments::forget($db, [Comments::contact($id)]); // the comments, their notifications and the tasks about it
        $db->delete('contacts', ['id' => $id]);

        return true;
    }

    /**
     * Anonymises contacts (the retention clean-up, Core\Privacy): the row stays for statistics (source, when first seen and
     * last active, the responsible person), the address, name, phone, company and summary go; the activities keep their kind,
     * time and author without the text; the team's comments and the titles of tasks about it are blanked; its enquiries,
     * bookings and subscription are unlinked. Already anonymised ones are skipped. Returns how many were anonymised.
     *
     * @param list<int> $ids
     */
    public static function anonymise(Db $db, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        $done = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            try {
                $chunk = array_map('intval', array_column($db->all('SELECT id FROM {contacts} WHERE anonymised_at IS NULL AND id IN (' . $in . ')'), 'id'));
            } catch (\PDOException) {
                return $done; // before migration 0114
            }
            if ($chunk === []) {
                continue;
            }
            $in = implode(',', $chunk);
            $now = Clock::now();
            $done += $db->run("UPDATE {contacts} SET email = '', email_norm = NULL, name = '', phone = '', company_id = NULL, summary = NULL, anonymised_at = ?, updated_at = ? WHERE id IN (" . $in . ')',
                [$now, $now])->rowCount();
            $db->run("UPDATE {contact_activities} SET text = '' WHERE contact_id IN (" . $in . ')');
            Comments::anonymiseTargets($db, array_map(Comments::contact(...), $chunk));
            self::unlink($db, $chunk);
        }

        return $done;
    }

    /* ---------- linking ---------- */

    /**
     * Links a record after the response: a new enquiry, booking or confirmed subscription is queued here and afterResponse()
     * links it once the visitor has the page.
     *
     * @param 'enquiry'|'booking'|'subscriber' $kind
     */
    public static function later(string $kind, int $id): void
    {
        if (isset(self::LINKED[$kind]) && $id > 0) {
            self::$pending[] = [$kind, $id];
        }
    }

    /** After the page was sent (index.php, admin.php): links what later() queued in this request. Never fails the request. */
    public static function afterResponse(App $app): void
    {
        if (self::$pending === []) {
            return;
        }
        $pending = self::$pending;
        self::$pending = [];
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        ignore_user_abort(true);
        foreach ($pending as [$kind, $id]) {
            try {
                self::link($app, $kind, $id);
            } catch (\Throwable) {
                // the linking job tries again (backfill)
            }
        }
    }

    /** How many links later() queued in this request (tests). */
    public static function pending(): int
    {
        return count(self::$pending);
    }

    /**
     * Links one enquiry, booking or subscription to the contact of its address, creating the contact when there is none
     * (source = the kind; 'import' for an imported enquiry). Never merges two different addresses: the contact is the one
     * with the same normalised address, or one a person merged that address into (a linked record with the same address).
     * A new contact gets the name and phone the record has, its language, and the company of its e-mail domain when one
     * exists (never for free-mail). A contact's empty name, phone and language are filled in; first_seen_at and
     * last_activity_at follow the record's date. An unconfirmed subscription, a record without a valid address or one
     * already linked is left as it is. Returns the contact id or null.
     *
     * @param 'enquiry'|'booking'|'subscriber' $kind
     */
    public static function link(App $app, string $kind, int $id): ?int
    {
        $db = $app->db();
        $record = self::record($db, $kind, $id);
        if ($record === null) {
            return null;
        }
        if ($record['contact_id'] !== null) {
            return $record['contact_id'];
        }
        $norm = self::normalise($record['email']);
        if ($norm === null || ($kind === 'subscriber' && !$record['confirmed'])) {
            return null;
        }
        $contact = self::findByEmail($db, $norm) ?? self::mergedInto($db, $app->settings(), $norm, $kind, $id);
        if ($contact === null) {
            $now = Clock::now();
            $company = self::companyByDomain($db, self::companyDomain($norm));
            try {
                $contactId = $db->insert('contacts', ['email' => mb_substr(trim($record['email']), 0, 190), 'email_norm' => $norm, 'name' => $record['name'], 'phone' => $record['phone'],
                    'company_id' => $company['id'] ?? null, 'owner_id' => null, 'language' => $record['language'], 'source' => $record['source'], 'summary' => null,
                    'first_seen_at' => $record['at'], 'last_activity_at' => $record['at'], 'created_at' => $now, 'updated_at' => $now]);
            } catch (\PDOException $e) {
                // a concurrent request created it a moment ago (the unique address): attach to that one
                $contact = self::findByEmail($db, $norm) ?? throw $e;
                $contactId = $contact['id'];
            }
        } else {
            $contactId = $contact['id'];
            $fill = array_filter(['name' => $contact['name'] === '' ? $record['name'] : '', 'phone' => $contact['phone'] === '' ? $record['phone'] : '',
                'language' => $contact['language'] === '' ? $record['language'] : ''], fn (string $v): bool => $v !== '');
            if ($fill !== []) {
                $db->update('contacts', $fill + ['updated_at' => Clock::now()], ['id' => $contactId]);
            }
        }
        [$table, $key] = self::LINKED[$kind];
        $db->run('UPDATE {' . $table . '} SET contact_id = ? WHERE ' . $key . ' = ? AND contact_id IS NULL', [$contactId, $id]);
        self::touch($db, $contactId, $record['at'], $record['at']);

        return $contactId;
    }

    /**
     * The linking job "contacts" (Core\Scheduler): links the enquiries, bookings and subscriptions that have no contact yet,
     * oldest first, at most $batch rows per run, remembering how far it got (the setting contacts_backfill) – the rows from
     * before 3.28 over a few runs, then only the new ones a failed after-response link missed. Returns what it did.
     *
     * @return array{linked: int, scanned: int, done: bool}
     */
    public static function backfill(App $app, int $batch = self::BACKFILL_BATCH): array
    {
        $db = $app->db();
        $s = $app->settings();
        $state = json_decode($s->get('contacts_backfill'), true);
        $state = array_map('intval', array_intersect_key(is_array($state) ? $state : [], self::LINKED)) + array_fill_keys(array_keys(self::LINKED), 0);
        $linked = 0;
        $scanned = 0;
        $done = true;
        foreach (self::LINKED as $kind => [$table, $key]) {
            $left = $batch - $scanned;
            if ($left <= 0) {
                $done = false;
                break;
            }
            try {
                $rows = $db->all('SELECT ' . $key . ' AS id, contact_id FROM {' . $table . '} WHERE ' . $key . ' > ? ORDER BY ' . $key . ' LIMIT ' . $left, [$state[$kind]]);
            } catch (\PDOException) {
                continue; // a table missing (bookings before 3.0, contacts before 0114)
            }
            foreach ($rows as $r) {
                $scanned++;
                $state[$kind] = (int) $r['id'];
                if ($r['contact_id'] === null) {
                    try {
                        $linked += self::link($app, $kind, (int) $r['id']) !== null ? 1 : 0;
                    } catch (\PDOException) {
                        // a row that cannot be linked now (contacts not installed yet) – it waits behind the cursor no longer
                    }
                }
            }
            if (count($rows) === $left) {
                $done = false;
            }
        }
        $s->set('contacts_backfill', (string) json_encode($state));

        return ['linked' => $linked, 'scanned' => $scanned, 'done' => $done];
    }

    /**
     * Moves a contact's first and last activity to include a moment: first_seen_at to the earlier, last_activity_at to the
     * later one (null leaves it).
     */
    public static function touch(Db $db, int $contactId, ?string $lastActivity, ?string $firstSeen = null): void
    {
        if ($contactId <= 0) {
            return;
        }
        if ($lastActivity !== null && $lastActivity !== '') {
            $db->run('UPDATE {contacts} SET last_activity_at = ? WHERE id = ? AND (last_activity_at IS NULL OR last_activity_at < ?)', [$lastActivity, $contactId, $lastActivity]);
        }
        if ($firstSeen !== null && $firstSeen !== '') {
            $db->run('UPDATE {contacts} SET first_seen_at = ? WHERE id = ? AND (first_seen_at IS NULL OR first_seen_at > ?)', [$firstSeen, $contactId, $firstSeen]);
        }
    }

    /** A record's contact had an activity now (a reply was e-mailed from an enquiry): its last_activity_at moves. */
    public static function touchLinked(Db $db, string $kind, int $id, ?string $at = null): void
    {
        [$table, $key] = self::LINKED[$kind] ?? [null, null];
        if ($table === null) {
            return;
        }
        try {
            $contactId = (int) $db->value('SELECT contact_id FROM {' . $table . '} WHERE ' . $key . ' = ?', [$id]);
            self::touch($db, $contactId, $at ?? Clock::now());
        } catch (\PDOException) {
            // before migration 0114
        }
    }

    /* ---------- merge ---------- */

    /**
     * Merges contact $from into $into – a person's decision, never Kaleta's (two different addresses are merged only here):
     * the enquiries, bookings, subscription, activities, the team's comments and tasks and the notifications of $from move to
     * $into; $into keeps its address and fills its empty name, phone, company, responsible person and language from $from,
     * the summaries are joined, the first and last activity span both. $from is deleted; a note on $into keeps the history
     * (which contact and address were merged, by whom). Returns what moved.
     *
     * @param int $byUserId who merges (the author of the note; 0 = Kaleta itself)
     * @return array{into: int, from: int, moved: array{enquiries: int, bookings: int, subscriptions: int, activities: int, comments: int, tasks: int}}
     * @throws \DomainException with a translated reason; nothing changes
     */
    public static function merge(App $app, int $into, int $from, int $byUserId): array
    {
        $db = $app->db();
        $user = $byUserId > 0 ? ($db->one('SELECT idu, jmeno, user FROM {uzivatele} WHERE idu = ?', [$byUserId]) ?? []) : [];
        if ($into === $from) {
            throw new \DomainException(t('Choose two different contacts to merge.'));
        }
        $a = self::find($db, $into) ?? throw new \DomainException(t('The contact does not exist.'));
        $b = self::find($db, $from) ?? throw new \DomainException(t('The contact to merge does not exist.'));
        if ($a['anonymised_at'] !== null || $b['anonymised_at'] !== null) {
            throw new \DomainException(t('An anonymised contact cannot be merged.'));
        }
        $moved = $db->transaction(function (Db $db) use ($a, $b, $user): array {
            $moved = [];
            foreach (['enquiries' => 'poptavky', 'bookings' => 'bookings', 'subscriptions' => 'odberatele'] as $label => $table) {
                try {
                    $moved[$label] = $db->run('UPDATE {' . $table . '} SET contact_id = ? WHERE contact_id = ?', [$a['id'], $b['id']])->rowCount();
                } catch (\PDOException) {
                    $moved[$label] = 0;
                }
            }
            $moved['activities'] = $db->run('UPDATE {contact_activities} SET contact_id = ? WHERE contact_id = ?', [$a['id'], $b['id']])->rowCount();
            [$old, $new] = [Comments::contact($b['id']), Comments::contact($a['id'])];
            $moved['comments'] = $db->run('UPDATE {comments} SET target = ? WHERE target = ?', [$new, $old])->rowCount();
            try {
                $moved['tasks'] = $db->run('UPDATE {tasks} SET target = ? WHERE target = ?', [$new, $old])->rowCount();
            } catch (\PDOException) {
                $moved['tasks'] = 0;
            }
            try {
                $db->run('UPDATE {notifications} SET target = ? WHERE target = ?', [$new, $old]);
            } catch (\PDOException) {
                // before migration 0112
            }
            $fill = array_filter(['name' => $a['name'] === '' ? $b['name'] : '', 'phone' => $a['phone'] === '' ? $b['phone'] : '', 'language' => $a['language'] === '' ? $b['language'] : ''],
                fn (string $v): bool => $v !== '');
            if ($a['company_id'] === null && $b['company_id'] !== null) {
                $fill['company_id'] = $b['company_id'];
            }
            if ($a['owner_id'] === null && $b['owner_id'] !== null) {
                $fill['owner_id'] = $b['owner_id'];
            }
            if ($b['summary'] !== '') {
                $fill['summary'] = mb_substr(trim($a['summary'] !== '' ? $a['summary'] . "\n\n" . $b['summary'] : $b['summary']), 0, self::MAX_TEXT);
            }
            $db->delete('contacts', ['id' => $b['id']]);
            $db->update('contacts', $fill + ['updated_at' => Clock::now()], ['id' => $a['id']]);
            self::touch($db, $a['id'], $b['last_activity_at'], $b['first_seen_at']);
            $what = $b['email'] !== '' ? $b['email'] : ($b['name'] !== '' ? $b['name'] : '#' . $b['id']);
            self::insertActivity($db, $a['id'], 'note', t('Merged with the contact #%d (%s).', $b['id'], $what), $user, Clock::now());

            return $moved;
        });
        self::markMerged($app->settings());
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'merge', '#' . $from . ' → #' . $into);

        return ['into' => $into, 'from' => $from, 'moved' => $moved];
    }

    /* ---------- activities ---------- */

    /**
     * Logs a call, a meeting, an e-mail or a note on a contact as the given user and returns its id. $happenedAt takes what
     * Tasks::parseMoment takes ("2026-11-03 14:30", "today"; null or '' = now). The contact's last activity moves with it.
     *
     * @param array<string, mixed> $user a row of ka_uzivatele (idu, jmeno, user)
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function logActivity(App $app, int $contactId, string $kind, string $text, array $user, ?string $happenedAt = null): int
    {
        $db = $app->db();
        $contact = self::find($db, $contactId) ?? throw new \DomainException(t('The contact does not exist.'));
        if ($contact['anonymised_at'] !== null) {
            throw new \DomainException(t('The contact was anonymised – nothing more can be logged on it.'));
        }
        if (!isset(self::ACTIVITY_KINDS[$kind])) {
            throw new \DomainException(t('Choose what happened: a call, a meeting, an e-mail or a note.'));
        }
        $text = Comments::clean($text);
        if ($text === '') {
            throw new \DomainException(t('Write what happened first.'));
        }
        $at = $happenedAt === null || trim($happenedAt) === '' ? Clock::now() : (Tasks::parseMoment($happenedAt) ?? Clock::now());
        $id = self::insertActivity($db, $contactId, $kind, $text, $user, $at);
        self::touch($db, $contactId, $at);

        return $id;
    }

    /**
     * Deletes an activity: its author, or an administrator. Throws \DomainException when it may not.
     *
     * @param array<string, mixed> $user the signed-in user
     */
    public static function deleteActivity(Db $db, int $contactId, int $activityId, array $user, bool $admin): void
    {
        $row = $db->one('SELECT id, contact_id, author_id FROM {contact_activities} WHERE id = ?', [$activityId]);
        if ($row === null || (int) $row['contact_id'] !== $contactId) {
            throw new \DomainException(t('The entry does not exist.'));
        }
        if (!$admin && ((int) ($user['idu'] ?? 0) <= 0 || (int) $row['author_id'] !== (int) ($user['idu'] ?? 0))) {
            throw new \DomainException(t('You can delete only your own entries.'));
        }
        $db->delete('contact_activities', ['id' => $activityId]);
    }

    /**
     * The activities of a contact, the latest first.
     *
     * @return list<array{id: int, kind: string, text: string, by: string, by_id: ?int, at: string, created_at: string}>
     */
    public static function activities(Db $db, int $contactId, int $limit = 200): array
    {
        try {
            $rows = $db->all('SELECT * FROM {contact_activities} WHERE contact_id = ? ORDER BY happened_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit)), [$contactId]);
        } catch (\PDOException) {
            return [];
        }

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'text' => (string) $r['text'], 'by' => (string) $r['author_name'],
            'by_id' => $r['author_id'] !== null ? (int) $r['author_id'] : null, 'at' => (string) $r['happened_at'], 'created_at' => (string) $r['created_at']], $rows);
    }

    /* ---------- timeline ---------- */

    /**
     * Everything that happened with a contact, newest first and at most $limit entries (TIMELINE_MAX): its enquiries (the
     * form, what it was about, the status), the replies e-mailed from them (subject, who), its bookings (service, when,
     * status), the subscription, the team's comments and tasks about it, and the activities (call, meeting, e-mail, note).
     * Each entry: at, kind (enquiry | reply | booking | subscription | comment | task | call | meeting | email | note), title
     * (data, not translated: a form name, a subject, a service), optional text, by, status, ref ('enquiry:12', 'booking:3',
     * 'comment:5', 'task:7', 'activity:9') and path (the admin address, relative). Deals join in 3.29.
     *
     * @return list<array<string, mixed>>
     */
    public static function timeline(Db $db, int $id, int $limit = self::TIMELINE): array
    {
        $limit = max(1, min(self::TIMELINE_MAX, $limit));
        $out = [];
        try {
            foreach ($db->all('SELECT idp, datum, formular, tema, stav, replies, anonymizovano FROM {poptavky} WHERE contact_id = ? ORDER BY datum DESC, idp DESC LIMIT ' . $limit, [$id]) as $r) {
                $path = 'admin.php?module=enquiries&action=detail&id=' . (int) $r['idp'];
                $out[] = ['at' => (string) $r['datum'], 'kind' => 'enquiry', 'title' => (string) $r['formular'], 'text' => (string) $r['tema'],
                    'status' => ['new', 'read', 'resolved'][(int) $r['stav']] ?? 'new', 'ref' => 'enquiry:' . (int) $r['idp'], 'path' => $path];
                foreach (EnquiryReply::decode($r['replies']) as $reply) {
                    $out[] = ['at' => $reply['at'], 'kind' => 'reply', 'title' => $reply['subject'], 'by' => $reply['by'], 'status' => $reply['status'], 'ref' => 'enquiry:' . (int) $r['idp'], 'path' => $path];
                }
            }
        } catch (\PDOException) {
            return []; // before migration 0114
        }
        try {
            foreach ($db->all('SELECT b.id, b.created_at, b.starts_at, b.status, s.name AS service FROM {bookings} b LEFT JOIN {booking_services} s ON s.id = b.service_id WHERE b.contact_id = ? ORDER BY b.created_at DESC, b.id DESC LIMIT ' . $limit, [$id]) as $r) {
                $out[] = ['at' => (string) $r['created_at'], 'kind' => 'booking', 'title' => (string) ($r['service'] ?? ''), 'text' => (string) $r['starts_at'], 'status' => (string) $r['status'],
                    'ref' => 'booking:' . (int) $r['id'], 'path' => 'admin.php?module=bookings&action=detail&id=' . (int) $r['id']];
            }
        } catch (\PDOException) {
            // without the booking tables
        }
        foreach ($db->all('SELECT ido, datum, potvrzeno, stav FROM {odberatele} WHERE contact_id = ? LIMIT 5', [$id]) as $r) {
            $out[] = ['at' => (string) ($r['potvrzeno'] ?? $r['datum']), 'kind' => 'subscription', 'title' => '', 'status' => (int) $r['stav'] === 1 ? 'confirmed' : 'pending',
                'ref' => 'subscriber:' . (int) $r['ido'], 'path' => 'admin.php?module=subscribers'];
        }
        $target = Comments::contact($id);
        foreach (array_slice(Comments::of($db, $target), -$limit) as $c) {
            $out[] = ['at' => $c['at'], 'kind' => 'comment', 'title' => '', 'text' => Comments::excerpt($c['text'], []), 'by' => $c['by'], 'ref' => 'comment:' . $c['id'], 'path' => self::adminPath($id)];
        }
        foreach (Tasks::forTarget($db, $target) as $task) {
            $out[] = ['at' => $task['done_at'] ?? $task['created_at'], 'kind' => 'task', 'title' => $task['title'], 'status' => $task['done_at'] !== null ? 'done' : $task['group'],
                'text' => (string) ($task['due_at'] ?? ''), 'ref' => 'task:' . $task['id'], 'path' => 'admin.php?module=my_work#task-' . $task['id']];
        }
        foreach (self::activities($db, $id, $limit) as $act) {
            $out[] = ['at' => $act['at'], 'kind' => $act['kind'], 'title' => '', 'text' => $act['text'], 'by' => $act['by'], 'ref' => 'activity:' . $act['id'], 'path' => self::adminPath($id)];
        }
        // 3.29: the deals of the contact join here
        usort($out, fn (array $x, array $y): int => [$y['at'], $y['ref']] <=> [$x['at'], $x['ref']]);

        return array_slice(array_map(fn (array $e): array => array_filter($e, fn (mixed $v, string $k): bool => $v !== '' || in_array($k, ['at', 'kind', 'title'], true), ARRAY_FILTER_USE_BOTH), $out), 0, $limit);
    }

    /* ---------- companies ---------- */

    /**
     * Creates a company ($id null) or changes the given fields of one (name, domain, registration_no, vat_id, owner_id, note)
     * and returns its id. A new responsible person is notified.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function saveCompany(App $app, ?int $id, array $fields, int $byUserId): int
    {
        $db = $app->db();
        $company = $id !== null ? (self::findCompany($db, $id) ?? throw new \DomainException(t('The company does not exist.'))) : null;
        $data = [];
        if (array_key_exists('name', $fields) || $company === null) {
            $data['name'] = self::line($fields['name'] ?? '', 150);
            if ($data['name'] === '') {
                throw new \DomainException(t('Give the company a name.'));
            }
        }
        if (array_key_exists('domain', $fields)) {
            $raw = is_scalar($fields['domain']) ? trim((string) $fields['domain']) : '';
            $data['domain'] = self::cleanDomain($raw);
            if ($raw !== '' && $data['domain'] === '') {
                throw new \DomainException(t('The domain “%s” is not a domain – write it like example.com.', $raw));
            }
        }
        foreach (['registration_no' => 40, 'vat_id' => 40] as $key => $max) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = self::line($fields[$key], $max);
            }
        }
        if (array_key_exists('note', $fields)) {
            $note = Comments::clean(is_scalar($fields['note']) ? (string) $fields['note'] : '');
            $data['note'] = $note !== '' ? $note : null;
        }
        if (array_key_exists('owner_id', $fields)) {
            $data['owner_id'] = self::owner($db, $fields['owner_id'], $company['owner_id'] ?? null);
        }
        $now = Clock::now();
        if ($company === null) {
            $id = $db->insert('companies', $data + ['created_at' => $now, 'updated_at' => $now]);
        } elseif ($data !== []) {
            $db->update('companies', $data + ['updated_at' => $now], ['id' => $id]);
        }
        if (($data['owner_id'] ?? null) !== null && $data['owner_id'] !== ($company['owner_id'] ?? null)) {
            self::notifyOwner($app, 'company', (int) $id, (int) $data['owner_id'], $byUserId);
        }

        return (int) $id;
    }

    /**
     * One company, or null.
     *
     * @return array{id: int, name: string, domain: string, registration_no: string, vat_id: string, owner_id: ?int, note: string, created_at: string, updated_at: string}|null
     */
    public static function findCompany(Db $db, int $id): ?array
    {
        try {
            $row = $id > 0 ? $db->one('SELECT * FROM {companies} WHERE id = ?', [$id]) : null;
        } catch (\PDOException) {
            return null;
        }

        return $row === null ? null : self::companyRow($row);
    }

    /**
     * The company with this name (case does not matter; the oldest when there are more), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function companyByName(Db $db, string $name): ?array
    {
        $name = self::line($name, 150);
        if ($name === '') {
            return null;
        }
        try {
            $row = $db->one('SELECT * FROM {companies} WHERE LOWER(name) = ? ORDER BY id LIMIT 1', [mb_strtolower($name)]);
        } catch (\PDOException) {
            return null;
        }

        return $row === null ? null : self::companyRow($row);
    }

    /**
     * A page of companies by name, each with how many contacts it has. Filter: search (name, domain, registration or VAT
     * number), owner (a user id; -1 = nobody).
     *
     * @param array<string, mixed> $filter
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function companies(Db $db, array $filter = [], int $page = 1, int $perPage = self::PAGE): array
    {
        $perPage = max(1, min(500, $perPage));
        $where = [];
        $params = [];
        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(name LIKE ? OR domain LIKE ? OR registration_no LIKE ? OR vat_id LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $owner = (int) ($filter['owner'] ?? 0);
        if ($owner !== 0) {
            $where[] = $owner > 0 ? 'owner_id = ?' : 'owner_id IS NULL';
            if ($owner > 0) {
                $params[] = $owner;
            }
        }
        $sql = ' FROM {companies}' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');
        try {
            $total = (int) $db->value('SELECT COUNT(*)' . $sql, $params);
            $pages = max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $pages));
            $rows = array_map(self::companyRow(...), $db->all('SELECT *' . $sql . ' ORDER BY ' . $db->sortText('name') . ', id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params));
        } catch (\PDOException) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        $ids = array_column($rows, 'id');
        $counts = $ids === [] ? [] : $db->pairs('SELECT company_id, COUNT(*) FROM {contacts} WHERE anonymised_at IS NULL AND company_id IN (' . implode(',', $ids) . ') GROUP BY company_id');

        return ['rows' => array_map(fn (array $r): array => $r + ['contacts' => (int) ($counts[$r['id']] ?? 0)], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Deletes a company: its contacts stay without a company; the team's comments and tasks about it go. Returns whether it
     * existed.
     */
    public static function deleteCompany(App $app, int $id): bool
    {
        $db = $app->db();
        if (self::findCompany($db, $id) === null) {
            return false;
        }
        $db->run('UPDATE {contacts} SET company_id = NULL WHERE company_id = ?', [$id]);
        Comments::forget($db, [Comments::company($id)]);
        $db->delete('companies', ['id' => $id]);

        return true;
    }

    /* ---------- retention ---------- */

    /** The contacts' retention in months (0 = keep): contacts_months. */
    public static function months(Settings $s): int
    {
        return max(0, $s->int('contacts_months'));
    }

    /**
     * Does the contact have an open deal? The hook of 3.29 (lean pipeline): a contact with an open deal is never anonymised
     * by the retention, and its enquiries are kept until the deal closes. There are no deals before 3.29 – always false.
     */
    public static function hasOpenDeal(Db $db, int $contactId): bool
    {
        return false;
    }

    /**
     * The enquiries of the given ones that must be kept although their retention has passed: those of a contact with an open
     * deal (hasOpenDeal – none before 3.29). Enquiries::deleteExpired leaves them for the next day.
     *
     * @param list<int> $idps
     * @return list<int>
     */
    public static function heldByDeals(Db $db, array $idps): array
    {
        $idps = array_values(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0));
        if ($idps === []) {
            return [];
        }
        $held = [];
        try {
            foreach (array_chunk($idps, 500) as $chunk) {
                foreach ($db->all('SELECT idp, contact_id FROM {poptavky} WHERE contact_id IS NOT NULL AND idp IN (' . implode(',', $chunk) . ')') as $r) {
                    if (self::hasOpenDeal($db, (int) $r['contact_id'])) {
                        $held[] = (int) $r['idp'];
                    }
                }
            }
        } catch (\PDOException) {
            return []; // before migration 0114
        }

        return $held;
    }

    /**
     * Enquiries about to expire (deleted or anonymised by the retention): the contact of the one that was its first contact
     * (its date is the contact's first_seen_at) keeps "First contact on <date> through the form “<form>”." at the end of
     * its summary – without the message. In the site's language. Returns how many summaries changed.
     *
     * @param list<int> $idps
     */
    public static function enquiriesExpiring(Db $db, Settings $s, array $idps): int
    {
        $idps = array_values(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0));
        $changed = 0;
        try {
            foreach (array_chunk($idps, 500) as $chunk) {
                $rows = $db->all('SELECT p.idp, p.datum, p.formular, c.id, c.summary FROM {poptavky} p JOIN {contacts} c ON c.id = p.contact_id'
                    . ' WHERE p.idp IN (' . implode(',', $chunk) . ') AND c.anonymised_at IS NULL AND c.first_seen_at IS NOT NULL AND DATE(p.datum) = DATE(c.first_seen_at) ORDER BY p.datum, p.idp');
                $seen = [];
                foreach ($rows as $r) {
                    if (isset($seen[(int) $r['id']])) {
                        continue;
                    }
                    $seen[(int) $r['id']] = true;
                    $line = self::firstContactLine($s, (string) $r['datum'], (string) $r['formular']);
                    $summary = (string) ($r['summary'] ?? '');
                    if (str_contains($summary, $line)) {
                        continue;
                    }
                    $db->update('contacts', ['summary' => mb_substr(ltrim($summary . "\n" . $line), 0, self::MAX_TEXT)], ['id' => (int) $r['id']]);
                    $changed++;
                }
            }
        } catch (\PDOException) {
            return $changed; // before migration 0114
        }

        return $changed;
    }

    /** "First contact on 3. 11. 2026 through the form “Contact”." in the site's language (the admin dictionaries). */
    public static function firstContactLine(Settings $s, string $at, string $form): string
    {
        return Language::runWith(Language::defaults($s), fn (): string => t('First contact on %s through the form “%s”.', format_date($at), $form), 'admin-');
    }

    /**
     * The retention clean-up (the daily job cleanup, Notifications::purgePersonalData): contacts with no activity for
     * contacts_months – last activity, or creation when there was none, before that – and no open deal are anonymised.
     * Returns how many.
     */
    public static function purge(App $app): int
    {
        $months = self::months($app->settings());
        if ($months <= 0) {
            return 0;
        }
        $db = $app->db();
        try {
            $ids = array_map('intval', array_column($db->all('SELECT id FROM {contacts} WHERE anonymised_at IS NULL AND COALESCE(last_activity_at, created_at) < ? ORDER BY id LIMIT ' . self::PURGE_BATCH,
                [Clock::monthsAgo($months)]), 'id'));
        } catch (\PDOException) {
            return 0; // before migration 0114
        }
        $ids = array_values(array_filter($ids, fn (int $id): bool => !self::hasOpenDeal($db, $id)));

        return self::anonymise($db, $ids);
    }

    /* ---------- permissions, labels, handover ---------- */

    /** May this account open Customers (the section SECTION: an active administrator or a row in ka_uzivatele_prava)? */
    public static function mayOpen(Db $db, Settings $siteSettings, int $userId): bool
    {
        return Handover::mayOpen($db, $siteSettings, $userId, self::SECTION);
    }

    /** The admin address of a contact (relative) – Admin\Modules\Contacts, action detail. */
    public static function adminPath(int $id): string
    {
        return 'admin.php?module=' . self::SECTION . '&action=detail&id=' . $id;
    }

    /** The admin address of a company (relative) – Admin\Modules\Contacts, action company. */
    public static function companyAdminPath(int $id): string
    {
        return 'admin.php?module=' . self::SECTION . '&action=company&id=' . $id;
    }

    /** How a contact is named in a list or a notification: the name, otherwise the address, otherwise "#id". Pure. */
    public static function displayName(array $contact): string
    {
        $name = trim((string) ($contact['name'] ?? ''));
        $email = trim((string) ($contact['email'] ?? ''));

        return $name !== '' ? $name : ($email !== '' ? $email : '#' . (int) ($contact['id'] ?? 0));
    }

    /**
     * Labels of contacts and companies by target (Core\Inbox::places, Core\Tasks::labels): 'contact:<id>' | 'company:<id>'
     * => kind, id, title, path. Missing ones are left out. One query per kind.
     *
     * @param list<int> $contactIds
     * @param list<int> $companyIds
     * @return array<string, array{kind: string, id: int, title: string, path: string}>
     */
    public static function places(Db $db, array $contactIds, array $companyIds): array
    {
        $out = [];
        try {
            if ($contactIds !== []) {
                foreach ($db->all('SELECT id, name, email FROM {contacts} WHERE id IN (' . implode(',', array_map('intval', array_unique($contactIds))) . ')') as $r) {
                    $out['contact:' . (int) $r['id']] = ['kind' => 'contact', 'id' => (int) $r['id'], 'title' => self::displayName($r), 'path' => self::adminPath((int) $r['id'])];
                }
            }
            if ($companyIds !== []) {
                foreach ($db->all('SELECT id, name FROM {companies} WHERE id IN (' . implode(',', array_map('intval', array_unique($companyIds))) . ')') as $r) {
                    $out['company:' . (int) $r['id']] = ['kind' => 'company', 'id' => (int) $r['id'], 'title' => (string) $r['name'], 'path' => self::companyAdminPath((int) $r['id'])];
                }
            }
        } catch (\PDOException) {
            // before migration 0114
        }

        return $out;
    }

    /**
     * What a person is responsible for here (Core\Handover): contacts (not anonymised) and companies.
     *
     * @return array{contact: int, company: int}
     */
    public static function owned(Db $db, int $userId): array
    {
        try {
            return ['contact' => (int) $db->value('SELECT COUNT(*) FROM {contacts} WHERE owner_id = ? AND anonymised_at IS NULL', [$userId]),
                'company' => (int) $db->value('SELECT COUNT(*) FROM {companies} WHERE owner_id = ?', [$userId])];
        } catch (\PDOException) {
            return ['contact' => 0, 'company' => 0];
        }
    }

    /**
     * The same for every account (the Users list): idu => counts, only those who own something.
     *
     * @return array<int, array{contact: int, company: int}>
     */
    public static function ownedByAll(Db $db): array
    {
        $out = [];
        try {
            foreach (['contact' => 'SELECT owner_id, COUNT(*) FROM {contacts} WHERE owner_id IS NOT NULL AND anonymised_at IS NULL GROUP BY owner_id',
                'company' => 'SELECT owner_id, COUNT(*) FROM {companies} WHERE owner_id IS NOT NULL GROUP BY owner_id'] as $kind => $sql) {
                foreach ($db->pairs($sql) as $idu => $n) {
                    $out[(int) $idu] ??= ['contact' => 0, 'company' => 0];
                    $out[(int) $idu][$kind] = (int) $n;
                }
            }
        } catch (\PDOException) {
            return [];
        }

        return $out;
    }

    /** Hands a leaver's contacts and companies to another person (null = nobody). @return array{contact: int, company: int} */
    public static function handOver(Db $db, int $from, ?int $to): array
    {
        try {
            return ['contact' => $db->run('UPDATE {contacts} SET owner_id = ? WHERE owner_id = ?', [$to, $from])->rowCount(),
                'company' => $db->run('UPDATE {companies} SET owner_id = ? WHERE owner_id = ?', [$to, $from])->rowCount()];
        } catch (\PDOException) {
            return ['contact' => 0, 'company' => 0];
        }
    }

    /**
     * The contacts of one address and of the given linked records (Core\PersonalData): ids. N328-6: also a contact whose
     * log names the address – the note of a merge keeps the address merged into it, which may be the only trace of it left
     * (a contact added by hand or imported has no records), like an enquiry that has the address in a field.
     *
     * @param list<int> $enquiryIds
     * @param list<int> $bookingIds
     * @return list<int>
     */
    public static function ofPerson(Db $db, string $email, array $enquiryIds = [], array $bookingIds = [], ?int $subscriberId = null): array
    {
        $ids = [];
        try {
            $norm = self::normalise($email);
            if ($norm !== null) {
                $ids = array_map('intval', array_column($db->all('SELECT id FROM {contacts} WHERE email_norm = ?', [$norm]), 'id'));
                $ids = [...$ids, ...array_map('intval', array_column($db->all('SELECT DISTINCT contact_id FROM {contact_activities} WHERE LOWER(text) LIKE ?',
                    ['%' . addcslashes($norm, '%_\\') . '%']), 'contact_id'))];
            }
            foreach (['poptavky' => ['idp', $enquiryIds], 'bookings' => ['id', $bookingIds], 'odberatele' => ['ido', $subscriberId !== null ? [$subscriberId] : []]] as $table => [$key, $list]) {
                $list = array_values(array_filter(array_map('intval', $list), fn (int $id): bool => $id > 0));
                if ($list !== []) {
                    $ids = [...$ids, ...array_map('intval', array_column($db->all('SELECT DISTINCT contact_id FROM {' . $table . '} WHERE contact_id IS NOT NULL AND ' . $key . ' IN (' . implode(',', $list) . ')'), 'contact_id'))];
                }
            }
        } catch (\PDOException) {
            return []; // before migration 0114
        }

        return array_values(array_unique($ids));
    }

    /* ---------- internals ---------- */

    /**
     * A record to link: its address, the name and phone it carries, its date, language and the source a new contact gets.
     *
     * @return array{email: string, name: string, phone: string, at: ?string, language: string, source: string, confirmed: bool, contact_id: ?int}|null
     */
    private static function record(Db $db, string $kind, int $id): ?array
    {
        if (!isset(self::LINKED[$kind])) {
            return null;
        }
        if ($kind === 'enquiry') {
            $r = $db->one('SELECT idp, datum, zdroj, stranka, email, data, contact_id, anonymizovano FROM {poptavky} WHERE idp = ?', [$id]);
            if ($r === null || $r['anonymizovano'] !== null) {
                return null;
            }
            $person = self::fromFields(json_decode((string) $r['data'], true) ?: [], (string) $r['email']);

            return ['email' => (string) $r['email'], 'name' => $person['name'], 'phone' => $person['phone'], 'at' => (string) $r['datum'],
                'language' => Language::splitPrefix((string) $r['stranka'])[0] ?? '', 'source' => str_starts_with((string) $r['zdroj'], 'import:') ? 'import' : 'enquiry',
                'confirmed' => true, 'contact_id' => $r['contact_id'] !== null ? (int) $r['contact_id'] : null];
        }
        if ($kind === 'booking') {
            $r = $db->one('SELECT id, created_at, name, email, phone, language, contact_id, anonymised_at FROM {bookings} WHERE id = ?', [$id]);

            return $r === null || $r['anonymised_at'] !== null ? null : ['email' => (string) $r['email'], 'name' => self::line($r['name'], 150), 'phone' => self::line($r['phone'], 40),
                'at' => (string) $r['created_at'], 'language' => (string) $r['language'], 'source' => 'booking', 'confirmed' => true,
                'contact_id' => $r['contact_id'] !== null ? (int) $r['contact_id'] : null];
        }
        $r = $db->one('SELECT ido, email, datum, potvrzeno, stav, contact_id FROM {odberatele} WHERE ido = ?', [$id]);

        return $r === null ? null : ['email' => (string) $r['email'], 'name' => '', 'phone' => '', 'at' => (string) ($r['potvrzeno'] ?? $r['datum']), 'language' => '',
            'source' => 'subscriber', 'confirmed' => (int) $r['stav'] === 1, 'contact_id' => $r['contact_id'] !== null ? (int) $r['contact_id'] : null];
    }

    /**
     * The name and phone a form's fields carry (the stored [label, value] pairs – the field types are not stored): the
     * first field labelled like a name (EnquiryDelivery::NAME_LABEL) and short enough to be one, the first labelled like a
     * phone or holding only a phone number. Never a message. Pure.
     *
     * @param array<mixed> $fields
     * @return array{name: string, phone: string}
     */
    public static function fromFields(array $fields, string $email = ''): array
    {
        $out = ['name' => '', 'phone' => ''];
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field[0], $field[1]) || isset($field[2]) || !is_scalar($field[1])) {
                continue; // an attachment or something malformed
            }
            [$label, $value] = [(string) $field[0], trim((string) $field[1])];
            if ($value === '' || $value === $email || str_contains($value, "\n")) {
                continue;
            }
            if ($out['phone'] === '' && preg_match('/^[+()\d\s\/.-]{6,30}$/', $value) === 1 && preg_match_all('/\d/', $value) >= 6
                && (preg_match('/(phone|tel|mobil|handy|tel[eé]fono|m[oó]vil|telefon)/iu', $label) === 1 || preg_match('/^[+\d]/', $value) === 1)) {
                $out['phone'] = mb_substr($value, 0, 40);
            } elseif ($out['name'] === '' && mb_strlen($value) <= 100 && preg_match(EnquiryDelivery::NAME_LABEL, $label) === 1 && preg_match(EnquiryDelivery::COMPANY_LABEL, $label) !== 1) {
                $out['name'] = self::line($value, 150);
            }
        }

        return $out;
    }

    /**
     * The contact a person merged this address into: a record with the same address that is linked to another contact
     * (merge() moved it there, or update() changed the contact's address) – so a new enquiry from a merged address joins the
     * merged contact, never a new one. Only records of this address: never two different addresses by itself.
     *
     * N328-3: until the first merge or change of a linked address (the setting contacts_merged_at) no record can be linked
     * under another address, so nothing is read – a new address costs no scan of the enquiries (no index on their address)
     * while the linking job or a visitor's submission runs. The address is matched with the columns' own collation (the
     * indexes of bookings and subscribers are used) and checked exactly here.
     *
     * @return array<string, mixed>|null
     */
    private static function mergedInto(Db $db, Settings $s, string $norm, string $kind, int $id): ?array
    {
        if ($s->get('contacts_merged_at') === '') {
            return null;
        }
        foreach (self::LINKED as $other => [$table, $key, $column]) {
            try {
                $rows = $db->all('SELECT ' . $column . ' AS email, contact_id FROM {' . $table . '} WHERE ' . $column . ' = ? AND contact_id IS NOT NULL' . ($other === $kind ? ' AND ' . $key . ' <> ' . $id : '')
                    . ' ORDER BY ' . $key . ' DESC LIMIT 20', [$norm]);
            } catch (\PDOException) {
                continue;
            }
            foreach ($rows as $r) {
                $contact = self::normalise((string) $r['email']) === $norm ? self::find($db, (int) $r['contact_id']) : null;
                if ($contact !== null && $contact['anonymised_at'] === null) {
                    return $contact;
                }
            }
        }

        return null;
    }

    /** A merge or a changed address happened: from now on linking looks for records of an address linked elsewhere (mergedInto). */
    private static function markMerged(Settings $s): void
    {
        if ($s->get('contacts_merged_at') === '') {
            $s->set('contacts_merged_at', Clock::now());
        }
    }

    /** Unlinks the enquiries, bookings and subscriptions of contacts (deleted, anonymised). */
    private static function unlink(Db $db, array $ids): void
    {
        $in = implode(',', array_map('intval', $ids));
        if ($in === '') {
            return;
        }
        foreach (self::LINKED as [$table]) {
            try {
                $db->run('UPDATE {' . $table . '} SET contact_id = NULL WHERE contact_id IN (' . $in . ')');
            } catch (\PDOException) {
                // a table missing (bookings before 3.0)
            }
        }
    }

    /**
     * The fields of create() and update() as columns, checked; only the keys given (all of them for a new contact).
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $contact the stored contact (null = a new one)
     * @return array<string, mixed>
     */
    private static function clean(Db $db, array $fields, ?array $contact): array
    {
        $new = $contact === null;
        $data = [];
        if ($new || array_key_exists('email', $fields)) {
            $raw = is_scalar($fields['email'] ?? null) ? trim((string) $fields['email']) : '';
            $norm = $raw !== '' ? self::normalise($raw) : null;
            if ($raw !== '' && $norm === null) {
                throw new \DomainException(t('“%s” is not an e-mail address.', mb_substr($raw, 0, 100)));
            }
            if ($new || $norm !== $contact['email_norm'] || $raw !== $contact['email']) {
                $other = $norm !== null ? self::findByEmail($db, $norm) : null;
                if ($other !== null && $other['id'] !== ($contact['id'] ?? 0)) {
                    throw new \DomainException(t('A contact with this e-mail address already exists (#%d) – open it, or merge the two.', $other['id']));
                }
                $data['email'] = mb_substr($raw, 0, 190);
                $data['email_norm'] = $norm;
            }
        }
        foreach (['name' => 150, 'phone' => 40] as $key => $max) {
            if ($new || array_key_exists($key, $fields)) {
                $data[$key] = self::line($fields[$key] ?? '', $max);
            }
        }
        if (($data['phone'] ?? '') !== '' && preg_match('/^[+()\d\s\/.-]{3,40}$/', $data['phone']) !== 1) {
            throw new \DomainException(t('“%s” is not a phone number.', $data['phone']));
        }
        if ($new || array_key_exists('language', $fields)) {
            $language = is_scalar($fields['language'] ?? null) ? strtolower(trim((string) $fields['language'])) : '';
            $data['language'] = $language === '' || Language::isOffered($language) ? $language : throw new \DomainException(t('“%s” is not a language Kaleta offers.', $language));
        }
        if ($new || array_key_exists('summary', $fields)) {
            $summary = Comments::clean(is_scalar($fields['summary'] ?? null) ? (string) $fields['summary'] : '');
            $data['summary'] = $summary !== '' ? $summary : null;
        }
        if ($new || array_key_exists('company_id', $fields)) {
            $companyId = (int) ($fields['company_id'] ?? 0);
            if ($companyId > 0 && self::findCompany($db, $companyId) === null) {
                throw new \DomainException(t('The company does not exist.'));
            }
            $data['company_id'] = $companyId > 0 ? $companyId : null;
        }
        if ($new || array_key_exists('owner_id', $fields)) {
            $data['owner_id'] = self::owner($db, $fields['owner_id'] ?? null, $contact['owner_id'] ?? null);
        }
        if ($contact !== null) {
            $data = array_filter($data, fn (mixed $v, string $k): bool => $v !== ($contact[$k] ?? null), ARRAY_FILTER_USE_BOTH);
        }

        return $data;
    }

    /** A responsible person as stored: null for nobody, the id of someone who may open Customers, or the refusal. */
    private static function owner(Db $db, mixed $value, ?int $stored): ?int
    {
        $id = (int) (is_scalar($value) ? $value : 0);
        if ($id <= 0) {
            return null;
        }
        if ($id !== $stored && !Assignment::allowed($db, 'contact', $id)) {
            throw new \DomainException(t('This person cannot open Customers (or the account is blocked) – choose someone who can.'));
        }

        return $id;
    }

    /** Tells the new responsible person of a contact or a company (Assignment::notify – never oneself). Never fails the save. */
    private static function notifyOwner(App $app, string $kind, int $id, int $toUserId, int $byUserId): void
    {
        try {
            Assignment::notify($app, $kind, $id, $toUserId, $byUserId);
        } catch (\Throwable) {
            // the e-mail is a courtesy; the save stands
        }
    }

    /** The refusal for a duplicate address (the unique key), or the error itself. */
    private static function duplicate(Db $db, \PDOException $e, string $norm): \Throwable
    {
        $other = $norm !== '' ? self::findByEmail($db, $norm) : null;

        return $other !== null ? new \DomainException(t('A contact with this e-mail address already exists (#%d) – open it, or merge the two.', $other['id'])) : $e;
    }

    /** @param array<string, mixed> $user */
    private static function insertActivity(Db $db, int $contactId, string $kind, string $text, array $user, string $at): int
    {
        $name = trim((string) ($user['jmeno'] ?? '')) !== '' ? trim((string) $user['jmeno']) : (string) ($user['user'] ?? '');

        return $db->insert('contact_activities', ['contact_id' => $contactId, 'kind' => $kind, 'text' => $text, 'author_id' => (int) ($user['idu'] ?? 0) > 0 ? (int) $user['idu'] : null,
            'author_name' => mb_substr($name, 0, 100), 'happened_at' => $at, 'created_at' => Clock::now()]);
    }

    /** One line of text: whitespace folded, control characters removed, at most $max characters. Pure. */
    private static function line(mixed $value, int $max): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) preg_replace('/\s+/u', ' ', strip_tags($value)))), 0, $max);
    }

    /**
     * A row of ka_contacts with its numbers as numbers.
     *
     * @param array<string, mixed> $r
     * @return array{id: int, email: string, email_norm: ?string, name: string, phone: string, company_id: ?int, company_name: ?string, owner_id: ?int, language: string, source: string, summary: string, first_seen_at: ?string, last_activity_at: ?string, created_at: string, updated_at: string, anonymised_at: ?string}
     */
    private static function row(array $r): array
    {
        $int = fn (mixed $v): ?int => $v !== null ? (int) $v : null;
        $str = fn (mixed $v): ?string => $v !== null ? (string) $v : null;

        return ['id' => (int) $r['id'], 'email' => (string) $r['email'], 'email_norm' => $str($r['email_norm']), 'name' => (string) $r['name'], 'phone' => (string) $r['phone'],
            'company_id' => $int($r['company_id']), 'company_name' => $str($r['company_name'] ?? null), 'owner_id' => $int($r['owner_id']), 'language' => (string) $r['language'],
            'source' => (string) $r['source'], 'summary' => (string) ($r['summary'] ?? ''), 'first_seen_at' => $str($r['first_seen_at']), 'last_activity_at' => $str($r['last_activity_at']),
            'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at'], 'anonymised_at' => $str($r['anonymised_at'])];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, name: string, domain: string, registration_no: string, vat_id: string, owner_id: ?int, note: string, created_at: string, updated_at: string}
     */
    private static function companyRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'domain' => (string) $r['domain'], 'registration_no' => (string) $r['registration_no'], 'vat_id' => (string) $r['vat_id'],
            'owner_id' => $r['owner_id'] !== null ? (int) $r['owner_id'] : null, 'note' => (string) ($r['note'] ?? ''), 'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at']];
    }
}
