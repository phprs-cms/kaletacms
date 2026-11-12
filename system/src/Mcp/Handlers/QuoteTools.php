<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Deals;
use Kaleta\Core\Handover;
use Kaleta\Core\Language;
use Kaleta\Core\Prices;
use Kaleta\Core\Quotes;

/**
 * MCP tools of internal prices and quotes (3.38, Core\Prices and Core\Quotes): list_prices reads
 * the products of the products collections with their prices in a price list, save_price saves one price or a batch of up
 * to Prices::BATCH_MAX. Only for a user who may open Quotes (the feature "quotes" on – Catalog gates it – and the
 * section); save_price also needs the permission to change prices on a connection with full access (Auth::canEditPrices).
 * No other tool returns a price. Prices are business secrets: never journaled, so undo_agent_session cannot take a change
 * back – save_price returns the previous values of every row it changed so a wrong batch can be put back. The change log
 * names the item or the code, never an amount. Refusals in English (Language::runWith). Part of Mcp\Tools.
 *
 * 3.38 B – quotes: list_quotes and get_quote read, save_quote creates or changes a draft (a drafts-only connection may – a
 * draft never leaves the site), mark_quote marks a sent quote accepted, declined (with a reason) or back to sent and, only
 * when asked (update_deal), moves its deal to won or lost. Claude never guesses a price (Quotes::save with $fromClaude: a
 * product without a list price keeps a line without a price, a different price is kept as a manual price with a warning,
 * a discount above quote_max_discount is refused). Quotes hold customer data: never journaled, not undoable.
 *
 * @phpstan-ignore trait.unused
 */
