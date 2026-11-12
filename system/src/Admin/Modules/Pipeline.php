<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\App;
use Kaleta\Core\Assignment;
use Kaleta\Core\Clock;
use Kaleta\Core\Comments;
use Kaleta\Core\Contacts as Customers;
use Kaleta\Core\Db;
use Kaleta\Core\Deals;
use Kaleta\Core\Response;

/**
 * Pipeline (3.31, stage 6 of the staged CRM path, Core\Deals): the deals of the site's customers, moving New → Qualified →
 * Proposal → Won / Lost. The CRM is core – no extension, no switch; the section "pipeline" is given to whoever has Customers
 * (migration 0117). Nothing here ever changes the public site (Kernel::SITE_NEUTRAL_MODULES) and the site never reads
 * deals. Core\Deals writes the change log of every create, save, move and delete itself – this module never adds a row of
 * its own for them.
 *
 *  - list: the board (default) – a column per stage with its count and, for open stages, the sums of the values per
 *    currency; won and lost columns hold the deals closed this month. Every card moves without JavaScript (a stage select
 *    and Move; a lost stage reveals the reason with :has()), and image/admin.js adds drag and drop with the keyboard
 *    (Space picks a card up, the arrows choose the column, Space drops, Esc cancels) and a live announcement.
 *    view=list: the same filters as a table with the status (open, closed, won, lost, all) and paging.
 *    Filters: the responsible person (mine, nobody, a person), the customer, the company, a word.
 *  - detail: a deal – its fields (edit), Move (won, lost with a reason and a note, reopen), the stage history, the enquiry
 *    it came from, the customer and the company, the team's comments and tasks (target deal:N); Delete for administrators
 *  - new and save: a new deal (from the board, a customer or a company) or the changed fields of one
 *  - new?enquiry=: "Create deal" on an enquiry (3.32) – the short form prefilled from it, saved through
 *    Deals::createFromEnquiry (one open deal per enquiry); from_enquiry creates one at once (forms of 3.31, kept)
 *  - stages and stages_save (administrators): rename, reorder, add and remove stages (Deals::saveStages; a stage with deals
 *    keeps its kind and cannot be removed – 3.31.1)
 *  - search_json: the command palette (Ctrl K) finds deals by title, customer or company
 *
 * Responsibility only: the responsible person never limits who sees a deal – "Mine" is a filter. (D-W3, an optional role
 * scope "only mine", is not built for customers or deals.)
 */
final class Pipeline extends Module
{
    use \Kaleta\Admin\CommentActions;
    use \Kaleta\Admin\TaskActions;

    public const string IDENT = Deals::SECTION;
    public const string NAME = 'Pipeline';
    public const string GROUP = 'Workspace';
    public const string ICON = 'obchody';

    /** The most open deals the board shows; with more it says so and points to the list. */
    public const int BOARD_MAX = 500;

    /** The most deals closed this month the board reads for its won and lost columns. */
    public const int CLOSED_MAX = 200;

    /** The customers and companies the deal form offers (the deal's own always among them). */
    public const int CONTACT_CHOICES = 300;
    public const int COMPANY_CHOICES = 500;

    /** The moves the detail shows. */
    public const int HISTORY = 100;

    /** The deals a customer's or a company's box lists. */
    public const int BOX = 20;

    /** Empty rows the stage editor offers for new stages. */
    public const int NEW_STAGE_ROWS = 2;

    /** The statuses of the list view => label (Deals::STATUSES). */
    public const array STATUS_LABELS = ['open' => 'Open deals', 'closed' => 'Closed deals', 'won' => 'Won', 'lost' => 'Lost', 'all' => 'All deals'];

    /** The kinds of a stage => label (Deals::KINDS). */
    public const array KIND_LABELS = ['open' => 'Open stage', 'won' => 'Won', 'lost' => 'Lost'];

    /* ---------- the board and the list ---------- */

