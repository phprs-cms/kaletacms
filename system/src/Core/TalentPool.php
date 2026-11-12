<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The talent pool (3.40, design B8 and B15 "Release B2"): the applicants who agreed that the site keeps their application
 * for future openings (pool_consent_at … pool_until, Core\Applications). Here:
 *
 *  - list(): the applications kept for future openings, the soonest to lapse first (the Talent pool tab of Applications).
 *  - sendRenewals() (the daily clean-up, Notifications::purgePersonalData): RENEW_DAYS before pool_until the applicant gets
 *    one e-mail in the language of the application – keep it for another applications_pool_months, or delete it now. Both
 *    links carry one random token (only its sha256 is stored, pool_token); it works once (either choice clears it) and only
 *    until the consent lapses (pool_token_until). Once per consent: a renewed consent may be renewed again.
 *  - byToken(), renew(), remove(): what the page of the link (Front\ApplicationConsent) does – opening the link only shows
 *    the choice (mail scanners open links), a button posts it.
 *  - webhook(): the optional webhook application.received (setting webhook_applications, https only) – the number and the
 *    position only, never the applicant (B5, B-10).
 *
 * When the consent lapses without a renewal, the retention deletes the application (Applications::deletionDate – the
 * consent only ever keeps it longer). Nothing here names the applicant in a log, an event or the mail log.
 */
final class TalentPool
{
    /** Days before pool_until when the renewal e-mail goes. */
    public const int RENEW_DAYS = 30;

    /** Renewal e-mails per daily run. */
    public const int BATCH = 50;

    /** The path of the link's page (Front\Kernel), followed by the token. */
    public const string PATH = 'application-consent';

    /** The setting with the webhook address of new applications ('' = off). */
    public const string WEBHOOK_SETTING = 'webhook_applications';

    /**
     * The applications kept for future openings on $today, the soonest to lapse first: id, received_at, name, job_title,
     * status, pool_consent_at, pool_until, pool_renewal_sent_at; total = all of them.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function list(Db $db, string $today, int $page = 1, int $perPage = 50): array
    {
        $perPage = max(1, min(200, $perPage));
        try {
            $total = (int) $db->value('SELECT COUNT(*) FROM {applications} WHERE pool_until >= ?', [$today]);
            $rows = $db->all('SELECT id, received_at, name, email, job_title, status, pool_consent_at, pool_until, pool_renewal_sent_at FROM {applications} WHERE pool_until >= ?'
                . ' ORDER BY pool_until, id LIMIT ' . $perPage . ' OFFSET ' . (max(1, $page) - 1) * $perPage, [$today]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return ['rows' => [], 'total' => 0]; // before migration 0129
        }

        return ['rows' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'renewal_sent' => self::renewalSent($r)] + $r, $rows), 'total' => $total];
    }

    /** Has the renewal e-mail of the current consent gone (sent after the consent was given)? Pure. */
    public static function renewalSent(array $row): bool
    {
        $sent = (string) ($row['pool_renewal_sent_at'] ?? '');

        return $sent !== '' && $sent >= (string) ($row['pool_consent_at'] ?? '');
    }

    /**
     * The applications whose renewal e-mail is due on $today: the consent lapses within RENEW_DAYS (and has not lapsed),
     * no renewal went for this consent, and there is one address to write to. Pure.
     *
     * @param list<array<string, mixed>> $rows id, email, pool_consent_at, pool_until, pool_renewal_sent_at
     * @return list<int>
     */
    public static function due(array $rows, string $today): array
    {
        $limit = (new \DateTimeImmutable($today))->modify('+' . self::RENEW_DAYS . ' days')->format('Y-m-d');
        $out = [];
        foreach ($rows as $r) {
            $until = substr((string) ($r['pool_until'] ?? ''), 0, 10);
            if ($until !== '' && $until >= $today && $until <= $limit && ($r['pool_consent_at'] ?? null) !== null && !self::renewalSent($r) && count(Mail::addresses((string) ($r['email'] ?? ''))) === 1) {
                $out[] = (int) $r['id'];
            }
        }

        return $out;
    }

