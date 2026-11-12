<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Language;
use Kaleta\Core\Prices;

/**
 * MCP tools of internal prices (3.38, Core\Prices; the quote tools of the next part join this trait): list_prices reads
 * the products of the products collections with their prices in a price list, save_price saves one price or a batch of up
 * to Prices::BATCH_MAX. Only for a user who may open Quotes (the feature "quotes" on – Catalog gates it – and the
 * section); save_price also needs the permission to change prices on a connection with full access (Auth::canEditPrices).
 * No other tool returns a price. Prices are business secrets: never journaled, so undo_agent_session cannot take a change
 * back – save_price returns the previous values of every row it changed so a wrong batch can be put back. The change log
 * names the item or the code, never an amount. Refusals in English (Language::runWith). Part of Mcp\Tools.
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
