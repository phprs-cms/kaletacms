<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Subscribers into the mailing service the site already uses: after the subscription is confirmed (double opt-in) the
 * address is added to the list in the service, after unsubscribing it is removed from it. Sending, deliverability and
 * unsubscribing from e-mails are handled by the service.
 *
 * Confirming and unsubscribing only write a task to the queue (ka_odber_fronta); the background cleanup sends it
 * (Notifications::runInBackground), so the visitor does not wait for the service. A failed attempt is retried later
 * (5 min, 30 min, 2 h, 12 h), then it gives up – the subscriber has the status "chyba" (error) in the admin and can be retried.
 *
 * The API key is stored only on the site ("Nastavení → Rozšíření", Features) and is neither shown nor changed over MCP.
 */
final class Newsletter
{
    /** service => [name, needs a key and a list] */
    public const array SERVICES = [
        'brevo' => ['Brevo', true],
        'mailerlite' => ['MailerLite', true],
        'mailchimp' => ['Mailchimp', true],
        'ecomail' => ['Ecomail', true],
        'smartemailing' => ['SmartEmailing', true],
        'webhook' => ['Another service via a webhook (Make, Zapier, n8n)', false],
    ];

    /** After how many minutes the next attempt comes (by the number of failed ones). */
    private const array RETRY_DELAYS = [5, 30, 120, 720];

    public static function isEnabled(Settings $s): bool
    {
        if (Demo::active()) {
            return false;
        }
        $service = $s->get('newsletter_service');
        if (!isset(self::SERVICES[$service])) {
            return false;
        }

        return self::SERVICES[$service][1] ? $s->get('newsletter_key') !== '' && $s->get('newsletter_list') !== '' : preg_match('#^https://#i', $s->get('newsletter_webhook')) === 1;
    }

    /** Queues adding (after confirmation) or removing (after unsubscribing) an address; nothing without a configured service. */
    public static function enqueue(App $app, string $email, string $action): void
    {
        if (!self::isEnabled($app->settings()) || !in_array($action, ['pridat', 'odebrat'], true)) {
            return;
        }
        $db = $app->db();
        // an older pending task for the same address is redundant – the latest state applies
        $db->run('DELETE FROM {odber_fronta} WHERE email = ?', [$email]);
        $db->insert('odber_fronta', ['email' => $email, 'akce' => $action, 'pokusy' => 0, 'dalsi' => date('Y-m-d H:i:s'), 'vytvoreno' => date('Y-m-d H:i:s')]);
        $db->run("UPDATE {odberatele} SET sync = 'ceka', sync_chyba = '' WHERE email = ?", [$email]);
    }

    /** All confirmed subscribers who are not in the service yet (after connecting the service). @return int how many are waiting */
    public static function enqueueAll(App $app): int
    {
        if (!self::isEnabled($app->settings())) {
            return 0;
        }
        $count = 0;
        foreach ($app->db()->all("SELECT email FROM {odberatele} WHERE stav = 1 AND sync <> 'ok'") as $o) {
            self::enqueue($app, (string) $o['email'], 'pridat');
            $count++;
        }

        return $count;
    }

    /** Retry abandoned tasks right away. */
    public static function retry(App $app): int
    {
        return $app->db()->run('UPDATE {odber_fronta} SET dalsi = ?, pokusy = 0 WHERE dalsi IS NULL', [Clock::now()])->rowCount();
    }

    /** Sends the tasks whose turn has come (called by the background cleanup). @return int number processed */
    public static function processQueue(App $app, int $limit = 10): int
    {
        $s = $app->settings();
        if (!self::isEnabled($s)) {
            return 0;
        }
        $db = $app->db();
        $done = 0;
        foreach ($db->all('SELECT * FROM {odber_fronta} WHERE dalsi IS NOT NULL AND dalsi <= ? ORDER BY idf LIMIT ' . max(1, $limit), [Clock::now()]) as $u) {
            try {
                self::apply($s, (string) $u['email'], (string) $u['akce'], (string) $db->value('SELECT zdroj FROM {odberatele} WHERE email = ?', [$u['email']]));
                $db->delete('odber_fronta', ['idf' => $u['idf']]);
                $db->run("UPDATE {odberatele} SET sync = 'ok', sync_chyba = '' WHERE email = ?", [$u['email']]);
                $done++;
            } catch (\RuntimeException $e) {
                $attempts = (int) $u['pokusy'] + 1;
                $delay = self::RETRY_DELAYS[$attempts - 1] ?? null;
                $error = mb_substr($e->getMessage(), 0, 250);
                $db->update('odber_fronta', ['pokusy' => $attempts, 'chyba' => $error, 'dalsi' => $delay === null ? null : date('Y-m-d H:i:s', time() + $delay * 60)], ['idf' => $u['idf']]);
                if ($delay === null) {
                    $db->run("UPDATE {odberatele} SET sync = 'chyba', sync_chyba = ? WHERE email = ?", [$error, $u['email']]);
                }
            }
        }

        return $done;
    }

