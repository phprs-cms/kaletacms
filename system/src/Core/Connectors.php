<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Connectors\Connector;

/**
 * Outbound connectors (2.13): one careful foundation for the outside services a site talks to, with small features on
 * top (Search Console data, the Business Profile, a sheet of enquiries, a CRM).
 *
 *  - A short curated list of services (SERVICES); nothing else can be connected.
 *  - Credentials are encrypted with a key derived from the site's secret (sodium secretbox) and never leave the server:
 *    the admin form never shows them back, MCP never sees them, the site export never carries them.
 *  - OAuth with PKCE and a state bound to the session; the access token is refreshed with the refresh token when it
 *    expires; disconnecting revokes it where the service allows it.
 *  - Every call is rate-limited per service and logged without its content (ka_connector_log); deliveries that may fail
 *    (a lead to a CRM, a row to a sheet) go through a queue with retries, run by the scheduler job 'connectors'.
 *
 * Tests point every service at a fake one with the environment variable KALETA_CONNECTORS_FAKE (a base URL that replaces
 * the scheme and host of every call); it cannot be set from the administration.
 */
final class Connectors
{
    /** @var list<class-string<Connector>> */
    public const array SERVICES = [\Kaleta\Connectors\Google::class, \Kaleta\Connectors\Bing::class, \Kaleta\Connectors\HubSpot::class, \Kaleta\Connectors\Pipedrive::class, \Kaleta\Connectors\Raynet::class];

    /** Queue handlers: the prefix of an action => the class with a static deliver(App, string $action, array $payload): string. */
    public const array HANDLERS = ['gbp' => GoogleBusiness::class, 'sheets' => EnquirySheet::class, 'crm' => EnquiryCrm::class];

    /** Minutes between attempts of a delivery; after the last one it is given up and reported. */
    public const array RETRY_DELAYS = [1, 5, 30, 120, 720];

    /** @return class-string<Connector>|null */
    public static function service(string $key): ?string
    {
        foreach (self::SERVICES as $class) {
            if ($class::KEY === $key) {
                return $class;
            }
        }

        return null;
    }

    /* ---------- credentials ---------- */

    /** The key of this vault: from vault_key in config.php once it was moved there, until then from secret_key (Core\Vault, 3.15). */
    private static function vaultKey(Settings $settings): string
    {
        return Vault::key($settings, 'connectors');
    }

    public static function encrypt(Settings $settings, string $plain): string
    {
        return Vault::seal(self::vaultKey($settings), $plain);
    }

    /** null when it cannot be read (another site's key, damaged). */
    public static function decrypt(Settings $settings, ?string $sealed): ?string
    {
        return Vault::open(self::vaultKey($settings), $sealed);
    }

    /** @return array<string, mixed>|null */
    public static function row(Db $db, string $key): ?array
    {
        return $db->one('SELECT * FROM {connectors} WHERE service = ?', [$key]);
    }

    /** The connection's settings (which sheet, location, pipeline). @return array<string, string> */
    public static function config(Db $db, string $key): array
    {
        $config = json_decode((string) ($db->value('SELECT config FROM {connectors} WHERE service = ?', [$key]) ?? ''), true);

        return is_array($config) ? array_map('strval', array_filter($config, 'is_scalar')) : [];
    }

    /**
     * Changes some of a connection's settings from code (the id of the sheet "Create the sheet" made); the rest stays.
     *
     * @param array<string, string> $values
     */
    public static function updateConfig(Db $db, string $key, array $values): void
    {
        $db->update('connectors', ['config' => (string) json_encode(array_map(fn (string $v): string => mb_substr($v, 0, 500), $values + self::config($db, $key)), JSON_UNESCAPED_UNICODE)], ['service' => $key]);
    }

    public static function isConnected(Db $db, string $key): bool
    {
        return (int) $db->value('SELECT COUNT(*) FROM {connectors} WHERE service = ? AND connected_at IS NOT NULL', [$key]) > 0;
    }

