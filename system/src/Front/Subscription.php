<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\Language;
use Kaleta\Core\Mail;
use Kaleta\Core\SendRegister;

/**
 * News subscription (Newsletter extension): sign-up from the News subscription element, confirmation and unsubscribe by
 * a link from the e-mail. An address counts as a subscriber only after confirmation (double opt-in). Whoever signs up
 * a second time only gets a new link – the site does not reveal to anyone whether the address is already on the list.
 *
 * 3.51: a subscriber keeps the language version they signed up in (ka_odberatele.language). An address on the send register
 * (Core\SendRegister – it bounced, or the person said "do not write to me") gets the confirmation e-mail at most once in
 * REGISTER_CONFIRM_DAYS, so nobody can make the site keep writing to a person who said no; confirming lifts the refusal.
 */
final class Subscription
{
    private const int LIMIT = 5; // sign-ups from one address per 10 minutes

    /** 3.51: an address on the send register gets a confirmation e-mail at most once in this many days. */
    public const int REGISTER_CONFIRM_DAYS = 7;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * POST from the element: saves or renews an unconfirmed address and sends the link.
     *
     * @return string result for the element's message (ok | chyba | limit)
     */
    public function subscribe(): string
    {
        $r = $this->app->request;
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $reason = $antispam->verify($r, 'odber');
        if ($reason === 'robot') {
            return 'ok';
        }
        if ($reason !== null) {
            return 'chyba';
        }
        if ($antispam->count($r->ip(), 'odber', 0, 10) >= self::LIMIT) {
            return 'limit';
        }
        if (!\Kaleta\Core\Captcha::accepted($this->app->settings(), \Kaleta\Core\Captcha::verify($this->app->settings(), $r))) {
            return 'captcha';
        }
        $antispam->write($r->ip(), 'odber', 0);
        $email = mb_strtolower(trim($r->post('email')));
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'chyba';
        }
        $db = $this->app->db();
        $subscriber = $db->one('SELECT * FROM {odberatele} WHERE email = ?', [$email]);
        if ($subscriber !== null && (int) $subscriber['stav'] === 1) {
            return 'ok'; // already subscribed – we send nothing more
        }
        if ($subscriber !== null && !self::mayConfirmAgain((string) $subscriber['datum'], SendRegister::blocked($db, $email) !== null, Clock::now())) {
            return 'ok'; // 3.51: on the send register and mailed within the last days – nothing more, the same answer as always
        }
        $token = bin2hex(random_bytes(16));
        // 3.51: the language version the form was sent from (its own /<code>/odber) – newsletters follow it
        if ($subscriber === null) {
            [$landing, $visitCampaign] = Forms::attribution($r); // with the visitor's consent to marketing (2.3)
            $db->insert('odberatele', ['email' => $email, 'token' => $token, 'datum' => date('Y-m-d H:i:s'), 'zdroj' => mb_substr($r->post('zpet'), 0, 255),
                'kampan' => Forms::campaign($r->referer(), $r->origin()) ?: $visitCampaign, 'vstup' => $landing, 'language' => Language::siteColumn()]);
        } else {
            $db->update('odberatele', ['token' => $token, 'datum' => date('Y-m-d H:i:s'), 'language' => Language::siteColumn()], ['ido' => (int) $subscriber['ido']]);
        }
        $siteSettings = $this->app->settings();
        $link = $this->address('odber?confirm=' . $token);
        Mail::send($siteSettings, $email, t('Confirm your subscription – %s', $siteSettings->get('site_name')),
            t('Hello,') . "\n\n" . t('to confirm your subscription to news from %s, click the link:', $siteSettings->get('site_name')) . "\n" . $link . "\n\n"
            . t('If you did not ask to subscribe, ignore this e-mail – without confirmation we will not send you anything.') . "\n");

