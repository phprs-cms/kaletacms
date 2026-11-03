<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The lean pipeline (3.31, stage 6 of the staged CRM path; tables ka_deals and ka_deal_stage_history, migration 0117):
 * enquiries and customers become deals that move New → Qualified → Proposal → Won / Lost. One pipeline only; its stages
 * are the setting pipeline_stages (stages(): key, label, kind open | won | lost – the default labels are translated in the
 * UI language, a custom label is shown as it was typed). The CRM is core – no extension, no switch; the admin section is
 * SECTION (Admin\Modules\Pipeline, in the Workspace group). The public site never reads deals.
 *
 *  - A deal names its contact, company, responsible person (owner_id – responsibility only, never an access limit), the
 *    enquiry it came from (source_enquiry_id; one OPEN deal per enquiry, createFromEnquiry()), an expected close day and a
 *    value: a number with a currency from CURRENCIES (no products, price maths, probabilities or forecasts).
 *  - move() records every move in ka_deal_stage_history; won and lost close the deal (closed_at), lost needs a reason from
 *    LOST_REASONS and takes an optional note; moving a closed deal to an open stage reopens it (closed_at and the reason
 *    are cleared).
 *  - summary(): counts per stage, the open count, won and lost this month; values only as sums per currency – for the
 *    pipeline screen and MCP pipeline_summary, never for a report (monthCounts() feeds the monthly report: counts only).
 *  - Activity: a created or moved deal is activity of its contact (last_activity_at, so contacts_months counts it) and an
 *    entry of the contact's and company's timeline (timeline()).
 *  - Retention (D-W6): an enquiry that is the source of a deal, or of a customer with a deal, is kept while the deal is
 *    open and for the enquiries' retention after it closed (heldEnquiries(), called by Enquiries::deleteExpired through
 *    Contacts::heldByDeals); a contact with an open deal is never anonymised (Contacts::hasOpenDeal). Erasing or
 *    anonymising a contact keeps its deals without the person (unlinkContacts()).
 *  - Notifications: a new responsible person gets the bell (kind "assigned", target "deal:<id>") and the e-mail of
 *    Core\Assignment – the deal named by its number only. My work lists a person's open deals (forOwner()).
 *  - The change log gets a row for create, save, move and delete (module SECTION). Deals are never journaled for undo
 *    (AgentJournal::PERSONAL_TABLES): they point to people.
 *
 * Messages of \DomainException are translated (t()); MCP calls them inside Language::runWith('en').
 */
final class Deals
{
    /** The admin section (Module::IDENT of Admin\Modules\Pipeline) and the permission row in ka_uzivatele_prava. */
    public const string SECTION = 'pipeline';

    /** The kinds of a stage: open, or one that closes a deal. */
    public const array KINDS = ['open', 'won', 'lost'];

    /** The stages of a new site (pipeline_stages = ''): key => [English label (t() when shown), kind]. */
    public const array DEFAULT_STAGES = ['new' => ['New', 'open'], 'qualified' => ['Qualified', 'open'], 'proposal' => ['Proposal', 'open'],
        'won' => ['Won', 'won'], 'lost' => ['Lost', 'lost']];

    /** Why a deal was lost (ka_deals.lost_reason) => the English label (t() in the admin). */
    public const array LOST_REASONS = ['price' => 'Price too high', 'competitor' => 'Chose a competitor', 'timing' => 'Bad timing', 'no_response' => 'No response',
        'not_a_fit' => 'Not a fit', 'other' => 'Other reason'];

    /** The currencies a value may have (ISO 4217): the EU and its neighbours first, then the common world ones. */
    public const array CURRENCIES = ['EUR', 'CZK', 'PLN', 'HUF', 'RON', 'BGN', 'SEK', 'DKK', 'NOK', 'ISK', 'CHF', 'GBP', 'UAH', 'RSD', 'TRY', 'USD', 'CAD', 'AUD',
        'NZD', 'JPY', 'CNY', 'INR', 'SGD', 'HKD', 'KRW', 'BRL', 'MXN', 'ZAR', 'ILS', 'AED', 'SAR'];

    /** The fewest and most stages; the longest stage key and label. */
    public const int MIN_STAGES = 3;
    public const int MAX_STAGES = 12;
    public const int KEY_MAX = 20;
    public const int LABEL_MAX = 40;

    /** The longest title and lost note. */
    public const int TITLE_MAX = 200;
    public const int NOTE_MAX = 500;

    /** The highest value DECIMAL(12,2) holds. */
    public const float VALUE_MAX = 9999999999.99;

    /** Deals per page of a list. */
    public const int PAGE = 50;

    /** The statuses list() filters by: open (not closed), closed (won or lost), won, lost, all. */
    public const array STATUSES = ['open', 'closed', 'won', 'lost', 'all'];

    /* ---------- stages ---------- */

    /**
     * The stages of the pipeline in their order: key, label (a default one translated in the current language, a custom
     * one as typed), kind (open | won | lost) and custom (whether the label was typed by someone). A damaged setting falls
     * back to the default stages.
     *
     * @return list<array{key: string, label: string, kind: string, custom: bool}>
     */
    public static function stages(Settings $s): array
    {
        return self::parseStages($s->get('pipeline_stages'));
    }

    /**
     * The stages from the stored JSON ('' or damaged = the defaults). Pure apart from the dictionaries.
     *
     * @return list<array{key: string, label: string, kind: string, custom: bool}>
     */
    public static function parseStages(string $json): array
    {
        $stored = $json !== '' ? json_decode($json, true) : null;
        $out = [];
        if (is_array($stored)) {
            try {
                foreach (self::cleanStages($stored) as $stage) {
                    $out[] = $stage + ['custom' => $stage['label'] !== ''];
                }
            } catch (\DomainException) {
                $out = [];
            }
        }
        if ($out === []) {
            foreach (self::DEFAULT_STAGES as $key => [, $kind]) {
                $out[] = ['key' => $key, 'label' => '', 'kind' => $kind, 'custom' => false];
            }
        }

        return array_map(fn (array $st): array => ['key' => $st['key'], 'label' => $st['label'] !== '' ? $st['label'] : t(self::DEFAULT_STAGES[$st['key']][0] ?? $st['key']),
            'kind' => $st['kind'], 'custom' => $st['custom']], $out);
    }

    /**
     * The stage of this key, or null.
     *
     * @return array{key: string, label: string, kind: string, custom: bool}|null
     */
    public static function stage(Settings $s, string $key): ?array
    {
        foreach (self::stages($s) as $stage) {
            if ($stage['key'] === $key) {
                return $stage;
            }
        }

        return null;
    }

