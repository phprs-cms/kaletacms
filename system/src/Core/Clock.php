<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The site's clock (3.10, docs/design/4.0-data-model.md §6 step 1): one place for "now" and for converting moments to and
 * from DATETIME columns.
 *
 * In 3.x the database holds the site's wall time (the time_zone setting, App::applyTimezone), so every method returns what
 * date('Y-m-d H:i:s') did – nothing changes yet. New code writes through Clock and passes Clock::now() as a parameter
 * instead of NOW() in SQL; when a later release stores instants in UTC, the change is made here and in the converted tables,
 * not in every caller. Civil times (a booking at 10:00, opening hours, the days of statistics) stay local and do not need it.
 */
final class Clock
{
    /** The form of a DATETIME column. */
    public const string FORMAT = 'Y-m-d H:i:s';

    /** Now on the site's clock, in the form of a DATETIME column. */
    public static function now(): string
    {
        return date(self::FORMAT);
    }

    /** Today on the site's clock (a DATE column). */
    public static function today(): string
    {
        return date('Y-m-d');
    }

    /** Now in UTC, in the form of a DATETIME column – for what leaves the site (APIs, signatures) and for checks. */
    public static function nowUtc(): string
    {
        return gmdate(self::FORMAT);
    }

    /** A moment relative to now on the site's clock ('-1 hour', '+30 days'), for comparisons that would use NOW() - INTERVAL. */
    public static function at(string $modifier): string
    {
        return self::toDb(new \DateTimeImmutable($modifier, self::zone()));
    }

    /**
     * Now minus whole months on the site's clock, as NOW() - INTERVAL n MONTH counts them: a day the shorter month does not
     * have becomes its last day (31 March minus one month = 28 February, where PHP's '-1 month' would give 3 March).
     */
    public static function monthsAgo(int $months): string
    {
        $now = new \DateTimeImmutable('now', self::zone());
        $month = $now->modify('first day of this month')->modify('-' . $months . ' months');

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min((int) $now->format('j'), (int) $month->format('t')))->format(self::FORMAT);
    }

    /** A day relative to today on the site's clock ('-14 days', '+30 days'), for comparisons that would use CURDATE() - INTERVAL. */
    public static function day(string $modifier): string
    {
        return (new \DateTimeImmutable('today ' . $modifier, self::zone()))->format('Y-m-d');
    }

    /** The site's time zone (PHP's default zone, which App::applyTimezone sets from the time_zone setting). */
    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(date_default_timezone_get());
    }

    /** The site's UTC offset now ('+02:00'): what each database connection sets as its session time_zone (Db::pdo). */
    public static function offset(): string
    {
        return date('P');
    }

    /** A moment (a date object or a Unix time) in the form of a DATETIME column on the site's clock. */
    public static function toDb(\DateTimeInterface|int $moment): string
    {
        $moment = is_int($moment) ? (new \DateTimeImmutable('@' . $moment)) : \DateTimeImmutable::createFromInterface($moment);

        return $moment->setTimezone(self::zone())->format(self::FORMAT);
    }

    /** A DATETIME (or DATE) value read from the database as a moment in the site's zone; null for an empty, zero or invalid value. */
    public static function fromDb(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, self::zone())
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::zone());

        return $moment !== false && \DateTimeImmutable::getLastErrors() === false ? $moment : null;
    }
}
