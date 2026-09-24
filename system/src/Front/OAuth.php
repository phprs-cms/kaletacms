<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Response;

/**
 * OAuth 2.1 pro konektor Claude (a další klienty MCP) podle specifikace autorizace MCP:
 *   /.well-known/oauth-protected-resource   metadata chráněného zdroje (RFC 9728) – kdo vydává tokeny pro /mcp
 *   /.well-known/oauth-authorization-server metadata autorizačního serveru (RFC 8414)
 *   /oauth/register                         dynamická registrace klienta (RFC 7591)
 *   /oauth/authorize                        začátek přihlášení → souhlas v administraci (admin.php?akce=oauth)
 *   /oauth/token                            výměna kódu za tokeny (PKCE S256) a obnova tokenu
 *
 * Aplikace dostane stejná práva jako uživatel, který ji povolil. Přístupový token platí hodinu, obnovovací 30 dní a při
 * každém použití se vymění za nový. Tokeny leží v ka_api_tokeny (jen otisky) – odpojení aplikace v Můj účet je smaže.
 * OAuth předpokládá web v kořeni domény (metadata jsou na /.well-known/); v podsložce zůstává přihlášení osobním tokenem.
 */
final class OAuth
{
    public const int PLATNOST_PRISTUPU = 3600;
    public const int PLATNOST_OBNOVY = 30 * 86400;
    private const int PLATNOST_KODU = 600;
    private const string VZOR_KLIENTA = '/^[a-f0-9]{32}$/';

    public function __construct(private readonly App $app)
    {
    }

    /** Obslouží adresu OAuth, nebo vrátí null, když cesta k OAuth nepatří. */
    public function handle(string $cesta): ?Response
    {
        $r = $this->app->request;
        $jeOAuth = str_starts_with($cesta, '/.well-known/oauth-') || in_array($cesta, ['/oauth/register', '/oauth/authorize', '/oauth/token'], true);
        if (!$jeOAuth) {
            return null;
        }
        if (!\Kaleta\Core\Rozsireni::je($this->app->settings(), 'claude')) {
            return Response::json(['error' => 'not_found', 'error_description' => 'Napojení na Claude je na tomto webu vypnuté.'], 404);
        }
        $mistni = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$mistni) {
            return Response::json(['error' => 'invalid_request', 'error_description' => 'OAuth je dostupné jen přes HTTPS.'], 403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            return new Response('', 204, self::cors() + ['Access-Control-Allow-Methods' => 'GET, POST, OPTIONS', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, MCP-Protocol-Version']);
        }

        return match (true) {
            str_starts_with($cesta, '/.well-known/oauth-protected-resource') => $this->json($this->metadataZdroje()),
            str_starts_with($cesta, '/.well-known/oauth-authorization-server') => $this->json($this->metadataServeru()),
            $cesta === '/oauth/register' => $this->registrace(),
            $cesta === '/oauth/authorize' => $this->autorizace(),
            default => $this->token(),
        };
    }

    public function vydavatel(): string
    {
        return $this->app->request->origin() . rtrim($this->app->url(''), '/');
    }

    /** Adresa metadat chráněného zdroje – posílá ji MCP v hlavičce WWW-Authenticate. */
    public function adresaMetadat(): string
    {
        return $this->vydavatel() . '/.well-known/oauth-protected-resource';
    }

    /** @return array<string, mixed> */
    private function metadataZdroje(): array
    {
        return ['resource' => $this->vydavatel() . '/mcp', 'authorization_servers' => [$this->vydavatel()], 'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'], 'resource_name' => $this->app->settings()->get('nazev_webu')];
    }

    /** @return array<string, mixed> */
    private function metadataServeru(): array
    {
        $v = $this->vydavatel();

        return [
            'issuer' => $v, 'authorization_endpoint' => $v . '/oauth/authorize', 'token_endpoint' => $v . '/oauth/token', 'registration_endpoint' => $v . '/oauth/register',
            'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'], 'scopes_supported' => ['mcp'],
            'service_documentation' => 'https://kaletacms.com',
        ];
    }

    /** Dynamická registrace klienta (RFC 7591): Claude si tak sám založí client_id se svou adresou pro návrat. */
    private function registrace(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return $this->chyba('invalid_request', 'Registrace se posílá metodou POST.', 405);
        }
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        if ($antispam->pocet($r->ip(), 'oauth-registrace', 0, 60) >= 20) {
            return $this->chyba('invalid_request', 'Příliš mnoho registrací z této adresy. Zkuste to za hodinu.', 429);
        }
        $antispam->zapis($r->ip(), 'oauth-registrace', 0);
        $data = json_decode((string) file_get_contents('php://input'), true);
        $adresy = is_array($data['redirect_uris'] ?? null) ? array_values(array_filter($data['redirect_uris'], 'is_string')) : [];
        if ($adresy === [] || count($adresy) > 5 || array_filter($adresy, fn (string $a): bool => !self::platnaAdresaNavratu($a)) !== []) {
            return $this->chyba('invalid_redirect_uri', 'redirect_uris: 1–5 adres https:// (nebo http://localhost pro aplikace v počítači) bez části #.');
        }
        $zpusob = in_array($data['token_endpoint_auth_method'] ?? 'none', ['none', 'client_secret_post', 'client_secret_basic'], true) ? (string) ($data['token_endpoint_auth_method'] ?? 'none') : 'none';
        $clientId = bin2hex(random_bytes(16));
        $tajemstvi = $zpusob === 'none' ? '' : bin2hex(random_bytes(32));
        $nazev = mb_substr(trim(strip_tags((string) ($data['client_name'] ?? ''))), 0, 100) ?: 'Aplikace MCP';
        $this->app->db()->insert('oauth_klienti', ['client_id' => $clientId, 'tajemstvi' => $tajemstvi === '' ? '' : hash('sha256', $tajemstvi), 'nazev' => $nazev,
            'presmerovani' => (string) json_encode($adresy, JSON_UNESCAPED_SLASHES), 'vytvoren' => date('Y-m-d H:i:s')]);

