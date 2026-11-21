<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.49 (from Core\Quotes): the arithmetic and the line input of a quote – the totals (Core\Money, integer minor units, VAT
 * per rate), the discounts (a line's, the whole quote's, a price below the list), and one line as stored from what a person
 * or Claude gave (the product, its list price, the caps of quote_max_discount). It knows nothing of the customer or the
 * status, so 3.53 invoices can call it. Core\Quotes keeps forwards under the old names.
 */
final class QuoteLines
{
    /** The longest values of a line. */
    public const int NAME_MAX = 250;
    public const int DESCRIPTION_MAX = 1000;

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

    /**
     * One line as stored: the product (item_id, or a code found in the price list or among the products) with its name,
     * code and list price; the quantity, unit, discount and VAT rate; the price given or the list price; line_net.
     *
     * @param array{id: int, currency: string} $list
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $stored the line it replaces (keeps its list price)
     * @param list<string> $warnings
     * @return array<string, mixed>
     *
     * @internal Core\Quotes::save() prepares its lines here
     */
    public static function prepareLine(Db $db, Settings $s, array $list, array $input, ?array $stored, bool $fromClaude, array &$warnings, int $n): array
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
        if ($fromClaude && $itemId <= 0 && $price === null && self::namesProduct($db, $list['id'], $name, $code)) {
            // 3.44 review M2: a free line named as a product has no list price to compare – the discount cap and the VAT rate would not apply
            throw new \DomainException(t('Line %1$d: “%2$s” is a product of the price list – give its item or code so its list price applies.', $n, $name));
        }
        $quantity = Money::quantity($input['quantity'] ?? ($stored['quantity'] ?? null));
        $discount = Money::percent($input['discount_percent'] ?? ($stored['discount_percent'] ?? null));
        // 3.44 (N143): Claude's price below the list price is a discount by another name – it counts against the same cap
        $implied = $fromClaude && array_key_exists('unit_price', $input) ? self::impliedDiscount($given, $listMinor) : 0;
        if ($implied > 0 && self::combinedDiscount($implied, $discount) > self::maxDiscount($s)) {
            throw new \DomainException(t('Line %1$d: the price is more than %2$s %% below the price list – ask a person to set it in the admin.', $n, Money::short(Money::percentText(self::maxDiscount($s)))));
        }
        self::checkDiscount($discount, $s, $fromClaude);
        $listRate = Money::percent($price['vat_rate'] ?? ($stored['vat_rate'] ?? Prices::defaultVatRate($s)));
        $rate = array_key_exists('vat_rate', $input) && !(is_string($input['vat_rate']) && trim($input['vat_rate']) === '') && $input['vat_rate'] !== null
            ? Money::percent($input['vat_rate']) : $listRate;
        if ($fromClaude && $listMinor !== null && $rate < $listRate && $s->bool('quote_vat_payer')) {
            // 3.44 (N143): a lower VAT rate than the price list's lowers what the customer pays – a person decides it in the admin
            throw new \DomainException(t('Line %d: a lower VAT rate than the price list has – ask a person to set it in the admin.', $n));
        }

        return ['id' => $stored['id'] ?? null, 'item_id' => $itemId > 0 ? $itemId : null, 'variant_code' => $variant, 'code' => $code, 'name' => $name,
            'description' => self::line($input['description'] ?? ($stored['description'] ?? ''), self::DESCRIPTION_MAX),
            'quantity' => Money::quantityText($quantity), 'unit' => array_key_exists('unit', $input) ? self::line($input['unit'], Prices::UNIT_MAX) : ($stored['unit'] ?? $unit),
            'unit_price' => $given === null ? null : Money::toDecimal($given), 'list_price' => $listMinor === null ? null : Money::toDecimal($listMinor),
            'discount_percent' => Money::percentText($discount), 'vat_rate' => Money::percentText($rate),
            'line_net' => Money::toDecimal($given === null ? 0 : Money::lineNet($given, $quantity, $discount)), 'implied_discount' => $implied];
    }

    /**
     * 3.44 review M2: does a free line's name (or its part before " – ", a variant) or code name a product of a products
     * collection (the preset or mapped) or an own row of the price list? Claude must then give the item or the code.
     */
    private static function namesProduct(Db $db, int $priceListId, string $name, string $code): bool
    {
        $names = array_values(array_unique(array_filter([$name, trim(explode(' – ', $name)[0])])));
        if ($code !== '' && Prices::findCode($db, $code) !== null) {
            return true;
        }
        $collections = array_column(Prices::productCollections($db), 'idk');
        $marks = implode(', ', array_fill(0, count($names), '?'));
        if ($collections !== [] && $db->value('SELECT 1 FROM {kolekce_polozky} WHERE idk IN (' . implode(', ', array_map('intval', $collections)) . ") AND smazano IS NULL AND nazev IN ($marks) LIMIT 1", $names) !== null) {
            return true;
        }
        try {
            return $db->value("SELECT 1 FROM {prices} WHERE price_list_id = ? AND item_id IS NULL AND (label IN ($marks)" . ($code !== '' ? ' OR code = ?' : '') . ') LIMIT 1',
                [$priceListId, ...$names, ...($code !== '' ? [$code] : [])]) !== null;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return false;
        }
    }

    /**
     * 3.44 (N143, pure): the discount a price below the list price amounts to, in basis points rounded up (a product listed
     * at 10 000 quoted at 7 999 = 20.01 %); 0 without a list price or at or above it.
     */
    public static function impliedDiscount(?int $price, ?int $list): int
    {
        if ($price === null || $list === null || $list <= 0 || $price >= $list) {
            return 0;
        }

        return 10000 - intdiv(max(0, $price) * 10000, $list);
    }

    /**
     * A discount above quote_max_discount is refused over MCP: a person sets it in the admin. $line > 0: the combined discount
     * of that line (3.38 review L4). @throws \DomainException
     *
     * @internal Core\Quotes::save() checks the whole-quote discount here
     */
    public static function checkDiscount(int $points, Settings $s, bool $fromClaude, int $line = 0): void
    {
        $max = self::maxDiscount($s);
        if ($fromClaude && $points > $max) {
            throw new \DomainException($line > 0
                ? t('Line %1$d: its discount and the quote\'s discount together are above %2$s %% – ask a person to set it in the admin.', $line, Money::short(Money::percentText($max)))
                : t('A discount above %s %% – ask a person to set it in the admin.', Money::short(Money::percentText($max))));
        }
    }

    /** The highest discount Claude may give, in basis points (quote_max_discount, default 20 %). */
    private static function maxDiscount(Settings $s): int
    {
        return Money::percent(trim($s->get('quote_max_discount')) !== '' ? $s->get('quote_max_discount') : '20');
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

    /**
     * Is a line's price manual: priced without a list price, or different from it?
     *
     * @internal Core\Quotes::get() and save() mark manual prices with it
     */
    public static function isManual(?int $price, ?int $list): bool
    {
        return $price !== null && $price !== $list;
    }

    /**
     * One line of plain text, at most $max characters (tags and control characters out).
     *
     * @internal the texts of Core\Quotes are cleaned the same way
     */
    public static function line(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($text))), 0, $max);
    }

    /**
     * A text of several lines, plain, at most $max characters.
     *
     * @internal the texts of Core\Quotes are cleaned the same way
     */
    public static function text(mixed $value, int $max): string
    {
        $text = is_scalar($value) ? str_replace("\r\n", "\n", (string) $value) : '';

        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', strip_tags($text))), 0, $max);
    }
}
