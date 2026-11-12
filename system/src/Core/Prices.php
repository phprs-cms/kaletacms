<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Collections;
use Kaleta\Builder\Products;

/**
 * Internal prices (3.38, design A1–A4; tables ka_price_lists and ka_prices, migration 0123): what a firm charges for its
 * products and own rows (installation, transport, training), in one or more price lists of one currency each – prices
 * nobody outside sees. The feature EXTENSION is off until an administrator switches it on; SECTION (Admin\Modules\Quotes)
 * reads prices and works with quotes, the area Auth::PRICES_AREA changes them. Administrators have both; an update gives
 * them to nobody else.
 *
 *  - Never public, by construction: a price is not part of the item, so no read path of the site (pages, item pages,
 *    structured data, the basket, search, sitemap, llms.txt, .md), no export, blueprint, page package, item version or
 *    undo journal can print it. Only Prices, Quotes, PersonalData, Privacy, Admin\Modules\Quotes, Mcp\Handlers\QuoteTools,
 *    Front\QuotePage and the clean-up name the tables (tools/tests/unit/2800-… checks the whole tree).
 *  - A row: a product (item_id, '' = the product itself or a variant's code) or an own row (item_id NULL, a code unique in
 *    its list and a label); unit price without VAT, unit, VAT rate (NULL = the default quote_vat_rate), an internal note.
 *  - forItem(): the price of a product in a list – the item itself, then its default-language counterpart (same
 *    collection, same address – the pairing of item translations), then the product code.
 *  - The change log names the item or the code, never an amount; the CSV export and import live in Quotes → Internal
 *    prices. The first default list is created on first use (defaultList) in the site's default currency.
 *
 * Messages of \DomainException are translated (t()); MCP calls them inside Language::runWith('en').
 */
final class Prices
{
    /** The feature (Core\Extensions) and the admin section (Admin\Modules\Quotes, a row in ka_uzivatele_prava). */
    public const string EXTENSION = 'quotes';
    public const string SECTION = 'quotes';

    /** The longest values of a row and of a list. */
    public const int CODE_MAX = 60;
    public const int LABEL_MAX = 200;
    public const int UNIT_MAX = 20;
    public const int NOTE_MAX = 200;
    public const int LIST_NAME_MAX = 100;
    public const int LIST_NOTE_MAX = 300;

    /** Rows of one CSV import and of one batch of save_price. */
    public const int IMPORT_MAX = 5000;
    public const int BATCH_MAX = 200;

    /** Products per page of the grid. */
    public const int PAGE = 50;

    /** The columns of the CSV export and import (machine names, in this order). */
    public const array CSV_COLUMNS = ['code', 'variant', 'price', 'unit', 'vat', 'note', 'name'];

    public static function isOn(Settings $settings): bool
    {
        return Extensions::isEnabled($settings, self::EXTENSION);
    }

    /** May this account read internal prices: the feature on, an active administrator or one with the section? */
    public static function mayRead(Db $db, Settings $siteSettings, int $userId): bool
    {
        return self::isOn($siteSettings) && self::hasRight($db, $userId, self::SECTION);
    }

    /** May this account change internal prices: mayRead and (an administrator or) the area Auth::PRICES_AREA? */
    public static function mayEdit(Db $db, Settings $siteSettings, int $userId): bool
    {
        return self::mayRead($db, $siteSettings, $userId) && self::hasRight($db, $userId, Auth::PRICES_AREA);
    }

    /* ---------- price lists ---------- */

    /**
     * The price lists, the default first, then by name; archived ones only with $archived.
     *
     * @return list<array{id: int, name: string, currency: string, is_default: bool, note: string, archived_at: ?string, prices: int}>
     */
    public static function lists(Db $db, bool $archived = false): array
    {
        try {
            $rows = $db->all('SELECT l.*, (SELECT COUNT(*) FROM {prices} p WHERE p.price_list_id = l.id) AS prices FROM {price_lists} l'
                . ($archived ? '' : ' WHERE l.archived_at IS NULL') . ' ORDER BY l.is_default DESC, ' . $db->sortText('l.name') . ', l.id');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0123
        }

        return array_map(self::listRow(...), $rows);
    }

    /** @return array{id: int, name: string, currency: string, is_default: bool, note: string, archived_at: ?string, prices: int}|null */
    public static function priceList(Db $db, int $id): ?array
    {
        $row = $id > 0 ? $db->one('SELECT l.*, (SELECT COUNT(*) FROM {prices} p WHERE p.price_list_id = l.id) AS prices FROM {price_lists} l WHERE l.id = ?', [$id]) : null;

        return $row === null ? null : self::listRow($row);
    }

