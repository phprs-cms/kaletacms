<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Webhooks: after a news item is published and after a new enquiry, sends the data to the URLs from Settings (Make, Zapier,
 * IFTTT, n8n, CRM…). Through such a service a news item can be shared to social networks automatically and an enquiry
 * created in a CRM or sent to Slack.
 *
 * Since 1.8 every call is signed and logged (table ka_webhook_deliveries): the event is stored first and sent after the
 * response has gone to the visitor (afterResponse), so a slow receiver never delays the page. A failed call is retried
 * 1, 5 and 30 minutes and 2 and 12 hours later; the administrator sees the log and can send a failed call again.
 *
 * Signature: X-Kaleta-Signature: sha256=<hex HMAC-SHA256 of "<X-Kaleta-Timestamp>.<body>" with the webhook secret>.
 * The receiver recomputes it and rejects calls older than a few minutes (replay).
 */
final class Webhook
{
    /** Minutes until a failed call is retried; after the last one it stays in the log as failed. */
    public const array RETRY_DELAYS = [1, 5, 30, 120, 720];

    /** A call was stored during this request – send it after the response (afterResponse). */
    private static bool $pending = false;

    /** New enquiry from a site form → URL from Settings (CRM, Make, Zapier, n8n, Slack…). */
    public static function enquiryReceived(App $app, int $idp, string $form, array $data, string $email, string $page, string $campaign = '', string $landing = '', string $referrer = '', string $formId = '', string $about = ''): void
    {
        $url = $app->settings()->get('webhook_enquiries');
        if (!preg_match('#^https://#i', $url)) {
            return;
        }
        self::queue($app->settings(), 'nova_poptavka', $url, [
            'udalost' => 'nova_poptavka', 'web' => $app->settings()->get('site_name'), 'id' => $idp, 'formular' => $form, 'email' => $email,
            'stranka' => $app->request->origin() . $page, 'prijato' => date('c'),
            'about' => $about !== '' ? $about : null, // what the form was about (2.12, Front\EnquiryTopic): the item or page it was on
            // 2.3: which form (to route one form elsewhere in Make or Zapier) and where the visit started (with consent)
            'form_id' => $formId, 'first_page' => $landing !== '' ? $app->request->origin() . $landing : null, 'came_from' => $referrer !== '' ? $referrer : null,
            'pole' => array_map(fn (array $d): array => ['popisek' => $d[0], 'hodnota' => $d[1]], $data),
        ] + ($campaign !== '' ? ['utm' => self::utm($campaign)] : []));
    }

    /** @return array<string, string> utm_* parameters without the prefix: source, medium, campaign, term, content */
    private static function utm(string $campaign): array
    {
        parse_str($campaign, $utm);
        $result = [];
        foreach ($utm as $k => $h) {
            if (is_string($k) && is_string($h) && str_starts_with($k, 'utm_')) {
                $result[substr($k, 4)] = $h;
            }
        }

        return $result;
    }

    /**
     * Stores the call in the delivery log; it goes out after the response (afterResponse) or with the next background tasks.
     *
     * @param array<string, mixed> $data
     */
    public static function queue(Settings $settings, string $event, string $url, array $data): ?int
    {
        if (Demo::active()) {
            return null; // the public demo calls no other servers
        }
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        try {
            $id = $settings->db()->insert('webhook_deliveries', [
                'event' => mb_substr($event, 0, 40), 'url' => mb_substr($url, 0, 500), 'body' => $body,
                'created' => date('Y-m-d H:i:s'), 'next_attempt' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            self::send($settings, $url, $event, 0, $body); // before the migration runs, the table does not exist yet

            return null;
        }
        self::$pending = true;

        return $id;
    }

    /** After the page was sent (index.php, admin.php): delivers the calls stored during this request. */
    public static function afterResponse(App $app): void
    {
        if (!self::$pending) {
            return;
        }
        self::$pending = false;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            self::processQueue($app->settings());
        } catch (\Throwable) {
            // the next background run tries again
        }
    }

    /** Sends the calls that are due (new ones and retries). Returns the number delivered. */
    public static function processQueue(Settings $settings, int $maxCount = 10): int
    {
        $db = $settings->db();
        $delivered = 0;
        foreach ($db->all('SELECT id, event, url, body, attempts, next_attempt FROM {webhook_deliveries} WHERE next_attempt IS NOT NULL AND next_attempt <= ? AND body IS NOT NULL ORDER BY id LIMIT ' . max(1, $maxCount), [Clock::now()]) as $w) {
            $attempt = (int) $w['attempts'] + 1;
            $next = isset(self::RETRY_DELAYS[$attempt - 1]) ? date('Y-m-d H:i:s', time() + self::RETRY_DELAYS[$attempt - 1] * 60) : null;
            // claim the call first: a concurrent request then does not send it a second time
            if ($db->run('UPDATE {webhook_deliveries} SET attempts = ?, next_attempt = ? WHERE id = ? AND next_attempt = ?', [min(255, $attempt), $next, $w['id'], $w['next_attempt']])->rowCount() === 0) {
                continue;
            }
            [$status, $error] = self::send($settings, $w['url'], $w['event'], (int) $w['id'], (string) $w['body']);
            if ($error === '') {
                $db->update('webhook_deliveries', ['status' => $status, 'error' => '', 'delivered' => date('Y-m-d H:i:s'), 'next_attempt' => null, 'body' => null], ['id' => $w['id']]);
                $delivered++;
            } else {
                $db->update('webhook_deliveries', ['status' => $status, 'error' => mb_substr($error, 0, 255)], ['id' => $w['id']]);
                if ($next === null) {
                    Events::record($db, 'webhook.failed', 'error', mb_substr(t('Webhook %s could not be delivered: %s', (string) $w['event'], $error), 0, 255), ['delivery' => (int) $w['id']]);
                }
            }
        }
        if (random_int(1, 20) === 1) {
            $db->run('DELETE FROM {webhook_deliveries} WHERE created < ?', [Clock::at('-30 days')]); // enquiry data must not stay forever
        }

        return $delivered;
    }

    /** A failed call (given up, the body still kept) goes out once more with the next processing. */
    public static function retry(Db $db, int $id): bool
    {
        return $db->run('UPDATE {webhook_deliveries} SET next_attempt = ? WHERE id = ? AND delivered IS NULL AND body IS NOT NULL', [Clock::now(), $id])->rowCount() > 0;
    }

    /** The secret signing the calls; created the first time it is needed. */
    public static function secret(Settings $settings): string
    {
        if ($settings->get('webhook_secret') === '') {
            $settings->set('webhook_secret', 'whsec_' . bin2hex(random_bytes(24)));
        }

        return $settings->get('webhook_secret');
    }

    /** @return array{0: string, 1: string} headers X-Kaleta-Timestamp and X-Kaleta-Signature for the body */
    public static function signature(string $secret, string $body, int $timestamp): array
    {
        return [(string) $timestamp, 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret)];
    }

