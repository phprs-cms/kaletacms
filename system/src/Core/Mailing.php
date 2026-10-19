<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\DesignSystem;
use Kaleta\Front\Company;
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
 */
final class Mailing
{
    public const array STATUSES = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent'];

    public const array NEWS_MODES = ['latest' => 'The latest news', 'chosen' => 'Chosen news items', 'none' => 'No news'];

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

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $id): ?array
    {
        return $db->one('SELECT * FROM {newsletters} WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> newest first, without the rendered e-mail */
    public static function all(Db $db): array
    {
        return $db->all('SELECT id, subject, news_mode, news_count, language, status, scheduled_at, recipients, sent_count, failed_count, created, changed, started_at, finished_at
            FROM {newsletters} ORDER BY COALESCE(finished_at, started_at, scheduled_at, changed, created) DESC, id DESC');
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
            $db->update('newsletters', self::sanitize($app, $input, $n) + ['changed' => date('Y-m-d H:i:s')], ['id' => $id]);

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
        $app->db()->delete('newsletter_queue', ['newsletter_id' => $id]);
        $app->db()->delete('newsletters', ['id' => $id]);
    }

    /**
     * Why the site cannot send newsletters now, or null when it can: an SMTP server and a running cron are required.
     */
    public static function problem(App $app): ?string
    {
        $s = $app->settings();
        if ($s->get('mail_mode') !== 'smtp' || $s->get('smtp_host') === '') {
            return 'Newsletters are sent only through an SMTP server (Brevo, Amazon SES, Mailgun, your mailbox…) – set it up in Settings → Mail.';
        }
        if (time() - $s->int('tasks_last_run') > self::CRON_MINUTES * 60) {
            return 'Cron has not called the tasks address in the last 30 minutes – newsletters go out in batches only while it runs. Set it up with the address in System status.';
        }

        return null;
    }

    public static function confirmedCount(Db $db): int
    {
        return (int) $db->value('SELECT COUNT(*) FROM {odberatele} WHERE stav = 1');
    }

    /**
     * Sends the newsletter now (null) or at the given time (YYYY-MM-DD HH:MM). A scheduled one starts on the first cron
     * call after that time; the latest news items are taken at that moment.
     */
    public static function send(App $app, int $id, ?string $at = null): void
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
        if (self::confirmedCount($db) === 0) {
            throw new \DomainException('There are no confirmed subscribers yet.');
        }
        if (self::newsItems($app, $n) === [] && $n['news_mode'] !== 'none') {
            throw new \DomainException('The newsletter has no news items to send – publish a news item or choose No news.');
        }
        if ($at === null || $at === '') {
            self::start($app, $n);

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

    /** Freezes the content and puts every confirmed subscriber in the queue; the first batch goes out on the next cron call. */
    private static function start(App $app, array $n): void
    {
        $db = $app->db();
        [$html, $text] = self::render($app, $n);
        // only one caller starts it (cron and an admin click may meet)
        if ($db->run("UPDATE {newsletters} SET status = 'sending', started_at = ?, html = ?, text = ? WHERE id = ? AND status IN ('draft', 'scheduled')", [Clock::now(), $html, $text, $n['id']])->rowCount() === 0) {
            return;
        }
        $count = $db->run('INSERT IGNORE INTO {newsletter_queue} (newsletter_id, subscriber_id, next_attempt) SELECT ?, ido, ? FROM {odberatele} WHERE stav = 1', [$n['id'], Clock::now()])->rowCount();
        $db->update('newsletters', ['recipients' => $count], ['id' => $n['id']]);
        MediaStaging::promoteReferenced($db, $html); // 3.15: an image waiting for approval goes out with the e-mail, so it goes public
        \Kaleta\Admin\ChangeLog::write($app, 'newsletters', 'send', mb_substr((string) $n['subject'], 0, 200));
    }

    /**
     * Cron (/tasks, /ulohy): starts scheduled newsletters that are due and sends the next batch within the hourly limit.
     * Returns the number of e-mails sent.
     */
    public static function processQueue(App $app): int
    {
        $db = $app->db();
        $s = $app->settings();
        if ($s->get('mail_mode') !== 'smtp' || $s->get('smtp_host') === '') {
            return 0; // waits until SMTP is set up again
        }
        foreach ($db->all("SELECT * FROM {newsletters} WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at", [Clock::now()]) as $n) {
            self::start($app, $n);
        }
        $allowed = min(self::BATCH, max(10, $s->int('newsletter_hourly_limit')) - (int) $db->value('SELECT COUNT(*) FROM {newsletter_queue} WHERE sent_at >= ?', [Clock::at('-1 hour')]));
        $sent = 0;
        $newsletters = [];
        $rows = $allowed <= 0 ? [] : $db->all("SELECT q.id, q.newsletter_id, q.attempts, o.email, o.token, o.stav FROM {newsletter_queue} q
            JOIN {newsletters} n ON n.id = q.newsletter_id AND n.status = 'sending' LEFT JOIN {odberatele} o ON o.ido = q.subscriber_id
            WHERE q.next_attempt <= ? ORDER BY q.id LIMIT " . (int) $allowed, [Clock::now()]);
        foreach ($rows as $q) {
            // claim the row first: a concurrent call does not send the same e-mail twice
            if ($db->run('UPDATE {newsletter_queue} SET next_attempt = ?, attempts = attempts + 1 WHERE id = ? AND next_attempt <= ?', [Clock::at('+10 minutes'), $q['id'], Clock::now()])->rowCount() === 0) {
                continue;
            }
            if ($q['email'] === null || (int) $q['stav'] !== 1) {
                $db->update('newsletter_queue', ['next_attempt' => null, 'error' => 'unsubscribed'], ['id' => $q['id']]); // unsubscribed in the meantime
                continue;
            }
            $n = $newsletters[$q['newsletter_id']] ??= (array) $db->one('SELECT subject, html, text FROM {newsletters} WHERE id = ?', [$q['newsletter_id']]);
            $unsubscribe = Subscription::unsubscribeLink($app, (string) $q['token']);
            $ok = Mail::deliverNow($s, (string) $q['email'], (string) $n['subject'],
                str_replace(self::UNSUBSCRIBE, $unsubscribe, (string) $n['text']), str_replace(self::UNSUBSCRIBE, e($unsubscribe), (string) $n['html']),
                ['List-Unsubscribe' => '<' . $unsubscribe . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
            $attempt = (int) $q['attempts'] + 1;
            if ($ok) {
                $db->update('newsletter_queue', ['sent_at' => date('Y-m-d H:i:s'), 'next_attempt' => null, 'error' => ''], ['id' => $q['id']]);
                $db->run('UPDATE {newsletters} SET sent_count = sent_count + 1 WHERE id = ?', [$q['newsletter_id']]);
                $sent++;
            } elseif (isset(self::RETRY_MINUTES[$attempt - 1])) {
                $db->update('newsletter_queue', ['next_attempt' => date('Y-m-d H:i:s', time() + self::RETRY_MINUTES[$attempt - 1] * 60), 'error' => mb_substr(Mail::$error, 0, 255)], ['id' => $q['id']]);
            } else {
                $db->update('newsletter_queue', ['next_attempt' => null, 'error' => mb_substr(Mail::$error, 0, 255)], ['id' => $q['id']]);
                $db->run('UPDATE {newsletters} SET failed_count = failed_count + 1 WHERE id = ?', [$q['newsletter_id']]);
            }
        }
        // finished: nothing left to try
        $db->run("UPDATE {newsletters} n SET status = 'sent', finished_at = ? WHERE n.status = 'sending'
            AND NOT EXISTS (SELECT 1 FROM {newsletter_queue} q WHERE q.newsletter_id = n.id AND q.next_attempt IS NOT NULL)", [Clock::now()]);
        // recipients are kept only while sending (and a day for the hourly limit)
        $db->run("DELETE q FROM {newsletter_queue} q JOIN {newsletters} n ON n.id = q.newsletter_id WHERE n.status = 'sent' AND n.finished_at < ?", [Clock::at('-1 day')]);

        return $sent;
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

        return Language::runWith($language, function () use ($app, $s, $n, $language): array {
            $ds = DesignSystem::load($s);
            $items = self::newsItems($app, $n);
            $siteName = $s->get('site_name_' . $language) !== '' ? $s->get('site_name_' . $language) : $s->get('site_name');
            $button = $n['button_url'] !== '' && $n['button_label'] !== ''
                ? [(string) $n['button_label'], self::campaign($app, str_starts_with((string) $n['button_url'], '/') ? self::origin($app) . $app->request->basePath() . $n['button_url'] : (string) $n['button_url'], $n)] : null;
            $company = array_values(array_filter([$s->get('company_name') !== '' ? $s->get('company_name') : $siteName, implode(', ', Company::address($s))]));
            $logo = preg_match('/\.(png|jpe?g|gif|webp)$/i', $s->get('logo')) ? self::image($app, $s->get('logo'), false) : '';
            $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', (string) $n['intro']) ?: []), fn (string $p): bool => $p !== ''));
            $data = [
                'language' => $language, 'subject' => (string) $n['subject'], 'preheader' => (string) $n['preheader'], 'paragraphs' => $paragraphs,
                'items' => $items, 'button' => $button, 'siteName' => $siteName, 'siteUrl' => self::absolute($app, ''), 'logo' => $logo, 'company' => $company,
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
            $text .= "--\n" . implode("\n", $company) . "\n" . t('You receive this e-mail because you subscribed to news from %s.', $siteName) . "\n"
                . t('Unsubscribe') . ': ' . self::UNSUBSCRIBE . "\n";

            return [$html, $text];
        });
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
        if (!str_starts_with($url, self::origin($app) . '/') || preg_match('/[?&]utm_/', $url)) {
            return $url;
        }
        [$url, $anchor] = array_pad(explode('#', $url, 2), 2, null);

        return $url . (str_contains($url, '?') ? '&' : '?') . 'utm_source=newsletter&utm_medium=email&utm_campaign=' . rawurlencode(slugify((string) $n['subject'], 60))
            . ($anchor !== null ? '#' . $anchor : '');
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
        if ($variant && preg_match('#^(media/\d{4}/\d{2}/[a-z0-9-]+?)\.(jpg|png)$#D', $path, $m) && is_file(KALETA_ROOT . '/' . $m[1] . '-1200.' . $m[2])) {
            $path = $m[1] . '-1200.' . $m[2];
        }

        return self::origin($app) . $app->request->basePath() . '/' . $path;
    }
}
