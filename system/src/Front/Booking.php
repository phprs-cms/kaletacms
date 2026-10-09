<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Builder\Elements\Booking as Element;
use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Booking as Bookings;
use Kaleta\Core\Captcha;
use Kaleta\Core\Response;

/**
 * The public side of online booking (3.0, Core\Booking):
 *  - GET /_booking/days?service=&staff=&month=  the days of a month with a free time (JSON, no personal data);
 *  - GET /_booking/slots?service=&staff=&day=   the free times of a day (JSON);
 *  - POST /_booking                              the booking itself – protected like the forms (Core\Antispam, the CAPTCHA
 *                                                when the site has one, a limit per address), the element taken from the
 *                                                PUBLISHED build, back to the page with a result code;
 *  - /_booking/cancel/<token>                    the customer's cancel page (GET shows it, POST cancels – a link a mail
 *                                                client prefetches must not cancel anything);
 *  - /_booking/choose/<token>                    the page where the customer picks one of the times the provider proposed
 *                                                for a request (3.3; GET lists them, POST picks one);
 *  - /_booking/ics/<token>                       the appointment for the customer's calendar.
 */
final class Booking
{
    /** Bookings one address may make in an hour, and availability questions in a minute. */
    private const int LIMIT = 5;
    private const int QUERY_LIMIT = 120;

    public function __construct(private readonly App $app)
    {
    }

