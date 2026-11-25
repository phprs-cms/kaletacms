<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\DesignSystem;
use Kaleta\Front\Subscription;

/**
 * Newsletters (Newsletter extension): "send the latest news to subscribers". There is no e-mail builder – one template
 * styled by the design system (colours, fonts, corner radius, logo) holds a subject, an intro, the latest or chosen news
 * items, a button and the company footer. One fixed renderer (views/email/newsletter.php) writes table-based HTML with
 * inline styles that mail clients show alike, and a plain-text part.
 *
 * Sending goes only through the SMTP server set in Settings → Mail – mail() of shared hosting is not fit for bulk mail –
 * and only while cron calls /tasks (or the older /ulohy): batches go out there, within the hourly limit, so a low-traffic site does not stall
 * half-way. Every e-mail has its own unsubscribe link and one-click unsubscribe (List-Unsubscribe, RFC 8058); nothing
 * tracks opens. The queue keeps recipients only while sending; a day after the end only the counts and dates remain.
 *
 * 3.51 – honest sending: a newsletter names its sender (Core\SenderIdentity) and says why it arrived; it skips the addresses
 * on the register (Core\SendRegister) and records the ones a server refused as unknown (only when the newsletter delivered
 * to someone, D4); a person may pause, resume or cancel a send, and the brake pauses it by itself when 5 of the last 50
 * attempts failed at the server (D5). A paused send is cancelled after PAUSED_DAYS. In both end states
 * recipients = sent_count + failed_count + skipped_count (the remainder is computed when it ends).
 */
