<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Money arithmetic of quotes and internal prices (3.38, design A3) in whole numbers only – no floats, no bcmath (not every
 * shared hosting has it), no GMP.
 *
 *  - An amount is an int of minor units (cents): "12 500,50" = 1250050. A quantity is an int of thousandths (DECIMAL(10,3)),
 *    a percentage (discount, VAT rate) an int of hundredths of a per cent – basis points (DECIMAL(5,2)): 21 % = 2100.
 *  - A line: round(quantity × unit price × (1 − line discount)) – one rounding, half up, to the minor unit. The whole-quote
 *    discount is applied to each line's net amount (rounded per line). VAT is computed per rate on the sum of that rate's
 *    lines, rounded half up. No rounding to whole units.
 *  - VAT modes: standard (VAT per rate), reverse_charge (the rates print as 0, no VAT – the customer accounts for it), none
 *    (the supplier is not a VAT payer: no VAT at all). One quote has one currency – no exchange rates.
 *
 * Products of two ints that could pass PHP_INT_MAX are split (mulDiv), so every case is exact; an amount a DECIMAL(14,2)
 * column cannot hold is refused. Parsing messages are translated (t()).
 */
final class Money
{
    /** The VAT modes of a quote. */
    public const array VAT_MODES = ['standard', 'reverse_charge', 'none'];

    /** The largest unit price (DECIMAL(12,2)) and total (DECIMAL(14,2)) in minor units. */
    public const int PRICE_MAX = 999999999999;
    public const int TOTAL_MAX = 99999999999999;

    /** The largest quantity (DECIMAL(10,3)) in thousandths. */
    public const int QUANTITY_MAX = 9999999999;