trait QuoteTools
{
    /** list_prices */
    private function toolListPrices(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $s = $this->app->settings();
        if (!Prices::mayRead($db, $s, $this->app->auth()->id())) {
            throw new \DomainException('Only a user who may open Quotes can read internal prices – an administrator gives the section in Users.');
        }

        return Language::runWith('en', function () use ($db, $s, $a): array {
            $list = Prices::resolveList($db, $s, $a['price_list'] ?? null);
            $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));
            $collection = 0;
            if (is_scalar($a['collection'] ?? null) && trim((string) $a['collection']) !== '') {
                $wanted = trim((string) $a['collection']);
                foreach (Prices::productCollections($db) as $c) {
                    if ((string) $c['idk'] === $wanted || $c['seo_link'] === $wanted) {
                        $collection = $c['idk'];
                    }
                }
                if ($collection === 0) {
                    throw new \InvalidArgumentException('No products collection is "' . mb_substr($wanted, 0, 60) . '". Use list_collections (preset products).');
                }
            }
            $item = (int) ($a['item'] ?? 0);
            if ($item > 0) {
                $one = Prices::item($db, $item) ?? throw new \InvalidArgumentException('The item does not exist. Use list_collection_items.');
                $grid = Prices::grid($db, $list['id'], ['collection' => $one['idk']], 1, 100000);
                $grid['rows'] = array_values(array_filter($grid['rows'], fn (array $r): bool => $r['idp'] === $item));
                $grid = ['rows' => $grid['rows'], 'total' => count($grid['rows']), 'page' => 1, 'pages' => 1];
            } else {
                $grid = Prices::grid($db, $list['id'], ['collection' => $collection, 'search' => is_scalar($a['search'] ?? null) ? (string) $a['search'] : '', 'missing' => ($a['missing'] ?? false) === true],
                    max(1, (int) ($a['page'] ?? 1)), $limit);
            }
            $default = Prices::defaultVatRate($s);

            return ['price_list' => $this->priceListOut($list), 'products' => array_map(fn (array $r): array => $this->productPricesOut($r), $grid['rows']),
                'total' => $grid['total'], 'page' => $grid['page'], 'pages' => $grid['pages']]
                + ($item > 0 ? [] : ['own_rows' => array_map(fn (array $p): array => $this->priceOut($p, ['code' => $p['code'], 'name' => $p['label']]), Prices::ownRows($db, $list['id']))])
                + ['price_lists' => array_map(fn (array $l): array => $this->priceListOut($l), Prices::lists($db))]
                + ($default !== null ? ['default_vat_rate' => $default] : ['next' => 'The default VAT rate is not set – an administrator sets it in Quotes → Settings.'])
                + ['admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . Prices::SECTION . '&action=prices&list=' . $list['id'])];
        }, 'admin-');
    }

    /** save_price */
    private function toolSavePrice(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $s = $this->app->settings();
        $self = $this->app->auth()->id();
        if (!Prices::mayEdit($db, $s, $self) || !$this->app->auth()->canEditPrices()) {
            throw new \DomainException('Only a user with the permission "Quotes: change internal prices" on a connection with full access can change internal prices – an administrator gives it in Users or Roles. Nothing was saved.');
        }
        $batch = $a['prices'] ?? null;
        if ($batch !== null && (!is_array($batch) || !array_is_list($batch))) {
            throw new \InvalidArgumentException('prices must be a list of {item or code, variant, unit_price, unit, vat_rate, note, label}.');
        }
        $rows = $batch ?? [$a];
        if ($rows === [] || count($rows) > Prices::BATCH_MAX) {
            throw new \InvalidArgumentException('Give one price, or a batch of 1–' . Prices::BATCH_MAX . ' in prices.');
        }

        return Language::runWith('en', function () use ($db, $s, $a, $rows, $self): array {
            $list = Prices::resolveList($db, $s, $a['price_list'] ?? null);
            $out = [];
            $counts = ['created' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => 0];
            $db->transaction(function () use ($db, $list, $rows, $self, &$out, &$counts): void {
                foreach ($rows as $i => $row) {
                    if (!is_array($row)) {
                        throw new \InvalidArgumentException('Price ' . ($i + 1) . ' is not an object.');
                    }
                    if (!array_key_exists('unit_price', $row)) {
                        throw new \InvalidArgumentException('Price ' . ($i + 1) . ': unit_price is required (null removes the price).');
                    }
                    $values = ['unit_price' => $row['unit_price']];
                    foreach (['unit' => 'unit', 'vat_rate' => 'vat_rate', 'note' => 'note', 'label' => 'label', 'variant' => 'variant_code'] as $param => $key) {
                        if (array_key_exists($param, $row)) {
                            $values[$key] = $row[$param];
                        }
                    }
                    $item = (int) ($row['item'] ?? 0);
                    $code = is_scalar($row['code'] ?? null) ? trim((string) $row['code']) : '';
                    if ($item <= 0 && $code !== '' && !array_key_exists('label', $row)) {
                        // a product's (or a variant's) code names the product unless it is an own row of the list
                        $own = $db->value('SELECT 1 FROM {prices} WHERE price_list_id = ? AND item_id IS NULL AND code = ?', [$list['id'], $code]);
                        $found = $own === null ? Prices::findCode($db, $code) : null;
                        if ($found !== null) {
                            $item = $found['item_id'];
                            $values['variant_code'] ??= $found['variant_code'];
                        }
                    }
                    if ($item > 0) {
                        $values['item_id'] = $item;
                    } else {
                        $values['code'] = $code;
                    }
                    try {
                        $done = Prices::save($this->app, $list['id'], $values, $self, false);
                    } catch (\DomainException $e) {
                        throw new \DomainException('Price ' . ($i + 1) . ': ' . $e->getMessage() . ' Nothing was saved.');
                    }
                    $counts[$done['action']]++;
                    $out[] = array_filter(['item' => $item > 0 ? $item : null, 'code' => $item > 0 ? null : $code, 'variant' => (string) ($values['variant_code'] ?? '') !== '' ? (string) $values['variant_code'] : null,
                        'action' => $done['action'], 'before' => $done['before'] !== null ? $this->priceOut($done['before']) : null, 'after' => $done['after'] !== null ? $this->priceOut($done['after']) : null],
                        fn (mixed $v): bool => $v !== null);
                }
            });
            if ($counts['created'] + $counts['changed'] + $counts['removed'] > 0) {
                \Kaleta\Admin\ChangeLog::write($this->app, Prices::SECTION, 'price', t('Internal prices changed in %1$s: %2$d', $list['name'], $counts['created'] + $counts['changed'] + $counts['removed']));
            }

            return ['price_list' => $this->priceListOut($list)] + array_filter($counts) + ['prices' => $out,
                'next' => 'Not undoable: the previous values are in before – save them back to undo a wrong change.'];
        }, 'admin-');
    }

    /** list_quotes */
    private function toolListQuotes(string $name, array $a): mixed
    {
        $this->quotesOpen();
        $db = $this->app->db();
        $status = is_string($a['status'] ?? null) && trim($a['status']) !== '' ? strtolower(trim($a['status'])) : 'all';
        if (!in_array($status, Quotes::LIST_STATUSES, true)) {
            throw new \InvalidArgumentException('status must be ' . implode(' | ', Quotes::LIST_STATUSES) . '.');
        }
        $filter = ['status' => $status, 'search' => is_scalar($a['search'] ?? null) ? (string) $a['search'] : ''];
        foreach (['contact', 'company', 'deal', 'enquiry'] as $key) {
            $filter[$key] = (int) ($a[$key] ?? 0);
        }
        if (array_key_exists('owner', $a) && is_scalar($a['owner']) && trim((string) $a['owner']) !== '') {
            $filter['owner'] = Handover::resolve($db, $a['owner'], $this->app->auth()->id()) ?? -1;
        }
        $result = Quotes::list($db, $filter, max(1, (int) ($a['page'] ?? 1)), max(1, min(100, (int) ($a['limit'] ?? 25))));

        return ['quotes' => array_map(fn (array $q): array => array_filter(['id' => $q['id'], 'number' => $q['number'], 'status' => $q['status'], 'title' => $q['title'] !== '' ? $q['title'] : null,
            'customer' => array_filter(['name' => $q['customer_name'], 'company' => $q['customer_company'], 'email' => $q['customer_email']]) ?: null,
            'total_net' => $q['total_net'], 'total_gross' => $q['total_gross'], 'currency' => $q['currency'], 'valid_until' => $q['valid_until'], 'revision_of' => $q['revision_of'],
            'owner' => Handover::person($db, $q['owner_id']), 'updated_at' => substr($q['updated_at'], 0, 16), 'admin_url' => $this->app->request->origin() . $this->app->url(Quotes::adminPath($q['id']))],
            fn (mixed $v): bool => $v !== null), $result['rows']), 'total' => $result['total'], 'page' => $result['page'], 'pages' => $result['pages'],
            'admin_url' => $this->app->request->origin() . $this->app->url('admin.php?module=' . Quotes::SECTION)]
            + ($result['total'] === 0 ? ['next' => 'No quotes here. save_quote creates a draft (with enquiry: from its basket and customer).'] : []);
    }

    /** get_quote */
    private function toolGetQuote(string $name, array $a): mixed
    {
        $this->quotesOpen();
        $quote = Quotes::get($this->app->db(), (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The quote does not exist. Use list_quotes.');

        return $this->quoteOut($quote);
    }

    /** save_quote */
    private function toolSaveQuote(string $name, array $a): mixed
    {
        $this->quotesOpen();
        $db = $this->app->db();
        $s = $this->app->settings();
        $self = $this->app->auth()->id();
        $id = (int) ($a['id'] ?? 0);
        if ($id > 0 && Quotes::get($db, $id) === null) {
            throw new \InvalidArgumentException('The quote does not exist. Use list_quotes.');
        }
        $fields = [];
        foreach (['enquiry' => 'enquiry_id', 'deal' => 'deal_id', 'contact' => 'contact_id', 'company' => 'company_id'] as $param => $key) {
            if (array_key_exists($param, $a)) {
                $value = is_scalar($a[$param]) ? trim((string) $a[$param]) : '';
                $fields[$key] = in_array(strtolower($value), ['none', 'null', ''], true) ? 0 : (int) $value;
            }
        }
        if ($id > 0 && ($fields['enquiry_id'] ?? 0) > 0) {
            throw new \InvalidArgumentException('enquiry starts a new quote from the enquiry – leave out id.');
        }
        foreach (['title', 'intro', 'terms', 'valid_until', 'valid_days', 'discount_percent', 'vat_mode', 'language'] as $key) {
            if (array_key_exists($key, $a)) {
                $fields[$key] = is_scalar($a[$key]) ? $a[$key] : null;
            }
        }
        if (array_key_exists('owner', $a)) {
            $fields['owner_id'] = Handover::resolve($db, $a['owner'], $self) ?? 0;
        }
        if (array_key_exists('customer', $a)) {
            if (!is_array($a['customer'])) {
                throw new \InvalidArgumentException('customer must be an object {name, company, address, reg_no, vat_id, email}.');
            }
            $fields['customer'] = array_intersect_key($a['customer'], Quotes::CUSTOMER);
        }
        if (array_key_exists('lines', $a)) {
            if (!is_array($a['lines']) || !array_is_list($a['lines'])) {
                throw new \InvalidArgumentException('lines must be a list of {item or code, variant, quantity, discount_percent, unit_price, name, description, unit, vat_rate} – it replaces all lines (keep a line by its id).');
            }
            $fields['lines'] = [];
            foreach ($a['lines'] as $i => $line) {
                if (!is_array($line)) {
                    throw new \InvalidArgumentException('Line ' . ($i + 1) . ' is not an object.');
                }
                $out = [];
                foreach (['id' => 'id', 'item' => 'item_id', 'code' => 'code', 'variant' => 'variant_code', 'quantity' => 'quantity', 'discount_percent' => 'discount_percent',
                    'unit_price' => 'unit_price', 'name' => 'name', 'description' => 'description', 'unit' => 'unit', 'vat_rate' => 'vat_rate'] as $param => $key) {
                    if (array_key_exists($param, $line)) {
                        $out[$key] = $line[$param];
                    }
                }
                $fields['lines'][] = $out;
            }
        }
        try {
            $saved = Language::runWith('en', function () use ($db, $s, $a, $fields, $id, $self): array {
                if (array_key_exists('price_list', $a)) {
                    $fields['price_list_id'] = Prices::resolveList($db, $s, $a['price_list'])['id'];
                }

                return Quotes::save($this->app, $id > 0 ? $id : null, $fields, $self, true);
            }, 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing was saved.');
        }
        $quote = Quotes::get($db, $saved['id']) ?? throw new \DomainException('The quote does not exist.');

        return ['id' => $saved['id'], ($id > 0 ? 'updated' : 'created') => true] + ($saved['warnings'] !== [] ? ['warnings' => $saved['warnings']] : [])
            + ['quote' => $this->quoteOut($quote),
                'note' => 'A draft – nothing went to the customer. A person checks it in the admin and sends it (admin_url). Not undoable: quotes hold customer data and never enter the undo journal.'];
    }

    /** mark_quote */
    private function toolMarkQuote(string $name, array $a): mixed
    {
        $this->quotesOpen();
        $db = $this->app->db();
        $self = $this->app->auth()->id();
        $id = (int) ($a['id'] ?? 0);
        $before = Quotes::get($db, $id) ?? throw new \InvalidArgumentException('The quote does not exist. Use list_quotes.');
        $status = is_string($a['status'] ?? null) ? strtolower(trim($a['status'])) : '';
        if (!in_array($status, ['accepted', 'declined', 'sent'], true)) {
            throw new \InvalidArgumentException('status must be accepted | declined | sent (back from accepted or declined).');
        }
        $updateDeal = ($a['update_deal'] ?? false) === true;
        if ($updateDeal && $status !== 'sent' && $before['deal_id'] !== null && !Deals::mayOpen($db, $this->app->settings(), $self)) {
            throw new \DomainException('Only a user who may open the pipeline can move the deal – leave out update_deal. Nothing changed.');
        }
        // decline_reason, not reason: "reason" is every tool's own guardrail argument (Mcp\Server takes it away)
        $reason = is_string($a['decline_reason'] ?? null) && trim($a['decline_reason']) !== '' ? trim($a['decline_reason']) : null;
        try {
            $changed = Language::runWith('en', fn (): bool => Quotes::setStatus($this->app, $id, $status, $self, $reason), 'admin-');
        } catch (\DomainException $e) {
            throw new \DomainException($e->getMessage() . ' Nothing changed.');
        }
        $out = ['id' => $id, 'changed' => $changed, 'from' => $before['status'], 'status' => $status];
        if ($updateDeal && $status !== 'sent' && $before['deal_id'] !== null) {
            $kind = $status === 'accepted' ? 'won' : 'lost';
            $stage = array_values(array_filter(Deals::stages($this->app->settings()), fn (array $st): bool => $st['kind'] === $kind))[0] ?? null;
            $deal = Deals::get($db, $before['deal_id']);
            if ($stage !== null && $deal !== null && $deal['closed_at'] === null) {
                try {
                    Language::runWith('en', fn (): array => Deals::move($this->app, $deal['id'], $stage['key'], $self, $kind === 'lost' ? $reason : null), 'admin-');
                    $out['deal'] = ['id' => $deal['id'], 'moved_to' => $stage['key']];
                } catch (\DomainException $e) {
                    $out['deal'] = ['id' => $deal['id'], 'not_moved' => $e->getMessage()];
                }
            } elseif ($deal !== null) {
                $out['deal'] = ['id' => $deal['id'], 'not_moved' => $deal['closed_at'] !== null ? 'The deal is already closed.' : 'The pipeline has no ' . $kind . ' stage.'];
            }
        }
        $quote = Quotes::get($db, $id); // not null: $before above, and setStatus never deletes

        return $out + ['quote' => ['id' => $quote['id'], 'number' => $quote['number'], 'status' => $quote['status']] + ($quote['decline_reason'] !== null ? ['decline_reason' => $quote['decline_reason']] : []),
            'note' => 'Not undoable through undo_agent_session; set it back with status sent.'];
    }

    /** The connected user must be able to open Quotes – otherwise an error says why. */
    private function quotesOpen(): void
    {
        if (!Quotes::mayOpen($this->app->db(), $this->app->settings(), $this->app->auth()->id())) {
            throw new \DomainException('Only a user who may open Quotes can read or change quotes – an administrator gives the section in Users.');
        }
    }

    /**
     * A quote as the tools show it: the customer block, the lines (manual = a price that differs from the price list;
     * unit_price null = no price yet), the totals per VAT rate as decimal texts, the history, why a draft cannot go out yet
     * (not_sendable) and the admin address. No default values.
     *
     * @param array<string, mixed> $q Quotes::get
     * @return array<string, mixed>
     */
    private function quoteOut(array $q): array
    {
        $db = $this->app->db();
        $list = Prices::priceList($db, $q['price_list_id']);
        $refusal = $q['status'] === 'draft' ? Language::runWith('en', fn (): ?string => Quotes::sendRefusal($q), 'admin-') : null;

        return array_filter(['id' => $q['id'], 'number' => $q['number'], 'status' => $q['status'], 'title' => $q['title'] !== '' ? $q['title'] : null,
            'revision_of' => $q['revision_of'], 'customer' => array_filter($q['customer']) ?: null,
            'contact' => $q['contact_id'], 'company' => $q['company_id'], 'enquiry' => $q['enquiry_id'], 'deal' => $q['deal_id'], 'owner' => Handover::person($db, $q['owner_id']),
            'price_list' => $list !== null ? $this->priceListOut($list) : null, 'currency' => $q['currency'], 'language' => $q['language'] !== '' ? $q['language'] : null,
            'valid_until' => $q['valid_until'], 'discount_percent' => $q['discount_percent'] !== '0.00' ? $q['discount_percent'] : null,
            'vat_mode' => $q['vat_mode'] !== 'standard' ? $q['vat_mode'] : null, 'intro' => $q['intro'] !== '' ? $q['intro'] : null, 'terms' => $q['terms'] !== '' ? $q['terms'] : null,
            'lines' => array_map(fn (array $l): array => array_filter(['id' => $l['id'], 'item' => $l['item_id'], 'variant' => $l['variant_code'] !== '' ? $l['variant_code'] : null,
                'code' => $l['code'] !== '' ? $l['code'] : null, 'name' => $l['name'], 'description' => $l['description'] !== '' ? $l['description'] : null, 'quantity' => $l['quantity'],
                'unit' => $l['unit'] !== '' ? $l['unit'] : null, 'unit_price' => $l['unit_price'], 'list_price' => $l['manual'] ? $l['list_price'] : null,
                'discount_percent' => $l['discount_percent'] !== '0.00' ? $l['discount_percent'] : null, 'vat_rate' => $l['vat_rate'], 'line_net' => $l['line_net'],
                'manual' => $l['manual'] ? true : null], fn (mixed $v): bool => $v !== null) + ($l['unit_price'] === null ? ['unit_price' => null] : []), $q['lines']),
            'totals' => $q['totals']['unpriced'] > 0 ? $q['totals'] : array_diff_key($q['totals'], ['unpriced' => 0]),
            'history' => array_map(fn (array $h): array => array_filter(['at' => substr((string) ($h['at'] ?? ''), 0, 16), 'action' => $h['action'] ?? null, 'from' => $h['from'] ?? null,
                'to' => $h['to'] ?? null, 'note' => $h['note'] ?? null, 'by' => isset($h['by']) ? Handover::person($db, $h['by']) : null], fn (mixed $v): bool => $v !== null), $q['history']),
            'not_sendable' => $refusal, 'sent_at' => $q['sent_at'] !== null ? substr($q['sent_at'], 0, 16) : null,
            'decided_at' => $q['decided_at'] !== null ? substr($q['decided_at'], 0, 16) : null, 'decline_reason' => $q['decline_reason'],
            'updated_at' => $q['updated_at'], 'admin_url' => $this->app->request->origin() . $this->app->url(Quotes::adminPath($q['id']))], fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * A price list as the tools show it.
     *
     * @param array{id: int, name: string, currency: string, is_default: bool, archived_at: ?string} $l
     * @return array<string, mixed>
     */
    private function priceListOut(array $l): array
    {
        return ['id' => $l['id'], 'name' => $l['name'], 'currency' => $l['currency']] + ($l['is_default'] ? ['default' => true] : []) + ($l['archived_at'] !== null ? ['archived' => true] : []);
    }

    /**
     * One price, compact: the amount as a DECIMAL text, the unit, the VAT rate (absent = the default), the internal note.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $head
     * @return array<string, mixed>
     */
    private function priceOut(array $p, array $head = []): array
    {
        return $head + ['unit_price' => (string) $p['unit_price']] + array_filter(['unit' => (string) $p['unit'], 'vat_rate' => $p['vat_rate'], 'note' => (string) $p['note']],
            fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * A product of the grid: item, name, code, its own price and its variants with theirs; "missing" lists what has none.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function productPricesOut(array $r): array
    {
        $out = ['item' => $r['idp'], 'name' => $r['name']] + ($r['code'] !== '' ? ['code' => $r['code']] : []) + ($r['language'] !== '' ? ['language' => $r['language']] : []);
        $variants = [];
        $missing = [];
        foreach ($r['lines'] as $line) {
            if ($line['variant'] === '') {
                if ($line['price'] !== null) {
                    $out += $this->priceOut($line['price']);
                } else {
                    $missing[] = 'product';
                }
                continue;
            }
            $head = ['code' => $line['variant']] + ($line['name'] !== '' ? ['name' => $line['name']] : []) + ($line['gone'] ? ['gone' => true] : []);
            if ($line['price'] !== null) {
                $variants[] = $this->priceOut($line['price'], $head);
            } else {
                $variants[] = $head;
                $missing[] = $line['variant'];
            }
        }

        return $out + ($variants !== [] ? ['variants' => $variants] : []) + ($missing !== [] ? ['missing' => $missing] : []);
    }

    /**
     * send_quote (3.38 C, design A-5): a real e-mail with the quote to its customer – confirm=true on a full-access
     * connection (Catalog: destructive), only to the address of the quote's customer record or enquiry
     * (QuoteDelivery::claudeRefusal); confirm=false returns what would be sent and sends nothing.
     */
    private function toolSendQuote(string $name, array $a): mixed
    {
        $db = $this->app->db();
        if (!\Kaleta\Core\Quotes::mayOpen($db, $this->app->settings(), $this->app->auth()->id())) {
            throw new \DomainException('Only a user who may open Quotes can send a quote – an administrator gives the section in Users.');
        }

        return Language::runWith('en', function () use ($db, $a): array {
            $id = (int) ($a['id'] ?? 0);
            $quote = \Kaleta\Core\Quotes::get($db, $id) ?? throw new \InvalidArgumentException('The quote does not exist.');
            $draft = \Kaleta\Core\QuoteDelivery::draft($this->app, $quote);
            $subject = is_string($a['subject'] ?? null) ? $a['subject'] : '';
            $message = is_string($a['message'] ?? null) ? $a['message'] : '';
            $setDeal = is_bool($a['set_deal_value'] ?? null) ? $a['set_deal_value'] : $draft['set_deal_value'];
            if (($a['confirm'] ?? false) !== true) {
                $why = $draft['refusal'] ?? $draft['smtp'] ?? \Kaleta\Core\QuoteDelivery::claudeRefusal($db, $quote);

                return ['id' => $id, 'sent' => false, 'to' => $draft['to'], 'subject' => $subject !== '' ? $subject : $draft['subject'], 'message' => $message !== '' ? $message : $draft['message'],
                    'currency' => $quote['currency'], 'totals' => ['net' => $quote['totals']['net'], 'vat' => $quote['totals']['vat'], 'gross' => $quote['totals']['gross']],
                    'valid_until' => $quote['valid_until'], 'set_deal_value' => $setDeal && $quote['deal_id'] !== null]
                    + ($why !== null ? ['cannot_send' => $why] : [])
                    + ['next' => $why !== null ? 'Tell the user why the quote cannot be sent yet.' : 'Show the user the recipient, subject, message and total. Send only when they explicitly ask: the same call with confirm=true.'];
            }
            $result = \Kaleta\Core\QuoteDelivery::send($this->app, $id, $subject, $message, $setDeal, 'mcp');

            return ['id' => $id, 'sent' => true, 'number' => $result['number'], 'status' => $result['status'], 'to' => $result['to'], 'deal_value_set' => $result['deal_value_set']]
                + ($result['error'] !== '' ? ['error' => $result['error']] : []) + ($result['warnings'] !== [] ? ['warnings' => $result['warnings']] : [])
                + ['admin_url' => $this->app->request->origin() . $this->app->url(\Kaleta\Core\Quotes::adminPath($id)),
                    'next' => $result['status'] === 'sent' ? 'Tell the user the quote went out with its number. Its link and state are in the quote in the administration.'
                        : 'Tell the user the mail server refused it for now (' . $result['error'] . '); the site retries from its mail queue – the quote is numbered and counts as sent.'];
        }, 'admin-');
    }
}