final class Mailing
{
    public const array STATUSES = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'paused' => 'Paused', 'sent' => 'Sent', 'cancelled' => 'Cancelled'];

    public const array NEWS_MODES = ['latest' => 'The latest news', 'chosen' => 'Chosen news items', 'none' => 'No news'];

    /** 3.54 (A1): who a newsletter goes to, and the filter on top (Core\Audience). */
    public const array AUDIENCES = Audience::AUDIENCES;

    public const array AUDIENCE_FILTERS = Audience::FILTERS;

    /** Cron must have called /tasks (/ulohy) within this many minutes, otherwise a newsletter would stall half-way. */
    public const int CRON_MINUTES = 30;

    /** At most this many chosen or latest news items in one newsletter. */
    public const int MAX_NEWS = 10;

    /** E-mails per cron call (the hourly limit applies on top). */
    private const int BATCH = 100;

    /** Minutes until a failed e-mail is retried; after the last one it is given up and counted as failed. */
    private const array RETRY_MINUTES = [15, 60];

    /** Placeholder in the rendered e-mail, replaced with each subscriber's own unsubscribe link. */
    public const string UNSUBSCRIBE = '{{unsubscribe}}';

    /** 3.51 (D5): the brake looks at this many of the last attempts since the start or the last resume … */
    public const int BRAKE_WINDOW = 50;

    /** … and pauses the send when this many of them failed at the mail server. */
    public const int BRAKE_FAILURES = 5;

    /**
     * 3.51 review: the reply of a queue row the brake put back – the only mark of such a row (a resume, an attempt cut off
     * between claim() and its answer and a retry left from 3.50 also have attempts > 0 and no tried_at, so those cannot be
     * told apart by them). The next attempt overwrites it; the brake never counts it.
     */
    public const string PUT_BACK = 'brake';

    /** 3.51: a send paused this many days is cancelled by itself (Retention::FIXED). */
    public const int PAUSED_DAYS = 7;

    /** 3.51: the queue (who got the e-mail) is deleted this many days after a newsletter ends (Retention::FIXED). */
    public const int RECIPIENT_DAYS = 1;

    /** 3.51 (B7): links carry the campaign name (subject and number) only from this many recipients. */
    public const int TAG_MIN = 5;

    /**
     * 3.51 (D4): enhanced status codes by which a server refuses the recipient's address itself at RCPT (no such mailbox,
     * bad address syntax, …) – 5.1.0, 5.1.7 and 5.1.8 speak about the sender and are not here.
     */
    public const array ADDRESS_REFUSALS = ['5.1.1', '5.1.2', '5.1.3', '5.1.6', '5.1.10'];

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $id): ?array
    {
        return $db->one('SELECT * FROM {newsletters} WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> newest first, without the rendered e-mail */
    public static function all(Db $db): array
    {
        // 3.54: the audience, the unsubscriptions through its links and whether its frozen e-mail carries the campaign name
        return $db->all("SELECT id, subject, news_mode, news_count, language, status, scheduled_at, recipients, sent_count, failed_count, skipped_count, created, changed, started_at, finished_at,
            paused_at, pause_reason, resumed_at, audience, audience_filter, unsubscribed_count, (html LIKE '%utm_campaign=%') AS tagged FROM {newsletters} ORDER BY COALESCE(finished_at, started_at, scheduled_at, changed, created) DESC, id DESC");
    }

    /**
     * Values from the admin form or from Claude, checked; keys that are not given keep the current value.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function sanitize(App $app, array $input, array $current = []): array
    {
        $value = fn (string $key, string $default = ''): string => trim((string) (array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? $default)));
        $subject = mb_substr(preg_replace('/\s+/u', ' ', $value('subject')) ?? '', 0, 200);
        if ($subject === '') {
            throw new \InvalidArgumentException('The newsletter needs a subject.');
        }
        $mode = $value('news_mode', 'latest');
        if (!isset(self::NEWS_MODES[$mode])) {
            throw new \InvalidArgumentException('news_mode must be latest, chosen or none.');
        }
        $ids = $input['news_ids'] ?? ($current['news_ids'] ?? '');
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : explode(',', (string) $ids)), fn (int $i): bool => $i > 0))), 0, self::MAX_NEWS);
        if ($mode === 'chosen' && $ids === []) {
            throw new \InvalidArgumentException('Choose at least one news item, or send the latest news.');
        }
        $url = $value('button_url');
        if ($url !== '' && !preg_match('~^(https?://[^\s<>"]+|/[^\s<>"]*)$~iD', $url)) {
            throw new \InvalidArgumentException('The button link must be an https:// address or a path on the site starting with /.');
        }
        $label = mb_substr($value('button_label'), 0, 80);
        $language = $value('language');
        $languages = [Language::defaults($app->settings()), ...Language::additional($app->settings())];
        if ($language !== '' && !in_array($language, $languages, true)) {
            throw new \InvalidArgumentException('Unknown language – the site has: ' . implode(', ', $languages) . '.');
        }

        $audience = $value('audience', 'all');
        if (!isset(self::AUDIENCES[$audience])) {
            throw new \InvalidArgumentException('audience must be all or language.'); // never a wider default for a typo
        }

        return [
            'subject' => $subject,
            'preheader' => mb_substr(preg_replace('/\s+/u', ' ', $value('preheader')) ?? '', 0, 200),
            'intro' => mb_substr(str_replace("\r\n", "\n", $value('intro')), 0, 5000),
            'news_mode' => $mode,
            'news_count' => max(1, min(self::MAX_NEWS, (int) $value('news_count', '3'))),
            'news_ids' => implode(',', $ids),
            'button_label' => $url !== '' ? ($label !== '' ? $label : '') : '',
            'button_url' => $label !== '' ? $url : '',
            'language' => $language === Language::defaults($app->settings()) ? '' : $language,
            'audience' => $audience,
            'audience_filter' => Audience::filterFrom($input, (string) ($current['audience_filter'] ?? '')),
        ];
    }

    /**
     * Creates a draft (without id) or changes a draft or a scheduled newsletter.
     *
     * @param array<string, mixed> $input
     */
    public static function save(App $app, array $input, int $id = 0): int
    {
        $db = $app->db();
        if ($id > 0) {
            $n = self::byId($db, $id) ?? throw new \InvalidArgumentException('The newsletter does not exist.');
            if (!in_array($n['status'], ['draft', 'scheduled'], true)) {
                throw new \DomainException('A newsletter that is being sent or was sent cannot be changed.');
            }
            // a scheduled newsletter goes out on its own: changing it is publishing (not for authors or a Claude connection limited to drafts)
            if ($n['status'] === 'scheduled' && !$app->auth()->canPublish()) {
                throw new \DomainException('A scheduled newsletter can be changed only by someone who may send newsletters – unschedule it first.');
            }
            $data = self::sanitize($app, $input, $n);
            // 3.54 (A2): who a scheduled newsletter goes to was confirmed when it was scheduled – it never changes behind that yes
            if ($n['status'] === 'scheduled' && self::audienceKey($data) !== self::audienceKey($n)) {
                throw new \DomainException('The audience of a scheduled newsletter cannot change – unschedule it first.');
            }
            $db->update('newsletters', $data + ['changed' => date('Y-m-d H:i:s')], ['id' => $id]);

            return $id;
        }

        return $db->insert('newsletters', self::sanitize($app, $input) + [
            'status' => 'draft', 'author' => $app->auth()->user()['idu'] ?? null, 'created' => date('Y-m-d H:i:s'), 'changed' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Deletes a newsletter that is not being sent right now (a sent one leaves only the site's own records). */
    public static function delete(App $app, int $id): void
    {
        $n = self::byId($app->db(), $id) ?? throw new \InvalidArgumentException('The newsletter does not exist.');
        if ($n['status'] === 'sending') {
            throw new \DomainException('The newsletter is being sent right now – it cannot be deleted until it finishes.');
        }
        if ($n['status'] === 'paused') {
            throw new \DomainException('The newsletter is paused – cancel it first, then it can be deleted.');
        }
        // the rows that went out in the last hour stay until the hourly limit no longer counts them (housekeeping deletes them):
        // cancel, delete and send a corrected newsletter must not exceed the provider's limit (3.51 review)
        $app->db()->run('DELETE FROM {newsletter_queue} WHERE newsletter_id = ? AND (sent_at IS NULL OR sent_at < ?)', [$id, Clock::at('-1 hour')]);
        $app->db()->delete('newsletters', ['id' => $id]);
    }

    /**
     * Why the site cannot send newsletters now, or null when it can: an SMTP server and a running cron are required.
     */
    public static function problem(App $app): ?string
    {
        return self::problemIn($app->settings(), time());
    }

    /** problem() for the given settings and time (3.51: also when the sender – company name and postal address – is missing). */
    public static function problemIn(Settings $s, int $now): ?string
    {
        if ($s->get('mail_mode') !== 'smtp' || $s->get('smtp_host') === '') {
            return 'Newsletters are sent only through an SMTP server (Brevo, Amazon SES, Mailgun, your mailbox…) – set it up in Settings → Mail.';
        }
        if ($now - $s->int('tasks_last_run') > self::CRON_MINUTES * 60) {
            return 'Cron has not called the tasks address in the last 30 minutes – newsletters go out in batches only while it runs. Set it up with the address in System status.';
        }
        if (SenderIdentity::line($s, Language::defaults($s)) === null) {
            return self::SENDER_MISSING;
        }

        return null;
    }

    /** 3.51 (B5): why a newsletter cannot go out without its sender. */
    public const string SENDER_MISSING = 'A newsletter must name its sender – fill in the company name and postal address in Business details.';

    /**
     * 3.51 (B6): what may hurt the delivery, without stopping it – a missing SPF or DMARC record of the sending domain (the
     * cached domain watch), a connected mailing service (subscribers go there too: sending from both writes twice).
     *
     * @return list<string> English sentences (t() in the administration)
     */
    public static function warnings(Settings $s): array
    {
        $warnings = [];
        $watch = DomainWatch::cached($s);
        $mail = is_array($watch['mail'] ?? null) && empty($watch['local']) ? $watch['mail'] : null;
        if ($mail !== null && ($mail['error'] ?? null) === null) {
            if (($mail['spf'] ?? null) === null) {
                $warnings[] = 'The sending domain has no SPF record – newsletters may land in spam. System status shows the record to add.';
            }
            if (($mail['dmarc'] ?? null) === null) {
                $warnings[] = 'The sending domain has no DMARC record – Gmail and Microsoft may refuse bulk mail without it. System status shows the record to add.';
            }
        }
        if ($s->get('newsletter_service') !== '') {
            $warnings[] = 'Subscribers are also copied to a connected mailing service – if you send newsletters there as well, people get them twice.';
        }

        return $warnings;
    }

    /** Every confirmed subscriber of the site – the Subscribers screens and the header of the list; a newsletter counts with recipientCount(). */
    public static function confirmedCount(Db $db): int
    {
        return (int) $db->value('SELECT COUNT(*) FROM {odberatele} WHERE stav = 1');
    }

    /** 3.54 (A1): why this newsletter's audience cannot be sent to, or null – fails closed, never wider (Audience::problem). */
    public static function audienceProblem(App $app, array $n): ?string
    {
        return Audience::problem($app, $n);
    }

    /**
     * 3.54 (A1): the one definition of a newsletter's recipients – a WHERE over "{odberatele} o" for the count shown, the
     * send guard, the campaign-name rule and the queue (Audience::where).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function recipientsWhere(App $app, array $n): array
    {
        return Audience::where($app, $n);
    }

    /** 3.54 (A1): how many subscribers the newsletter goes to now – the number shown, confirmed and compared at the start. */
    public static function recipientCount(App $app, array $n): int
    {
        [$where, $params] = Audience::where($app, $n);

        return (int) $app->db()->value('SELECT COUNT(*) FROM {odberatele} o WHERE ' . $where, $params);
    }

    /** 3.54: the confirmed subscribers of the audience not linked to a customer record yet (Audience::unlinked). */
    public static function unlinkedCount(App $app, array $n): int
    {
        return Audience::unlinked($app, $n);
    }

    /** 3.54 (A2): why the audience is empty, in its own words – the administration and MCP show it (Audience::emptyText). */
    public static function emptyAudience(App $app, array $n): string
    {
        return Audience::emptyText($app, $n);
    }

    /** 3.54: what decides who a newsletter goes to – locked while scheduled, compared by a restore (Audience::key). */
    public static function audienceKey(array $n): string
    {
        return Audience::key($n);
    }

    /**
     * 3.51 review: how many confirmed subscribers a newsletter can reach, as far as the campaign name (TAG_MIN) needs it –
     * the addresses on the send register are skipped by the send: five subscribers of whom three bounced are two people.
     * The number is exact only while it can be below TAG_MIN: when the confirmed subscribers minus every row of the register
     * still reach TAG_MIN, that lower bound is returned without reading and fingerprinting every address (a list of
     * thousands is rendered for each preview). Not for showing as a count of recipients.
     *
     * @param array{0: string, 1: list<mixed>}|null $where
     */
    public static function reachableForCampaign(Db $db, ?array $where = null): int
    {
        [$sql, $params] = $where ?? ['o.stav = 1', []]; // 3.54: the newsletter's own audience (recipientsWhere); without it every confirmed subscriber
        $atLeast = (int) $db->value('SELECT COUNT(*) FROM {odberatele} o WHERE ' . $sql, $params) - array_sum(SendRegister::counts($db));
        if ($atLeast >= self::TAG_MIN) {
            return $atLeast;
        }

        return SendRegister::reachable($db, array_map('strval', array_column($db->all('SELECT o.email FROM {odberatele} o WHERE ' . $sql, $params), 'email')));
    }

    /** 3.54: reachableForCampaign() for this newsletter's audience – three customers on a list of 500 get no campaign name. */
    private static function tagRecipients(App $app, array $n): int
    {
        return self::reachableForCampaign($app->db(), self::recipientsWhere($app, $n));
    }

    /**
     * Sends the newsletter now (null) or at the given time (YYYY-MM-DD HH:MM). A scheduled one starts on the first cron
     * call after that time; the latest news items are taken at that moment.
     *
     * 3.54 (A2): the audience must be allowed (audienceProblem) and not empty, also when scheduling. $expected is the number
     * of recipients the person or Claude confirmed: for "now" a different number starts nothing (RecipientCountChanged, the
     * newsletter stays a draft without queue rows) – checked before the render and again against the rows the queue got,
     * in one transaction. null (cron, a caller without a number) skips only that comparison. A scheduled newsletter is
     * counted again at its start (processQueue).
     */
    public static function send(App $app, int $id, ?string $at = null, ?int $expected = null): void
    {
        $db = $app->db();
        $n = self::byId($db, $id) ?? throw new \InvalidArgumentException('The newsletter does not exist.');
        if (!in_array($n['status'], ['draft', 'scheduled'], true)) {
            throw new \DomainException('The newsletter has already been sent.');
        }
        $problem = self::problem($app);
        if ($problem !== null) {
            throw new \DomainException($problem);
        }
        $refused = self::audienceProblem($app, $n);
        if ($refused !== null) {
            throw new \DomainException($refused);
        }
        $count = self::recipientCount($app, $n);
        if ($count === 0) {
            throw new \DomainException(self::emptyAudience($app, $n));
        }
        if (self::newsItems($app, $n) === [] && $n['news_mode'] !== 'none') {
            throw new \DomainException('The newsletter has no news items to send – publish a news item or choose No news.');
        }
        if ($at === null || $at === '') {
            if ($expected !== null && $count !== $expected) {
                throw new RecipientCountChanged($expected, $count); // before the render and before anything is written
            }
            self::start($app, $n, $expected);

            return;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', substr($at, 0, 16)));
        if ($time === false || $time->getTimestamp() <= time()) {
            throw new \InvalidArgumentException('The time must be in the future, as YYYY-MM-DD HH:MM.');
        }
        $db->update('newsletters', ['status' => 'scheduled', 'scheduled_at' => $time->format('Y-m-d H:i:s'), 'changed' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** A scheduled newsletter goes back to the drafts. */
    public static function unschedule(App $app, int $id): void
    {
        $app->db()->run("UPDATE {newsletters} SET status = 'draft', scheduled_at = NULL WHERE id = ? AND status = 'scheduled'", [$id]);
    }

    /**
     * 3.51: stops a send at once (manual: a person or Claude; brake: Mailing itself). Only a newsletter being sent – nothing
     * leaves after this, also not from a cron call that already picked its rows (claim()).
     */
    public static function pause(App $app, int $id, string $reason = 'manual'): void
    {
        $db = $app->db();
        $n = self::byId($db, $id) ?? throw new \InvalidArgumentException('The newsletter does not exist.');
        // 3.51 review: kept out of a Claude session's journal – undoing the session must not put the send back to "sending";
        // a paused send goes on only by resume() in the administration, with its checks
        $journal = $db->journal;
        $db->journal = null;
        try {
            $paused = self::markPaused($db, $id, $reason === 'brake' ? 'brake' : 'manual');
        } finally {
            $db->journal = $journal;
        }
        if (!$paused) {
            throw new \DomainException($n['status'] === 'paused' ? 'The newsletter is already paused.' : 'Only a newsletter that is being sent can be paused.');
        }
        $journal?->untracked('newsletters', 'a paused send is resumed only in the administration');
        ChangeLog::write($app, 'newsletters', 'pause', mb_substr((string) $n['subject'], 0, 200));
    }

    /** 3.51: a paused send goes on with the e-mails not sent yet (the brake counts again from here). */
    public static function resume(App $app, int $id): void
    {
        $db = $app->db();
        $n = self::byId($db, $id) ?? throw new \InvalidArgumentException('The newsletter does not exist.');
        if ($n['status'] !== 'paused') {
            throw new \DomainException('Only a paused newsletter can be resumed.');
        }
        $problem = self::problem($app);
        if ($problem !== null) {
            throw new \DomainException($problem);
        }
        if ($db->run("UPDATE {newsletters} SET status = 'sending', resumed_at = ?, paused_at = NULL, pause_reason = '' WHERE id = ? AND status = 'paused'", [Clock::now(), $id])->rowCount() === 0) {
            return;
        }
        // the brake's window starts at the resume: attempts before it no longer count
        $db->run('UPDATE {newsletter_queue} SET tried_at = NULL WHERE newsletter_id = ?', [$id]);
        ChangeLog::write($app, 'newsletters', 'resume', mb_substr((string) $n['subject'], 0, 200));
    }

    /**
     * 3.51: ends a send that is under way or paused – the e-mails not sent yet never go. The queue rows stay for a day (the
     * hourly limit counts what they sent), the rest is counted as skipped.
     */
    public static function cancel(App $app, int $id, string $why = ''): void
    {
        $db = $app->db();
        $n = self::byId($db, $id) ?? throw new \InvalidArgumentException('The newsletter does not exist.');
        if (!in_array($n['status'], ['sending', 'paused'], true) || !self::finish($db, $id, "status IN ('sending', 'paused')", 'cancelled')) {
            throw new \DomainException('Only a newsletter that is being sent or is paused can be cancelled.');
        }
        ChangeLog::write($app, 'newsletters', 'cancel', mb_substr((string) $n['subject'], 0, 200), $why);
    }

    /** A paused newsletter is cancelled automatically on this day (Y-m-d H:i:s), or null. */
    public static function cancelOn(array $n): ?string
    {
        return ($n['status'] ?? '') === 'paused' && !empty($n['paused_at'])
            ? date('Y-m-d H:i:s', (int) strtotime((string) $n['paused_at']) + self::PAUSED_DAYS * 86400) : null;
    }

    /**
     * Freezes the content and puts the audience (recipientsWhere) in the queue; the first batch goes out on the next cron
     * call. 3.54 (A2): the flip to "sending", the queue rows and the number of recipients are one transaction – when the
     * rows differ from $expected (RecipientCountChanged) or nobody is left (DomainException), it is rolled back and the
     * newsletter stays as it was, without queue rows. The effects outside the database follow the commit.
     */
    private static function start(App $app, array $n, ?int $expected = null): void
    {
        $db = $app->db();
        $n['tag_recipients'] = self::tagRecipients($app, $n); // 3.51 (B7): the campaign name depends on the number, taken before the render
        [$html, $text] = self::render($app, $n);
        [$where, $params] = self::recipientsWhere($app, $n);
        $started = $db->transaction(function (Db $db) use ($app, $n, $html, $text, $where, $params, $expected): bool {
            // only one caller starts it (cron and an admin click may meet)
            if ($db->run("UPDATE {newsletters} SET status = 'sending', started_at = ?, html = ?, text = ? WHERE id = ? AND status IN ('draft', 'scheduled')", [Clock::now(), $html, $text, $n['id']])->rowCount() === 0) {
                return false;
            }
            $count = $db->run('INSERT IGNORE INTO {newsletter_queue} (newsletter_id, subscriber_id, next_attempt) SELECT ?, o.ido, ? FROM {odberatele} o WHERE ' . $where, [$n['id'], Clock::now(), ...$params])->rowCount();
            if ($expected !== null && $count !== $expected) {
                throw new RecipientCountChanged($expected, $count);
            }
            if ($count === 0) {
                throw new \DomainException(self::emptyAudience($app, $n));
            }
            $db->update('newsletters', ['recipients' => $count], ['id' => $n['id']]);

            return true;
        });
        if (!$started) {
            return;
        }
        MediaStaging::promoteReferenced($db, $html); // 3.15: an image waiting for approval goes out with the e-mail, so it goes public
        ChangeLog::write($app, 'newsletters', 'send', mb_substr((string) $n['subject'], 0, 200));
    }

    /**
     * Cron (/tasks, /ulohy): starts scheduled newsletters that are due and sends the next batch within the hourly limit.
     * Returns the number of e-mails sent.
     */
    public static function processQueue(App $app): int
    {
        $db = $app->db();
        $s = $app->settings();
        self::housekeeping($app); // 3.51 (B4): also while SMTP is switched off
        if ($s->get('mail_mode') !== 'smtp' || $s->get('smtp_host') === '') {
            return 0; // waits until SMTP is set up again
        }
        foreach ($db->all("SELECT * FROM {newsletters} WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at", [Clock::now()]) as $n) {
            if (SenderIdentity::line($s, (string) $n['language']) === null) {
                self::backToDraft($app, $n, 'sender'); // 3.51 (B5): it cannot go out without its sender
                continue;
            }
            // 3.54 (A2): the audience is counted again at the start – refused or empty, it goes back to the drafts, never "sent" to nobody
            if (self::audienceProblem($app, $n) !== null) {
                self::backToDraft($app, $n, 'audience');
                continue;
            }
            if (self::recipientCount($app, $n) === 0) {
                self::backToDraft($app, $n, 'empty');
                continue;
            }
            try {
                self::start($app, $n);
            } catch (\DomainException) {
                self::backToDraft($app, $n, 'empty'); // the last subscriber left between the count and the queue: rolled back
            }
        }
        $allowed = min(self::BATCH, max(10, $s->int('newsletter_hourly_limit')) - (int) $db->value('SELECT COUNT(*) FROM {newsletter_queue} WHERE sent_at >= ?', [Clock::at('-1 hour')]));
        $sent = 0;
        $newsletters = [];
        $stopped = []; // newsletters the brake paused in this call: their remaining rows wait
        // in the order of the list as in 3.50 – untried rows and due retries (a greylisted address keeps its RETRY_MINUTES,
        // also after a resume, an update from 3.50 or a cut-off attempt); the rows the brake put back (reply PUT_BACK) only
        // when nothing else is due, so five dead addresses at the head of a list cannot pause it after every resume (3.51
        // review). Two reads in the order of the primary key instead of one sort over the whole queue.
        $select = "SELECT q.id, q.newsletter_id, q.attempts, o.email, o.token, o.stav FROM {newsletter_queue} q
            JOIN {newsletters} n ON n.id = q.newsletter_id AND n.status = 'sending' LEFT JOIN {odberatele} o ON o.ido = q.subscriber_id
            WHERE q.next_attempt <= ? AND ";
        $rows = $allowed <= 0 ? [] : $db->all($select . 'q.reply <> ? ORDER BY q.id LIMIT ' . (int) $allowed, [Clock::now(), self::PUT_BACK]);
        if ($allowed > count($rows)) {
            $rows = array_merge($rows, $db->all($select . 'q.reply = ? ORDER BY q.id LIMIT ' . ($allowed - count($rows)), [Clock::now(), self::PUT_BACK]));
        }
        foreach ($rows as $q) {
            $newsletterId = (int) $q['newsletter_id'];
            // claim the row first: a concurrent call does not send the same e-mail twice, and nothing goes after a pause or a cancel
            if (isset($stopped[$newsletterId]) || !self::claim($db, (int) $q['id'])) {
                continue;
            }
            if ($q['email'] === null || (int) $q['stav'] !== 1) {
                $db->update('newsletter_queue', ['next_attempt' => null, 'error' => 'unsubscribed'], ['id' => $q['id']]); // unsubscribed in the meantime
                continue;
            }
            // 3.51 (B3): an address that bounced or said no is skipped – no attempt, so it never feeds the brake
            $blocked = SendRegister::blocked($db, (string) $q['email']);
            if ($blocked !== null) {
                $db->update('newsletter_queue', ['next_attempt' => null, 'error' => $blocked], ['id' => $q['id']]);
                continue;
            }
            $n = $newsletters[$newsletterId] ??= (array) $db->one('SELECT subject, html, text FROM {newsletters} WHERE id = ?', [$newsletterId]);
            $unsubscribe = Subscription::unsubscribeLink($app, (string) $q['token'], $newsletterId); // 3.54: the link counts its unsubscribe for this newsletter
            $ok = Mail::deliverNow($s, (string) $q['email'], (string) $n['subject'],
                str_replace(self::UNSUBSCRIBE, $unsubscribe, (string) $n['text']), str_replace(self::UNSUBSCRIBE, e($unsubscribe), (string) $n['html']),
                ['List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
            $reply = self::reply(Mail::lastResult());
            $tried = ['tried_at' => Clock::now(), 'reply' => $reply];
            $attempt = (int) $q['attempts'] + 1;
            if ($ok) {
                $db->update('newsletter_queue', $tried + ['sent_at' => date('Y-m-d H:i:s'), 'next_attempt' => null, 'error' => ''], ['id' => $q['id']]);
                $db->run('UPDATE {newsletters} SET sent_count = sent_count + 1, ' . self::LATE . ' WHERE id = ?', [$newsletterId]);
                $sent++;
                continue;
            }
            if (!self::isAddressRefusal($reply) && isset(self::RETRY_MINUTES[$attempt - 1])) {
                $db->update('newsletter_queue', $tried + ['next_attempt' => date('Y-m-d H:i:s', time() + self::RETRY_MINUTES[$attempt - 1] * 60), 'error' => mb_substr(Mail::$error, 0, 255)], ['id' => $q['id']]);
            } else {
                // given up – at once for an address the server refused as unknown (3.51), after the last retry otherwise
                $db->update('newsletter_queue', $tried + ['next_attempt' => null, 'error' => mb_substr(Mail::$error, 0, 255)], ['id' => $q['id']]);
                $db->run('UPDATE {newsletters} SET failed_count = failed_count + 1, ' . self::LATE . ' WHERE id = ?', [$newsletterId]);
            }
            // 3.51 (D5): a failure at the server may trip the brake; one decided before any server (the demo, a bad address) never does
            if (!str_starts_with($reply, 'local:') && self::brake($app, $newsletterId)) {
                $stopped[$newsletterId] = true;
            }
        }
        // finished: nothing left to try (the addresses a server refused as unknown go to the register first)
        foreach ($db->all("SELECT n.id FROM {newsletters} n WHERE n.status = 'sending'
            AND NOT EXISTS (SELECT 1 FROM {newsletter_queue} q WHERE q.newsletter_id = n.id AND q.next_attempt IS NOT NULL)") as $done) {
            self::finish($db, (int) $done['id'], "status = 'sending' AND NOT EXISTS (SELECT 1 FROM {newsletter_queue} q WHERE q.newsletter_id = {newsletters}.id AND q.next_attempt IS NOT NULL)", 'sent');
        }

        return $sent;
    }

    /**
     * 3.51 (B1): takes one queue row for sending – only while its newsletter is still being sent (a pause, the brake in a
     * parallel cron call or a cancel between the select and this claim leaves the row alone). false = not ours.
     */
    public static function claim(Db $db, int $queueId): bool
    {
        return $db->run("UPDATE {newsletter_queue} SET next_attempt = ?, attempts = attempts + 1 WHERE id = ? AND next_attempt <= ?
            AND newsletter_id IN (SELECT id FROM {newsletters} WHERE status = 'sending')", [Clock::at('+10 minutes'), $queueId, Clock::now()])->rowCount() > 0;
    }

    /** The queue's record of one attempt: "<stage>:<code>:<enhanced>" (rcpt:550:5.1.1, connect:0:, body:250:2.0.0). */
    public static function reply(array $result): string
    {
        return substr((string) ($result['stage'] ?? '') . ':' . ((int) ($result['code'] ?? 0) ?: '') . ':' . (string) ($result['enhanced'] ?? ''), 0, 32);
    }

    /**
     * 3.51 review: an attempt that ends after the newsletter ended (cancelled while this cron call was delivering the row)
     * was counted as skipped by finish() – it moves to sent or failed, so recipients = sent + failed + skipped still holds.
     */
    private const string LATE = "skipped_count = CASE WHEN status IN ('sent', 'cancelled') AND skipped_count > 0 THEN skipped_count - 1 ELSE skipped_count END";

    /** 3.51 (D4): did the server refuse the recipient's address itself (at RCPT, a permanent 5xx with one of ADDRESS_REFUSALS)? */
    public static function isAddressRefusal(string $reply): bool
    {
        $parts = explode(':', $reply);

        return count($parts) === 3 && $parts[0] === 'rcpt' && str_starts_with($parts[1], '5') && in_array($parts[2], self::ADDRESS_REFUSALS, true);
    }

    /**
     * 3.51 (D5): the brake – the window is the last BRAKE_WINDOW attempts since the start or the last resume (rows with
     * sent_at and reply). It trips when BRAKE_FAILURES of them failed at the mail server: a delivered row never counts, a
     * failure decided before any server (stage local) never counts, and an address the server refused as unknown does not
     * count while the window also delivered something – a stale list does not stop a healthy send.
     *
     * @param list<array{sent_at: ?string, reply: string}> $window
     */
    public static function brakeTripped(array $window): bool
    {
        $window = array_slice($window, 0, self::BRAKE_WINDOW);
        $delivered = array_filter($window, fn (array $r): bool => $r['sent_at'] !== null) !== [];
        $failed = array_filter($window, fn (array $r): bool => $r['sent_at'] === null && $r['reply'] !== '' && $r['reply'] !== self::PUT_BACK && !str_starts_with($r['reply'], 'local:')
            && !($delivered && self::isAddressRefusal($r['reply'])));

        return count($failed) >= self::BRAKE_FAILURES;
    }

    /**
     * Checks the brake for a newsletter after a failure and, when it trips, pauses the send and tells its author. When the
     * window delivered nothing (the server refuses the site, not the addresses), its failed rows go back to the queue without
     * their answer – they go out after the resume and none of them can reach the register. They keep their attempts, and a
     * row that used its last one is not put back, so an address that fails after every resume is given up in the end and
     * no resume repeats the same pause for ever (3.51 review). Their reply is PUT_BACK; the send takes such a row only when no other row is due,
     * so after a resume the untried addresses go before the put-back ones – five dead addresses at the head of a list cannot
     * pause it again and again. true = the send was paused now.
     */
    private static function brake(App $app, int $id): bool
    {
        $db = $app->db();
        $window = $db->all('SELECT id, attempts, sent_at, reply, next_attempt FROM {newsletter_queue} WHERE newsletter_id = ? AND tried_at IS NOT NULL ORDER BY tried_at DESC, id DESC LIMIT ' . self::BRAKE_WINDOW, [$id]);
        $rows = array_map(fn (array $r): array => ['sent_at' => $r['sent_at'] !== null ? (string) $r['sent_at'] : null, 'reply' => (string) $r['reply']], $window);
        if (!self::brakeTripped($rows) || !self::markPaused($db, $id, 'brake')) {
            return false;
        }
        if (array_filter($window, fn (array $r): bool => $r['sent_at'] !== null) === []) {
            // only rows with a try left: one that used every attempt stays given up, so each resume moves the send on
            $failed = array_values(array_filter($window, fn (array $r): bool => $r['sent_at'] === null && (int) $r['attempts'] <= count(self::RETRY_MINUTES)));
            $given = count(array_filter($failed, fn (array $r): bool => $r['next_attempt'] === null)); // already counted as failed
            $ids = implode(',', array_map(fn (array $r): int => (int) $r['id'], $failed));
            if ($ids !== '') {
                $db->run("UPDATE {newsletter_queue} SET reply = ?, error = '', tried_at = NULL, next_attempt = ? WHERE id IN (" . $ids . ')', [self::PUT_BACK, Clock::now()]);
            }
            if ($given > 0) {
                $db->run('UPDATE {newsletters} SET failed_count = CASE WHEN failed_count > ? THEN failed_count - ? ELSE 0 END WHERE id = ?', [$given, $given, $id]);
            }
        }
        $n = (array) self::byId($db, $id);
        ChangeLog::write($app, 'newsletters', 'pause', mb_substr((string) ($n['subject'] ?? ''), 0, 200), 'the brake: ' . self::BRAKE_FAILURES . ' of the last attempts failed at the mail server');
        self::tellAuthor($app, $n, 'brake');

        return true;
    }

    /** status sending → paused (one caller wins). */
    private static function markPaused(Db $db, int $id, string $reason): bool
    {
        return $db->run("UPDATE {newsletters} SET status = 'paused', paused_at = ?, pause_reason = ? WHERE id = ? AND status = 'sending'", [Clock::now(), $reason, $id])->rowCount() > 0;
    }

    /**
     * Ends a newsletter (sent or cancelled) when $where still holds: the e-mails not sent yet stop, skipped_count is the
     * remainder (recipients = sent + failed + skipped), and – when it delivered at least one e-mail (D4) – the addresses a
     * server refused as unknown go to the register. The addresses are selected before the flip; a subscriber gone in the
     * meantime is skipped. false = another caller ended it, or $where no longer holds.
     */
    private static function finish(Db $db, int $id, string $where, string $status): bool
    {
        $refused = array_values(array_filter($db->all('SELECT q.reply, o.email FROM {newsletter_queue} q JOIN {odberatele} o ON o.ido = q.subscriber_id
            WHERE q.newsletter_id = ? AND q.sent_at IS NULL AND q.reply LIKE ?', [$id, 'rcpt:5%']), fn (array $r): bool => self::isAddressRefusal((string) $r['reply'])));
        if ($db->run('UPDATE {newsletters} SET status = ?, finished_at = ?,
                skipped_count = CASE WHEN recipients > sent_count + failed_count THEN recipients - sent_count - failed_count ELSE 0 END
                WHERE id = ? AND ' . $where, [$status, Clock::now(), $id])->rowCount() === 0) {
            return false;
        }
        $db->run("UPDATE {newsletter_queue} SET next_attempt = NULL, error = ? WHERE newsletter_id = ? AND next_attempt IS NOT NULL", ['cancelled', $id]);
        if ((int) $db->value('SELECT sent_count FROM {newsletters} WHERE id = ?', [$id]) >= 1) {
            foreach ($refused as $r) {
                SendRegister::record($db, 'bounced', (string) $r['email']);
            }
        }

        return true;
    }

    /**
     * 3.51 (B4): runs on every cron call, also while SMTP is switched off – a send paused for PAUSED_DAYS is cancelled (its
     * author is told), and the queue of a newsletter that ended RECIPIENT_DAYS ago is deleted (recipients are kept only
     * while sending, and a day for the hourly limit).
     */
    private static function housekeeping(App $app): void
    {
        $db = $app->db();
        foreach ($db->all("SELECT id FROM {newsletters} WHERE status = 'paused' AND paused_at < ?", [Clock::at('-' . self::PAUSED_DAYS . ' days')]) as $row) {
            $id = (int) $row['id'];
            if (self::finish($db, $id, "status = 'paused'", 'cancelled')) {
                $n = (array) self::byId($db, $id);
                ChangeLog::write($app, 'newsletters', 'cancel', mb_substr((string) ($n['subject'] ?? ''), 0, 200), 'paused for ' . self::PAUSED_DAYS . ' days');
                self::tellAuthor($app, $n, 'cancelled');
            }
        }
        $db->run("DELETE q FROM {newsletter_queue} q JOIN {newsletters} n ON n.id = q.newsletter_id WHERE n.status IN ('sent', 'cancelled') AND n.finished_at < ?", [Clock::at('-' . self::RECIPIENT_DAYS . ' day')]);
        // the rows a deleted newsletter left for the hourly limit (delete())
        $db->run('DELETE q FROM {newsletter_queue} q LEFT JOIN {newsletters} n ON n.id = q.newsletter_id WHERE n.id IS NULL AND (q.sent_at IS NULL OR q.sent_at < ?)', [Clock::at('-1 hour')]);
    }

    /** 3.54 (A2): why a due scheduled newsletter went back to the drafts – reason => the change log's note. */
    public const array DRAFT_REASONS = [
        'sender' => 'not sent: the sender\'s address is missing',
        'audience' => 'not sent: its audience cannot be sent to',
        'empty' => 'not sent: nobody is in its audience',
    ];

    /**
     * 3.51 (B5): a due scheduled newsletter that cannot go out goes back to the drafts; the change log and its author say
     * why (3.54: $reason of DRAFT_REASONS – the sender is missing, the audience is refused, nobody is in the audience).
     */
    private static function backToDraft(App $app, array $n, string $reason): void
    {
        if ($app->db()->run("UPDATE {newsletters} SET status = 'draft', scheduled_at = NULL WHERE id = ? AND status = 'scheduled'", [$n['id']])->rowCount() === 0) {
            return;
        }
        ChangeLog::write($app, 'newsletters', 'unschedule', mb_substr((string) $n['subject'], 0, 200), self::DRAFT_REASONS[$reason] ?? self::DRAFT_REASONS['sender']);
        self::tellAuthor($app, $n, 'draft', $reason);
    }

    /**
     * 3.51: one e-mail to the newsletter's author (the site e-mail when the author is gone) – never to the list – when the
     * brake paused it, when it went back to the drafts ($reason: no sender, a refused or an empty audience) or when a paused
     * one was cancelled automatically.
     *
     * @param array<string, mixed> $n
     */
    private static function tellAuthor(App $app, array $n, string $what, string $reason = 'sender'): void
    {
        $s = $app->settings();
        $user = isset($n['author']) ? $app->db()->one('SELECT email, jazyk, register FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [(int) $n['author']]) : null;
        $to = trim((string) ($user['email'] ?? '')) !== '' ? (string) $user['email'] : $s->get('site_email');
        if ($to === '') {
            return;
        }
        $link = self::origin($app) . $app->url('admin.php?module=newsletters&action=edit&id=' . (int) $n['id']);
        $subject = (string) ($n['subject'] ?? '');
        [$title, $body] = Language::runWith((string) ($user['jazyk'] ?? '') ?: Language::defaults($s), fn (): array => match ($what) {
            'brake' => [t('Newsletter paused: %s', $subject), t('Sending of the newsletter “%s” was paused by itself: the mail server refused %d of the last attempts. Nothing more goes out until someone resumes it.', $subject, self::BRAKE_FAILURES)
                . "\n\n" . t('Sent so far: %d of %d.', (int) ($n['sent_count'] ?? 0), (int) ($n['recipients'] ?? 0))
                . "\n" . t('Check Settings → Mail and System status, then resume or cancel it. If nobody does, it is cancelled on %s.', format_date((string) self::cancelOn($n), true))],
            'draft' => [t('Newsletter not sent: %s', $subject), match ($reason) {
                'audience' => t('The newsletter “%s” was scheduled but did not go out: its audience cannot be sent to (an unknown audience, or only customers while the sales pipeline and quotes are switched off). It is a draft again – choose who it goes to and send it again.', $subject),
                'empty' => t('The newsletter “%s” was scheduled but did not go out: nobody was in its audience at the time of sending. It is a draft again – check who it goes to and send it again.', $subject),
                default => t('The newsletter “%s” was scheduled but did not go out: the company name or the postal address of the sender is missing (Settings → Business details). It is a draft again – fill them in and send it again.', $subject),
            }],
            default => [t('Newsletter cancelled: %s', $subject), t('The newsletter “%s” stayed paused for %d days and was cancelled; the e-mails not sent yet will not go.', $subject, self::PAUSED_DAYS)
                . "\n\n" . t('Sent: %d of %d.', (int) ($n['sent_count'] ?? 0), (int) ($n['recipients'] ?? 0))],
        }, 'admin-', (string) ($user['register'] ?? ''));
        Mail::send($s, $to, $title, $body . "\n\n" . $link);
    }

    /** A test e-mail to one address, now; the unsubscribe link in it leads nowhere. */
    public static function sendTest(App $app, array $n, string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The test address is not a valid e-mail.');
        }
        [$html, $text] = self::render($app, $n);
        $placeholder = self::absolute($app, 'odber');

        return Mail::send($app->settings(), $email, '[' . t('Test') . '] ' . $n['subject'], str_replace(self::UNSUBSCRIBE, $placeholder, $text),
            str_replace(self::UNSUBSCRIBE, e($placeholder), $html), [], false);
    }

    /**
     * News items of the newsletter in its language: the latest published ones, or the chosen ones in the chosen order.
     *
     * @return list<array{id: int, title: string, url: string, intro: string, image: string, date: string}>
     */
    public static function newsItems(App $app, array $n): array
    {
        if ($n['news_mode'] === 'none' || !Extensions::isEnabled($app->settings(), 'novinky')) {
            return [];
        }
        $db = $app->db();
        $n['tag_recipients'] ??= self::tagRecipients($app, $n); // 3.51 (B7): the campaign name of the links depends on it
        $published = 'visible = 1 AND datum <= ? AND smazano IS NULL'; // the first parameter of both queries: Clock::now()
        if ($n['news_mode'] === 'chosen') {
            $ids = array_map('intval', array_filter(explode(',', (string) $n['news_ids'])));
            if ($ids === []) {
                return [];
            }
            $rows = $db->all('SELECT idc, seo_link, titulek, uvod, obrazek, datum, jazyk FROM {novinky} WHERE ' . $published . ' AND idc IN (' . implode(',', $ids) . ')', [Clock::now()]);
            usort($rows, fn (array $a, array $b): int => array_search((int) $a['idc'], $ids, true) <=> array_search((int) $b['idc'], $ids, true));
        } else {
            $rows = $db->all('SELECT idc, seo_link, titulek, uvod, obrazek, datum, jazyk FROM {novinky} WHERE ' . $published . ' AND jazyk = ? ORDER BY datum DESC, idc DESC LIMIT ' . max(1, min(self::MAX_NEWS, (int) $n['news_count'])),
                [Clock::now(), (string) $n['language']]);
        }

        return array_map(fn (array $c): array => [
            'id' => (int) $c['idc'], 'title' => (string) $c['titulek'], 'date' => (string) $c['datum'],
            'url' => self::campaign($app, self::origin($app) . $app->newsItemUrl((string) $c['seo_link'], (string) $c['jazyk']), $n),
            'intro' => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], ' ', (string) $c['uvod'])), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''), 0, 320, '…'),
            'image' => self::image($app, (string) $c['obrazek']),
        ], $rows);
    }

    /**
     * The e-mail: HTML (tables, inline styles from the design system) and plain text, both with the unsubscribe placeholder.
     *
     * @return array{0: string, 1: string}
     */
    public static function render(App $app, array $n): array
    {
        $s = $app->settings();
        $language = (string) $n['language'] !== '' ? (string) $n['language'] : Language::defaults($s);
        $n['tag_recipients'] ??= self::tagRecipients($app, $n); // 3.51 (B7): the same number for the preview and the send

        return Language::runWith($language, function () use ($app, $s, $n, $language): array {
            $ds = DesignSystem::load($s);
            $items = self::newsItems($app, $n);
            $siteName = $s->get('site_name_' . $language) !== '' ? $s->get('site_name_' . $language) : $s->get('site_name');
            $button = $n['button_url'] !== '' && $n['button_label'] !== ''
                ? [(string) $n['button_label'], self::campaign($app, str_starts_with((string) $n['button_url'], '/') ? self::origin($app) . $app->request->basePath() . $n['button_url'] : (string) $n['button_url'], $n)] : null;
            ['identity' => $identity, 'reason' => $reason, 'privacy' => $privacy] = self::footer($s, $language, $siteName, self::origin($app));
            $logo = preg_match('/\.(png|jpe?g|gif|webp)$/i', $s->get('logo')) ? self::image($app, $s->get('logo'), false) : '';
            $paragraphs = self::paragraphs((string) $n['intro'], $reason);
            $data = [
                'language' => $language, 'subject' => (string) $n['subject'], 'preheader' => (string) $n['preheader'], 'paragraphs' => $paragraphs,
                'items' => $items, 'button' => $button, 'siteName' => $siteName, 'siteUrl' => self::absolute($app, ''), 'logo' => $logo,
                'identity' => $identity, 'reason' => $reason, 'privacy' => $privacy,
                'colors' => $ds['barvy'] + ['tlumeny' => '#5b6170', 'na-primarni' => DesignSystem::contrastColor($ds['barvy']['primarni'])],
                'headingFont' => DesignSystem::fontFamily($ds, (string) $ds['pismo_titulky'], true) ?: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                'textFont' => DesignSystem::fontFamily($ds, (string) $ds['pismo_text'], false) ?: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                'radius' => DesignSystem::RADII[$ds['zaobleni']] === '999px' ? '999px' : (string) (int) round((float) DesignSystem::RADII[$ds['zaobleni']] * 16) . 'px',
                'unsubscribe' => self::UNSUBSCRIBE,
            ];
            $html = $app->view->render('email/newsletter', $data);

            $text = $n['subject'] . "\n\n" . implode("\n\n", $paragraphs) . ($paragraphs !== [] ? "\n\n" : '');
            foreach ($items as $item) {
                $text .= '• ' . $item['title'] . "\n" . ($item['intro'] !== '' ? $item['intro'] . "\n" : '') . $item['url'] . "\n\n";
            }
            if ($button !== null) {
                $text .= $button[0] . ': ' . $button[1] . "\n\n";
            }
            $text .= "--\n" . ($identity !== null ? $identity . "\n" : '') . $reason . "\n" . ($privacy !== '' ? t('Privacy policy') . ': ' . $privacy . "\n" : '')
                . t('Unsubscribe') . ': ' . self::UNSUBSCRIBE . "\n";

            return [$html, $text];
        });
    }

    /**
     * 3.51 (B5): the footer in one language – who sends it (mail_identity filled with the company name and the address; null
     * while either is missing, then the newsletter cannot be sent at all) and why it arrived (newsletter_reason with the
     * site's name). Empty fields give the default wording of that language (Settings::wording). 3.51: and the privacy policy
     * of that language version (cookies_policy_url, an absolute address; '' while the site has none – then no link).
     *
     * @return array{identity: ?string, reason: string, privacy: string}
     */
    public static function footer(Settings $s, string $language, string $siteName, string $origin = ''): array
    {
        $privacy = Privacy::policyUrl($s, $language);
        if (str_starts_with($privacy, '/')) {
            $privacy = $origin !== '' ? $origin . $privacy : '';
        }

        return ['identity' => SenderIdentity::line($s, $language), 'reason' => strtr($s->wording('newsletter_reason', $language), ['{{site}}' => $siteName]), 'privacy' => $privacy];
    }

    /**
     * The paragraphs of the introduction (an empty line starts a new one); {{reason}} in it is the footer's reason sentence.
     *
     * @return list<string>
     */
    public static function paragraphs(string $intro, string $reason): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace('{{reason}}', $reason, $intro)) ?: []), fn (string $p): bool => $p !== ''));
    }

    /** Absolute address of a path on the site (the e-mail is read outside the site). */
    public static function absolute(App $app, string $path): string
    {
        return self::origin($app) . $app->url($path);
    }

    /** Scheme and host of the site: the site address from Settings, not the Host header (cron may call from anywhere). */
    private static function origin(App $app): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
    }

    /** Links to the site carry utm parameters, so the site's cookie-free statistics show visits from the newsletter. */
    private static function campaign(App $app, string $url, array $n): string
    {
        return self::campaignUrl(self::origin($app), $url, (string) $n['subject'], (int) ($n['id'] ?? 0), (int) ($n['tag_recipients'] ?? self::tagRecipients($app, $n)));
    }

    /**
     * 3.51 (B7): a link to the site (under $origin, without utm already) gets utm_source and utm_medium; from TAG_MIN
     * recipients also utm_campaign = the subject's slug and the newsletter's number (-n<id>). Below it no campaign name at
     * all – a subject identifies a send to one to four people as well as a number would.
     */
    public static function campaignUrl(string $origin, string $url, string $subject, int $id, int $recipients): string
    {
        if (!str_starts_with($url, $origin . '/') || preg_match('/[?&]utm_/', $url)) {
            return $url;
        }
        [$url, $anchor] = array_pad(explode('#', $url, 2), 2, null);
        $campaign = $recipients >= self::TAG_MIN ? '&utm_campaign=' . rawurlencode(self::campaignName($subject, $id)) : '';

        return $url . (str_contains($url, '?') ? '&' : '?') . 'utm_source=newsletter&utm_medium=email' . $campaign . ($anchor !== null ? '#' . $anchor : '');
    }

    /** The campaign name of a newsletter's links (utm_campaign): the subject's slug and its number. */
    public static function campaignName(string $subject, int $id): string
    {
        return slugify($subject, 60) . ($id > 0 ? '-n' . $id : '');
    }

    /**
     * 3.54 (C): what the newsletter did – sent, failed, skipped, unsubscribed through its links and visits from it (null
     * when they cannot be known). Core\NewsletterResults.
     *
     * @param array<string, mixed> $n
     * @return array{sent: int, failed: int, skipped: int, unsubscribed: int, visits: ?int}
     */
    public static function results(App $app, array $n): array
    {
        return NewsletterResults::of($app, $n);
    }

    /** Absolute URL of an image from Media; the 1200 px variant when there is one (e-mail is 600 px wide, sharp on retina). */
    private static function image(App $app, string $path, bool $variant = true): string
    {
        if ($path === '') {
            return '';
        }
        if (preg_match('~^https?://~', $path)) {
            return $path;
        }
        $path = ltrim($path, '/');
        if ($variant && preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+?)\.(jpg|png)$#D', $path, $m) && Files::isFile(KALETA_ROOT . '/' . $m[1] . '-1200.' . $m[2])) {
            $path = $m[1] . '-1200.' . $m[2];
        }

        return self::origin($app) . $app->request->basePath() . '/' . $path;
    }
}