    /**
     * A price typed by a person or Claude, in minor units: null for none ('' or null); "12 500,50", "12500.50", "12,500.50"
     * and "12.500,50" are the same. Refused when it is not a number, negative, has more than two decimals or is too large.
     *
     * @throws \DomainException
     */
    public static function parse(mixed $raw): ?int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (is_int($raw)) {
            $raw = (string) $raw;
        } elseif (is_float($raw)) {
            $raw = number_format($raw, 2, '.', ''); // MCP JSON numbers: 12.5 → "12.50"
        }
        if (!is_string($raw)) {
            throw new \DomainException(t('The price must be a number.'));
        }
        $text = self::normalise($raw);
        if (str_starts_with($text, '-')) {
            throw new \DomainException(t('The price cannot be negative.'));
        }
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', $text, $m) !== 1) {
            throw new \DomainException(t('The price must be a number with at most two decimals.'));
        }
        $minor = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($minor > self::PRICE_MAX) {
            throw new \DomainException(t('The price is too large.'));
        }

        return $minor;
    }

    /**
     * A quantity in thousandths: '' or null = 1 piece (1000); "2", "2,5", "0.125". Refused when not a positive number with
     * at most three decimals.
     *
     * @throws \DomainException
     */
    public static function quantity(mixed $raw): int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return 1000;
        }
        $text = is_int($raw) ? (string) $raw : (is_float($raw) ? rtrim(rtrim(number_format($raw, 3, '.', ''), '0'), '.') : (is_string($raw) ? self::normalise($raw, false) : ''));
        if (preg_match('/^(\d{1,7})(?:\.(\d{1,3}))?$/D', $text, $m) !== 1) {
            throw new \DomainException(t('The quantity must be a number with at most three decimals.'));
        }
        $milli = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
        if ($milli <= 0) {
            throw new \DomainException(t('The quantity must be more than zero.'));
        }

        return $milli;
    }

    /**
     * A percentage (a discount or a VAT rate) in hundredths of a per cent: '' or null = 0; "5", "12,5", "21.00". Refused when
     * it is not between 0 and 100 with at most two decimals.
     *
     * @throws \DomainException
     */
    public static function percent(mixed $raw): int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return 0;
        }
        $text = is_int($raw) ? (string) $raw : (is_float($raw) ? number_format($raw, 2, '.', '') : (is_string($raw) ? str_replace('%', '', self::normalise($raw, false)) : ''));
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', $text, $m) !== 1) {
            throw new \DomainException(t('A percentage must be a number from 0 to 100 with at most two decimals.'));
        }
        $points = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($points > 10000) {
            throw new \DomainException(t('A percentage must be a number from 0 to 100 with at most two decimals.'));
        }

        return $points;
    }

    /** A DECIMAL value as the database returns it ("12.50", "-3.00", null) in minor units; null stays null. */
    public static function fromDecimal(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_int($value) ? (string) $value : (is_float($value) ? number_format($value, 2, '.', '') : (string) $value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/D', $text, $m) !== 1) {
            return null;
        }
        $minor = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$minor : $minor;
    }

    /** Minor units as a DECIMAL text for the database and MCP: 1250050 → "12500.50". */
    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign . intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Thousandths as a DECIMAL(10,3) text: 2500 → "2.500". */
    public static function quantityText(int $milli): string
    {
        return intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    /** Basis points as a DECIMAL(5,2) text: 2100 → "21.00". */
    public static function percentText(int $points): string
    {
        return intdiv($points, 100) . '.' . str_pad((string) ($points % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A quantity, a rate or a discount for people: "2", "2.5", "12.5" – trailing zeros left out. */
    public static function short(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /**
     * The net amount of a line in minor units: round(quantity × unit price × (1 − discount)), half up, one rounding.
     *
     * @throws \DomainException when the line is larger than a total can hold
     */
    public static function lineNet(int $unitPrice, int $quantityMilli, int $discountPoints = 0): int
    {
        if ($unitPrice < 0 || $quantityMilli < 0 || $discountPoints < 0 || $discountPoints > 10000) {
            throw new \DomainException(t('The line cannot be computed.'));
        }
        if ($unitPrice !== 0 && $quantityMilli > intdiv(PHP_INT_MAX, $unitPrice)) {
            throw new \DomainException(t('The line is too large.'));
        }
        $net = self::mulDiv($unitPrice * $quantityMilli, 10000 - $discountPoints, 1000 * 10000);
        if ($net > self::TOTAL_MAX) {
            throw new \DomainException(t('The line is too large.'));
        }

        return $net;
    }

    /** A net amount after a discount in basis points, rounded half up: the whole-quote discount on one line. */
    public static function discounted(int $net, int $discountPoints): int
    {
        return $discountPoints <= 0 ? $net : self::mulDiv($net, 10000 - min(10000, $discountPoints), 10000);
    }

    /** VAT of a net amount at a rate in basis points, rounded half up. */
    public static function vat(int $net, int $ratePoints): int
    {
        return $ratePoints <= 0 ? 0 : self::mulDiv($net, $ratePoints, 10000);
    }

    /**
     * The totals of a quote in minor units. $lines: [{net: int (line net after its own discount, null = unpriced), rate:
     * int basis points}]; the whole-quote discount is applied to each line, VAT per rate on the sum of its lines. The modes
     * reverse_charge and none carry no VAT (rates: each rate's net with VAT 0; reverse charge reports the rates as 0).
     *
     * @param list<array{net: ?int, rate: int}> $lines
     * @return array{net: int, vat: int, gross: int, rates: list<array{rate: int, net: int, vat: int}>, unpriced: int}
     * @throws \DomainException when a total is larger than the database can hold
     */
    public static function totals(array $lines, int $discountPoints, string $vatMode): array
    {
        $byRate = [];
        $unpriced = 0;
        foreach ($lines as $line) {
            if ($line['net'] === null) {
                $unpriced++;
                continue;
            }
            $rate = $vatMode === 'standard' ? max(0, $line['rate']) : 0;
            $byRate[$rate] = ($byRate[$rate] ?? 0) + self::discounted($line['net'], $discountPoints);
        }
        krsort($byRate);
        $rates = [];
        $net = 0;
        $vat = 0;
        foreach ($byRate as $rate => $sum) {
            $tax = $vatMode === 'standard' ? self::vat($sum, $rate) : 0;
            $rates[] = ['rate' => $rate, 'net' => $sum, 'vat' => $tax];
            $net += $sum;
            $vat += $tax;
        }
        if ($net + $vat > self::TOTAL_MAX) {
            throw new \DomainException(t('The total of the quote is too large.'));
        }

        return ['net' => $net, 'vat' => $vat, 'gross' => $net + $vat, 'rates' => $rates, 'unpriced' => $unpriced];
    }

    /** "18 000 Kč", "€1,250.50" – an amount with its currency in the current language (Deals::formatValue). */
    public static function format(?int $minor, string $currency): string
    {
        return $minor === null ? '' : Deals::formatValue(self::toDecimal($minor), $currency);
    }

    /**
     * round($a × $b / $d) half up for non-negative ints without overflow: $a × $b is split as ($q × $d + $r) × $b / $d,
     * so only $r × $b (below $d × $b) has to fit.
     */
    public static function mulDiv(int $a, int $b, int $d): int
    {
        $q = intdiv($a, $d);
        $r = $a % $d;

        return $q * $b + intdiv(2 * $r * $b + $d, 2 * $d);
    }

    /**
     * "12 500,50", "12.500,50", "12,500.50" → "12500.50" – the rules of Deals::value: spaces of all kinds and apostrophes
     * go; dots group thousands only before a decimal comma. $grouped = false (quantities, percentages): a comma is a decimal
     * point and nothing groups thousands ("1.250" pieces stay one and a quarter).
     */
    private static function normalise(string $raw, bool $grouped = true): string
    {
        $text = str_replace([' ', "\u{00A0}", "\u{202F}", "'"], '', trim($raw));
        if ($grouped && preg_match('/^-?\d{1,3}(\.\d{3})+,\d{1,2}$/D', $text) === 1) {
            return str_replace(['.', ','], ['', '.'], $text); // 12.500,50
        }
        if ($grouped && preg_match('/^-?\d{1,3}(,\d{3})+(\.\d{1,2})?$/D', $text) === 1) {
            return str_replace(',', '', $text); // 12,500.50
        }

        return str_replace(',', '.', $text);
    }
}
