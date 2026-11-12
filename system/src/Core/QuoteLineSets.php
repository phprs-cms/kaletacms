<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Saved line sets of quotes (3.40, design A12 "Release Q2"; table ka_quote_line_sets, migration 0128): the lines of a
 * draft kept under a name ("Laser with installation") and inserted into another draft in one step – in the editor, or by
 * Claude through save_quote (line_set).
 *
 *  - What is kept: for a product line the product (item and variant), the quantity, the line discount, the description
 *    and a name only when someone changed it; for an own row of the price list its code; a free line keeps everything
 *    including its price. Product and own-row prices are never kept: inserting reads them from the price list of the quote
 *    it goes into (Quotes::save → Prices::forItem / Prices::byCode), so a set never carries an old price or another
 *    currency into a quote. A product the list has no price for gets a line without a price, as everywhere.
 *  - Inserting appends the set's lines after the draft's own lines (the draft's lines keep their prices); only a draft
 *    takes them, and Quotes::MAX_LINES still holds.
 *  - A name is unique: saving under an existing name replaces that set's lines. People with the section Quotes save and
 *    insert sets; administrators rename and delete them (Quotes → Settings).
 *  - Sets hold prices of free lines – business data: never journaled (AgentJournal::PERSONAL_TABLES), never exported, and
 *    only the classes of the quotes name the table (unit 3240). The admin (Admin\QuoteExtras) writes the change log.
 */
final class QuoteLineSets
{
    /** The longest name, and the most sets a site keeps. */
    public const int NAME_MAX = 120;
    public const int MAX_SETS = 200;

    /**
     * Every set by name: id, name, how many lines, who saved it and when.
     *
     * @return list<array{id: int, name: string, lines: int, created_by: ?int, updated_at: string}>
     */
    public static function all(Db $db): array
    {
        try {
            $rows = $db->all('SELECT id, name, content, created_by, updated_at FROM {quote_line_sets} ORDER BY ' . $db->sortText('name') . ', id');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0128
        }

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'lines' => count(self::decode($r['content'])),
            'created_by' => $r['created_by'] === null ? null : (int) $r['created_by'], 'updated_at' => (string) $r['updated_at']], $rows);
    }

    /**
     * One set with its stored lines, by id or by its exact name (MCP takes either); null when there is none.
     *
     * @return array{id: int, name: string, lines: list<array<string, mixed>>, updated_at: string}|null
     */
    public static function find(Db $db, mixed $idOrName): ?array
    {
        if (!is_scalar($idOrName) || trim((string) $idOrName) === '') {
            return null;
        }
        $key = trim((string) $idOrName);
        try {
            $row = ctype_digit($key) ? $db->one('SELECT id, name, content, updated_at FROM {quote_line_sets} WHERE id = ?', [(int) $key]) : null;
            $row ??= $db->one('SELECT id, name, content, updated_at FROM {quote_line_sets} WHERE name = ?', [mb_substr($key, 0, self::NAME_MAX)]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0128
        }

        return $row === null ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'lines' => self::decode($row['content']), 'updated_at' => (string) $row['updated_at']];
    }

    /**
     * Saves the lines of a draft as a set under $name (an existing name is replaced). Returns the set's id, whether an
     * existing set was replaced and how many lines it has.
     *
     * @return array{id: int, replaced: bool, lines: int}
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function fromQuote(App $app, int $quoteId, string $name, int $byUserId): array
    {
        $db = $app->db();
        $quote = Quotes::get($db, $quoteId) ?? throw new \DomainException(t('The quote does not exist.'));
        $name = self::cleanName($name);
        if ($name === '') {
            throw new \DomainException(t('Give the line set a name.'));
        }
        if ($quote['lines'] === []) {
            throw new \DomainException(t('The quote has no lines to save as a set.'));
        }
        $lines = array_map(fn (array $l): array => self::stored($db, $l), $quote['lines']);
        $existing = $db->value('SELECT id FROM {quote_line_sets} WHERE name = ?', [$name]);
        if ($existing === null && (int) $db->value('SELECT COUNT(*) FROM {quote_line_sets}') >= self::MAX_SETS) {
            throw new \DomainException(t('A site keeps at most %d line sets – delete one in Quotes → Settings first.', self::MAX_SETS));
        }
        $now = Clock::now();
        $content = (string) json_encode($lines, JSON_UNESCAPED_UNICODE);
        if ($existing !== null) {
            $id = (int) $existing;
            $db->update('quote_line_sets', ['content' => $content, 'updated_at' => $now], ['id' => $id]);
        } else {
            $id = $db->insert('quote_line_sets', ['name' => $name, 'content' => $content, 'created_by' => $byUserId > 0 ? $byUserId : null, 'created_at' => $now, 'updated_at' => $now]);
        }

        return ['id' => $id, 'replaced' => $existing !== null, 'lines' => count($lines)];
    }

    /**
     * Inserts a set into a draft: its lines after the draft's own (whose prices stay), products and own rows priced from the
     * quote's price list now. Returns what Quotes::save returns.
     *
     * @return array{id: int, warnings: list<string>}
     * @throws \DomainException with a translated reason; nothing is saved
     */
    public static function insert(App $app, int $quoteId, int $setId, int $byUserId, bool $fromClaude = false): array
    {
        $db = $app->db();
        $quote = Quotes::get($db, $quoteId) ?? throw new \DomainException(t('The quote does not exist.'));
        $set = self::find($db, $setId) ?? throw new \DomainException(t('The line set does not exist.'));

        return Quotes::save($app, $quote['id'], ['lines' => [...array_map(self::kept(...), $quote['lines']), ...self::inputs($set)]], $byUserId, $fromClaude);
    }

    /**
     * The lines of a set as Quotes::save takes them: a product by item and variant, an own row by its code (both priced by
     * the quote's list), a free line with its own name, unit, price and VAT rate.
     *
     * @param array{lines: list<array<string, mixed>>} $set
     * @return list<array<string, mixed>>
     */
    public static function inputs(array $set): array
    {
        $out = [];
        foreach ($set['lines'] as $l) {
            $common = ['quantity' => (string) ($l['quantity'] ?? '1'), 'discount_percent' => (string) ($l['discount_percent'] ?? '0'), 'description' => (string) ($l['description'] ?? '')];
            $out[] = match ((string) ($l['kind'] ?? 'free')) {
                'product' => ['item_id' => (int) ($l['item_id'] ?? 0), 'variant_code' => (string) ($l['variant_code'] ?? '')] + $common
                    + ((string) ($l['name'] ?? '') !== '' ? ['name' => (string) $l['name']] : []),
                'own' => ['code' => (string) ($l['code'] ?? ''), 'name' => (string) ($l['name'] ?? '')] + $common,
                default => ['code' => (string) ($l['code'] ?? ''), 'name' => (string) ($l['name'] ?? ''), 'unit' => (string) ($l['unit'] ?? ''),
                    'unit_price' => isset($l['unit_price']) ? (string) $l['unit_price'] : null, 'vat_rate' => (string) ($l['vat_rate'] ?? '')] + $common,
            };
        }

        return $out;
    }

    /** Renames a set (administrators, Quotes → Settings); returns the former name. @throws \DomainException */
    public static function rename(App $app, int $id, string $name): string
    {
        $db = $app->db();
        $set = self::find($db, $id) ?? throw new \DomainException(t('The line set does not exist.'));
        $name = self::cleanName($name);
        if ($name === '') {
            throw new \DomainException(t('Give the line set a name.'));
        }
        if ($name === $set['name']) {
            return $set['name'];
        }
        if ($db->value('SELECT 1 FROM {quote_line_sets} WHERE name = ? AND id <> ?', [$name, $id]) !== null) {
            throw new \DomainException(t('Another line set is called “%s”.', $name));
        }
        $db->update('quote_line_sets', ['name' => $name, 'updated_at' => Clock::now()], ['id' => $id]);

        return $set['name'];
    }

    /** Deletes a set (administrators, Quotes → Settings); returns its name, or null when there was none. */
    public static function delete(App $app, int $id): ?string
    {
        $db = $app->db();
        $set = self::find($db, $id);
        if ($set === null) {
            return null;
        }
        $db->run('DELETE FROM {quote_line_sets} WHERE id = ?', [$id]);

        return $set['name'];
    }

    /** A name as stored: one line, at most NAME_MAX characters. */
    public static function cleanName(string $name): string
    {
        return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, self::NAME_MAX));
    }

    /**
     * A line of a quote as a set keeps it (see the class): kind product | own | free.
     *
     * @param array<string, mixed> $l a line of Quotes::get
     * @return array<string, mixed>
     */
    private static function stored(Db $db, array $l): array
    {
        $common = ['quantity' => (string) $l['quantity'], 'discount_percent' => (string) $l['discount_percent']] + ((string) $l['description'] !== '' ? ['description' => (string) $l['description']] : []);
        if ($l['item_id'] !== null) {
            $item = Prices::item($db, (int) $l['item_id']);
            $variant = $item !== null && $l['variant_code'] !== '' ? array_values(array_filter($item['variants'], fn (array $v): bool => $v['code'] === $l['variant_code']))[0] ?? null : null;
            $productName = $item === null ? '' : $item['name'] . ($variant !== null && $variant['name'] !== '' ? ' – ' . $variant['name'] : '');

            return ['kind' => 'product', 'item_id' => (int) $l['item_id'], 'variant_code' => (string) $l['variant_code']] + $common
                + ((string) $l['name'] !== $productName ? ['name' => (string) $l['name']] : []);
        }
        if ((string) $l['code'] !== '' && $l['list_price'] !== null) {
            return ['kind' => 'own', 'code' => (string) $l['code'], 'name' => (string) $l['name']] + $common;
        }

        return ['kind' => 'free', 'code' => (string) $l['code'], 'name' => (string) $l['name'], 'unit' => (string) $l['unit'], 'unit_price' => $l['unit_price'], 'vat_rate' => (string) $l['vat_rate']] + $common;
    }

    /**
     * A line of a draft as Quotes::save takes it back unchanged (its price, list price, name and rate stay).
     *
     * @param array<string, mixed> $l a line of Quotes::get
     * @return array<string, mixed>
     */
    private static function kept(array $l): array
    {
        return ['id' => $l['id'], 'name' => $l['name'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price' => $l['unit_price'],
            'discount_percent' => $l['discount_percent'], 'vat_rate' => $l['vat_rate']]
            + ($l['item_id'] !== null ? ['item_id' => $l['item_id'], 'variant_code' => $l['variant_code']] : ['code' => $l['code']]);
    }

    /** @return list<array<string, mixed>> the stored lines of a set */
    private static function decode(mixed $content): array
    {
        $lines = json_decode((string) $content, true);

        return is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];
    }
}