        return 'ok';
    }

    /**
     * Link from the e-mail (?confirm= / ?unsubscribe=). Opening the link (GET) only shows a button – mail link scanners
     * (Safe Links etc.) would otherwise confirm the subscription or unsubscribe the subscriber on their own. The change
     * happens only on submission (POST), unsubscribing also with one click from the mail client (List-Unsubscribe-Post).
     *
     * @return array{0: string, 1: string} page title and content (HTML)
     */
    public function link(): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $action = preg_match('/^[a-f0-9]{32}$/D', $r->get('confirm')) ? 'confirm' : (preg_match('/^[a-f0-9]{32}$/D', $r->get('unsubscribe')) ? 'unsubscribe' : '');
        $o = $action !== '' ? $db->one('SELECT * FROM {odberatele} WHERE token = ?', [$r->get($action)]) : null;
        if ($o === null) {
            return [t('The link is no longer valid'), '<p>' . e(t('The link is invalid or has already been used. If you want to receive news, please subscribe again.')) . '</p>'];
        }
        if (!$r->isPost()) {
            [$heading, $text, $button] = $action === 'confirm'
                ? [t('Potvrzení odběru'), t('Please confirm that you want to receive news at %s.', $o['email']), t('Potvrdit odběr')]
                : [t('Odhlášení odběru'), t('Do you really no longer want to receive news at %s?', $o['email']), t('Odhlásit odběr')];

            return [$heading, '<p>' . e($text) . '</p><form method="post" action="' . e($this->app->url('odber') . '?' . $action . '=' . $o['token']) . '"><p><button class="tlacitko" type="submit">' . e($button) . '</button></p></form>'];
        }
        if ($action === 'unsubscribe') {
            self::remove($this->app, (string) $o['email']);

            return [t('Unsubscribed'), '<p>' . e(t('We have removed %s from the subscriber list.', $o['email'])) . '</p>'];
        }
        if ((int) $o['stav'] === 0) {
            $db->update('odberatele', ['stav' => 1, 'potvrzeno' => date('Y-m-d H:i:s')], ['ido' => (int) $o['ido']]);
            \Kaleta\Core\RestoreLedger::forget($db, 'unsubscribed', (string) $o['email']); // 3.46: subscribed again – a restore keeps it
            // 3.51: a new confirmed sign-up is the person's own word again – it ends a refusal (and a bounce: the address works)
            SendRegister::lift($db, 'refused', (string) $o['email']);
            SendRegister::lift($db, 'bounced', (string) $o['email']);
            \Kaleta\Core\Contacts::later('subscriber', (int) $o['ido']); // 3.28: a confirmed subscription joins its customer after the response
            \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'pridat'); // to the mailing service, sent by the background cleanup
        }

        return [t('Subscription confirmed'), '<p>' . e(t('Thank you, we will send news to %s. You can unsubscribe using the link in every e-mail.', $o['email'])) . '</p>'];
    }

    /**
     * 3.51: removes the subscription of an address – the unsubscribe link, the refuse page of a testimonial request
     * (Front\TestimonialRefusal) and Delete in Subscribers: the row goes, the restore ledger remembers the unsubscribe (3.46
     * N156: a restore never subscribes them again) and a confirmed address leaves the mailing service too. Returns whether
     * the address had a subscription.
     */
    public static function remove(App $app, string $email): bool
    {
        $db = $app->db();
        $o = $email !== '' ? $db->one('SELECT ido, email, stav FROM {odberatele} WHERE email = ?', [$email]) : null;
        if ($o === null) {
            return false;
        }
        $db->delete('odberatele', ['ido' => (int) $o['ido']]);
        \Kaleta\Core\RestoreLedger::record($db, 'unsubscribed', (string) $o['email']);
        if ((int) $o['stav'] === 1) {
            \Kaleta\Core\Newsletter::enqueue($app, (string) $o['email'], 'odebrat'); // from the mailing service too
        }

        return true;
    }

    /**
     * 3.51: may an unconfirmed sign-up be mailed again – always, unless the address is on the send register and its last
     * confirmation e-mail (the row's datum) went out less than REGISTER_CONFIRM_DAYS ago. Pure.
     */
    public static function mayConfirmAgain(string $lastMailed, bool $onRegister, string $now): bool
    {
        if (!$onRegister) {
            return true;
        }
        $last = strtotime($lastMailed);

        return $last === false || strtotime($now) - $last >= self::REGISTER_CONFIRM_DAYS * 86400;
    }

    /** Unsubscribe link for the mailing tool (subscriber export). */
    public static function unsubscribeLink(App $app, string $token): string
    {
        return (new self($app))->address('odber?unsubscribe=' . $token);
    }

    private function address(string $path): string
    {
        return rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . $this->app->url($path);
    }
}
