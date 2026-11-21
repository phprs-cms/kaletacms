<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\ChangeLog;
use Kaleta\Core\Booking;
use Kaleta\Mcp\Schema;

/**
 * MCP tools for online booking (3.0, Core\Booking): the set-up (services, people with their hours and days off), the free
 * times of a day, the bookings themselves – personal data, read only with the Bookings section and logged like enquiries –
 * and cancelling one. Part of Mcp\Tools. They work only while the Bookings feature is on (3.2).
 *
 * @phpstan-ignore trait.unused
 */
trait BookingTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function bookingToolsDefinitions(): array
    {
        return [
            'list_bookings' => ['Online bookings of appointments (3.0, users with the Bookings section): by day range (default today and the next 30 days), person, service and status, the earliest first – the service, the person, when, the customer\'s name, e-mail, phone and note. Personal data: every read is in the change log; use them only for what the user asks.',
                Schema::object(['from' => Schema::text('first day YYYY-MM-DD (default today)'), 'to' => Schema::text('last day YYYY-MM-DD (default from + 30 days)'), 'staff' => Schema::number('id of the person (optional)'), 'service' => Schema::number('id of the service (optional)'),
                    'status' => Schema::text('active = confirmed and waiting (default) | pending | confirmed | declined | done | no_show | cancelled | all'), 'limit' => Schema::number('1–200, default 100')])],
            'booking_availability' => ['Free times for a service on a day (read-only, 3.0): the times the visitor could book and who is free then; also the services and the people with their hours, so Claude can answer “when could I come”. No personal data.',
                Schema::object(['service' => Schema::number('id of the service (from the services list of this tool when left out)'), 'day' => Schema::text('YYYY-MM-DD (default today)'), 'staff' => Schema::number('id of the person; 0 or left out = anyone who offers the service')])],
            'save_booking_service' => ['Creates or changes a bookable service (administrators, 3.0): the name, the duration in minutes (5–480; the offered times step by it up to an hour), the buffer kept free after it, a price as text (shown only, no payments), a description, active, requires_confirmation (a booking is then a request: pending until accepted, see confirm_booking), and the people who offer it (staff ids – replaces).',
                Schema::object(['id' => Schema::number('only to change an existing service'), 'name' => Schema::text('name, e.g. Haircut'), 'duration_min' => Schema::number('duration in minutes'), 'buffer_min' => Schema::number('minutes kept free after the appointment (0–240)'), 'price_text' => Schema::text('e.g. from 450 CZK (shown as written)'),
                    'description' => Schema::text('a sentence for the visitor'), 'active' => ['type' => 'boolean', 'description' => 'false = not offered (bookings stay)'], 'requires_confirmation' => ['type' => 'boolean', 'description' => 'true = a booking waits for the provider (pending, the time is held)'], 'sort_order' => Schema::number('order, lower = first'),
                    'staff' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ids of the people who offer it (replaces the list)']])],
            'save_booking_staff' => ['Creates or changes a person who takes bookings (administrators, 3.0): the name, the e-mail that gets the notifications (empty = the site e-mail), active, the services they offer (ids – replaces), their weekly hours as an object weekday => ranges (monday or 1 … sunday or 7; "9:00-12:00, 13:00-17:00"; an empty object = the site\'s opening hours; a closed day of the site is a day off for everyone), hours for single services (service_hours: service id => the same object; they replace the weekly hours for that service only, e.g. speed dates on weekday evenings and family shoots at weekends – one person, one calendar, no overlaps) and their days off (a list of {from, to, note} – from and to as YYYY-MM-DD or YYYY-MM-DD HH:MM; replaces).',
                Schema::object(['id' => Schema::number('only to change an existing person'), 'name' => Schema::text('name'), 'email' => Schema::text('e-mail for the notifications (optional)'), 'active' => ['type' => 'boolean', 'description' => 'false = takes no new bookings'], 'sort_order' => Schema::number('order, lower = first'),
                    'services' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ids of the services they offer (replaces the list)'],
                    'hours' => ['type' => 'object', 'description' => 'weekly hours: {"monday": "9:00-12:00, 13:00-17:00", …}; {} = the site\'s opening hours (replaces)'],
                    'service_hours' => ['type' => 'object', 'description' => 'hours per service: {"<service id>": {"saturday": "13:00-18:00", …}}; {} for a service = back to the weekly hours (replaces that service)'],
                    'days_off' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'days off: [{"from": "2026-12-24", "to": "2026-12-26", "note": "Christmas"}] (replaces)']])],
            'cancel_booking' => ['Cancels one booking (users with the Bookings section, 3.0; only when the user explicitly asks): the customer gets an e-mail that the appointment was cancelled, the person a notification. Needs confirm = true.',
                Schema::object(['id' => Schema::number('booking id from list_bookings'), 'confirm' => ['type' => 'boolean', 'description' => 'true = the user asked for it']], ['id'])],
            'confirm_booking' => ['Accepts a pending booking – a request for a service that requires confirmation (users with the Bookings section, 3.3; only when the user explicitly asks): it becomes confirmed and the customer gets the confirmation with the calendar and cancel links. Needs confirm = true.',
                Schema::object(['id' => Schema::number('booking id from list_bookings (status pending)'), 'confirm' => ['type' => 'boolean', 'description' => 'true = the user asked for it']], ['id'])],
            'decline_booking' => ['Declines a pending booking (users with the Bookings section, 3.3; only when the user explicitly asks): the time is free again and the customer gets an e-mail, with the optional personal message. Needs confirm = true.',
                Schema::object(['id' => Schema::number('booking id from list_bookings (status pending)'), 'message' => Schema::text('a personal message for the customer (optional, in the customer\'s language and the site\'s tone)'), 'confirm' => ['type' => 'boolean', 'description' => 'true = the user asked for it']], ['id'])],
            'propose_booking_times' => ['Proposes one to three other times for a pending booking (users with the Bookings section, 3.3; only when the user explicitly asks): each must be free for the person (booking_availability); the customer gets an e-mail with a link to pick one, which confirms the booking. Needs confirm = true.',
                Schema::object(['id' => Schema::number('booking id from list_bookings (status pending)'), 'times' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '1–3 times as YYYY-MM-DD HH:MM'], 'message' => Schema::text('a personal message for the customer (optional)'), 'confirm' => ['type' => 'boolean', 'description' => 'true = the user asked for it']], ['id', 'times'])],
        ];
    }

    /**
     * Bookings are a feature (3.2): the tools stay listed for every connection, but while the feature is off they answer
     * why instead of working on data the administration does not show.
     */
    private function requireBookings(): void
    {
        if (!Booking::isOn($this->app->settings())) {
            throw new \DomainException('Online booking is switched off on this site. An administrator switches it on in the administration under Features (Bookings), or with update_settings by adding "bookings" to extensions – ask the user first.');
        }
    }

    /** list_bookings */
    private function toolListBookings(string $name, array $a): mixed
    {
        $this->requireBookings();
        if (!$this->app->auth()->hasModule('bookings')) {
            throw new \DomainException('Bookings are read only by users with the Bookings section – they hold personal data of customers.');
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($a['from'] ?? '')) ? (string) $a['from'] : date('Y-m-d');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($a['to'] ?? '')) ? (string) $a['to'] : date('Y-m-d', strtotime($from . ' +30 days'));
        $status = (string) ($a['status'] ?? 'active');
        $rows = Booking::list($this->app->db(), ['from' => $from, 'to' => $to, 'staff' => (int) ($a['staff'] ?? 0), 'service' => (int) ($a['service'] ?? 0),
            'status' => $status === 'all' ? '' : $status, 'limit' => max(1, min(200, (int) ($a['limit'] ?? 100)))]);
        // bookings hold personal data: every read by Claude is in the change log, with how many it saw (as list_enquiries)
        ChangeLog::write($this->app, 'claude', 'list_bookings', t('%d bookings read', count($rows)));

        return ['from' => $from, 'to' => $to, 'count' => count($rows), 'bookings' => array_map(fn (array $b): array => [
            'id' => (int) $b['id'], 'service' => $b['service'], 'staff' => $b['staff'], 'starts_at' => substr((string) $b['starts_at'], 0, 16), 'ends_at' => substr((string) $b['ends_at'], 0, 16), 'status' => $b['status'],
            'hold_until' => $b['hold_until'] === null ? null : substr((string) $b['hold_until'], 0, 16), 'name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'], 'note' => $b['note'], 'source' => $b['source'], 'created_at' => substr((string) $b['created_at'], 0, 16),
            'reminded' => $b['reminded_at'] !== null, 'anonymised' => $b['anonymised_at'] !== null], $rows),
            'untrusted' => ['bookings'], // 3.35 (N105, Mcp\Untrusted): the customer's name, contact and note
            'note' => 'Personal data – use them only for what the user asks. cancel_booking cancels one (the customer is e-mailed); a pending one (a request) is answered with confirm_booking, decline_booking or propose_booking_times; done and no-show are marked in the administration.'];
    }

    /** booking_availability */
    private function toolBookingAvailability(string $name, array $a): mixed
    {
        $this->requireBookings();
        $db = $this->app->db();
        $services = Booking::services($db);
        $staff = Booking::staff($db);
        $out = ['services' => array_map(fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'duration_min' => $s['duration_min'], 'buffer_min' => $s['buffer_min'], 'price_text' => $s['price_text'], 'requires_confirmation' => $s['requires_confirmation'], 'staff' => $s['staff']], $services),
            'staff' => array_map(fn (array $m): array => ['id' => $m['id'], 'name' => $m['name'], 'services' => $m['services'],
                'hours' => array_map(fn (array $ranges): string => implode(', ', array_map(fn (array $r): string => $r[0] . '-' . $r[1], $ranges)), Booking::hours($db, $m['id'])) ?: 'the site\'s opening hours',
                'service_hours' => array_map(fn (array $perDay): array => array_map(fn (array $ranges): string => implode(', ', array_map(fn (array $r): string => $r[0] . '-' . $r[1], $ranges)), $perDay), Booking::serviceHours($db, $m['id'])) ?: null,
                'days_off' => array_map(fn (array $o): array => ['from' => $o['from'], 'to' => $o['to'], 'note' => $o['note']], Booking::offs($db, $m['id']))], $staff),
            'settings' => ['lead_hours' => $this->app->settings()->int('booking_lead_hours'), 'horizon_days' => $this->app->settings()->int('booking_horizon_days'), 'cancel_hours' => $this->app->settings()->int('booking_cancel_hours'), 'reminder_hours' => $this->app->settings()->int('booking_reminder_hours'), 'hold_hours' => $this->app->settings()->int('booking_hold_hours')]];
        $serviceId = (int) ($a['service'] ?? 0);
        if ($serviceId > 0) {
            $service = Booking::service($db, $serviceId, true) ?? throw new \InvalidArgumentException('The service does not exist or is switched off. The services are listed in this result without the service parameter.');
            $day = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($a['day'] ?? '')) ? (string) $a['day'] : date('Y-m-d');
            $free = Booking::availability($this->app, $service, (int) ($a['staff'] ?? 0), $day);
            $out += ['service' => $service['name'], 'day' => $day, 'slots' => array_keys($free), 'free_staff_at' => $free,
                'days_with_free_times' => Booking::days($this->app, $service, (int) ($a['staff'] ?? 0), substr($day, 0, 7))];
        } else {
            $out['next'] = 'Pass service (and a day) for the free times. The set-up: save_booking_service, save_booking_staff; the settings booking_lead_hours, booking_horizon_days, booking_cancel_hours, booking_reminder_hours with update_settings.';
        }
        // 3.5: visitors could book nothing soon – tell the user why instead of an empty calendar (an additive key)
        if (($none = Booking::noFreeTime($this->app)) !== null) {
            $out['warning'] = sprintf($none[0], $none[1]);
        }

        return $out;
    }

    /** save_booking_service */
    private function toolSaveBookingService(string $name, array $a): mixed
    {
        $this->requireBookings();
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The booking set-up is changed by administrators.');
        }
        $result = Booking::saveService($this->app, array_intersect_key($a, array_flip(['name', 'duration_min', 'buffer_min', 'price_text', 'description', 'active', 'requires_confirmation', 'sort_order', 'staff'])), (int) ($a['id'] ?? 0));
        if (is_string($result)) {
            throw new \InvalidArgumentException($result);
        }

        return ['service' => $result, 'next' => $result['staff'] === [] ? 'Nobody offers this service yet – save_booking_staff with services [' . $result['id'] . '], or save_booking_service with staff. Then a Booking element (type booking, content service ' . $result['id'] . ' or 0 for a choice) on a page.' : null];
    }

    /** save_booking_staff */
    private function toolSaveBookingStaff(string $name, array $a): mixed
    {
        $this->requireBookings();
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The booking set-up is changed by administrators.');
        }
        $result = Booking::saveStaff($this->app, array_intersect_key($a, array_flip(['name', 'email', 'active', 'user_id', 'sort_order', 'services', 'hours', 'service_hours', 'days_off'])), (int) ($a['id'] ?? 0));
        if (is_string($result)) {
            throw new \InvalidArgumentException($result);
        }
        $db = $this->app->db();

        return ['staff' => $result + ['hours' => Booking::hours($db, $result['id']) ?: 'the site\'s opening hours', 'service_hours' => array_map(fn (array $perDay): array => array_map(fn (array $ranges): string => implode(', ', array_map(fn (array $r): string => $r[0] . '-' . $r[1], $ranges)), $perDay), Booking::serviceHours($db, $result['id'])) ?: null, 'days_off' => Booking::offs($db, $result['id'])],
            'next' => $result['services'] === [] ? 'The person offers no service yet – pass services (ids from booking_availability).' : null];
    }

    /** cancel_booking */
    private function toolCancelBooking(string $name, array $a): mixed
    {
        $this->requireBookings();
        if (!$this->app->auth()->hasModule('bookings')) {
            throw new \DomainException('Bookings are handled only by users with the Bookings section.');
        }
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Cancelling needs confirm=true – only when the user asked for it.');
        }
        $booking = Booking::find($this->app->db(), (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The booking does not exist. Use list_bookings.');
        if (!Booking::cancel($this->app, $booking, 'claude')) {
            throw new \DomainException('The booking is ' . $booking['status'] . ' – only a confirmed one can be cancelled.');
        }

        return ['cancelled' => (int) $booking['id'], 'customer_notified' => (string) $booking['email'] !== ''];
    }

    /** The booking of an answer to a request (confirm_booking, decline_booking, propose_booking_times): the Bookings section and confirm=true. */
    private function pendingBooking(array $a): array
    {
        $this->requireBookings();
        if (!$this->app->auth()->hasModule('bookings')) {
            throw new \DomainException('Bookings are handled only by users with the Bookings section.');
        }
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('This e-mails the customer and needs confirm=true – only when the user asked for it.');
        }
        $booking = Booking::find($this->app->db(), (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The booking does not exist. Use list_bookings.');
        if ($booking['status'] !== 'pending') {
            throw new \DomainException('The booking is ' . $booking['status'] . ' – only a pending one (a request) can be answered.');
        }

        return $booking;
    }

    /** confirm_booking */
    private function toolConfirmBooking(string $name, array $a): mixed
    {
        $booking = $this->pendingBooking($a);
        $error = Booking::confirm($this->app, $booking, 'claude');
        if ($error !== null) {
            throw new \DomainException($error);
        }

        return ['confirmed' => (int) $booking['id'], 'customer_notified' => (string) $booking['email'] !== ''];
    }

    /** decline_booking */
    private function toolDeclineBooking(string $name, array $a): mixed
    {
        $booking = $this->pendingBooking($a);
        if (!Booking::decline($this->app, $booking, (string) ($a['message'] ?? ''), 'claude')) {
            throw new \DomainException('The request could not be declined – it may have been answered meanwhile.');
        }

        return ['declined' => (int) $booking['id'], 'customer_notified' => (string) $booking['email'] !== ''];
    }

    /** propose_booking_times */
    private function toolProposeBookingTimes(string $name, array $a): mixed
    {
        $booking = $this->pendingBooking($a);
        $error = Booking::propose($this->app, $booking, array_map('strval', (array) ($a['times'] ?? [])), (string) ($a['message'] ?? ''), 'claude');
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        return ['proposed' => (int) $booking['id'], 'times' => array_map(fn (array $p): string => substr($p['starts_at'], 0, 16), Booking::proposals($this->app->db(), (int) $booking['id'])), 'customer_notified' => true];
    }
}
