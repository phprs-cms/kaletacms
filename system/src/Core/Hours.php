<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Front\Company;

/**
 * Opening hours with exceptions (2.10). The week stays in Business details (company_hours, as people write it); the
 * exceptions – holidays, a closed day, shorter hours – are dated rows in ka_hours_exceptions. From both the site knows
 * whether it is open now, the hours of today, shows a notice bar a few days ahead until an exception ends, and adds the
 * exceptions to the structured data (specialOpeningHoursSpecification).
 *
 * Times are in the site's time zone. The pure functions take the week, the exceptions and "now", so they are tested
 * without a database.
 *
 * A Claude connection limited to drafts saves an exception as PROPOSED (3.2, column proposed): exceptions() and find()
 * never return it, so the hours, the notice bar, the structured data, the Google sync, bookings and the door sign ignore
 * it until a person applies it (apply) or discards it (discard); proposed() lists them for the admin and for Claude.
 */
final class Hours
{
    public const array DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** A range of hours: 9:00-12:00 (also 9-12, 9.00–12.00). */
    private const string RANGE = '/^(\d{1,2})(?:[:.](\d{2}))?\s*[-–—]\s*(\d{1,2})(?:[:.](\d{2}))?$/u';

    /**
     * The regular week from the settings: day => ranges [opens, closes] ("09:00", "17:00").
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function week(Settings $s): array
    {
        $week = array_fill_keys(self::DAYS, []);
        foreach (Company::parseOpeningHours($s->get('company_hours')) ?? [] as $row) {
            foreach ($row['dny'] as $day) {
                $week[$day][] = [$row['od'], $row['do']];
            }
        }

        return $week;
    }

    /**
     * Exceptions that have not ended yet (or all of them), the nearest first.
     *
     * @return list<array{id: int, from: string, to: string, closed: bool, hours: string, note: string, notice_days: int}>
     */
    public static function exceptions(Db $db, bool $pastToo = false): array
    {
        try {
            $rows = $db->all('SELECT * FROM {hours_exceptions} WHERE proposed = 0' . ($pastToo ? '' : ' AND date_to >= ?') . ' ORDER BY date_from, id LIMIT 200', $pastToo ? [] : [Clock::today()]);
        } catch (\Throwable) {
            return []; // before the 2.10 migration
        }

        return array_map(self::row(...), $rows);
    }

    /**
     * One exception by its id (past ones too – a sign may be printed after the fact), null when it does not exist.
     * Applied exceptions only, unless $proposed asks for a proposed one (3.2).
     *
     * @return array{id: int, from: string, to: string, closed: bool, hours: string, note: string, notice_days: int}|null
     */
    public static function find(Db $db, int $id, bool $proposed = false): ?array
    {
        try {
            $row = $id > 0 ? $db->one('SELECT * FROM {hours_exceptions} WHERE id = ? AND proposed = ' . ($proposed ? 1 : 0), [$id]) : null;
        } catch (\Throwable) {
            return null; // before the 2.10 migration
        }

        return $row === null ? null : self::row($row);
    }