    protected function actionList(): Response
    {
        $me = $this->app->auth()->id();
        $filter = self::filter(['owner' => $this->request->get('owner'), 'contact' => $this->request->get('contact'), 'company' => $this->request->get('company'),
            'search' => $this->request->get('search'), 'status' => $this->request->get('status'), 'view' => $this->request->get('view')]);
        $where = ['owner' => $filter['owner'], 'contact' => $filter['contact'], 'company' => $filter['company'], 'search' => $filter['search']];
        $stages = Deals::stages($this->app->settings());
        $modules = $this->kernel->modules();
        $data = ['filter' => $filter, 'query' => self::query($filter), 'stages' => $stages, 'admin' => $this->app->auth()->isAdmin(), 'me' => $me,
            'people' => Assignment::candidates($this->db, 'deal', ctype_digit($filter['owner']) && (int) $filter['owner'] !== $me ? (int) $filter['owner'] : 0),
            'canContacts' => isset($modules[Customers::SECTION]),
            'companies' => isset($modules[Customers::SECTION]) ? Customers::companies($this->db, [], 1, 200)['rows'] : [],
            'contactName' => $filter['contact'] > 0 ? (($c = Customers::find($this->db, $filter['contact'])) !== null ? Customers::displayName($c) : '#' . $filter['contact']) : '',
            'companyName' => $filter['company'] > 0 ? (string) (Customers::findCompany($this->db, $filter['company'])['name'] ?? '#' . $filter['company']) : ''];
        if ($filter['view'] === 'list') {
            $result = Deals::list($this->app, $where + ['status' => $filter['status']], max(1, $this->request->getInt('page', 1)), Deals::PAGE, $me);

            return $this->view('list', 'Pipeline', $data + ['result' => $result, 'labels' => array_column($stages, 'label', 'key'), 'kinds' => array_column($stages, 'kind', 'key'),
                'owners' => Assignment::labels($this->db, array_values(array_filter(array_column($result['rows'], 'owner_id'))))]);
        }
        $open = Deals::list($this->app, $where + ['status' => 'open'], 1, self::BOARD_MAX, $me);
        $closed = Deals::list($this->app, $where + ['status' => 'closed'], 1, self::CLOSED_MAX, $me)['rows'];
        $deals = [...$open['rows'], ...$closed];

        return $this->view('board', 'Pipeline', $data + ['columns' => self::columns($stages, $deals, self::monthStart()), 'more' => max(0, $open['total'] - count($open['rows'])),
            'owners' => Assignment::labels($this->db, array_values(array_filter(array_column($deals, 'owner_id')))), 'today' => Clock::today()]);
    }

    /**
     * The filter of the board and the list from the query: owner '' (anyone) | mine | none | a user id; contact and company
     * ids; a word (at most 100 characters); the status of the list (Deals::STATUSES, open by default); the view (board |
     * list). Anything else falls back to the default. Pure.
     *
     * @param array<string, string> $q
     * @return array{owner: string, contact: int, company: int, search: string, status: string, view: string}
     */
    public static function filter(array $q): array
    {
        $owner = (string) ($q['owner'] ?? '');
        $int = fn (string $key): int => ctype_digit((string) ($q[$key] ?? '')) ? (int) $q[$key] : 0;
        $status = (string) ($q['status'] ?? '');

        return ['owner' => in_array($owner, ['mine', 'none'], true) || (ctype_digit($owner) && (int) $owner > 0) ? $owner : '',
            'contact' => $int('contact'), 'company' => $int('company'), 'search' => mb_substr(trim((string) ($q['search'] ?? '')), 0, 100),
            'status' => in_array($status, Deals::STATUSES, true) ? $status : 'open', 'view' => ($q['view'] ?? '') === 'list' ? 'list' : 'board'];
    }

    /**
     * The query of a filter without its defaults (for links, paging and the way back after a move). Pure.
     *
     * @param array{owner: string, contact: int, company: int, search: string, status: string, view: string} $filter
     * @return array<string, int|string>
     */
    public static function query(array $filter): array
    {
        return array_filter(['view' => $filter['view'] === 'list' ? 'list' : '', 'owner' => $filter['owner'], 'contact' => $filter['contact'] ?: '', 'company' => $filter['company'] ?: '',
            'search' => $filter['search'], 'status' => $filter['view'] === 'list' && $filter['status'] !== 'open' ? $filter['status'] : ''], fn (int|string $v): bool => $v !== '');
    }

