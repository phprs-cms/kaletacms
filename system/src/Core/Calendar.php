<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Collections;
use Kaleta\Builder\Presets;

/**
 * Events that keep themselves current (2.11): a collection made from a preset with a 'calendar' entry (events) knows its
 * start, end, place, repetition, capacity and registration deadline by its fields.
 *
 *  - Lists show what is upcoming and archive the past by the Collection list period (Collections::periodCondition).
 *  - A repeating event rolls forward on its own: when an occurrence ends, the hourly job ('events') moves its start and
 *    end to the next one until the repetition ends – one item per series, always showing the next date.
 *  - iCal: /<collection>.ics subscribes to the whole calendar, /<collection>/<item>.ics adds one event (RRULE for a series).
 *  - Registration is a Form in the item template: its submissions are enquiries from the item page; with a capacity
 *    the form closes when full, with a deadline when it passes, and always when the event has ended. The server checks
 *    it again when the form is sent (Front\Forms).
 *
 * The calculations are pure static functions and unit-tested; run() and the database helpers wrap them.
 */
final class Calendar
{
    /** A repetition (the English option stored in the choice field) => [PHP interval, iCalendar RRULE]. */
    public const array REPEATS = [
        'daily' => ['P1D', 'FREQ=DAILY'],
        'weekly' => ['P1W', 'FREQ=WEEKLY'],
        'every 2 weeks' => ['P2W', 'FREQ=WEEKLY;INTERVAL=2'],
        'monthly' => ['P1M', 'FREQ=MONTHLY'],
        'yearly' => ['P1Y', 'FREQ=YEARLY'],
    ];

    /** Roles of calendar fields; the preset maps each to a field key and the field must still have one of the types. */
    private const array ROLES = [
        'start' => ['termin', 'datum'], 'end' => ['termin', 'datum'], 'place' => ['text'], 'address' => ['text'], 'summary' => ['radky', 'text'],
        'online' => ['odkaz'], 'repeat' => ['volba', 'text'], 'repeat_until' => ['datum', 'termin'], 'capacity' => ['cislo'], 'registration_until' => ['termin', 'datum'],
    ];

    /**
     * The calendar fields of a collection: role => field key (or '' when the field is not there); null when the
     * collection is not a calendar (not made from such a preset, or its start field is gone).
     *
     * @return array<string, string>|null
     */
    public static function fields(array $collection): ?array
    {
        $preset = Presets::of($collection);
        $map = is_array($preset['calendar'] ?? null) ? $preset['calendar'] : null;
        if ($map === null) {
            return null;
        }
        $out = [];
        foreach (self::ROLES as $role => $types) {
            $key = (string) ($map[$role] ?? '');
            $out[$role] = $key !== '' ? (Presets::field($collection, (string) $collection['preset'], $key, $types) ?? '') : '';
        }

        return $out['start'] !== '' ? $out : null;
    }

    /** When an occurrence ends: its end, or its start without one; a whole day lasts until 23:59 ("Y-m-d H:i"). */
    public static function endsAt(string $start, string $end): string
    {
        $last = $end !== '' ? $end : $start;

        return strlen($last) === 10 ? $last . ' 23:59' : $last;
    }

    /**
     * The next occurrence of a repeating event whose occurrence has ended: start and end moved by whole repetitions until
     * it has not ended at $now. null = nothing to move (not repeating, not ended yet, or the next start is after the
     * repetition's last day – the event then stays in the past).
     *
     * @return array{0: string, 1: string}|null [start, end] in the same form as given (a whole day stays a day)
     */
    public static function nextOccurrence(string $start, string $end, string $repeat, string $until, string $now): ?array
    {
        if (!isset(self::REPEATS[$repeat]) || $start === '' || self::endsAt($start, $end) >= $now) {
            return null;
        }
        $format = fn (string $v): string => strlen($v) === 10 ? 'Y-m-d' : 'Y-m-d H:i';
        $interval = new \DateInterval(self::REPEATS[$repeat][0]);
        $first = new \DateTimeImmutable($start);
        $firstEnd = $end !== '' ? new \DateTimeImmutable($end) : null;
        for ($i = 1; $i <= 1000; $i++) {
            // always counted from this date, so that the 31st of a month does not drift to the 28th after February
            $s = self::add($first, $interval, $i);
            $e = $firstEnd !== null ? self::add($firstEnd, $interval, $i) : null;
            $startText = $s->format($format($start));
            if ($until !== '' && substr($startText, 0, 10) > substr($until, 0, 10)) {
                return null;
            }
            $endText = $e?->format($format($end)) ?? '';
            if (self::endsAt($startText, $endText) >= $now) {
                return [$startText, $endText];
            }
        }

        return null;
    }

