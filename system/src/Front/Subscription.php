<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Clock;
use Kaleta\Core\Language;
use Kaleta\Core\Mail;
use Kaleta\Core\SendRegister;
use Kaleta\Builder\Elements\Newsletter;

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
        $this->request($email, Language::siteColumn(), $r->post('zpet'), self::elementProof($this->app, $r->post('zdroj'), $r->post('prvek')), false);

        return 'ok';
    }

    /**
     * 3.54: one sign-up request – from the Newsletter element (subscribe) or from the news consent of a form (Front\Forms,
     * $fromForm). Nobody is subscribed here: an unconfirmed row gets a token and the confirmation e-mail, and stav stays 0
     * until the person confirms by the link (double opt-in). The rules:
     * - a confirmed address gets nothing (no second confirmation e-mail);
     * - from the Newsletter element, an unconfirmed address may ask again at once, an address on the send register once in
     *   REGISTER_CONFIRM_DAYS (mayConfirmAgain – the person's own way back);
     * - from a form, an address on the send register gets no e-mail and no row at all, and an unconfirmed address is mailed
     *   at most once in REGISTER_CONFIRM_DAYS – somebody else's form cannot make the site keep writing to a person.
     * When no e-mail goes out the row is not touched: datum is "last mailed" (mayConfirmAgain, the 30-day clean-up) and the
     * stored proof must belong to the request whose link was mailed. When one goes out, insert and renewal both write the
     * token, datum, language, the page and the proof ($proof: text = the wording the person saw, source = the build source,
     * element = its id; empty strings when they could not be read – a sign-up is never refused for a missing proof).
     * Returns whether a confirmation e-mail was sent.
     *
     * @param array{text?: string, source?: string, element?: string} $proof
     */
    public function request(string $email, string $language, string $page, array $proof, bool $fromForm): bool
    {
        $email = mb_strtolower(trim($email));
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $db = $this->app->db();
        $subscriber = $db->one('SELECT ido, stav, datum FROM {odberatele} WHERE email = ?', [$email]);
        if ($subscriber !== null && (int) $subscriber['stav'] === 1) {
            return false; // already subscribed – we send nothing more
        }
        $onRegister = SendRegister::blocked($db, $email) !== null;
        if ($fromForm && $onRegister) {
            return false; // 3.54: a form never writes to an address that bounced or refused – the Newsletter element is their way back
        }
        // 3.51: on the send register and mailed within the last days – nothing more, the same answer as always; 3.54: from a
        // form every unconfirmed address is held to that guard
        if ($subscriber !== null && !self::mayConfirmAgain((string) $subscriber['datum'], $fromForm || $onRegister, Clock::now())) {
            return false;
        }
        $token = bin2hex(random_bytes(16));
        $row = ['token' => $token, 'datum' => date('Y-m-d H:i:s'), 'language' => $language, 'zdroj' => mb_substr($page, 0, 255)] + self::proofColumns($proof);
        // 3.51: the language version the form was sent from (its own /<code>/odber) – newsletters follow it
        if ($subscriber === null) {
            $r = $this->app->request;
            [$landing, $visitCampaign] = Forms::attribution($r); // with the visitor's consent to marketing (2.3)
            $db->insert('odberatele', ['email' => $email, 'kampan' => Forms::campaign($r->referer(), $r->origin()) ?: $visitCampaign, 'vstup' => $landing] + $row);
        } else {
            $db->update('odberatele', $row, ['ido' => (int) $subscriber['ido']]);
        }
        $siteSettings = $this->app->settings();
        $link = $this->address('odber?confirm=' . $token);
        Mail::send($siteSettings, $email, t('Confirm your subscription – %s', $siteSettings->get('site_name')),
            t('Hello,') . "\n\n" . t('to confirm your subscription to news from %s, click the link:', $siteSettings->get('site_name')) . "\n" . $link . "\n\n"
            . t('If you did not ask to subscribe, ignore this e-mail – without confirmation we will not send you anything.') . "\n");

        return true;
    }

    /**
     * 3.54: the proof columns of a sign-up, cut to their lengths. Pure.
     *
     * @param array{text?: string, source?: string, element?: string} $proof
     * @return array{consent_text: string, consent_source: string, consent_element: string}
     */
    public static function proofColumns(array $proof): array
    {
        return ['consent_text' => mb_substr(trim((string) ($proof['text'] ?? '')), 0, 500), 'consent_source' => mb_substr((string) ($proof['source'] ?? ''), 0, 64),
            'consent_element' => mb_substr((string) ($proof['element'] ?? ''), 0, 16)];
    }

    /**
     * 3.54: the proof of a sign-up from the Newsletter element – the text under its field as the PUBLISHED build has it
     * (never the request's), with the source and the element id the form names. An unknown source or element (a cached
     * page with the markup of 3.53, an element removed since) gives an empty proof; the sign-up goes on.
     *
     * @return array{text: string, source: string, element: string}
     */
    public static function elementProof(App $app, string $source, string $element): array
    {
        $node = $source !== '' && $element !== '' ? Forms::findElement($app->db(), $source, $element, Newsletter::TYPE, Language::siteColumn()) : null;
        if ($node === null) {
            return ['text' => '', 'source' => '', 'element' => ''];
        }
        $text = $node['obsah']['souhlas'] ?? Newsletter::properties()['souhlas']['vychozi']; // as the element renders a missing value

        return ['text' => is_string($text) ? $text : '', 'source' => $source, 'element' => $element];
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

            // 3.54: the newsletter the link came from stays in the action, so the button counts like the one-click unsubscribe
            $from = $action === 'unsubscribe' ? self::newsletterId($r) : 0;

            return [$heading, '<p>' . e($text) . '</p><form method="post" action="' . e($this->app->url('odber') . '?' . $action . '=' . $o['token'] . ($from > 0 ? '&newsletter=' . $from : '')) . '"><p><button class="tlacitko" type="submit">' . e($button) . '</button></p></form>'];
        }
        if ($action === 'unsubscribe') {
            self::countUnsubscribe($db, self::newsletterId($r), $o);
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

    /**
     * Unsubscribe link for the mailing tool (subscriber export) and the newsletters (Core\Mailing). 3.54: a newsletter's
     * own link carries its id (&newsletter=), so the unsubscribe counts for it – both the button page and the one-click
     * unsubscribe of the mail client (List-Unsubscribe-Post) post to this address.
     */
    public static function unsubscribeLink(App $app, string $token, int $newsletterId = 0): string
    {
        return (new self($app))->address('odber?unsubscribe=' . $token . ($newsletterId > 0 ? '&newsletter=' . $newsletterId : ''));
    }

    /** 3.54: the newsletter id an unsubscribe link carries – a positive integer, otherwise 0 (an old link, a forged value). */
    private static function newsletterId(\Kaleta\Core\Request $r): int
    {
        $id = $r->getInt('newsletter');

        return $id > 0 && $id <= 4294967295 ? $id : 0;
    }

    /**
     * 3.54: "unsubscribed from this newsletter" (ka_newsletters.unsubscribed_count) – one more for the newsletter the link
     * came from, only when it was really sent (it started) and started after the person confirmed: a person who never got
     * it adds nothing. A token works once (remove() deletes the row), so a person adds at most one.
     *
     * @param array<string, mixed> $subscriber the row of the token
     */
    private static function countUnsubscribe(\Kaleta\Core\Db $db, int $newsletterId, array $subscriber): void
    {
        $confirmed = (string) ($subscriber['potvrzeno'] ?? '');
        if ($newsletterId <= 0 || (int) ($subscriber['stav'] ?? 0) !== 1 || $confirmed === '') {
            return;
        }
        $db->run("UPDATE {newsletters} SET unsubscribed_count = unsubscribed_count + 1 WHERE id = ? AND status IN ('sending', 'paused', 'sent', 'cancelled') AND started_at >= ?",
            [$newsletterId, $confirmed]);
    }

    private function address(string $path): string
    {
        return rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . $this->app->url($path);
    }
}