    /** @return array{0: int, 1: string} HTTP status (0 = no response) and the error ('' = delivered, 2xx) */
    private static function send(Settings $settings, string $url, string $event, int $id, string $body): array
    {
        // tests: the URL can be redirected to a local fake server (only through the database, it is not in the admin)
        $test = $settings->get('webhook_test_url');
        if ($test !== '' && preg_match('#^http://127\.0\.0\.1:\d+$#D', $test)) {
            $url = $test . (string) parse_url($url, PHP_URL_PATH);
        } elseif (!preg_match('#^https://#i', $url)) {
            return [0, 'Only https:// addresses are allowed.'];
        }
        [$timestamp, $signature] = self::signature(self::secret($settings), $body, time());
        $headers = ['Content-Type: application/json; charset=utf-8', 'User-Agent: Kaleta/' . KALETA_VERSION, 'X-Kaleta-Event: ' . $event,
            'X-Kaleta-Delivery: ' . $id, 'X-Kaleta-Timestamp: ' . $timestamp, 'X-Kaleta-Signature: ' . $signature];
        $response = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
            'header' => implode("\r\n", $headers) . "\r\n", 'content' => $body,
        ]]));
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string) (last_response_headers($http_response_header ?? null)[0] ?? ''), $m) ? (int) $m[1] : 0; // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
        if ($status >= 200 && $status < 300) {
            return [$status, ''];
        }

        return [$status, $status === 0 ? 'The receiver did not respond.' : 'HTTP ' . $status . (is_string($response) && trim($response) !== '' ? ': ' . mb_substr(trim(strip_tags($response)), 0, 180) : '')];
    }

    public static function articlePublished(App $app, int $idc): void
    {
        $url = $app->settings()->get('webhook_url');
        if (!preg_match('#^https://#i', $url)) {
            return;
        }
        $c = $app->db()->one(
            'SELECT c.*, t.nazev AS kategorie FROM {novinky} c JOIN {kategorie} t ON t.idt = c.tema WHERE c.idc = ? AND c.visible = 1 AND c.datum <= ? AND c.noindex = 0',
            [$idc, Clock::now()],
        );
        if ($c === null) {
            return; // a draft or a news item scheduled for the future
        }
        $root = $app->request->origin() . $app->request->basePath() . '/'; // files are shared by all languages
        $data = [
            'udalost' => 'novinka_vydana', 'web' => $app->settings()->get('site_name'), 'titulek' => $c['titulek'],
            'adresa' => $app->request->origin() . $app->newsItemUrl($c['seo_link'], $c['jazyk']), 'perex' => trim(strip_tags($c['uvod'])), 'kategorie' => $c['kategorie'],
            'obrazek' => $c['obrazek'] === '' ? '' : (preg_match('#^https?://#i', $c['obrazek']) ? $c['obrazek'] : rtrim($root, '/') . '/' . ltrim($c['obrazek'], '/')),
            'stitky' => array_column($app->db()->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$idc]), 'nazev'),
            'vydano' => date('c', strtotime($c['datum'])),
        ];
        self::queue($app->settings(), 'novinka_vydana', $url, $data);
    }

    /** A test call to every configured URL (Settings → Webhooks); returns the delivery IDs. @return list<int> */
    public static function test(App $app): array
    {
        $ids = [];
        foreach (['webhook_enquiries', 'webhook_url'] as $key) {
            $url = $app->settings()->get($key);
            if (preg_match('#^https://#i', $url)) {
                $ids[] = (int) self::queue($app->settings(), 'test', $url, ['udalost' => 'test', 'web' => $app->settings()->get('site_name'), 'cas' => date('c')]);
            }
        }

        return array_values(array_filter($ids));
    }
}