    /**
     * The columns of the board: one per stage in its order with its deals (as Deals::list gives them), the count and – for
     * an open stage – the sums of the open values per currency (exact, never converted or added across currencies). A won
     * or lost column holds the deals closed since $monthStart (and any open deal left in it after its kind was changed – old
     * data only: since 3.31.1 Deals::saveStages refuses a kind change while deals are in the stage; the board stays tolerant).
     * Deals of a stage the pipeline no longer has are left out. Pure.
     *
     * @param list<array{key: string, label: string, kind: string, custom: bool}> $stages
     * @param list<array<string, mixed>> $deals
     * @return list<array{key: string, label: string, kind: string, deals: list<array<string, mixed>>, count: int, sums: array<string, string>}>
     */
    public static function columns(array $stages, array $deals, string $monthStart): array
    {
        $by = [];
        foreach ($deals as $deal) {
            $by[(string) $deal['stage']][] = $deal;
        }
        $out = [];
        foreach ($stages as $stage) {
            $rows = array_values(array_filter($by[$stage['key']] ?? [], fn (array $d): bool => $d['closed_at'] === null || ($stage['kind'] !== 'open' && (string) $d['closed_at'] >= $monthStart)));
            $out[] = ['key' => $stage['key'], 'label' => $stage['label'], 'kind' => $stage['kind'], 'deals' => $rows, 'count' => count($rows),
                'sums' => $stage['kind'] === 'open' ? self::sums(array_values(array_filter($rows, fn (array $d): bool => $d['closed_at'] === null))) : []];
        }

        return $out;
    }

