<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Contacts;
use Kaleta\Core\Handover;
use Kaleta\Core\Language;
use Kaleta\Mcp\Schema;

/**
 * MCP tools of contacts and companies (3.28, Core\Contacts): list_contacts and get_contact read the customers,
 * save_contact creates or changes one (and its company through the company_* fields), log_activity logs a call, a
 * meeting, an e-mail or a note, merge_contacts joins two records of one person (destructive, confirm: true; administrators
 * only, as in the administration). Only for a
 * user who may open Customers (Contacts::mayOpen). Contacts are personal data: never journaled, so undo_agent_session
 * cannot take these changes back (the tool descriptions say so), and they never change the site (Catalog::SITE_NEUTRAL).
 * Refusals come in English (Language::runWith). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait ContactTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function contactToolsDefinitions(): array
    {
        return [
            'list_contacts' => ['The customers of the site (read-only, users with Customers): one contact per e-mail address across its enquiries, bookings and newsletter subscription – name, e-mail, phone, company, the responsible person, the source, first seen and last activity, how many enquiries and bookings. PERSONAL DATA: list them when the user asks. '
                . 'Without filters the most recently active first. Companies: kind companies.',
                Schema::object(['search' => Schema::text('a word in the name, e-mail, phone or company'), 'owner' => Schema::text('a user id, e-mail or "me"; "none" = nobody is responsible'), 'company' => Schema::number('only the contacts of this company id'),
                    'inactive_months' => Schema::number('only contacts with no activity for at least this many months'), 'sort' => Schema::text('activity (default) | name | created'),
                    'kind' => Schema::text('contacts (default) | companies'), 'page' => Schema::number('page, default 1'), 'limit' => Schema::number('1–100, default 25')])],
            'get_contact' => ['One customer (read-only): what the contact holds, its company, counts and a bounded timeline, newest first – enquiries (form, topic, status – never the message; read one with list_enquiries), replies sent, bookings, the subscription, the team\'s comments and tasks, and logged calls, meetings, e-mails and notes. PERSONAL DATA. '
                . 'By id or e-mail.',
                Schema::object(['id' => Schema::number('contact id (list_contacts)'), 'email' => Schema::text('or the e-mail address'), 'timeline' => Schema::number('how many timeline entries, 0–50, default 20')])],
            'save_contact' => ['Creates a contact, or changes the given fields of one with id (users with Customers). An address that already has a contact is refused – never a second record of the same person; to join two records use merge_contacts. Company: company (an id, or a name – an existing company of that name is used, otherwise it is created) and company_domain, company_registration_no, company_vat_id to fill in the company. '
                . 'NOT UNDOABLE: contacts are personal data and never enter the undo journal – undo_agent_session cannot take this back. Never shown on the site.',
                Schema::object(['id' => Schema::number('contact id to change; without it a new contact'), 'email' => Schema::text('e-mail address'), 'name' => Schema::text('name'), 'phone' => Schema::text('phone'),
                    'language' => Schema::text('the language code the customer uses, e.g. en'), 'summary' => Schema::text('a short summary for the team, plain text'),
                    'owner' => Schema::text('the responsible person: a user id, e-mail or "me"; "none" = nobody'), 'company' => Schema::text('company id or name; "none" = no company'),
                    'company_domain' => Schema::text('the company\'s web domain, e.g. acme.com'), 'company_registration_no' => Schema::text('company registration number'), 'company_vat_id' => Schema::text('VAT ID')])],
            'log_activity' => ['Logs what happened with a customer: a call, a meeting, an e-mail or a note, as the connected user – it shows on the contact\'s timeline and counts as its latest activity. NOT UNDOABLE: personal data never enters the undo journal.',
                Schema::object(['contact' => Schema::number('contact id (list_contacts)'), 'kind' => Schema::text('call | meeting | email | note'), 'text' => Schema::text('what happened, plain text up to 5000 characters'),
                    'at' => Schema::text('when: YYYY-MM-DD HH:MM, "today"; default now')], ['contact', 'kind', 'text'])],
            'merge_contacts' => ['Merges two contacts of the same person – only on the user\'s explicit request: the enquiries, bookings, subscription, logged activities, comments and tasks of from move to into; into keeps its e-mail and fills its empty fields from from; from is deleted and a note on into records the merge. Kaleta never merges two different addresses by itself. '
                . 'Administrators only. DESTRUCTIVE AND NOT UNDOABLE: needs confirm true.',
                Schema::object(['into' => Schema::number('the contact that stays'), 'from' => Schema::number('the contact merged into it and deleted'), 'confirm' => ['type' => 'boolean', 'description' => 'true – the user asked for this merge']], ['into', 'from', 'confirm'])],
        ];
    }

    /** list_contacts */
    private function toolListContacts(string $name, array $a): mixed
    {
        $this->contactsOpen();
        $db = $this->app->db();
        $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $search = is_scalar($a['search'] ?? null) ? trim((string) $a['search']) : '';
        $owner = 0;
        if (array_key_exists('owner', $a) && is_scalar($a['owner']) && trim((string) $a['owner']) !== '') {
            $owner = Handover::resolve($db, $a['owner'], $this->app->auth()->id()) ?? -1;
        }
        if (($a['kind'] ?? 'contacts') === 'companies') {
            $result = Contacts::companies($db, ['search' => $search, 'owner' => $owner], $page, $limit);

            return ['kind' => 'companies', 'companies' => array_map(fn (array $c): array => $this->companyOut($c) + ['contacts' => $c['contacts']], $result['rows']),
                'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages']] + \Kaleta\Mcp\Untrusted::mark(['companies']); // 3.35 (N105): names from forms
        }
        $filter = ['search' => $search, 'owner' => $owner, 'company' => (int) ($a['company'] ?? 0), 'sort' => is_string($a['sort'] ?? null) ? $a['sort'] : 'activity'];
        if ((int) ($a['inactive_months'] ?? 0) > 0) {
            $filter['inactive_before'] = \Kaleta\Core\Clock::monthsAgo((int) $a['inactive_months']);
        }
        $result = Contacts::list($db, $filter, $page, $limit);

        return ['contacts' => array_map(fn (array $c): array => $this->contactOut($c) + ['enquiries' => $c['enquiries'], 'bookings' => $c['bookings']] + ($c['subscribed'] ? ['subscribed' => true] : []), $result['rows']),
            'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'], 'admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . Contacts::SECTION)]
            + \Kaleta\Mcp\Untrusted::mark(['contacts']) // 3.35 (N105): names and addresses as visitors typed them
            + ($result['total'] === 0 ? ['next' => 'No contacts here. New enquiries, bookings and confirmed subscriptions create them; save_contact adds one by hand.'] : []);
    }

    /** get_contact */
    private function toolGetContact(string $name, array $a): mixed
    {
        $this->contactsOpen();
        $db = $this->app->db();
        $contact = $this->contactFrom($a);
        $limit = max(0, min(50, (int) ($a['timeline'] ?? 20)));
        $counts = Contacts::linkCounts($db, [$contact['id']])[$contact['id']] ?? ['enquiries' => 0, 'bookings' => 0, 'subscribed' => false];
        $company = $contact['company_id'] !== null ? Contacts::findCompany($db, $contact['company_id']) : null;
        $origin = $this->app->request->origin();
        // 3.48 review: with the Pipeline switched off the timeline carries no deal at all (as the admin timeline) – not even a count
        $pipelineOn = \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'pipeline');
        $timeline = $limit > 0 ? Contacts::timeline($db, $contact['id'], $limit, $this->app->auth()->hasModule(\Kaleta\Core\Applications::SECTION), $pipelineOn) : [];
        if ($timeline !== [] && !\Kaleta\Core\Deals::mayOpen($db, $this->app->settings(), $this->app->auth()->id())) {
            $timeline = \Kaleta\Core\Deals::neutralTimeline($timeline); // 3.31.1: without the pipeline a deal is only "a deal"
        }

        return ['contact' => $this->contactOut($contact) + ($contact['summary'] !== '' ? ['summary' => $contact['summary']] : [])]
            + ($company !== null ? ['company' => $this->companyOut($company)] : [])
            + ['counts' => $counts,
                'timeline' => array_map(fn (array $e): array => ['at' => substr((string) $e['at'], 0, 16), 'kind' => $e['kind']]
                    + ($e['title'] !== '' ? ['title' => $e['title']] : [])
                    + (isset($e['text']) ? ['text' => mb_strimwidth((string) $e['text'], 0, 200, '…')] : [])
                    + (isset($e['by']) ? ['by' => $e['by']] : []) + (isset($e['status']) ? ['status' => $e['status']] : []) + (isset($e['ref']) ? ['ref' => $e['ref']] : []), $timeline),
                'admin_url' => $origin . $this->app->url(Contacts::adminPath($contact['id']))]
            + ($contact['anonymised_at'] !== null ? ['anonymised_at' => substr($contact['anonymised_at'], 0, 16)] : [])
            + self::linkedFiles(\Kaleta\Core\InternalFileLinks::ofRecord($this->app, 'contact', $contact['id'])) // 3.49 B: only files this user can open
            + \Kaleta\Mcp\Untrusted::mark(['contact', 'company', 'timeline']) // 3.35 (N105): what visitors typed into forms and bookings
            + ['note' => 'Personal data – show the user what they asked for. Enquiry messages are not part of the timeline; read one with list_enquiries.'
                . ($pipelineOn ? ' A deal entry without its title and stage means the user may not open the pipeline.' : '')];
    }

    /** save_contact */
    private function toolSaveContact(string $name, array $a): mixed
    {
        $this->contactsOpen();
        $db = $this->app->db();
        $self = $this->app->auth()->id();
        $id = (int) ($a['id'] ?? 0);
        $fields = [];
        foreach (['email', 'name', 'phone', 'language', 'summary'] as $key) {
            if (array_key_exists($key, $a)) {
                $fields[$key] = is_scalar($a[$key]) ? (string) $a[$key] : '';
            }
        }
        if (array_key_exists('owner', $a)) {
            $fields['owner_id'] = Handover::resolve($db, $a['owner'], $self) ?? 0;
        }
        $created = $id <= 0;
        if (!$created && Contacts::find($db, $id) === null) {
            throw new \InvalidArgumentException('The contact does not exist. Use list_contacts.');
        }
        try {
            Language::runWith('en', function () use (&$id, &$fields, $a, $self, $created, $db): void {
                $companyId = $this->contactCompany($a, $self, $created ? null : (Contacts::find($db, $id)['company_id'] ?? null));
                if ($companyId !== false) {
                    $fields['company_id'] = $companyId ?? 0;
                }
                if ($created) {
                    $id = Contacts::create($this->app, $fields, $self);
                } else {
                    Contacts::update($this->app, $id, $fields, $self);
                }
            }, 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing was saved.');
        }
        $contact = Contacts::find($db, $id) ?? throw new \DomainException('The contact does not exist.');
        \Kaleta\Core\ChangeLog::write($this->app, Contacts::SECTION, $created ? 'create' : 'save', '#' . $id);
        $company = $contact['company_id'] !== null ? Contacts::findCompany($db, $contact['company_id']) : null;

        return ['id' => $id, ($created ? 'created' : 'updated') => true, 'contact' => $this->contactOut($contact)]
            + ($company !== null ? ['company' => $this->companyOut($company)] : [])
            + ['note' => 'Not undoable: contacts are personal data and never enter the undo journal.'];
    }

    /** log_activity */
    private function toolLogActivity(string $name, array $a): mixed
    {
        $this->contactsOpen();
        $contactId = (int) ($a['contact'] ?? 0);
        $kind = is_string($a['kind'] ?? null) ? strtolower(trim($a['kind'])) : '';
        $text = is_string($a['text'] ?? null) ? $a['text'] : '';
        $at = is_scalar($a['at'] ?? null) ? (string) $a['at'] : null;
        try {
            $id = Language::runWith('en', fn (): int => Contacts::logActivity($this->app, $contactId, $kind, $text, $this->app->auth()->user() ?? [], $at), 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing was saved.');
        }
        $activity = Contacts::activities($this->app->db(), $contactId, 1000);
        $row = array_values(array_filter($activity, fn (array $x): bool => $x['id'] === $id))[0] ?? null;

        return ['id' => $id, 'contact' => $contactId, 'logged' => true, 'kind' => $kind] + ($row !== null ? ['at' => substr($row['at'], 0, 16)] : [])
            + ['admin_url' => $this->app->request->origin() . $this->app->url(Contacts::adminPath($contactId))];
    }

    /** merge_contacts */
    private function toolMergeContacts(string $name, array $a): mixed
    {
        $this->contactsOpen();
        if (!$this->app->auth()->isAdmin()) {
            // as in the administration (Admin\Modules\Contacts::actionMerge): merging deletes a record – administrators only (N328-1)
            throw new \DomainException('Only an administrator can merge customers. Nothing changed.');
        }
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Merging needs confirm=true – only when the user asked to merge these two contacts. It cannot be undone.');
        }
        $into = (int) ($a['into'] ?? 0);
        $from = (int) ($a['from'] ?? 0);
        try {
            $result = Language::runWith('en', fn (): array => Contacts::merge($this->app, $into, $from, $this->app->auth()->id()), 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing changed.');
        }
        $contact = Contacts::find($this->app->db(), $into);

        return ['merged' => true, 'into' => $into, 'from' => $from, 'moved' => $result['moved']] + ($contact !== null ? ['contact' => $this->contactOut($contact)] : [])
            + ['admin_url' => $this->app->request->origin() . $this->app->url(Contacts::adminPath($into))];
    }

    /** The connected user must be able to open Customers – otherwise an error says why. */
    private function contactsOpen(): void
    {
        if (!Contacts::mayOpen($this->app->db(), $this->app->settings(), $this->app->auth()->id())) {
            throw new \DomainException('Only a user who may open Customers can read or change contacts – an administrator gives the section in Users.');
        }
    }

    /**
     * The contact a call names by id or e-mail.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function contactFrom(array $a): array
    {
        $db = $this->app->db();
        if ((int) ($a['id'] ?? 0) > 0) {
            return Contacts::find($db, (int) $a['id']) ?? throw new \InvalidArgumentException('The contact does not exist. Use list_contacts.');
        }
        if (is_string($a['email'] ?? null) && trim($a['email']) !== '') {
            return Contacts::findByEmail($db, $a['email']) ?? throw new \InvalidArgumentException('No contact has this e-mail address. Use list_contacts with search.');
        }
        throw new \InvalidArgumentException('Give id or email.');
    }

    /**
     * The company a save_contact call names: false = not given (unchanged), null = none, or an id – an existing one by id or
     * name, or a new one created from the name; the company_* fields fill it in.
     *
     * @param array<string, mixed> $a
     */
    private function contactCompany(array $a, int $self, ?int $current): int|false|null
    {
        $db = $this->app->db();
        $details = [];
        foreach (['company_domain' => 'domain', 'company_registration_no' => 'registration_no', 'company_vat_id' => 'vat_id'] as $param => $key) {
            if (array_key_exists($param, $a)) {
                $details[$key] = is_scalar($a[$param]) ? (string) $a[$param] : '';
            }
        }
        $raw = array_key_exists('company', $a) && is_scalar($a['company']) ? trim((string) $a['company']) : null;
        if ($raw === null) {
            if ($details !== [] && $current !== null) {
                Contacts::saveCompany($this->app, $current, $details, $self);
            } elseif ($details !== []) {
                throw new \DomainException('Name the company (company) before its details.');
            }

            return false;
        }
        if ($raw === '' || in_array(strtolower($raw), ['none', 'null'], true)) {
            return null;
        }
        $company = ctype_digit($raw) ? Contacts::findCompany($db, (int) $raw) : Contacts::companyByName($db, $raw);
        if ($company === null && ctype_digit($raw)) {
            throw new \DomainException('The company does not exist. Use list_contacts with kind companies.');
        }
        $id = $company !== null ? $company['id'] : Contacts::saveCompany($this->app, null, ['name' => $raw], $self);
        if ($details !== []) {
            Contacts::saveCompany($this->app, $id, $details, $self);
        }

        return $id;
    }

    /**
     * A contact as the tools show it.
     *
     * @param array<string, mixed> $c a row of Contacts::find
     * @return array<string, mixed>
     */
    private function contactOut(array $c): array
    {
        $db = $this->app->db();

        return ['id' => $c['id']] + array_filter(['name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'language' => $c['language']], fn (string $v): bool => $v !== '')
            + ($c['company_id'] !== null ? ['company' => ['id' => $c['company_id'], 'name' => (string) $c['company_name']]] : [])
            + ['owner' => Handover::person($db, $c['owner_id']), 'source' => $c['source']]
            + ($c['first_seen_at'] !== null ? ['first_seen' => substr($c['first_seen_at'], 0, 16)] : [])
            + ($c['last_activity_at'] !== null ? ['last_activity' => substr($c['last_activity_at'], 0, 16)] : [])
            + ($c['anonymised_at'] !== null ? ['anonymised' => true] : []);
    }

    /**
     * @param array<string, mixed> $c a row of Contacts::findCompany
     * @return array<string, mixed>
     */
    private function companyOut(array $c): array
    {
        return ['id' => $c['id'], 'name' => $c['name']] + array_filter(['domain' => $c['domain'], 'registration_no' => $c['registration_no'], 'vat_id' => $c['vat_id']], fn (string $v): bool => $v !== '')
            + ($c['owner_id'] !== null ? ['owner' => Handover::person($this->app->db(), $c['owner_id'])] : [])
            + ['admin_url' => $this->app->request->origin() . $this->app->url(Contacts::companyAdminPath($c['id']))];
    }
}