    /**
     * Proposed exceptions that have not ended yet (3.2): saved by a drafts-only Claude connection, waiting for a person.
     *
     * @return list<array{id: int, from: string, to: string, closed: bool, hours: string, note: string, notice_days: int}>
     */
    public static function proposed(Db $db): array
    {
        try {
            return array_map(self::row(...), $db->all('SELECT * FROM {hours_exceptions} WHERE proposed = 1 AND date_to >= ? ORDER BY date_from, id LIMIT 200', [Clock::today()]));
        } catch (\Throwable) {
            return []; // before the 3.2 migration
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, from: string, to: string, closed: bool, hours: string, note: string, notice_days: int}
     */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'from' => (string) $r['date_from'], 'to' => (string) $r['date_to'], 'closed' => (int) $r['closed'] === 1,
            'hours' => (string) $r['hours'], 'note' => (string) $r['note'], 'notice_days' => (int) $r['notice_days']];
    }

    /**
     * Ranges of hours given as text ("9:00-12:00, 13-17"); null when one of them is not a range.
     *
     * @return list<array{0: string, 1: string}>|null
     */
    public static function parseRanges(string $text): ?array
    {
        $out = [];
        foreach (preg_split('/\s*[,;]\s*/', trim($text)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match(self::RANGE, $part, $m) !== 1 || (int) $m[1] > 24 || (int) $m[3] > 24) {
                return null;
            }
            $out[] = [sprintf('%02d:%s', $m[1], $m[2] !== '' ? $m[2] : '00'), sprintf('%02d:%s', $m[3], ($m[4] ?? '') !== '' ? $m[4] : '00')];
        }

        return $out;
    }

    /**
     * The hours of one day: the exception that covers it, or the regular week.
     *
     * @param array<string, list<array{0: string, 1: string}>> $week
     * @param list<array<string, mixed>> $exceptions
     * @return array{ranges: list<array{0: string, 1: string}>, exception: ?array<string, mixed>}
     */
    public static function day(array $week, array $exceptions, \DateTimeImmutable $date): array
    {
        $ymd = $date->format('Y-m-d');
        foreach ($exceptions as $e) {
            if ($ymd >= $e['from'] && $ymd <= $e['to']) {
                return ['ranges' => $e['closed'] ? [] : (self::parseRanges((string) $e['hours']) ?? []), 'exception' => $e];
            }
        }

        return ['ranges' => $week[$date->format('l')] ?? [], 'exception' => null];
    }

    /**
     * Open now? Until when, or when it opens next (within two weeks).
     *
     * @param array<string, list<array{0: string, 1: string}>> $week
     * @param list<array<string, mixed>> $exceptions
     * @return array{open: bool, until: ?string, next: ?\DateTimeImmutable, exception: ?array<string, mixed>}
     */
    public static function status(array $week, array $exceptions, \DateTimeImmutable $now): array
    {
        $today = self::day($week, $exceptions, $now);
        $time = $now->format('H:i');
        foreach ($today['ranges'] as [$opens, $closes]) {
            if ($time >= $opens && $time < $closes) {
                return ['open' => true, 'until' => $closes, 'next' => null, 'exception' => $today['exception']];
            }
        }
        for ($i = 0; $i <= 14; $i++) {
            $date = $now->setTime(0, 0)->modify('+' . $i . ' days');
            foreach (self::day($week, $exceptions, $date)['ranges'] as [$opens]) {
                $at = $date->setTime((int) substr($opens, 0, 2), (int) substr($opens, 3, 2));
                if ($at > $now) {
                    return ['open' => false, 'until' => null, 'next' => $at, 'exception' => $today['exception']];
                }
            }
        }

        return ['open' => false, 'until' => null, 'next' => null, 'exception' => $today['exception']];
    }

    /** "Open now, until 17:00" / "Closed now, opens tomorrow at 8:00" in the site language ('' without opening hours). */
    public static function statusText(App $app, ?\DateTimeImmutable $now = null): string
    {
        $week = self::week($app->settings());
        $exceptions = self::exceptions($app->db());
        if (array_merge(...array_values($week)) === [] && $exceptions === []) {
            return '';
        }
        $now ??= new \DateTimeImmutable();
        $st = self::status($week, $exceptions, $now);
        $note = $st['exception'] !== null && $st['exception']['note'] !== '' ? ' (' . $st['exception']['note'] . ')' : '';
        if ($st['open']) {
            return t('Open now, until %s', self::time((string) $st['until'])) . $note;
        }
        if ($st['next'] === null) {
            return t('Closed now') . $note;
        }
        $days = (int) $now->setTime(0, 0)->diff($st['next']->setTime(0, 0))->days;
        $at = self::time($st['next']->format('H:i'));

        return match (true) {
            $days === 0 => t('Closed now, opens today at %s', $at),
            $days === 1 => t('Closed now, opens tomorrow at %s', $at),
            default => t('Closed now, opens on %s at %s', format_date($st['next']), $at),
        } . $note;
    }

    /** The hours of today as text: "8:00–12:00, 13:00–17:00", or "closed". */
    public static function todayText(App $app, ?\DateTimeImmutable $now = null): string
    {
        $day = self::day(self::week($app->settings()), self::exceptions($app->db()), $now ?? new \DateTimeImmutable());

        return $day['ranges'] === [] ? t('closed') : self::rangesText($day['ranges']);
    }

    /**
     * Ranges of hours for people: "8:00–12:00, 13:00–17:00" (a text with ranges is parsed first; an invalid one gives '').
     *
     * @param list<array{0: string, 1: string}>|string $ranges
     */
    public static function rangesText(array|string $ranges): string
    {
        $ranges = is_string($ranges) ? (self::parseRanges($ranges) ?? []) : $ranges;

        return implode(', ', array_map(fn (array $r): string => self::time($r[0]) . '–' . self::time($r[1]), $ranges));
    }

    /** One exception for people: "24. 12. – 26. 12.: closed (Christmas)". */
    public static function describe(array $e): string
    {
        $dates = $e['from'] === $e['to'] ? format_date($e['from']) : format_date($e['from']) . ' – ' . format_date($e['to']);
        $what = $e['closed'] ? t('closed') : self::rangesText((string) $e['hours']);

        return $dates . ': ' . $what . ($e['note'] !== '' ? ' (' . $e['note'] . ')' : '');
    }

    /**
     * The exceptions the notice bar shows today: from notice_days before the start until the end.
     *
     * @param list<array<string, mixed>> $exceptions
     * @return list<array<string, mixed>>
     */
    public static function noticed(array $exceptions, \DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');

        return array_values(array_filter($exceptions, fn (array $e): bool => $e['notice_days'] > 0 && $today <= $e['to']
            && $today >= (new \DateTimeImmutable($e['from']))->modify('-' . $e['notice_days'] . ' days')->format('Y-m-d')));
    }

    /** The notice bar for the top of the site ('' when nothing is coming). */
    public static function noticeBar(App $app): string
    {
        $noticed = self::noticed(self::exceptions($app->db()), new \DateTimeImmutable());
        if ($noticed === []) {
            return '';
        }

        return '<div class="ka-oznameni-hodiny" role="note"><p>' . implode('<br>', array_map(fn (array $e): string => e(t('Opening hours') . ' ' . self::describe($e)), $noticed)) . '</p></div>';
    }

    /**
     * Opening hours written as text – one rule per line, as in Business details ("Mo-Fr 9-17") – for schema.org:
     * OpeningHoursSpecification rows with the days, opens and closes. [] when the text is empty or a line does not parse
     * (Company::parseOpeningHours): rather no hours than wrong ones. Shared by the company and a branch (2.11).
     *
     * @return list<array<string, mixed>>
     */
    public static function specification(string $text): array
    {
        return array_map(fn (array $h): array => ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $h['dny'], 'opens' => $h['od'], 'closes' => $h['do']],
            Company::parseOpeningHours($text) ?? []);
    }

    /**
     * Exceptions for schema.org (OpeningHoursSpecification with validFrom/validThrough; closed = 00:00–00:00).
     *
     * @param list<array<string, mixed>> $exceptions
     * @return list<array<string, mixed>>
     */
    public static function schema(array $exceptions): array
    {
        $out = [];
        foreach ($exceptions as $e) {
            foreach ($e['closed'] ? [['00:00', '00:00']] : (self::parseRanges((string) $e['hours']) ?? []) as [$opens, $closes]) {
                $out[] = ['@type' => 'OpeningHoursSpecification', 'opens' => $opens, 'closes' => $closes, 'validFrom' => $e['from'], 'validThrough' => $e['to']];
            }
        }

        return $out;
    }

    /**
     * Saves an exception; returns null, or the error. proposed (3.2) saves it as a proposal the site ignores until a person
     * applies it; saving an existing proposal without it applies it.
     *
     * @param array{from?: string, to?: string, closed?: bool, hours?: string, note?: string, notice_days?: int, proposed?: bool} $data
     */
    public static function save(App $app, array $data, int $id = 0): ?string
    {
        $from = (string) ($data['from'] ?? '');
        $to = (string) (($data['to'] ?? '') !== '' ? $data['to'] : $from);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || strtotime($from) === false || strtotime($to) === false || $to < $from) {
            return 'Enter the dates as YYYY-MM-DD; the last day must not be before the first one.';
        }
        $closed = (bool) ($data['closed'] ?? true);
        $hours = trim((string) ($data['hours'] ?? ''));
        if (!$closed && (self::parseRanges($hours) ?? []) === []) {
            return 'Enter the hours of the exception, e.g. 9:00-12:00 (more ranges with a comma), or mark the days as closed.';
        }
        $row = ['date_from' => $from, 'date_to' => $to, 'closed' => $closed ? 1 : 0, 'hours' => $closed ? '' : mb_substr($hours, 0, 100),
            'note' => mb_substr(trim(strip_tags((string) ($data['note'] ?? ''))), 0, 150), 'notice_days' => max(0, min(60, (int) ($data['notice_days'] ?? 7))),
            'proposed' => !empty($data['proposed']) ? 1 : 0];
        $db = $app->db();
        if ($id > 0) {
            $db->update('hours_exceptions', $row, ['id' => $id]);
        } else {
            $db->insert('hours_exceptions', $row + ['created_at' => date('Y-m-d H:i:s')]);
        }
        \Kaleta\Admin\ChangeLog::write($app, 'settings', $row['proposed'] === 1 ? 'hours_exception_proposed' : 'hours_exception', $from . '–' . $to);
        if ($row['proposed'] === 0) {
            \Kaleta\Front\Cache::clear();
            GoogleBusiness::hoursChanged($app); // the Business Profile gets the exception (2.13)
        }

        return null;
    }

    /** Applies a proposed exception (3.2): from now on the site uses it like any other. False when there is no such proposal. */
    public static function apply(App $app, int $id): bool
    {
        $proposal = self::find($app->db(), $id, true);
        if ($proposal === null || $app->db()->update('hours_exceptions', ['proposed' => 0], ['id' => $id, 'proposed' => 1]) === 0) {
            return false;
        }
        \Kaleta\Admin\ChangeLog::write($app, 'settings', 'hours_exception_applied', $proposal['from'] . '–' . $proposal['to']);
        \Kaleta\Front\Cache::clear();
        GoogleBusiness::hoursChanged($app);

        return true;
    }

    /** Discards a proposed exception (3.2) – the site never used it. False when there is no such proposal. */
    public static function discard(App $app, int $id): bool
    {
        $discarded = $app->db()->delete('hours_exceptions', ['id' => $id, 'proposed' => 1]) > 0;
        if ($discarded) {
            \Kaleta\Admin\ChangeLog::write($app, 'settings', 'hours_exception_discarded', '#' . $id);
        }

        return $discarded;
    }

    public static function delete(App $app, int $id): bool
    {
        $deleted = $app->db()->delete('hours_exceptions', ['id' => $id]) > 0;
        if ($deleted) {
            \Kaleta\Admin\ChangeLog::write($app, 'settings', 'hours_exception_delete', '#' . $id);
            \Kaleta\Front\Cache::clear();
            GoogleBusiness::hoursChanged($app);
        }

        return $deleted;
    }

    /** 08:00 → 8:00 */
    private static function time(string $hhmm): string
    {
        return ltrim(substr($hhmm, 0, 2), '0') === '' ? '0' . substr($hhmm, 2) : ltrim(substr($hhmm, 0, 2), '0') . substr($hhmm, 2);
    }
}
