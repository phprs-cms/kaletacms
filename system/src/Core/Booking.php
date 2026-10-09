<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;
use Kaleta\Front\Company;

/**
 * Online booking of appointments (3.0): a hairdresser, a physiotherapist, a garage, a consultant. The visitor picks a
 * service, a person (or anyone), a day and a free time, leaves a name, e-mail and phone and gets a confirmation with a
 * cancel link; the business sees the bookings in Administration → Bookings and the site reminds the customer.
 *
 *  - Services (ka_booking_services) have a duration and a buffer kept free after them; people (ka_booking_staff) offer
 *    some of them, have their weekly hours (ka_booking_hours; hours of a service replace the general ones for that service,
 *    so one person can offer speed dates on weekday evenings and family shoots at weekends; without any, the site's opening hours from Core\Hours) and
 *    days off (ka_booking_off; a closed day in the site's hours exceptions is a day off for everyone).
 *  - Free times (free()) are pure: the ranges of the day minus days off, minus existing bookings with the buffer around
 *    them, never in the past, after the lead time and within the horizon – unit tested without a database.
 *  - Booking (book()) runs in a transaction that first locks the rows of the people concerned (SELECT … FOR UPDATE) and
 *    checks the slot again, so two visitors never get the same time. "Anyone" goes to the least-booked free person that day.
 *  - A booking is personal data: it follows the enquiry retention (purge(): deleted or anonymised after N months), Core\PersonalData
 *    finds and erases it, Claude reads it only with the Bookings permission and every read is logged, the site export
 *    carries only the set-up.
 */
final class Booking
{
    /**
     * The Bookings feature is on (3.2, Core\Extensions 'bookings'): off, the admin module, the Booking element, the public
     * addresses and the reminders are gone, the MCP tools say so; the data stays and the retention keeps running.
     */
    public static function isOn(Settings $settings): bool
    {
        return Extensions::isEnabled($settings, 'bookings');
    }

    /** status => label (admin, translated with t()) */
    public const array STATUSES = ['pending' => 'waiting for confirmation', 'confirmed' => 'confirmed', 'declined' => 'declined', 'done' => 'done', 'no_show' => 'did not come', 'cancelled' => 'cancelled'];

    /**
     * Statuses that keep the time occupied. A pending booking (3.3, a service that needs the provider's confirmation) holds
     * its time too – until hold_until; after that the time is free again, but the booking waits to be answered.
     */
    public const array BLOCKING = ['confirmed', 'done', 'pending'];

    /** How many other times a provider may propose at once. */
    public const int MAX_PROPOSALS = 3;

    public const array WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** The settings with their defaults (Settings::DEFAULTS has the same). */
    public const array SETTINGS = ['booking_lead_hours' => 2, 'booking_horizon_days' => 60, 'booking_cancel_hours' => 24, 'booking_reminder_hours' => 24, 'booking_hold_hours' => 48];

    public const string SLOT_PATTERN = '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/';

    public const int MAX_DURATION = 480;

    /* ---------- set-up: services and people ---------- */

