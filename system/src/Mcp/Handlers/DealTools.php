<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Deals;
use Kaleta\Core\Handover;
use Kaleta\Core\Language;
use Kaleta\Mcp\Schema;

/**
 * MCP tools of the lean pipeline (3.31, Core\Deals): list_deals reads the deals, save_deal creates one (also from an
 * enquiry) or changes its fields, move_deal moves it to another stage (won, lost with a reason, reopened) and
 * pipeline_summary gives the counts per stage with the sums per currency. Only for a user who may open the pipeline
 * (Deals::mayOpen); as in the administration, a deal from an enquiry also needs Enquiries and choosing a deal's contact or
 * company needs Customers; deleting a deal stays in the administration (administrators). Deals point to customers: never
 * journaled, so undo_agent_session cannot take these changes back (the tool descriptions say so), and they never change
 * the site (Catalog::SITE_NEUTRAL). Refusals come in English (Language::runWith). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait DealTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function dealToolsDefinitions(): array
    {
        return [
            'list_deals' => ['The deals of the pipeline (read-only, users with the pipeline): title, stage, status (open | won | lost), value and currency, the contact, company and responsible person, expected close, the lost reason, the enquiry it came from; and the stages in order. Names customers: list them when the user asks. Open deals by expected close by default.',
                Schema::object(['status' => Schema::text('open (default) | closed | won | lost | all'), 'stage' => Schema::text('a stage key (stages)'), 'owner' => Schema::text('a user id, e-mail or "me"; "none" = nobody is responsible'),
                    'contact' => Schema::number('only the deals of this contact id'), 'company' => Schema::number('only the deals of this company id'), 'search' => Schema::text('a word in the title, contact or company'),
                    'next_step' => Schema::text('"none" = deals with no open task'),
                    'page' => Schema::number('page, default 1'), 'limit' => Schema::number('1–100, default 25')])],
            'save_deal' => ['Creates a deal, or changes the given fields of one with id (users with the pipeline). enquiry = a new deal from that enquiry: the title from the person\'s name and the first words of the message (unless title is given), its customer, its handler as responsible; one open deal per enquiry. A new deal starts in the first open stage (or stage); move it with move_deal. Value: a number >= 0 with an ISO currency (EUR, CZK). '
                . 'NOT UNDOABLE: deals never enter the undo journal. Never shown on the site.',
                Schema::object(['id' => Schema::number('deal id to change; without it a new deal'), 'enquiry' => Schema::number('a new deal from this enquiry (list_enquiries)'), 'title' => Schema::text('what the deal is, up to 200 characters'),
                    'contact' => Schema::number('contact id (list_contacts); 0 = none'), 'company' => Schema::number('company id; 0 = none (a new deal takes the contact\'s company)'),
                    'owner' => Schema::text('the responsible person: a user id, e-mail or "me"; "none" = nobody'), 'value' => Schema::text('e.g. 12500 or 12500.50; empty = none'),
                    'currency' => Schema::text('ISO currency code, e.g. EUR'), 'expected_close' => Schema::text('YYYY-MM-DD; "none" = no date'), 'stage' => Schema::text('a new deal only (also with enquiry): the open stage it starts in; default the first open stage')])],
            'move_deal' => ['Moves a deal to another stage: the move is kept in its history; a won or lost stage closes it, lost needs lost_reason; moving a closed deal to an open stage reopens it. NOT UNDOABLE: never in the undo journal.',
                Schema::object(['id' => Schema::number('deal id (list_deals)'), 'stage' => Schema::text('the stage key to move to (list_deals stages)'),
                    'lost_reason' => Schema::text('for a lost stage: ' . implode(' | ', array_keys(\Kaleta\Core\Deals::LOST_REASONS))), 'lost_note' => Schema::text('for a lost stage: an optional note, up to 500 characters')], ['id', 'stage'])],
            'pipeline_summary' => ['The pipeline at a glance (read-only): per stage the count and the value as sums per currency (open stages: the open deals; won and lost: closed this month), how many are open, won and lost this month. Never add different currencies together.',
                Schema::object(['owner' => Schema::text('only the deals of a user id, e-mail or "me"; "none" = nobody is responsible')])],
        ];
    }

    /** list_deals */
    private function toolListDeals(string $name, array $a): mixed
    {
        $this->pipelineOpen();
        $self = $this->app->auth()->id();
        $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));
        $filter = ['status' => is_string($a['status'] ?? null) ? strtolower(trim($a['status'])) : 'open', 'stage' => is_string($a['stage'] ?? null) ? trim($a['stage']) : '',
            'contact' => (int) ($a['contact'] ?? 0), 'company' => (int) ($a['company'] ?? 0), 'search' => is_scalar($a['search'] ?? null) ? (string) $a['search'] : '',
            'next' => ($a['next_step'] ?? '') === 'none' ? 'none' : '']; // 3.44: no next step, as the board's filter
        if (!in_array($filter['status'], Deals::STATUSES, true)) {
            throw new \InvalidArgumentException('status must be ' . implode(' | ', Deals::STATUSES) . '.');
        }
        if ($filter['stage'] !== '' && Deals::stage($this->app->settings(), $filter['stage']) === null) {
            throw new \InvalidArgumentException('The pipeline has no stage "' . mb_substr($filter['stage'], 0, 40) . '". Stages: ' . implode(', ', array_column(Deals::stages($this->app->settings()), 'key')) . '.');
        }
        if (array_key_exists('owner', $a) && is_scalar($a['owner']) && trim((string) $a['owner']) !== '') {
            $filter['owner'] = Handover::resolve($this->app->db(), $a['owner'], $self) ?? -1;
        }
        $result = Language::runWith('en', fn (): array => Deals::list($this->app, $filter, max(1, (int) ($a['page'] ?? 1)), $limit, $self), 'admin-');
        $stages = $this->stagesOut();

        return ['deals' => array_map(fn (array $d): array => $this->dealOut($d, $stages), $result['rows']), 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'],
            'stages' => array_values($stages), 'admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . Deals::SECTION)]
            + ($result['total'] === 0 ? ['next' => 'No deals here. save_deal creates one (with enquiry: from an enquiry); status all lists the closed ones too.'] : []);
    }

    /** save_deal */
    private function toolSaveDeal(string $name, array $a): mixed
    {
        $this->pipelineOpen();
        $db = $this->app->db();
        $self = $this->app->auth()->id();
        $id = (int) ($a['id'] ?? 0);
        $enquiry = (int) ($a['enquiry'] ?? 0);
        $fields = [];
        foreach (['title' => 'title', 'value' => 'value', 'currency' => 'currency', 'expected_close' => 'expected_close', 'stage' => 'stage', 'contact' => 'contact_id', 'company' => 'company_id'] as $param => $key) {
            if (array_key_exists($param, $a)) {
                $value = $a[$param];
                $fields[$key] = is_scalar($value) ? (in_array($param, ['contact', 'company'], true) && in_array(strtolower(trim((string) $value)), ['none', 'null', ''], true) ? 0 : $value) : null;
            }
        }
        if (array_key_exists('owner', $a)) {
            $fields['owner_id'] = Handover::resolve($db, $a['owner'], $self) ?? 0;
        }
        if ($id > 0 && Deals::get($db, $id) === null) {
            throw new \InvalidArgumentException('The deal does not exist. Use list_deals.');
        }
        if ($id > 0 && $enquiry > 0) {
            throw new \InvalidArgumentException('enquiry creates a new deal – leave out id.');
        }
        // N331-1: as in the administration – a deal from an enquiry needs Enquiries, choosing its contact or company needs
        // Customers (otherwise any enquiry's or contact's name and e-mail could be read back through the deal)
        if ($enquiry > 0 && !Handover::mayOpen($db, $this->app->settings(), $self, 'enquiries')) {
            throw new \DomainException('Only a user who may open Enquiries can create a deal from an enquiry. Nothing was saved.');
        }
        if ((array_key_exists('contact_id', $fields) || array_key_exists('company_id', $fields)) && !Handover::mayOpen($db, $this->app->settings(), $self, \Kaleta\Core\Contacts::SECTION)) {
            throw new \DomainException('Only a user who may open Customers can choose the contact or company of a deal – leave out contact and company. Nothing was saved.');
        }
        try {
            $id = Language::runWith('en', fn (): int => match (true) {
                $id > 0 => Deals::save($this->app, $id, $fields, $self),
                $enquiry > 0 => Deals::createFromEnquiry($this->app, $enquiry, $self, $fields),
                default => Deals::save($this->app, null, $fields, $self),
            }, 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing was saved.');
        }
        $deal = Deals::get($db, $id) ?? throw new \DomainException('The deal does not exist.');
        $created = (int) ($a['id'] ?? 0) <= 0;

        return ['id' => $id, ($created ? 'created' : 'updated') => true, 'deal' => $this->dealOut($deal, $this->stagesOut())]
            + ['note' => 'Not undoable: deals point to customers and never enter the undo journal. Move it with move_deal.'];
    }

    /** move_deal */
    private function toolMoveDeal(string $name, array $a): mixed
    {
        $this->pipelineOpen();
        $id = (int) ($a['id'] ?? 0);
        $stage = is_string($a['stage'] ?? null) ? strtolower(trim($a['stage'])) : '';
        $before = Deals::get($this->app->db(), $id) ?? throw new \InvalidArgumentException('The deal does not exist. Use list_deals.');
        try {
            $deal = Language::runWith('en', fn (): array => Deals::move($this->app, $id, $stage, $this->app->auth()->id(), is_string($a['lost_reason'] ?? null) ? $a['lost_reason'] : null,
                is_string($a['lost_note'] ?? null) ? $a['lost_note'] : ''), 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing changed.');
        }

        return ['id' => $id, 'moved' => $before['stage'] !== $deal['stage'], 'from' => $before['stage'], 'deal' => $this->dealOut($deal, $this->stagesOut())];
    }

    /** pipeline_summary */
    private function toolPipelineSummary(string $name, array $a): mixed
    {
        $this->pipelineOpen();
        $owner = 0;
        if (array_key_exists('owner', $a) && is_scalar($a['owner']) && trim((string) $a['owner']) !== '') {
            $owner = Handover::resolve($this->app->db(), $a['owner'], $this->app->auth()->id()) ?? -1;
        }
        $summary = Language::runWith('en', fn (): array => Deals::summary($this->app, $owner), 'admin-');

        return ['month' => $summary['month'], 'open' => $summary['open'], 'won_this_month' => $summary['won_month'], 'lost_this_month' => $summary['lost_month']]
            + ($summary['open_sums'] !== [] ? ['open_value' => $summary['open_sums']] : [])
            + ['stages' => array_map(fn (array $st): array => ['key' => $st['key'], 'label' => $st['label'], 'kind' => $st['kind'], 'count' => $st['count']] + ($st['sums'] !== [] ? ['value' => $st['sums']] : []),
                $summary['stages']),
                'note' => 'Open stages count the open deals; won and lost stages the deals closed this month. Values are sums per currency – never add different currencies together.',
                'admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . Deals::SECTION)];
    }

    /** The connected user must be able to open the pipeline – otherwise an error says why. */
    private function pipelineOpen(): void
    {
        if (!Deals::mayOpen($this->app->db(), $this->app->settings(), $this->app->auth()->id())) {
            throw new \DomainException('Only a user who may open the pipeline can read or change deals – an administrator gives the section in Users.');
        }
    }

    /**
     * The stages in English as the tools show them: key => {key, label, kind}.
     *
     * @return array<string, array{key: string, label: string, kind: string}>
     */
    private function stagesOut(): array
    {
        $out = [];
        foreach (Language::runWith('en', fn (): array => Deals::stages($this->app->settings()), 'admin-') as $st) {
            $out[$st['key']] = ['key' => $st['key'], 'label' => $st['label'], 'kind' => $st['kind']];
        }

        return $out;
    }

    /**
     * A deal as the tools show it.
     *
     * @param array<string, mixed> $d a row of Deals::get
     * @param array<string, array{key: string, label: string, kind: string}> $stages
     * @return array<string, mixed>
     */
    private function dealOut(array $d, array $stages): array
    {
        $kind = $stages[$d['stage']]['kind'] ?? 'open';
        $contact = $d['contact_id'] !== null ? array_filter(['id' => $d['contact_id'], 'name' => (string) ($d['contact_name'] ?? ''), 'email' => (string) ($d['contact_email'] ?? '')], fn (mixed $v): bool => $v !== '') : null;

        return ['id' => $d['id'], 'title' => $d['title'], 'stage' => $d['stage'], 'stage_label' => $stages[$d['stage']]['label'] ?? $d['stage'],
            'status' => $d['closed_at'] === null ? 'open' : $kind]
            + ($d['value'] !== null ? ['value' => $d['value'], 'currency' => (string) $d['currency']] : [])
            + ($contact !== null ? ['contact' => $contact] : [])
            + ($d['company_id'] !== null ? ['company' => ['id' => $d['company_id'], 'name' => (string) ($d['company_name'] ?? '')]] : [])
            + ['owner' => Handover::person($this->app->db(), $d['owner_id'])]
            + ($d['expected_close'] !== null ? ['expected_close' => $d['expected_close']] : [])
            + ($d['closed_at'] !== null ? ['closed_at' => substr((string) $d['closed_at'], 0, 16)] : [])
            + ($d['lost_reason'] !== null ? ['lost_reason' => $d['lost_reason']] + ($d['lost_note'] !== '' ? ['lost_note' => $d['lost_note']] : []) : [])
            + ($d['source_enquiry_id'] !== null ? ['enquiry' => $d['source_enquiry_id']] : [])
            + ['created_at' => substr((string) $d['created_at'], 0, 16), 'admin_url' => $this->app->request->origin() . $this->app->url(Deals::adminPath($d['id']))]
            // 3.44 (N128): a deal made from an enquiry is titled with the visitor's own words; the customer's name and e-mail as typed
            + \Kaleta\Mcp\Untrusted::mark(array_values(array_filter(['title', $contact !== null ? 'contact' : null, $d['company_id'] !== null ? 'company' : null])));
    }
}
