<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\SendRegister;
use Kaleta\Core\Testimonials;

/**
 * 3.51: the refuse link of a testimonial request (…/_testimonial/<token>?refuse=1, the line of the setting mail_refuse_text
 * in the e-mail). Opening it (GET) only asks and shows a button – a mail link scanner changes nothing; pressing it (POST)
 * puts the address on the send register as refused (Core\SendRegister: no testimonial request or newsletter again; answers
 * to the person's own enquiries and bookings still arrive), marks the request used and ends a newsletter subscription of
 * the address (owner decision 5 of 3.51: one refusal covers everything unsolicited).
 *
 * The link works as long as the request row is kept (Testimonials::forRefusal – used and expired requests too), longer
 * than the 30 days of the form, and only for a request that was e-mailed (a link only handed out refuses nothing). Neither the address nor anything else of the person is in the URL or on the page, and
 * nothing is written to the change log from the public site (Auth stays untouched); the register is the record.
 */
final class TestimonialRefusal
{
    public function __construct(private readonly App $app)
    {
    }

    /** @return array{0: string, 1: string} page title and content (HTML) */
    public function page(string $token): array
    {
        $db = $this->app->db();
        $site = (string) $this->app->settings()->get('site_name');
        $request = Testimonials::forRefusal($db, $token);
        if ($request === null) {
            $contact = (string) $this->app->settings()->get('company_email');

            return [t('The link is no longer valid'), '<p>' . e($contact !== '' ? t('This link is no longer valid – write to us at %s.', $contact) : t('This link is no longer valid – write to us.')) . '</p>'];
        }
        $heading = t('Stop e-mails from %s', $site);
        if (!$this->app->request->isPost()) {
            return [$heading, '<p>' . e(t('Should we stop sending you requests and news?')) . '</p><form method="post" action="'
                . e($this->app->url('_testimonial/' . $token) . '?refuse=1') . '"><p><button class="tlacitko" type="submit">' . e(t('Do not write to me')) . '</button></p></form>'];
        }
        $email = (string) $request['email'];
        SendRegister::record($db, 'refused', $email);
        $db->run('UPDATE {testimonial_requests} SET used_at = ? WHERE id = ? AND used_at IS NULL', [Clock::now(), (int) $request['id']]);
        Subscription::remove($this->app, $email);

        return [$heading, '<p>' . e(t('We will not send you requests or news again. Answers to your own enquiries and bookings still arrive.')) . '</p>'];
    }
}