    /**
     * @return list<array{id: int, name: string, duration_min: int, buffer_min: int, price_text: string, description: string, active: bool, requires_confirmation: bool, sort_order: int, staff: list<int>}>
     */
    public static function services(Db $db, bool $activeOnly = true): array
    {
        try {
            $rows = $db->all('SELECT * FROM {booking_services}' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name, id');
            $links = $db->all('SELECT staff_id, service_id FROM {booking_staff_services}');
        } catch (\Throwable) {
            return []; // before the 3.0 migration
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out[] = ['id' => $id, 'name' => (string) $r['name'], 'duration_min' => (int) $r['duration_min'], 'buffer_min' => (int) $r['buffer_min'], 'price_text' => (string) $r['price_text'],
                'description' => (string) $r['description'], 'active' => (int) $r['active'] === 1, 'requires_confirmation' => (int) $r['requires_confirmation'] === 1, 'sort_order' => (int) $r['sort_order'],
                'staff' => array_values(array_map(fn (array $l): int => (int) $l['staff_id'], array_filter($links, fn (array $l): bool => (int) $l['service_id'] === $id)))];
        }

        return $out;
    }

    /** @return array{id: int, name: string, duration_min: int, buffer_min: int, price_text: string, description: string, active: bool, requires_confirmation: bool, sort_order: int, staff: list<int>}|null */
    public static function service(Db $db, int $id, bool $activeOnly = false): ?array
    {
        foreach (self::services($db, $activeOnly) as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, name: string, email: string, active: bool, user_id: ?int, sort_order: int, services: list<int>}>
     */
    public static function staff(Db $db, bool $activeOnly = true): array
    {
        try {
            $rows = $db->all('SELECT * FROM {booking_staff}' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name, id');
            $links = $db->all('SELECT staff_id, service_id FROM {booking_staff_services}');
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out[] = ['id' => $id, 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'active' => (int) $r['active'] === 1, 'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
                'sort_order' => (int) $r['sort_order'], 'services' => array_values(array_map(fn (array $l): int => (int) $l['service_id'], array_filter($links, fn (array $l): bool => (int) $l['staff_id'] === $id)))];
        }

        return $out;
    }

    /** @return array{id: int, name: string, email: string, active: bool, user_id: ?int, sort_order: int, services: list<int>}|null */
    public static function member(Db $db, int $id, bool $activeOnly = false): ?array
    {
        foreach (self::staff($db, $activeOnly) as $m) {
            if ($m['id'] === $id) {
                return $m;
            }
        }

        return null;
    }

    /**
     * The people who offer a service (active ones).
     *
     * @return list<array{id: int, name: string, email: string, active: bool, user_id: ?int, sort_order: int, services: list<int>}>
     */
    public static function staffFor(Db $db, int $serviceId): array
    {
        return array_values(array_filter(self::staff($db), fn (array $m): bool => in_array($serviceId, $m['services'], true)));
    }

    /**
     * The general weekly hours of a person: weekday (1–7) => ranges; [] when the person has none (the site's hours apply).
     * With a service: only the hours kept for that service.
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public static function hours(Db $db, int $staffId, ?int $serviceId = null): array
    {
        $out = [];
        foreach ($db->all('SELECT weekday, time_from, time_to FROM {booking_hours} WHERE staff_id = ? AND service_id <=> ? ORDER BY weekday, time_from', [$staffId, $serviceId]) as $r) {
            $out[(int) $r['weekday']][] = [(string) $r['time_from'], (string) $r['time_to']];
        }

        return $out;
    }

    /**
     * The hours that apply to a person for a service: the ones kept for that service, else the general ones, else [] (the site's).
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public static function hoursFor(Db $db, int $staffId, int $serviceId): array
    {
        return self::hours($db, $staffId, $serviceId) ?: self::hours($db, $staffId);
    }

    /**
     * The hours kept per service: service id => weekday => ranges (only services with their own hours).
     *
     * @return array<int, array<int, list<array{0: string, 1: string}>>>
     */
    public static function serviceHours(Db $db, int $staffId): array
    {
        $out = [];
        foreach ($db->all('SELECT service_id, weekday, time_from, time_to FROM {booking_hours} WHERE staff_id = ? AND service_id IS NOT NULL ORDER BY service_id, weekday, time_from', [$staffId]) as $r) {
            $out[(int) $r['service_id']][(int) $r['weekday']][] = [(string) $r['time_from'], (string) $r['time_to']];
        }

        return $out;
    }

    /**
     * Hours written per weekday ("9:00-12:00, 13-17"; a weekday number 1–7 or an English name) checked: weekday => ranges;
     * null when a text is not a range. Empty texts are left out, an empty result = the site's hours.
     *
     * @param array<int|string, mixed> $hours
     * @return array<int, list<array{0: string, 1: string}>>|null
     */
    public static function parseHours(array $hours): ?array
    {
        $names = array_map('strtolower', self::WEEKDAYS);
        $out = [];
        foreach ($hours as $day => $text) {
            $weekday = is_int($day) || ctype_digit((string) $day) ? (int) $day : (int) array_search(strtolower((string) $day), $names, true);
            if ($weekday < 1 || $weekday > 7 || !is_string($text)) {
                return null;
            }
            if (trim($text) === '') {
                continue;
            }
            $ranges = Hours::parseRanges($text);
            if ($ranges === null) {
                return null;
            }
            foreach ($ranges as [$from, $to]) {
                if ($to <= $from) {
                    return null;
                }
            }
            $out[$weekday] = $ranges;
        }
        ksort($out);

        return $out;
    }

    /**
     * Saves a service. Returns the row, or the error text.
     *
     * @param array<string, mixed> $data name, duration_min, buffer_min, price_text, description, active, sort_order, requires_confirmation, staff (ids)
     * @return array<string, mixed>|string
     */
    public static function saveService(App $app, array $data, int $id = 0): array|string
    {
        $db = $app->db();
        $existing = $id > 0 ? $db->one('SELECT * FROM {booking_services} WHERE id = ?', [$id]) : null;
        if ($id > 0 && $existing === null) {
            return 'The service does not exist.';
        }
        $name = mb_substr(trim(strip_tags((string) ($data['name'] ?? ($existing['name'] ?? '')))), 0, 150);
        if ($name === '') {
            return 'Enter the name of the service.';
        }
        $duration = (int) ($data['duration_min'] ?? ($existing['duration_min'] ?? 30));
        if ($duration < 5 || $duration > self::MAX_DURATION) {
            return 'The duration is in minutes, 5 to 480.';
        }
        $row = ['name' => $name, 'duration_min' => $duration, 'buffer_min' => max(0, min(240, (int) ($data['buffer_min'] ?? ($existing['buffer_min'] ?? 0)))),
            'price_text' => mb_substr(trim(strip_tags((string) ($data['price_text'] ?? ($existing['price_text'] ?? '')))), 0, 60),
            'description' => mb_substr(trim(strip_tags((string) ($data['description'] ?? ($existing['description'] ?? '')))), 0, 500),
            'active' => (bool) ($data['active'] ?? ($existing['active'] ?? true)) ? 1 : 0,
            'requires_confirmation' => (bool) ($data['requires_confirmation'] ?? ($existing['requires_confirmation'] ?? false)) ? 1 : 0, 'sort_order' => (int) ($data['sort_order'] ?? ($existing['sort_order'] ?? 0))];
        if ($existing === null) {
            $id = $db->insert('booking_services', $row);
        } else {
            $db->update('booking_services', $row, ['id' => $id]);
        }
        if (isset($data['staff']) && is_array($data['staff'])) {
            self::link($db, 'service_id', $id, 'staff_id', array_map('intval', $data['staff']), array_column(self::staff($db, false), 'id'));
        }
        ChangeLog::write($app, 'bookings', $existing === null ? 'service_create' : 'service_update', $name);
        \Kaleta\Front\Cache::clear();

        return self::service($db, $id) ?? [];
    }

    /**
     * Saves a person. Returns the row, or the error text.
     *
     * @param array<string, mixed> $data name, email, active, user_id, sort_order, services (ids), hours (weekday => "9-12, 13-17"),
     *        service_hours (service id => weekday => "9-12, 13-17"; an empty map takes the service back to the general hours), days_off (list of {from, to, note} – replaces)
     * @return array<string, mixed>|string
     */
    public static function saveStaff(App $app, array $data, int $id = 0): array|string
    {
        $db = $app->db();
        $existing = $id > 0 ? $db->one('SELECT * FROM {booking_staff} WHERE id = ?', [$id]) : null;
        if ($id > 0 && $existing === null) {
            return 'The person does not exist.';
        }
        $name = mb_substr(trim(strip_tags((string) ($data['name'] ?? ($existing['name'] ?? '')))), 0, 150);
        if ($name === '') {
            return 'Enter the name of the person.';
        }
        $email = trim((string) ($data['email'] ?? ($existing['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid e-mail address of the person, or leave it empty.';
        }
        $hours = null;
        if (isset($data['hours'])) {
            $hours = is_array($data['hours']) ? self::parseHours($data['hours']) : null;
            if ($hours === null) {
                return 'Write the hours per weekday as ranges, e.g. 9:00-12:00, 13:00-17:00 (empty = the opening hours of the site).';
            }
        }
        $serviceHours = [];
        if (isset($data['service_hours'])) {
            $known = array_column(self::services($db, false), 'id');
            foreach (is_array($data['service_hours']) ? $data['service_hours'] : [] as $serviceId => $perDay) {
                if (!in_array((int) $serviceId, $known, true)) {
                    return 'Hours per service need an existing service (the ids are listed by booking_availability).';
                }
                $parsed = is_array($perDay) ? self::parseHours($perDay) : null;
                if ($parsed === null) {
                    return 'Write the hours per weekday as ranges, e.g. 9:00-12:00, 13:00-17:00 (empty = the opening hours of the site).';
                }
                $serviceHours[(int) $serviceId] = $parsed;
            }
        }
        $daysOff = null;
        if (isset($data['days_off'])) {
            $daysOff = is_array($data['days_off']) ? self::cleanOffs($data['days_off']) : null;
            if ($daysOff === null) {
                return 'Days off are given as from and to (YYYY-MM-DD or YYYY-MM-DD HH:MM) with an optional note.';
            }
        }
        $userId = (int) ($data['user_id'] ?? ($existing['user_id'] ?? 0));
        $row = ['name' => $name, 'email' => mb_substr($email, 0, 190), 'active' => (bool) ($data['active'] ?? ($existing['active'] ?? true)) ? 1 : 0,
            'user_id' => $userId > 0 && $db->value('SELECT idu FROM {uzivatele} WHERE idu = ?', [$userId]) !== null ? $userId : null, 'sort_order' => (int) ($data['sort_order'] ?? ($existing['sort_order'] ?? 0))];
        if ($existing === null) {
            $id = $db->insert('booking_staff', $row);
        } else {
            $db->update('booking_staff', $row, ['id' => $id]);
        }
        if (isset($data['services']) && is_array($data['services'])) {
            self::link($db, 'staff_id', $id, 'service_id', array_map('intval', $data['services']), array_column(self::services($db, false), 'id'));
        }
        if ($hours !== null) {
            self::saveHours($db, $id, null, $hours);
        }
        foreach ($serviceHours as $serviceId => $perDay) {
            self::saveHours($db, $id, $serviceId, $perDay);
        }
        if ($daysOff !== null) {
            $db->delete('booking_off', ['staff_id' => $id]);
            foreach ($daysOff as $off) {
                $db->insert('booking_off', ['staff_id' => $id, 'off_from' => $off['from'], 'off_to' => $off['to'], 'note' => $off['note']]);
            }
        }
        ChangeLog::write($app, 'bookings', $existing === null ? 'staff_create' : 'staff_update', $name);
        \Kaleta\Front\Cache::clear();

        return self::member($db, $id) ?? [];
    }

    /** Replaces the weekly hours of a person for a service (null = the general hours). @param array<int, list<array{0: string, 1: string}>> $hours */
    private static function saveHours(Db $db, int $staffId, ?int $serviceId, array $hours): void
    {
        $db->run('DELETE FROM {booking_hours} WHERE staff_id = ? AND service_id <=> ?', [$staffId, $serviceId]);
        foreach ($hours as $weekday => $ranges) {
            foreach ($ranges as [$from, $to]) {
                $db->insert('booking_hours', ['staff_id' => $staffId, 'service_id' => $serviceId, 'weekday' => $weekday, 'time_from' => $from, 'time_to' => $to]);
            }
        }
    }

    /** Replaces the links of one side of ka_booking_staff_services. @param list<int> $ids @param list<int> $known */
    private static function link(Db $db, string $ownColumn, int $ownId, string $otherColumn, array $ids, array $known): void
    {
        $db->delete('booking_staff_services', [$ownColumn => $ownId]);
        foreach (array_unique(array_intersect($ids, $known)) as $other) {
            $db->insert('booking_staff_services', [$ownColumn => $ownId, $otherColumn => $other]);
        }
    }

    /**
     * Days off as given (from, to – a day or a day with a time, an open "to" = the same day; note) checked and ordered.
     *
     * @param list<mixed> $offs
     * @return list<array{from: string, to: string, note: string}>|null
     */
    public static function cleanOffs(array $offs): ?array
    {
        $out = [];
        foreach ($offs as $o) {
            if (!is_array($o)) {
                return null;
            }
            $range = self::offRange((string) ($o['from'] ?? ''), (string) ($o['to'] ?? ''));
            if ($range === null) {
                return null;
            }
            $out[] = ['from' => $range[0], 'to' => $range[1], 'note' => mb_substr(trim(strip_tags((string) ($o['note'] ?? ''))), 0, 150)];
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['from'], $b['from']));

        return $out;
    }

    /**
     * A day off from "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" to the same (empty = the same day, a whole day); null when not valid.
     *
     * @return array{0: string, 1: string}|null 'Y-m-d H:i:s' both
     */
    public static function offRange(string $from, string $to): ?array
    {
        $from = trim($from);
        $to = trim($to);
        $date = '/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))?$/';
        if (!preg_match($date, $from, $f) || strtotime($from) === false) {
            return null;
        }
        $start = $f[1] . ' ' . (($f[2] ?? '') !== '' ? $f[2] : '00:00') . ':00';
        if ($to === '') {
            $to = $f[1];
        }
        if (!preg_match($date, $to, $t) || strtotime($to) === false) {
            return null;
        }
        $end = ($t[2] ?? '') !== '' ? $t[1] . ' ' . $t[2] . ':00' : date('Y-m-d H:i:s', strtotime($t[1] . ' +1 day'));

        return $end <= $start ? null : [$start, $end];
    }

    /** A day off for one person or everyone (staff 0); returns null, or the error. */
    public static function saveOff(App $app, int $staffId, string $from, string $to, string $note): ?string
    {
        $range = self::offRange($from, $to);
        if ($range === null) {
            return 'Enter the day off as YYYY-MM-DD (and HH:MM for a part of a day); the end must not be before the start.';
        }
        if ($staffId > 0 && self::member($app->db(), $staffId) === null) {
            return 'The person does not exist.';
        }
        $app->db()->insert('booking_off', ['staff_id' => $staffId > 0 ? $staffId : null, 'off_from' => $range[0], 'off_to' => $range[1], 'note' => mb_substr(trim(strip_tags($note)), 0, 150)]);
        ChangeLog::write($app, 'bookings', 'off_create', ($staffId > 0 ? '#' . $staffId . ' ' : '') . $range[0] . ' – ' . $range[1]);
        \Kaleta\Front\Cache::clear();

        return null;
    }

    public static function deleteOff(App $app, int $id): bool
    {
        $deleted = $app->db()->delete('booking_off', ['id' => $id]) > 0;
        if ($deleted) {
            ChangeLog::write($app, 'bookings', 'off_delete', '#' . $id);
            \Kaleta\Front\Cache::clear();
        }

        return $deleted;
    }

    /**
     * Days off of a person (and of everyone), the nearest first.
     *
     * @return list<array{id: int, staff_id: ?int, from: string, to: string, note: string}>
     */
    public static function offs(Db $db, ?int $staffId, bool $pastToo = false): array
    {
        $sql = 'SELECT * FROM {booking_off} WHERE ' . ($staffId === null ? '1 = 1' : '(staff_id IS NULL OR staff_id = ?)') . ($pastToo ? '' : ' AND off_to >= NOW()') . ' ORDER BY off_from, id LIMIT 500';

        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'staff_id' => $r['staff_id'] === null ? null : (int) $r['staff_id'], 'from' => (string) $r['off_from'], 'to' => (string) $r['off_to'], 'note' => (string) $r['note']],
            $db->all($sql, $staffId === null ? [] : [$staffId]));
    }

    /** Removes a service; the error when it still has upcoming bookings. */
    public static function deleteService(App $app, int $id): ?string
    {
        $db = $app->db();
        if ($db->value('SELECT id FROM {booking_services} WHERE id = ?', [$id]) === null) {
            return 'The service does not exist.';
        }
        if ((int) $db->value("SELECT COUNT(*) FROM {bookings} WHERE service_id = ? AND status IN ('confirmed', 'pending') AND starts_at >= NOW()", [$id]) > 0) {
            return 'The service has upcoming bookings – cancel them first, or switch the service off instead.';
        }
        $db->delete('booking_services', ['id' => $id]);
        ChangeLog::write($app, 'bookings', 'service_delete', '#' . $id);
        \Kaleta\Front\Cache::clear();

        return null;
    }

    /** Removes a person; the error when they still have upcoming bookings. */
    public static function deleteStaff(App $app, int $id): ?string
    {
        $db = $app->db();
        if ($db->value('SELECT id FROM {booking_staff} WHERE id = ?', [$id]) === null) {
            return 'The person does not exist.';
        }
        if ((int) $db->value("SELECT COUNT(*) FROM {bookings} WHERE staff_id = ? AND status IN ('confirmed', 'pending') AND starts_at >= NOW()", [$id]) > 0) {
            return 'The person has upcoming bookings – cancel or move them first, or switch the person off instead.';
        }
        $db->delete('booking_staff', ['id' => $id]);
        ChangeLog::write($app, 'bookings', 'staff_delete', '#' . $id);
        \Kaleta\Front\Cache::clear();

        return null;
    }

    /* ---------- free times ---------- */

    /** The step between offered times: the duration of the service up to an hour, otherwise a quarter of an hour. */
    public static function step(int $durationMin): int
    {
        return $durationMin >= 5 && $durationMin <= 60 ? $durationMin : 15;
    }

    /**
     * The free times of one person on one day – pure, so it is tested without a database. Times are the site's wall-clock
     * times; the day may change clocks (DST) – the slots are stepped on the real timeline and shown as wall-clock times.
     *
     * @param list<array{0: string, 1: string}> $ranges the person's hours of the day, "HH:MM" from and to
     * @param list<array{0: string, 1: string}> $busy existing bookings, 'Y-m-d H:i:s' start and end (any day – others are ignored)
     * @param list<array{0: string, 1: string}> $off blocked intervals (days off), 'Y-m-d H:i:s'
     * @param string $day YYYY-MM-DD
     * @param \DateTimeImmutable $now the moment of asking, in the site's time zone
     * @return list<string> "HH:MM"
     */
    public static function free(array $ranges, array $busy, array $off, int $durationMin, int $bufferMin, int $stepMin, string $day, \DateTimeImmutable $now, int $leadHours, int $horizonDays): array
    {
        if ($durationMin <= 0 || $stepMin <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || strtotime($day) === false) {
            return [];
        }
        $tz = $now->getTimezone();
        $earliest = $now->modify('+' . max(0, $leadHours) . ' hours');
        if ($day > $now->modify('+' . max(0, $horizonDays) . ' days')->format('Y-m-d')) {
            return [];
        }
        $at = fn (string $local): \DateTimeImmutable => new \DateTimeImmutable($local, $tz);
        $blocked = [];
        foreach ($busy as [$start, $end]) {
            // the buffer of a service is kept free on both sides of an existing booking: after it (cleaning, notes) and
            // before it (the new booking's own buffer must fit)
            $blocked[] = [$at($start)->modify('-' . $bufferMin . ' minutes'), $at($end)->modify('+' . $bufferMin . ' minutes')];
        }
        foreach ($off as [$start, $end]) {
            $blocked[] = [$at($start), $at($end)];
        }
        $out = [];
        foreach ($ranges as [$from, $to]) {
            $rangeStart = $at($day . ' ' . $from);
            $rangeEnd = $to === '24:00' ? $at($day . ' 00:00')->modify('+1 day') : $at($day . ' ' . $to);
            for ($t = $rangeStart; $t->modify('+' . $durationMin . ' minutes') <= $rangeEnd; $t = $t->modify('+' . $stepMin . ' minutes')) {
                if ($t < $earliest) {
                    continue;
                }
                $end = $t->modify('+' . $durationMin . ' minutes');
                foreach ($blocked as [$bStart, $bEnd]) {
                    if ($t < $bEnd && $end > $bStart) {
                        continue 2;
                    }
                }
                $out[] = $t->format('H:i');
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * Picks the person for an "anyone" booking: the least-booked free person that day (ties: the first in the order).
     *
     * @param list<int> $free ids of the people free at the time
     * @param array<int, int> $counts id => bookings that day
     * @param list<int> $order ids in the admin order
     */
    public static function leastBooked(array $free, array $counts, array $order): ?int
    {
        $best = null;
        foreach ($order as $id) {
            if (in_array($id, $free, true) && ($best === null || ($counts[$id] ?? 0) < ($counts[$best] ?? 0))) {
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * What the free-time calculation needs for some people over a span of days, loaded once: their weekly hours for the service
     * (or the site's), their bookings and days off, the site's hours exceptions.
     *
     * @param list<array<string, mixed>> $members
     * @return array{week: array<int, array<int, list<array{0: string, 1: string}>>>, site: array<string, list<array{0: string, 1: string}>>, exceptions: list<array<string, mixed>>, busy: array<int, list<array{0: string, 1: string}>>, off: array<int, list<array{0: string, 1: string}>>}
     */
    private static function calendar(App $app, array $members, int $serviceId, string $fromDay, string $toDay, int $exclude = 0): array
    {
        $db = $app->db();
        $ids = array_map(fn (array $m): int => (int) $m['id'], $members);
        $cal = ['week' => [], 'site' => Hours::week($app->settings()), 'exceptions' => Hours::exceptions($db), 'busy' => array_fill_keys($ids, []), 'off' => array_fill_keys($ids, [])];
        foreach ($ids as $id) {
            $cal['week'][$id] = self::hoursFor($db, $id, $serviceId);
        }
        if ($ids === []) {
            return $cal;
        }
        $in = implode(',', $ids);
        $from = $fromDay . ' 00:00:00';
        $to = date('Y-m-d 00:00:00', strtotime($toDay . ' +2 days'));
        foreach ($db->all('SELECT staff_id, starts_at, ends_at FROM {bookings} WHERE staff_id IN (' . $in . ') AND (status IN (\'confirmed\', \'done\') OR (status = \'pending\' AND hold_until > NOW())) AND id <> ? AND ends_at > ? AND starts_at < ?', [$exclude, date('Y-m-d H:i:s', strtotime($from . ' -1 day')), $to]) as $b) {
            $cal['busy'][(int) $b['staff_id']][] = [(string) $b['starts_at'], (string) $b['ends_at']];
        }
        foreach ($db->all('SELECT staff_id, off_from, off_to FROM {booking_off} WHERE (staff_id IS NULL OR staff_id IN (' . $in . ')) AND off_to > ? AND off_from < ?', [$from, $to]) as $o) {
            foreach ($o['staff_id'] === null ? $ids : [(int) $o['staff_id']] as $id) {
                $cal['off'][$id][] = [(string) $o['off_from'], (string) $o['off_to']];
            }
        }

        return $cal;
    }

    /**
     * The hours of one person on one day: their own weekly hours (a closed day of the site is a day off for them too), or
     * the site's opening hours with their exceptions when they have none of their own.
     *
     * @param array<int, list<array{0: string, 1: string}>> $own
     * @param array<string, list<array{0: string, 1: string}>> $site
     * @param list<array<string, mixed>> $exceptions
     * @return list<array{0: string, 1: string}>
     */
    public static function dayRanges(array $own, array $site, array $exceptions, \DateTimeImmutable $date): array
    {
        $siteDay = Hours::day($site, $exceptions, $date);
        if ($own === []) {
            return $siteDay['ranges'];
        }
        if ($siteDay['exception'] !== null && !empty($siteDay['exception']['closed'])) {
            return [];
        }

        return $own[(int) $date->format('N')] ?? [];
    }

    /**
     * The free times for a service on a day: "HH:MM" => ids of the people free then. A person given = only that person;
     * none = everyone who offers the service.
     *
     * @param array<string, mixed> $service
     * @return array<string, list<int>>
     */
    public static function availability(App $app, array $service, int $staffId, string $day, ?\DateTimeImmutable $now = null, bool $forStaff = false, int $exclude = 0): array
    {
        $members = self::candidates($app->db(), (int) $service['id'], $staffId);
        if ($members === [] || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || strtotime($day) === false) {
            return [];
        }

        return self::freeFrom($app, self::calendar($app, $members, (int) $service['id'], $day, $day, $exclude), $members, $service, $day, $now ?? new \DateTimeImmutable(), $forStaff);
    }

    /**
     * @param array<string, mixed> $cal calendar()
     * @param list<array<string, mixed>> $members
     * @param array<string, mixed> $service
     * @return array<string, list<int>>
     */
    private static function freeFrom(App $app, array $cal, array $members, array $service, string $day, \DateTimeImmutable $now, bool $forStaff): array
    {
        $s = $app->settings();
        $date = new \DateTimeImmutable($day, $now->getTimezone());
        $out = [];
        foreach ($members as $m) {
            $id = (int) $m['id'];
            $ranges = self::dayRanges($cal['week'][$id] ?? [], $cal['site'], $cal['exceptions'], $date);
            $times = self::free($ranges, $cal['busy'][$id] ?? [], $cal['off'][$id] ?? [], (int) $service['duration_min'], (int) $service['buffer_min'], self::step((int) $service['duration_min']), $day, $now,
                $forStaff ? 0 : $s->int('booking_lead_hours'), $forStaff ? 366 : $s->int('booking_horizon_days'));
            foreach ($times as $t) {
                $out[$t][] = $id;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The days of a month with at least one free time – the small calendar of the element.
     *
     * @param array<string, mixed> $service
     * @return list<string> YYYY-MM-DD
     */
    public static function days(App $app, array $service, int $staffId, string $month, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return [];
        }
        $members = self::candidates($app->db(), (int) $service['id'], $staffId);
        if ($members === []) {
            return [];
        }
        $first = max($month . '-01', $now->format('Y-m-d'));
        $last = min(date('Y-m-t', strtotime($month . '-01')), $now->modify('+' . $app->settings()->int('booking_horizon_days') . ' days')->format('Y-m-d'));
        if ($first > $last) {
            return [];
        }
        $cal = self::calendar($app, $members, (int) $service['id'], $first, $last);
        $out = [];
        for ($d = new \DateTimeImmutable($first, $now->getTimezone()); $d->format('Y-m-d') <= $last; $d = $d->modify('+1 day')) {
            if (self::freeFrom($app, $cal, $members, $service, $d->format('Y-m-d'), $now, false) !== []) {
                $out[] = $d->format('Y-m-d');
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the people a booking may go to: the one asked for (when they offer the service), or everyone who does */
    private static function candidates(Db $db, int $serviceId, int $staffId): array
    {
        $all = self::staffFor($db, $serviceId);

        return $staffId > 0 ? array_values(array_filter($all, fn (array $m): bool => $m['id'] === $staffId)) : $all;
    }

    /* ---------- booking ---------- */

    /**
     * Books a time. The check and the insert run in one transaction after the rows of the people concerned are locked, so
     * a second visitor asking for the same time waits and then learns that it is taken.
     *
     * @param array<string, mixed> $input service_id, staff_id (0 = anyone), slot "YYYY-MM-DD HH:MM", name, email, phone, note, source, language, by (customer | admin | claude)
     * @return array{0: array<string, mixed>|null, 1: ?string} [the booking with 'token', null] or [null, error: service | slot | taken | name | email | phone]
     */
    public static function book(App $app, array $input): array
    {
        $db = $app->db();
        $service = self::service($db, (int) ($input['service_id'] ?? 0), true);
        if ($service === null) {
            return [null, 'service'];
        }
        $slot = trim((string) ($input['slot'] ?? ''));
        if (!preg_match(self::SLOT_PATTERN, $slot, $m) || strtotime($slot) === false) {
            return [null, 'slot'];
        }
        $name = mb_substr(trim(strip_tags((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($input['name'] ?? '')))), 0, 150);
        if ($name === '') {
            return [null, 'name'];
        }
        $by = in_array($input['by'] ?? '', ['admin', 'claude'], true) ? (string) $input['by'] : 'customer';
        // a visitor must leave an e-mail (the confirmation and the cancel link go there); a booking taken by phone may have none
        $email = trim((string) ($input['email'] ?? ''));
        if (($email === '' && $by === 'customer') || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return [null, 'email'];
        }
        $phone = trim((string) ($input['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[+()\d\s\/.-]{6,30}$/', $phone)) {
            return [null, 'phone'];
        }
        $staffId = (int) ($input['staff_id'] ?? 0);
        $members = self::candidates($db, $service['id'], $staffId);
        if ($members === []) {
            return [null, 'slot'];
        }
        $token = bin2hex(random_bytes(16));
        $now = new \DateTimeImmutable();
        $result = $db->transaction(function (Db $db) use ($app, $service, $members, $m, $slot, $name, $email, $phone, $input, $by, $token, $now): array {
            $ids = array_map(fn (array $x): int => (int) $x['id'], $members);
            sort($ids); // always in the same order – two bookings never wait for each other
            $db->all('SELECT id FROM {booking_staff} WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE');
            $db->all('SELECT id FROM {bookings} WHERE staff_id IN (' . implode(',', $ids) . ') AND starts_at >= ? AND starts_at < ? FOR UPDATE', [$m[1] . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($m[1] . ' +1 day'))]);
            // the free times again, now with the rows locked: what another request booked a moment ago is busy now
            $free = self::freeFrom($app, self::calendar($app, $members, (int) $service['id'], $m[1], $m[1]), $members, $service, $m[1], $now, $by !== 'customer');
            if (!isset($free[$m[2]])) {
                return [null, 'taken'];
            }
            $counts = [];
            foreach ($db->all("SELECT staff_id, COUNT(*) AS n FROM {bookings} WHERE staff_id IN (" . implode(',', $ids) . ") AND status IN ('confirmed', 'pending') AND starts_at >= ? AND starts_at < ? GROUP BY staff_id", [$m[1] . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($m[1] . ' +1 day'))]) as $c) {
                $counts[(int) $c['staff_id']] = (int) $c['n'];
            }
            $chosen = self::leastBooked($free[$m[2]], $counts, array_map(fn (array $x): int => (int) $x['id'], $members));
            if ($chosen === null) {
                return [null, 'taken'];
            }
            $start = new \DateTimeImmutable($slot);
            // 3.3: a service that needs the provider's confirmation holds the time as pending; a booking entered by hand is binding
            $pending = $by === 'customer' && !empty($service['requires_confirmation']);
            $row = ['service_id' => $service['id'], 'staff_id' => $chosen, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->modify('+' . $service['duration_min'] . ' minutes')->format('Y-m-d H:i:s'),
                'name' => $name, 'email' => mb_substr($email, 0, 190), 'phone' => mb_substr($phone, 0, 40), 'note' => mb_substr(trim(strip_tags((string) ($input['note'] ?? ''))), 0, 1000),
                'status' => $pending ? 'pending' : 'confirmed', 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s'),
                'hold_until' => $pending ? min(date('Y-m-d H:i:s', strtotime('+' . max(1, $app->settings()->int('booking_hold_hours')) . ' hours')), $start->format('Y-m-d H:i:s')) : null,
                'source' => mb_substr((string) ($input['source'] ?? ''), 0, 255), 'language' => Language::offeredOrDefault($input['language'] ?? '')];
            $row['id'] = $db->insert('bookings', $row);

            return [$row, null];
        });
        if ($result[0] === null) {
            return $result;
        }
        $booking = self::find($db, (int) $result[0]['id']) ?? $result[0];
        $booking['token'] = $token;
        Events::record($db, 'booking.created', 'info', t('An appointment was booked: %s, %s', $service['name'], self::when($booking['starts_at'], $booking['ends_at'])),
            ['booking' => (int) $booking['id'], 'service' => $service['id'], 'staff' => (int) $booking['staff_id'], 'by' => $by]); // never the customer
        if ($by !== 'customer') {
            ChangeLog::write($app, 'bookings', 'create', '#' . $booking['id'] . ' ' . $service['name'] . ' ' . $booking['starts_at']);
        }
        if ($booking['status'] === 'pending') {
            self::sendPending($app, $booking);
            self::notifyStaff($app, $booking, 'pending');
        } else {
            self::sendConfirmation($app, $booking);
            self::notifyStaff($app, $booking, 'new');
        }

        return [$booking, null];
    }

    /**
     * One booking with the names of its service and person; null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public static function find(Db $db, int $id): ?array
    {
        return $id > 0 ? $db->one('SELECT b.*, s.name AS service, p.name AS staff, p.email AS staff_email FROM {bookings} b LEFT JOIN {booking_services} s ON s.id = b.service_id LEFT JOIN {booking_staff} p ON p.id = b.staff_id WHERE b.id = ?', [$id]) : null;
    }

    /** The booking of a customer's token (the cancel and .ics links). @return array<string, mixed>|null */
    public static function byToken(Db $db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            return null;
        }
        $id = $db->value('SELECT id FROM {bookings} WHERE token_hash = ?', [hash('sha256', $token)]);

        return $id === null ? null : self::find($db, (int) $id);
    }

    /**
     * Bookings for the admin and Claude: by day range, person, service and status, the earliest first.
     *
     * @param array{from?: string, to?: string, staff?: int, service?: int, status?: string, limit?: int} $filter
     * @return list<array<string, mixed>>
     */
    public static function list(Db $db, array $filter): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($filter['from'] ?? ''))) {
            $where[] = 'b.starts_at >= ?';
            $params[] = $filter['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($filter['to'] ?? ''))) {
            $where[] = 'b.starts_at < ?';
            $params[] = date('Y-m-d 00:00:00', strtotime($filter['to'] . ' +1 day'));
        }
        if (($filter['staff'] ?? 0) > 0) {
            $where[] = 'b.staff_id = ?';
            $params[] = (int) $filter['staff'];
        }
        if (($filter['service'] ?? 0) > 0) {
            $where[] = 'b.service_id = ?';
            $params[] = (int) $filter['service'];
        }
        if (($filter['status'] ?? '') === 'active') {
            $where[] = "b.status IN ('confirmed', 'pending')"; // what still has to happen
        } elseif (isset(self::STATUSES[$filter['status'] ?? ''])) {
            $where[] = 'b.status = ?';
            $params[] = $filter['status'];
        }

        return $db->all('SELECT b.*, s.name AS service, p.name AS staff FROM {bookings} b LEFT JOIN {booking_services} s ON s.id = b.service_id LEFT JOIN {booking_staff} p ON p.id = b.staff_id WHERE '
            . implode(' AND ', $where) . ' ORDER BY b.starts_at, b.id LIMIT ' . max(1, min(500, (int) ($filter['limit'] ?? 200))), $params);
    }

    /** Marks a booking done or did not come (only a confirmed one, after it started). */
    public static function setStatus(App $app, array $booking, string $status): bool
    {
        if (!in_array($status, ['done', 'no_show'], true) || $booking['status'] !== 'confirmed') {
            return false;
        }
        $app->db()->update('bookings', ['status' => $status], ['id' => (int) $booking['id']]);
        ChangeLog::write($app, 'bookings', $status, '#' . $booking['id']);

        return true;
    }

    /**
     * Cancels a confirmed booking. By the customer (the link) the business is told; by the admin or Claude the customer
     * gets an e-mail.
     */
    public static function cancel(App $app, array $booking, string $by): bool
    {
        if ($booking['status'] !== 'confirmed') {
            return false;
        }
        $db = $app->db();
        if ($db->run("UPDATE {bookings} SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ? WHERE id = ? AND status = 'confirmed'", [$by, (int) $booking['id']])->rowCount() === 0) {
            return false;
        }
        $booking = self::find($db, (int) $booking['id']) ?? $booking;
        Events::record($db, 'booking.cancelled', 'info', t('An appointment was cancelled: %s, %s', (string) ($booking['service'] ?? ''), self::when($booking['starts_at'], $booking['ends_at'])),
            ['booking' => (int) $booking['id'], 'by' => $by]);
        if ($by !== 'customer') {
            ChangeLog::write($app, 'bookings', 'cancel', '#' . $booking['id']);
            self::sendCancelled($app, $booking);
        }
        self::notifyStaff($app, $booking, 'cancelled');

        return true;
    }

    /* ---------- bookings that need the provider's confirmation (3.3) ---------- */

    /** A new token for the customer's links (the old ones stop working); only the hash is stored, so a mail needing a link makes a new one. */
    private static function rotateToken(Db $db, int $id): string
    {
        $token = bin2hex(random_bytes(16));
        $db->update('bookings', ['token_hash' => hash('sha256', $token)], ['id' => $id]);

        return $token;
    }

    /** Whether another booking occupies the person at that time (a pending one only while its hold lasts). */
    private static function occupied(Db $db, int $staffId, string $start, string $end, int $exclude): bool
    {
        return (int) $db->value("SELECT COUNT(*) FROM {bookings} WHERE staff_id = ? AND id <> ? AND (status IN ('confirmed', 'done') OR (status = 'pending' AND hold_until > NOW())) AND starts_at < ? AND ends_at > ?",
            [$staffId, $exclude, $end, $start]) > 0;
    }

    /**
     * The provider accepts a pending booking: it becomes confirmed and the customer gets the regular confirmation.
     * Returns null, or the error text (the hold ran out and somebody else took the time meanwhile).
     */
    public static function confirm(App $app, array $booking, string $by): ?string
    {
        $db = $app->db();
        if ($booking['status'] !== 'pending') {
            return 'Only a booking waiting for confirmation can be accepted.';
        }
        if (self::occupied($db, (int) $booking['staff_id'], (string) $booking['starts_at'], (string) $booking['ends_at'], (int) $booking['id'])) {
            return 'This time has been taken meanwhile – propose other times or decline the request.';
        }
        if ($db->run("UPDATE {bookings} SET status = 'confirmed', hold_until = NULL WHERE id = ? AND status = 'pending'", [(int) $booking['id']])->rowCount() === 0) {
            return 'Only a booking waiting for confirmation can be accepted.';
        }
        $db->delete('booking_proposals', ['booking_id' => (int) $booking['id']]);
        $token = self::rotateToken($db, (int) $booking['id']);
        $booking = (self::find($db, (int) $booking['id']) ?? $booking) + ['token' => $token];
        ChangeLog::write($app, $by === 'claude' ? 'claude' : 'bookings', 'confirm', '#' . $booking['id']);
        Events::record($db, 'booking.confirmed', 'info', t('An appointment was accepted: %s, %s', (string) ($booking['service'] ?? ''), self::when((string) $booking['starts_at'], (string) $booking['ends_at'])), ['booking' => (int) $booking['id'], 'by' => $by]);
        self::sendConfirmation($app, $booking);

        return null;
    }

    /** The provider declines a pending booking: the time is free again, the customer is told – with a personal message when given. */
    public static function decline(App $app, array $booking, string $message, string $by): bool
    {
        $db = $app->db();
        if ($booking['status'] !== 'pending'
            || $db->run("UPDATE {bookings} SET status = 'declined', hold_until = NULL, cancelled_at = NOW(), cancelled_by = ? WHERE id = ? AND status = 'pending'", [$by, (int) $booking['id']])->rowCount() === 0) {
            return false;
        }
        $db->delete('booking_proposals', ['booking_id' => (int) $booking['id']]);
        $booking = self::find($db, (int) $booking['id']) ?? $booking;
        ChangeLog::write($app, $by === 'claude' ? 'claude' : 'bookings', 'decline', '#' . $booking['id']);
        Events::record($db, 'booking.declined', 'info', t('An appointment request was declined: %s, %s', (string) ($booking['service'] ?? ''), self::when((string) $booking['starts_at'], (string) $booking['ends_at'])), ['booking' => (int) $booking['id'], 'by' => $by]);
        self::sendDeclined($app, $booking, mb_substr(trim(strip_tags($message)), 0, 1000));

        return true;
    }

    /** Other times proposed for a pending booking, the earliest first. @return list<array{id: int, starts_at: string, ends_at: string}> */
    public static function proposals(Db $db, int $bookingId): array
    {
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'starts_at' => (string) $r['starts_at'], 'ends_at' => (string) $r['ends_at']],
            $db->all('SELECT id, starts_at, ends_at FROM {booking_proposals} WHERE booking_id = ? ORDER BY starts_at, id', [$bookingId]));
    }

    /**
     * The provider proposes one to three other times (each must be free for the person). The booking stays pending, its own
     * time stays held, the hold starts again; the customer picks one with the link in the e-mail (acceptProposal()).
     *
     * @param list<string> $slots "YYYY-MM-DD HH:MM"
     */
    public static function propose(App $app, array $booking, array $slots, string $message, string $by): ?string
    {
        $db = $app->db();
        if ($booking['status'] !== 'pending') {
            return 'Only a booking waiting for confirmation can get other times.';
        }
        if ((string) $booking['email'] === '') {
            return 'The customer left no e-mail address – call them, or decline the request.';
        }
        $slots = array_values(array_unique(array_filter(array_map('trim', $slots), fn (string $x): bool => $x !== '')));
        if ($slots === [] || count($slots) > self::MAX_PROPOSALS) {
            return 'Propose one to three times.';
        }
        $service = self::service($db, (int) $booking['service_id']);
        if ($service === null) {
            return 'The service does not exist.';
        }
        $rows = [];
        foreach ($slots as $slot) {
            if (!preg_match(self::SLOT_PATTERN, $slot, $m) || strtotime($slot) === false) {
                return 'Enter every time as YYYY-MM-DD HH:MM.';
            }
            if (!isset(self::availability($app, $service, (int) $booking['staff_id'], $m[1], null, true, (int) $booking['id'])[$m[2]])) {
                return 'One of the times is not free for this person – pick them from the free times.';
            }
            $rows[] = [$slot . ':00', date('Y-m-d H:i:s', strtotime($slot . ' +' . $service['duration_min'] . ' minutes'))];
        }
        $db->transaction(function (Db $db) use ($booking, $rows, $app): void {
            $db->delete('booking_proposals', ['booking_id' => (int) $booking['id']]);
            foreach ($rows as [$start, $end]) {
                $db->insert('booking_proposals', ['booking_id' => (int) $booking['id'], 'starts_at' => $start, 'ends_at' => $end]);
            }
            $db->run('UPDATE {bookings} SET hold_until = ?, hold_reminded_at = NULL WHERE id = ?', [date('Y-m-d H:i:s', strtotime('+' . max(1, $app->settings()->int('booking_hold_hours')) . ' hours')), (int) $booking['id']]);
        });
        $token = self::rotateToken($db, (int) $booking['id']);
        $booking = (self::find($db, (int) $booking['id']) ?? $booking) + ['token' => $token];
        ChangeLog::write($app, $by === 'claude' ? 'claude' : 'bookings', 'propose', '#' . $booking['id']);
        self::sendProposal($app, $booking, self::proposals($db, (int) $booking['id']), mb_substr(trim(strip_tags($message)), 0, 1000));

        return null;
    }

    /**
     * The customer picks one of the proposed times (the link in the e-mail): the booking moves there and is confirmed.
     * Returns null, or the error text.
     */
    public static function acceptProposal(App $app, array $booking, int $proposalId): ?string
    {
        $db = $app->db();
        if ($booking['status'] !== 'pending') {
            return 'This request is no longer waiting for an answer.';
        }
        $proposal = null;
        foreach (self::proposals($db, (int) $booking['id']) as $p) {
            if ($p['id'] === $proposalId) {
                $proposal = $p;
            }
        }
        $service = self::service($db, (int) $booking['service_id']);
        if ($proposal === null || $service === null) {
            return 'This time is not on offer.';
        }
        $error = $db->transaction(function (Db $db) use ($app, $booking, $proposal, $service): ?string {
            $db->all('SELECT id FROM {booking_staff} WHERE id = ? FOR UPDATE', [(int) $booking['staff_id']]);
            $day = substr($proposal['starts_at'], 0, 10);
            if (!isset(self::availability($app, $service, (int) $booking['staff_id'], $day, null, true, (int) $booking['id'])[substr($proposal['starts_at'], 11, 5)])) {
                return 'Sorry, this time has just been taken. Please choose another one.';
            }
            // a second submit (double click, back and resend) finds the booking confirmed already: no second e-mail and no new
            // token, which would break the links in the first confirmation (3.4.2, N34-3)
            if ($db->run("UPDATE {bookings} SET starts_at = ?, ends_at = ?, status = 'confirmed', hold_until = NULL WHERE id = ? AND status = 'pending'", [$proposal['starts_at'], $proposal['ends_at'], (int) $booking['id']])->rowCount() === 0) {
                return 'This request is no longer waiting for an answer.';
            }
            $db->delete('booking_proposals', ['booking_id' => (int) $booking['id']]);

            return null;
        });
        if ($error !== null) {
            return $error;
        }
        $token = self::rotateToken($db, (int) $booking['id']);
        $booking = (self::find($db, (int) $booking['id']) ?? $booking) + ['token' => $token];
        Events::record($db, 'booking.confirmed', 'info', t('An appointment was accepted: %s, %s', (string) ($booking['service'] ?? ''), self::when((string) $booking['starts_at'], (string) $booking['ends_at'])), ['booking' => (int) $booking['id'], 'by' => 'customer']);
        self::sendConfirmation($app, $booking);
        self::notifyStaff($app, $booking, 'accepted');

        return null;
    }

    /** Until when the customer may cancel by the link: the start minus the set hours. */
    public static function cancelDeadline(Settings $s, array $booking): \DateTimeImmutable
    {
        return (new \DateTimeImmutable((string) $booking['starts_at']))->modify('-' . max(0, $s->int('booking_cancel_hours')) . ' hours');
    }

    /** "Tue 6 Oct 2026 10:00–10:30" in the current language. */
    public static function when(string $start, string $end): string
    {
        return format_date($start, true) . '–' . substr($end, 11, 5);
    }

    /* ---------- e-mails ---------- */

    /** Runs a callback with the site texts in the customer's language version. @template T @param callable(): T $fn @return T */
    private static function inLanguage(App $app, array $booking, callable $fn): mixed
    {
        $language = (string) ($booking['language'] ?? '');

        return Language::runWith($language !== '' ? $language : Language::defaults($app->settings()), $fn);
    }

    /** The absolute address of a booking link (site_url, or the origin of the request). */
    private static function absolute(App $app, string $path): string
    {
        $s = $app->settings();

        return rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/') . $app->url($path);
    }

    /** The place of the appointment: the company name and address from Business details. */
    private static function place(Settings $s): string
    {
        return implode(', ', array_filter([$s->get('company_name'), ...Company::address($s)]));
    }

    /**
     * The lines every customer e-mail shares: the service, the person, when, where.
     *
     * @return list<string>
     */
    private static function details(App $app, array $booking): array
    {
        $s = $app->settings();
        $lines = [t('Service') . ': ' . (string) ($booking['service'] ?? ''), t('With') . ': ' . (string) ($booking['staff'] ?? ''), t('When') . ': ' . self::when((string) $booking['starts_at'], (string) $booking['ends_at'])];
        if (self::place($s) !== '') {
            $lines[] = t('Where') . ': ' . self::place($s);
        }

        return $lines;
    }

    private static function signature(App $app): string
    {
        $s = $app->settings();

        return "\n\n—\n" . $s->get('site_name') . "\n" . rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/');
    }

    /** The confirmation to the customer, in their language: the details, the .ics link and the cancel link with its deadline. */
    public static function sendConfirmation(App $app, array $booking): bool
    {
        if (($booking['email'] ?? '') === '' || ($booking['token'] ?? '') === '') {
            return false;
        }

        return self::inLanguage($app, $booking, function () use ($app, $booking): bool {
            $s = $app->settings();
            $text = t('Thank you, your appointment is booked.') . "\n\n" . implode("\n", self::details($app, $booking))
                . "\n\n" . t('Add it to your calendar: %s', self::absolute($app, '_booking/ics/' . $booking['token']))
                . "\n\n" . t('If you cannot come, cancel the appointment by %s with this link: %s', format_date(self::cancelDeadline($s, $booking), true), self::absolute($app, '_booking/cancel/' . $booking['token']))
                . self::signature($app);

            return Mail::send($s, (string) $booking['email'], t('Your appointment on %s – %s', format_date((string) $booking['starts_at'], true), $s->get('site_name')), $text);
        });
    }

    /** The customer is told that the business cancelled. */
    private static function sendCancelled(App $app, array $booking): bool
    {
        if (($booking['email'] ?? '') === '') {
            return false;
        }

        return self::inLanguage($app, $booking, function () use ($app, $booking): bool {
            $s = $app->settings();
            $text = t('We are sorry – we had to cancel your appointment. Please book another time on our site, or contact us.') . "\n\n" . implode("\n", self::details($app, $booking)) . self::signature($app);

            return Mail::send($s, (string) $booking['email'], t('Your appointment on %s was cancelled – %s', format_date((string) $booking['starts_at'], true), $s->get('site_name')), $text);
        });
    }

    /** The reminder before the appointment, in the customer's language; the cancel link only while it still works. */
    private static function sendReminder(App $app, array $booking): bool
    {
        return self::inLanguage($app, $booking, function () use ($app, $booking): bool {
            $s = $app->settings();
            $text = t('A reminder of your appointment with us.') . "\n\n" . implode("\n", self::details($app, $booking)) . self::signature($app);

            return Mail::send($s, (string) $booking['email'], t('Reminder: your appointment on %s – %s', format_date((string) $booking['starts_at'], true), $s->get('site_name')), $text);
        });
    }

    /**
     * The business is told about a new or a cancelled booking: the person's e-mail, or the site e-mail when they have
     * none. In the site's default language, with a link to the booking in the administration.
     */
    private static function notifyStaff(App $app, array $booking, string $what): bool
    {
        $s = $app->settings();
        $recipient = filter_var((string) ($booking['staff_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false ? (string) $booking['staff_email'] : $s->get('site_email');
        if ($recipient === '') {
            return false;
        }

        return Language::runWith(Language::defaults($s), function () use ($app, $booking, $what, $recipient, $s): bool {
            $customer = [t('Customer') . ': ' . (string) $booking['name'], t('E-mail') . ': ' . (string) $booking['email'], t('Phone') . ': ' . ((string) $booking['phone'] !== '' ? (string) $booking['phone'] : '—')];
            if ((string) $booking['note'] !== '') {
                $customer[] = t('Note') . ': ' . (string) $booking['note'];
            }
            $text = implode("\n", self::details($app, $booking)) . "\n\n" . implode("\n", $customer)
                . "\n\n—\n" . t('The booking in the administration: %s', self::absolute($app, 'admin.php?module=bookings&action=detail&id=' . (int) $booking['id']));
            $when = self::when((string) $booking['starts_at'], (string) $booking['ends_at']);
            $subject = match ($what) {
                'cancelled' => t('Booking cancelled: %s, %s', (string) $booking['service'], $when),
                'pending' => t('Request waiting for your answer: %s, %s', (string) $booking['service'], $when),
                'hold_expired' => t('Still waiting for your answer: %s, %s', (string) $booking['service'], $when),
                'accepted' => t('The customer accepted the proposed time: %s, %s', (string) $booking['service'], $when),
                default => t('New booking: %s, %s', (string) $booking['service'], $when),
            };
            if ($what === 'pending' || $what === 'hold_expired') {
                $text .= "\n" . t('Accept it, decline it or propose other times there.');
            }

            return Mail::send($s, $recipient, $subject, $text, '', (string) $booking['email'] !== '' ? ['Reply-To' => (string) $booking['email']] : []);
        });
    }

    /** An own text of the settings (empty = none); {name} is the customer's name, so the form of address and the tone are the site's own. */
    private static function ownText(App $app, string $key, array $booking): string
    {
        $text = trim($app->settings()->get($key));

        return $text === '' ? '' : str_replace('{name}', (string) $booking['name'], $text);
    }

    /**
     * A booking setting as the Bookings settings form stores it – the same limits for the form and for update_settings over
     * MCP (their keys are in Mcp\Tools::MCP_SETTINGS, but the settings form types of Admin\Modules\Settings do not know them).
     * Null = not a booking setting, or not a whole number where one is needed.
     */
    public static function settingValue(string $key, string $value): ?string
    {
        $numbers = ['booking_lead_hours' => [0, 720], 'booking_horizon_days' => [1, 365], 'booking_cancel_hours' => [0, 720], 'booking_reminder_hours' => [0, 168], 'booking_hold_hours' => [1, 720]];
        if (isset($numbers[$key])) {
            return preg_match('/^-?\d{1,6}$/', trim($value)) === 1 ? (string) max($numbers[$key][0], min($numbers[$key][1], (int) trim($value))) : null;
        }
        // own texts: empty = the built-in one; {name} is the customer's name (form of address and tone are the site's)
        $texts = ['booking_pending_thanks' => 400, 'booking_pending_mail' => 1000, 'booking_declined_mail' => 1000];

        return isset($texts[$key]) ? mb_substr(trim(strip_tags($value)), 0, $texts[$key]) : null;
    }

    /** The thank-you after a request for a service that needs confirmation (the element shows it; empty setting = the built-in text). */
    public static function pendingThanks(Settings $s): string
    {
        return trim($s->get('booking_pending_thanks')) !== '' ? trim($s->get('booking_pending_thanks')) : t('Thank you, we have received your request and will get back to you soon. A note is on its way to your e-mail.');
    }

    /** The acknowledgement to the customer after a request: not a confirmation – the provider still has to say yes. */
    private static function sendPending(App $app, array $booking): bool
    {
        if (($booking['email'] ?? '') === '') {
            return false;
        }

        return self::inLanguage($app, $booking, function () use ($app, $booking): bool {
            $s = $app->settings();
            $intro = self::ownText($app, 'booking_pending_mail', $booking) ?: t('Thank you, we have received your request for an appointment. We will check it and get back to you – it is not confirmed yet.');
            $text = $intro . "\n\n" . implode("\n", self::details($app, $booking)) . self::signature($app);

            return Mail::send($s, (string) $booking['email'], t('We received your request for %s – %s', format_date((string) $booking['starts_at'], true), $s->get('site_name')), $text);
        });
    }

    /** The decline to the customer, with the provider's personal message when there is one. */
    private static function sendDeclined(App $app, array $booking, string $message): bool
    {
        if (($booking['email'] ?? '') === '') {
            return false;
        }

        return self::inLanguage($app, $booking, function () use ($app, $booking, $message): bool {
            $s = $app->settings();
            $intro = self::ownText($app, 'booking_declined_mail', $booking) ?: t('Unfortunately we cannot offer you the requested time. We are sorry.');
            $text = $intro . ($message !== '' ? "\n\n" . $message : '') . "\n\n" . implode("\n", self::details($app, $booking)) . self::signature($app);

            return Mail::send($s, (string) $booking['email'], t('Your request for %s – %s', format_date((string) $booking['starts_at'], true), $s->get('site_name')), $text);
        });
    }

    /**
     * The proposal to the customer: the other times and the link where they pick one.
     *
     * @param list<array{id: int, starts_at: string, ends_at: string}> $proposals
     */
    private static function sendProposal(App $app, array $booking, array $proposals, string $message): bool
    {
        if (($booking['email'] ?? '') === '' || ($booking['token'] ?? '') === '') {
            return false;
        }

        return self::inLanguage($app, $booking, function () use ($app, $booking, $proposals, $message): bool {
            $s = $app->settings();
            $times = implode("\n", array_map(fn (array $p): string => '– ' . self::when($p['starts_at'], $p['ends_at']), $proposals));
            $text = t('Unfortunately we cannot offer you the requested time, but these times are free:') . "\n\n" . $times . ($message !== '' ? "\n\n" . $message : '')
                . "\n\n" . t('Pick one with this link: %s', self::absolute($app, '_booking/choose/' . $booking['token']))
                . "\n\n" . t('Your original request:') . "\n" . implode("\n", self::details($app, $booking)) . self::signature($app);

            return Mail::send($s, (string) $booking['email'], t('Other times for your request – %s', $s->get('site_name')), $text);
        });
    }

    /**
     * The hourly part of the reminder job: the provider is reminded once of every pending request whose hold ran out
     * without an answer. The customer gets nothing – no automatic rejection.
     */
    private static function remindHolds(App $app): int
    {
        $db = $app->db();
        $sent = 0;
        try {
            $rows = $db->all("SELECT id FROM {bookings} WHERE status = 'pending' AND hold_reminded_at IS NULL AND hold_until < NOW() AND ends_at > NOW() ORDER BY starts_at LIMIT 50");
        } catch (\Throwable) {
            return 0; // before the 3.3 migration
        }
        foreach ($rows as $r) {
            if ($db->run('UPDATE {bookings} SET hold_reminded_at = NOW() WHERE id = ? AND hold_reminded_at IS NULL', [(int) $r['id']])->rowCount() === 0) {
                continue;
            }
            $booking = self::find($db, (int) $r['id']);
            if ($booking !== null && self::notifyStaff($app, $booking, 'hold_expired')) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The hourly reminder job (Core\Scheduler): confirmed bookings starting within the set hours, each reminded once
     * (reminded_at). A booking made inside the window gets no reminder – the confirmation just arrived.
     */
    public static function remind(App $app): string
    {
        $hours = $app->settings()->int('booking_reminder_hours');
        if (!self::isOn($app->settings())) {
            return 'off';
        }
        $held = self::remindHolds($app);
        if ($hours <= 0) {
            return $held > 0 ? 'holds ' . $held : 'off';
        }
        $db = $app->db();
        $sent = 0;
        try {
            $rows = $db->all("SELECT id FROM {bookings} WHERE status = 'confirmed' AND reminded_at IS NULL AND email <> '' AND starts_at > NOW() AND starts_at <= NOW() + INTERVAL ? HOUR AND created_at <= starts_at - INTERVAL ? HOUR ORDER BY starts_at LIMIT 50", [$hours, $hours]);
        } catch (\Throwable) {
            return 'skipped'; // before the 3.0 migration
        }
        foreach ($rows as $r) {
            // mark first: a failing mail server must not send the reminder again and again
            if ($db->run('UPDATE {bookings} SET reminded_at = NOW() WHERE id = ? AND reminded_at IS NULL', [(int) $r['id']])->rowCount() === 0) {
                continue;
            }
            $booking = self::find($db, (int) $r['id']);
            if ($booking !== null && self::sendReminder($app, $booking)) {
                $sent++;
            }
        }

        return 'sent ' . $sent . ($held > 0 ? ', holds ' . $held : '');
    }

    /* ---------- personal data ---------- */

    /**
     * Bookings past the enquiry retention (enquiries_months): deleted, or – with enquiries_expiry = anonymise – kept as
     * rows without the person for statistics. Called by the background clean-up (Core\Notifications). Returns the count.
     */
    public static function purge(App $app): int
    {
        $s = $app->settings();
        $months = $s->int('enquiries_months');
        if ($months <= 0) {
            return 0;
        }
        try {
            if ($s->get('enquiries_expiry') === 'anonymise') {
                return $app->db()->run("UPDATE {bookings} SET name = '', email = '', phone = '', note = '', anonymised_at = NOW() WHERE anonymised_at IS NULL AND ends_at < NOW() - INTERVAL ? MONTH", [$months])->rowCount();
            }

            return $app->db()->run('DELETE FROM {bookings} WHERE ends_at < NOW() - INTERVAL ? MONTH', [$months])->rowCount();
        } catch (\Throwable) {
            return 0; // before the 3.0 migration
        }
    }

    /** Blanks the person in one booking and keeps the row (the admin's Anonymise). */
    public static function anonymise(App $app, int $id): bool
    {
        $done = $app->db()->run("UPDATE {bookings} SET name = '', email = '', phone = '', note = '', anonymised_at = NOW() WHERE id = ? AND anonymised_at IS NULL", [$id])->rowCount() > 0;
        if ($done) {
            ChangeLog::write($app, 'bookings', 'anonymise', '#' . $id);
        }

        return $done;
    }

    /* ---------- the .ics file ---------- */

    /** One appointment as an iCalendar file for the customer's calendar (Core\Calendar helpers). */
    public static function ics(App $app, array $booking, string $token): string
    {
        $s = $app->settings();
        $host = (string) (parse_url($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), PHP_URL_HOST) ?: 'kaleta.invalid');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kaleta//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT', 'UID:kaleta-booking-' . (int) $booking['id'] . '@' . $host, 'DTSTAMP:' . Calendar::utc((string) $booking['created_at']),
            'DTSTART:' . Calendar::utc((string) $booking['starts_at']), 'DTEND:' . Calendar::utc((string) $booking['ends_at']),
            'SUMMARY:' . Calendar::escape((string) $booking['service'] . ' – ' . $s->get('site_name'))];
        if (self::place($s) !== '') {
            $lines[] = 'LOCATION:' . Calendar::escape(self::place($s));
        }
        $cancel = self::absolute($app, '_booking/cancel/' . $token);
        $lines[] = 'DESCRIPTION:' . Calendar::escape(t('With') . ': ' . (string) $booking['staff'] . "\n" . $cancel);
        $lines[] = 'URL:' . Calendar::escape($cancel);
        if (in_array($booking['status'], ['cancelled', 'declined'], true)) {
            $lines[] = 'STATUS:CANCELLED';
        }
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');

        return implode("\r\n", array_map(Calendar::fold(...), $lines)) . "\r\n";
    }

    /* ---------- the set-up (3.5) ---------- */

    /** How many days ahead the Bookings screen and booking_availability look for a free time. */
    public const int FREE_DAYS = 14;

    /** The weekly hours a new person starts with when the site has no opening hours: Monday to Friday, 9:00–17:00. */
    public const array STARTER_HOURS = [1 => [['09:00', '17:00']], 2 => [['09:00', '17:00']], 3 => [['09:00', '17:00']], 4 => [['09:00', '17:00']], 5 => [['09:00', '17:00']]];

    /**
     * How far the set-up is: a person who takes bookings, a service such a person offers, and a page with the Booking
     * element (published or a draft). The Bookings screen shows the set-up card until all three are done.
     *
     * @return array{person: bool, service: bool, page: bool}
     */
    public static function setup(Db $db): array
    {
        $element = '%"typ":"' . \Kaleta\Builder\Elements\Booking::TYPE . '"%';

        return [
            'person' => $db->value('SELECT 1 FROM {booking_staff} WHERE active = 1 LIMIT 1') !== null,
            'service' => $db->value('SELECT 1 FROM {booking_services} s JOIN {booking_staff_services} l ON l.service_id = s.id JOIN {booking_staff} m ON m.id = l.staff_id WHERE s.active = 1 AND m.active = 1 LIMIT 1') !== null,
            'page' => $db->value('SELECT 1 FROM {stranky} WHERE smazano IS NULL AND (stavba LIKE ? OR stavba_koncept LIKE ?) LIMIT 1', [$element, $element]) !== null
                || $db->value('SELECT 1 FROM {casti} WHERE stavba LIKE ? OR stavba_koncept LIKE ? LIMIT 1', [$element, $element]) !== null,
        ];
    }

    /**
     * Why a visitor could not book anything in the next FREE_DAYS days, or null when some service has a free time (or no
     * service is offered yet – the set-up card says what is missing). [English source text, the name of the person
     * concerned]: the admin shows it through t(), Claude gets it with the name filled in.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function noFreeTime(App $app, ?\DateTimeImmutable $now = null): ?array
    {
        $db = $app->db();
        $now ??= new \DateTimeImmutable();
        $last = $now->modify('+' . (self::FREE_DAYS - 1) . ' days')->format('Y-m-d');
        $offered = false;
        foreach (self::services($db) as $service) {
            $members = self::candidates($db, (int) $service['id'], 0);
            if ($members === []) {
                continue;
            }
            $offered = true;
            $cal = self::calendar($app, $members, (int) $service['id'], $now->format('Y-m-d'), $last); // the hours kept for this service count (3.5, #24)
            for ($d = $now; $d->format('Y-m-d') <= $last; $d = $d->modify('+1 day')) {
                if (self::freeFrom($app, $cal, $members, $service, $d->format('Y-m-d'), $now, false) !== []) {
                    return null;
                }
            }
        }
        if (!$offered) {
            return null;
        }
        // the usual cause: a person without hours of their own takes the site's opening hours, and the site has none
        if (array_filter(Hours::week($app->settings())) === []) {
            foreach (self::staff($db) as $m) {
                if ($m['services'] !== [] && self::hours($db, (int) $m['id']) === [] && self::serviceHours($db, (int) $m['id']) === []) {
                    return ['No free time in the next 14 days: %s has no weekly hours and the site has no opening hours, so no time is offered. Give them weekly hours, or fill in the opening hours in Business details.', (string) $m['name']];
                }
            }
        }

        return ['No free time in the next 14 days – visitors see an empty calendar. Check the weekly hours and days off of the people, and the earliest booking and how far ahead visitors may book.', ''];
    }
}