    /**
     * The values of deals added per currency: currency => "12500.00", sorted by the currency; deals without a value or a
     * currency are left out. Added in cents as integers – exact. Pure.
     *
     * @param list<array<string, mixed>> $deals
     * @return array<string, string>
     */
    public static function sums(array $deals): array
    {
        $cents = [];
        foreach ($deals as $deal) {
            if (($deal['value'] ?? null) === null || ($deal['currency'] ?? null) === null || $deal['currency'] === '') {
                continue;
            }
            [$whole, $fraction] = explode('.', number_format((float) $deal['value'], 2, '.', '')) + [1 => '00'];
            $cents[(string) $deal['currency']] = ($cents[(string) $deal['currency']] ?? 0) + (int) $whole * 100 + (int) $fraction;
        }
        ksort($cents);

        return array_map(fn (int $c): string => intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT), $cents);
    }

    /** The first moment of this month on the site's clock ("2026-11-01 00:00:00"). */
    private static function monthStart(): string
    {
        return substr(Clock::today(), 0, 8) . '01 00:00:00';
    }

    /** The command palette (Ctrl K): deals by title, customer or company – open ones first. */
    protected function actionSearchJson(): Response
    {
        $q = mb_substr(trim($this->request->get('q')), 0, 80);
        if (mb_strlen($q) < 2) {
            return Response::json(['deals' => []]);
        }
        $labels = array_column(Deals::stages($this->app->settings()), 'label', 'key');
        $rows = Deals::list($this->app, ['search' => $q, 'status' => 'all'], 1, 6)['rows'];

        return Response::json(['deals' => array_map(fn (array $d): array => ['name' => $d['title'],
            'note' => implode(' · ', array_filter([$labels[$d['stage']] ?? $d['stage'], (string) ($d['company_name'] ?? '') !== '' ? (string) $d['company_name'] : self::contactLabel($d)])),
            'url' => $this->url('detail', ['id' => $d['id']])], $rows)]);
    }

    /* ---------- a deal ---------- */

    protected function actionDetail(): Response
    {
        $deal = Deals::get($this->db, $this->request->getInt('id'));
        if ($deal === null) {
            return $this->error('The deal does not exist.', 404);
        }
        $id = $deal['id'];
        $modules = $this->kernel->modules();
        $stages = Deals::stages($this->app->settings());
        $lose = $this->request->get('lose');
        $history = self::history($this->db, $id);
        $target = Comments::deal($id);
        $enquiry = null;
        if ($deal['source_enquiry_id'] !== null && isset($modules['enquiries'])) {
            $enquiry = $this->db->one('SELECT idp, datum, formular, tema, zdroj, email, data, anonymizovano FROM {poptavky} WHERE idp = ? AND '
                . Enquiries::visible($this->db, $this->app->settings(), isset($this->kernel->modules()[\Kaleta\Core\Applications::SECTION])), [$deal['source_enquiry_id']]); // 3.34 second review N6
        }

        return $this->view('detail', $deal['title'], ['d' => $deal, 'stages' => $stages, 'stage' => Deals::stage($this->app->settings(), $deal['stage']),
            'lose' => in_array($lose, array_column(array_filter($stages, fn (array $s): bool => $s['kind'] === 'lost'), 'key'), true) ? $lose : '',
            'history' => $history, 'labels' => array_column($stages, 'label', 'key'),
            'names' => Assignment::labels($this->db, array_values(array_filter([$deal['owner_id'], $deal['created_by'], ...array_column($history, 'user_id')]))),
            'people' => Assignment::candidates($this->db, 'deal', (int) $deal['owner_id']),
            'canContacts' => isset($modules[Customers::SECTION]), 'enquiry' => $enquiry,
            'contacts' => isset($modules[Customers::SECTION]) ? self::contactChoices($this->db, $deal['contact_id']) : [],
            'companies' => isset($modules[Customers::SECTION]) ? self::companyChoices($this->db, $deal['company_id']) : [],
            'draft' => $this->takeDraft('deal_draft', $id), 'currency' => $deal['currency'] ?? Deals::defaultCurrency($this->app->settings()), 'today' => Clock::today(),
            'thread' => $this->commentThread($target, ['id' => $id]),
            'tasks' => $this->taskPanel($target, ['id' => $id]),
            'quoteBox' => Quotes::box($this->app, $modules, 'deal', $id), // 3.38 B
            'admin' => $this->app->auth()->isAdmin(), 'me' => $this->app->auth()->id()]);
    }

    /**
     * A new deal – from the board (nothing filled in), a customer (?contact=), a company (?company=) or (3.32, U25) an
     * enquiry (?enquiry=, "Create deal"): the short form prefilled from the enquiry (Deals::enquiryDefaults – the title from
     * who and what, the customer and company, the responsible person, the first open stage), the value, currency and close
     * left empty for the person to add; saving creates it through Deals::createFromEnquiry. An enquiry needs Enquiries and
     * one open deal per enquiry – with one, back to the enquiry.
     */
    protected function actionNew(): Response
    {
        $modules = $this->kernel->modules();
        $canContacts = isset($modules[Customers::SECTION]);
        $me = $this->app->auth()->id();
        $people = Assignment::candidates($this->db, 'deal');
        $idp = $this->request->getInt('enquiry');
        $fromEnquiry = null;
        if ($idp > 0) {
            if (!isset($modules['enquiries'])) {
                return $this->error('You do not have access to this module.', 403);
            }
            $open = Deals::openForEnquiry($this->db, $idp);
            $fromEnquiry = $open === null ? Deals::enquiryDefaults($this->app, $idp, $me) : null;
            if ($fromEnquiry === null) {
                $this->app->session->flash('chyba', $open !== null ? t('The enquiry already has an open deal (#%d).', $open) : t('The enquiry does not exist.'));

                return Response::redirect($this->app->url('admin.php?module=enquiries&action=detail&id=' . $idp) . '#deal');
            }
        }
        $contact = $canContacts && $this->request->getInt('contact') > 0 ? Customers::find($this->db, $this->request->getInt('contact')) : null;
        $contact = $contact !== null && $contact['anonymised_at'] === null ? $contact : null;
        $companyId = $canContacts && $this->request->getInt('company') > 0 && Customers::findCompany($this->db, $this->request->getInt('company')) !== null ? $this->request->getInt('company') : 0;
        $companyId = $companyId > 0 ? $companyId : (int) ($contact['company_id'] ?? 0);
        $start = $fromEnquiry !== null
            ? ['title' => $fromEnquiry['title'], 'contact_id' => (string) ($fromEnquiry['contact_id'] ?? ''), 'company_id' => (string) ($fromEnquiry['company_id'] ?? ''),
                'owner_id' => (string) ($fromEnquiry['owner_id'] ?? ''), 'currency' => '', 'stage' => $fromEnquiry['stage']]
            : ['contact_id' => (string) ($contact['id'] ?? ''), 'company_id' => $companyId > 0 ? (string) $companyId : '',
                'owner_id' => isset($people[$me]) ? (string) $me : '', 'currency' => Deals::defaultCurrency($this->app->settings())];

        return $this->view('new', t('New deal'), ['draft' => $this->takeDraft('deal_draft', -$idp) + $start,
            'stages' => array_values(array_filter(Deals::stages($this->app->settings()), fn (array $s): bool => $s['kind'] === 'open')),
            'people' => $people, 'canContacts' => $canContacts, 'me' => $me, 'enquiry' => $idp,
            'contacts' => $canContacts ? self::contactChoices($this->db, $fromEnquiry !== null ? $fromEnquiry['contact_id'] : ($contact['id'] ?? null)) : [],
            'companies' => $canContacts ? self::companyChoices($this->db, $fromEnquiry !== null ? $fromEnquiry['company_id'] : ($companyId > 0 ? $companyId : null)) : []]);
    }

    /**
     * Creates a deal (id 0) or changes the fields a form posts (the edit form, the responsible person of the header): only
     * the keys present change; the customer and the company only for whoever may open Customers. Deals::save validates and
     * writes the change log; a refused form is offered again.
     */
    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->request->postInt('id');
        $fields = [];
        foreach (['title', 'value', 'currency', 'expected_close'] as $key) {
            if ($this->request->hasPost($key)) {
                $fields[$key] = $this->request->post($key);
            }
        }
        if ($this->request->hasPost('owner_id')) {
            $fields['owner_id'] = $this->request->postInt('owner_id');
        }
        $fromEnquiry = $id <= 0 && $this->request->postInt('enquiry') > 0;
        if (isset($this->kernel->modules()[Customers::SECTION])) {
            foreach (['contact_id', 'company_id'] as $key) {
                if (!$this->request->hasPost($key)) {
                    continue;
                }
                // 3.32 review: from an enquiry 0 keeps the enquiry's customer (and its company), linked by createFromEnquiry;
                // "none" (the form's "—") is explicitly nobody
                $chosen = $this->request->post($key) === 'none' ? 0 : $this->request->postInt($key);
                if ($chosen > 0 || !$fromEnquiry || $this->request->post($key) === 'none') {
                    $fields[$key] = $chosen;
                }
            }
        }
        if (($fields['value'] ?? null) === '') {
            unset($fields['currency']); // no value: the currency of the select would only be noise
        }
        if ($id <= 0 && $this->request->hasPost('stage')) {
            $fields['stage'] = $this->request->post('stage');
        }
        // 3.32 (U25): the prefilled form of "Create deal" names its enquiry – the deal is made by Deals::createFromEnquiry
        $idp = $id <= 0 ? $this->request->postInt('enquiry') : 0;
        if ($idp > 0 && !isset($this->kernel->modules()['enquiries'])) {
            return $this->error('You do not have access to this module.', 403);
        }
        try {
            if ($id > 0 && Deals::get($this->db, $id) === null) {
                return $this->error('The deal does not exist.', 404);
            }
            $saved = $idp > 0 ? Deals::createFromEnquiry($this->app, $idp, $this->app->auth()->id(), $fields) : Deals::save($this->app, $id > 0 ? $id : null, $fields, $this->app->auth()->id());
        } catch (\DomainException $e) {
            $draft = array_map(fn (mixed $v): string => mb_substr((string) $v, 0, Deals::TITLE_MAX), $fields);
            foreach (['contact_id', 'company_id'] as $key) {
                if ($fromEnquiry && $this->request->post($key) === 'none') {
                    $draft[$key] = 'none'; // the form offers "—" again, not "the enquiry's customer"
                }
            }
            $this->app->session->set('deal_draft', ['id' => $idp > 0 ? -$idp : $id] + $draft);

            return $this->back($e->getMessage(), $id > 0 ? 'detail' : 'new', $id > 0 ? ['id' => $id] : ($idp > 0 ? ['enquiry' => $idp] : []), 'chyba');
        }

        return $this->back($id > 0 ? 'The deal was saved.' : 'The deal was created.', 'detail', ['id' => $saved]);
    }

    /**
     * Moves a deal (Deals::move – the history, won and lost close it, a lost one needs a reason, an open stage reopens it).
     * A form returns where it came from (the board or the list with its filter, or the detail); a move to a lost stage
     * without a reason goes to the detail with that stage chosen and the reason to fill in. format=json (drag and drop
     * in image/admin.js) answers with the deal's new stage instead.
     */
    protected function actionMove(): Response
    {
        $json = $this->request->post('format') === 'json';
        $id = $this->request->postInt('id');
        $deal = $this->request->isPost() ? Deals::get($this->db, $id) : null;
        if ($deal === null) {
            return $json ? Response::json(['ok' => false, 'chyba' => t('The deal does not exist.')], 404) : ($this->request->isPost() ? $this->error('The deal does not exist.', 404) : $this->back());
        }
        $to = Deals::stage($this->app->settings(), $this->request->post('stage'));
        $reason = $this->request->post('lost_reason');
        try {
            if ($to !== null && $to['kind'] === 'lost' && $reason === '' && !$json && $deal['stage'] !== $to['key']) {
                // the card's select without a reason (no :has() in the browser): the detail asks for it
                $this->app->session->flash('info', t('Say why the deal was lost.'));

                return Response::redirect($this->url('detail', ['id' => $id, 'lose' => $to['key']]) . '#move');
            }
            $moved = Deals::move($this->app, $id, $this->request->post('stage'), $this->app->auth()->id(), $reason !== '' ? $reason : null, $this->request->post('lost_note'));
        } catch (\DomainException $e) {
            if ($json) {
                return Response::json(['ok' => false, 'chyba' => $e->getMessage()], 400);
            }
            $this->app->session->flash('chyba', $e->getMessage());

            return Response::redirect($this->returnUrl($id));
        }
        $stage = Deals::stage($this->app->settings(), $moved['stage']);
        $message = $moved['stage'] === $deal['stage'] ? t('The deal is already in this stage.') : match ($stage['kind'] ?? 'open') {
            'won' => t('Won – “%s” is closed.', $moved['title']),
            'lost' => t('Lost – “%s” is closed.', $moved['title']),
            default => t('Moved to %s.', (string) ($stage['label'] ?? $moved['stage'])),
        };
        if ($json) {
            return Response::json(['ok' => true, 'stage' => $moved['stage'], 'kind' => $stage['kind'] ?? 'open', 'closed' => $moved['closed_at'] !== null, 'zprava' => $message]);
        }
        $this->app->session->flash('ok', $message);

        return Response::redirect($this->returnUrl($id));
    }

    /**
     * Where a move form returns: the detail ("return" = detail), or the board or the list with the filter it carried
     * ("return" = the query of the screen – only the keys of the filter are kept, anything else is dropped).
     */
    private function returnUrl(int $id): string
    {
        $back = $this->request->post('return');
        if ($back === 'detail') {
            return $this->url('detail', ['id' => $id]) . '#move';
        }
        parse_str($back, $q);
        $q = array_map(fn (mixed $v): string => is_string($v) ? $v : '', array_intersect_key($q, array_flip(['view', 'owner', 'contact', 'company', 'search', 'status', 'page'])));
        $page = ctype_digit($q['page'] ?? '') && (int) $q['page'] > 1 ? ['page' => (int) $q['page']] : [];

        return $this->url('', self::query(self::filter($q)) + $page) . '#deal-' . $id;
    }

    /** "Create deal" on an enquiry (Deals::createFromEnquiry): whoever may open both Enquiries and the Pipeline. */
    protected function actionFromEnquiry(): Response
    {
        $idp = $this->request->postInt('idp');
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!isset($this->kernel->modules()['enquiries'])) {
            return $this->error('You do not have access to this module.', 403);
        }
        $back = $this->app->url('admin.php?module=enquiries&action=detail&id=' . $idp);
        try {
            $id = Deals::createFromEnquiry($this->app, $idp, $this->app->auth()->id());
        } catch (\DomainException $e) {
            $this->app->session->flash('chyba', $e->getMessage());

            return Response::redirect($back . '#deal');
        }

        return $this->back('The deal was created.', 'detail', ['id' => $id]);
    }

    /** Deletes a deal for good with its history, comments and tasks – administrators only. */
    protected function actionDelete(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can delete a deal.', 403);
        }
        $id = $this->request->postInt('id');
        try {
            $deleted = $this->request->isPost() && Deals::delete($this->app, $id);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'detail', ['id' => $id], 'chyba');
        }
        if ($deleted) {
            Comments::forget($this->db, [Comments::deal($id)]); // the comments, the tasks about it and their notifications

            return $this->back('The deal was deleted.');
        }

        return $this->back();
    }

    /* ---------- the stages (administrators) ---------- */

    protected function actionStages(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the stages of the pipeline.', 403);
        }
        $draft = $this->app->session->get('stages_draft');
        $this->app->session->remove('stages_draft');
        try {
            $counts = array_map('intval', $this->db->pairs('SELECT stage, COUNT(*) FROM {deals} GROUP BY stage'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            $counts = [];
        }

        return $this->view('stages', t('Stages of the pipeline'), ['rows' => is_array($draft) ? array_values(array_filter($draft, 'is_array')) : array_map(fn (array $s): array => ['key' => $s['key'],
            'label' => $s['label'], 'kind' => $s['kind']], Deals::stages($this->app->settings())), 'counts' => $counts]);
    }

    /** Saves the stages from the editor: in the order of their numbers, without the removed and the empty rows (Deals::saveStages). */
    protected function actionStagesSave(): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            return $this->error('Only an administrator can change the stages of the pipeline.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'stages');
        }
        $rows = self::stageRows($this->request->postList('key'), $this->request->postList('label'), $this->request->postList('kind'), $this->request->postList('order'), $this->request->postList('remove'));
        try {
            Deals::saveStages($this->app, $rows);
        } catch (\DomainException $e) {
            $this->app->session->set('stages_draft', $rows);

            return $this->back($e->getMessage(), 'stages', [], 'chyba');
        }

        return $this->back('The stages were saved.', 'stages');
    }

    /**
     * The rows of the stage editor as Deals::saveStages takes them: the parallel lists of the form (row i = key[i], label[i],
     * kind[i], order[i]), sorted by their number (rows without one keep their place after the numbered ones, ties keep the
     * form's order), without the rows whose index is in $remove and the empty new rows. Pure.
     *
     * @param list<string> $keys
     * @param list<string> $labels
     * @param list<string> $kinds
     * @param list<string> $orders
     * @param list<string> $remove
     * @return list<array{key: string, label: string, kind: string}>
     */
    public static function stageRows(array $keys, array $labels, array $kinds, array $orders, array $remove): array
    {
        $rows = [];
        foreach ($labels as $i => $label) {
            $key = trim($keys[$i] ?? '');
            $label = trim($label);
            if (in_array((string) $i, $remove, true) || ($key === '' && $label === '')) {
                continue;
            }
            $order = trim($orders[$i] ?? '');
            $rows[] = ['sort' => preg_match('/^-?\d{1,4}$/D', $order) === 1 ? (int) $order : PHP_INT_MAX, 'i' => $i,
                'row' => ['key' => $key, 'label' => mb_substr($label, 0, Deals::LABEL_MAX), 'kind' => in_array($kinds[$i] ?? '', Deals::KINDS, true) ? $kinds[$i] : 'open']];
        }
        usort($rows, fn (array $a, array $b): int => [$a['sort'], $a['i']] <=> [$b['sort'], $b['i']]);

        return array_column($rows, 'row');
    }

    /* ---------- the boxes on an enquiry, a customer and a company ---------- */

    /**
     * What views/admin/deal_box.php shows on an enquiry: its deal (the latest – open or closed) with the stage and "Open the
     * deal", and "Create deal" unless an open one exists. Null for someone who may not open the Pipeline.
     *
     * @param array<string, class-string<Module>> $modules Kernel::modules()
     * @return array<string, mixed>|null
     */
    public static function enquiryBox(App $app, array $modules, int $idp): ?array
    {
        if (!isset($modules[self::IDENT])) {
            return null;
        }
        $deal = Deals::forEnquiries($app->db(), [$idp])[$idp] ?? null;
        $stage = $deal !== null ? Deals::stage($app->settings(), $deal['stage']) : null;

        return ['idp' => $idp, 'deal' => $deal, 'label' => $stage['label'] ?? (string) ($deal['stage'] ?? ''), 'kind' => $stage['kind'] ?? 'open',
            'url' => $deal !== null ? $app->url(Deals::adminPath($deal['id'])) : '', 'createUrl' => $app->url('admin.php?module=' . self::IDENT . '&action=from_enquiry'),
            'formUrl' => $app->url('admin.php?module=' . self::IDENT . '&action=new&enquiry=' . $idp), // 3.32: the prefilled form
            'canCreate' => $deal === null || $deal['closed']];
    }

    /**
     * What views/admin/pipeline/deals_box.php shows on a customer's or a company's page: their deals (open ones first) with
     * the stage, value and expected close, and "New deal" for them. Null for someone who may not open the Pipeline.
     *
     * @param array<string, class-string<Module>> $modules
     * @param 'contact'|'company' $of
     * @return array<string, mixed>|null
     */
    public static function deals(App $app, array $modules, string $of, int $id): ?array
    {
        if (!isset($modules[self::IDENT])) {
            return null;
        }
        $db = $app->db();
        $stages = Deals::stages($app->settings());

        $deals = $of === 'company' ? Deals::forCompany($db, $id, self::BOX) : Deals::forContact($db, $id, self::BOX);

        return ['deals' => array_map(fn (array $d): array => $d + ['url' => $app->url(Deals::adminPath($d['id']))], $deals), 'labels' => array_column($stages, 'label', 'key'),
            'kinds' => array_column($stages, 'kind', 'key'), 'today' => Clock::today(),
            'newUrl' => $app->url('admin.php?module=' . self::IDENT . '&action=new&' . $of . '=' . $id),
            'allUrl' => $app->url('admin.php?module=' . self::IDENT . '&view=list&status=all&' . $of . '=' . $id)];
    }

    /* ---------- comments and tasks (Admin\CommentActions, Admin\TaskActions) ---------- */

    /** The deal a comment or task form is about ("id"); its change-log rows say "deal" – never the title, which may name a customer. */
    protected function commentTarget(): ?array
    {
        $deal = Deals::get($this->db, $this->request->postInt('id'));

        return $deal === null ? null : ['target' => Comments::deal($deal['id']), 'back' => ['detail', ['id' => $deal['id']]], 'title' => 'deal'];
    }

    protected function taskTarget(): ?array
    {
        $record = $this->commentTarget();

        return $record === null ? null : $record + ['review' => null];
    }

    /* ---------- helpers ---------- */

    /**
     * The moves of a deal, newest first: from (null = created), to, when, who.
     *
     * @return list<array{from: ?string, to: string, at: string, user_id: ?int}>
     */
    private static function history(Db $db, int $id): array
    {
        try {
            $rows = $db->all('SELECT from_stage, to_stage, at, user_id FROM {deal_stage_history} WHERE deal_id = ? ORDER BY at DESC, id DESC LIMIT ' . self::HISTORY, [$id]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return array_map(fn (array $r): array => ['from' => $r['from_stage'] !== null ? (string) $r['from_stage'] : null, 'to' => (string) $r['to_stage'], 'at' => (string) $r['at'],
            'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null], $rows);
    }

    /**
     * The customers the deal form offers: the most recently active ones, and the deal's own.
     *
     * @return array<int, string> id => name (and e-mail)
     */
    public static function contactChoices(Db $db, ?int $keep): array
    {
        $rows = Customers::list($db, ['sort' => 'activity'], 1, self::CONTACT_CHOICES)['rows'];
        if ($keep !== null && !in_array($keep, array_column($rows, 'id'), true) && ($own = Customers::find($db, $keep)) !== null) {
            $rows[] = $own;
        }
        $out = [];
        foreach ($rows as $c) {
            if (($c['anonymised_at'] ?? null) === null) {
                $out[(int) $c['id']] = Customers::displayName($c) . ((string) $c['name'] !== '' && (string) $c['email'] !== '' ? ' (' . $c['email'] . ')' : '');
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /** @return array<int, string> id => name */
    public static function companyChoices(Db $db, ?int $keep): array
    {
        $out = array_column(Customers::companies($db, [], 1, self::COMPANY_CHOICES)['rows'], 'name', 'id');
        if ($keep !== null && !isset($out[$keep]) && ($own = Customers::findCompany($db, $keep)) !== null) {
            $out[$keep] = $own['name'];
            asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return array_map('strval', $out);
    }

    /**
     * The customer of a deal as a name ('' for none).
     *
     * @param array<string, mixed> $deal
     */
    public static function contactLabel(array $deal): string
    {
        return $deal['contact_id'] !== null ? Customers::displayName(['id' => $deal['contact_id'], 'name' => $deal['contact_name'] ?? '', 'email' => $deal['contact_email'] ?? '']) : '';
    }

    /**
     * A refused form's fields, once, for the same deal (0 = a new one).
     *
     * @return array<string, string>
     */
    private function takeDraft(string $key, int $id): array
    {
        $draft = $this->app->session->get($key);
        if (!is_array($draft)) {
            return [];
        }
        $this->app->session->remove($key);
        if ((int) ($draft['id'] ?? -1) !== $id) {
            return [];
        }
        unset($draft['id']);

        return array_map('strval', $draft);
    }
}