    /**
     * The default price list; a site without one gets it now – "Standard" in the site's default currency (Deals::
     * defaultCurrency) – or, when lists exist but none is the default, the first one that is not archived becomes it.
     *
     * @return array{id: int, name: string, currency: string, is_default: bool, note: string, archived_at: ?string, prices: int}
     */
    public static function defaultList(Db $db, Settings $s): array
    {
        $row = $db->one('SELECT id FROM {price_lists} WHERE is_default = 1 AND archived_at IS NULL ORDER BY id LIMIT 1');
        if ($row === null) {
            $first = $db->value('SELECT id FROM {price_lists} WHERE archived_at IS NULL ORDER BY id LIMIT 1');
            if ($first !== null) {
                $db->run('UPDATE {price_lists} SET is_default = (id = ?)', [(int) $first]);
                $row = ['id' => (int) $first];
            } else {
                $now = Clock::now();
                $row = ['id' => $db->insert('price_lists', ['name' => 'Standard', 'currency' => Deals::defaultCurrency($s), 'is_default' => 1, 'note' => '', 'archived_at' => null,
                    'created_at' => $now, 'updated_at' => $now])];
            }
        }

        return self::priceList($db, (int) $row['id']) ?? throw new \LogicException('The default price list vanished.');
    }

    /**
     * Creates a price list or renames one / changes its note. The currency of a list that already has prices or quotes
     * does not change (one quote has one currency, prices have no exchange rate).
     *
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function saveList(App $app, ?int $id, string $name, string $currency, string $note = ''): int
    {
        $db = $app->db();
        $name = self::line($name, self::LIST_NAME_MAX);
        if ($name === '') {
            throw new \DomainException(t('Give the price list a name.'));
        }
        $currency = Deals::currency($currency);
        if ($currency === '') {
            throw new \DomainException(t('Choose the currency of the price list.'));
        }
        $note = self::line($note, self::LIST_NOTE_MAX);
        $now = Clock::now();
        if ($id === null || $id <= 0) {
            $default = $db->value('SELECT 1 FROM {price_lists} WHERE is_default = 1 AND archived_at IS NULL') === null;
            $id = $db->insert('price_lists', ['name' => $name, 'currency' => $currency, 'is_default' => $default ? 1 : 0, 'note' => $note, 'archived_at' => null, 'created_at' => $now, 'updated_at' => $now]);
            \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price list', t('Price list created: %s', $name));

            return $id;
        }
        $list = self::priceList($db, $id) ?? throw new \DomainException(t('The price list does not exist.'));
        if ($currency !== $list['currency'] && ($list['prices'] > 0 || $db->value('SELECT 1 FROM {quotes} WHERE price_list_id = ? LIMIT 1', [$id]) !== null)) {
            throw new \DomainException(t('The currency of a price list with prices or quotes cannot change – create a new list for %s.', $currency));
        }
        $db->update('price_lists', ['name' => $name, 'currency' => $currency, 'note' => $note, 'updated_at' => $now], ['id' => $id]);
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price list', t('Price list changed: %s', $name));

        return $id;
    }

    /**
     * Archives a list (not offered for new quotes; its prices and quotes stay) or brings it back. The default list is not
     * archived – make another one the default first.
     *
     * @throws \DomainException
     */
    public static function archiveList(App $app, int $id, bool $archive): void
    {
        $db = $app->db();
        $list = self::priceList($db, $id) ?? throw new \DomainException(t('The price list does not exist.'));
        if ($archive && $list['is_default']) {
            throw new \DomainException(t('The default price list cannot be archived – make another list the default first.'));
        }
        $db->update('price_lists', ['archived_at' => $archive ? Clock::now() : null, 'updated_at' => Clock::now()], ['id' => $id]);
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price list', ($archive ? t('Price list archived: %s', $list['name']) : t('Price list restored: %s', $list['name'])));
    }

    /** Makes a list that is not archived the default one. @throws \DomainException */
    public static function makeDefault(App $app, int $id): void
    {
        $db = $app->db();
        $list = self::priceList($db, $id) ?? throw new \DomainException(t('The price list does not exist.'));
        if ($list['archived_at'] !== null) {
            throw new \DomainException(t('An archived price list cannot be the default.'));
        }
        $db->run('UPDATE {price_lists} SET is_default = (id = ?)', [$id]);
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price list', t('Default price list: %s', $list['name']));
    }

