<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Contacts;
use Kaleta\Core\Handover;
use Kaleta\Core\Language;

/**
 * MCP tools of contacts and companies (3.28, Core\Contacts): list_contacts and get_contact read the customers,
 * save_contact creates or changes one (and its company through the company_* fields), log_activity logs a call, a
 * meeting, an e-mail or a note, merge_contacts joins two records of one person (destructive, confirm: true). Only for a
 * user who may open Customers (Contacts::mayOpen). Contacts are personal data: never journaled, so undo_agent_session
 * cannot take these changes back (the tool descriptions say so), and they never change the site (Catalog::SITE_NEUTRAL).
 * Refusals come in English (Language::runWith). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait ContactTools
{
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
                'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages']];
        }
        $filter = ['search' => $search, 'owner' => $owner, 'company' => (int) ($a['company'] ?? 0), 'sort' => is_string($a['sort'] ?? null) ? $a['sort'] : 'activity'];
        if ((int) ($a['inactive_months'] ?? 0) > 0) {
            $filter['inactive_before'] = \Kaleta\Core\Clock::monthsAgo((int) $a['inactive_months']);
        }
        $result = Contacts::list($db, $filter, $page, $limit);

        return ['contacts' => array_map(fn (array $c): array => $this->contactOut($c) + ['enquiries' => $c['enquiries'], 'bookings' => $c['bookings']] + ($c['subscribed'] ? ['subscribed' => true] : []), $result['rows']),
            'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'], 'admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . Contacts::SECTION)]
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
        $timeline = $limit > 0 ? Contacts::timeline($db, $contact['id'], $limit) : [];

        return ['contact' => $this->contactOut($contact) + ($contact['summary'] !== '' ? ['summary' => $contact['summary']] : [])]
            + ($company !== null ? ['company' => $this->companyOut($company)] : [])
            + ['counts' => $counts,
                'timeline' => array_map(fn (array $e): array => ['at' => substr((string) $e['at'], 0, 16), 'kind' => $e['kind']]
                    + ($e['title'] !== '' ? ['title' => $e['title']] : [])
                    + (isset($e['text']) ? ['text' => mb_strimwidth((string) $e['text'], 0, 200, '…')] : [])
                    + (isset($e['by']) ? ['by' => $e['by']] : []) + (isset($e['status']) ? ['status' => $e['status']] : []) + (isset($e['ref']) ? ['ref' => $e['ref']] : []), $timeline),
                'admin_url' => $origin . $this->app->url(Contacts::adminPath($contact['id']))]
            + ($contact['anonymised_at'] !== null ? ['anonymised_at' => substr($contact['anonymised_at'], 0, 16)] : [])
            + ['note' => 'Personal data – show the user what they asked for. Enquiry messages are not part of the timeline; read one with list_enquiries.'];
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
        \Kaleta\Admin\ChangeLog::write($this->app, Contacts::SECTION, $created ? 'create' : 'save', '#' . $id);
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