    /**
     * Saves what the administrator entered: the OAuth client (id, secret) or an API token, the account label and the
     * connection's settings. An empty secret keeps the stored one (the form never shows it back).
     *
     * @param array<string, string> $config
     */
    public static function saveCredentials(App $app, string $key, string $clientId, string $secret, string $account, array $config): void
    {
        $class = self::service($key) ?? throw new \InvalidArgumentException('Unknown service.');
        $db = $app->db();
        $row = ['client_id' => mb_substr(trim($clientId), 0, 255), 'account' => mb_substr(trim($account), 0, 190),
            'config' => (string) json_encode(array_intersect_key(array_map(fn (string $v): string => mb_substr(trim($v), 0, 500), $config), $class::settings()), JSON_UNESCAPED_UNICODE)];
        if (trim($secret) !== '') {
            $row['secret'] = self::encrypt($app->settings(), trim($secret));
            if ($class::AUTH !== 'oauth') {
                $row += ['connected_at' => date('Y-m-d H:i:s'), 'connected_by' => self::who($app), 'last_error' => ''];
            }
        }
        if (self::row($db, $key) === null) {
            $db->insert('connectors', $row + ['service' => $key]);
        } else {
            $db->update('connectors', $row, ['service' => $key]);
        }
        \Kaleta\Admin\ChangeLog::write($app, 'connectors', 'save', $key);
    }

    /**
     * Changes some of a connection's settings and keeps the rest (a button that picks the Search Console property).
     *
     * @param array<string, string> $config
     */
    public static function saveConfig(App $app, string $key, array $config): void
    {
        $class = self::service($key) ?? throw new \InvalidArgumentException('Unknown service.');
        $db = $app->db();
        $merged = array_intersect_key(array_map(fn (string $v): string => mb_substr(trim($v), 0, 500), $config), $class::settings()) + self::config($db, $key);
        if (self::row($db, $key) === null) {
            $db->insert('connectors', ['service' => $key, 'config' => (string) json_encode($merged, JSON_UNESCAPED_UNICODE)]);
        } else {
            $db->update('connectors', ['config' => (string) json_encode($merged, JSON_UNESCAPED_UNICODE)], ['service' => $key]);
        }
        \Kaleta\Admin\ChangeLog::write($app, 'connectors', 'save', $key);
    }

    private static function who(App $app): string
    {
        $user = $app->auth()->user();

        return mb_substr((string) (($user['jmeno'] ?? '') !== '' ? $user['jmeno'] : ($user['user'] ?? '')), 0, 100);
    }

    /* ---------- OAuth ---------- */

    /** Where the service sends the administrator back after the sign-in – to be entered in the OAuth app. */
    public static function redirectUri(App $app): string
    {
        return Mailing::absolute($app, 'admin.php?module=connectors&action=callback');
    }

