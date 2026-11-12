<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * What happens to a quote once it leaves the firm (3.38, design A6, A7, A9, A-5, A-10, A-11, A-13) – on top of the core
 * Core\Quotes: the customer's link, sending, the expiry job, the retention and the personal data of a customer. One of
 * the few files that may name the quote tables (unit 2800, A4); the document itself is Front\QuotePage.
 *
 *  - The link (/quote/<token>): a bearer link to one sent quote. The token is 32 random bytes per link (design A7, 3.38
 *    review L1 – not derived from the quote or the secret key, so a withdrawn token never comes back and a leaked key
 *    reveals nothing); the table keeps only its sha256 (token_hash) and token_expires_at (quote_link_days after the
 *    validity). The plain link exists only in the response that made it and in the session of the person who made it
 *    (remember/linkUrl: "Copy link" right after sending or New link); later the admin offers New link, which says the old
 *    one stops working. Withdrawing blanks the hash. A wrong, withdrawn or expired token is the site's 404, never a detail.
 *  - Sending (send(), the admin dialog and MCP send_quote): only to the quote's customer e-mail through the site's SMTP
 *    (EnquiryReply::unavailable – the server's mail() lands in spam), Reply-To = the sender, their signature
 *    (Builder\UserSignature via EnquiryReply::signature). The quote gets its number first (Quotes::markSent), then the link,
 *    then the e-mail in the quote's language: the message, the number, the validity, the total (A-13 – it is the
 *    customer's own quote) and the link. The mail log keeps only "Quote <number>: <site>". A refused delivery stays in the
 *    mail queue (status queued); the quote is sent all the same. With the box ticked the deal takes the net total (A-10).
 *    Claude may send only when the address is the one of the quote's contact or enquiry and no Claude connection wrote that
 *    address there (A-5: never an address it typed – claudeRefusal, Contacts::emailByAgent).
 *  - The expiry job (Scheduler "quotes"): a sent quote past its validity expires (Quotes::setStatus by Kaleta), links past
 *    their end are withdrawn, and the responsible person gets one bell EXPIRING_DAYS before and one when it expired – the
 *    bell names the quote by its number, never an amount.
 *  - Retention (A-11, the daily personal-data clean-up): quotes_months after the last status change a quote goes the way
 *    of old enquiries (enquiries_expiry: deleted, or anonymised – the customer block, the frozen document and the link go,
 *    the number, lines and totals stay as the firm's record); drafts untouched for DRAFT_MONTHS are deleted. A contact
 *    with a sent quote is not anonymised and the enquiry of an open or recently decided quote is kept (Contacts holds).
 *  - Personal data (Core\PersonalData): the quotes of an address (their e-mail or their contact) with their lines go into
 *    the person's file; erasing deletes their drafts and keeps a numbered quote without the person.
 *  - Reports count quotes, never money (monthCounts).
 */
final class QuoteDelivery
{
    /** Drafts nobody touched for this many months are deleted by the daily clean-up (A-11). */
    public const int DRAFT_MONTHS = 6;

    /** The responsible person is reminded this many days before a sent quote expires. */
    public const int EXPIRING_DAYS = 3;

    /** Failed link attempts from one network within an hour before every attempt is a 404 (Core\Antispam, type QUOTE_TRIES). */
    public const int MAX_TRIES = 30;
    public const string TRIES = 'quote';

    /** The longest subject and message of the send dialog. */
    public const int MAX_SUBJECT = 200;
    public const int MAX_MESSAGE = 10000;

    /** The setting keys of the default texts; each may have a variant per additional language (quote_intro_en …). */
    public const array TEXTS = ['intro' => 'quote_intro', 'terms' => 'quote_terms', 'footer' => 'quote_footer'];

    /** At most this many rows per run of a job or a clean-up step. */
    private const int BATCH = 500;

    /* ---------- the default texts per language ---------- */

    /**
     * A default text of a quote in a language (the quote's language column: '' = the site's default language): its own
     * variant quote_<key>_<code>, an empty one falls back to the default language's (the Settings::PER_LANGUAGE rule).
     */
    public static function defaultText(Settings $s, string $key, string $language): string
    {
        $setting = self::TEXTS[$key] ?? throw new \InvalidArgumentException('Unknown text of a quote.');
        $own = $language !== '' ? $s->get($setting . '_' . $language) : '';

        return $own !== '' ? $own : $s->get($setting);
    }

    /** The languages the texts are written for: '' (the default language) and every additional language of the site. @return list<string> */
    public static function textLanguages(Settings $s): array
    {
        return ['', ...Language::additional($s)];
    }

    /* ---------- the customer's link ---------- */

    /** The session key that holds the links this person made (quote id => token), so "Copy link" can show them again. */
    private const string SESSION = 'quote_links';

    /** How many just-made links one session keeps for "Copy link". */
    private const int SESSION_LINKS = 20;

    /** A new token: 32 random bytes as 64 hex characters (design A7) – only its sha256 is stored. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * When a new link of a quote stops working: quote_link_days after its validity (after today without one), at the time
     * of day of now. Null when that is not in the future (the link would be dead at once).
     */
    public static function linkExpiry(Settings $s, ?string $validUntil): ?string
    {
        $base = $validUntil !== null && $validUntil > Clock::today() ? $validUntil : Clock::today();
        $moment = (new \DateTimeImmutable($base . ' ' . date('H:i:s', Clock::time()), Clock::zone()))->modify('+' . max(1, $s->int('quote_link_days')) . ' days');
        $expires = Clock::toDb($moment);

        return $expires > Clock::now() ? $expires : null;
    }

    /**
     * Gives a sent quote a (new) link and returns its absolute address. Refused for a draft, an anonymised quote or one
     * without its frozen document. The history notes it ("link").
     *
     * @throws \DomainException
     */
    public static function issueLink(App $app, int $quoteId, int $byUserId): string
    {
        $db = $app->db();
        $row = self::row($db, $quoteId) ?? throw new \DomainException(t('The quote does not exist.'));
        if ($row['status'] === 'draft' || $row['snapshot'] === null || $row['anonymised_at'] !== null) {
            throw new \DomainException(t('Only a sent quote has a link for the customer.'));
        }
        $expires = self::linkExpiry($app->settings(), $row['valid_until'] === null ? null : (string) $row['valid_until']) ?? throw new \DomainException(t('The link would not work any more.'));
        $token = self::newToken();
        $db->update('quotes', ['token_hash' => hash('sha256', $token), 'token_expires_at' => $expires,
            'history' => self::history($row['history'], ['at' => Clock::now(), 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'link'])], ['id' => $quoteId]);

        return self::absolute($app, $token);
    }

    /**
     * Keeps a link this person just made (send, Finalise, New link) in their admin session, so "Copy link" can show it
     * again in this session – the database has only its hash. Admin requests only (it starts the session).
     */
    public static function remember(App $app, int $quoteId, string $link): void
    {
        $token = substr($link, strrpos($link, '/') + 1);
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return;
        }
        $links = $app->session->get(self::SESSION, []);
        $links = is_array($links) ? $links : [];
        unset($links[$quoteId]);
        $links[$quoteId] = $token;
        $app->session->set(self::SESSION, array_slice($links, -self::SESSION_LINKS, null, true));
    }

    /**
     * The working link of a quote again (the admin's "Copy link"), or null: none, withdrawn, expired, or not made in this
     * person's session (the link is stored only as a hash – then they make a new one, see hasWorkingLink). Admin views only.
     *
     * @param array<string, mixed> $quote as Quotes::get returns it
     */
    public static function linkUrl(App $app, array $quote): ?string
    {
        $row = self::row($app->db(), (int) $quote['id']);
        if ($row === null || $row['token_hash'] === null || $row['token_expires_at'] === null || (string) $row['token_expires_at'] <= Clock::now() || $row['anonymised_at'] !== null) {
            return null;
        }
        $links = $app->session->get(self::SESSION, []);
        $token = is_array($links) && is_string($links[(int) $row['id']] ?? null) ? $links[(int) $row['id']] : '';

        return $token !== '' && hash_equals((string) $row['token_hash'], hash('sha256', $token)) ? self::absolute($app, $token) : null;
    }

    /** Does the quote have a link that works now (shown or not)? @param array<string, mixed> $quote as Quotes::get returns it */
    public static function hasWorkingLink(array $quote): bool
    {
        return $quote['has_link'] && $quote['token_expires_at'] !== null && (string) $quote['token_expires_at'] > Clock::now() && $quote['anonymised_at'] === null;
    }

    /** Withdraws the customer's link at once (the admin's "Withdraw link"); false when there was none. */
    public static function withdrawLink(App $app, int $quoteId, int $byUserId): bool
    {
        $db = $app->db();
        $row = self::row($db, $quoteId);
        if ($row === null || $row['token_hash'] === null) {
            return false;
        }
        $db->update('quotes', ['token_hash' => null, 'history' => self::history($row['history'], ['at' => Clock::now(), 'by' => $byUserId > 0 ? $byUserId : null, 'action' => 'link_withdrawn'])], ['id' => $quoteId]);
        \Kaleta\Admin\ChangeLog::write($app, Quotes::SECTION, 'link', t('Quote %s: the customer’s link withdrawn', Quotes::label($row['number'] === null ? null : (string) $row['number'], $quoteId)));

        return true;
    }

    /**
     * The quote a customer's token opens, or null: not a token, no such link, withdrawn, past its end, a draft, anonymised
     * or without its frozen document. Never more than "no".
     */
    public static function byToken(Db $db, string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        try {
            $id = $db->value("SELECT id FROM {quotes} WHERE token_hash = ? AND token_expires_at > ? AND status <> 'draft' AND anonymised_at IS NULL AND snapshot IS NOT NULL",
                [hash('sha256', $token), Clock::now()]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0123
        }

        return $id === null || $id === false ? null : (int) $id;
    }

    /** The absolute address of a token: site_url (or this request's origin) + /quote/<token>. */
    private static function absolute(App $app, string $token): string
    {
        $s = $app->settings();

        return rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/') . $app->request->basePath() . '/quote/' . $token;
    }

    /* ---------- sending ---------- */

    /**
     * What the send dialog and send_quote offer before anything is sent: the recipient, the subject and message in the
     * quote's language, whether the deal box is shown and ticked, and why it cannot be sent (null = it can).
     *
     * @param array<string, mixed> $quote as Quotes::get returns it
     * @return array{to: string, subject: string, message: string, refusal: ?string, smtp: ?string, deal: bool, set_deal_value: bool, language: string}
     */
    public static function draft(App $app, array $quote): array
    {
        $s = $app->settings();
        $language = Language::ofContent($s, (string) $quote['language']);
        [$subject, $message] = Language::runWith($language, fn (): array => [self::defaultSubject($quote), implode("\n\n", [t('Hello,'),
            t('thank you for your interest. You will find our quotation at the link below – you can print it or save it as a PDF.'), t('Kind regards')])], '', $s->get('german_register'));

        // 3.38 review L3: the deal box only for someone who may open the Pipeline (as Finalise and mark_quote)
        $deal = $quote['deal_id'] !== null && Deals::mayOpen($app->db(), $s, $app->auth()->id());

        return ['to' => (string) $quote['customer']['email'], 'subject' => $subject, 'message' => $message, 'refusal' => Quotes::sendRefusal($quote),
            'smtp' => self::smtpRefusal($s), 'deal' => $deal, 'set_deal_value' => $deal && $s->bool('quote_set_deal_value'), 'language' => $language];
    }

    /** Why the site cannot send a quote by e-mail now (translated), or null: like replies, only through SMTP and with a sender. */
    public static function smtpRefusal(Settings $s): ?string
    {
        $reason = EnquiryReply::unavailable($s);

        return $reason === null ? null : t($reason);
    }

    /**
     * Why Claude may not send this quote (A-5), or null: its e-mail must be the address of its contact or of its enquiry – an
     * address typed into the quote is sent only by a person from the admin. 3.38 review M3: and that address must not come
     * from Claude itself – a contact whose e-mail a Claude connection set last (Contacts::emailByAgent: save_contact, a record
     * made from an imported enquiry) or an enquiry import_enquiries brought in (zdroj "import:…") does not count.
     *
     * @param array<string, mixed> $quote as Quotes::get returns it
     */
    public static function claudeRefusal(Db $db, array $quote): ?string
    {
        $email = PersonalData::cleanAddress((string) $quote['customer']['email']);
        if ($email === '') {
            return null; // sendRefusal says what is missing
        }
        $known = $byClaude = [];
        if ($quote['contact_id'] !== null) {
            // 3.38 review M3: an address a Claude connection wrote into the customer record (save_contact, an imported enquiry) is not the customer's own
            $address = (string) ($db->value('SELECT email FROM {contacts} WHERE id = ?', [$quote['contact_id']]) ?? '');
            if (Contacts::emailByAgent($db, (int) $quote['contact_id'])) {
                $byClaude[] = $address;
            } else {
                $known[] = $address;
            }
        }
        if ($quote['enquiry_id'] !== null) {
            // an enquiry import_enquiries (MCP) brought in carries an address Claude passed, not one the customer typed into the site
            $enquiry = $db->one('SELECT email, zdroj FROM {poptavky} WHERE idp = ?', [$quote['enquiry_id']]);
            if ($enquiry !== null && str_starts_with((string) $enquiry['zdroj'], 'import:')) {
                $byClaude[] = (string) $enquiry['email'];
            } elseif ($enquiry !== null) {
                $known[] = (string) $enquiry['email'];
            }
        }
        foreach ($known as $address) {
            if ($address !== '' && PersonalData::cleanAddress($address) === $email) {
                return null;
            }
        }
        foreach ($byClaude as $address) {
            if ($address !== '' && PersonalData::cleanAddress($address) === $email) {
                return 'The quote\'s e-mail was written by a Claude connection (a customer record saved or an enquiry imported over MCP), so it is not proven to be the customer\'s own address – Claude does not send to it. A person checks the address and sends the quote from the admin (Quotes → the quote → Send).';
            }
        }

        return 'The quote\'s e-mail is not the address of its customer record or enquiry – Claude sends a quote only to that address. A person can send it from the admin (Quotes → the quote → Send).';
    }

    /**
     * Sends a draft to its customer: the number (Quotes::markSent), the link, the e-mail in the quote's language and – when
     * asked – the deal's value (A-10). $subject may hold {number}; an empty subject or message takes the default.
     *
     * @param 'admin'|'mcp' $via
     * @param (\Closure(array<string, mixed>): ?array{name: string, type: string, data: string})|null $attachment 3.40: makes the file sent
     *        with the e-mail from the sent quote (the caller's Front\QuotePdf – Core draws no documents); null from it = no file
     * @return array{id: int, number: string, status: 'sent'|'queued', error: string, to: string, link: string, deal_value_set: bool, warnings: list<string>, pdf: bool}
     * @throws \DomainException with a translated reason; nothing is sent
     */
    public static function send(App $app, int $id, string $subject, string $message, bool $setDealValue, string $via = 'admin', ?\Closure $attachment = null): array
    {
        $db = $app->db();
        $s = $app->settings();
        $quote = Quotes::get($db, $id) ?? throw new \DomainException(t('The quote does not exist.'));
        $refusal = Quotes::sendRefusal($quote) ?? self::smtpRefusal($s);
        if ($refusal !== null) {
            throw new \DomainException($refusal);
        }
        $to = Mail::addresses((string) $quote['customer']['email']);
        if (count($to) !== 1) {
            throw new \DomainException(t('The customer’s e-mail is not a valid address.'));
        }
        if ($via === 'mcp' && ($claude = self::claudeRefusal($db, $quote)) !== null) {
            throw new \DomainException($claude);
        }
        $draft = self::draft($app, $quote);
        $subject = trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject), 0, self::MAX_SUBJECT));
        $message = trim(mb_substr(str_replace("\r\n", "\n", strip_tags($message)), 0, self::MAX_MESSAGE));
        $user = $app->auth()->user() ?? [];

        $sent = Quotes::markSent($app, $id, (int) ($user['idu'] ?? 0));
        $link = self::issueLink($app, $id, (int) ($user['idu'] ?? 0));
        $number = (string) $sent['number'];
        $subject = str_replace('{number}', $number, $subject !== '' ? $subject : $draft['subject']);
        $message = $message !== '' ? $message : $draft['message'];
        $replyTo = Mail::addresses((string) ($user['email'] ?? ''));
        $headers = count($replyTo) === 1 ? ['Reply-To' => $replyTo[0]] : [];
        $signature = EnquiryReply::signature($app, $user);
        [$plain, $html] = Language::runWith($draft['language'], fn (): array => self::mail($sent, $message, $link, $signature), '', $s->get('german_register'));
        $warnings = [];
        $file = $attachment !== null ? $attachment($sent) : null; // 3.40: the quote as a PDF, when asked and when its language can be drawn
        if ($attachment !== null && $file === null) {
            $warnings[] = t('The e-mail went without the PDF: the quote’s language or text cannot be drawn in a PDF – the customer has the link.');
        }
        $ok = Mail::send($s, $to[0], $subject, $plain, $html, $headers, true, t('Quote %1$s: %2$s', $number, $s->get('site_name')), $file !== null ? [$file] : []);
        $mailId = Mail::$lastId;
        if ($mailId !== null) {
            $db->update('quotes', ['sent_mail' => $mailId], ['id' => $id]);
        }
        $dealSet = false;
        if ($setDealValue && $sent['deal_id'] !== null && !Deals::mayOpen($db, $s, (int) ($user['idu'] ?? 0))) {
            $warnings[] = t('The deal’s value was not changed: only a user who may open the Pipeline changes a deal.'); // 3.38 review L3
        } elseif ($setDealValue && $sent['deal_id'] !== null) {
            try {
                $dealSet = Quotes::setDealValue($app, $id, (int) ($user['idu'] ?? 0));
            } catch (\DomainException $e) {
                $warnings[] = t('The deal’s value was not changed: %s', $e->getMessage());
            }
        }
        Events::record($db, 'quote.sent', $ok ? 'info' : 'warning', mb_substr(t($ok ? 'Quote %s was sent to the customer.' : 'Quote %s waits in the mail queue for another attempt.', $number), 0, 255), ['quote' => $id, 'via' => $via]);

        return ['id' => $id, 'number' => $number, 'status' => $ok ? 'sent' : 'queued', 'error' => $ok ? '' : Mail::$error, 'to' => $to[0], 'link' => $link,
            'deal_value_set' => $dealSet, 'warnings' => $warnings, 'pdf' => $file !== null];
    }

    /** The default subject in the current language: "Quotation {number} – <title>". @param array<string, mixed> $quote */
    public static function defaultSubject(array $quote): string
    {
        $title = trim((string) $quote['title']);

        return t('Quotation %s', '{number}') . ($title !== '' ? ' – ' . $title : '');
    }

    /**
     * The e-mail in the current language: the message, then the number, the validity, the total and the link; the
     * signature under the "-- " line. HTML only with a signature or in a right-to-left language (Mail adds that one).
     *
     * @param array<string, mixed> $quote as Quotes::get returns it (sent)
     * @param array{text: string, html: string}|null $signature
     * @return array{0: string, 1: string}
     */
    public static function mail(array $quote, string $message, string $link, ?array $signature): array
    {
        $facts = self::facts($quote);
        $plain = $message . "\n\n" . implode("\n", array_map(fn (array $f): string => $f[0] . ': ' . $f[1], $facts)) . "\n\n" . t('Open the quotation: %s', $link)
            . ($signature !== null && $signature['text'] !== '' ? "\n\n-- \n" . $signature['text'] : '');
        $dir = Language::isRtl(Language::code()) ? ' dir="rtl"' : '';
        $rows = implode('', array_map(fn (array $f): string => '<tr><td style="padding:2px 16px 2px 0;color:#57606a;">' . e($f[0]) . '</td><td style="padding:2px 0;font-weight:600;">' . e($f[1]) . '</td></tr>', $facts));
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body' . $dir . '>'
            . '<div style="font:15px/1.6 system-ui, -apple-system, \'Segoe UI\', Roboto, Arial, sans-serif; color:#1f2328;">'
            . '<div style="white-space:pre-wrap;">' . e($message) . '</div>'
            . '<table role="presentation" style="margin:20px 0;border-collapse:collapse;font-size:15px;">' . $rows . '</table>'
            . '<p style="margin:0 0 24px;"><a href="' . e($link) . '" style="display:inline-block;padding:10px 18px;border-radius:6px;background:#1f2328;color:#ffffff;text-decoration:none;font-weight:600;">' . e(t('Open the quotation')) . '</a></p>'
            . '<p style="margin:0;font-size:13px;color:#57606a;word-break:break-all;">' . e($link) . '</p></div>'
            . ($signature !== null && $signature['html'] !== '' ? '<div style="margin-top:24px;">' . $signature['html'] . '</div>' : '') . '</body></html>';

        return [$plain, $html];
    }

    /**
     * The number, the validity and the total of a quote, labelled in the current language – the lines of the e-mail.
     *
     * @param array<string, mixed> $quote
     * @return list<array{0: string, 1: string}>
     */
    public static function facts(array $quote): array
    {
        $totals = $quote['totals'];
        $currency = (string) $quote['currency'];
        $withVat = $quote['vat_mode'] === 'standard' && ($quote['snapshot']['vat_payer'] ?? true) !== false;
        $facts = [[t('Quotation number'), (string) $quote['number']]];
        if ($quote['valid_until'] !== null) {
            $facts[] = [t('Valid until'), format_date((string) $quote['valid_until'])];
        }
        $facts[] = $withVat ? [t('Total including VAT'), self::money((string) $totals['gross'], $currency)] : [t('Total'), self::money((string) $totals['net'], $currency)];
        if ($withVat) {
            $facts[] = [t('Total without VAT'), self::money((string) $totals['net'], $currency)];
        }

        return $facts;
    }

    /**
     * An amount for the customer in the current language: always two decimals, thousands separated, the currency as the
     * site shows it (Deals::moneyFormat). "12 500,00 Kč", "€1,250.50".
     */
    public static function money(?string $decimal, string $currency): string
    {
        $minor = Money::fromDecimal($decimal);
        if ($minor === null) {
            return '';
        }
        $format = Deals::moneyFormat();
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        $text = number_format(intdiv($minor, 100), 0, '', $format['thousands']) . $format['decimal'] . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
        [$before, $after] = $format['affixes'][$currency] ?? ['', "\u{00A0}" . $currency];

        return $sign . $before . $text . $after;
    }

    /* ---------- the expiry job ---------- */

    /**
     * The background job "quotes": sent quotes past their validity expire (by Kaleta, into the history) and their
     * responsible person gets a bell; EXPIRING_DAYS before, one reminder; links past their end are withdrawn. Returns a short
     * result for the job list.
     */
    public static function run(App $app): string
    {
        $db = $app->db();
        try {
            $due = $db->all("SELECT id, owner_id FROM {quotes} WHERE status = 'sent' AND valid_until IS NOT NULL AND valid_until < ? ORDER BY id LIMIT " . self::BATCH, [Clock::today()]);
            $soon = $db->all("SELECT id, owner_id FROM {quotes} WHERE status = 'sent' AND owner_id IS NOT NULL AND valid_until IS NOT NULL AND valid_until >= ? AND valid_until <= ? ORDER BY id LIMIT " . self::BATCH,
                [Clock::today(), Clock::day('+' . self::EXPIRING_DAYS . ' days')]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 'not needed'; // before migration 0123
        }
        $expired = 0;
        foreach ($due as $q) {
            try {
                if (Quotes::setStatus($app, (int) $q['id'], 'expired', 0)) {
                    $expired++;
                    self::notifyOwner($app, $q['owner_id'] === null ? 0 : (int) $q['owner_id'], 'quote_expired', (int) $q['id']);
                }
            } catch (\DomainException) {
                // changed by a person in the meantime – nothing to expire
            }
        }
        $reminded = 0;
        foreach ($soon as $q) {
            $target = 'quote:' . (int) $q['id'];
            if ((int) $db->value("SELECT COUNT(*) FROM {notifications} WHERE kind = 'quote_expiring' AND target = ?", [$target]) === 0) {
                $reminded += self::notifyOwner($app, (int) $q['owner_id'], 'quote_expiring', (int) $q['id']) ? 1 : 0;
            }
        }
        $withdrawn = $db->run('UPDATE {quotes} SET token_hash = NULL WHERE token_hash IS NOT NULL AND token_expires_at <= ?', [Clock::now()])->rowCount();

        return 'expired ' . $expired . ', reminded ' . $reminded . ', links ended ' . $withdrawn;
    }

    /** A bell for the responsible person, when they may still open Quotes. Returns whether one was sent. */
    private static function notifyOwner(App $app, int $ownerId, string $kind, int $quoteId): bool
    {
        if ($ownerId <= 0 || !Quotes::mayOpen($app->db(), $app->settings(), $ownerId)) {
            return false;
        }
        Inbox::notify($app, $ownerId, $kind, 'quote:' . $quoteId, null);

        return true;
    }

    /**
     * The places of quote targets for the bell ("quote:<id>" => kind, id, title = the number or #id, path) – never an
     * amount, never the customer.
     *
     * @param list<int> $ids
     * @return array<string, array{kind: string, id: int, title: string, path: string}>
     */
    public static function places(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        try {
            $rows = $db->all('SELECT id, number FROM {quotes} WHERE id IN (' . implode(',', $ids) . ')');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0123
        }
        $out = [];
        foreach ($rows as $r) {
            $out['quote:' . (int) $r['id']] = ['kind' => 'quote', 'id' => (int) $r['id'], 'title' => Quotes::label($r['number'] === null ? null : (string) $r['number'], (int) $r['id']),
                'path' => Quotes::adminPath((int) $r['id'])];
        }

        return $out;
    }

    /* ---------- retention ---------- */

    /**
     * The daily clean-up (Notifications::purgePersonalData): drafts untouched for DRAFT_MONTHS are deleted; quotes_months
     * (0 = keep) after the last status change a quote is deleted or – when the site anonymises old enquiries – kept
     * without the person. Runs whether the feature is on or not (data from when it was on go too).
     *
     * @return array{drafts: int, deleted: int, anonymised: int}
     */
    public static function purge(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $done = ['drafts' => 0, 'deleted' => 0, 'anonymised' => 0];
        try {
            $drafts = array_map('intval', array_column($db->all("SELECT id FROM {quotes} WHERE status = 'draft' AND updated_at < ? ORDER BY id LIMIT " . self::BATCH,
                [Clock::monthsAgo(self::DRAFT_MONTHS)]), 'id'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return $done; // before migration 0123
        }
        $done['drafts'] = self::delete($db, $drafts);
        $months = max(0, $s->int('quotes_months'));
        if ($months > 0) {
            // 3.38 review L6: deleting takes the anonymised ones too (erased by a request, or kept while the site anonymised)
            $anonymise = $s->get('enquiries_expiry') === 'anonymise';
            $old = array_map('intval', array_column($db->all("SELECT id FROM {quotes} WHERE status <> 'draft'" . ($anonymise ? ' AND anonymised_at IS NULL' : '') . ' AND updated_at < ? ORDER BY id LIMIT ' . self::BATCH,
                [Clock::monthsAgo($months)]), 'id'));
            if ($anonymise) {
                $done['anonymised'] = self::anonymise($db, $old);
            } else {
                $done['deleted'] = self::delete($db, $old);
            }
        }

        return $done;
    }

    /**
     * Which of the given quotes the retention would take now (pure selection, for the unit test): drafts older than
     * DRAFT_MONTHS, others older than $months after their last status change (0 = never); anonymised ones are not
     * anonymised again, but go when the site deletes ($delete, 3.38 review L6).
     *
     * @param list<array{status: string, updated_at: string, anonymised_at: ?string}> $quotes
     * @return list<int> the keys of the quotes that go
     */
    public static function due(array $quotes, int $months, string $now, bool $delete = false): array
    {
        $at = function (int $m) use ($now): string {
            // as Clock::monthsAgo counts: a day the shorter month does not have becomes its last day
            $day = new \DateTimeImmutable($now);
            $month = $day->modify('first day of this month')->modify('-' . $m . ' months');

            return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min((int) $day->format('j'), (int) $month->format('t')))->format('Y-m-d H:i:s');
        };
        $out = [];
        foreach ($quotes as $key => $q) {
            if ($q['status'] === 'draft' ? $q['updated_at'] < $at(self::DRAFT_MONTHS) : ($months > 0 && ($delete || $q['anonymised_at'] === null) && $q['updated_at'] < $at($months))) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** Deletes quotes with their lines. Returns how many. @param list<int> $ids */
    public static function delete(Db $db, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        return $db->transaction(function (Db $db) use ($ids): int {
            $in = implode(',', $ids);
            $db->run('DELETE FROM {quote_lines} WHERE quote_id IN (' . $in . ')');

            return $db->run('DELETE FROM {quotes} WHERE id IN (' . $in . ')')->rowCount();
        });
    }

    /**
     * Keeps quotes as the firm's record without the person: the customer block, the title, intro and terms (free text that
     * may name them), the frozen document, the link and the links to the contact, company, enquiry and deal go (3.38 review
     * L5, design A-11); the number, the lines, the totals, the status and the history stay. Returns how many (already
     * anonymised ones are skipped). @param list<int> $ids
     */
    public static function anonymise(Db $db, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $now = Clock::now();

        return $db->run("UPDATE {quotes} SET customer_name = '', customer_company = '', customer_address = '', customer_reg_no = '', customer_vat_id = '', customer_email = '',"
            . " title = '', intro = NULL, terms = NULL, contact_id = NULL, company_id = NULL, enquiry_id = NULL, deal_id = NULL," // 3.38 review L5: free texts may name the person, the links lead to them
            . ' snapshot = NULL, token_hash = NULL, token_expires_at = NULL, anonymised_at = ? WHERE anonymised_at IS NULL AND id IN (' . implode(',', $ids) . ')', [$now])->rowCount();
    }

    /* ---------- customers, enquiries and personal data ---------- */

    /** Does the contact have a quote waiting for the customer's answer (status sent)? Such a contact is not anonymised. */
    public static function hasOpenQuote(Db $db, int $contactId): bool
    {
        try {
            return (int) $db->value("SELECT COUNT(*) FROM {quotes} WHERE contact_id = ? AND status = 'sent' AND anonymised_at IS NULL", [$contactId]) > 0;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return false; // before migration 0123
        }
    }

    /**
     * The enquiries of the given ones a quote still needs (Contacts::heldByDeals): the enquiry of a quote that is sent
     * and waits for the answer, or whose last status change is less than $months ago.
     *
     * @param list<int> $idps
     * @return list<int>
     */
    public static function heldEnquiries(Db $db, array $idps, int $months): array
    {
        $idps = array_values(array_unique(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0)));
        if ($idps === []) {
            return [];
        }
        try {
            return array_map('intval', array_column($db->all("SELECT DISTINCT enquiry_id FROM {quotes} WHERE enquiry_id IN (" . implode(',', $idps) . ") AND anonymised_at IS NULL"
                . " AND (status = 'sent' OR (status <> 'draft' AND updated_at >= ?))", [Clock::monthsAgo(max(0, $months))]), 'enquiry_id'));
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0123
        }
    }

    /** Quotes of deleted enquiries keep their customer block and lose the link (every path that deletes enquiries, via Deals::forgetEnquiries). @param list<int> $idps */
    public static function forgetEnquiries(Db $db, array $idps): void
    {
        $idps = array_values(array_filter(array_map('intval', $idps), fn (int $id): bool => $id > 0));
        if ($idps === []) {
            return;
        }
        try {
            $db->run('UPDATE {quotes} SET enquiry_id = NULL WHERE enquiry_id IN (' . implode(',', $idps) . ')');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0123
        }
    }

    /** A deleted or anonymised contact (or company) leaves its quotes, which keep their own customer block. @param list<int> $ids */
    public static function unlink(Db $db, string $column, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0));
        if ($ids === [] || !in_array($column, ['contact_id', 'company_id'], true)) {
            return;
        }
        try {
            $db->run('UPDATE {quotes} SET ' . $column . ' = NULL WHERE ' . $column . ' IN (' . implode(',', $ids) . ')');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            // before migration 0123
        }
    }

    /** Two contacts merged: the quotes of the one that goes move to the one that stays. Returns how many. */
    public static function moveContact(Db $db, int $from, int $into): int
    {
        try {
            return $db->run('UPDATE {quotes} SET contact_id = ? WHERE contact_id = ?', [$into, $from])->rowCount();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0123
        }
    }

    /**
     * The quotes of a person for their file (PersonalData::find): sent to the address or made for one of their contacts,
     * with the customer block, the texts and the lines (their own quote – the prices they were offered).
     *
     * @param list<int> $contactIds
     * @return list<array<string, mixed>>
     */
    public static function ofPerson(Db $db, string $email, array $contactIds): array
    {
        $contactIds = array_values(array_filter(array_map('intval', $contactIds), fn (int $id): bool => $id > 0));
        try {
            $rows = $db->all('SELECT id, LOWER(customer_email) AS email FROM {quotes} WHERE LOWER(customer_email) = ?' . ($contactIds !== [] ? ' OR contact_id IN (' . implode(',', $contactIds) . ')' : '') . ' ORDER BY id', [$email]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return []; // before migration 0123
        }
        $out = [];
        foreach ($rows as $r) {
            $q = Quotes::get($db, (int) $r['id']);
            if ($q === null) {
                continue;
            }
            $out[] = ['id' => $q['id'], 'number' => $q['number'], 'status' => $q['status'], 'title' => $q['title'], 'customer' => $q['customer'], 'currency' => $q['currency'],
                'intro' => $q['intro'], 'terms' => $q['terms'], 'valid_until' => $q['valid_until'], 'sent_at' => $q['sent_at'], 'created_at' => $q['created_at'], 'totals' => $q['totals'],
                'lines' => array_map(fn (array $l): array => array_intersect_key($l, array_flip(['code', 'name', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'vat_rate', 'line_net'])), $q['lines'])];
        }

        return $out;
    }

    /**
     * Erases the quotes of a person (PersonalData::erase): their drafts go, a numbered quote stays without the person.
     *
     * @param list<array<string, mixed>> $quotes as ofPerson returns them
     * @return int how many
     */
    public static function erase(Db $db, array $quotes): int
    {
        $drafts = array_map(fn (array $q): int => (int) $q['id'], array_filter($quotes, fn (array $q): bool => $q['number'] === null));
        $numbered = array_map(fn (array $q): int => (int) $q['id'], array_filter($quotes, fn (array $q): bool => $q['number'] !== null));

        return self::delete($db, array_values($drafts)) + self::anonymise($db, array_values($numbered));
    }

    /* ---------- reports and the record of processing ---------- */

    /**
     * The quotes of a month for the monthly report – counts only, never money (A6): sent (or finalised), accepted,
     * declined and expired in the month. Null when there is none.
     *
     * @return array{sent: int, accepted: int, declined: int, expired: int}|null
     */
    public static function monthCounts(Db $db, string $from, string $to): ?array
    {
        try {
            $sent = (int) $db->value('SELECT COUNT(*) FROM {quotes} WHERE sent_at >= ? AND sent_at < ?', [$from, $to]);
            $decided = array_map('intval', $db->pairs("SELECT status, COUNT(*) FROM {quotes} WHERE status IN ('accepted', 'declined') AND decided_at >= ? AND decided_at < ? GROUP BY status", [$from, $to]));
            $expired = (int) $db->value("SELECT COUNT(*) FROM {quotes} WHERE status = 'expired' AND updated_at >= ? AND updated_at < ?", [$from, $to]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0123
        }
        $counts = ['sent' => $sent, 'accepted' => $decided['accepted'] ?? 0, 'declined' => $decided['declined'] ?? 0, 'expired' => $expired];

        return array_sum($counts) > 0 ? $counts : null;
    }

    /** How many quotes hold a customer (the record of processing). */
    public static function withCustomer(Db $db): int
    {
        try {
            return (int) $db->value('SELECT COUNT(*) FROM {quotes} WHERE anonymised_at IS NULL');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0123
        }
    }

    /* ---------- helpers ---------- */

    /** @return array<string, mixed>|null the columns of a quote this class works with */
    private static function row(Db $db, int $id): ?array
    {
        return $id > 0 ? $db->one('SELECT id, number, status, valid_until, snapshot, token_hash, token_expires_at, history, anonymised_at FROM {quotes} WHERE id = ?', [$id]) : null;
    }

    /** The stored history with one more entry, at most Quotes::HISTORY_MAX, as JSON. */
    private static function history(mixed $json, array $entry): string
    {
        $history = json_decode((string) $json, true);
        $history = is_array($history) ? array_values($history) : [];
        $history[] = $entry;

        return (string) json_encode(array_slice($history, -Quotes::HISTORY_MAX), JSON_UNESCAPED_UNICODE);
    }
}