    /** The key of the first open stage – where a new deal starts. */
    public static function firstStage(Settings $s): string
    {
        foreach (self::stages($s) as $stage) {
            if ($stage['kind'] === 'open') {
                return $stage['key'];
            }
        }

        return 'new';
    }

    /**
     * Saves the stages of the pipeline (the admin's stage editor): a list of {key, label, kind} in their order. A key is
     * kept as it is (lower case a–z, 0–9, _); a new stage without a key gets one from its label. At least MIN_STAGES and at
     * most MAX_STAGES, at least one open stage, exactly one won and at least one lost. A default stage whose label is left
     * empty (or is its translated default) keeps the translated label; any other label is stored as typed. A stage that
     * still has deals cannot be removed. Writes the change log.
     *
     * @param list<array<string, mixed>> $stages
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function saveStages(App $app, array $stages): void
    {
        $clean = self::cleanStages($stages, true);
        $removed = array_diff(array_column(self::stages($app->settings()), 'key'), array_column($clean, 'key'));
        if ($removed !== []) {
            try {
                $used = $app->db()->value('SELECT stage FROM {deals} WHERE stage IN (' . implode(',', array_fill(0, count($removed), '?')) . ') LIMIT 1', array_values($removed));
            } catch (\PDOException) {
                $used = null;
            }
            if ($used !== null) {
                throw new \DomainException(t('The stage “%s” still has deals – move them to another stage first.', (string) $used));
            }
        }
        $isDefault = array_keys(self::DEFAULT_STAGES) === array_column($clean, 'key') && array_column(self::DEFAULT_STAGES, 1) === array_column($clean, 'kind')
            && array_filter(array_column($clean, 'label')) === [];
        $app->settings()->set('pipeline_stages', $isDefault ? '' : (string) json_encode($clean, JSON_UNESCAPED_UNICODE));
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'stages', implode(', ', array_column($clean, 'key')));
    }

    /**
     * The checked stages: key, label ('' = the translated default of a default key), kind. $fromForm: a missing key is made
     * from the label and a label equal to the translated default is stored empty.
     *
     * @param array<mixed> $stages
     * @return list<array{key: string, label: string, kind: string}>
     * @throws \DomainException
     */
    private static function cleanStages(array $stages, bool $fromForm = false): array
    {
        $out = [];
        $keys = [];
        foreach (array_values($stages) as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $label = self::line($stage['label'] ?? '', self::LABEL_MAX);
            $key = is_scalar($stage['key'] ?? null) ? strtolower(trim((string) $stage['key'])) : '';
            if ($key === '' && $fromForm) {
                $key = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', slugify($label)), '_'), 0, self::KEY_MAX);
            }
            if ($key === '' && $label === '') {
                continue; // an empty row of the form
            }
            if (preg_match('/^[a-z0-9_]{1,' . self::KEY_MAX . '}$/D', $key) !== 1) {
                throw new \DomainException(t('A stage needs a name.'));
            }
            if (isset($keys[$key])) {
                $key = substr($key, 0, self::KEY_MAX - 3) . '_' . count($out);
            }
            $kind = is_string($stage['kind'] ?? null) && in_array($stage['kind'], self::KINDS, true) ? $stage['kind'] : 'open';
            if (isset(self::DEFAULT_STAGES[$key]) && ($label === t(self::DEFAULT_STAGES[$key][0]) || $label === self::DEFAULT_STAGES[$key][0])) {
                $label = '';
            }
            if ($label === '' && !isset(self::DEFAULT_STAGES[$key])) {
                throw new \DomainException(t('A stage needs a name.'));
            }
            $keys[$key] = true;
            $out[] = ['key' => $key, 'label' => $label, 'kind' => $kind];
        }
        $kinds = array_count_values(array_column($out, 'kind'));
        if (count($out) < self::MIN_STAGES || count($out) > self::MAX_STAGES) {
            throw new \DomainException(t('The pipeline needs %d to %d stages.', self::MIN_STAGES, self::MAX_STAGES));
        }
        if (($kinds['open'] ?? 0) < 1 || ($kinds['won'] ?? 0) !== 1 || ($kinds['lost'] ?? 0) < 1) {
            throw new \DomainException(t('The pipeline needs at least one open stage, exactly one won stage and at least one lost stage.'));
        }

        return $out;
    }

    /* ---------- money ---------- */

    /** The currency a new deal takes when none is given: CZK on a Czech site, otherwise EUR. */
    public static function defaultCurrency(Settings $s): string
    {
        return Language::defaults($s) === 'cs' ? 'CZK' : 'EUR';
    }