    /**
     * A price list given by id, name or currency code (MCP); null/''/0 = the default list.
     *
     * @return array{id: int, name: string, currency: string, is_default: bool, note: string, archived_at: ?string, prices: int}
     * @throws \DomainException
     */
    public static function resolveList(Db $db, Settings $s, mixed $value): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '' || $text === '0') {
            return self::defaultList($db, $s);
        }
        if (ctype_digit($text)) {
            return self::priceList($db, (int) $text) ?? throw new \DomainException(t('The price list does not exist.'));
        }
        foreach (self::lists($db, true) as $list) {
            if (mb_strtolower($list['name']) === mb_strtolower($text)) {
                return $list;
            }
        }
        $byCurrency = array_values(array_filter(self::lists($db), fn (array $l): bool => $l['currency'] === strtoupper($text)));
        if (count($byCurrency) === 1) {
            return $byCurrency[0];
        }

        throw new \DomainException(t('The price list does not exist.'));
    }

    /* ---------- products ---------- */

    /**
     * The collections whose items are products (made from the products preset), with the keys of their code and variants
     * fields ('' when the field is gone).
     *
     * @return list<array{idk: int, nazev: string, seo_link: string, code: string, variants: string}>
     */
    public static function productCollections(Db $db): array
    {
        $out = [];
        foreach (Collections::all($db) as $c) {
            $fields = Products::fields($c);
            if ($fields !== null) {
                $out[] = ['idk' => (int) $c['idk'], 'nazev' => (string) $c['nazev'], 'seo_link' => (string) $c['seo_link'], 'code' => $fields['code'], 'variants' => $fields['variants']];
            }
        }

        return $out;
    }

    /**
     * One item of any collection, not in the trash, with its code and variants (when its collection is a products one).
     *
     * @return array{idp: int, idk: int, name: string, slug: string, language: string, visible: bool, code: string, variants: list<array{name: string, code: string}>}|null
     */
    public static function item(Db $db, int $itemId): ?array
    {
        $row = $itemId > 0 ? $db->one('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.jazyk, p.zobrazit, p.data, k.preset, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.idp = ? AND p.smazano IS NULL', [$itemId]) : null;

        return $row === null ? null : self::itemRow($row);
    }

    /**
     * The price of a product (or of one of its variants) in a price list ($priceListId 0 = the default list; none yet =
     * null): the row of the item and variant; for a variant without its own price, the product's (matched "product"); then
     * the item's default-language counterpart – the same collection and address (matched "translation"); then a row of the
     * list with the item's or the variant's code (matched "code"). null = no price.
     *
     * @return array{id: int, price_list_id: int, currency: string, item_id: ?int, variant_code: string, code: string, label: string, unit_price: string, minor: int, unit: string, vat_rate: ?string, note: string, updated_at: string, matched: string}|null
     */
    public static function forItem(Db $db, int $itemId, string $variantCode = '', int $priceListId = 0): ?array
    {
        try {
            $listId = $priceListId > 0 ? $priceListId : (int) $db->value('SELECT id FROM {price_lists} WHERE is_default = 1 ORDER BY archived_at IS NOT NULL, id LIMIT 1');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0123
        }
        $item = self::item($db, $itemId);
        if ($listId <= 0 || $item === null) {
            return null;
        }
        $candidates = [[$item['idp'], 'item']];
        if ($item['language'] !== '') {
            $original = $db->value("SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND jazyk = '' AND smazano IS NULL LIMIT 1", [$item['idk'], $item['slug']]);
            if ($original !== null) {
                $candidates[] = [(int) $original, 'translation'];
            }
        }
        foreach ($candidates as [$id, $how]) {
            foreach (array_unique([$variantCode, '']) as $variant) {
                $row = $db->one('SELECT p.*, l.currency FROM {prices} p JOIN {price_lists} l ON l.id = p.price_list_id WHERE p.price_list_id = ? AND p.item_id = ? AND p.variant_code = ?',
                    [$listId, $id, $variant]);
                if ($row !== null) {
                    return self::priceRow($row, $how === 'item' && $variant !== $variantCode ? 'product' : $how);
                }
            }
        }
        $code = $variantCode !== '' ? $variantCode : $item['code'];
        if ($code !== '') {
            $row = $db->one('SELECT p.*, l.currency FROM {prices} p JOIN {price_lists} l ON l.id = p.price_list_id WHERE p.price_list_id = ? AND (p.code = ? OR (p.variant_code = ? AND p.variant_code <> \'\'))'
                . ' ORDER BY p.item_id IS NULL, p.id LIMIT 1', [$listId, $code, $code]);
            if ($row !== null) {
                return self::priceRow($row, 'code');
            }
        }

        return null;
    }

    /**
     * A row of a price list by its code: an own row first, then a product's (the product code, or a variant's code).
     *
     * @return array{id: int, price_list_id: int, currency: string, item_id: ?int, variant_code: string, code: string, label: string, unit_price: string, minor: int, unit: string, vat_rate: ?string, note: string, updated_at: string, matched: string}|null
     */
    public static function byCode(Db $db, int $priceListId, string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || $priceListId <= 0) {
            return null;
        }
        $row = $db->one('SELECT p.*, l.currency FROM {prices} p JOIN {price_lists} l ON l.id = p.price_list_id WHERE p.price_list_id = ? AND (p.code = ? OR p.variant_code = ?)'
            . ' ORDER BY p.item_id IS NOT NULL, p.variant_code = ?, p.id LIMIT 1', [$priceListId, $code, $code, $code]);

        return $row === null ? null : self::priceRow($row, 'code');
    }

    /**
     * Finds the product a code names: the item whose code field or one of whose variants has it, in a products collection.
     *
     * @return array{item_id: int, variant_code: string}|null
     */
    public static function findCode(Db $db, string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return self::codeIndex($db)[mb_strtolower($code)] ?? null;
    }

    /**
     * Every code of the products: lower-case code => [item, variant code ('' = the product's own code)]. The first item
     * with a code wins (the default language before a translation).
     *
     * @return array<string, array{item_id: int, variant_code: string}>
     */
    public static function codeIndex(Db $db): array
    {
        $index = [];
        foreach (self::productCollections($db) as $c) {
            if ($c['code'] === '' && $c['variants'] === '') {
                continue;
            }
            foreach ($db->all("SELECT idp, data FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL ORDER BY jazyk = '' DESC, idp", [$c['idk']]) as $r) {
                $data = json_decode((string) $r['data'], true) ?: [];
                $own = $c['code'] !== '' ? trim((string) ($data[$c['code']] ?? '')) : '';
                if ($own !== '') {
                    $index[mb_strtolower($own)] ??= ['item_id' => (int) $r['idp'], 'variant_code' => ''];
                }
                foreach ($c['variants'] !== '' ? Products::variants((string) ($data[$c['variants']] ?? '')) : [] as $v) {
                    if ($v['code'] !== '') {
                        $index[mb_strtolower($v['code'])] ??= ['item_id' => (int) $r['idp'], 'variant_code' => $v['code']];
                    }
                }
            }
        }

        return $index;
    }

    /* ---------- saving ---------- */

    /**
     * Saves one price of a list and returns what it was and what it is (the previous values let a wrong batch be put back).
     * $row: item_id (a product) or code (an own row; with label to create one), variant_code, unit_price (null or '' =
     * remove the price), unit, vat_rate ('' or null = the default rate), note. A product row takes the product's (or the
     * variant's) current code. The change log names the item or the code – never an amount.
     *
     * @param array<string, mixed> $row
     * @return array{action: 'created'|'changed'|'removed'|'unchanged', before: ?array<string, mixed>, after: ?array<string, mixed>}
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function save(App $app, int $priceListId, array $row, int $byUserId, bool $log = true): array
    {
        $db = $app->db();
        $list = self::priceList($db, $priceListId) ?? throw new \DomainException(t('The price list does not exist.'));
        $itemId = (int) (is_scalar($row['item_id'] ?? null) ? $row['item_id'] : 0);
        $variant = self::line($row['variant_code'] ?? '', self::CODE_MAX);
        if ($itemId > 0) {
            $item = self::item($db, $itemId) ?? throw new \DomainException(t('The product does not exist.'));
            if ($variant !== '' && !in_array($variant, array_column($item['variants'], 'code'), true)) {
                throw new \DomainException(t('The product has no variant with the code %s.', $variant));
            }
            $code = $variant !== '' ? $variant : $item['code'];
            $where = ['price_list_id' => $list['id'], 'item_id' => $itemId, 'variant_code' => $variant];
            $existing = $db->one('SELECT * FROM {prices} WHERE price_list_id = ? AND item_id = ? AND variant_code = ?', [$list['id'], $itemId, $variant]);
            $label = '';
            $what = t('item #%d', $itemId) . ($variant !== '' ? ' (' . $variant . ')' : '');
        } else {
            $code = self::line($row['code'] ?? '', self::CODE_MAX);
            if ($code === '') {
                throw new \DomainException(t('An own row needs a code.'));
            }
            $existing = $db->one('SELECT * FROM {prices} WHERE price_list_id = ? AND item_id IS NULL AND code = ?', [$list['id'], $code]);
            $label = array_key_exists('label', $row) ? self::line($row['label'], self::LABEL_MAX) : (string) ($existing['label'] ?? '');
            if ($existing === null && $label === '') {
                throw new \DomainException(t('An own row needs a name.'));
            }
            $where = null;
            $what = $code;
        }
        $before = $existing === null ? null : self::priceRow($existing + ['currency' => $list['currency']], 'item');
        $price = Money::parse($row['unit_price'] ?? null);
        if ($price === null) {
            if ($existing === null) {
                return ['action' => 'unchanged', 'before' => null, 'after' => null];
            }
            $db->delete('prices', ['id' => (int) $existing['id']]);
            if ($log) {
                \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price', t('Internal price removed: %s', $what));
            }

            return ['action' => 'removed', 'before' => $before, 'after' => null];
        }
        $vat = array_key_exists('vat_rate', $row) ? $row['vat_rate'] : ($existing['vat_rate'] ?? null);
        $data = ['code' => $code, 'label' => $label, 'unit_price' => Money::toDecimal($price),
            'unit' => array_key_exists('unit', $row) ? self::line($row['unit'], self::UNIT_MAX) : (string) ($existing['unit'] ?? ''),
            'vat_rate' => $vat === null || (is_string($vat) && trim($vat) === '') ? null : Money::percentText(Money::percent($vat)),
            'note' => array_key_exists('note', $row) ? self::line($row['note'], self::NOTE_MAX) : (string) ($existing['note'] ?? '')];
        if ($existing !== null) {
            $same = Money::fromDecimal($existing['unit_price']) === $price && (string) $existing['code'] === $data['code'] && (string) $existing['label'] === $data['label']
                && (string) $existing['unit'] === $data['unit'] && ($existing['vat_rate'] === null ? null : Money::percentText((int) Money::fromDecimal($existing['vat_rate']))) === $data['vat_rate']
                && (string) $existing['note'] === $data['note'];
            if ($same) {
                return ['action' => 'unchanged', 'before' => $before, 'after' => $before];
            }
            $db->update('prices', $data + ['updated_at' => Clock::now(), 'updated_by' => $byUserId > 0 ? $byUserId : null], ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
            $action = 'changed';
        } else {
            $id = $db->insert('prices', ($where ?? ['price_list_id' => $list['id'], 'item_id' => null, 'variant_code' => '']) + $data + ['updated_at' => Clock::now(), 'updated_by' => $byUserId > 0 ? $byUserId : null]);
            $action = 'created';
        }
        if ($log) {
            \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price', t('Internal price changed: %s', $what));
        }
        $after = $db->one('SELECT p.*, l.currency FROM {prices} p JOIN {price_lists} l ON l.id = p.price_list_id WHERE p.id = ?', [$id]);

        return ['action' => $action, 'before' => $before, 'after' => $after === null ? null : self::priceRow($after, 'item')];
    }

    /**
     * The prices of the items in a list: item id => variant code ('' = the product) => row.
     *
     * @param list<int> $itemIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    public static function ofItems(Db $db, int $priceListId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), fn (int $id): bool => $id > 0)));
        if ($itemIds === [] || $priceListId <= 0) {
            return [];
        }
        $out = [];
        foreach ($db->all('SELECT p.*, l.currency FROM {prices} p JOIN {price_lists} l ON l.id = p.price_list_id WHERE p.price_list_id = ? AND p.item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')',
            [$priceListId, ...$itemIds]) as $r) {
            $out[(int) $r['item_id']][(string) $r['variant_code']] = self::priceRow($r, 'item');
        }

        return $out;
    }

    /**
     * The prices of one item in every list (the box on the item form): list => variant code => row.
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    public static function ofItem(Db $db, int $itemId): array
    {
        $out = [];
        try {
            foreach ($db->all('SELECT p.*, l.currency FROM {prices} p JOIN {price_lists} l ON l.id = p.price_list_id WHERE p.item_id = ?', [$itemId]) as $r) {
                $out[(int) $r['price_list_id']][(string) $r['variant_code']] = self::priceRow($r, 'item');
            }
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0123
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the own rows of a list (not products), by code */
    public static function ownRows(Db $db, int $priceListId): array
    {
        return array_map(fn (array $r): array => self::priceRow($r, 'item'),
            $db->all('SELECT p.*, l.currency FROM {prices} p JOIN {price_lists} l ON l.id = p.price_list_id WHERE p.price_list_id = ? AND p.item_id IS NULL ORDER BY p.code', [$priceListId]));
    }

    /**
     * The grid of a price list: the products of one collection (or of all products collections) with their variants and
     * prices. Filter keys: collection (idk; 0 = all products collections), search (a word in the name or code), missing
     * (only products with a row – the product or a variant – without a price). Variants that no longer exist in the item
     * but still have a price come as "gone" rows.
     *
     * @param array{collection?: int, search?: string, missing?: bool} $filter
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function grid(Db $db, int $priceListId, array $filter = [], int $page = 1, int $perPage = self::PAGE): array
    {
        $collections = self::productCollections($db);
        $chosen = (int) ($filter['collection'] ?? 0);
        $ids = array_column($chosen > 0 ? array_filter($collections, fn (array $c): bool => $c['idk'] === $chosen) : $collections, 'idk');
        if ($ids === []) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        $where = 'p.smazano IS NULL AND p.idk IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            $where .= ' AND (p.nazev LIKE ? OR p.data LIKE ?)';
            $like = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $rows = [];
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.seo_link, p.jazyk, p.zobrazit, p.data, k.preset, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE ' . $where
            . ' ORDER BY ' . $db->sortText('k.nazev') . ', p.jazyk, p.poradi, ' . $db->sortText('p.nazev') . ', p.idp', $params) as $r) {
            $rows[] = self::itemRow($r);
        }
        $prices = self::ofItems($db, $priceListId, array_column($rows, 'idp'));
        $out = [];
        foreach ($rows as $item) {
            $own = $prices[$item['idp']] ?? [];
            $lines = [['variant' => '', 'name' => '', 'code' => $item['code'], 'price' => $own[''] ?? null, 'gone' => false]];
            foreach ($item['variants'] as $v) {
                if ($v['code'] !== '') {
                    $lines[] = ['variant' => $v['code'], 'name' => $v['name'], 'code' => $v['code'], 'price' => $own[$v['code']] ?? null, 'gone' => false];
                }
            }
            foreach (array_diff(array_keys($own), array_column($lines, 'variant')) as $gone) {
                $lines[] = ['variant' => (string) $gone, 'name' => '', 'code' => (string) $gone, 'price' => $own[$gone], 'gone' => true];
            }
            if (($filter['missing'] ?? false) && array_filter($lines, fn (array $l): bool => $l['price'] === null && !$l['gone']) === []) {
                continue;
            }
            $out[] = $item + ['lines' => $lines];
        }
        $total = count($out);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));

        return ['rows' => array_slice($out, ($page - 1) * $perPage, $perPage), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /* ---------- CSV ---------- */

    /**
     * The whole price list as CSV (UTF-8 with BOM, semicolons – opens in a spreadsheet): CSV_COLUMNS, one row per price –
     * products by their code and variant, own rows by their code and name. A cell starting with = + - @ is quoted so a
     * spreadsheet never runs it. One change-log line (without amounts).
     */
    public static function exportCsv(App $app, int $priceListId): string
    {
        $db = $app->db();
        $list = self::priceList($db, $priceListId) ?? throw new \DomainException(t('The price list does not exist.'));
        $f = fopen('php://temp', 'w+');
        if ($f === false) {
            throw new \RuntimeException('php://temp');
        }
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, self::CSV_COLUMNS, ';', '"', '');
        $rows = $db->all('SELECT p.*, i.nazev FROM {prices} p LEFT JOIN {kolekce_polozky} i ON i.idp = p.item_id WHERE p.price_list_id = ? ORDER BY p.item_id IS NULL, i.nazev, p.item_id, p.variant_code, p.code', [$list['id']]);
        foreach ($rows as $r) {
            $isProduct = $r['item_id'] !== null;
            $code = $isProduct ? ($r['variant_code'] !== '' ? (string) (self::item($db, (int) $r['item_id'])['code'] ?? $r['code']) : (string) $r['code']) : (string) $r['code'];
            $cells = [$code, (string) $r['variant_code'], (string) $r['unit_price'], (string) $r['unit'], $r['vat_rate'] === null ? '' : Money::short((string) $r['vat_rate']), (string) $r['note'],
                $isProduct ? (string) ($r['nazev'] ?? '') : (string) $r['label']];
            fputcsv($f, array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v, $cells), ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price export', t('Internal prices exported: %s', $list['name']));

        return $csv;
    }

    /**
     * The rows of an uploaded CSV: the header names the columns (CSV_COLUMNS, any order; a missing optional column keeps
     * the value saved); semicolon, comma or tab; a BOM and a leading quote of a cell are dropped.
     *
     * @return list<array<string, string>> column => value, with "line" (the line number in the file)
     * @throws \DomainException
     */
    public static function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $header = '';
        while ($lines !== [] && trim($header) === '') {
            $header = (string) array_shift($lines);
        }
        $counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];
        arsort($counts);
        $separator = (string) array_key_first($counts);
        $names = array_map(fn (string $h): string => strtolower(trim($h, " \t\"'")), str_getcsv($header, $separator, '"', ''));
        if (!in_array('code', $names, true) || !in_array('price', $names, true)) {
            throw new \DomainException(t('The file needs a header with at least the columns code and price (%s).', implode(';', self::CSV_COLUMNS)));
        }
        $out = [];
        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($out) >= self::IMPORT_MAX) {
                throw new \DomainException(t('The file has more than %d rows – split it.', self::IMPORT_MAX));
            }
            $cells = str_getcsv($line, $separator, '"', '');
            $row = ['line' => (string) ($n + 2)];
            foreach ($names as $i => $name) {
                if (in_array($name, self::CSV_COLUMNS, true)) {
                    $value = trim((string) ($cells[$i] ?? ''));
                    $row[$name] = str_starts_with($value, "'") && preg_match('/^\'[=+\-@]/', $value) === 1 ? substr($value, 1) : $value;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * What an import would do, row by row, without writing: each row matched to a product (by its code and variant
     * code), an own row of the list, or nothing ("unknown"); new (no price yet), changed, the same, or refused with the
     * reason (a bad price or rate). An unknown code with a name and no variant becomes a new own row.
     *
     * @param list<array<string, string>> $rows from parseCsv()
     * @return array{new: int, changed: int, same: int, unknown: int, invalid: int, rows: list<array<string, mixed>>}
     */
    public static function importPreview(Db $db, int $priceListId, array $rows): array
    {
        $index = self::codeIndex($db);
        $counts = ['new' => 0, 'changed' => 0, 'same' => 0, 'unknown' => 0, 'invalid' => 0];
        $planned = [];
        foreach ($rows as $row) {
            $code = trim($row['code'] ?? '');
            $variant = trim($row['variant'] ?? '');
            $target = null;
            if ($variant !== '') {
                $hit = $index[mb_strtolower($variant)] ?? null;
                $target = $hit !== null && $hit['variant_code'] !== '' ? $hit : null;
            } elseif ($code !== '') {
                $own = $db->one('SELECT id FROM {prices} WHERE price_list_id = ? AND item_id IS NULL AND code = ?', [$priceListId, $code]);
                $hit = $index[mb_strtolower($code)] ?? null;
                $target = $own !== null ? ['own' => $code] : $hit;
                if ($target === null && trim($row['name'] ?? '') !== '') {
                    $target = ['own' => $code, 'label' => trim($row['name'] ?? '')];
                }
            }
            $status = 'unknown';
            $reason = '';
            if ($target !== null) {
                try {
                    $price = Money::parse($row['price'] ?? '');
                    if (isset($row['vat']) && trim($row['vat']) !== '') {
                        Money::percent($row['vat']);
                    }
                    $existing = isset($target['own'])
                        ? $db->one('SELECT * FROM {prices} WHERE price_list_id = ? AND item_id IS NULL AND code = ?', [$priceListId, $target['own']])
                        : $db->one('SELECT * FROM {prices} WHERE price_list_id = ? AND item_id = ? AND variant_code = ?', [$priceListId, $target['item_id'], $target['variant_code']]);
                    $vat = isset($row['vat']) ? (trim($row['vat']) === '' ? null : Money::percentText(Money::percent($row['vat']))) : false;
                    $same = $existing !== null && $price !== null && Money::fromDecimal($existing['unit_price']) === $price
                        && (!isset($row['unit']) || (string) $existing['unit'] === $row['unit']) && (!isset($row['note']) || (string) $existing['note'] === $row['note'])
                        && ($vat === false || ($existing['vat_rate'] === null ? null : Money::percentText((int) Money::fromDecimal($existing['vat_rate']))) === $vat);
                    $status = match (true) {
                        $price === null => $existing === null ? 'same' : 'changed',
                        $existing === null => 'new',
                        $same => 'same',
                        default => 'changed',
                    };
                } catch (\DomainException $e) {
                    $status = 'invalid';
                    $reason = $e->getMessage();
                }
            }
            $counts[$status]++;
            $planned[] = ['line' => (int) ($row['line'] ?? 0), 'code' => $code, 'variant' => $variant, 'price' => (string) ($row['price'] ?? ''), 'status' => $status, 'reason' => $reason,
                'target' => $target, 'row' => $row];
        }

        return $counts + ['rows' => $planned];
    }

    /**
     * Writes an import that importPreview() planned: the new and changed rows (an empty price removes the row); unknown
     * and refused rows are skipped. One change-log line with the counts – never an amount.
     *
     * @param list<array<string, string>> $rows from parseCsv()
     * @return array{saved: int, removed: int, skipped: int}
     */
    public static function import(App $app, int $priceListId, array $rows, int $byUserId): array
    {
        $db = $app->db();
        $list = self::priceList($db, $priceListId) ?? throw new \DomainException(t('The price list does not exist.'));
        $plan = self::importPreview($db, $list['id'], $rows);
        $result = ['saved' => 0, 'removed' => 0, 'skipped' => 0];
        $db->transaction(function () use ($app, $list, $plan, $byUserId, &$result): void {
            foreach ($plan['rows'] as $p) {
                if (!in_array($p['status'], ['new', 'changed'], true) || !is_array($p['target'])) {
                    $result['skipped'] += $p['status'] === 'same' ? 0 : 1;
                    continue;
                }
                $row = $p['row'];
                $values = ['unit_price' => $row['price'] ?? ''] + array_filter(['unit' => $row['unit'] ?? null, 'vat_rate' => $row['vat'] ?? null, 'note' => $row['note'] ?? null], fn (mixed $v): bool => $v !== null);
                $values += isset($p['target']['own']) ? ['code' => $p['target']['own']] + (isset($p['target']['label']) ? ['label' => $p['target']['label']] : [])
                    : ['item_id' => $p['target']['item_id'], 'variant_code' => $p['target']['variant_code']];
                $done = self::save($app, $list['id'], $values, $byUserId, false);
                $result[$done['action'] === 'removed' ? 'removed' : 'saved'] += in_array($done['action'], ['created', 'changed', 'removed'], true) ? 1 : 0;
            }
        });
        \Kaleta\Admin\ChangeLog::write($app, self::SECTION, 'price import', t('Internal prices imported into %1$s: %2$d saved, %3$d removed, %4$d skipped', $list['name'], $result['saved'], $result['removed'], $result['skipped']));

        return $result;
    }

    /**
     * Removes the prices of items that left the trash for good (Collections: "Delete permanently" and the trash clean-up).
     *
     * @param list<int> $itemIds
     */
    public static function forgetItems(Db $db, array $itemIds): int
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), fn (int $id): bool => $id > 0));
        if ($itemIds === []) {
            return 0;
        }
        try {
            return $db->run('DELETE FROM {prices} WHERE item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')', $itemIds)->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0123
        }
    }

    /* ---------- VAT ---------- */

    /** The default VAT rate as "21.00", or null while the administrator has not set it. */
    public static function defaultVatRate(Settings $s): ?string
    {
        $raw = trim($s->get('quote_vat_rate'));
        if ($raw === '') {
            return null;
        }
        try {
            return Money::percentText(Money::percent($raw));
        } catch (\DomainException) {
            return null; // a damaged setting – the settings screen refuses it
        }
    }

    /**
     * The rates offered on a line: the short list of the settings with the default rate, as "21.00", highest first.
     *
     * @return list<string>
     */
    public static function vatRates(Settings $s): array
    {
        $rates = [];
        foreach (preg_split('/[\s,;]+/', $s->get('quote_vat_rates')) ?: [] as $raw) {
            try {
                if (trim($raw) !== '') {
                    $rates[] = Money::percentText(Money::percent($raw));
                }
            } catch (\DomainException) {
                continue;
            }
        }
        $default = self::defaultVatRate($s);
        if ($default !== null) {
            $rates[] = $default;
        }
        $rates = array_values(array_unique($rates));
        usort($rates, fn (string $a, string $b): int => (int) Money::fromDecimal($b) <=> (int) Money::fromDecimal($a));

        return $rates;
    }

    /* ---------- helpers ---------- */

    private static function hasRight(Db $db, int $userId, string $ident): bool
    {
        return $userId > 0 && $db->value('SELECT 1 FROM {uzivatele} u WHERE idu = ? AND blokovat = 0 AND (admin = ? OR EXISTS (SELECT 1 FROM {uzivatele_prava} p WHERE p.fk_id_user = u.idu AND p.ident_modulu = ?))',
            [$userId, Auth::ADMIN, $ident]) !== null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, name: string, currency: string, is_default: bool, note: string, archived_at: ?string, prices: int}
     */
    private static function listRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'currency' => (string) $r['currency'], 'is_default' => (bool) $r['is_default'], 'note' => (string) $r['note'],
            'archived_at' => $r['archived_at'] === null ? null : (string) $r['archived_at'], 'prices' => (int) ($r['prices'] ?? 0)];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, price_list_id: int, currency: string, item_id: ?int, variant_code: string, code: string, label: string, unit_price: string, minor: int, unit: string, vat_rate: ?string, note: string, updated_at: string, matched: string}
     */
    private static function priceRow(array $r, string $matched): array
    {
        $minor = (int) Money::fromDecimal($r['unit_price']);

        return ['id' => (int) $r['id'], 'price_list_id' => (int) $r['price_list_id'], 'currency' => (string) ($r['currency'] ?? ''), 'item_id' => $r['item_id'] === null ? null : (int) $r['item_id'],
            'variant_code' => (string) $r['variant_code'], 'code' => (string) $r['code'], 'label' => (string) $r['label'], 'unit_price' => Money::toDecimal($minor), 'minor' => $minor,
            'unit' => (string) $r['unit'], 'vat_rate' => $r['vat_rate'] === null ? null : Money::percentText((int) Money::fromDecimal($r['vat_rate'])), 'note' => (string) $r['note'],
            'updated_at' => (string) $r['updated_at'], 'matched' => $matched];
    }

    /**
     * @param array<string, mixed> $r an item row joined with its collection's preset and fields
     * @return array{idp: int, idk: int, name: string, slug: string, language: string, visible: bool, code: string, variants: list<array{name: string, code: string}>}
     */
    private static function itemRow(array $r): array
    {
        $fields = Products::fields(['preset' => (string) $r['preset'], 'pole' => json_decode((string) $r['pole'], true) ?: []]);
        $data = json_decode((string) $r['data'], true) ?: [];
        $variants = $fields !== null && $fields['variants'] !== '' ? array_map(fn (array $v): array => ['name' => $v['name'], 'code' => $v['code']], Products::variants((string) ($data[$fields['variants']] ?? ''))) : [];

        return ['idp' => (int) $r['idp'], 'idk' => (int) $r['idk'], 'name' => (string) $r['nazev'], 'slug' => (string) $r['seo_link'], 'language' => (string) $r['jazyk'], 'visible' => (bool) $r['zobrazit'],
            'code' => $fields !== null && $fields['code'] !== '' ? trim((string) ($data[$fields['code']] ?? '')) : '', 'variants' => $variants];
    }

    /** One line of plain text, at most $max characters (tags and control characters out). */
    private static function line(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($text))), 0, $max);
    }
}
