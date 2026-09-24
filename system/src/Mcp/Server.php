<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Admin\Protokol;
use Kaleta\Core\App;
use Kaleta\Core\Response;
use Kaleta\Core\Rozsireni;

/**
 * MCP server (Model Context Protocol, přenos "Streamable HTTP") na adrese /mcp.
 * Přes něj umí Claude pracovat s webem: číst a psát stránky a novinky, spravovat kategorie a tvořit šablony webu.
 *
 * Přihlášení: hlavička "Authorization: Bearer <token>"; token si uživatel vytvoří v nabídce Můj účet.
 * Claude pak jedná s právy tohoto uživatele (autor / redaktor / administrátor). Rozšíření je ve výchozím stavu vypnuté.
 */
final class Server
{
    private const string PROTOKOL = '2025-03-26';

    public function __construct(private readonly App $app)
    {
    }

    public function handle(): Response
    {
        $r = $this->app->request;
        if (!Rozsireni::je($this->app->settings(), 'claude')) {
            return Response::json(['chyba' => 'Napojení na Claude je vypnuté (nabídka Rozšíření).'], 404);
        }
        $mistni = in_array((string) parse_url($r->origin(), PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
        if (!$r->isHttps() && !$mistni) {
            return Response::json(['chyba' => 'MCP je dostupné jen přes HTTPS.'], 403);
        }
        if (!$r->isPost()) {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $user = $this->uzivatel();
        if ($user === null) {
            return new Response(json_encode(['chyba' => 'Neplatný nebo chybějící token.']), 401, ['Content-Type' => 'application/json', 'WWW-Authenticate' => 'Bearer']);
        }
        $this->app->auth()->prihlasJako($user);

        $zprava = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($zprava)) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Neplatný JSON.']], 400);
        }
        // dávka zpráv i jediná zpráva
        $davka = array_is_list($zprava) ? $zprava : [$zprava];
        $odpovedi = array_values(array_filter(array_map($this->zpracuj(...), $davka)));
        if ($odpovedi === []) {
            return new Response('', 202);
        }

        return Response::json(array_is_list($zprava) ? $odpovedi : $odpovedi[0]);
    }

    /** @param array<string, mixed> $z @return array<string, mixed>|null null = oznámení bez odpovědi */
    private function zpracuj(array $z): ?array
    {
        $id = $z['id'] ?? null;
        $metoda = (string) ($z['method'] ?? '');
        if ($id === null) {
            return null;
        }
        $ok = fn (array $vysledek): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $vysledek];
        $nastroje = new Nastroje($this->app);

        return match ($metoda) {
            'initialize' => $ok([
                'protocolVersion' => is_string($z['params']['protocolVersion'] ?? null) ? $z['params']['protocolVersion'] : self::PROTOKOL,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'Kaleta – ' . $this->app->settings()->get('nazev_webu'), 'version' => KALETA_VERSION],
                'instructions' => 'Firemní web na Kaletě. Texty piš v jazyce webu, stránky a novinky jako čisté sémantické HTML (p, h2, h3, ul, ol, blockquote, a, strong, em, figure/img, table). '
                    . 'STRÁNKY SKLÁDEJ VE BUILDERU: načti stavba_schema, pak stavba_z_html (sémantické HTML po sekcích + <style> s pravidly jedné třídy a tokeny var(--ka-…), žádné vložené styly); '
                    . 'drobné úpravy přes stavba_nacti a stavba_uloz, hotové sekce přes vloz_sekci, vzhled celého webu přes uprav_design_system. Stavba se ukládá jako koncept – pošli uživateli odkaz na náhled a publikuj až na jeho pokyn. '
                    . 'Nová novinka vzniká jako koncept; vydat ji může jen uživatel s právem vydávat a jen na výslovný pokyn. Nová stránka je skrytá, dokud ji uživatel výslovně nechce zveřejnit. '
                    . 'Před úpravou šablony si ji nejdřív zkopíruj a změny ukaž v náhledu. '
                    . 'HRANICE: přes toto napojení se mění jen obsah (stránky, novinky, kategorie) a VLASTNÍ šablony vzhledu. Kód systému (system/, admin.php, index.php), vestavěné šablony '
                    . 'ani databázi neupravuj a nenavrhuj obcházení – vlastní funkce CMS se nedělají, systém má být pro všechny stejný a aktualizovatelný. Šablona je jen prezentační vrstva: '
                    . 'vypisuje data, která dostane; nesmí číst soubory, volat databázi ani síť. Požaduje-li uživatel novou funkci systému, řekni mu, že ji má navrhnout autorům Kalety.',
            ]),
            'ping' => $ok([]),
            'tools/list' => $ok(['tools' => $nastroje->seznam()]),
            'tools/call' => $ok($this->zavolej($nastroje, (string) ($z['params']['name'] ?? ''), (array) ($z['params']['arguments'] ?? []))),
            default => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Neznámá metoda: ' . $metoda]],
        };
    }

    /** @param array<string, mixed> $argumenty @return array<string, mixed> */
    private function zavolej(Nastroje $nastroje, string $nazev, array $argumenty): array
    {
        try {
            $vysledek = $nastroje->zavolej($nazev, $argumenty);
            if ($nastroje->meni($nazev)) {
                Protokol::zapis($this->app, 'claude', $nazev, mb_substr((string) ($argumenty['titulek'] ?? $argumenty['nazev'] ?? $argumenty['sablona'] ?? $argumenty['id'] ?? ''), 0, 200));
                \Kaleta\Front\Cache::vymaz();
            }

            return ['content' => [['type' => 'text', 'text' => is_string($vysledek) ? $vysledek : json_encode($vysledek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]]];
        } catch (\InvalidArgumentException | \DomainException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
    }

    /** @return array<string, mixed>|null uživatel podle tokenu */
    private function uzivatel(): ?array
    {
        $hlavicka = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $db = $this->app->db();
        $ip = substr(hash('sha256', 'kaleta|' . $this->app->request->ip()), 0, 40);
        if ((int) $db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'mcp' AND ip_adresa = ? AND cas > NOW() - INTERVAL 15 MINUTE", [$ip]) >= 20) {
            return null;
        }
        if (!preg_match('/^Bearer\s+(kaleta_[a-f0-9]{48})$/', $hlavicka, $m)) {
            return null;
        }
        $token = $db->one('SELECT t.idt, u.* FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu WHERE t.otisk = ? AND u.blokovat = 0', [hash('sha256', $m[1])]);
        if ($token === null) {
            $db->insert('kontrola_ip', ['ip_adresa' => $ip, 'typ' => 'mcp', 'cas' => date('Y-m-d H:i:s')]);

            return null;
        }
        $db->run('UPDATE {api_tokeny} SET pouzit = NOW() WHERE idt = ?', [$token['idt']]);

        return $token;
    }
}
