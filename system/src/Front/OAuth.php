<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Events;
use Kaleta\Core\Firewall;
use Kaleta\Core\Response;
use Kaleta\Core\Session;

/**
 * OAuth 2.1 for the Claude connector (and other MCP clients) according to the MCP authorization specification:
 *   /.well-known/oauth-protected-resource   protected resource metadata (RFC 9728) – who issues tokens for /mcp
 *   /.well-known/oauth-authorization-server authorization server metadata (RFC 8414)
 *   /oauth/register                         dynamic client registration (RFC 7591)
 *   /oauth/authorize                        start of sign-in → consent in the administration (admin.php?action=oauth)
 *   /oauth/token                            exchange of a code for tokens (PKCE S256) and token refresh
 *
 * The application gets the permissions of the user who allowed it – all of them, or (chosen on the consent screen, 2.2)
 * only drafts or only reading. The access token is valid for an hour, the
 * refresh token for 30 days, and it is exchanged for a new one on every use. Tokens are stored in ka_api_tokeny (hashes
 * only) – disconnecting the application in "Můj účet" (My account) deletes them.
 * At the domain root the metadata are at /.well-known/. A site in a subfolder serves them at /folder/.well-known/
 * (openid-configuration included), where MCP clients that follow the current specification look; for others, sign-in with
 * a personal token remains.
 *
 * 3.3.4 (security audit of 8 October 2026):
 *  - N14: every sign-in request waits in the session under its own nonce (a few at most, 15 minutes each), the consent
 *    form carries that nonce and approves only that request – a second /oauth/authorize in the same session cannot swap
 *    the request behind a consent page the person is looking at.
 *  - N65: the consent screen leads with the return host, warns when it is not one of Claude's own (CLAUDE_HOSTS), marks
 *    clients the site never approved, and pre-selects "Drafts only" for a client outside Claude's hosts. The setting
 *    claude_apps_only (Claude settings, off by default) refuses registration and sign-in outside CLAUDE_HOSTS.
 *  - N13: a code or a refresh token is redeemed only by the request whose DELETE removed its row. A rotated refresh token
 *    is remembered in ka_oauth_rotated. Used again within REFRESH_GRACE seconds (a client retrying a refresh whose answer
 *    it lost, or two of its workers refreshing at once) it returns the same new pair – the pair is derived from the old
 *    token and a salt kept with the record, so no token is stored in the clear. Used again later, it counts as stolen:
 *    every token of that client for that user is revoked and the event security.token_reuse is recorded.
 *  - N66: clients that were never approved and have no token and no code are deleted a day after registration (the daily
 *    job "security"); the registration limit counts the visitor by Firewall::visitorKey (an IPv6 address by its /64).
 *  - N20: the code_verifier must have the 43–128 characters RFC 7636 requires.
 */
final class OAuth
{
    public const int ACCESS_LIFETIME = 3600;
    // 3.9 (owner decision): a Claude connection stays signed in while it is used at least once a year, like a personal token;
    // the access token still lasts an hour and every refresh rotates the refresh token
    public const int REFRESH_LIFETIME = 365 * 86400;
    private const int CODE_LIFETIME = 600;
    private const string CLIENT_PATTERN = '/^[a-f0-9]{32}$/D';

    /**
     * Return hosts of Claude's own apps: claude.ai and claude.com with their subdomains (Claude on the web, desktop and
     * mobile), and the loopback addresses Claude Code receives the sign-in on. A redirect_uri on one of them goes back to
     * Claude; anything else gets a warning on the consent screen (or is refused with claude_apps_only).
     */
    public const array CLAUDE_HOSTS = ['claude.ai', 'claude.com', 'localhost', '127.0.0.1', '[::1]'];

    /** A sign-in request waits for the consent this long (seconds), and a session keeps at most this many of them. */
    public const int PENDING_LIFETIME = 900;
    private const int PENDING_MAX = 5;
    private const string PENDING_KEY = 'oauth_pending';