    /** "12 500,00 CZK" – a value with its currency, in the current language's number format. Pure apart from the dictionaries. */
    public static function formatValue(float|string|null $value, ?string $currency): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return trim(format_number((float) $value, 2) . ' ' . (string) $currency);
    }

    /* ---------- deals ---------- */

    /**
     * One deal with the names of its contact and company, or null.
     *
     * @return array{id: int, title: string, contact_id: ?int, contact_name: ?string, contact_email: ?string, company_id: ?int, company_name: ?string, owner_id: ?int, stage: string, value: ?string, currency: ?string, source_enquiry_id: ?int, expected_close: ?string, closed_at: ?string, lost_reason: ?string, lost_note: string, created_by: ?int, created_at: string, updated_at: string}|null
     */
    public static function get(Db $db, int $id): ?array
    {
        try {
            $row = $id > 0 ? $db->one('SELECT d.*, c.name AS contact_name, c.email AS contact_email, co.name AS company_name FROM {deals} d LEFT JOIN {contacts} c ON c.id = d.contact_id'
                . ' LEFT JOIN {companies} co ON co.id = d.company_id WHERE d.id = ?', [$id]) : null;
        } catch (\PDOException) {
            return null; // before migration 0117
        }

        return $row === null ? null : self::row($row);
    }

    /**
     * A page of deals. Filter keys: stage (a stage key), status (STATUSES; default open), owner (a user id; 'mine' with
     * $userId; 'none' or -1 = nobody; 0 / '' = anyone), contact (a contact id), company (a company id), search (a word in the
     * title, the contact's name or e-mail or the company's name). Sorted: open ones by expected close (none last), then the
     * newest; closed ones by when they closed.
     *
     * @param array<string, mixed> $filter
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function list(App $app, array $filter = [], int $page = 1, int $perPage = self::PAGE, int $userId = 0): array
    {
        $db = $app->db();
        $perPage = max(1, min(500, $perPage));
        $where = [];
        $params = [];
        $status = is_string($filter['status'] ?? null) && in_array($filter['status'], self::STATUSES, true) ? $filter['status'] : 'open';
        if ($status === 'open') {
            $where[] = 'd.closed_at IS NULL';
        } elseif ($status === 'closed') {
            $where[] = 'd.closed_at IS NOT NULL';
        } elseif ($status === 'won' || $status === 'lost') {
            $keys = array_column(array_filter(self::stages($app->settings()), fn (array $st): bool => $st['kind'] === $status), 'key');
            $where[] = 'd.closed_at IS NOT NULL AND d.stage IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
            array_push($params, ...$keys);
        }
        if (is_string($filter['stage'] ?? null) && $filter['stage'] !== '') {
            $where[] = 'd.stage = ?';
            $params[] = $filter['stage'];
        }
        $owner = self::ownerFilter($filter['owner'] ?? 0, $userId);
        if ($owner > 0) {
            $where[] = 'd.owner_id = ?';
            $params[] = $owner;
        } elseif ($owner < 0) {
            $where[] = 'd.owner_id IS NULL';
        }
        foreach (['contact' => 'd.contact_id', 'company' => 'd.company_id'] as $key => $column) {
            if ((int) ($filter[$key] ?? 0) > 0) {
                $where[] = $column . ' = ?';
                $params[] = (int) $filter[$key];
            }
        }
        $search = trim(is_scalar($filter['search'] ?? null) ? (string) $filter['search'] : '');
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(d.title LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR co.name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $sql = ' FROM {deals} d LEFT JOIN {contacts} c ON c.id = d.contact_id LEFT JOIN {companies} co ON co.id = d.company_id' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = $status === 'open' ? 'd.expected_close IS NULL, d.expected_close, d.id DESC' : ($status === 'all' ? 'd.closed_at IS NOT NULL, d.closed_at DESC, d.id DESC' : 'd.closed_at DESC, d.id DESC');
        try {
            $total = (int) $db->value('SELECT COUNT(*)' . $sql, $params);
            $pages = max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $pages));
            $rows = $db->all('SELECT d.*, c.name AS contact_name, c.email AS contact_email, co.name AS company_name' . $sql . ' ORDER BY ' . $order
                . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        } catch (\PDOException) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]; // before migration 0117
        }

        return ['rows' => array_map(self::row(...), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Creates a deal ($id null) or changes the given fields of one, and returns its id. Fields: title (required for a new
     * one), contact_id, company_id (a new deal with a contact and no company takes the contact's), owner_id, value (>= 0;
     * '' or null = none), currency (CURRENCIES; a value without one takes defaultCurrency()), expected_close (YYYY-MM-DD;
     * '' = none), source_enquiry_id (a new deal only), stage (a new deal only – an open stage; the first one by default; a
     * stage of an existing deal changes only through move()). The responsible person must be able to open the pipeline and
     * is notified. Writes the history (created), the change log, and the contact's activity.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function save(App $app, ?int $id, array $fields, int $byUserId): int
    {
        $db = $app->db();
        $s = $app->settings();
        $deal = $id !== null ? (self::get($db, $id) ?? throw new \DomainException(t('The deal does not exist.'))) : null;
        $data = [];
        if ($deal === null || array_key_exists('title', $fields)) {
            $data['title'] = self::line($fields['title'] ?? '', self::TITLE_MAX);
            if ($data['title'] === '') {
                throw new \DomainException(t('Give the deal a title.'));
            }
        }
        if ($deal === null || array_key_exists('contact_id', $fields)) {
            $contactId = (int) (is_scalar($fields['contact_id'] ?? null) ? $fields['contact_id'] : 0);
            $contact = $contactId > 0 ? Contacts::find($db, $contactId) : null;
            if ($contactId > 0 && ($contact === null || $contact['anonymised_at'] !== null)) {
                throw new \DomainException(t('The contact does not exist.'));
            }
            $data['contact_id'] = $contact['id'] ?? null;
            if ($deal === null && !array_key_exists('company_id', $fields) && $contact !== null) {
                $data['company_id'] = $contact['company_id'];
            }
        }
        if (array_key_exists('company_id', $fields) || ($deal === null && !array_key_exists('company_id', $data))) {
            $companyId = (int) (is_scalar($fields['company_id'] ?? null) ? $fields['company_id'] : 0);
            if ($companyId > 0 && Contacts::findCompany($db, $companyId) === null) {
                throw new \DomainException(t('The company does not exist.'));
            }
            $data['company_id'] = $companyId > 0 ? $companyId : null;
        }
        if ($deal === null || array_key_exists('owner_id', $fields)) {
            $data['owner_id'] = self::owner($db, $s, $fields['owner_id'] ?? null, $deal['owner_id'] ?? null);
        }
        if ($deal === null || array_key_exists('value', $fields) || array_key_exists('currency', $fields)) {
            $value = array_key_exists('value', $fields) ? self::value($fields['value']) : ($deal['value'] ?? null);
            $currency = array_key_exists('currency', $fields) ? self::currency($fields['currency']) : ($deal['currency'] ?? null);
            $data['value'] = $value;
            $data['currency'] = $value === null ? ($currency !== '' ? $currency : null) : ($currency !== null && $currency !== '' ? $currency : self::defaultCurrency($s));
        }
        if ($deal === null || array_key_exists('expected_close', $fields)) {
            $data['expected_close'] = self::day($fields['expected_close'] ?? null);
        }
        if ($deal !== null && array_key_exists('stage', $fields) && is_scalar($fields['stage']) && (string) $fields['stage'] !== '' && (string) $fields['stage'] !== $deal['stage']) {
            throw new \DomainException(t('Move a deal to another stage with Move – it keeps the history.'));
        }
        $now = Clock::now();
        if ($deal === null) {
            $stage = is_scalar($fields['stage'] ?? null) && (string) $fields['stage'] !== '' ? (string) $fields['stage'] : self::firstStage($s);
            if ((self::stage($s, $stage)['kind'] ?? null) !== 'open') {
                throw new \DomainException(t('A new deal starts in an open stage.'));
            }
            $source = (int) (is_scalar($fields['source_enquiry_id'] ?? null) ? $fields['source_enquiry_id'] : 0);
            if ($source > 0) {
                if ($db->value('SELECT 1 FROM {poptavky} WHERE idp = ?', [$source]) === null) {
                    throw new \DomainException(t('The enquiry does not exist.'));
                }
                $open = self::openForEnquiry($db, $source);
                if ($open !== null) {
                    throw new \DomainException(t('The enquiry already has an open deal (#%d).', $open));
                }
            }
            $id = $db->transaction(function (Db $db) use ($data, $stage, $source, $byUserId, $now): int {
                $id = $db->insert('deals', $data + ['stage' => $stage, 'source_enquiry_id' => $source > 0 ? $source : null, 'closed_at' => null, 'lost_reason' => null, 'lost_note' => null,
                    'created_by' => $byUserId > 0 ? $byUserId : null, 'created_at' => $now, 'updated_at' => $now]);
                $db->insert('deal_stage_history', ['deal_id' => $id, 'from_stage' => null, 'to_stage' => $stage, 'at' => $now, 'user_id' => $byUserId > 0 ? $byUserId : null]);

                return $id;
            });
            \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'create', '#' . $id);
            Contacts::touch($db, (int) ($data['contact_id'] ?? 0), $now);
        } else {
            $data = array_filter($data, fn (mixed $v, string $k): bool => $v !== ($deal[$k] ?? null), ARRAY_FILTER_USE_BOTH);
            if ($data === []) {
                return $deal['id'];
            }
            $db->update('deals', $data + ['updated_at' => $now], ['id' => $deal['id']]);
            \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'save', '#' . $deal['id']);
            if (array_key_exists('contact_id', $data)) {
                Contacts::touch($db, (int) $data['contact_id'], $now);
            }
            $id = $deal['id'];
        }
        if (($data['owner_id'] ?? null) !== null && $data['owner_id'] !== ($deal['owner_id'] ?? null)) {
            self::notifyOwner($app, $id, (int) $data['owner_id'], $byUserId);
        }

        return $id;
    }

    /**
     * Moves a deal to another stage and returns it: the move is recorded in the history; a won or lost stage closes the
     * deal (closed_at = now), a lost one needs a reason (LOST_REASONS) and takes an optional note; moving a closed deal to an
     * open stage reopens it (closed_at, the reason and the note are cleared). The same stage changes nothing. The contact's
     * last activity moves; the change log names the stages.
     *
     * @return array<string, mixed> the deal as get() returns it
     * @throws \DomainException with a translated reason; nothing changes
     */
    public static function move(App $app, int $id, string $toStage, int $byUserId, ?string $lostReason = null, string $lostNote = ''): array
    {
        $db = $app->db();
        $deal = self::get($db, $id) ?? throw new \DomainException(t('The deal does not exist.'));
        $to = self::stage($app->settings(), $toStage) ?? throw new \DomainException(t('The pipeline has no stage “%s”.', mb_substr($toStage, 0, 40)));
        if ($to['key'] === $deal['stage']) {
            return $deal;
        }
        $reason = null;
        $note = null;
        if ($to['kind'] === 'lost') {
            $reason = is_string($lostReason) ? strtolower(trim($lostReason)) : '';
            if (!isset(self::LOST_REASONS[$reason])) {
                throw new \DomainException(t('Say why the deal was lost: %s.', implode(', ', array_keys(self::LOST_REASONS))));
            }
            $note = self::line($lostNote, self::NOTE_MAX);
            $note = $note !== '' ? $note : null;
        }
        $now = Clock::now();
        $closedAt = $to['kind'] === 'open' ? null : ($deal['closed_at'] !== null && (self::stage($app->settings(), $deal['stage'])['kind'] ?? 'open') === $to['kind'] ? $deal['closed_at'] : $now);
        $db->transaction(function (Db $db) use ($deal, $to, $closedAt, $reason, $note, $now, $byUserId): void {
            $db->update('deals', ['stage' => $to['key'], 'closed_at' => $closedAt, 'lost_reason' => $reason, 'lost_note' => $note, 'updated_at' => $now], ['id' => $deal['id']]);
            $db->insert('deal_stage_history', ['deal_id' => $deal['id'], 'from_stage' => $deal['stage'], 'to_stage' => $to['key'], 'at' => $now, 'user_id' => $byUserId > 0 ? $byUserId : null]);
        });
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'move', '#' . $deal['id'] . ' ' . $deal['stage'] . ' → ' . $to['key']);
        Contacts::touch($db, (int) $deal['contact_id'], $now);

        return self::get($db, $id) ?? $deal;
    }

    /**
     * A deal from an enquiry ("Create deal" on an enquiry, MCP save_deal with enquiry): the title from what the enquiry was
     * about (its topic, otherwise the form's name), its contact (linked now when the after-response link has not run yet)
     * and the contact's company, the person who handles the enquiry as responsible (when they may open the pipeline,
     * otherwise nobody), source_enquiry_id. One open deal per enquiry: a second one is refused. $fields may override title,
     * owner_id, value, currency and expected_close. Returns the new deal's id.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function createFromEnquiry(App $app, int $idp, int $byUserId, array $fields = []): int
    {
        $db = $app->db();
        $enquiry = $db->one('SELECT idp, formular, tema, prirazeno, contact_id, anonymizovano FROM {poptavky} WHERE idp = ?', [$idp]) ?? throw new \DomainException(t('The enquiry does not exist.'));
        $open = self::openForEnquiry($db, $idp);
        if ($open !== null) {
            throw new \DomainException(t('The enquiry already has an open deal (#%d).', $open));
        }
        $contactId = $enquiry['contact_id'] !== null ? (int) $enquiry['contact_id'] : null;
        if ($contactId === null && $enquiry['anonymizovano'] === null) {
            try {
                $contactId = Contacts::link($app, 'enquiry', $idp);
            } catch (\Throwable) {
                $contactId = null; // the linking job tries again; the deal stands without a contact
            }
        }
        $topic = self::line($enquiry['tema'] ?? '', self::TITLE_MAX);
        $assignee = (int) ($enquiry['prirazeno'] ?? 0);

        return self::save($app, null, array_intersect_key($fields, array_flip(['title', 'owner_id', 'value', 'currency', 'expected_close'])) + [
            'title' => $topic !== '' ? $topic : (string) $enquiry['formular'], 'contact_id' => $contactId ?? 0,
            'owner_id' => $assignee > 0 && self::mayOpen($db, $app->settings(), $assignee) ? $assignee : null, 'source_enquiry_id' => $idp,
        ], $byUserId);
    }

    /** The open deal that came from this enquiry, or null. */
    public static function openForEnquiry(Db $db, int $idp): ?int
    {
        try {
            $id = $db->value('SELECT id FROM {deals} WHERE source_enquiry_id = ? AND closed_at IS NULL ORDER BY id LIMIT 1', [$idp]);
        } catch (\PDOException) {
            return null;
        }

        return $id !== null ? (int) $id : null;
    }

    /**
     * The deals that came from these enquiries (the enquiry list and detail): idp => [id, stage, closed] of the latest.
     *
     * @param list<int> $idps
     * @return array<int, array{id: int, stage: string, closed: bool}>
     */
    public static function forEnquiries(Db $db, array $idps): array
    {
        $idps = array_values(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0));
        if ($idps === []) {
            return [];
        }
        $out = [];
        try {
            foreach ($db->all('SELECT id, stage, closed_at, source_enquiry_id FROM {deals} WHERE source_enquiry_id IN (' . implode(',', array_unique($idps)) . ') ORDER BY id') as $r) {
                $out[(int) $r['source_enquiry_id']] = ['id' => (int) $r['id'], 'stage' => (string) $r['stage'], 'closed' => $r['closed_at'] !== null];
            }
        } catch (\PDOException) {
            return [];
        }

        return $out;
    }

    /**
     * Deletes a deal for good with its history (administrators only – the admin's Delete, MCP has none). There is no trash
     * for deals, as for contacts. Returns whether it existed.
     *
     * @throws \DomainException when the signed-in person is not an administrator
     */
    public static function delete(App $app, int $id): bool
    {
        if (!$app->auth()->isAdmin()) {
            throw new \DomainException(t('Only an administrator can delete a deal.'));
        }
        $db = $app->db();
        if (self::get($db, $id) === null) {
            return false;
        }
        $db->transaction(function (Db $db) use ($id): void {
            $db->delete('deal_stage_history', ['deal_id' => $id]);
            $db->delete('deals', ['id' => $id]);
        });
        Inbox::forgetTargets($db, ['deal:' . $id]);
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'delete', '#' . $id);

        return true;
    }

    /**
     * The pipeline at a glance (the pipeline screen, MCP pipeline_summary): per stage in order the count and the sums of
     * the values per currency (open stages: the open deals; won and lost stages: closed this month), the open count and its
     * sums, and how many deals were won and lost this month. $owner: 0 = everyone, a user id, -1 = nobody responsible.
     * Values only as sums per currency – never converted or added across currencies, and never in a report.
     *
     * @return array{stages: list<array{key: string, label: string, kind: string, count: int, sums: array<string, string>}>, open: int, open_sums: array<string, string>, won_month: int, lost_month: int, month: string}
     */
    public static function summary(App $app, int $owner = 0): array
    {
        $db = $app->db();
        $stages = self::stages($app->settings());
        $kinds = array_column($stages, 'kind', 'key');
        $monthStart = substr(Clock::today(), 0, 8) . '01 00:00:00';
        $where = $owner > 0 ? ' AND owner_id = ' . $owner : ($owner < 0 ? ' AND owner_id IS NULL' : '');
        $counts = [];
        $sums = [];
        try {
            $rows = $db->all('SELECT stage, closed_at IS NULL AS open, currency, COUNT(*) AS n, SUM(value) AS total FROM {deals} WHERE (closed_at IS NULL OR closed_at >= ?)' . $where
                . ' GROUP BY stage, closed_at IS NULL, currency', [$monthStart]);
        } catch (\PDOException) {
            $rows = []; // before migration 0117
        }
        $openCount = 0;
        $openSums = [];
        $won = 0;
        $lost = 0;
        foreach ($rows as $r) {
            $stage = (string) $r['stage'];
            $isOpen = (int) $r['open'] === 1;
            $kind = $kinds[$stage] ?? ($isOpen ? 'open' : 'lost');
            if ($isOpen !== ($kind === 'open')) {
                continue; // a deal left in a stage whose kind was changed since – counted where it belongs only
            }
            $n = (int) $r['n'];
            $counts[$stage] = ($counts[$stage] ?? 0) + $n;
            if ($r['total'] !== null && $r['currency'] !== null) {
                $sums[$stage][(string) $r['currency']] = self::add($sums[$stage][(string) $r['currency']] ?? '0', (string) $r['total']);
                if ($isOpen) {
                    $openSums[(string) $r['currency']] = self::add($openSums[(string) $r['currency']] ?? '0', (string) $r['total']);
                }
            }
            if ($isOpen) {
                $openCount += $n;
            } elseif ($kind === 'won') {
                $won += $n;
            } else {
                $lost += $n;
            }
        }
        ksort($openSums);

        return ['stages' => array_map(function (array $st) use ($counts, $sums): array {
            $own = $sums[$st['key']] ?? [];
            ksort($own);

            return ['key' => $st['key'], 'label' => $st['label'], 'kind' => $st['kind'], 'count' => $counts[$st['key']] ?? 0, 'sums' => $own];
        }, $stages), 'open' => $openCount, 'open_sums' => $openSums, 'won_month' => $won, 'lost_month' => $lost, 'month' => substr($monthStart, 0, 7)];
    }

    /**
     * The deals of a contact or a company (the contact's and company's page), open ones first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forContact(Db $db, int $contactId, int $limit = 50): array
    {
        return self::of($db, 'contact_id', $contactId, $limit);
    }

    /** @return list<array<string, mixed>> */
    public static function forCompany(Db $db, int $companyId, int $limit = 50): array
    {
        return self::of($db, 'company_id', $companyId, $limit);
    }

    /**
     * The open deals a person is responsible for (My work, MCP my_work): by expected close (overdue first, none last).
     * Each row: kind deal, id, title, stage (key), stage_label, expected_close, overdue, contact (display name or null),
     * url (relative admin address).
     *
     * @return list<array<string, mixed>>
     */
    public static function forOwner(App $app, int $userId, int $limit = 50): array
    {
        $db = $app->db();
        try {
            $rows = $db->all('SELECT d.id, d.title, d.stage, d.expected_close, d.contact_id, c.name AS contact_name, c.email AS contact_email FROM {deals} d LEFT JOIN {contacts} c ON c.id = d.contact_id'
                . ' WHERE d.owner_id = ? AND d.closed_at IS NULL ORDER BY d.expected_close IS NULL, d.expected_close, d.id DESC LIMIT ' . max(1, min(500, $limit)), [$userId]);
        } catch (\PDOException) {
            return [];
        }
        $labels = array_column(self::stages($app->settings()), 'label', 'key');
        $today = Clock::today();

        return array_map(fn (array $r): array => ['kind' => 'deal', 'id' => (int) $r['id'], 'title' => (string) $r['title'], 'stage' => (string) $r['stage'],
            'stage_label' => $labels[(string) $r['stage']] ?? (string) $r['stage'], 'expected_close' => $r['expected_close'] !== null ? (string) $r['expected_close'] : null,
            'overdue' => $r['expected_close'] !== null && (string) $r['expected_close'] < $today,
            'contact' => $r['contact_id'] !== null ? Contacts::displayName(['id' => $r['contact_id'], 'name' => $r['contact_name'], 'email' => $r['contact_email']]) : null,
            'url' => self::adminPath((int) $r['id'])], $rows);
    }

    /**
     * The deals' entries of a contact's or company's timeline (Contacts::timeline, the company page), newest first: one per
     * move – kind deal, title (the deal's title, data), status created | moved | won | lost | reopened, text (the label of
     * the stage it moved to), by (who moved it), ref deal:<id>, path.
     *
     * @param 'contact'|'company' $of
     * @return list<array<string, mixed>>
     */
    public static function timeline(Db $db, string $of, int $id, int $limit = 100): array
    {
        $column = $of === 'company' ? 'company_id' : 'contact_id';
        try {
            $rows = $db->all('SELECT h.deal_id, h.from_stage, h.to_stage, h.at, h.user_id, d.title FROM {deal_stage_history} h JOIN {deals} d ON d.id = h.deal_id WHERE d.' . $column . ' = ?'
                . ' ORDER BY h.at DESC, h.id DESC LIMIT ' . max(1, min(500, $limit)), [$id]);
            $stored = (string) ($db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'pipeline_stages'") ?? '');
        } catch (\PDOException) {
            return []; // before migration 0117
        }
        $stages = self::parseStages($stored);
        $labels = array_column($stages, 'label', 'key');
        $kinds = array_column($stages, 'kind', 'key');
        $users = Assignment::labels($db, array_values(array_filter(array_map(fn (array $r): int => (int) $r['user_id'], $rows))));

        return array_map(fn (array $r): array => ['at' => (string) $r['at'], 'kind' => 'deal', 'title' => (string) $r['title'],
            'status' => self::event($r['from_stage'] !== null ? (string) $r['from_stage'] : null, (string) $r['to_stage'], $kinds), 'text' => $labels[(string) $r['to_stage']] ?? (string) $r['to_stage'],
            'by' => $r['user_id'] !== null ? ($users[(int) $r['user_id']] ?? '') : '', 'ref' => 'deal:' . (int) $r['deal_id'], 'path' => self::adminPath((int) $r['deal_id'])], $rows);
    }

    /**
     * What a move was: created (no previous stage), won, lost, reopened (from a closing stage to an open one) or moved. Pure.
     *
     * @param array<string, string> $kinds stage key => kind
     */
    public static function event(?string $from, string $to, array $kinds): string
    {
        if ($from === null) {
            return 'created';
        }
        $toKind = $kinds[$to] ?? 'open';
        if ($toKind !== 'open') {
            return $toKind;
        }

        return ($kinds[$from] ?? 'open') !== 'open' ? 'reopened' : 'moved';
    }

    /* ---------- the monthly report ---------- */

    /**
     * Counts of a month for the monthly report (MonthlyReport::build): new deals, won and lost ones, and how many deals
     * entered each stage – never values (D-W7, the reports are never money). Null when the site has no deals at all, so a
     * site that does not use the pipeline gets no empty section.
     *
     * @return array{new: int, won: int, lost: int, stages: array<string, int>}|null
     */
    public static function monthCounts(App $app, string $from, string $to): ?array
    {
        $db = $app->db();
        try {
            if ($db->value('SELECT 1 FROM {deals} LIMIT 1') === null) {
                return null;
            }
            $entered = array_map('intval', $db->pairs('SELECT to_stage, COUNT(*) FROM {deal_stage_history} WHERE at >= ? AND at < ? GROUP BY to_stage', [$from, $to]));
            $new = (int) $db->value('SELECT COUNT(*) FROM {deal_stage_history} WHERE from_stage IS NULL AND at >= ? AND at < ?', [$from, $to]);
            $closed = $db->all('SELECT stage, COUNT(*) AS n FROM {deals} WHERE closed_at >= ? AND closed_at < ? GROUP BY stage', [$from, $to]);
        } catch (\PDOException) {
            return null;
        }
        $kinds = array_column(self::stages($app->settings()), 'kind', 'key');
        $won = 0;
        $lost = 0;
        foreach ($closed as $r) {
            if (($kinds[(string) $r['stage']] ?? '') === 'won') {
                $won += (int) $r['n'];
            } else {
                $lost += (int) $r['n'];
            }
        }
        $stages = [];
        foreach (array_keys($kinds) as $key) {
            $stages[$key] = $entered[$key] ?? 0;
        }

        return ['new' => $new, 'won' => $won, 'lost' => $lost, 'stages' => $stages];
    }

    /* ---------- retention and personal data ---------- */

    /** Does the contact have an open deal (Contacts::hasOpenDeal – never anonymised by the retention)? */
    public static function hasOpenDeal(Db $db, int $contactId): bool
    {
        if ($contactId <= 0) {
            return false;
        }
        try {
            return $db->value('SELECT 1 FROM {deals} WHERE contact_id = ? AND closed_at IS NULL LIMIT 1', [$contactId]) !== null;
        } catch (\PDOException) {
            return false; // before migration 0117
        }
    }

    /**
     * The enquiries of the given ones that a deal keeps although their retention has passed (D-W6): the source enquiry of a
     * deal, and every enquiry of a customer with a deal, while the deal is open or closed less than $months ago (the
     * enquiries' retention; 0 = only open deals hold).
     *
     * @param list<int> $idps
     * @return list<int>
     */
    public static function heldEnquiries(Db $db, array $idps, int $months): array
    {
        $idps = array_values(array_unique(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0)));
        if ($idps === []) {
            return [];
        }
        $live = '(d.closed_at IS NULL' . ($months > 0 ? ' OR d.closed_at >= ?' : '') . ')';
        $params = $months > 0 ? [Clock::monthsAgo($months)] : [];
        $held = [];
        try {
            foreach (array_chunk($idps, 500) as $chunk) {
                $in = implode(',', $chunk);
                $held = [...$held, ...array_map('intval', array_column($db->all('SELECT DISTINCT d.source_enquiry_id AS idp FROM {deals} d WHERE d.source_enquiry_id IN (' . $in . ') AND ' . $live, $params), 'idp'))];
                $held = [...$held, ...array_map('intval', array_column($db->all('SELECT DISTINCT p.idp FROM {poptavky} p JOIN {deals} d ON d.contact_id = p.contact_id WHERE p.idp IN (' . $in . ') AND ' . $live, $params), 'idp'))];
            }
        } catch (\PDOException) {
            return []; // before migration 0117
        }
        $held = array_values(array_unique($held));
        sort($held);

        return $held;
    }

    /**
     * A contact is erased or anonymised (Contacts::delete, Contacts::anonymise): its deals stay for the pipeline, without
     * the person – no contact, no lost note (free text about them), and a title that names the person (their name or
     * address) becomes "#<id>". Returns how many deals changed.
     *
     * @param list<int> $contactIds
     */
    public static function unlinkContacts(Db $db, array $contactIds): int
    {
        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), fn (int $id): bool => $id > 0)));
        if ($contactIds === []) {
            return 0;
        }
        $changed = 0;
        try {
            foreach (array_chunk($contactIds, 500) as $chunk) {
                $rows = $db->all('SELECT d.id, d.title, c.name, c.email FROM {deals} d LEFT JOIN {contacts} c ON c.id = d.contact_id WHERE d.contact_id IN (' . implode(',', $chunk) . ')');
                foreach ($rows as $r) {
                    $title = (string) $r['title'];
                    if (self::names($title, (string) ($r['name'] ?? ''), (string) ($r['email'] ?? ''))) {
                        $title = '#' . (int) $r['id'];
                    }
                    $changed += $db->update('deals', ['contact_id' => null, 'title' => $title, 'lost_note' => null, 'updated_at' => Clock::now()], ['id' => (int) $r['id']]);
                }
            }
        } catch (\PDOException) {
            return $changed; // before migration 0117
        }

        return $changed;
    }

    /**
     * Enquiries were deleted (the retention, an erasure on request): the deals that came from them keep everything else and
     * forget the enquiry (source_enquiry_id = NULL). Returns how many deals changed.
     *
     * @param list<int> $idps
     */
    public static function forgetEnquiries(Db $db, array $idps): int
    {
        $idps = array_values(array_unique(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0)));
        $changed = 0;
        try {
            foreach (array_chunk($idps, 500) as $chunk) {
                $changed += $db->run('UPDATE {deals} SET source_enquiry_id = NULL WHERE source_enquiry_id IN (' . implode(',', $chunk) . ')')->rowCount();
            }
        } catch (\PDOException) {
            return $changed; // before migration 0117
        }

        return $changed;
    }

    /** Does a text name the person – their name (a word of at least 3 letters of it, all of them) or their address? Pure. */
    public static function names(string $text, string $name, string $email): bool
    {
        $text = mb_strtolower($text);
        $email = mb_strtolower(trim($email));
        if ($email !== '' && str_contains($text, $email)) {
            return true;
        }
        $words = array_values(array_filter(preg_split('/[\s,.;]+/u', mb_strtolower(trim($name))) ?: [], fn (string $w): bool => mb_strlen($w) >= 3));
        if ($words === []) {
            return false;
        }
        foreach ($words as $word) {
            if (!str_contains($text, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The deals of contacts for the personal data file (Core\PersonalData): title, stage, value, currency, when created and
     * closed, the lost reason and note.
     *
     * @param list<int> $contactIds
     * @return array<int, list<array<string, mixed>>> contact id => deals
     */
    public static function ofContacts(Db $db, array $contactIds): array
    {
        $contactIds = array_values(array_filter(array_map('intval', $contactIds), fn (int $id): bool => $id > 0));
        if ($contactIds === []) {
            return [];
        }
        $out = [];
        try {
            foreach ($db->all('SELECT * FROM {deals} WHERE contact_id IN (' . implode(',', $contactIds) . ') ORDER BY id') as $r) {
                $out[(int) $r['contact_id']][] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'stage' => (string) $r['stage'], 'value' => $r['value'] !== null ? (string) $r['value'] : null,
                    'currency' => $r['currency'] !== null ? (string) $r['currency'] : null, 'expected_close' => $r['expected_close'], 'created_at' => (string) $r['created_at'],
                    'closed_at' => $r['closed_at'], 'lost_reason' => $r['lost_reason'], 'lost_note' => $r['lost_note']];
            }
        } catch (\PDOException) {
            return [];
        }

        return $out;
    }

    /* ---------- permissions, labels, handover ---------- */

    /** May this account open the pipeline (the section SECTION: an active administrator or a row in ka_uzivatele_prava)? */
    public static function mayOpen(Db $db, Settings $siteSettings, int $userId): bool
    {
        return Handover::mayOpen($db, $siteSettings, $userId, self::SECTION);
    }

    /** The admin address of a deal (relative) – Admin\Modules\Pipeline, action detail. */
    public static function adminPath(int $id): string
    {
        return 'admin.php?module=' . self::SECTION . '&action=detail&id=' . $id;
    }

    /**
     * Labels of deals by target (Core\Inbox::places): 'deal:<id>' => kind, id, title ("#<id>" – a notification and its
     * e-mail never carry the title, which may name a customer), path. Missing ones are left out.
     *
     * @param list<int> $ids
     * @return array<string, array{kind: string, id: int, title: string, path: string}>
     */
    public static function places(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        $out = [];
        try {
            foreach ($ids === [] ? [] : $db->all('SELECT id FROM {deals} WHERE id IN (' . implode(',', $ids) . ')') as $r) {
                $out['deal:' . (int) $r['id']] = ['kind' => 'deal', 'id' => (int) $r['id'], 'title' => '#' . (int) $r['id'], 'path' => self::adminPath((int) $r['id'])];
            }
        } catch (\PDOException) {
            // before migration 0117
        }

        return $out;
    }

    /** How many open deals a person is responsible for (Core\Handover). */
    public static function owned(Db $db, int $userId): int
    {
        try {
            return (int) $db->value('SELECT COUNT(*) FROM {deals} WHERE owner_id = ? AND closed_at IS NULL', [$userId]);
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Is the person responsible for any deal, open or closed (what handOver() moves)? */
    public static function ownsAny(Db $db, int $userId): bool
    {
        try {
            return $db->value('SELECT 1 FROM {deals} WHERE owner_id = ? LIMIT 1', [$userId]) !== null;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return array<int, int> idu => open deals, only those who own some */
    public static function ownedByAll(Db $db): array
    {
        try {
            return array_map('intval', $db->pairs('SELECT owner_id, COUNT(*) FROM {deals} WHERE owner_id IS NOT NULL AND closed_at IS NULL GROUP BY owner_id'));
        } catch (\PDOException) {
            return [];
        }
    }

    /** Hands a leaver's deals – open and closed, so no leaver's id is left behind – to another person (null = nobody). */
    public static function handOver(Db $db, int $from, ?int $to): int
    {
        try {
            return $db->run('UPDATE {deals} SET owner_id = ? WHERE owner_id = ?', [$to, $from])->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }

    /* ---------- internals ---------- */

    /** The owner filter of list(): a user id, -1 = nobody, 0 = anyone. 'mine' = $userId, 'none' = nobody. Pure. */
    public static function ownerFilter(mixed $value, int $userId): int
    {
        if ($value === 'mine' || $value === 'me') {
            return $userId > 0 ? $userId : -1;
        }
        if ($value === 'none') {
            return -1;
        }

        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * A value as stored: null for none ('' or null), otherwise a non-negative amount with at most two decimals ("12 500,5"
     * and "12500.50" are the same). Refused when it is not a number, negative or too large.
     *
     * @throws \DomainException
     */
    public static function value(mixed $raw): ?string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_scalar($raw) || is_bool($raw)) {
            throw new \DomainException(t('The value must be a number.'));
        }
        $text = str_replace([' ', "\u{00A0}", "\u{202F}", "'"], '', trim((string) $raw));
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/D', $text) === 1) {
            $text = str_replace(['.', ','], ['', '.'], $text); // 12.500,50
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/D', $text) === 1) {
            $text = str_replace(',', '', $text); // 12,500.50
        } else {
            $text = str_replace(',', '.', $text);
        }
        if (preg_match('/^-/', $text) === 1) {
            throw new \DomainException(t('The value cannot be negative.'));
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/D', $text) !== 1) {
            throw new \DomainException(t('The value must be a number.'));
        }
        if ((float) $text > self::VALUE_MAX) {
            throw new \DomainException(t('The value is too large.'));
        }

        return number_format((float) $text, 2, '.', '');
    }

    /**
     * A currency as stored: '' for none, an upper-case code of CURRENCIES; anything else is refused.
     *
     * @throws \DomainException
     */
    public static function currency(mixed $raw): string
    {
        $code = is_scalar($raw) ? strtoupper(trim((string) $raw)) : '';
        if ($code === '') {
            return '';
        }
        if (!in_array($code, self::CURRENCIES, true)) {
            throw new \DomainException(t('“%s” is not a currency Kaleta offers.', mb_substr($code, 0, 10)));
        }

        return $code;
    }

    /**
     * An expected close day as stored: null for none, YYYY-MM-DD; anything else is refused.
     *
     * @throws \DomainException
     */
    private static function day(mixed $raw): ?string
    {
        $text = is_scalar($raw) ? trim((string) $raw) : '';
        if ($text === '' || $text === 'none') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new \DomainException(t('Write the expected close as a date, e.g. %s.', Clock::day('+30 days')));
        }

        return $text;
    }

    /** A responsible person as stored: null for nobody, the id of someone who may open the pipeline, or the refusal. */
    private static function owner(Db $db, Settings $s, mixed $value, ?int $stored): ?int
    {
        $id = (int) (is_scalar($value) ? $value : 0);
        if ($id <= 0) {
            return null;
        }
        if ($id !== $stored && !self::mayOpen($db, $s, $id)) {
            throw new \DomainException(t('This person cannot open the pipeline (or the account is blocked) – choose someone who can.'));
        }

        return $id;
    }

    /** Tells the new responsible person of a deal (Assignment::notify – never oneself). Never fails the save. */
    private static function notifyOwner(App $app, int $id, int $toUserId, int $byUserId): void
    {
        try {
            Assignment::notify($app, 'deal', $id, $toUserId, $byUserId);
        } catch (\Throwable) {
            // the e-mail is a courtesy; the save stands
        }
    }

    /** @return list<array<string, mixed>> */
    private static function of(Db $db, string $column, int $id, int $limit): array
    {
        if ($id <= 0) {
            return [];
        }
        try {
            $rows = $db->all('SELECT d.*, c.name AS contact_name, c.email AS contact_email, co.name AS company_name FROM {deals} d LEFT JOIN {contacts} c ON c.id = d.contact_id'
                . ' LEFT JOIN {companies} co ON co.id = d.company_id WHERE d.' . $column . ' = ? ORDER BY d.closed_at IS NOT NULL, d.closed_at DESC, d.id DESC LIMIT ' . max(1, min(500, $limit)), [$id]);
        } catch (\PDOException) {
            return [];
        }

        return array_map(self::row(...), $rows);
    }

    /** Two decimal amounts added exactly (as strings – no float rounding over many deals). Pure. */
    private static function add(string $a, string $b): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, 2);
        }
        [$ai, $af] = explode('.', number_format((float) $a, 2, '.', '')) + [1 => '00'];
        [$bi, $bf] = explode('.', number_format((float) $b, 2, '.', '')) + [1 => '00'];
        $cents = ((int) $ai * 100 + (int) $af) + ((int) $bi * 100 + (int) $bf);

        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** One line of text: whitespace folded, control characters and tags removed, at most $max characters. Pure. */
    private static function line(mixed $value, int $max): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) preg_replace('/\s+/u', ' ', strip_tags($value)))), 0, $max);
    }

    /**
     * A row of ka_deals with its numbers as numbers.
     *
     * @param array<string, mixed> $r
     * @return array{id: int, title: string, contact_id: ?int, contact_name: ?string, contact_email: ?string, company_id: ?int, company_name: ?string, owner_id: ?int, stage: string, value: ?string, currency: ?string, source_enquiry_id: ?int, expected_close: ?string, closed_at: ?string, lost_reason: ?string, lost_note: string, created_by: ?int, created_at: string, updated_at: string}
     */
    private static function row(array $r): array
    {
        $int = fn (mixed $v): ?int => $v !== null ? (int) $v : null;
        $str = fn (mixed $v): ?string => $v !== null ? (string) $v : null;

        return ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'contact_id' => $int($r['contact_id']), 'contact_name' => $str($r['contact_name'] ?? null),
            'contact_email' => $str($r['contact_email'] ?? null), 'company_id' => $int($r['company_id']), 'company_name' => $str($r['company_name'] ?? null), 'owner_id' => $int($r['owner_id']),
            'stage' => (string) $r['stage'], 'value' => $str($r['value']), 'currency' => $str($r['currency']), 'source_enquiry_id' => $int($r['source_enquiry_id']),
            'expected_close' => $str($r['expected_close']), 'closed_at' => $str($r['closed_at']), 'lost_reason' => $str($r['lost_reason']), 'lost_note' => (string) ($r['lost_note'] ?? ''),
            'created_by' => $int($r['created_by']), 'created_at' => (string) $r['created_at'], 'updated_at' => (string) $r['updated_at']];
    }
}
