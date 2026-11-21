<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Quotes (3.38, design A3, A6, A10; tables ka_quotes, ka_quote_lines and ka_quote_counters, migration 0123) – the core
 * without screens: the next parts build the list, the editor, the detail, the document, sending and MCP on it. The feature
 * and the section are those of Core\Prices (EXTENSION, SECTION). The public site never reads a quote except the one the
 * customer's link opens (Front\QuotePage, a later part).
 *
 *  - A quote copies the customer block (snapshot of the contact or company, editable) and, per line, the name, code and
 *    price from the price list (Prices::forItem) – a later price change never alters a quote. A line whose price differs
 *    from the list or that has no list price is "manual" (manual_prices on the quote); a line without a price cannot be
 *    sent (sendRefusal). At most MAX_LINES lines; one quote, one price list, one currency – no exchange rates.
 *  - Totals (Core\Money, integer minor units): each line round(quantity × unit price × (1 − line discount)), the whole-
 *    quote discount on each line, VAT per rate on the sum of its lines; vat_mode standard | reverse_charge | none. The
 *    server computes them on every save – the admin never duplicates the arithmetic in JavaScript.
 *  - Statuses: draft → sent (markSent: the number, the frozen snapshot) → accepted | declined (with a reason of
 *    Deals::LOST_REASONS) | expired; accepted and declined go back to sent (a mistake). Only a draft can be edited or
 *    changed; every status change goes into history (at most HISTORY_MAX entries).
 *  - The number (design A-7) is assigned at the first send or Finalise, never to a draft: quote_number_format ({YYYY} or
 *    {YY}, {NNNN}, an optional prefix) and one counter per year in ka_quote_counters taken with SELECT … FOR UPDATE inside
 *    the transaction, so two people sending at once never get the same number; quote_next_number continues an existing
 *    series (raiseCounter only raises the counter of the current year). A revision gets its original's number with /2, /3…
 *    and, once sent, the original expires with the note "replaced".
 *  - The deal (A-10): setDealValue() sets the linked deal's value and currency to the quote's NET total – only when the
 *    sender asks (the checkbox, ticked by default by quote_set_deal_value); nothing moves a deal by itself.
 *  - The change log, bells and events name the quote by its number (a draft by #id) – never an amount; quotes are never
 *    journaled for undo (AgentJournal::PERSONAL_TABLES) and never reach a report as money.
 *
 * Since 3.49 three classes carry parts of it, and every public member that moved keeps a forward here (the block at the
 * end), so callers, migrations and index.php are unchanged:
 *  - Core\QuoteLines – the totals, the discounts and one line as stored (the arithmetic 3.53 invoices call);
 *  - Core\QuoteNumbers – the number format and the counter per year;
 *  - Core\QuoteIntake – the lines of an enquiry and the draft prepared for a new enquiry about products.
 *
 * Messages of \DomainException are translated (t()); MCP calls them inside Language::runWith('en').
 */
final class Quotes
{
    public const string EXTENSION = Prices::EXTENSION;
    public const string SECTION = Prices::SECTION;

    /** The statuses of a quote. */
    public const array STATUSES = ['draft', 'sent', 'accepted', 'declined', 'expired'];

    /** The statuses => their English labels (t() in the admin; MCP keeps the keys). */
    public const array STATUS_LABELS = ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'];

    /** What setStatus() may do: from => the statuses it may go to (draft → sent only through markSent/finalise). */
    public const array TRANSITIONS = ['draft' => [], 'sent' => ['accepted', 'declined', 'expired'], 'accepted' => ['sent'], 'declined' => ['sent'], 'expired' => []];

    /** At most this many lines on one quote and entries in its history. */
    public const int MAX_LINES = 200;
    public const int HISTORY_MAX = 50;

    /** The longest values. */
    public const int TITLE_MAX = 200;
    public const int TEXT_MAX = 5000;

    /** The customer block: key => the longest value. */
    public const array CUSTOMER = ['name' => 150, 'company' => 150, 'address' => 500, 'reg_no' => 40, 'vat_id' => 40, 'email' => 190];

    public static function isOn(Settings $settings): bool
    {
        return Prices::isOn($settings);
    }

    /** May this account work with quotes (the feature on, an administrator or the section)? */
    public static function mayOpen(Db $db, Settings $siteSettings, int $userId): bool
    {
        return Prices::mayRead($db, $siteSettings, $userId);
    }

    /** The admin address of a quote (relative) – Admin\Modules\Quotes, action detail. */
    public static function adminPath(int $id): string
    {
        return 'admin.php?module=' . self::SECTION . '&action=detail&id=' . $id;
    }

    /** How a quote is named in the change log, bells and e-mails to colleagues: its number, a draft by its id. Never an amount. */
    public static function label(?string $number, int $id): string
    {
        return $number !== null && $number !== '' ? $number : '#' . $id;
    }

    /* ---------- reading ---------- */

    /**
     * One quote with its lines, totals per VAT rate, history and frozen snapshot, or null. Amounts are DECIMAL texts
     * ("12500.50"), never floats. The shape is the contract of the quote screens, the document and MCP (api338.md).
     *
     * @return array<string, mixed>|null
     */
    public static function get(Db $db, int $id): ?array
    {
        try {
            $q = $id > 0 ? $db->one('SELECT * FROM {quotes} WHERE id = ?', [$id]) : null;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0123
        }
        if ($q === null) {
            return null;
        }
        $lines = self::lines($db, (int) $q['id']);
        $totals = QuoteLines::computeTotals($lines, (string) $q['discount_percent'], (string) $q['vat_mode']);
        $history = json_decode((string) ($q['history'] ?? ''), true);
        $snapshot = json_decode((string) ($q['snapshot'] ?? ''), true);
        $int = fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return ['id' => (int) $q['id'], 'number' => $q['number'] === null ? null : (string) $q['number'], 'label' => self::label($q['number'], (int) $q['id']),
            'revision_of' => $int($q['revision_of']), 'status' => (string) $q['status'],
            'contact_id' => $int($q['contact_id']), 'company_id' => $int($q['company_id']), 'enquiry_id' => $int($q['enquiry_id']), 'deal_id' => $int($q['deal_id']),
            'owner_id' => $int($q['owner_id']), 'price_list_id' => (int) $q['price_list_id'], 'currency' => (string) $q['currency'], 'language' => (string) $q['language'],
            'customer' => ['name' => (string) $q['customer_name'], 'company' => (string) $q['customer_company'], 'address' => (string) $q['customer_address'],
                'reg_no' => (string) $q['customer_reg_no'], 'vat_id' => (string) $q['customer_vat_id'], 'email' => (string) $q['customer_email']],
            'title' => (string) $q['title'], 'intro' => (string) ($q['intro'] ?? ''), 'terms' => (string) ($q['terms'] ?? ''),
            'valid_until' => $q['valid_until'] === null ? null : (string) $q['valid_until'], 'discount_percent' => Money::percentText((int) Money::fromDecimal($q['discount_percent'])),
            'vat_mode' => (string) $q['vat_mode'], 'totals' => $totals, 'manual_prices' => (bool) $q['manual_prices'], 'lines' => $lines,
            'history' => is_array($history) ? $history : [], 'snapshot' => is_array($snapshot) ? $snapshot : null,
            'has_link' => $q['token_hash'] !== null, 'token_expires_at' => $q['token_expires_at'] === null ? null : (string) $q['token_expires_at'],
            'sent_at' => $q['sent_at'] === null ? null : (string) $q['sent_at'], 'sent_by' => $int($q['sent_by']), 'sent_mail' => $int($q['sent_mail']),
            'decided_at' => $q['decided_at'] === null ? null : (string) $q['decided_at'], 'decided_by' => $int($q['decided_by']),
            'decline_reason' => $q['decline_reason'] === null ? null : (string) $q['decline_reason'],
            'created_by' => $int($q['created_by']), 'created_at' => (string) $q['created_at'], 'updated_at' => (string) $q['updated_at'],
            'anonymised_at' => $q['anonymised_at'] === null ? null : (string) $q['anonymised_at'],
            // 3.40 (migration 0128): the first opening of the customer's link (only with quote_track_opened), the customer's own
            // words when they declined, and whether the last decision was the customer's own (the Accept / Decline buttons)
            'first_opened_at' => ($q['first_opened_at'] ?? null) === null ? null : (string) $q['first_opened_at'],
            'customer_reason' => ($q['customer_reason'] ?? null) === null ? null : (string) $q['customer_reason'],
            'decided_by_customer' => $q['decided_at'] !== null && in_array((string) $q['status'], ['accepted', 'declined'], true) && self::customerDecided(is_array($history) ? $history : [], (string) $q['status'])];
    }

    /**
     * The lines of a quote in their order.
     *
     * @return list<array{id: int, position: int, item_id: ?int, variant_code: string, code: string, name: string, description: string, quantity: string, unit: string, unit_price: ?string, list_price: ?string, discount_percent: string, vat_rate: string, line_net: string, manual: bool}>
     */
    public static function lines(Db $db, int $quoteId): array
    {
        return array_map(function (array $l): array {
            $price = Money::fromDecimal($l['unit_price']);
            $list = Money::fromDecimal($l['list_price']);

            return ['id' => (int) $l['id'], 'position' => (int) $l['position'], 'item_id' => $l['item_id'] === null ? null : (int) $l['item_id'],
                'variant_code' => (string) $l['variant_code'], 'code' => (string) $l['code'], 'name' => (string) $l['name'], 'description' => (string) $l['description'],
                'quantity' => Money::quantityText(Money::quantity((string) $l['quantity'])), 'unit' => (string) $l['unit'],
                'unit_price' => $price === null ? null : Money::toDecimal($price), 'list_price' => $list === null ? null : Money::toDecimal($list),
                'discount_percent' => Money::percentText((int) Money::fromDecimal($l['discount_percent'])), 'vat_rate' => Money::percentText((int) Money::fromDecimal($l['vat_rate'])),
                'line_net' => Money::toDecimal((int) Money::fromDecimal($l['line_net'])), 'manual' => QuoteLines::isManual($price, $list)];
        }, $db->all('SELECT * FROM {quote_lines} WHERE quote_id = ? ORDER BY position, id', [$quoteId]));
    }

    /**
     * The quotes of an enquiry, a deal, a contact or a company, newest first (the boxes of the record pages): id, number,
     * title, status, currency, totals, validity, dates.
     *
     * @return list<array{id: int, number: ?string, label: string, title: string, status: string, currency: string, total_net: string, total_gross: string, valid_until: ?string, sent_at: ?string, updated_at: string}>
     */
    public static function of(Db $db, string $kind, int $id, int $limit = 50): array
    {
        $column = ['enquiry' => 'enquiry_id', 'deal' => 'deal_id', 'contact' => 'contact_id', 'company' => 'company_id'][$kind] ?? throw new \InvalidArgumentException('kind');
        try {
            $rows = $db->all('SELECT id, number, title, status, currency, total_net, total_gross, valid_until, sent_at, updated_at FROM {quotes} WHERE ' . $column . ' = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)), [$id]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0123
        }

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'number' => $r['number'] === null ? null : (string) $r['number'], 'label' => self::label($r['number'], (int) $r['id']),
            'title' => (string) $r['title'], 'status' => (string) $r['status'], 'currency' => (string) $r['currency'], 'total_net' => (string) $r['total_net'], 'total_gross' => (string) $r['total_gross'],
            'valid_until' => $r['valid_until'] === null ? null : (string) $r['valid_until'], 'sent_at' => $r['sent_at'] === null ? null : (string) $r['sent_at'], 'updated_at' => (string) $r['updated_at']], $rows);
    }

    /** 3.38 B: the filters of the quote list => what they keep (all, open = still in play, or one status). */
    public const array LIST_STATUSES = ['all', 'open', 'draft', 'sent', 'accepted', 'declined', 'expired'];

    /**
     * 3.38 B: the quote list (Quotes → Quotes, MCP list_quotes): newest change first, filtered by status (LIST_STATUSES;
     * open = draft or sent), the responsible person (owner: a user id, -1 = nobody), contact, company, deal, enquiry and a
     * word in the number, title or customer block. Rows as of() returns them plus the customer's name, company and e-mail,
     * the owner and revision_of.
     *
     * @param array{status?: string, owner?: int, contact?: int, company?: int, deal?: int, enquiry?: int, search?: string} $filter
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function list(Db $db, array $filter, int $page = 1, int $limit = 30): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = $filter['status'] ?? 'all';
        if ($status === 'open') {
            $where[] = "status IN ('draft', 'sent')";
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $owner = (int) ($filter['owner'] ?? 0);
        if ($owner === -1) {
            $where[] = 'owner_id IS NULL';
        } elseif ($owner > 0) {
            $where[] = 'owner_id = ?';
            $params[] = $owner;
        }
        foreach (['contact' => 'contact_id', 'company' => 'company_id', 'deal' => 'deal_id', 'enquiry' => 'enquiry_id'] as $key => $column) {
            if ((int) ($filter[$key] ?? 0) > 0) {
                $where[] = $column . ' = ?';
                $params[] = (int) $filter[$key];
            }
        }
        $search = mb_substr(trim((string) ($filter['search'] ?? '')), 0, 100);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = '(number LIKE ? OR title LIKE ? OR customer_name LIKE ? OR customer_company LIKE ? OR customer_email LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $limit = max(1, min(200, $limit));
        $sql = ' FROM {quotes} WHERE ' . implode(' AND ', $where);
        try {
            $total = (int) $db->value('SELECT COUNT(*)' . $sql, $params);
            $pages = max(1, (int) ceil($total / $limit));
            $page = max(1, min($page, $pages));
            $rows = $db->all('SELECT id, number, revision_of, title, status, currency, total_net, total_gross, valid_until, sent_at, updated_at, owner_id, customer_name, customer_company, customer_email'
                . $sql . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), $params);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1]; // before migration 0123
        }

        return ['rows' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'number' => $r['number'] === null ? null : (string) $r['number'], 'label' => self::label($r['number'], (int) $r['id']),
            'revision_of' => $r['revision_of'] === null ? null : (int) $r['revision_of'], 'title' => (string) $r['title'], 'status' => (string) $r['status'], 'currency' => (string) $r['currency'],
            'total_net' => (string) $r['total_net'], 'total_gross' => (string) $r['total_gross'], 'valid_until' => $r['valid_until'] === null ? null : (string) $r['valid_until'],
            'sent_at' => $r['sent_at'] === null ? null : (string) $r['sent_at'], 'updated_at' => (string) $r['updated_at'], 'owner_id' => $r['owner_id'] === null ? null : (int) $r['owner_id'],
            'customer_name' => (string) $r['customer_name'], 'customer_company' => (string) $r['customer_company'], 'customer_email' => (string) $r['customer_email']], $rows),
            'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /* ---------- saving ---------- */

    /**
     * Creates a draft or changes one (only a draft can change). Fields (all optional; on a change only the given ones):
     * price_list_id (default list; fixes the currency – refused once the quote has stored lines, 3.38 review M1), enquiry_id (a new quote:
     * its customer, its open deal and – without lines – its basket through linesFromEnquiry), deal_id, contact_id,
     * company_id (the customer block is filled from them unless customer is given), customer {name, company, address,
     * reg_no, vat_id, email}, owner_id (someone who may open Quotes; a new quote: its creator), language, title, intro,
     * terms (new: the defaults of the settings), valid_until (YYYY-MM-DD) or valid_days (new: quote_valid_days),
     * discount_percent, vat_mode (new: standard, or none when the firm is no VAT payer), lines (replaces all: each
     * {id (an existing line keeps its list price), item_id or code, variant_code, name, description, quantity, unit,
     * unit_price (absent = the list price), discount_percent, vat_rate}), expected_updated_at (a warning when someone saved
     * in between – last save wins).
     *
     * With $byUserId > 0 the rules of save_deal apply: an enquiry needs Enquiries, a contact or company Customers, a deal the
     * Pipeline. $fromClaude: a discount above quote_max_discount is refused, and a product without a list price keeps a
     * line without a price (Claude never guesses one).
     *
     * @param array<string, mixed> $fields
     * @return array{id: int, warnings: list<string>}
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function save(App $app, ?int $id, array $fields, int $byUserId, bool $fromClaude = false): array
    {
        return Prices::withCodeIndex(fn (): array => self::saveNow($app, $id, $fields, $byUserId, $fromClaude));
    }

    /**
     * save() with the product codes read once (Prices::withCodeIndex – 3.42 review M1).
     *
     * @param array<string, mixed> $fields
     * @return array{id: int, warnings: list<string>}
     */
    private static function saveNow(App $app, ?int $id, array $fields, int $byUserId, bool $fromClaude): array
    {
        $db = $app->db();
        $s = $app->settings();
        $quote = $id !== null && $id > 0 ? (self::get($db, $id) ?? throw new \DomainException(t('The quote does not exist.'))) : null;
        if ($quote !== null && $quote['status'] !== 'draft') {
            throw new \DomainException(t('A sent quote is frozen – revise it to make a new draft.'));
        }
        $warnings = [];
        if ($quote !== null && is_string($fields['expected_updated_at'] ?? null) && $fields['expected_updated_at'] !== '' && $fields['expected_updated_at'] !== $quote['updated_at']) {
            $warnings[] = t('Someone else saved this quote in the meantime – your version replaced theirs.');
        }
        $has = fn (string $key): bool => array_key_exists($key, $fields);
        $int = fn (string $key): int => (int) (is_scalar($fields[$key] ?? null) ? $fields[$key] : 0);
        $data = [];

        // where it came from: the enquiry, the deal; the customer
        $sources = self::sources($app, $quote, $fields, $byUserId);

        // the price list and its currency – 3.40: a new quote (or a draft without lines whose customer changes) takes the
        // price list of its contact or company unless one is chosen (customerList)
        $customerList = null;
        if (!$has('price_list_id') && ($quote === null || ($quote['lines'] === [] && (array_key_exists('contact_id', $sources['data']) || array_key_exists('company_id', $sources['data']))))) {
            $customerList = self::customerList($db, array_key_exists('contact_id', $sources['data']) ? $sources['data']['contact_id'] : ($quote['contact_id'] ?? null),
                array_key_exists('company_id', $sources['data']) ? $sources['data']['company_id'] : ($quote['company_id'] ?? null));
        }
        if ($quote === null || $has('price_list_id') || ($customerList !== null && $customerList['id'] !== $quote['price_list_id'])) {
            $list = $customerList ?? ($int('price_list_id') > 0 ? Prices::priceList($db, $int('price_list_id')) : Prices::defaultList($db, $s));
            if ($list === null) {
                throw new \DomainException(t('The price list does not exist.'));
            }
            if ($list['archived_at'] !== null && ($quote === null || $quote['price_list_id'] !== $list['id'])) {
                throw new \DomainException(t('The price list %s is archived – choose another one.', $list['name']));
            }
            if ($quote !== null && $list['id'] !== $quote['price_list_id'] && $quote['lines'] !== []) {
                // 3.38 review M1: lines kept by id keep the prices (and currency) of the old list – never a quote in one currency with numbers of another
                throw new \DomainException(t('The price list of a quote with lines cannot change – remove the lines first, or start a new quote.'));
            }
            if ($fromClaude && $has('price_list_id') && $customerList === null) {
                // 3.44 review M2: Claude prices from the customer's list or the default one – never a cheaper list of its choice
                $allowed = array_filter([Prices::defaultList($db, $s)['id'], self::customerList($db, array_key_exists('contact_id', $sources['data']) ? $sources['data']['contact_id'] : ($quote['contact_id'] ?? null),
                    array_key_exists('company_id', $sources['data']) ? $sources['data']['company_id'] : ($quote['company_id'] ?? null))['id'] ?? null]);
                if (!in_array($list['id'], $allowed, true)) {
                    throw new \DomainException(t('Claude uses the customer’s price list or the default one – ask a person to choose another list in the admin.'));
                }
            }
            $data['price_list_id'] = $list['id'];
            $data['currency'] = $list['currency'];
        } else {
            $list = Prices::priceList($db, $quote['price_list_id']) ?? Prices::defaultList($db, $s);
        }

        $data += $sources['data'];
        if ($has('customer') || $sources['fill'] !== null) {
            $given = is_array($fields['customer'] ?? null) ? $fields['customer'] : [];
            $base = $sources['fill'] ?? ($quote['customer'] ?? array_fill_keys(array_keys(self::CUSTOMER), ''));
            foreach (self::CUSTOMER as $key => $max) {
                $value = array_key_exists($key, $given) ? QuoteLines::line($given[$key], $max) : QuoteLines::line($base[$key] ?? '', $max);
                if ($key === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \DomainException(t('The customer’s e-mail is not a valid address.'));
                }
                $data['customer_' . $key] = $value;
            }
        }
        // 3.44 (N146): a change of the customer's e-mail goes into the history with who wrote it (via mcp = a Claude connection) –
        // the Send dialog warns when a connection wrote the address a person is about to send to
        $history = [];
        $emailBefore = (string) ($quote['customer']['email'] ?? ($sources['fill']['email'] ?? ''));
        if (isset($data['customer_email']) && is_array($fields['customer'] ?? null) && array_key_exists('email', $fields['customer'])
            && PersonalData::cleanAddress($data['customer_email']) !== PersonalData::cleanAddress($emailBefore) && ($quote !== null || $fromClaude)) {
            $history[] = ['at' => Clock::now(), 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'customer_email'] + ($fromClaude ? ['via' => 'mcp'] : []);
        }

        // the responsible person
        if ($quote === null && !$has('owner_id')) {
            $data['owner_id'] = $byUserId > 0 && self::mayOpen($db, $s, $byUserId) ? $byUserId : null;
        } elseif ($has('owner_id')) {
            $owner = $int('owner_id');
            if ($owner > 0 && !self::mayOpen($db, $s, $owner)) {
                throw new \DomainException(t('The responsible person must be someone who may open Quotes.'));
            }
            $data['owner_id'] = $owner > 0 ? $owner : null;
        }

        // texts, validity, language, discount, VAT
        if ($quote === null || $has('title')) {
            $data['title'] = QuoteLines::line($fields['title'] ?? ($sources['title'] ?? ''), self::TITLE_MAX);
        }
        if ($quote === null || $has('language')) {
            $data['language'] = Language::column($s, is_string($fields['language'] ?? null) ? $fields['language'] : '');
        }
        foreach (['intro', 'terms'] as $key) {
            if ($quote === null || $has($key)) {
                // 3.38 C: the default text in the quote's language (QuoteDelivery::defaultText)
                $data[$key] = QuoteLines::text($has($key) ? $fields[$key] : QuoteDelivery::defaultText($s, $key, (string) ($data['language'] ?? $quote['language'] ?? '')), self::TEXT_MAX);
            }
        }
        if ($has('valid_until') || $has('valid_days') || $quote === null) {
            $data['valid_until'] = self::validUntil($fields, $s, $has('valid_until') || $has('valid_days') ? null : 'default');
        }
        if ($quote === null || $has('discount_percent')) {
            $discount = Money::percent($fields['discount_percent'] ?? null);
            QuoteLines::checkDiscount($discount, $s, $fromClaude);
            $data['discount_percent'] = Money::percentText($discount);
        }
        if ($quote === null || $has('vat_mode')) {
            $mode = is_string($fields['vat_mode'] ?? null) && $fields['vat_mode'] !== '' ? $fields['vat_mode'] : ($s->bool('quote_vat_payer') ? 'standard' : 'none');
            if (!in_array($mode, Money::VAT_MODES, true)) {
                throw new \DomainException(t('The VAT mode must be one of: %s.', implode(', ', Money::VAT_MODES)));
            }
            if ($fromClaude && $has('vat_mode') && in_array($mode, ['none', 'reverse_charge'], true) && $s->bool('quote_vat_payer') && $mode !== ($quote['vat_mode'] ?? 'standard')) {
                // 3.44 review M2: reverse charge leaves the VAT out of what the customer pays as well
                // 3.44 (N143): a quote without VAT from a VAT payer lowers what the customer pays – a person decides it in the admin
                throw new \DomainException(t('A quote without VAT from a firm that pays VAT – ask a person to set it in the admin.'));
            }
            $data['vat_mode'] = $mode;
        }
        if (!$s->bool('quote_vat_payer') && ($data['vat_mode'] ?? $quote['vat_mode'] ?? 'none') !== 'none') {
            // 3.38 review L8: a firm that is no VAT payer has quotes without VAT – the stored totals, the list and the document agree (gross = net)
            if ($has('vat_mode')) {
                $warnings[] = t('The firm is not a VAT payer (Quotes → Settings) – the quote is without VAT.');
            }
            $data['vat_mode'] = 'none';
        }

        // the lines
        $lines = null;
        $inputLines = $has('lines') ? $fields['lines'] : ($quote === null ? $sources['lines'] : null);
        if ($inputLines !== null) {
            if (!is_array($inputLines) || !array_is_list($inputLines)) {
                throw new \DomainException(t('The lines must be a list.'));
            }
            if (count($inputLines) > self::MAX_LINES) {
                throw new \DomainException(t('A quote has at most %d lines.', self::MAX_LINES));
            }
            $stored = array_column($quote['lines'] ?? [], null, 'id');
            $lines = [];
            foreach ($inputLines as $i => $input) {
                if (!is_array($input)) {
                    throw new \DomainException(t('Line %d is not valid.', $i + 1));
                }
                $lines[] = QuoteLines::prepareLine($db, $s, $list, $input, $stored[(int) ($input['id'] ?? 0)] ?? null, $fromClaude, $warnings, $i + 1);
            }
        }
        $current = $lines ?? ($quote['lines'] ?? []);
        if ($fromClaude && ($lines !== null || isset($data['discount_percent']))) {
            // 3.38 review L4: Claude's cap is on what the customer gets – a line's discount and the whole-quote discount together
            $quoteDiscount = Money::percent($data['discount_percent'] ?? $quote['discount_percent'] ?? null);
            foreach ($current as $n => $l) {
                // 3.44 (N143): a price below the list counts too – a line Claude priced now, or one kept from before
                $implied = max((int) ($l['implied_discount'] ?? 0), QuoteLines::impliedDiscount(Money::fromDecimal($l['unit_price']), Money::fromDecimal($l['list_price'] ?? null)));
                $line = QuoteLines::combinedDiscount($implied, Money::percent($l['discount_percent']));
                QuoteLines::checkDiscount(QuoteLines::combinedDiscount($line, $quoteDiscount), $s, true, $n + 1);
            }
        }
        $totals = QuoteLines::computeTotals($current, (string) ($data['discount_percent'] ?? $quote['discount_percent'] ?? '0.00'), (string) ($data['vat_mode'] ?? $quote['vat_mode'] ?? 'standard'));
        $data['total_net'] = $totals['net'];
        $data['total_vat'] = $totals['vat'];
        $data['total_gross'] = $totals['gross'];
        $data['manual_prices'] = array_filter($current, fn (array $l): bool => QuoteLines::isManual(Money::fromDecimal($l['unit_price']), Money::fromDecimal($l['list_price'] ?? null))) !== [] ? 1 : 0;
        if ($totals['unpriced'] > 0) {
            $warnings[] = t('%d lines have no price yet – the quote cannot be sent until a person fills them.', $totals['unpriced']);
        }
        if (($data['vat_mode'] ?? $quote['vat_mode'] ?? '') === 'standard' && Prices::defaultVatRate($s) === null && array_filter($current, fn (array $l): bool => Money::percent($l['vat_rate']) === 0) !== []) {
            $warnings[] = t('The default VAT rate is not set (Quotes → Settings) – lines without their own rate have 0 %.');
        }

        $now = Clock::now();
        $id = $db->transaction(function (Db $db) use ($quote, $data, $lines, $byUserId, $now, $history): int {
            // 3.38 review M2: the status is checked again under the row lock – a save that overlaps Send never changes a numbered quote
            if ($quote !== null && $db->value('SELECT status FROM {quotes} WHERE id = ? FOR UPDATE', [$quote['id']]) !== 'draft') {
                throw new \DomainException(t('A sent quote is frozen – revise it to make a new draft.'));
            }
            if ($quote === null) {
                $id = $db->insert('quotes', $data + ['status' => 'draft', 'number' => null, 'revision_of' => null, 'created_by' => $byUserId > 0 ? $byUserId : null, 'created_at' => $now, 'updated_at' => $now,
                    'history' => (string) json_encode([['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'created'], ...$history], JSON_UNESCAPED_UNICODE)]);
            } else {
                $id = $quote['id'];
                $more = $history !== [] ? ['history' => self::appended($quote['history'], $history[0])] : [];
                $db->update('quotes', $data + $more + ['updated_at' => $now], ['id' => $id]);
            }
            if ($lines !== null) {
                $db->run('DELETE FROM {quote_lines} WHERE quote_id = ?', [$id]);
                foreach ($lines as $position => $line) {
                    unset($line['id'], $line['manual'], $line['implied_discount']);
                    $db->insert('quote_lines', ['quote_id' => $id, 'position' => $position + 1] + $line);
                }
            }

            return $id;
        });
        \Kaleta\Core\ChangeLog::write($app, self::SECTION, $quote === null ? 'create' : 'save', t('Quote %s', self::label($quote['number'] ?? null, $id)));

        return ['id' => $id, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Why a quote cannot be sent or finalised now (translated), or null: it is not a draft, has no line, a line without a
     * price, no customer name, or reverse charge without the customer's VAT number. $needsEmail: sending also needs the
     * customer's e-mail (Finalise does not – a quote handed over on paper).
     *
     * @param array<string, mixed> $quote as get() returns it
     */
    public static function sendRefusal(array $quote, bool $needsEmail = true): ?string
    {
        return match (true) {
            $quote['status'] !== 'draft' => t('The quote was already sent.'),
            $quote['lines'] === [] => t('Add at least one line to the quote.'),
            $quote['totals']['unpriced'] > 0 => t('Fill the price of every line first – %d lines have none.', $quote['totals']['unpriced']),
            trim($quote['customer']['name']) === '' && trim($quote['customer']['company']) === '' => t('Fill in the customer – a name or a company.'),
            $quote['vat_mode'] === 'reverse_charge' && trim($quote['customer']['vat_id']) === '' => t('Reverse charge needs the customer’s VAT number.'),
            $needsEmail && trim($quote['customer']['email']) === '' => t('The customer has no e-mail – finalise the quote and hand it over, or add the e-mail.'),
            default => null,
        };
    }

    /**
     * Marks a draft as sent (the send dialog after the e-mail is queued, or Finalise without an e-mail): assigns the number
     * (assignNumber, in the same transaction that locks the quote), freezes the snapshot, sets sent_at/sent_by/sent_mail and
     * the status, appends the history ("sent" or "finalised"). A revision's original expires with the note "replaced".
     * A quote that is no longer a draft is refused (two people sending at once: one wins).
     *
     * @return array<string, mixed> the quote as get() returns it
     * @throws \DomainException with the reason of sendRefusal()
     */
    public static function markSent(App $app, int $id, int $byUserId, ?int $mailId = null, string $action = 'sent'): array
    {
        $db = $app->db();
        $quote = self::get($db, $id) ?? throw new \DomainException(t('The quote does not exist.'));
        $refusal = self::sendRefusal($quote, $action === 'sent');
        if ($refusal !== null) {
            throw new \DomainException($refusal);
        }
        $now = Clock::now();
        $number = $db->transaction(function (Db $db) use ($app, $id, $byUserId, $mailId, $action, $now): string {
            $locked = $db->one('SELECT status, number FROM {quotes} WHERE id = ? FOR UPDATE', [$id]);
            if ($locked === null || $locked['status'] !== 'draft') {
                throw new \DomainException(t('The quote was already sent.'));
            }
            // 3.38 review M2: the frozen document (and the totals the deal value takes) is built from the quote as it is under the lock
            $quote = self::get($db, $id) ?? throw new \DomainException(t('The quote does not exist.'));
            if (!$app->settings()->bool('quote_vat_payer') && $quote['vat_mode'] !== 'none') {
                self::withoutVat($db, [$id]); // 3.38 review L8: a draft saved while the firm was a VAT payer
                $quote = self::get($db, $id);
            }
            $refusal = self::sendRefusal($quote, $action === 'sent');
            if ($refusal !== null) {
                throw new \DomainException($refusal);
            }
            $snapshot = self::snapshot($app, $quote);
            $number = $locked['number'] !== null ? (string) $locked['number'] : QuoteNumbers::assignNumber($db, $app->settings(), $quote['revision_of']);
            $snapshot['number'] = $number;
            $snapshot['sent_at'] = $now;
            $db->update('quotes', ['number' => $number, 'status' => 'sent', 'snapshot' => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'sent_at' => $now,
                'sent_by' => $byUserId > 0 ? $byUserId : null, 'sent_mail' => $mailId, 'decided_at' => null, 'decided_by' => null, 'decline_reason' => null,
                'history' => self::appended($quote['history'], ['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'action' => $action, 'from' => 'draft', 'to' => 'sent']), 'updated_at' => $now], ['id' => $quote['id']]);
            if ($quote['revision_of'] !== null) {
                $original = $db->one('SELECT status, history FROM {quotes} WHERE id = ? FOR UPDATE', [$quote['revision_of']]);
                if ($original !== null && $original['status'] === 'sent') {
                    $db->update('quotes', ['status' => 'expired', 'updated_at' => $now, 'history' => self::appended(json_decode((string) $original['history'], true) ?: [],
                        ['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'replaced', 'from' => 'sent', 'to' => 'expired', 'note' => $number])], ['id' => $quote['revision_of']]);
                }
            }

            return $number;
        });
        \Kaleta\Core\ChangeLog::write($app, self::SECTION, $action === 'sent' ? 'send' : 'finalise', ($action === 'sent' ? t('Quote %s sent', $number) : t('Quote %s finalised', $number)));

        return self::get($db, $id); // PHPStan remembers the get() above as not null; the row is never deleted in between
    }

    /**
     * Drafts without VAT (3.38 review L8): the firm stopped being a VAT payer (Quotes → Settings), so the drafts that still
     * count VAT get vat_mode none and gross = net, as the document shows them. Null = every such draft. Returns how many.
     *
     * @param list<int>|null $ids
     */
    public static function withoutVat(Db $db, ?array $ids = null): int
    {
        $ids = $ids === null ? null : array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        return $db->run("UPDATE {quotes} SET vat_mode = 'none', total_vat = '0.00', total_gross = total_net WHERE status = 'draft' AND vat_mode <> 'none'"
            . ($ids !== null ? ' AND id IN (' . implode(',', $ids) . ')' : ''))->rowCount();
    }

    /** Finalise without sending (a quote handed over on paper): the number and the frozen snapshot, status sent. @return array<string, mixed> */
    public static function finalise(App $app, int $id, int $byUserId): array
    {
        return self::markSent($app, $id, $byUserId, null, 'finalised');
    }

    /**
     * Changes the status of a sent quote: accepted, declined (a reason of Deals::LOST_REASONS), expired (the expiry job or a
     * person), or back to sent from accepted/declined (a mistake). The same status changes nothing (false). Everything goes
     * into the history; the change log names the number, never an amount. Nothing moves the deal by itself.
     *
     * @throws \DomainException
     */
    public static function setStatus(App $app, int $id, string $status, int $byUserId, ?string $reason = null, string $via = ''): bool
    {
        $db = $app->db();
        $quote = self::get($db, $id) ?? throw new \DomainException(t('The quote does not exist.'));
        if ($status === $quote['status']) {
            return false;
        }
        if (!in_array($status, self::TRANSITIONS[$quote['status']] ?? [], true)) {
            throw new \DomainException($quote['status'] === 'draft' ? t('A draft is sent or finalised first.')
                : t('A quote that is %1$s cannot become %2$s.', mb_strtolower(t(self::STATUS_LABELS[$quote['status']] ?? $quote['status'])), mb_strtolower(t(self::STATUS_LABELS[$status] ?? $status))));
        }
        if ($status === 'declined' && !isset(Deals::LOST_REASONS[(string) $reason])) {
            throw new \DomainException(t('Choose why the quote was declined: %s.', implode(', ', array_keys(Deals::LOST_REASONS))));
        }
        $now = Clock::now();
        $decided = in_array($status, ['accepted', 'declined'], true);
        $db->update('quotes', ['status' => $status, 'decided_at' => $decided ? $now : null, 'decided_by' => $decided && $byUserId > 0 ? $byUserId : null,
            'decline_reason' => $status === 'declined' ? (string) $reason : null, 'updated_at' => $now,
            'history' => self::appended($quote['history'], ['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'status', 'from' => $quote['status'], 'to' => $status]
                + ($status === 'declined' ? ['note' => (string) $reason] : []) + ($via === 'customer' ? ['via' => 'customer'] : []))], ['id' => $id]); // 3.40: the customer's own decision
        \Kaleta\Core\ChangeLog::write($app, self::SECTION, 'status', $via === 'customer' ? t('Quote %1$s: %2$s by the customer', $quote['label'], t(self::STATUS_LABELS[$status]))
            : t('Quote %1$s: %2$s', $quote['label'], t(self::STATUS_LABELS[$status])));

        return true;
    }

    /* ---------- 3.40: the customer's decision, the first opening, the customer's price list, the follow-up ---------- */

    /** The longest reason a customer may give when declining on the quote page. */
    public const int CUSTOMER_REASON_MAX = 1000;

    /** The days "Remind me" offers after sending by default, and the most it takes. */
    public const int FOLLOW_UP_DAYS = 7;
    public const int FOLLOW_UP_MAX_DAYS = 90;

    /**
     * May the customer accept or decline this quote on its page now? The setting quote_customer_decision is on (off by
     * default, design A-12), the quote is sent and still valid (valid_until today or later), it has its frozen document and a
     * customer, and the customer has not decided on it before (one decision – a person who set it back to sent decides the
     * rest).
     *
     * @param array<string, mixed> $quote as get() returns it
     */
    public static function customerMayDecide(Settings $s, array $quote): bool
    {
        return $s->bool('quote_customer_decision') && $quote['status'] === 'sent' && $quote['snapshot'] !== null && $quote['anonymised_at'] === null
            && ($quote['valid_until'] === null || $quote['valid_until'] >= Clock::today()) && !self::customerDecided($quote['history']);
    }

    /**
     * The customer accepts or declines a sent quote on its page (Front\QuoteDecision – a POST bound to the link's token):
     * the status changes with the history entry "by the customer" (decided_by stays empty), a decline keeps the customer's
     * own words (customer_reason; the reason code is "other"), and the responsible person – or whoever sent it – gets a
     * bell that names the number only. The deal is never moved: the quote's page in the admin offers won or lost to a
     * person. Under the quote's row lock, so two clicks make one decision. False when the quote may not be decided now
     * (customerMayDecide) – nothing changes then.
     *
     * @param 'accepted'|'declined' $decision
     */
    public static function customerDecision(App $app, int $id, string $decision, string $reason = ''): bool
    {
        if (!in_array($decision, ['accepted', 'declined'], true)) {
            return false;
        }
        $db = $app->db();
        $s = $app->settings();
        $reason = trim(mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', str_replace("\r\n", "\n", $reason))), 0, self::CUSTOMER_REASON_MAX));

        return Language::runWith(Language::defaults($s), function () use ($app, $db, $s, $id, $decision, $reason): bool {
            $done = $db->transaction(function (Db $db) use ($app, $s, $id, $decision, $reason): ?array {
                $db->value('SELECT status FROM {quotes} WHERE id = ? FOR UPDATE', [$id]);
                $quote = self::get($db, $id);
                if ($quote === null || !self::customerMayDecide($s, $quote)) {
                    return null;
                }
                self::setStatus($app, $id, $decision, 0, $decision === 'declined' ? 'other' : null, 'customer');
                $db->update('quotes', ['customer_reason' => $decision === 'declined' && $reason !== '' ? $reason : null], ['id' => $id]);

                return $quote;
            });
            if ($done === null) {
                return false;
            }
            Events::record($db, 'quote.' . $decision, 'info', mb_substr(t($decision === 'accepted' ? 'Quote %s was accepted by the customer.' : 'Quote %s was declined by the customer.', $done['label']), 0, 255), ['quote' => $id]);
            foreach (array_unique(array_filter([$done['owner_id'] ?? $done['sent_by']])) as $person) {
                if (Quotes::mayOpen($db, $s, (int) $person)) {
                    Inbox::notify($app, (int) $person, $decision === 'accepted' ? 'quote_accepted' : 'quote_declined', 'quote:' . $id, null); // the number only – never the customer's words
                }
            }

            return true;
        }, 'admin-');
    }

    /**
     * 3.44 (N146): was the customer's e-mail last written by a Claude connection (the newest "customer_email" entry of the
     * history came over MCP)? A person's later change of the address clears it.
     *
     * @param array<string, mixed> $quote as get() returns it
     */
    public static function emailByClaude(array $quote): bool
    {
        foreach (array_reverse(is_array($quote['history'] ?? null) ? $quote['history'] : []) as $h) {
            if (is_array($h) && ($h['action'] ?? '') === 'customer_email') {
                return ($h['via'] ?? '') === 'mcp';
            }
        }

        return false;
    }

    /** Did the customer decide on this quote themself (an entry "by the customer"; with $status: the last change to it)? @param array<mixed> $history */
    public static function customerDecided(array $history, string $status = ''): bool
    {
        foreach (array_reverse($history) as $h) {
            if (!is_array($h) || ($h['action'] ?? '') !== 'status') {
                continue;
            }
            if ($status !== '' && ($h['to'] ?? '') === $status) {
                return ($h['via'] ?? '') === 'customer';
            }
            if ($status === '' && ($h['via'] ?? '') === 'customer') {
                return true;
            }
        }

        return false;
    }

    /**
     * The first opening of the customer's link (3.40, setting quote_track_opened – off by default): only the time, never an
     * address or a browser, and only the first one. Returns whether it was recorded now.
     */
    public static function markOpened(Db $db, int $id): bool
    {
        return $db->run('UPDATE {quotes} SET first_opened_at = ? WHERE id = ? AND first_opened_at IS NULL AND status <> \'draft\'', [Clock::now(), $id])->rowCount() > 0;
    }

    /** Forgets every recorded first opening (the setting quote_track_opened was switched off). Returns how many. */
    public static function forgetOpened(Db $db): int
    {
        try {
            return $db->run('UPDATE {quotes} SET first_opened_at = NULL WHERE first_opened_at IS NOT NULL')->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0128
        }
    }

    /**
     * The price list a new quote for this customer takes (3.40): the contact's own, else its company's; null = none set
     * (or archived, or gone) – then the default list.
     *
     * @return array{id: int, name: string, currency: string, is_default: bool, archived_at: ?string}|null
     */
    public static function customerList(Db $db, ?int $contactId, ?int $companyId): ?array
    {
        try {
            $ids = [$contactId !== null && $contactId > 0 ? $db->value('SELECT price_list_id FROM {contacts} WHERE id = ?', [$contactId]) : null,
                $companyId !== null && $companyId > 0 ? $db->value('SELECT price_list_id FROM {companies} WHERE id = ?', [$companyId]) : null];
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0128
        }
        foreach ($ids as $listId) {
            $list = $listId !== null ? Prices::priceList($db, (int) $listId) : null;
            if ($list !== null && $list['archived_at'] === null) {
                return $list;
            }
        }

        return null;
    }

    /** The price list chosen on a contact or company (its id, or null for none). @param 'contact'|'company' $kind */
    public static function customerListId(Db $db, string $kind, int $id): ?int
    {
        try {
            $value = $db->value('SELECT price_list_id FROM {' . ($kind === 'company' ? 'companies' : 'contacts') . '} WHERE id = ?', [$id]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0128
        }

        return $value === null ? null : (int) $value;
    }

    /**
     * Chooses the price list of a contact or company (0 = none – the company's or the default list). Needs Quotes and
     * Customers ($byUserId > 0). The change log names the customer by number, never a price.
     *
     * @param 'contact'|'company' $kind
     * @throws \DomainException
     */
    public static function setCustomerList(App $app, string $kind, int $id, int $listId, int $byUserId): bool
    {
        $db = $app->db();
        if ($byUserId > 0 && (!self::mayOpen($db, $app->settings(), $byUserId) || !Handover::mayOpen($db, $app->settings(), $byUserId, Contacts::SECTION))) {
            throw new \DomainException(t('Only a user who may open Quotes and Customers chooses the price list of a customer.'));
        }
        $exists = $kind === 'company' ? Contacts::findCompany($db, $id) !== null : (($c = Contacts::find($db, $id)) !== null && $c['anonymised_at'] === null);
        if (!$exists) {
            throw new \DomainException(t($kind === 'company' ? 'The company does not exist.' : 'The contact does not exist.'));
        }
        if ($listId > 0) {
            $list = Prices::priceList($db, $listId) ?? throw new \DomainException(t('The price list does not exist.'));
            if ($list['archived_at'] !== null) {
                throw new \DomainException(t('The price list %s is archived – choose another one.', $list['name']));
            }
        }
        if (self::customerListId($db, $kind, $id) === ($listId > 0 ? $listId : null)) {
            return false;
        }
        $db->update($kind === 'company' ? 'companies' : 'contacts', ['price_list_id' => $listId > 0 ? $listId : null], ['id' => $id]);
        \Kaleta\Core\ChangeLog::write($app, self::SECTION, 'customer_list', $kind === 'company' ? t('Price list of the company #%s changed', (string) $id) : t('Price list of the customer #%s changed', (string) $id));

        return true;
    }

    /**
     * A follow-up task after sending (3.40, "Remind me in N days"): a task about the quote (target quote:<id>) due in $days
     * days at Tasks::DEFAULT_TIME, for the responsible person – or the one who asks, when nobody is responsible (or the
     * responsible person can no longer open Quotes). The title names the number only. Returns the task's id.
     *
     * @throws \DomainException
     */
    public static function followUp(App $app, int $quoteId, int $days, int $byUserId): int
    {
        $db = $app->db();
        $quote = self::get($db, $quoteId) ?? throw new \DomainException(t('The quote does not exist.'));
        if ($quote['status'] === 'draft') {
            throw new \DomainException(t('A follow-up comes after the quote is sent.'));
        }
        $days = max(1, min(self::FOLLOW_UP_MAX_DAYS, $days));
        $owner = $quote['owner_id'] !== null && self::mayOpen($db, $app->settings(), $quote['owner_id']) ? $quote['owner_id'] : $byUserId;

        return Tasks::create($app, ['title' => t('Follow up on quote %s', $quote['label']), 'target' => 'quote:' . $quoteId, 'assignee_id' => $owner,
            'due_at' => Clock::day('+' . $days . ' days') . ' ' . Tasks::DEFAULT_TIME], $byUserId);
    }

    /**
     * A revision: a new draft with the same customer, texts and lines (their prices as they were), revision_of = the
     * original; it gets the original's number with /2, /3… when sent, and then the original expires ("replaced").
     *
     * @throws \DomainException
     */
    public static function revise(App $app, int $id, int $byUserId): int
    {
        $db = $app->db();
        $quote = self::get($db, $id) ?? throw new \DomainException(t('The quote does not exist.'));
        if ($quote['status'] === 'draft' || $quote['number'] === null) {
            throw new \DomainException(t('Only a sent quote can be revised – change the draft itself.'));
        }
        $now = Clock::now();
        $row = $db->one('SELECT * FROM {quotes} WHERE id = ?', [$id]) ?? [];
        unset($row['id']);
        $newId = $db->transaction(function (Db $db) use ($row, $quote, $byUserId, $now, $id): int {
            $newId = $db->insert('quotes', ['number' => null, 'revision_of' => $id, 'status' => 'draft', 'snapshot' => null, 'token_hash' => null, 'token_expires_at' => null,
                'sent_at' => null, 'sent_by' => null, 'sent_mail' => null, 'decided_at' => null, 'decided_by' => null, 'decline_reason' => null, 'anonymised_at' => null,
                'first_opened_at' => null, 'customer_reason' => null, // 3.40
                'created_by' => $byUserId > 0 ? $byUserId : null, 'created_at' => $now, 'updated_at' => $now,
                'history' => (string) json_encode([['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'revision', 'note' => $quote['number']]])] + $row);
            foreach ($db->all('SELECT * FROM {quote_lines} WHERE quote_id = ? ORDER BY position, id', [$id]) as $line) {
                unset($line['id']);
                $db->insert('quote_lines', ['quote_id' => $newId] + $line);
            }

            return $newId;
        });
        \Kaleta\Core\ChangeLog::write($app, self::SECTION, 'revise', t('Quote %s revised', (string) $quote['number']));

        return $newId;
    }

    /**
     * Deletes a quote with its lines: a draft by its author or an administrator, a numbered quote by an administrator only.
     *
     * @throws \DomainException
     */
    public static function delete(App $app, int $id, int $byUserId, bool $byAdmin): void
    {
        $db = $app->db();
        $quote = self::get($db, $id) ?? throw new \DomainException(t('The quote does not exist.'));
        if (!$byAdmin && ($quote['number'] !== null || $quote['status'] !== 'draft' || $quote['created_by'] !== $byUserId)) {
            throw new \DomainException(t('Only its author or an administrator deletes a draft; a numbered quote only an administrator.'));
        }
        $db->transaction(function (Db $db) use ($id): void {
            QuoteDelivery::withdrawMail($db, [$id]); // 3.44 (N145): its e-mail still waiting in the queue goes with it
            Tasks::forgetTargets($db, ['quote:' . $id]); // 3.40 review M4: the tasks about it go with it
            $db->run('DELETE FROM {quote_lines} WHERE quote_id = ?', [$id]);
            $db->run('DELETE FROM {quotes} WHERE id = ?', [$id]);
        });
        \Kaleta\Core\ChangeLog::write($app, self::SECTION, 'delete', t('Quote %s deleted', $quote['label']));
    }

    /**
     * Sets the linked deal's value and currency to the quote's NET total (A-10) – called by the send dialog when its box is
     * ticked (by default per quote_set_deal_value). A quote without a deal, or a deal that already has this value, changes
     * nothing (false). A deal with several quotes takes the value of the one sent last.
     *
     * @throws \DomainException
     */
    public static function setDealValue(App $app, int $quoteId, int $byUserId): bool
    {
        $db = $app->db();
        $quote = self::get($db, $quoteId) ?? throw new \DomainException(t('The quote does not exist.'));
        if ($quote['deal_id'] === null) {
            return false;
        }
        $deal = Deals::get($db, $quote['deal_id']);
        if ($deal === null) {
            return false;
        }
        $net = (string) ($quote['snapshot']['totals']['net'] ?? $quote['totals']['net']); // 3.38 review M2: the frozen document's total once sent
        if ($deal['value'] !== null && Money::fromDecimal($deal['value']) === Money::fromDecimal($net) && $deal['currency'] === $quote['currency']) {
            return false;
        }
        Deals::save($app, $deal['id'], ['value' => $net, 'currency' => $quote['currency']], $byUserId);

        return true;
    }

    /**
     * 3.44 (CPO 2): the quotes of one list of My work (Core\MyWork, MCP my_work) – the key ix_quotes_owner (owner_id, status) serves all three:
     *  - quotes_drafts: my drafts, and the drafts Kaleta prepared from an enquiry with nobody responsible (reason prepared /
     *    unowned) – the newest change first;
     *  - quotes_waiting: my sent quotes with no decision QUOTE_WAITING_DAYS after sending whose validity does not end soon;
     *  - quotes_expiring: my sent quotes whose validity ends within QUOTE_EXPIRING_DAYS (the expiry job closes them after).
     * Rows name the quote by its number (a draft by #id), its title and the customer – never an amount.
     *
     * @return list<array<string, mixed>>
     */
    public static function forMyWork(Db $db, string $list, int $userId, int $limit): array
    {
        $soon = Clock::day('+' . (MyWork::QUOTE_EXPIRING_DAYS - 1) . ' days');
        [$where, $params, $order] = match ($list) {
            'quotes_drafts' => ["status = 'draft' AND (owner_id = ? OR (owner_id IS NULL AND created_by IS NULL))", [$userId], 'updated_at DESC, id DESC'],
            'quotes_waiting' => ["owner_id = ? AND status = 'sent' AND sent_at <= ? AND (valid_until IS NULL OR valid_until > ?)", [$userId, Clock::at('-' . MyWork::QUOTE_WAITING_DAYS . ' days'), $soon], 'sent_at, id'],
            default => ["owner_id = ? AND status = 'sent' AND valid_until IS NOT NULL AND valid_until >= ? AND valid_until <= ?", [$userId, Clock::today(), $soon], 'valid_until, id'],
        };
        $rows = $db->all('SELECT id, number, title, customer_name, customer_company, owner_id, created_by, valid_until, sent_at, updated_at FROM {quotes} WHERE ' . $where
            . ' ORDER BY ' . $order . ' LIMIT ' . max(1, min(200, $limit)), $params);

        return array_map(fn (array $q): array => ['kind' => 'quote', 'id' => (int) $q['id'], 'title' => self::label($q['number'], (int) $q['id']), 'about' => (string) $q['title'],
            'name' => trim((string) $q['customer_company']) !== '' ? (string) $q['customer_company'] : (string) $q['customer_name'],
            'status' => $list === 'quotes_drafts' ? 'draft' : 'sent',
            'reasons' => match ($list) {
                'quotes_drafts' => array_values(array_filter([$q['created_by'] === null ? 'prepared' : '', $q['owner_id'] === null ? 'unowned' : ''])),
                'quotes_waiting' => ['no_answer'],
                default => ['expires'],
            },
            'valid_until' => $q['valid_until'] === null ? null : (string) $q['valid_until'], 'sent_at' => $q['sent_at'] === null ? null : (string) $q['sent_at'],
            'date' => (string) ($list === 'quotes_drafts' ? $q['updated_at'] : ($q['sent_at'] ?? $q['updated_at'])), 'url' => self::adminPath((int) $q['id'])], $rows);
    }

    /* ---------- the customer block ---------- */

    /**
     * The customer block of a contact and/or a company: name, company, reg_no, vat_id, e-mail ('' where unknown; contacts
     * have no postal address). An anonymised contact gives nothing.
     *
     * @return array{name: string, company: string, address: string, reg_no: string, vat_id: string, email: string}
     */
    public static function customerFrom(Db $db, ?int $contactId, ?int $companyId): array
    {
        $out = array_fill_keys(array_keys(self::CUSTOMER), '');
        $contact = $contactId !== null && $contactId > 0 ? Contacts::find($db, $contactId) : null;
        if ($contact !== null && $contact['anonymised_at'] === null) {
            $out['name'] = (string) $contact['name'];
            $out['email'] = (string) $contact['email'];
            $companyId ??= $contact['company_id'] !== null ? (int) $contact['company_id'] : null;
        }
        $company = $companyId !== null && $companyId > 0 ? Contacts::findCompany($db, $companyId) : null;
        if ($company !== null) {
            $out['company'] = (string) $company['name'];
            $out['reg_no'] = $company['registration_no'];
            $out['vat_id'] = $company['vat_id'];
        }

        return $out;
    }

    /* ---------- the document ---------- */

    /**
     * The frozen content of the document (the snapshot stored when sent): the supplier block from Business details, the
     * customer block, the lines, the totals per rate, the texts, the language, the VAT mode and the dates. A later change
     * of prices, product names or company details never rewrites it.
     *
     * @param array<string, mixed> $quote as get() returns it
     * @return array<string, mixed>
     */
    public static function snapshot(App $app, array $quote): array
    {
        $s = $app->settings();
        $supplier = [];
        foreach (['name' => 'company_name', 'reg_no' => 'company_id', 'vat_id' => 'company_vat_id', 'register' => 'company_register', 'street' => 'company_street',
            'city' => 'company_city', 'postcode' => 'company_postcode', 'country' => 'company_country', 'phone' => 'company_phone', 'email' => 'company_email'] as $key => $setting) {
            $supplier[$key] = $s->get($setting);
        }
        if ($supplier['name'] === '') {
            $supplier['name'] = $s->get('site_name');
        }

        return ['v' => 1, 'number' => $quote['number'], 'title' => $quote['title'], 'currency' => $quote['currency'], 'language' => $quote['language'], 'vat_mode' => $quote['vat_mode'],
            'vat_payer' => $s->bool('quote_vat_payer'), 'valid_until' => $quote['valid_until'], 'discount_percent' => $quote['discount_percent'], 'supplier' => $supplier,
            'customer' => $quote['customer'], 'intro' => $quote['intro'], 'terms' => $quote['terms'], 'footer' => QuoteDelivery::defaultText($s, 'footer', (string) $quote['language']),
            'lines' => array_map(fn (array $l): array => array_intersect_key($l, array_flip(['code', 'name', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'vat_rate', 'line_net'])), $quote['lines']),
            'totals' => $quote['totals']];
    }

    /* ---------- helpers ---------- */

    /**
     * The enquiry, deal, contact and company of a new or changed quote, with the permission checks of save_deal, and what
     * fills the customer block (null = nothing new), the default title and – for a new quote of an enquiry – its lines.
     *
     * @param array<string, mixed>|null $quote
     * @param array<string, mixed> $fields
     * @return array{data: array<string, mixed>, fill: ?array<string, string>, title: ?string, lines: ?list<array<string, mixed>>}
     */
    private static function sources(App $app, ?array $quote, array $fields, int $byUserId): array
    {
        $db = $app->db();
        $s = $app->settings();
        $value = fn (string $key): int => (int) (is_scalar($fields[$key] ?? null) ? $fields[$key] : 0);
        $changes = fn (string $key): bool => array_key_exists($key, $fields) && $value($key) !== (int) ($quote[$key] ?? 0);
        $may = fn (string $section): bool => $byUserId <= 0 || Handover::mayOpen($db, $s, $byUserId, $section);
        $data = [];
        $fill = null;
        $title = null;
        $lines = null;
        $enquiry = null;
        $contact = $changes('contact_id') ? $value('contact_id') : null;
        $company = $changes('company_id') ? $value('company_id') : null;
        if ($changes('enquiry_id')) {
            if (!$may('enquiries')) {
                throw new \DomainException(t('Only a user who may open Enquiries can make a quote from an enquiry.'));
            }
            $enquiry = $value('enquiry_id') > 0 ? $db->one('SELECT idp, tema, email, contact_id FROM {poptavky} WHERE idp = ? AND ' . EnquiryVisibility::visible($db, $s, false), [$value('enquiry_id')]) : null;
            if ($value('enquiry_id') > 0 && $enquiry === null) {
                throw new \DomainException(t('The enquiry does not exist.'));
            }
            $data['enquiry_id'] = $enquiry !== null ? (int) $enquiry['idp'] : null;
            if ($enquiry !== null && $quote === null) {
                $contact ??= $enquiry['contact_id'] !== null ? (int) $enquiry['contact_id'] : null;
                $title = (string) $enquiry['tema'];
                if (!array_key_exists('deal_id', $fields) && $may(Deals::SECTION)) {
                    $open = Deals::openForEnquiry($db, (int) $enquiry['idp']);
                    if ($open !== null) {
                        $data['deal_id'] = $open;
                    }
                }
                if (!array_key_exists('lines', $fields)) {
                    $lines = QuoteIntake::linesFromEnquiry($app, (int) $enquiry['idp'])['lines'];
                }
                if ($contact === null && (string) $enquiry['email'] !== '' && !array_key_exists('customer', $fields)) {
                    $fill = array_fill_keys(array_keys(self::CUSTOMER), '');
                    $fill['email'] = (string) $enquiry['email'];
                }
            }
        }
        if ($changes('deal_id')) {
            if (!$may(Deals::SECTION)) {
                throw new \DomainException(t('Only a user who may open the Pipeline can link a quote to a deal.'));
            }
            $deal = $value('deal_id') > 0 ? Deals::get($db, $value('deal_id')) : null;
            if ($value('deal_id') > 0 && $deal === null) {
                throw new \DomainException(t('The deal does not exist.'));
            }
            $data['deal_id'] = $deal['id'] ?? null;
            if ($deal !== null && $quote === null) {
                $contact ??= $deal['contact_id'];
                $company ??= $deal['company_id'];
                $title ??= $deal['title'];
            }
        }
        if ($contact !== null || $company !== null) {
            if (($changes('contact_id') || $changes('company_id')) && !$may(Contacts::SECTION)) {
                throw new \DomainException(t('Only a user who may open Customers can choose the customer of a quote.'));
            }
            if ($contact !== null) {
                $found = $contact > 0 ? Contacts::find($db, $contact) : null;
                if ($contact > 0 && ($found === null || $found['anonymised_at'] !== null)) {
                    throw new \DomainException(t('The contact does not exist.'));
                }
                $data['contact_id'] = $found['id'] ?? null;
                if ($company === null && $found !== null && $found['company_id'] !== null) {
                    $company = (int) $found['company_id'];
                }
            }
            if ($company !== null) {
                if ($company > 0 && Contacts::findCompany($db, $company) === null) {
                    throw new \DomainException(t('The company does not exist.'));
                }
                $data['company_id'] = $company > 0 ? $company : null;
            }
            if (!array_key_exists('customer', $fields) || $quote === null) {
                $fill = self::customerFrom($db, $data['contact_id'] ?? ($quote['contact_id'] ?? null), $data['company_id'] ?? ($quote['company_id'] ?? null));
                if ($fill['email'] === '' && $enquiry !== null) {
                    $fill['email'] = (string) $enquiry['email'];
                }
            }
        }

        return ['data' => $data, 'fill' => $fill, 'title' => $title, 'lines' => $lines];
    }

    /**
     * The validity: valid_until (YYYY-MM-DD, '' = none) or valid_days (from today); $default = quote_valid_days.
     *
     * @param array<string, mixed> $fields
     * @throws \DomainException
     */
    private static function validUntil(array $fields, Settings $s, ?string $default): ?string
    {
        if ($default === null && array_key_exists('valid_until', $fields)) {
            $text = is_scalar($fields['valid_until']) ? trim((string) $fields['valid_until']) : '';
            if ($text === '') {
                return null;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
            if ($date === false || $date->format('Y-m-d') !== $text) {
                throw new \DomainException(t('Write the validity as a date, e.g. %s.', Clock::day('+30 days')));
            }

            return $text;
        }
        $days = $default === null ? (int) (is_scalar($fields['valid_days'] ?? null) ? $fields['valid_days'] : 0) : $s->int('quote_valid_days');
        if ($days <= 0) {
            return $default === null ? throw new \DomainException(t('The validity must be at least one day.')) : null;
        }

        return Clock::day('+' . min(3650, $days) . ' days');
    }

    /**
     * The history with one more entry, at most HISTORY_MAX (the oldest go), as JSON.
     *
     * @param list<array<string, mixed>> $history
     * @param array<string, mixed> $entry
     */
    private static function appended(array $history, array $entry): string
    {
        $history[] = $entry;

        return (string) json_encode(array_slice($history, -self::HISTORY_MAX), JSON_UNESCAPED_UNICODE);
    }

    /* ---------- forwards (3.49): the members that moved to QuoteLines, QuoteNumbers and QuoteIntake keep their old names
       here, so callers, migrations, index.php and the tests are unchanged ---------- */

    public const int NAME_MAX = QuoteLines::NAME_MAX;
    public const int DESCRIPTION_MAX = QuoteLines::DESCRIPTION_MAX;
    public const int NUMBER_MAX = QuoteNumbers::NUMBER_MAX;
    public const string NUMBER_FORMAT = QuoteNumbers::NUMBER_FORMAT;
    public const int LOOKUPS_MAX = QuoteIntake::LOOKUPS_MAX;
    public const int PARTS_MAX = QuoteIntake::PARTS_MAX;
    public const string AUTO_DRAFT_SETTING = QuoteIntake::AUTO_DRAFT_SETTING;
    public const int AUTO_DRAFT_DAILY_MAX = QuoteIntake::AUTO_DRAFT_DAILY_MAX;

    /**
     * @see QuoteLines::computeTotals()
     *
     * @param list<array{unit_price: ?string, quantity: string, discount_percent: string, vat_rate: string}> $lines
     * @return array{net: string, vat: string, gross: string, rates: list<array{rate: string, net: string, vat: string}>, unpriced: int}
     */
    public static function computeTotals(array $lines, string $discountPercent, string $vatMode): array
    {
        return QuoteLines::computeTotals($lines, $discountPercent, $vatMode);
    }

    /** @see QuoteLines::impliedDiscount() */
    public static function impliedDiscount(?int $price, ?int $list): int
    {
        return QuoteLines::impliedDiscount($price, $list);
    }

    /** @see QuoteLines::combinedDiscount() */
    public static function combinedDiscount(int $linePoints, int $quotePoints): int
    {
        return QuoteLines::combinedDiscount($linePoints, $quotePoints);
    }

    /** @see QuoteNumbers::formatRefusal() */
    public static function formatRefusal(string $format): ?string
    {
        return QuoteNumbers::formatRefusal($format);
    }

    /** @see QuoteNumbers::formatNumber() */
    public static function formatNumber(string $format, int $year, int $counter): string
    {
        return QuoteNumbers::formatNumber($format, $year, $counter);
    }

    /** @see QuoteNumbers::numberFormat() */
    public static function numberFormat(Settings $s): string
    {
        return QuoteNumbers::numberFormat($s);
    }

    /** @see QuoteNumbers::assignNumber() */
    public static function assignNumber(Db $db, Settings $s, ?int $revisionOf = null): string
    {
        return QuoteNumbers::assignNumber($db, $s, $revisionOf);
    }

    /** @see QuoteNumbers::nextNumber() */
    public static function nextNumber(Db $db, Settings $s): string
    {
        return QuoteNumbers::nextNumber($db, $s);
    }

    /** @see QuoteNumbers::raiseCounter() */
    public static function raiseCounter(Db $db, int $next): bool
    {
        return QuoteNumbers::raiseCounter($db, $next);
    }

    /** @see QuoteIntake::prepareLater() */
    public static function prepareLater(App $app, int $enquiryId): void
    {
        QuoteIntake::prepareLater($app, $enquiryId);
    }

    /** @see QuoteIntake::afterResponse() – index.php calls it under this name */
    public static function afterResponse(App $app): void
    {
        QuoteIntake::afterResponse($app);
    }

    /** @see QuoteIntake::preparing() */
    public static function preparing(): int
    {
        return QuoteIntake::preparing();
    }

    /** @see QuoteIntake::prepareDraft() */
    public static function prepareDraft(App $app, int $enquiryId): ?int
    {
        return QuoteIntake::prepareDraft($app, $enquiryId);
    }

    /** @see QuoteIntake::dropPreparedDraft() */
    public static function dropPreparedDraft(Db $db, int $enquiryId): int
    {
        return QuoteIntake::dropPreparedDraft($db, $enquiryId);
    }

    /**
     * @see QuoteIntake::parseBasket()
     *
     * @return list<array{quantity: int, name: string, variant: string, code: string}>
     */
    public static function parseBasket(string $text): array
    {
        return QuoteIntake::parseBasket($text);
    }

    /**
     * @see QuoteIntake::linesFromEnquiry()
     *
     * @return array{lines: list<array<string, mixed>>, matched: int, unmatched: int}
     */
    public static function linesFromEnquiry(App $app, int $enquiryId): array
    {
        return QuoteIntake::linesFromEnquiry($app, $enquiryId);
    }
}