    /** The sign-in address with a state and a PKCE challenge kept in the session. */
    public static function authorizeUrl(App $app, string $key): string
    {
        $class = self::service($key);
        $row = self::row($app->db(), $key);
        if ($class === null || $class::AUTH !== 'oauth' || ($row['client_id'] ?? '') === '') {
            throw new \DomainException('Enter the client ID and secret of your OAuth app first.');
        }
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $app->session->set('connector_oauth', ['service' => $key, 'state' => $state, 'verifier' => $verifier, 'at' => time()]);

        return self::url($class::AUTHORIZE_URL) . '?' . http_build_query([
            'client_id' => $row['client_id'], 'redirect_uri' => self::redirectUri($app), 'response_type' => 'code', 'scope' => implode(' ', $class::SCOPES),
            'state' => $state, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
    }

    /** The service's answer: the state must match the session's (10 minutes), then the code is exchanged for tokens. */
    public static function callback(App $app, string $code, string $state): string
    {
        $pending = $app->session->get('connector_oauth');
        $app->session->set('connector_oauth', null);
        if (!is_array($pending) || !hash_equals((string) ($pending['state'] ?? ''), $state) || time() - (int) ($pending['at'] ?? 0) > 600 || $code === '') {
            throw new \DomainException('The sign-in could not be verified. Try connecting again.');
        }
        $key = (string) $pending['service'];
        $class = self::service($key) ?? throw new \DomainException('Unknown service.');
        $row = (array) self::row($app->db(), $key);
        $answer = self::http('POST', self::url($class::TOKEN_URL), ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri($app), 'client_id' => $row['client_id'] ?? '',
            'client_secret' => self::decrypt($app->settings(), $row['secret'] ?? null) ?? '', 'code_verifier' => (string) $pending['verifier'],
        ]));
        self::log($app->db(), $key, 'oauth.token', $answer);
        $tokens = $answer['json'];
        if ($answer['status'] !== 200 || !is_string($tokens['access_token'] ?? null)) {
            throw new \DomainException(t('%s did not accept the sign-in: %s', $class::NAME, mb_substr((string) ($tokens['error_description'] ?? $tokens['error'] ?? $answer['error']), 0, 150)));
        }
        $email = '';
        if (is_string($tokens['id_token'] ?? null) && count($parts = explode('.', $tokens['id_token'])) === 3) {
            $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true); // only shown as the account name, never trusted for anything
            $email = is_array($claims) && is_string($claims['email'] ?? null) ? $claims['email'] : '';
        }
        $app->db()->update('connectors', ['access_token' => self::encrypt($app->settings(), $tokens['access_token']),
            'refresh_token' => is_string($tokens['refresh_token'] ?? null) ? self::encrypt($app->settings(), $tokens['refresh_token']) : ($row['refresh_token'] ?? null),
            'expires_at' => date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600)) - 60), 'scopes' => mb_substr((string) ($tokens['scope'] ?? implode(' ', $class::SCOPES)), 0, 500),
            'account' => $email !== '' ? mb_substr($email, 0, 190) : (string) ($row['account'] ?? ''), 'connected_at' => date('Y-m-d H:i:s'), 'connected_by' => self::who($app), 'last_error' => ''], ['service' => $key]);
        Events::record($app->db(), 'connector.connected', 'info', t('%s was connected.', $class::NAME), ['service' => $key]);

        return $key;
    }

    /** The credential for a call: the API token, or an OAuth access token refreshed when it has expired; null = not connected. */
    public static function credential(App $app, string $key): ?string
    {
        $class = self::service($key);
        $row = self::row($app->db(), $key);
        if ($class === null || $row === null || $row['connected_at'] === null) {
            return null;
        }
        if ($class::AUTH !== 'oauth') {
            return self::decrypt($app->settings(), $row['secret']);
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) > time()) {
            return self::decrypt($app->settings(), $row['access_token']);
        }
        $refresh = self::decrypt($app->settings(), $row['refresh_token']);
        if ($refresh === null) {
            return null;
        }
        $answer = self::http('POST', self::url($class::TOKEN_URL), ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $row['client_id'], 'client_secret' => self::decrypt($app->settings(), $row['secret']) ?? '',
        ]));
        self::log($app->db(), $key, 'oauth.refresh', $answer);
        if ($answer['status'] !== 200 || !is_string($answer['json']['access_token'] ?? null)) {
            $app->db()->update('connectors', ['last_error' => mb_substr(t('The sign-in expired – connect %s again.', $class::NAME), 0, 255)], ['service' => $key]);

            return null;
        }
        $app->db()->update('connectors', ['access_token' => self::encrypt($app->settings(), $answer['json']['access_token']),
            'expires_at' => date('Y-m-d H:i:s', time() + max(60, (int) ($answer['json']['expires_in'] ?? 3600)) - 60)], ['service' => $key]);

        return $answer['json']['access_token'];
    }

    /** Disconnects: revokes the token where the service allows it and forgets every credential (the OAuth app stays). */
    public static function disconnect(App $app, string $key): void
    {
        $class = self::service($key);
        $row = self::row($app->db(), $key);
        if ($class === null || $row === null) {
            return;
        }
        $token = self::decrypt($app->settings(), $row['refresh_token'] ?? null) ?? self::decrypt($app->settings(), $row['access_token'] ?? null);
        if ($class::REVOKE_URL !== '' && $token !== null) {
            self::log($app->db(), $key, 'oauth.revoke', self::http('POST', self::url($class::REVOKE_URL), ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['token' => $token])));
        }
        $app->db()->update('connectors', ['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'connected_at' => null, 'last_error' => '']
            + ($class::AUTH !== 'oauth' ? ['secret' => null] : []), ['service' => $key]);
        $class::disconnected($app);
        \Kaleta\Admin\ChangeLog::write($app, 'connectors', 'disconnect', $key);
    }

    /* ---------- calls ---------- */

    /**
     * A call to a connected service: authorised, rate-limited and logged (without its content). $body is JSON-encoded
     * unless it is already a string (a form body with its own Content-Type).
     *
     * @param array<string, string> $headers
     * @return array{status: int, json: array<mixed>|null, raw: string, error: string}
     */
    public static function request(App $app, string $key, string $method, string $url, array|string|null $body = null, array $headers = [], string $action = ''): array
    {
        $class = self::service($key);
        $credential = self::credential($app, $key);
        if ($class === null || $credential === null) {
            return ['status' => 0, 'json' => null, 'raw' => '', 'error' => t('%s is not connected.', $class !== null ? $class::NAME : $key)];
        }
        $db = $app->db();
        if ((int) $db->value('SELECT COUNT(*) FROM {connector_log} WHERE service = ? AND created_at > ?', [$key, date('Y-m-d H:i:s', time() - 60)]) >= $class::PER_MINUTE) {
            return ['status' => 0, 'json' => null, 'raw' => '', 'error' => t('Too many calls to %s this minute – it will be tried again.', $class::NAME)];
        }
        $row = (array) self::row($db, $key);
        $headers += $class::authHeaders($credential, (string) ($row['account'] ?? ''));
        if (is_array($body)) {
            $headers += ['Content-Type' => 'application/json'];
            $body = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        // a key the service wants in the address (Bing) goes into the query of the call only – the log keeps the path
        $authQuery = $class::authQuery($credential);
        $target = $authQuery === [] ? $url : $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($authQuery);
        $answer = self::http($method, self::url($target), $headers + ['Accept' => 'application/json'], $body);
        self::log($db, $key, $action !== '' ? $action : $method . ' ' . (string) parse_url($url, PHP_URL_PATH), $answer);
        if ($answer['status'] === 401) {
            $db->update('connectors', ['expires_at' => date('Y-m-d H:i:s', 0)], ['service' => $key]); // the next call refreshes it
        }

        return $answer;
    }

    /** The real address, or the fake service in tests (KALETA_CONNECTORS_FAKE keeps the path and the query). */
    public static function url(string $url): string
    {
        $fake = getenv('KALETA_CONNECTORS_FAKE');
        if (is_string($fake) && $fake !== '' && preg_match('#^https://[^/]+(/.*)?$#D', $url, $m) === 1) {
            return rtrim($fake, '/') . ($m[1] ?? '/');
        }

        return $url;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, json: array<mixed>|null, raw: string, error: string, ms: int}
     */
    private static function http(string $method, string $url, array $headers, ?string $body): array
    {
        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers), CURLOPT_USERAGENT => 'Kaleta/' . KALETA_VERSION]
            + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? curl_error($ch) : '';
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if ($error === '' && ($status < 200 || $status >= 300)) {
            $error = 'HTTP ' . $status . (is_array($json) ? ': ' . mb_substr((string) ($json['error']['message'] ?? (is_string($json['error'] ?? null) ? $json['error'] : '') ?: ($json['message'] ?? '')), 0, 150) : '');
        }

        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => is_string($raw) ? $raw : '', 'error' => $error, 'ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    /** @param array{status: int, error: string, ms?: int} $answer */
    private static function log(Db $db, string $key, string $action, array $answer): void
    {
        $db->insert('connector_log', ['created_at' => date('Y-m-d H:i:s'), 'service' => $key, 'action' => mb_substr($action, 0, 60), 'status' => max(0, min(999, $answer['status'])),
            'ok' => $answer['error'] === '' ? 1 : 0, 'ms' => (int) ($answer['ms'] ?? 0), 'error' => mb_substr($answer['error'], 0, 255)]);
        if (random_int(1, 50) === 1) {
            $db->run('DELETE FROM {connector_log} WHERE created_at < ?', [Clock::at('-30 days')]);
        }
    }

    /* ---------- the delivery queue ---------- */

    /** Puts a delivery in the queue (e.g. sheets.append with the enquiry); the job sends it, with retries. */
    public static function queue(Db $db, string $action, array $payload): int
    {
        return $db->insert('connector_queue', ['action' => mb_substr($action, 0, 40), 'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'), 'next_attempt' => date('Y-m-d H:i:s')]);
    }

    /** The handler class of an action by its prefix (the part before the dot). @return class-string|null */
    public static function handler(string $action): ?string
    {
        $prefix = strstr($action, '.', true) ?: $action;

        return self::HANDLERS[$prefix] ?? null;
    }

    /** The scheduler job: due deliveries go out; a failed one is tried again later, the last failure is reported. */
    public static function processQueue(App $app, int $max = 20): string
    {
        $db = $app->db();
        $done = 0;
        foreach ($db->all('SELECT * FROM {connector_queue} WHERE next_attempt IS NOT NULL AND next_attempt <= ? ORDER BY id LIMIT ' . max(1, $max), [date('Y-m-d H:i:s')]) as $q) {
            $attempt = (int) $q['attempts'] + 1;
            $next = isset(self::RETRY_DELAYS[$attempt - 1]) ? date('Y-m-d H:i:s', time() + self::RETRY_DELAYS[$attempt - 1] * 60) : null;
            if ($db->run('UPDATE {connector_queue} SET attempts = ?, next_attempt = ? WHERE id = ? AND next_attempt = ?', [min(255, $attempt), $next, $q['id'], $q['next_attempt']])->rowCount() === 0) {
                continue; // another run took it
            }
            $handler = self::handler((string) $q['action']);
            $payload = json_decode((string) $q['payload'], true);
            $error = $handler === null ? 'No handler for ' . $q['action'] : (string) $handler::deliver($app, (string) $q['action'], is_array($payload) ? $payload : []);
            if ($error === '') {
                $db->update('connector_queue', ['delivered_at' => date('Y-m-d H:i:s'), 'next_attempt' => null, 'payload' => null, 'last_error' => ''], ['id' => $q['id']]);
                $done++;
            } else {
                $db->update('connector_queue', ['last_error' => mb_substr($error, 0, 255)], ['id' => $q['id']]);
                if ($next === null) {
                    Events::record($db, 'connector.failed', 'error', mb_substr(t('A delivery to an outside service failed: %s', $error), 0, 255), ['delivery' => (int) $q['id'], 'action' => (string) $q['action']]);
                }
            }
        }
        if (random_int(1, 20) === 1) {
            $db->run('DELETE FROM {connector_queue} WHERE created_at < ?', [Clock::at('-30 days')]); // payloads may carry personal data
        }

        return 'delivered ' . $done;
    }

    /**
     * Status of every service for the admin and Claude – never a credential.
     *
     * @return list<array{service: string, name: string, auth: string, connected: bool, account: string, since: ?string, error: string, has_app: bool}>
     */
    public static function status(Db $db): array
    {
        $out = [];
        foreach (self::SERVICES as $class) {
            $row = self::row($db, $class::KEY);
            $out[] = ['service' => $class::KEY, 'name' => $class::NAME, 'auth' => $class::AUTH, 'connected' => ($row['connected_at'] ?? null) !== null,
                'account' => (string) ($row['account'] ?? ''), 'since' => $row['connected_at'] ?? null, 'error' => (string) ($row['last_error'] ?? ''),
                'has_app' => ($row['client_id'] ?? '') !== '' && ($row['secret'] ?? null) !== null];
        }

        return $out;
    }
}