    /** /_booking/days and /_booking/slots */
    public function availability(string $what): Response
    {
        $r = $this->app->request;
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        if ($antispam->count($r->ip(), 'rezervace_dotaz', 0, 1) >= self::QUERY_LIMIT) {
            return Response::json(['error' => 'limit'], 429);
        }
        $antispam->write($r->ip(), 'rezervace_dotaz', 0);
        $service = Bookings::service($this->app->db(), $r->getInt('service'), true);
        if ($service === null) {
            return Response::json(['error' => 'service'], 404);
        }
        $headers = ['Cache-Control' => 'no-store'];
        if ($what === 'days') {
            $days = Bookings::days($this->app, $service, $r->getInt('staff'), $r->get('month'));

            return new Response((string) json_encode(['month' => $r->get('month'), 'days' => $days]), 200, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
        }
        $slots = Bookings::availability($this->app, $service, $r->getInt('staff'), $r->get('day'));

        return new Response((string) json_encode(['day' => $r->get('day'), 'slots' => array_keys($slots)]), 200, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** POST /_booking */
    public function process(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $source = $r->post('zdroj');
        $back = $r->post('zpet');
        $back = preg_match('#^/[^\s\\\\?]*$#D', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('');
        $element = Forms::findElement($this->app->db(), $source, $r->post('prvek'), Element::TYPE, \Kaleta\Core\Language::siteColumn());
        if ($element === null) {
            return Response::redirect($back, 303);
        }
        $redirect = fn (string $result): Response => Response::redirect($back . '?booking=' . rawurlencode($element['id']) . '&result=' . $result . '#' . Element::anchor($element), 303);
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $reason = $antispam->reason($r, 'rezervace|' . $source . '|' . $element['id']);
        if ($reason === 'robot') {
            return $redirect('ok'); // the robot does not learn that it failed
        }
        if ($reason !== null) {
            return $redirect($reason === 'rychle' ? 'rychle' : 'overeni');
        }
        if ($antispam->count($r->ip(), 'rezervace', 0, 60) >= self::LIMIT) {
            return $redirect('limit');
        }
        if (!Captcha::accepted($this->app->settings(), Captcha::verify($this->app->settings(), $r))) {
            return $redirect('captcha');
        }
        if ($r->post('souhlas') !== '1') {
            return $redirect('souhlas');
        }
        $o = $element['obsah'];
        // the element may fix the service or the person – then the visitor's choice does not count
        $serviceId = (int) $o['sluzba'] > 0 ? (int) $o['sluzba'] : $r->postInt('service');
        $staffId = (int) $o['osoba'] > 0 ? (int) $o['osoba'] : $r->postInt('staff');
        [$booking, $error] = Bookings::book($this->app, ['service_id' => $serviceId, 'staff_id' => $staffId, 'slot' => $r->post('slot'), 'name' => $r->post('jmeno'), 'email' => $r->post('email'),
            'phone' => $r->post('telefon'), 'note' => $r->post('poznamka'), 'source' => $back, 'language' => \Kaleta\Core\Language::siteColumn(), 'by' => 'customer']);
        if ($booking === null) {
            return $redirect($error === 'taken' ? 'obsazeno' : (string) $error);
        }
        $antispam->write($r->ip(), 'rezervace', 0);
        Cache::clear(); // the free times on the page changed

        return $redirect($booking['status'] === 'pending' ? 'pending' : 'ok');
    }

    /**
     * The cancel page of a booking: GET shows the appointment and a button, POST cancels it – while the deadline allows.
     *
     * @return array{0: string, 1: string, 2: int} heading, content HTML, HTTP status
     */
    public function cancelPage(string $token): array
    {
        $booking = Bookings::byToken($this->app->db(), $token);
        if ($booking === null) {
            return [t('Appointment not found'), '<p>' . e(t('This link does not belong to any appointment. It may have been removed already.')) . '</p>', 404];
        }
        $s = $this->app->settings();
        $details = '<p>' . e((string) $booking['service']) . ' – ' . e((string) $booking['staff']) . '<br>' . e(Bookings::when((string) $booking['starts_at'], (string) $booking['ends_at'])) . '</p>';
        if ($booking['status'] === 'cancelled' || $booking['status'] === 'declined') {
            return [t('Appointment cancelled'), $details . '<p>' . e(t('This appointment is cancelled.')) . '</p>', 200];
        }
        if ($booking['status'] === 'pending') {
            return [t('Your request'), $details . '<p>' . e(t('We have your request and will get back to you. It is not confirmed yet.')) . '</p>', 200];
        }
        if ($booking['status'] !== 'confirmed') {
            return [t('Your appointment'), $details . '<p>' . e(t('This appointment has already taken place.')) . '</p>', 200];
        }
        $deadline = Bookings::cancelDeadline($s, $booking);
        if (new \DateTimeImmutable() > $deadline) {
            return [t('Your appointment'), $details . '<p>' . e(t('The appointment can no longer be cancelled online (the deadline was %s). Please contact us.', format_date($deadline, true))) . '</p>', 200];
        }
        if ($this->app->request->isPost() && $this->app->request->post('zrusit') === '1') {
            Bookings::cancel($this->app, $booking, 'customer');
            Cache::clear();

            return [t('Appointment cancelled'), $details . '<p>' . e(t('Your appointment is cancelled. You are welcome to book another time.')) . '</p>', 200];
        }

        return [t('Cancel the appointment?'), $details . '<form method="post" action="' . e($this->app->url('_booking/cancel/' . $token)) . '"><input type="hidden" name="zrusit" value="1">'
            . '<p><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e(t('Yes, cancel the appointment')) . '</button></p>'
            . '<p>' . e(t('You can cancel online until %s.', format_date($deadline, true))) . '</p></form>', 200];
    }

    /**
     * The page of the proposed times: GET lists them with a button each, POST picks one (a mail client that prefetches a
     * link must not choose anything).
     *
     * @return array{0: string, 1: string, 2: int} heading, content HTML, HTTP status
     */
    public function choosePage(string $token): array
    {
        $db = $this->app->db();
        $booking = Bookings::byToken($db, $token);
        if ($booking === null) {
            return [t('Appointment not found'), '<p>' . e(t('This link does not belong to any appointment. It may have been removed already.')) . '</p>', 404];
        }
        $proposals = $booking['status'] === 'pending' ? Bookings::proposals($db, (int) $booking['id']) : [];
        if ($proposals === []) {
            return [t('Your request'), '<p>' . e($booking['status'] === 'confirmed' ? t('Your appointment is confirmed – thank you.') : t('There is nothing to choose here any more. Please contact us.')) . '</p>', 200];
        }
        $r = $this->app->request;
        $message = '';
        if ($r->isPost() && $r->postInt('proposal') > 0) {
            $error = Bookings::acceptProposal($this->app, $booking, $r->postInt('proposal'));
            if ($error === null) {
                Cache::clear();

                return [t('Your appointment is confirmed'), '<p>' . e(t('Thank you, your appointment is confirmed. A confirmation is on its way to your e-mail.')) . '</p>', 200];
            }
            $message = '<p role="alert">' . e(t($error)) . '</p>';
            $booking = Bookings::byToken($db, $token) ?? $booking;
            $proposals = Bookings::proposals($db, (int) $booking['id']);
        }
        $form = '<form method="post" action="' . e($this->app->url('_booking/choose/' . $token)) . '"><ul>';
        foreach ($proposals as $p) {
            $form .= '<li><button class="ka-tlacitko ka-tlacitko--primarni" type="submit" name="proposal" value="' . $p['id'] . '">' . e(Bookings::when($p['starts_at'], $p['ends_at'])) . '</button></li>';
        }

        return [t('Choose a time'), $message . '<p>' . e((string) $booking['service']) . ' – ' . e((string) $booking['staff']) . '</p><p>' . e(t('These times are free. Pick the one that suits you:')) . '</p>' . $form . '</ul></form>', 200];
    }

    /** /_booking/ics/<token> */
    public function ics(string $token): ?Response
    {
        $booking = Bookings::byToken($this->app->db(), $token);
        if ($booking === null) {
            return null;
        }

        return new Response(Bookings::ics($this->app, $booking, $token), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="appointment-' . (int) $booking['id'] . '.ics"', 'Cache-Control' => 'no-store']);
    }
}
