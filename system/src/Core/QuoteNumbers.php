<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.49 (from Core\Quotes): the numbering of quotes (design A-7) – the format (quote_number_format: {YYYY} or {YY}, one
 * counter {N…}, an optional prefix), the number made from it (pure), and the counter per year in ka_quote_counters taken
 * with SELECT … FOR UPDATE inside the caller's transaction (Quotes::markSent). Core\Quotes keeps forwards under the old names.
 */
final class QuoteNumbers
{
    public const int NUMBER_MAX = 24;

    /** The default numbering (design A-7). */
    public const string NUMBER_FORMAT = '{YYYY}-{NNNN}';

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
}