    /**
     * One add or remove in the service. A service error = RuntimeException with the code and the start of the response (without the key).
     */
    public static function apply(Settings $s, string $email, string $action, string $source = ''): void
    {
        $service = $s->get('newsletter_service');
        $key = str_replace(["\r", "\n"], '', $s->get('newsletter_key')); // the key goes into a header – without line breaks
        $items = $s->get('newsletter_list');
        $toAdd = $action === 'pridat';
        [$method, $url, $headers, $body, $missingOk] = match ($service) {
            'brevo' => $toAdd
                ? ['POST', 'https://api.brevo.com/v3/contacts', ['api-key: ' . $key], ['email' => $email, 'listIds' => [(int) $items], 'updateEnabled' => true], false]
                : ['POST', 'https://api.brevo.com/v3/contacts/lists/' . rawurlencode($items) . '/contacts/remove', ['api-key: ' . $key], ['emails' => [$email]], true],
            'mailerlite' => ['POST', 'https://connect.mailerlite.com/api/subscribers', ['Authorization: Bearer ' . $key],
                $toAdd ? ['email' => $email, 'groups' => [$items], 'status' => 'active'] : ['email' => $email, 'status' => 'unsubscribed'], !$toAdd],
            'mailchimp' => [$toAdd ? 'PUT' : 'PATCH', 'https://' . self::dataCenter($key) . '.api.mailchimp.com/3.0/lists/' . rawurlencode($items) . '/members/' . md5(mb_strtolower($email)),
                ['Authorization: Basic ' . base64_encode('kaleta:' . $key)], $toAdd ? ['email_address' => $email, 'status_if_new' => 'subscribed', 'status' => 'subscribed'] : ['status' => 'unsubscribed'], !$toAdd],
            'ecomail' => $toAdd
                ? ['POST', 'https://api2.ecomailapp.cz/lists/' . rawurlencode($items) . '/subscribe', ['key: ' . $key], ['subscriber_data' => ['email' => $email], 'update_existing' => true, 'resubscribe' => true, 'skip_confirmation' => true], false]
                : ['DELETE', 'https://api2.ecomailapp.cz/lists/' . rawurlencode($items) . '/unsubscribe', ['key: ' . $key], ['email' => $email], true],
            'smartemailing' => ['POST', 'https://app.smartemailing.cz/api/v3/import', ['Authorization: Basic ' . base64_encode($key)],
                ['settings' => ['update' => true, 'skip_invalid_emails' => true], 'data' => [['emailaddress' => $email, 'contactlists' => [['id' => (int) $items, 'status' => $toAdd ? 'confirmed' : 'unsubscribed']]]]], false],
            'webhook' => ['POST', $s->get('newsletter_webhook'), [], ['udalost' => $toAdd ? 'novy_odberatel' : 'odhlaseni_odberu', 'web' => $s->get('site_name'), 'email' => $email,
                'zdroj' => $source, 'cas' => date('c')], false],
            default => throw new \RuntimeException(t('The mailing service is not set up.')),
        };
        // tests: the service URL can be redirected to a local fake server (only through the database, it is not in the admin)
        $test = $s->get('newsletter_test_url');
        if ($test !== '' && preg_match('#^http://127\.0\.0\.1:\d+$#D', $test)) {
            $url = $test . '/' . $service . (string) parse_url($url, PHP_URL_PATH);
        }
        [$code, $response] = self::http($method, $url, $headers, $body);
        if (($code >= 200 && $code < 300) || ($missingOk && $code === 404)) {
            return; // removing an address the service does not know is fine
        }
        // the error text is stored with the subscriber; the admin translates 'Služba neodpověděla.' when displaying it
        throw new \RuntimeException($code === 0 ? 'The service did not respond.' : 'HTTP ' . $code . ($response !== '' ? ': ' . mb_substr(trim(strip_tags($response)), 0, 180) : ''));
    }

    /** Mailchimp: the data center is after the dash in the key (…-us21). */
    private static function dataCenter(string $key): string
    {
        return preg_match('/-([a-z]{2}\d{1,3})$/', $key, $m) ? $m[1] : 'us1';
    }

    /** @param list<string> $headers @return array{0: int, 1: string} response code (0 = no response) and body */
    private static function http(string $method, string $url, array $headers, ?array $body): array
    {
        $response = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => $method, 'timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0, // the service does not redirect the request elsewhere
            'header' => implode("\r\n", array_merge(['Content-Type: application/json; charset=utf-8', 'Accept: application/json', 'User-Agent: Kaleta/' . KALETA_VERSION], $headers)) . "\r\n",
            'content' => $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]));
        // the status line through last_response_headers(): PHP 8.5 deprecates reading $http_response_header (3.15, N74)
        $status = last_response_headers($http_response_header ?? null)[0] ?? ''; // @phpstan-ignore nullCoalesce.variable (undefined on PHP 8.3 when the request fails)
        $code = preg_match('#^HTTP/\S+\s+(\d{3})#', $status, $m) ? (int) $m[1] : 0;

        return [$code, is_string($response) ? $response : ''];
    }
}
