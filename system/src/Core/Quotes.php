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
    public const int NAME_MAX = 250;
    public const int DESCRIPTION_MAX = 1000;
    public const int NUMBER_MAX = 24;

    /** The default numbering (design A-7). */
    public const string NUMBER_FORMAT = '{YYYY}-{NNNN}';

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
        $totals = self::computeTotals($lines, (string) $q['discount_percent'], (string) $q['vat_mode']);
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
            'anonymised_at' => $q['anonymised_at'] === null ? null : (string) $q['anonymised_at']];
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
                'line_net' => Money::toDecimal((int) Money::fromDecimal($l['line_net'])), 'manual' => self::isManual($price, $list)];
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

    /* ---------- totals ---------- */

    /**
     * The totals of lines as DECIMAL texts: net, vat, gross, per rate (rate, net, vat) and how many lines have no price.
     * Pure – the quote screens show what the server stores.
     *
     * @param list<array{unit_price: ?string, quantity: string, discount_percent: string, vat_rate: string}> $lines
     * @return array{net: string, vat: string, gross: string, rates: list<array{rate: string, net: string, vat: string}>, unpriced: int}
     */
    public static function computeTotals(array $lines, string $discountPercent, string $vatMode): array
    {
        $money = Money::totals(array_map(fn (array $l): array => [
            'net' => $l['unit_price'] === null ? null : Money::lineNet((int) Money::fromDecimal($l['unit_price']), Money::quantity($l['quantity']), Money::percent($l['discount_percent'])),
            'rate' => Money::percent($l['vat_rate'])], $lines), Money::percent($discountPercent), in_array($vatMode, Money::VAT_MODES, true) ? $vatMode : 'standard');

        return ['net' => Money::toDecimal($money['net']), 'vat' => Money::toDecimal($money['vat']), 'gross' => Money::toDecimal($money['gross']),
            'rates' => array_map(fn (array $r): array => ['rate' => Money::percentText($r['rate']), 'net' => Money::toDecimal($r['net']), 'vat' => Money::toDecimal($r['vat'])], $money['rates']),
            'unpriced' => $money['unpriced']];
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

        // the price list and its currency
        if ($quote === null || $has('price_list_id')) {
            $list = $int('price_list_id') > 0 ? Prices::priceList($db, $int('price_list_id')) : Prices::defaultList($db, $s);
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
            $data['price_list_id'] = $list['id'];
            $data['currency'] = $list['currency'];
        } else {
            $list = Prices::priceList($db, $quote['price_list_id']) ?? Prices::defaultList($db, $s);
        }

        // where it came from: the enquiry, the deal; the customer
        $sources = self::sources($app, $quote, $fields, $byUserId);
        $data += $sources['data'];
        if ($has('customer') || $sources['fill'] !== null) {
            $given = is_array($fields['customer'] ?? null) ? $fields['customer'] : [];
            $base = $sources['fill'] ?? ($quote['customer'] ?? array_fill_keys(array_keys(self::CUSTOMER), ''));
            foreach (self::CUSTOMER as $key => $max) {
                $value = array_key_exists($key, $given) ? self::line($given[$key], $max) : self::line($base[$key] ?? '', $max);
                if ($key === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \DomainException(t('The customer’s e-mail is not a valid address.'));
                }
                $data['customer_' . $key] = $value;
            }
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
            $data['title'] = self::line($fields['title'] ?? ($sources['title'] ?? ''), self::TITLE_MAX);
        }
        if ($quote === null || $has('language')) {
            $data['language'] = Language::column($s, is_string($fields['language'] ?? null) ? $fields['language'] : '');
        }
        foreach (['intro', 'terms'] as $key) {
            if ($quote === null || $has($key)) {
                // 3.38 C: the default text in the quote's language (QuoteDelivery::defaultText)
                $data[$key] = self::text($has($key) ? $fields[$key] : QuoteDelivery::defaultText($s, $key, (string) ($data['language'] ?? $quote['language'] ?? '')), self::TEXT_MAX);
            }
        }
        if ($has('valid_until') || $has('valid_days') || $quote === null) {
            $data['valid_until'] = self::validUntil($fields, $s, $has('valid_until') || $has('valid_days') ? null : 'default');
        }
        if ($quote === null || $has('discount_percent')) {
            $discount = Money::percent($fields['discount_percent'] ?? null);
            self::checkDiscount($discount, $s, $fromClaude);
            $data['discount_percent'] = Money::percentText($discount);
        }
        if ($quote === null || $has('vat_mode')) {
            $mode = is_string($fields['vat_mode'] ?? null) && $fields['vat_mode'] !== '' ? $fields['vat_mode'] : ($s->bool('quote_vat_payer') ? 'standard' : 'none');
            if (!in_array($mode, Money::VAT_MODES, true)) {
                throw new \DomainException(t('The VAT mode must be one of: %s.', implode(', ', Money::VAT_MODES)));
            }
            $data['vat_mode'] = $mode;
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
                $lines[] = self::prepareLine($db, $s, $list, $input, $stored[(int) ($input['id'] ?? 0)] ?? null, $fromClaude, $warnings, $i + 1);
            }
        }
        $current = $lines ?? ($quote['lines'] ?? []);
        if ($fromClaude && ($lines !== null || isset($data['discount_percent']))) {
            // 3.38 review L4: Claude's cap is on what the customer gets – a line's discount and the whole-quote discount together
            $quoteDiscount = Money::percent($data['discount_percent'] ?? $quote['discount_percent'] ?? null);
            foreach ($current as $n => $l) {
                self::checkDiscount(self::combinedDiscount(Money::percent($l['discount_percent']), $quoteDiscount), $s, true, $n + 1);
            }
        }
        $totals = self::computeTotals($current, (string) ($data['discount_percent'] ?? $quote['discount_percent'] ?? '0.00'), (string) ($data['vat_mode'] ?? $quote['vat_mode'] ?? 'standard'));
        $data['total_net'] = $totals['net'];
        $data['total_vat'] = $totals['vat'];
        $data['total_gross'] = $totals['gross'];
        $data['manual_prices'] = array_filter($current, fn (array $l): bool => self::isManual(Money::fromDecimal($l['unit_price']), Money::fromDecimal($l['list_price'] ?? null))) !== [] ? 1 : 0;
        if ($totals['unpriced'] > 0) {
            $warnings[] = t('%d lines have no price yet – the quote cannot be sent until a person fills them.', $totals['unpriced']);
        }
        if (($data['vat_mode'] ?? $quote['vat_mode'] ?? '') === 'standard' && Prices::defaultVatRate($s) === null && array_filter($current, fn (array $l): bool => Money::percent($l['vat_rate']) === 0) !== []) {
            $warnings[] = t('The default VAT rate is not set (Quotes → Settings) – lines without their own rate have 0 %.');
        }

        $now = Clock::now();
        $id = $db->transaction(function (Db $db) use ($quote, $data, $lines, $byUserId, $now): int {
            // 3.38 review M2: the status is checked again under the row lock – a save that overlaps Send never changes a numbered quote
            if ($quote !== null && $db->value('SELECT status FROM {quotes} WHERE id = ? FOR UPDATE', [$quote['id']]) !== 'draft') {
                throw new \DomainException(t('A sent quote is frozen – revise it to make a new draft.'));
            }
            if ($quote === null) {
                $id = $db->insert('quotes', $data + ['status' => 'draft', 'number' => null, 'revision_of' => null, 'created_by' => $byUserId > 0 ? $byUserId : null, 'created_at' => $now, 'updated_at' => $now,
                    'history' => (string) json_encode([['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'created']])]);
            } else {
                $id = $quote['id'];
                $db->update('quotes', $data + ['updated_at' => $now], ['id' => $id]);
            }
            if ($lines !== null) {
                $db->run('DELETE FROM {quote_lines} WHERE quote_id = ?', [$id]);
                foreach ($lines as $position => $line) {
                    unset($line['id'], $line['manual']);
                    $db->insert('quote_lines', ['quote_id' => $id, 'position' => $position + 1] + $line);
                }
            }

            return $id;
        });
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, $quote === null ? 'create' : 'save', t('Quote %s', self::label($quote['number'] ?? null, $id)));

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
            $refusal = self::sendRefusal($quote, $action === 'sent');
            if ($refusal !== null) {
                throw new \DomainException($refusal);
            }
            $snapshot = self::snapshot($app, $quote);
            $number = $locked['number'] !== null ? (string) $locked['number'] : self::assignNumber($db, $app->settings(), $quote['revision_of']);
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
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, $action === 'sent' ? 'send' : 'finalise', ($action === 'sent' ? t('Quote %s sent', $number) : t('Quote %s finalised', $number)));

        return self::get($db, $id); // PHPStan remembers the get() above as not null; the row is never deleted in between
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
    public static function setStatus(App $app, int $id, string $status, int $byUserId, ?string $reason = null): bool
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
                + ($status === 'declined' ? ['note' => (string) $reason] : []))], ['id' => $id]);
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'status', t('Quote %1$s: %2$s', $quote['label'], t(self::STATUS_LABELS[$status])));

        return true;
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
                'created_by' => $byUserId > 0 ? $byUserId : null, 'created_at' => $now, 'updated_at' => $now,
                'history' => (string) json_encode([['at' => $now, 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'revision', 'note' => $quote['number']]])] + $row);
            foreach ($db->all('SELECT * FROM {quote_lines} WHERE quote_id = ? ORDER BY position, id', [$id]) as $line) {
                unset($line['id']);
                $db->insert('quote_lines', ['quote_id' => $newId] + $line);
            }

            return $newId;
        });
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'revise', t('Quote %s revised', (string) $quote['number']));

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
            $db->run('DELETE FROM {quote_lines} WHERE quote_id = ?', [$id]);
            $db->run('DELETE FROM {quotes} WHERE id = ?', [$id]);
        });
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'delete', t('Quote %s deleted', $quote['label']));
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

    /* ---------- numbering ---------- */

    /**
     * Why a number format cannot be used (translated), or null: it needs the year ({YYYY} or {YY}) and one counter {N…}
     * (1–8 N), may have letters, digits, spaces and - / . _ around them, and a number made from it fits 24 characters.
     */
    public static function formatRefusal(string $format): ?string
    {
        $format = trim($format);
        if (preg_match_all('/\{N{1,8}\}/', $format) !== 1 || preg_match('/\{(YYYY|YY)\}/', $format) !== 1) {
            return t('The number format needs the year ({YYYY} or {YY}) and one counter ({NNNN}).');
        }
        $rest = (string) preg_replace('/\{(YYYY|YY|N{1,8})\}/', '', $format);
        if (preg_match('/^[A-Za-z0-9 \/._-]*$/D', $rest) !== 1) {
            return t('Around the year and the counter the number format may have letters, digits, spaces and - / . _ only.');
        }
        if (mb_strlen(self::formatNumber($format, 2026, 99999)) > self::NUMBER_MAX - 3) {
            return t('A number made from this format would be longer than %d characters.', self::NUMBER_MAX - 3);
        }

        return null;
    }

    /** The number of a quote from the format: {YYYY} 2026, {YY} 26, {NNNN} the counter padded to four digits (pure). */
    public static function formatNumber(string $format, int $year, int $counter): string
    {
        $format = trim($format) !== '' ? trim($format) : self::NUMBER_FORMAT;

        return (string) preg_replace_callback('/\{(YYYY|YY|N{1,8})\}/', fn (array $m): string => match ($m[1]) {
            'YYYY' => (string) $year,
            'YY' => substr((string) $year, -2),
            default => str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT),
        }, $format);
    }

    /** The format of the settings, or the default when it cannot be used. */
    public static function numberFormat(Settings $s): string
    {
        $format = trim($s->get('quote_number_format'));

        return $format !== '' && self::formatRefusal($format) === null ? $format : self::NUMBER_FORMAT;
    }

    /**
     * The next number of a quote; call it inside a transaction (markSent). A revision: its original's number with the
     * next free /2, /3…; otherwise this year's counter + 1 (taken with FOR UPDATE), skipping a number that already exists.
     */
    public static function assignNumber(Db $db, Settings $s, ?int $revisionOf = null): string
    {
        if ($revisionOf !== null) {
            $base = (string) ($db->value('SELECT number FROM {quotes} WHERE id = ?', [$revisionOf]) ?? '');
            $base = (string) preg_replace('~/\d+$~', '', $base);
            if ($base !== '') {
                for ($n = 2; $n < 1000; $n++) {
                    $candidate = mb_substr($base, 0, self::NUMBER_MAX - 1 - strlen((string) $n)) . '/' . $n;
                    if ($db->value('SELECT 1 FROM {quotes} WHERE number = ?', [$candidate]) === null) {
                        return $candidate;
                    }
                }
            }
        }
        $year = (int) substr(Clock::now(), 0, 4);
        $db->run('INSERT IGNORE INTO {quote_counters} (year, last) VALUES (?, 0)', [$year]);
        $last = (int) $db->value('SELECT last FROM {quote_counters} WHERE year = ? FOR UPDATE', [$year]);
        $format = self::numberFormat($s);
        do {
            $last++;
            $number = self::formatNumber($format, $year, $last);
        } while ($db->value('SELECT 1 FROM {quotes} WHERE number = ?', [$number]) !== null);
        $db->run('UPDATE {quote_counters} SET last = ? WHERE year = ?', [$last, $year]);

        return $number;
    }

    /** The number the next quote of this year would get (the settings screen; not reserved). */
    public static function nextNumber(Db $db, Settings $s): string
    {
        $year = (int) substr(Clock::now(), 0, 4);
        try {
            $last = (int) ($db->value('SELECT last FROM {quote_counters} WHERE year = ?', [$year]) ?? 0);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            $last = 0; // before migration 0123
        }

        return self::formatNumber(self::numberFormat($s), $year, $last + 1);
    }

    /**
     * Continues an existing series (quote_next_number): the next quote of this year gets at least $next. Only raises the
     * counter – a lower number is ignored (false), so no number is given twice.
     */
    public static function raiseCounter(Db $db, int $next): bool
    {
        if ($next < 2) {
            return false;
        }
        $year = (int) substr(Clock::now(), 0, 4);
        $db->run('INSERT IGNORE INTO {quote_counters} (year, last) VALUES (?, 0)', [$year]);

        return $db->run('UPDATE {quote_counters} SET last = ? WHERE year = ? AND last < ?', [$next - 1, $year, $next - 1])->rowCount() > 0;
    }

    /* ---------- from an enquiry ---------- */

    /**
     * The lines of an enquiry basket, as the form stored them ("2 × Name – Variant (code)", Builder\Products::basketLines):
     * quantity, name, variant name and code. Other text is ignored (pure).
     *
     * @return list<array{quantity: int, name: string, variant: string, code: string}>
     */
    public static function parseBasket(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*(\d{1,4})\s*×\s*(.+?)(?:\s+–\s+(.+?))?(?:\s+\(([^()]{1,60})\))?\s*$/u', $line, $m) === 1 && (int) $m[1] > 0) {
                $out[] = ['quantity' => (int) $m[1], 'name' => trim($m[2]), 'variant' => trim($m[3] ?? ''), 'code' => trim($m[4] ?? '')];
            }
        }

        return $out;
    }

    /**
     * The lines a new quote of an enquiry starts with: every basket line of the enquiry matched to a product – by its code
     * (a variant's or the product's), then by its name (and the variant's name) in the products collections; what cannot be
     * matched becomes a free line with its text and quantity and no price. The prices come from the price list when the
     * quote is saved (save()), never from the enquiry.
     *
     * @return array{lines: list<array<string, mixed>>, matched: int, unmatched: int}
     */
    public static function linesFromEnquiry(App $app, int $enquiryId): array
    {
        $db = $app->db();
        $data = $db->value('SELECT data FROM {poptavky} WHERE idp = ? AND ' . \Kaleta\Admin\Modules\Enquiries::visible($db, $app->settings(), false), [$enquiryId]);
        $fields = json_decode((string) $data, true);
        $lines = [];
        $matched = 0;
        $unmatched = 0;
        $names = null;
        foreach (is_array($fields) ? $fields : [] as $field) {
            foreach (self::parseBasket(is_array($field) ? (string) ($field[1] ?? '') : '') as $b) {
                $hit = $b['code'] !== '' ? Prices::findCode($db, $b['code']) : null;
                if ($hit === null) {
                    $names ??= self::productNames($db);
                    $item = $names[mb_strtolower($b['name'])] ?? null;
                    if ($item !== null) {
                        $variant = '';
                        foreach ($item['variants'] as $v) {
                            if ($b['variant'] !== '' && mb_strtolower($v['name']) === mb_strtolower($b['variant'])) {
                                $variant = $v['code'];
                            }
                        }
                        $hit = ($b['variant'] === '' || $variant !== '') ? ['item_id' => $item['idp'], 'variant_code' => $variant] : null;
                    }
                }
                if ($hit !== null) {
                    $lines[] = ['item_id' => $hit['item_id'], 'variant_code' => $hit['variant_code'], 'quantity' => (string) $b['quantity']];
                    $matched++;
                } else {
                    $lines[] = ['name' => $b['name'] . ($b['variant'] !== '' ? ' – ' . $b['variant'] : ''), 'code' => $b['code'], 'quantity' => (string) $b['quantity'], 'unit_price' => null];
                    $unmatched++;
                }
                if (count($lines) >= self::MAX_LINES) {
                    break 2;
                }
            }
        }

        return ['lines' => $lines, 'matched' => $matched, 'unmatched' => $unmatched];
    }

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
            $enquiry = $value('enquiry_id') > 0 ? $db->one('SELECT idp, tema, email, contact_id FROM {poptavky} WHERE idp = ? AND ' . \Kaleta\Admin\Modules\Enquiries::visible($db, $s, false), [$value('enquiry_id')]) : null;
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
                    $lines = self::linesFromEnquiry($app, (int) $enquiry['idp'])['lines'];
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
     * One line as stored: the product (item_id, or a code found in the price list or among the products) with its name,
     * code and list price; the quantity, unit, discount and VAT rate; the price given or the list price; line_net.
     *
     * @param array{id: int, currency: string} $list
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $stored the line it replaces (keeps its list price)
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private static function prepareLine(Db $db, Settings $s, array $list, array $input, ?array $stored, bool $fromClaude, array &$warnings, int $n): array
    {
        $itemId = (int) (is_scalar($input['item_id'] ?? null) ? $input['item_id'] : 0);
        $variant = self::line($input['variant_code'] ?? '', Prices::CODE_MAX);
        $code = self::line($input['code'] ?? '', Prices::CODE_MAX);
        $price = null;
        $name = '';
        $unit = '';
        if ($itemId <= 0 && $code !== '') {
            $price = Prices::byCode($db, $list['id'], $code);
            if ($price !== null && $price['item_id'] !== null) {
                $itemId = $price['item_id'];
                $variant = $price['variant_code'];
                $price = null;
            } elseif ($price === null && ($found = Prices::findCode($db, $code)) !== null) {
                $itemId = $found['item_id'];
                $variant = $found['variant_code'];
            } elseif ($price !== null) {
                $name = $price['label'];
            }
        }
        if ($itemId > 0) {
            $item = Prices::item($db, $itemId) ?? throw new \DomainException(t('Line %d: the product does not exist.', $n));
            $variantName = '';
            if ($variant !== '') {
                $v = array_values(array_filter($item['variants'], fn (array $v): bool => $v['code'] === $variant))[0] ?? null;
                if ($v === null) {
                    throw new \DomainException(t('Line %1$d: the product has no variant with the code %2$s.', $n, $variant));
                }
                $variantName = $v['name'];
            }
            $name = $item['name'] . ($variantName !== '' ? ' – ' . $variantName : '');
            $code = $variant !== '' ? $variant : $item['code'];
            $price = Prices::forItem($db, $itemId, $variant, $list['id']);
        }
        $unit = $price['unit'] ?? $unit;
        $listMinor = $stored !== null && (int) ($stored['item_id'] ?? 0) === $itemId && (string) ($stored['variant_code'] ?? '') === $variant
            ? Money::fromDecimal($stored['list_price']) : ($price['minor'] ?? null);
        $given = array_key_exists('unit_price', $input) ? Money::parse($input['unit_price']) : ($stored !== null && !array_key_exists('item_id', $input) && !array_key_exists('code', $input) ? Money::fromDecimal($stored['unit_price']) : $listMinor);
        if ($fromClaude && ($itemId > 0 || $price !== null) && $listMinor === null && $given !== null) {
            $given = null; // Claude never guesses the price of a product the list has no price for
            $warnings[] = t('Line %d: %s has no internal price – a person fills it in the admin.', $n, $name);
        } elseif (($itemId > 0 || $price !== null) && $listMinor === null && $given === null) {
            $warnings[] = t('Line %d: %s has no internal price.', $n, $name);
        } elseif ($given !== null && $listMinor !== null && $given !== $listMinor) {
            $warnings[] = t('Line %d: the price differs from the price list (manual price).', $n);
        }
        if (array_key_exists('name', $input) && self::line($input['name'], self::NAME_MAX) !== '') {
            $name = self::line($input['name'], self::NAME_MAX);
        }
        if ($name === '') {
            throw new \DomainException(t('Line %d needs a name.', $n));
        }
        $quantity = Money::quantity($input['quantity'] ?? ($stored['quantity'] ?? null));
        $discount = Money::percent($input['discount_percent'] ?? ($stored['discount_percent'] ?? null));
        self::checkDiscount($discount, $s, $fromClaude);
        $rate = array_key_exists('vat_rate', $input) && !(is_string($input['vat_rate']) && trim($input['vat_rate']) === '') && $input['vat_rate'] !== null
            ? Money::percent($input['vat_rate']) : Money::percent($price['vat_rate'] ?? ($stored['vat_rate'] ?? Prices::defaultVatRate($s)));

        return ['id' => $stored['id'] ?? null, 'item_id' => $itemId > 0 ? $itemId : null, 'variant_code' => $variant, 'code' => $code, 'name' => $name,
            'description' => self::line($input['description'] ?? ($stored['description'] ?? ''), self::DESCRIPTION_MAX),
            'quantity' => Money::quantityText($quantity), 'unit' => array_key_exists('unit', $input) ? self::line($input['unit'], Prices::UNIT_MAX) : ($stored['unit'] ?? $unit),
            'unit_price' => $given === null ? null : Money::toDecimal($given), 'list_price' => $listMinor === null ? null : Money::toDecimal($listMinor),
            'discount_percent' => Money::percentText($discount), 'vat_rate' => Money::percentText($rate),
            'line_net' => Money::toDecimal($given === null ? 0 : Money::lineNet($given, $quantity, $discount))];
    }

    /** Lower-case product name => item, for matching basket lines by name (default language first). @return array<string, array<string, mixed>> */
    private static function productNames(Db $db): array
    {
        $out = [];
        foreach (Prices::productCollections($db) as $c) {
            foreach ($db->all("SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL ORDER BY jazyk = '' DESC, idp", [$c['idk']]) as $r) {
                $item = Prices::item($db, (int) $r['idp']);
                if ($item !== null) {
                    $out[mb_strtolower($item['name'])] ??= $item;
                }
            }
        }

        return $out;
    }

    /**
     * A discount above quote_max_discount is refused over MCP: a person sets it in the admin. $line > 0: the combined discount
     * of that line (3.38 review L4). @throws \DomainException
     */
    private static function checkDiscount(int $points, Settings $s, bool $fromClaude, int $line = 0): void
    {
        $max = Money::percent(trim($s->get('quote_max_discount')) !== '' ? $s->get('quote_max_discount') : '20');
        if ($fromClaude && $points > $max) {
            throw new \DomainException($line > 0
                ? t('Line %1$d: its discount and the quote\'s discount together are above %2$s %% – ask a person to set it in the admin.', $line, Money::short(Money::percentText($max)))
                : t('A discount above %s %% – ask a person to set it in the admin.', Money::short(Money::percentText($max))));
        }
    }

    /**
     * The discount a line really gets with the whole-quote discount on top, in basis points: 1 − (1 − line)(1 − quote),
     * rounded up (20 % + 20 % = 36 %). Pure.
     */
    public static function combinedDiscount(int $linePoints, int $quotePoints): int
    {
        $linePoints = max(0, min(10000, $linePoints));
        $quotePoints = max(0, min(10000, $quotePoints));

        return 10000 - intdiv((10000 - $linePoints) * (10000 - $quotePoints), 10000);
    }

    /** Is a line's price manual: priced without a list price, or different from it? */
    private static function isManual(?int $price, ?int $list): bool
    {
        return $price !== null && $price !== $list;
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

    /** One line of plain text, at most $max characters (tags and control characters out). */
    private static function line(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($text))), 0, $max);
    }

    /** A text of several lines, plain, at most $max characters. */
    private static function text(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? str_replace("\r\n", "\n", (string) $value) : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', strip_tags($text))), 0, $max);
    }
}