    /**
     * The renewal e-mails due today (the daily clean-up): only while the feature is on – a switched-off feature writes to
     * nobody. Each one gets a new token. Returns how many went (or were queued).
     */
    public static function sendRenewals(App $app): int
    {
        $s = $app->settings();
        if (!Applications::isOn($s)) {
            return 0;
        }
        $db = $app->db();
        $today = Clock::today();
        try {
            $rows = $db->all('SELECT id, name, email, job_title, language, pool_consent_at, pool_until, pool_renewal_sent_at FROM {applications} WHERE pool_until >= ? AND pool_until <= ?'
                . ' ORDER BY pool_until, id LIMIT ' . (self::BATCH * 4), [$today, (new \DateTimeImmutable($today))->modify('+' . self::RENEW_DAYS . ' days')->format('Y-m-d')]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 0; // before migration 0129
        }
        $byId = array_column($rows, null, 'id');
        $sent = 0;
        foreach (array_slice(self::due($rows, $today), 0, self::BATCH) as $id) {
            $row = $byId[$id];
            $token = bin2hex(random_bytes(32));
            $now = Clock::now();
            $db->update('applications', ['pool_token' => hash('sha256', $token), 'pool_token_until' => substr((string) $row['pool_until'], 0, 10) . ' 23:59:59', 'pool_renewal_sent_at' => $now], ['id' => $id]);
            $mail = self::compose($app, $row, $token);
            Mail::send($s, Mail::addresses((string) $row['email'])[0], $mail['subject'], $mail['text'], '', Mail::addresses($s->get('site_email')) !== [] ? ['Reply-To' => Mail::addresses($s->get('site_email'))[0]] : [], true,
                Language::runWith(Language::defaults($s), fn (): string => t('Renewal of the consent, application #%d: %s', $id, $s->get('site_name')), 'admin-')); // the log names nobody
            $sent++;
        }
        if ($sent > 0) {
            Events::record($db, 'applications.pool_renewal', 'info', sprintf('%d applicant(s) were asked whether their application may be kept for future openings.', $sent), ['count' => $sent]);
        }

        return $sent;
    }

    /**
     * The renewal e-mail of one application, in its language: the position, until when it is kept, a link to keep it for
     * another applications_pool_months and a link to delete it now. The name only when Applications::safeName() lets it
     * through (an automatic e-mail carries no text a visitor typed).
     *
     * @param array<string, mixed> $row name, job_title, language, pool_until
     * @return array{subject: string, text: string, keep: string, delete: string}
     */
    public static function compose(App $app, array $row, string $token): array
    {
        $s = $app->settings();
        $language = Applications::languageOf($s, $row);
        $link = self::link($app, $token, $language);
        $months = max(1, $s->int('applications_pool_months'));
        $company = $s->get('company_name') !== '' ? $s->get('company_name') : $s->get('site_name');

        return Language::runWith($language, function () use ($row, $link, $months, $company): array {
            $name = Applications::safeName((string) ($row['name'] ?? ''));
            $position = (string) ($row['job_title'] ?? '') !== '' ? (string) $row['job_title'] : t('a position with us');
            $until = format_date(substr((string) $row['pool_until'], 0, 10));
            $keep = $link . '?choice=keep';
            $delete = $link . '?choice=delete';
            $text = ($name !== '' ? t('Hello %s,', $name) : t('Hello,')) . "\n\n"
                . t('when you applied for %s, you agreed that we keep your application until %s and contact you about other openings.', $position, $until) . "\n\n"
                . t('May we keep it for another %d months?', $months) . "\n\n"
                . t('Yes, keep my application:') . "\n" . $keep . "\n\n"
                . t('No, delete my application now:') . "\n" . $delete . "\n\n"
                . t('If you do nothing, we delete your application after %s.', $until) . "\n\n"
                . t('Kind regards') . "\n" . $company;

            return ['subject' => t('May we keep your application? – %s', $company), 'text' => $text, 'keep' => $keep, 'delete' => $delete];
        });
    }

    /** The absolute address of the link's page in a language version of the site. */
    public static function link(App $app, string $token, string $language): string
    {
        $previous = $app->languagePrefix;
        $app->languagePrefix = in_array($language, Language::additional($app->settings()), true) ? $language : '';
        try {
            $path = $app->url(self::PATH . '/' . $token);
        } finally {
            $app->languagePrefix = $previous;
        }
        // the way every e-mail link of the site is made (Front\Subscription, Applications::notifyRecipients)
        return rtrim($app->settings()->get('site_url') !== '' ? $app->settings()->get('site_url') : $app->request->origin(), '/') . $path;
    }

    /**
     * The application of a link's token: id, name, job_title, language, pool_until – null for a wrong, used or expired
     * token (the page answers the same in every case).
     *
     * @return array<string, mixed>|null
     */
    public static function byToken(Db $db, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        try {
            return $db->one('SELECT id, name, job_title, language, pool_until FROM {applications} WHERE pool_token = ? AND pool_token_until >= ? AND pool_until IS NOT NULL', [hash('sha256', $token), Clock::now()]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // before migration 0129
        }
    }

    /**
     * The applicant keeps the application for future openings: the consent starts again today for applications_pool_months,
     * the token is used up. Returns the new pool_until, null when the token no longer works.
     */
    public static function renew(App $app, string $token): ?string
    {
        $db = $app->db();
        $row = self::byToken($db, $token);
        if ($row === null) {
            return null;
        }
        $until = Clock::day('+' . max(1, $app->settings()->int('applications_pool_months')) . ' months');
        $now = Clock::now();
        // compare-and-swap on the token: two clicks (or two tabs) renew once
        if ($db->run('UPDATE {applications} SET pool_consent_at = ?, pool_until = ?, pool_token = NULL, pool_token_until = NULL, last_activity_at = ? WHERE id = ? AND pool_token = ?',
            [$now, $until, $now, (int) $row['id'], hash('sha256', $token)])->rowCount() !== 1) {
            return null;
        }
        Applications::log($app, 'consent_renewed', '#' . (int) $row['id']);
        Events::record($db, 'application.pool_renewed', 'info', sprintf('Application #%d: the applicant renewed the consent for future openings.', (int) $row['id']), ['application' => (int) $row['id']]);

        return $until;
    }

    /**
     * The applicant wants the application deleted now: the token is used up, the consent goes and the application is
     * deleted with its CVs, notes and tasks (Applications::delete – one whose CV list cannot be opened and whose files
     * cannot be located stays for the administrator, without the consent, and the event says so). Returns false when the
     * token no longer works.
     */
    public static function remove(App $app, string $token): bool
    {
        $db = $app->db();
        $row = self::byToken($db, $token);
        if ($row === null) {
            return false;
        }
        $id = (int) $row['id'];
        if ($db->run('UPDATE {applications} SET pool_consent_at = NULL, pool_consent_text = NULL, pool_until = NULL, pool_token = NULL, pool_token_until = NULL WHERE id = ? AND pool_token = ?',
            [$id, hash('sha256', $token)])->rowCount() !== 1) {
            return false;
        }
        Applications::delete($app, [$id], false, $held);
        Applications::log($app, 'deleted_by_applicant', '#' . $id);
        Events::record($db, 'application.deleted_by_applicant', $held === [] ? 'info' : 'warning', $held === []
            ? sprintf('Application #%d was deleted at the applicant\'s request (the renewal e-mail).', $id)
            : sprintf('Application #%d: the applicant asked for deletion, but its CV list cannot be opened (encryption key) – delete it in Applications once the key is back.', $id), ['application' => $id]);

        return true;
    }

    /**
     * The optional webhook of a new application (webhook_applications, https only): the event, the site, the number and
     * the position – never a name, an address, a field or a file (B5).
     *
     * @param array{item: ?int, title: string} $position
     */
    public static function webhook(App $app, int $id, array $position): void
    {
        $url = $app->settings()->get(self::WEBHOOK_SETTING);
        if (preg_match('#^https://#i', $url) !== 1) {
            return;
        }
        Webhook::queue($app->settings(), 'application.received', $url, self::webhookBody($app->settings()->get('site_name'), $id, $position, Clock::now()));
    }

    /**
     * The body of the webhook application.received. Pure.
     *
     * @param array{item: ?int, title: string} $position
     * @return array<string, mixed>
     */
    public static function webhookBody(string $site, int $id, array $position, string $at): array
    {
        return ['event' => 'application.received', 'site' => $site, 'application' => $id, 'position' => $position['title'] !== '' ? $position['title'] : null,
            'position_id' => $position['item'], 'received_at' => (new \DateTimeImmutable($at))->format('c')];
    }
}