        return $this->json(['client_id' => $clientId, 'client_id_issued_at' => time(), 'client_name' => $nazev, 'redirect_uris' => $adresy,
            'token_endpoint_auth_method' => $zpusob, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]
            + ($tajemstvi !== '' ? ['client_secret' => $tajemstvi, 'client_secret_expires_at' => 0] : []), 201);
    }

    /**
     * Začátek přihlášení: ověří klienta a adresu návratu, parametry uloží do relace a pošle uživatele na souhlas do administrace
     * (tam se případně nejdřív přihlásí). Neplatný klient nebo adresa návratu se nikam nepřesměrovávají (otevřené přesměrování).
     */
    private function autorizace(): Response
    {
        $r = $this->app->request;
        $klient = $this->klient($r->get('client_id'));
        $navrat = $r->get('redirect_uri');
        if ($klient === null || !in_array($navrat, json_decode((string) $klient['presmerovani'], true) ?: [], true)) {
            return new Response('<!doctype html><meta charset="utf-8"><title>' . e(t('Neplatná žádost o přihlášení')) . '</title><p style="font:16px system-ui;margin:3em">'
                . e(t('Aplikace se na tomto webu neohlásila správně (neznámý klient nebo adresa pro návrat). Připojte ji prosím znovu.')) . '</p>', 400, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $zpet = fn (string $chyba, string $popis): Response => Response::redirect(self::sParametry($navrat, ['error' => $chyba, 'error_description' => $popis, 'state' => $r->get('state'), 'iss' => $this->vydavatel()]));
        if ($r->get('response_type') !== 'code') {
            return $zpet('unsupported_response_type', 'Podporované je jen response_type=code.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $r->get('code_challenge')) || $r->get('code_challenge_method') !== 'S256') {
            return $zpet('invalid_request', 'Chybí PKCE (code_challenge s metodou S256).');
        }
        $this->app->session->set('oauth_ceka', [
            'client_id' => $klient['client_id'], 'nazev' => $klient['nazev'], 'redirect_uri' => $navrat, 'state' => mb_substr($r->get('state'), 0, 500),
            'challenge' => $r->get('code_challenge'), 'cas' => time(),
        ]);

        return Response::redirect($this->app->url('admin.php?akce=oauth'));
    }

    /**
     * Souhlas uživatele (volá administrace po potvrzení): jednorázový kód na 10 minut a návrat do aplikace.
     *
     * @param array<string, mixed> $ceka parametry uložené v autorizace()
     */
    public function vydejKod(array $ceka, int $idu): string
    {
        $kod = bin2hex(random_bytes(32));
        $this->app->db()->insert('oauth_kody', ['otisk' => hash('sha256', $kod), 'client_id' => $ceka['client_id'], 'idu' => $idu, 'presmerovani' => $ceka['redirect_uri'],
            'vyzva' => $ceka['challenge'], 'expirace' => date('Y-m-d H:i:s', time() + self::PLATNOST_KODU)]);

        return self::sParametry((string) $ceka['redirect_uri'], ['code' => $kod, 'state' => (string) $ceka['state'], 'iss' => $this->vydavatel()]);
    }

    /** @param array<string, mixed> $ceka */
    public function odmitnuti(array $ceka): string
    {
        return self::sParametry((string) $ceka['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'Uživatel přístup nepovolil.', 'state' => (string) $ceka['state'], 'iss' => $this->vydavatel()]);
    }

    private function token(): Response
    {
        $r = $this->app->request;
        if (!$r->isPost()) {
            return $this->chyba('invalid_request', 'Token se žádá metodou POST.', 405);
        }
        [$clientId, $tajemstvi] = $this->prihlaseniKlienta();
        $klient = $this->klient($clientId);
        if ($klient === null || ($klient['tajemstvi'] !== '' && !hash_equals((string) $klient['tajemstvi'], hash('sha256', $tajemstvi)))) {
            return $this->chyba('invalid_client', 'Neznámý klient nebo špatné tajemství klienta.', 401);
        }
        $db = $this->app->db();
        $ted = date('Y-m-d H:i:s');
        if ($r->post('grant_type') === 'authorization_code') {
            $kod = $db->one('SELECT * FROM {oauth_kody} WHERE otisk = ?', [hash('sha256', $r->post('code'))]);
            if ($kod !== null) {
                $db->delete('oauth_kody', ['otisk' => $kod['otisk']]); // kód platí jen jednou
            }
            $vyzva = rtrim(strtr(base64_encode(hash('sha256', $r->post('code_verifier'), true)), '+/', '-_'), '=');
            if ($kod === null || $kod['expirace'] < $ted || $kod['client_id'] !== $clientId || $kod['presmerovani'] !== $r->post('redirect_uri') || !hash_equals((string) $kod['vyzva'], $vyzva)) {
                return $this->chyba('invalid_grant', 'Kód je neplatný, prošlý, už použitý, nebo nesedí adresa návratu či PKCE.');
            }

            return $this->vydejTokeny($db, (int) $kod['idu'], $klient);
        }
        if ($r->post('grant_type') === 'refresh_token') {
            $obnova = $db->one("SELECT * FROM {api_tokeny} WHERE otisk = ? AND druh = 'obnova'", [hash('sha256', $r->post('refresh_token'))]);
            if ($obnova === null || $obnova['klient'] !== $clientId || (string) $obnova['expirace'] < $ted) {
                return $this->chyba('invalid_grant', 'Obnovovací token je neplatný nebo prošlý – připojte aplikaci znovu.');
            }
            $db->delete('api_tokeny', ['idt' => (int) $obnova['idt']]); // rotace: starý obnovovací token končí

            return $this->vydejTokeny($db, (int) $obnova['idu'], $klient);
        }

        return $this->chyba('unsupported_grant_type', 'Podporované je authorization_code a refresh_token.');
    }

    /** @param array<string, mixed> $klient */
    private function vydejTokeny(Db $db, int $idu, array $klient): Response
    {
        $uzivatel = $db->one('SELECT idu FROM {uzivatele} WHERE idu = ? AND blokovat = 0', [$idu]);
        if ($uzivatel === null) {
            return $this->chyba('invalid_grant', 'Účet, který aplikaci povolil, už nemá přístup.');
        }
        $pristup = 'kaleta_oa_' . bin2hex(random_bytes(24));
        $obnova = 'kaleta_or_' . bin2hex(random_bytes(24));
        foreach ([[$pristup, 'pristup', self::PLATNOST_PRISTUPU], [$obnova, 'obnova', self::PLATNOST_OBNOVY]] as [$token, $druh, $platnost]) {
            $db->insert('api_tokeny', ['idu' => $idu, 'nazev' => $klient['nazev'], 'klient' => $klient['client_id'], 'druh' => $druh,
                'expirace' => date('Y-m-d H:i:s', time() + $platnost), 'otisk' => hash('sha256', $token), 'vytvoren' => date('Y-m-d H:i:s')]);
        }
        // úklid prošlých tokenů a kódů
        $db->run("DELETE FROM {api_tokeny} WHERE druh <> 'token' AND expirace < ?", [date('Y-m-d H:i:s')]);
        $db->run('DELETE FROM {oauth_kody} WHERE expirace < ?', [date('Y-m-d H:i:s')]);

        return $this->json(['access_token' => $pristup, 'token_type' => 'Bearer', 'expires_in' => self::PLATNOST_PRISTUPU, 'refresh_token' => $obnova, 'scope' => 'mcp']);
    }

    /** @return array{0: string, 1: string} client_id a tajemství z hlavičky Basic nebo z formuláře */
    private function prihlaseniKlienta(): array
    {
        $hlavicka = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Basic\s+(\S+)$/i', $hlavicka, $m) && str_contains((string) base64_decode($m[1], true), ':')) {
            [$id, $tajemstvi] = explode(':', (string) base64_decode($m[1], true), 2);

            return [rawurldecode($id), rawurldecode($tajemstvi)];
        }

        return [$this->app->request->post('client_id'), $this->app->request->post('client_secret')];
    }

    /** @return array<string, mixed>|null */
    private function klient(string $clientId): ?array
    {
        return preg_match(self::VZOR_KLIENTA, $clientId) ? $this->app->db()->one('SELECT * FROM {oauth_klienti} WHERE client_id = ?', [$clientId]) : null;
    }

    public static function platnaAdresaNavratu(string $adresa): bool
    {
        if (strlen($adresa) > 500 || str_contains($adresa, '#') || preg_match('/[\s<>"\\\\]/', $adresa)) {
            return false;
        }
        $casti = parse_url($adresa);

        return is_array($casti) && isset($casti['host']) && (($casti['scheme'] ?? '') === 'https'
            || (($casti['scheme'] ?? '') === 'http' && in_array($casti['host'], ['localhost', '127.0.0.1', '[::1]'], true)));
    }

    /** @param array<string, string> $parametry */
    private static function sParametry(string $adresa, array $parametry): string
    {
        return $adresa . (str_contains($adresa, '?') ? '&' : '?') . http_build_query(array_filter($parametry, fn (string $h): bool => $h !== ''));
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

    private function chyba(string $kod, string $popis, int $status = 400): Response
    {
        return $this->json(['error' => $kod, 'error_description' => $popis], $status);
    }
}