    /** $times repetitions after a date; a month or a year that has no such day ends on its last day (31 Jan → 28 Feb). */
    private static function add(\DateTimeImmutable $from, \DateInterval $interval, int $times): \DateTimeImmutable
    {
        if ($interval->m === 0 && $interval->y === 0) {
            return $from->add(new \DateInterval('P' . ($interval->d * $times) . 'D'));
        }
        $months = ($interval->m + 12 * $interval->y) * $times;
        $day = (int) $from->format('j');
        $target = $from->modify('first day of +' . $months . ' month');

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($day, (int) $target->format('t')));
    }

    /**
     * Whether a registration form is open: 'closed' after the event ended or its deadline passed, 'full' when the
     * capacity is taken, otherwise 'open'. A capacity of 0 or none = unlimited.
     */
    public static function registrationState(int $capacity, int $registered, string $deadline, string $endsAt, string $now): string
    {
        $deadlinePassed = $deadline !== '' && self::endsAt($deadline, '') < $now; // a deadline day lasts until 23:59

        return match (true) {
            $endsAt < $now || $deadlinePassed => 'closed',
            $capacity > 0 && $registered >= $capacity => 'full',
            default => 'open',
        };
    }

    /**
     * Registrations of one occurrence: enquiries sent from the item's page (any language version) since the previous
     * occurrence of a series ended – a weekly class counts its own week.
     */
    public static function registered(Db $db, array $collection, array $item, array $fields): int
    {
        $data = (array) ($item['data'] ?? []);
        $start = (string) ($data[$fields['start']] ?? '');
        $repeat = $fields['repeat'] !== '' ? (string) ($data[$fields['repeat']] ?? '') : '';
        $since = '1970-01-01 00:00:00';
        if (isset(self::REPEATS[$repeat]) && $start !== '') {
            $previous = (new \DateTimeImmutable($start))->sub(new \DateInterval(self::REPEATS[$repeat][0]));
            $since = $previous->format(strlen($start) === 10 ? 'Y-m-d 23:59:59' : 'Y-m-d H:i:s');
        }

        return (int) $db->value("SELECT COUNT(*) FROM {poptavky} WHERE zdroj = ? AND (stranka LIKE ? OR stranka LIKE ?) AND datum > ?",
            ['kolekce:' . (int) $collection['idk'], '%/' . $collection['seo_link'] . '/' . $item['seo_link'], '%/' . $collection['seo_link'] . '/' . $item['seo_link'] . '?%', $since]);
    }

    /**
     * Placeholders of an event for the item page and lists: {{event_status}} (empty, or "This event has ended."),
     * {{ical}} (adds it to a calendar), {{places_left}} (with a capacity) and the internal _registration state the Form
     * element reads (a key outside the placeholder pattern, so no text can use it).
     *
     * @param callable(string): string $url
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(Db $db, array $collection, array $item, callable $url, string $now): array
    {
        $fields = self::fields($collection);
        if ($fields === null) {
            return [];
        }
        $data = (array) ($item['data'] ?? []);
        $get = fn (string $role): string => $fields[$role] !== '' ? (string) ($data[$fields[$role]] ?? '') : '';
        $start = $get('start');
        $endsAt = $start !== '' ? self::endsAt($start, $get('end')) : '9999-12-31 23:59';
        $capacity = (int) $get('capacity');
        $registered = $capacity > 0 && isset($item['idp']) ? self::registered($db, $collection, $item, $fields) : 0;
        $state = self::registrationState($capacity, $registered, $get('registration_until'), $endsAt, $now);

        $place = implode(', ', array_filter([$get('place'), $get('address')]));

        return [
            'when' => [self::when($start, $get('end')), 'text'],
            'where' => [$place !== '' ? $place : ($get('online') !== '' ? t('Online') : ''), 'text'],
            'event_status' => [$endsAt < $now ? t('This event has ended.') : ($state === 'full' ? t('This event is fully booked.') : ''), 'text'],
            'ical' => [$collection['detail'] && ($item['seo_link'] ?? '') !== '' ? $url($collection['seo_link'] . '/' . $item['seo_link'] . '.ics') : '', 'odkaz'],
            'places_left' => [$capacity > 0 ? (string) max(0, $capacity - $registered) : '', 'text'],
            '_registration' => [$state, 'text'],
        ];
    }

    /**
     * The date range for visitors: "2. 11. 2026 17:00–19:00" on one day, "2. 11. 2026 – 4. 11. 2026" over several, the
     * start alone without an end.
     */
    public static function when(string $start, string $end): string
    {
        if ($start === '') {
            return '';
        }
        $from = Collections::formatDateTime($start);
        if ($end === '' || $end === $start) {
            return $from;
        }
        if (substr($end, 0, 10) === substr($start, 0, 10)) {
            return strlen($end) > 10 ? $from . '–' . substr($end, 11, 5) : $from;
        }

        return $from . ' – ' . Collections::formatDateTime($end);
    }

    /**
     * The registration state of the item page a form was sent from (Front\Forms): null when the source is not an event
     * item page, otherwise open | full | closed.
     */
    public static function stateForSubmission(Db $db, int $idk, string $back): ?string
    {
        $collection = Collections::byId($db, $idk);
        if ($collection === null || ($fields = self::fields($collection)) === null
            || preg_match('#/' . preg_quote((string) $collection['seo_link'], '#') . '/([a-z0-9-]{1,160})/?(?:\?.*)?$#', $back, $m) !== 1) {
            return null;
        }
        $item = $db->one('SELECT * FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND zobrazit = 1 AND smazano IS NULL LIMIT 1', [$idk, $m[1]]);
        if ($item === null) {
            return null;
        }
        $item['data'] = json_decode((string) $item['data'], true) ?: [];

        return self::values($db, $collection, $item, fn (string $p): string => $p, date('Y-m-d H:i'))['_registration'][0];
    }

    /**
     * The hourly job: repeating events whose occurrence ended move to their next one. Each move is recorded in the change
     * log and the page cache is cleared when something moved.
     */
    public static function run(App $app): string
    {
        $db = $app->db();
        $now = date('Y-m-d H:i');
        $moved = 0;
        foreach (Collections::all($db) as $collection) {
            $fields = self::fields($collection);
            $repeat = $fields === null || $fields['repeat'] === '' ? null : Collections::jsonField($fields['repeat']); // N3183-1
            if ($repeat === null) {
                continue;
            }
            foreach ($db->all('SELECT idp, nazev, data FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL AND ' . $repeat . " <> ''", [(int) $collection['idk']]) as $r) {
                $data = json_decode((string) $r['data'], true) ?: [];
                $get = fn (string $role): string => $fields[$role] !== '' ? (string) ($data[$fields[$role]] ?? '') : '';
                $next = self::nextOccurrence($get('start'), $get('end'), $get('repeat'), $get('repeat_until'), $now);
                if ($next === null) {
                    continue;
                }
                $data[$fields['start']] = $next[0];
                if ($fields['end'] !== '' && $next[1] !== '') {
                    $data[$fields['end']] = $next[1];
                }
                $db->update('kolekce_polozky', ['data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE)], ['idp' => (int) $r['idp']]);
                \Kaleta\Admin\ChangeLog::write($app, 'collections', 'event_next', $r['nazev'] . ': ' . $next[0]);
                $moved++;
            }
        }
        if ($moved > 0) {
            \Kaleta\Front\Cache::clear();
        }

        return 'moved ' . $moved;
    }

    /* ---------- iCalendar (RFC 5545) ---------- */

    /**
     * An iCalendar file of events: each with its date (a whole day as DATE, a time in UTC), place, description, address
     * and – for a series – RRULE. $events: [item, absolute url].
     *
     * @param list<array{0: array<string, mixed>, 1: string}> $events
     */
    public static function ics(array $collection, array $events, string $name, string $host): string
    {
        $fields = self::fields($collection) ?? [];
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kaleta//Events//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::escape($name)];
        foreach ($events as [$item, $url]) {
            $data = (array) ($item['data'] ?? []);
            $get = fn (string $role): string => ($fields[$role] ?? '') !== '' ? trim(html_entity_decode(strip_tags((string) ($data[$fields[$role]] ?? '')), ENT_QUOTES | ENT_HTML5)) : '';
            $start = $get('start');
            if ($start === '') {
                continue;
            }
            $end = $get('end');
            $wholeDay = strlen($start) === 10;
            $event = ['BEGIN:VEVENT', 'UID:kaleta-' . (int) $item['idp'] . '@' . $host, 'DTSTAMP:' . self::utc((string) ($item['zmeneno'] ?? $item['datum'] ?? 'now'))];
            if ($wholeDay) {
                $event[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $start);
                $event[] = 'DTEND;VALUE=DATE:' . (new \DateTimeImmutable(substr($end !== '' ? $end : $start, 0, 10)))->modify('+1 day')->format('Ymd');
            } else {
                $event[] = 'DTSTART:' . self::utc($start);
                if ($end !== '') {
                    $event[] = 'DTEND:' . self::utc(strlen($end) === 10 ? $end . ' 23:59' : $end);
                }
            }
            $repeat = $get('repeat');
            if (isset(self::REPEATS[$repeat])) {
                $until = $get('repeat_until');
                $event[] = 'RRULE:' . self::REPEATS[$repeat][1] . ($until !== '' ? ';UNTIL=' . ($wholeDay ? str_replace('-', '', substr($until, 0, 10)) : self::utc(substr($until, 0, 10) . ' 23:59')) : '');
            }
            $event[] = 'SUMMARY:' . self::escape((string) $item['nazev']);
            $place = implode(', ', array_filter([$get('place'), $get('address')]));
            if ($place !== '' || $get('online') !== '') {
                $event[] = 'LOCATION:' . self::escape($place !== '' ? $place : $get('online'));
            }
            $description = implode("\n\n", array_filter([$get('summary'), $url]));
            if ($description !== '') {
                $event[] = 'DESCRIPTION:' . self::escape($description);
            }
            if ($url !== '') {
                $event[] = 'URL:' . self::escape($url);
            }
            $event[] = 'END:VEVENT';
            array_push($lines, ...$event);
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    /** A local date and time as UTC in the iCalendar form 20261102T160000Z. */
    public static function utc(string $local): string
    {
        return (new \DateTimeImmutable($local))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** TEXT values: backslash, semicolon, comma and line breaks escaped (RFC 5545 3.3.11). */
    public static function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** Lines longer than 75 octets continue on the next line after a space, never inside a UTF-8 character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $out . "\r\n " . $current;
    }
}