    /**
     * Seconds during which the refresh token just rotated still returns the same new pair (a retry of a refresh whose answer
     * was lost, or two parallel refreshes of one client). After that, using it again revokes the client's tokens.
     */
    public const int REFRESH_GRACE = 30;

    public function __construct(private readonly App $app)
    {
    }

    /** Handles an OAuth URL, or returns null when the path does not belong to OAuth. */
    public function handle(string $path): ?Response
    {
        $r = $this->app->request;
        $isOAuth = str_starts_with($path, '/.well-known/oauth-') || in_array($path, ['/.well-known/openid-configuration', '/oauth/register', '/oauth/authorize', '/oauth/token'], true);
        if (!$isOAuth) {
            return null;
        }
        if (!\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'claude')) {
            return Response::json(['error' => 'not_found', 'error_description' => 'The Claude connection is switched off on this website.'], 404);
        }
        $isLocal = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$isLocal) {
            return Response::json(['error' => 'invalid_request', 'error_description' => 'OAuth is available over HTTPS only.'], 403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            return new Response('', 204, self::cors() + ['Access-Control-Allow-Methods' => 'GET, POST, OPTIONS', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version']);
        }

        return match (true) {
            str_starts_with($path, '/.well-known/oauth-protected-resource') => $this->json($this->resourceMetadata()),
            // a site in a subfolder cannot answer at the domain root (/.well-known/oauth-authorization-server/folder); MCP clients
            // then look for {issuer}/.well-known/openid-configuration, which it can (2.2)
            str_starts_with($path, '/.well-known/oauth-authorization-server'), $path === '/.well-known/openid-configuration' => $this->json($this->serverMetadata()),
            $path === '/oauth/register' => $this->register(),
            $path === '/oauth/authorize' => $this->authorize(),
            default => $this->token(),
        };
    }

    public function issuer(): string
    {
        return $this->app->request->origin() . rtrim($this->app->url(''), '/');
    }

    /** URL of the protected resource metadata – MCP sends it in the WWW-Authenticate header. */
    public function metadataUrl(): string
    {
        return $this->issuer() . '/.well-known/oauth-protected-resource';
    }

    /** @return array<string, mixed> */
    private function resourceMetadata(): array
    {
        return ['resource' => $this->issuer() . '/mcp', 'authorization_servers' => [$this->issuer()], 'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'], 'resource_name' => $this->app->settings()->get('site_name')];
    }

    /** @return array<string, mixed> */
    private function serverMetadata(): array
    {
        $v = $this->issuer();

        return [
            'issuer' => $v, 'authorization_endpoint' => $v . '/oauth/authorize', 'token_endpoint' => $v . '/oauth/token', 'registration_endpoint' => $v . '/oauth/register',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'], 'scopes_supported' => ['mcp'],
            'service_documentation' => 'https://kaletacms.com',
        ];
    }

    /** Dynamic client registration (RFC 7591): this is how Claude creates its own client_id with its redirect URI. */
    private function register(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return $this->error('invalid_request', 'Registration is sent with the POST method.', 405);
        }
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        // the visitor behind the configured proxy, an IPv6 address by its /64 – not the proxy's address (3.3.4, N66)
        $visitor = Firewall::visitorKey($r, $this->app->settings());
        if ($antispam->count($visitor, 'oauth-registrace', 0, 60) >= 20) {
            return $this->error('invalid_request', 'Too many registrations from this address. Try again in an hour.', 429);
        }
        $antispam->write($visitor, 'oauth-registrace', 0);
        $data = json_decode((string) file_get_contents('php://input'), true);
        $addresses = is_array($data['redirect_uris'] ?? null) ? array_values(array_filter($data['redirect_uris'], 'is_string')) : [];
        if ($addresses === [] || count($addresses) > 5 || array_filter($addresses, fn (string $a): bool => !self::isValidRedirectUri($a)) !== []) {
            return $this->error('invalid_redirect_uri', 'redirect_uris: 1–5 https:// addresses (or http://localhost for apps on the computer) without a # part.');
        }
        if ($this->app->settings()->bool('claude_apps_only') && array_filter($addresses, fn (string $a): bool => !self::isClaudeHost(self::host($a))) !== []) {
            return $this->error('invalid_redirect_uri', 'This website lets only Claude\'s own apps connect: redirect_uris must be on ' . implode(', ', self::CLAUDE_HOSTS) . '.');
        }
        $authMethod = in_array($data['token_endpoint_auth_method'] ?? 'none', ['none', 'client_secret_post', 'client_secret_basic'], true) ? (string) ($data['token_endpoint_auth_method'] ?? 'none') : 'none';
        $clientId = bin2hex(random_bytes(16));
        $secret = $authMethod === 'none' ? '' : bin2hex(random_bytes(32));
        $name = mb_substr(trim(strip_tags((string) ($data['client_name'] ?? ''))), 0, 100) ?: 'Aplikace MCP';
        $this->app->db()->insert('oauth_klienti', ['client_id' => $clientId, 'tajemstvi' => $secret === '' ? '' : hash('sha256', $secret), 'nazev' => $name,
            'presmerovani' => (string) json_encode($addresses, JSON_UNESCAPED_SLASHES), 'vytvoren' => date('Y-m-d H:i:s')]);

        return $this->json(['client_id' => $clientId, 'client_id_issued_at' => time(), 'client_name' => $name, 'redirect_uris' => $addresses,
            'token_endpoint_auth_method' => $authMethod, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]
            + ($secret !== '' ? ['client_secret' => $secret, 'client_secret_expires_at' => 0] : []), 201);
    }

    /**
     * Start of sign-in: verifies the client and the redirect URI, saves the parameters in the session and sends the user
     * to consent in the administration (where they sign in first if needed). An invalid client or redirect URI is not
     * redirected anywhere (open redirect).
     */
    private function authorize(): Response
    {
        $r = $this->app->request;
        $client = $this->client($r->get('client_id'));
        $redirectUri = $r->get('redirect_uri');
        if ($client === null || !self::redirectAllowed($redirectUri, (array) (json_decode((string) $client['presmerovani'], true) ?: []))) {
            return new Response('<!doctype html><meta charset="utf-8"><title>' . e(t('Invalid sign-in request')) . '</title><p style="font:16px system-ui;margin:3em">'
                . e(t('The application did not register correctly with this website (unknown client or return address). Please connect it again.')) . '</p>', 400, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        // 3.3.2 (N8): before consent nothing is redirected – a client registered by anyone with any https address would make
        // this site a redirector; a request Claude never sends (no code flow, no PKCE) gets a page with the reason instead
        $back = fn (string $error, string $description): Response => new Response('<!doctype html><meta charset="utf-8"><title>' . e(t('Invalid sign-in request')) . '</title><p style="font:16px system-ui;margin:3em">'
            . e(t('The application sent an incomplete sign-in request, so it was stopped here. Please connect it again.')) . ' <code>' . e($error) . '</code></p>', 400, ['Content-Type' => 'text/html; charset=utf-8']);
        if ($r->get('response_type') !== 'code') {
            return $back('unsupported_response_type', 'Only response_type=code is supported.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $r->get('code_challenge')) || $r->get('code_challenge_method') !== 'S256') {
            return $back('invalid_request', 'PKCE is missing (code_challenge with the S256 method).');
        }
        if ($this->app->settings()->bool('claude_apps_only') && !self::isClaudeHost(self::host($redirectUri))) {
            return new Response('<!doctype html><meta charset="utf-8"><title>' . e(t('Invalid sign-in request')) . '</title><p style="font:16px system-ui;margin:3em">'
                . e(t('This website lets only Claude’s own apps connect. If you need to connect another application, ask the administrator of the website.')) . '</p>', 403, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        // 3.3.4 (N14): each request waits under its own nonce; the consent form names it, so a later request in the same
        // session cannot take the place of the one on the screen
        $nonce = bin2hex(random_bytes(16));
        $pending = self::pendingRequests($this->app->session);
        $pending[$nonce] = [
            'client_id' => $client['client_id'], 'nazev' => $client['nazev'], 'redirect_uri' => $redirectUri, 'state' => mb_substr($r->get('state'), 0, 500),
            'challenge' => $r->get('code_challenge'), 'cas' => time(),
        ];
        $this->app->session->set(self::PENDING_KEY, array_slice($pending, -self::PENDING_MAX, null, true));

        return Response::redirect($this->app->url('admin.php?action=oauth&request=' . $nonce));
    }

    /**
     * Sign-in requests waiting for consent in this session, oldest first, without the expired ones.
     *
     * @return array<string, array<string, mixed>> nonce => request
     */
    public static function pendingRequests(Session $session): array
    {
        $all = $session->get(self::PENDING_KEY);
        $out = [];
        foreach (is_array($all) ? $all : [] as $nonce => $request) {
            if (is_string($nonce) && is_array($request) && time() - (int) ($request['cas'] ?? 0) <= self::PENDING_LIFETIME) {
                /** @var array<string, mixed> $request */
                $out[$nonce] = $request;
            }
        }

        return $out;
    }

    /**
     * One waiting request: the one with this nonce, or with an empty nonce the newest (the page after sign-in, which does
     * not know the nonce). The consent itself is only ever accepted for a named nonce (Admin\Kernel::handleOAuthConsent).
     *
     * @return array{0: string, 1: array<string, mixed>}|null nonce and request
     */
    public static function pendingRequest(Session $session, string $nonce): ?array
    {
        $pending = self::pendingRequests($session);
        if ($nonce === '') {
            $last = array_key_last($pending);

            return $last === null ? null : [$last, $pending[$last]];
        }

        return isset($pending[$nonce]) ? [$nonce, $pending[$nonce]] : null;
    }

    /** Removes one request after the person allowed or denied it; the others keep waiting for their own consent. */
    public static function forgetRequest(Session $session, string $nonce): void
    {
        $pending = self::pendingRequests($session);
        unset($pending[$nonce]);
        $session->set(self::PENDING_KEY, $pending === [] ? null : $pending);
    }

    /** Whether the host of a redirect_uri belongs to Claude's own apps (CLAUDE_HOSTS, subdomains included). */
    public static function isClaudeHost(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');
        foreach (self::CLAUDE_HOSTS as $known) {
            if ($host === $known || str_ends_with($host, '.' . $known)) {
                return true;
            }
        }

        return false;
    }

    /** The host of an address as the person should read it ('' when there is none). */
    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /** Whether a person on this site has allowed this client before (3.3.4) – a client nobody approved is shown as new. */
    public function isApprovedClient(string $clientId): bool
    {
        return ($this->client($clientId)['approved'] ?? null) !== null;
    }

    /**
     * User consent (called by the administration after confirmation): a one-time code valid for 10 minutes and a return
     * to the application.
     *
     * @param array<string, mixed> $pending parameters saved in authorize()
     */
    public function issueCode(array $pending, int $idu, string $access = 'full'): string
    {
        $code = bin2hex(random_bytes(32));
        $this->app->db()->run('UPDATE {oauth_klienti} SET approved = ? WHERE client_id = ? AND approved IS NULL', [date('Y-m-d H:i:s'), (string) $pending['client_id']]);
        $this->app->db()->insert('oauth_kody', ['otisk' => hash('sha256', $code), 'client_id' => $pending['client_id'], 'idu' => $idu, 'presmerovani' => $pending['redirect_uri'],
            'vyzva' => $pending['challenge'], 'access' => self::access($access), 'expirace' => date('Y-m-d H:i:s', time() + self::CODE_LIFETIME)]);

        return self::withParams((string) $pending['redirect_uri'], ['code' => $code, 'state' => (string) $pending['state'], 'iss' => $this->issuer()]);
    }

    /** @param array<string, mixed> $pending */
    public function deny(array $pending): string
    {
        return self::withParams((string) $pending['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user did not allow access.', 'state' => (string) $pending['state'], 'iss' => $this->issuer()]);
    }

    private function token(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return $this->error('invalid_request', 'A token is requested with the POST method.', 405);
        }
        [$clientId, $secret] = $this->clientCredentials();
        $client = $this->client($clientId);
        if ($client === null || ($client['tajemstvi'] !== '' && !hash_equals((string) $client['tajemstvi'], hash('sha256', $secret)))) {
            return $this->error('invalid_client', 'Unknown client or wrong client secret.', 401);
        }
        $db = $this->app->db();
        $now = date('Y-m-d H:i:s');
        if ($r->post('grant_type') === 'authorization_code') {
            $code = $db->one('SELECT * FROM {oauth_kody} WHERE otisk = ?', [hash('sha256', $r->post('code'))]);
            // the code is valid only once: of two requests racing with it, only the one whose DELETE removed the row goes on
            // (3.3.4, N13); it is deleted even when the request fails
            $redeemed = $code !== null && $db->delete('oauth_kody', ['otisk' => $code['otisk']]) === 1;
            $verifier = $r->post('code_verifier');
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            // RFC 7636: the verifier has 43–128 unreserved characters (N20)
            if (!$redeemed || $code['expirace'] < $now || $code['client_id'] !== $clientId || $code['presmerovani'] !== $r->post('redirect_uri')
                || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) || !hash_equals((string) $code['vyzva'], $challenge)) {
                return $this->error('invalid_grant', 'The code is invalid, expired or already used, or the redirect URI or PKCE does not match.');
            }

            return $this->issueTokens($db, (int) $code['idu'], $client, (string) $code['access']);
        }
        if ($r->post('grant_type') === 'refresh_token') {
            return $this->refresh($db, $client, $r->post('refresh_token'));
        }

        return $this->error('unsupported_grant_type', 'authorization_code and refresh_token are supported.');
    }

    /** @param array<string, mixed> $client */
    private function issueTokens(Db $db, int $idu, array $client, string $level): Response
    {
        if (!self::isActiveUser($db, $idu)) {
            return $this->error('invalid_grant', 'The account that allowed the application no longer has access.');
        }
        $pair = ['kaleta_oa_' . bin2hex(random_bytes(24)), 'kaleta_or_' . bin2hex(random_bytes(24))];
        self::storeTokens($db, $idu, $client, $level, $pair);

        return $this->tokenResponse($db, $pair);
    }

    /**
     * Refresh with rotation (3.3.4, N13). The live refresh token is exchanged by the one request whose DELETE removed it, in
     * one transaction with the record of the rotation and the new pair – a parallel request waits for the row lock, then
     * finds the rotation. Within REFRESH_GRACE seconds the rotated token returns the same pair (derived from the token and
     * the salt of the record, see successor()); afterwards it counts as stolen and the client's tokens for the user go.
     *
     * @param array<string, mixed> $client
     */
    private function refresh(Db $db, array $client, string $token): Response
    {
        $now = date('Y-m-d H:i:s');
        $hash = hash('sha256', $token);
        $invalid = fn (): Response => $this->error('invalid_grant', 'The refresh token is invalid or expired – connect the application again.');
        $refresh = $db->one("SELECT * FROM {api_tokeny} WHERE otisk = ? AND druh = 'obnova'", [$hash]);
        if ($refresh !== null) {
            if ($refresh['klient'] !== $client['client_id'] || (string) $refresh['expirace'] < $now) {
                return $invalid();
            }
            if (!self::isActiveUser($db, (int) $refresh['idu'])) {
                return $this->error('invalid_grant', 'The account that allowed the application no longer has access.');
            }
            $salt = bin2hex(random_bytes(32));
            $pair = self::successor($token, $salt);
            $rotated = $db->transaction(function (Db $db) use ($refresh, $hash, $salt, $client, $pair, $now): bool {
                if ($db->delete('api_tokeny', ['idt' => (int) $refresh['idt'], 'druh' => 'obnova']) !== 1) {
                    return false; // another request rotated it a moment ago
                }
                $db->insert('oauth_rotated', ['hash' => $hash, 'client_id' => (string) $client['client_id'], 'idu' => (int) $refresh['idu'], 'salt' => $salt,
                    'rotated_at' => $now, 'expires_at' => (string) $refresh['expirace']]);
                self::storeTokens($db, (int) $refresh['idu'], $client, (string) $refresh['access'], $pair); // the access chosen at consent stays

                return true;
            });
            if ($rotated) {
                return $this->tokenResponse($db, $pair);
            }
        }
        $record = $db->one('SELECT * FROM {oauth_rotated} WHERE hash = ?', [$hash]);
        if ($record === null || $record['client_id'] !== $client['client_id'] || (string) $record['expires_at'] < $now) {
            return $invalid();
        }
        if ((int) strtotime((string) $record['rotated_at']) >= time() - self::REFRESH_GRACE) {
            $pair = self::successor($token, (string) $record['salt']);
            // the same pair, as long as it was not revoked in between (a disconnect, a password reset)
            $alive = $db->value("SELECT 1 FROM {api_tokeny} WHERE otisk = ? AND druh = 'pristup' AND expirace > ?", [hash('sha256', $pair[0]), $now]) !== null;

            return $alive ? $this->tokenResponse($db, $pair) : $invalid();
        }
        // a refresh token used again after its rotation: whoever holds it is not the client that rotated it, or the client
        // lost track – either way the connection ends and the person connects the application again
        $revoked = $db->delete('api_tokeny', ['klient' => (string) $client['client_id'], 'idu' => (int) $record['idu']]);
        $db->delete('oauth_rotated', ['client_id' => (string) $client['client_id'], 'idu' => (int) $record['idu']]);
        Events::record($db, 'security.token_reuse', 'warning', t('A replaced refresh token of the connected application “%s” was used again, so all its tokens were revoked. If it was you, connect the application again.', (string) $client['nazev']),
            ['client' => (string) $client['client_id'], 'idu' => (int) $record['idu'], 'revoked' => $revoked]);

        return $invalid();
    }

    /**
     * The pair a refresh token is rotated into: derived from the token and a random salt, so a retry within the grace
     * period gets the same pair back while the pair itself is stored only as hashes.
     *
     * @return array{0: string, 1: string} access token and refresh token
     */
    private static function successor(string $token, string $salt): array
    {
        return ['kaleta_oa_' . substr(hash_hmac('sha256', 'access|' . $token, $salt), 0, 48), 'kaleta_or_' . substr(hash_hmac('sha256', 'refresh|' . $token, $salt), 0, 48)];
    }

    private static function isActiveUser(Db $db, int $idu): bool
    {
        return $db->one('SELECT idu FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$idu]) !== null;
    }

    /**
     * @param array<string, mixed> $client
     * @param array{0: string, 1: string} $pair access token and refresh token
     */
    private static function storeTokens(Db $db, int $idu, array $client, string $level, array $pair): void
    {
        foreach ([[$pair[0], 'pristup', self::ACCESS_LIFETIME], [$pair[1], 'obnova', self::REFRESH_LIFETIME]] as [$token, $kind, $lifetime]) {
            $db->insert('api_tokeny', ['idu' => $idu, 'nazev' => (string) $client['nazev'], 'klient' => (string) $client['client_id'], 'druh' => $kind, 'access' => self::access($level),
                'expirace' => date('Y-m-d H:i:s', time() + $lifetime), 'otisk' => hash('sha256', $token), 'vytvoren' => date('Y-m-d H:i:s')]);
        }
    }

    /** @param array{0: string, 1: string} $pair */
    private function tokenResponse(Db $db, array $pair): Response
    {
        // cleanup of expired tokens, codes and rotation records
        $db->run("DELETE FROM {api_tokeny} WHERE druh <> 'token' AND expirace < ?", [date('Y-m-d H:i:s')]);
        $db->run('DELETE FROM {oauth_kody} WHERE expirace < ?', [date('Y-m-d H:i:s')]);
        $db->run('DELETE FROM {oauth_rotated} WHERE expires_at < ?', [date('Y-m-d H:i:s')]);

        return $this->json(['access_token' => $pair[0], 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_LIFETIME, 'refresh_token' => $pair[1], 'scope' => 'mcp']);
    }

    /**
     * Registration housekeeping (3.3.4, N66, the daily job "security"): clients nobody ever approved that have no token and
     * no code are deleted a day after their registration. A client approved once stays – Claude keeps its client_id and
     * signs in with it again when its tokens have run out.
     */
    public static function purgeUnusedClients(Db $db): int
    {
        $db->run('DELETE FROM {oauth_rotated} WHERE expires_at < ?', [date('Y-m-d H:i:s')]);

        return $db->run('DELETE FROM {oauth_klienti} WHERE approved IS NULL AND vytvoren < ?
            AND client_id NOT IN (SELECT klient FROM {api_tokeny} WHERE klient IS NOT NULL) AND client_id NOT IN (SELECT client_id FROM {oauth_kody})',
            [date('Y-m-d H:i:s', time() - 86400)])->rowCount();
    }

    /** @return array{0: string, 1: string} client_id and secret from the Basic header or from the form */
    private function clientCredentials(): array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Basic\s+(\S+)$/i', $header, $m) && str_contains((string) base64_decode($m[1], true), ':')) {
            [$id, $secret] = explode(':', (string) base64_decode($m[1], true), 2);

            return [rawurldecode($id), rawurldecode($secret)];
        }

        return [$this->app->request->post('client_id'), $this->app->request->post('client_secret')];
    }

    /** @return array<string, mixed>|null */
    private function client(string $clientId): ?array
    {
        return preg_match(self::CLIENT_PATTERN, $clientId) ? $this->app->db()->one('SELECT * FROM {oauth_klienti} WHERE client_id = ?', [$clientId]) : null;
    }

    /** A known connection access (Mcp\Catalog::CONNECTION_ACCESS); anything else is read-only. */
    public static function access(string $access): string
    {
        return isset(\Kaleta\Mcp\Catalog::CONNECTION_ACCESS[$access]) ? $access : 'read';
    }

    /**
     * Is the redirect_uri of a sign-in request one the client registered (3.8)? Exactly – or, for a loopback address, with
     * any port: RFC 8252 §7.3 requires it for 127.0.0.1 and [::1], and Claude asks the same for localhost, because Claude
     * Code listens for the answer on a port the system gives it each time. Scheme, host, path and query must still match.
     *
     * @param array<mixed> $registered the client's registered redirect_uris
     */
    public static function redirectAllowed(string $requested, array $registered): bool
    {
        if (in_array($requested, $registered, true)) {
            return true;
        }
        $withoutPort = function (string $url): ?string {
            // the port, when there is one, is a plain number 1–65535 (no :0, :01, :+1 or an empty port – N38-4)
            if (preg_match('#^http://(?:localhost|127\.0\.0\.1|\[::1\])(?::([1-9][0-9]{0,4}))?(?:[/?]|$)#D', $url, $m) !== 1 || (int) ($m[1] ?? 1) > 65535) {
                return null;
            }
            $parts = self::isValidRedirectUri($url) ? parse_url($url) : false;
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || !in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true) || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            return 'http://' . $parts['host'] . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        };
        $loopback = $withoutPort($requested);

        return $loopback !== null && in_array($loopback, array_map(fn (mixed $uri): ?string => is_string($uri) ? $withoutPort($uri) : null, $registered), true);
    }

    public static function isValidRedirectUri(string $url): bool
    {
        if (strlen($url) > 500 || str_contains($url, '#') || preg_match('/[\s<>"\\\\\x00-\x1f\x7f]/', $url)) { // no control characters (N38-4: a NUL after the port)
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['host']) && (($parts['scheme'] ?? '') === 'https'
            || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)));
    }

    /** @param array<string, string> $params */
    private static function withParams(string $url, array $params): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(array_filter($params, fn (string $h): bool => $h !== ''));
    }

    /** @return array<string, string> */
    private static function cors(): array
    {
        return ['Access-Control-Allow-Origin' => '*'];
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + self::cors());
    }

    private function error(string $code, string $description, int $status = 400): Response
    {
        return $this->json(['error' => $code, 'error_description' => $description], $status);
    }
}
