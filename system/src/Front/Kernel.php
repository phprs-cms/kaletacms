<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\App;
use MiroCMS\Core\Jazyk;
use MiroCMS\Core\Response;
use MiroCMS\Core\Rozsireni;
use MiroCMS\Core\View;

/**
 * Veřejná část webu.
 *
 *   /                    hlavní stránka
 *   /clanek/<seo-link>   celý článek
 *   /rubrika/<seo-link>  výpis rubriky
 *   /hledani?q=...       vyhledávání
 *   /rss.xml             RSS kanál
 *   /stitek/<seo-link>   články se štítkem
 *   /autor/<id>          články autora
 *   /<adresa>            statická stránka
 *   robots.txt, sitemap.xml, llms.txt, feed.json... viz Seo
 */
final class Kernel
{
    private readonly View $view;
    private readonly Clanky $clanky;
    private readonly ?Ctenari $ctenari;

    /** Rubrika nebo stránka, kterou požadavek zobrazuje - přepínač jazyků podle ní najde protějšek v jiné verzi. */
    private ?array $protejsek = null;

    /** Požadovaná stránka výpisu je až za jeho koncem - odpoví se 404. */
    private bool $zaKoncem = false;

    public function __construct(private readonly App $app)
    {
        $app->request->setOrigin($app->settings()->get('adresa_webu'));
        $app->casovePasmo();
        // po aktualizaci systému (i automatické) se databáze upraví hned při první návštěvě, ne až po přihlášení administrátora
        if ($app->settings()->int('verze_db') < MIROCMS_VERZE_DB) {
            \MiroCMS\Core\Migrace::proved($app->db(), $app->settings());
        }
        // jazyková verze: /en/clanek/x -> jazyk "en", cesta "/clanek/x"; adresy z $app->url() pak dostávají předponu samy
        $jazyk = Jazyk::vychozi($app->settings());
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $app->request->path(), $m) && in_array($m[1], Jazyk::dalsi($app->settings()), true)) {
            $jazyk = $m[1];
            $app->jazykPrefix = $m[1];
            $app->request->setPath($m[2] ?? '/');
        }
        Jazyk::nastavWeb($app->settings(), $jazyk);
        $layout = $app->settings()->get('layout');
        // náhled jiné šablony (?sablona=slozka) - jen přihlášenému administrátorovi, např. při tvorbě šablony přes Claude
        $nahled = $app->request->get('sablona');
        if ($nahled !== '' && preg_match('/^[a-z0-9_-]+$/i', $nahled) && is_file(MIROCMS_ROOT . '/layout/' . $nahled . '/base.php') && $app->auth()->isAdmin()) {
            $layout = $nahled;
        }
        // nastavená šablona chybí (smazaná složka, zrušená vestavěná šablona „default“ před provedením migrace) - web se vykreslí výchozí
        if (!preg_match('/^[a-z0-9_-]+$/i', $layout) || !is_file(MIROCMS_ROOT . '/layout/' . $layout . '/base.php')) {
            $layout = Layouty::VYCHOZI;
        }
        // šablona se hledá nejdřív v layoutu webu, potom mezi systémovými - layout tak může přepsat cokoli
        $this->view = new View([MIROCMS_ROOT . '/layout/' . $layout, MIROCMS_SYSTEM . '/views/front']);
        $this->ctenari = Rozsireni::je($app->settings(), 'ctenari') ? new Ctenari($app) : null;
        $this->clanky = new Clanky($app->db(), $app->settings(), $app->request->basePath(), $this->ctenari === null ? null : $this->ctenari->zamkni(...));
    }

    public function handle(): Response
    {
        $request = $this->app->request;
        if ($request->path() === '/platba/stripe') {
            // webhook Stripe: ověřuje ho podpis zprávy, proto běží i při údržbě webu, bez cache a bez ohledu na jazykovou verzi
            return $request->isPost()
                ? (new Platby($this->app->db(), $this->app->settings()))->webhook((string) file_get_contents('php://input', false, null, 0, Platby::MAX_TELO + 1), (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''))
                : new Response("POST\n", 405, ['Content-Type' => 'text/plain; charset=utf-8', 'Allow' => 'POST']);
        }
        if ($this->app->settings()->bool('udrzba') && $request->path() !== '/mcp' && $this->app->auth()->user() === null) {
            return new Response('<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($this->app->settings()->get('nazev_webu')) . '</title>'
                . '<body style="font:18px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;padding:24px;text-align:center"><div><h1 style="font-size:28px">' . e($this->app->settings()->get('nazev_webu'))
                . '</h1><p>' . e($this->app->settings()->get('udrzba_text')) . '</p></div>', 503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '3600']);
        }
        // stará číselná adresa WordPressu /?p=123 (po importu): její cesta je hlavní stránka, která existuje vždy, takže by se na
        // přesměrování při chybě 404 nikdy nedostalo – hledá se proto podle parametru, ještě před cache
        if ($request->getInt('p') > 0 && $request->path() === '/' && Rozsireni::je($this->app->settings(), 'presmerovani')) {
            $cil = $this->app->db()->one('SELECT idp, na_adresu FROM {presmerovani} WHERE z_adresy = ?', ['?p=' . $request->getInt('p')]);
            if ($cil !== null) {
                $this->app->db()->run('UPDATE {presmerovani} SET pocet = pocet + 1 WHERE idp = ?', [$cil['idp']]);

                return Response::redirect(preg_match('#^https?://#i', $cil['na_adresu']) ? $cil['na_adresu'] : $this->app->url($cil['na_adresu']), 301);
            }
        }
        if (($zCache = Cache::nacti($this->app)) !== null) {
            return $zCache;
        }
        $path = $request->path();
        if ($path === '/' || $path === '/index.php') {
            return $this->hlavniStranka();
        }
        if (preg_match('#^/clanek/([a-z0-9-]+)$#', $path, $m)) {
            return $this->clanek($m[1]);
        }
        if (preg_match('#^/rubrika/([a-z0-9-]+)$#', $path, $m)) {
            return $this->rubrika($m[1]);
        }
        if (preg_match('#^/stitek/([a-z0-9-]+)$#', $path, $m)) {
            return $this->stitek($m[1]);
        }
        if ($path === '/hledani') {
            return $this->hledani();
        }
        if ($path === '/rss.xml') {
            return $this->rss();
        }
        $seo = new Seo($this->app);
        if ($path === '/robots.txt') {
            return new Response($seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/sitemap.xml') {
            return new Response(Cache::text($this->app, 'sitemap', $seo->sitemapXml(...)), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
        }
        if ($path === '/podcast.xml') {
            return new Response(Cache::text($this->app, 'podcast|' . Jazyk::sloupecWebu(), $seo->podcastXml(...)), 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
        }
        if ($path === '/sitemap-news.xml') {
            return new Response(Cache::text($this->app, 'sitemap-news', $seo->sitemapNewsXml(...)), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
        }
        if ($path === '/feed.json') {
            $json = Cache::text($this->app, 'feed.json|' . Jazyk::sloupecWebu(), fn (): string => (string) json_encode($seo->jsonFeed($this->clanky->naHlavniStranku(1, 20, true)[0]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return new Response($json, 200, ['Content-Type' => 'application/feed+json; charset=utf-8']);
        }
        $klicIndexNow = $this->app->settings()->get('indexnow_klic');
        if ($klicIndexNow !== '' && $path === '/' . $klicIndexNow . '.txt') {
            return new Response($klicIndexNow, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/souhlas' && $request->isPost()) {
            // evidence souhlasu s cookies: bez IP adresy, jen náhodný identifikátor z cookie návštěvníka
            $kategorie = implode(',', array_intersect(explode(',', $request->post('kategorie')), ['analytika', 'marketing'])) ?: 'nic';
            $antispam = new \MiroCMS\Core\Antispam($this->app->db(), $this->app->settings());
            if ($this->app->settings()->bool('cookies_evidence') && preg_match('/^[a-f0-9]{32}$/', $request->post('id')) && $antispam->pocet($request->ip(), 'souhlas', 0, 60) < 20) {
                $antispam->zapis($request->ip(), 'souhlas', 0);
                $this->app->db()->insert('souhlasy', ['id_souhlasu' => $request->post('id'), 'cas' => date('Y-m-d H:i:s'), 'kategorie' => $kategorie]);
            }

            return new Response('', 204);
        }
        if (str_starts_with($path, '/newsletter') && Rozsireni::je($this->app->settings(), 'newsletter')) {
            $newsletter = new Newsletter($this->app, $this->view);
            if ($path === '/newsletter' && $request->isPost()) {
                return $newsletter->prihlas();
            }
            if (preg_match('#^/newsletter/o/(\d+)\.gif$#', $path, $m)) {
                // otevření newsletteru: průhledný obrázek 1×1, počítá se jen souhrnné číslo
                $this->app->db()->run('UPDATE {newsletter} SET otevreno = otevreno + 1 WHERE idn = ?', [(int) $m[1]]);

                return new Response((string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
            }
            if (preg_match('#^/newsletter/k/(\d+)$#', $path, $m)) {
                // proklik z newsletteru: cíl musí nést platný podpis, jinak by adresa šla zneužít k přesměrování kamkoli
                $cil = $request->get('u');
                if (!hash_equals(\MiroCMS\Core\Rozesilka::podpis($this->app, $m[1] . '|' . $cil), $request->get('p'))) {
                    return Response::redirect($this->app->url(''));
                }
                $this->app->db()->run('UPDATE {newsletter} SET prokliku = prokliku + 1 WHERE idn = ?', [(int) $m[1]]);

                return Response::redirect($cil);
            }
            if (preg_match('#^/newsletter/(potvrdit|odhlasit)/([a-f0-9]{32})$#', $path, $m)) {
                [$nadpis, $text] = $m[1] === 'potvrdit' ? $newsletter->potvrd($m[2]) : $newsletter->odhlas($m[2]);

                return $this->stranka($nadpis, $this->view->render('zprava', ['nadpis' => $nadpis, 'text' => $text, 'url' => $this->app->url(...)]), ['noindex' => true]);
            }
        }
        if ($this->ctenari !== null && ($path === '/ctenar' || str_starts_with($path, '/ctenar/'))) {
            $vysledek = $this->ctenari->handle($path, $this->view);

            $odpoved = $vysledek instanceof Response ? $vysledek : $this->stranka($vysledek[0], $vysledek[1], ['noindex' => true]);

            // účet čtenáře (e-mail, předplatné, odkazy s tokenem) nepatří do mezipaměti prohlížeče ani proxy
            return new Response($odpoved->body, $odpoved->status, $odpoved->headers + ['Cache-Control' => 'no-store, private']);
        }
        if (preg_match('#^/zive/(\d+)\.json$#', $path, $m)) {
            // průběžné načítání nových zápisů živé reportáže (image/web.js)
            $clanek = $this->app->db()->one('SELECT idc, zive, pristup FROM {clanky} WHERE idc = ? AND visible = 1 AND datum <= NOW() AND zive > 0', [(int) $m[1]]);
            $this->ctenari?->meritClanek(true); // čtenář s článkem zdarma (měkký paywall) má nárok i na průběžné zápisy
            if ($clanek === null || ($this->ctenari !== null && !$this->ctenari->smiCist($clanek))) {
                return Response::json(['html' => '', 'bezi' => false], 404);
            }

            return Response::json(['html' => (new TypyObsahu($this->app))->ziveHtml($clanek, max(1, $request->getInt('od'))), 'bezi' => (int) $clanek['zive'] === 1]);
        }
        if (str_starts_with($path, '/push') || $path === '/manifest.webmanifest') {
            $odpoved = $this->push($path);
            if ($odpoved !== null) {
                return $odpoved;
            }
        }
        if (str_starts_with($path, '/api/') && Rozsireni::je($this->app->settings(), 'api')) {
            return (new Api($this->app, $this->clanky))->handle($path);
        }
        if ($path === '/mcp') {
            return (new \MiroCMS\Mcp\Server($this->app))->handle();
        }
        if (preg_match('#^/r/(\d+)$#', $path, $m)) {
            return (new Reklama($this->app))->proklik((int) $m[1]);
        }
        if ($path === '/ads.txt' && trim($this->app->settings()->get('ads_txt')) !== '') {
            return new Response($this->app->settings()->get('ads_txt') . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/komentar/nahlasit' && $request->isPost()) {
            return (new Interakce($this->app, $this->view))->nahlas();
        }
        if ($path === '/komentar/neupozornovat') {
            $ok = (new Interakce($this->app, $this->view))->neupozornovat();
            [$nadpis, $text] = $ok ? [t('Upozornění jsou vypnutá'), t('Na odpovědi k tomuto komentáři už vás e-mailem upozorňovat nebudeme.')] : [t('Odkaz neplatí'), t('Upozornění se nepodařilo vypnout.')];

            return $this->stranka($nadpis, $this->view->render('zprava', ['nadpis' => $nadpis, 'text' => $text, 'url' => $this->app->url(...)]), ['noindex' => true]);
        }
        if ($request->isPost() && in_array($path, ['/komentar', '/hodnoceni', '/anketa'], true)) {
            $interakce = new Interakce($this->app, $this->view);

            return match ($path) {
                '/komentar' => $interakce->ulozKomentar(),
                '/hodnoceni' => $interakce->ulozHodnoceni(),
                default => $interakce->ulozHlas(),
            };
        }
        if (preg_match('#^/archiv/(\d{4}-\d{2})$#', $path, $m)) {
            $strana = max(1, $request->getInt('strana', 1));
            [$clanky, $celkem] = $this->clanky->zMesice($m[1], $strana);
            $mesice = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
            $nadpis = t('Archiv') . ': ' . t($mesice[(int) substr($m[1], 5)]) . ' ' . substr($m[1], 0, 4);

            return $celkem === 0 ? $this->nenalezeno() : $this->stranka($nadpis, $this->view->render('vypis', ['rubrika' => ['nazev' => $nadpis, 'popis' => '']] + $this->proVypis($clanky, $celkem, $strana, 'archiv/' . $m[1])));
        }
        if (preg_match('#^/autor/(\d+)$#', $path, $m)) {
            return $this->autor((int) $m[1]);
        }
        if ($path === '/llms.txt' && $this->app->settings()->bool('llms_txt')) {
            return new Response(Cache::text($this->app, 'llms|' . Jazyk::sloupecWebu(), $seo->llmsTxt(...)), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if (preg_match('#^/clanek/([a-z0-9-]+)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_clanky')) {
            $clanek = $this->clanky->podleSeo($m[1]);

            return $clanek === null || (int) $clanek['typ_clanku'] === 2
                ? $this->nenalezeno()
                : new Response($seo->clanekMarkdown($clanek), 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
        }
        if ($path === '/ulohy') {
            // úlohy na pozadí pro cron: weby s malou návštěvností tak vydají naplánovaný článek a rozešlou oznámení včas
            $token = $this->app->settings()->get('ulohy_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return new Response(t('Neplatný token.') . "\n", 403, ['Content-Type' => 'text/plain; charset=utf-8']);
            }
            $hotovo = [];
            try {
                \MiroCMS\Core\Oznameni::zpracuj($this->app);
                $hotovo[] = 'oznameni';
                $hotovo[] = 'push:' . (new \MiroCMS\Core\Push($this->app->db(), $this->app->settings()))->rozesli();
                \MiroCMS\Core\Zaloha::automaticka($this->app->db(), $this->app->settings());
                $hotovo[] = 'zalohy';
                \MiroCMS\Core\Rozesilka::naPozadi($this->app);
                $hotovo[] = 'newsletter';
                $hotovo[] = 'posta:' . \MiroCMS\Core\Posta::zpracujFrontu($this->app->settings(), 30);
            } catch (\Throwable $e) {
                $hotovo[] = 'chyba: ' . $e->getMessage();
            }

            return new Response('OK ' . date('c') . ' ' . implode(', ', $hotovo) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        if ($path === '/stav.json') {
            $token = $this->app->settings()->get('stav_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return Response::json(['chyba' => 'Neplatný token.'], 403);
            }
            // monitoring dostává texty vždy česky - nesmí se měnit podle jazyka zobrazené verze webu
            $kontroly = Jazyk::docasne('cs', fn (): array => \MiroCMS\Core\Stav::kontroly($this->app));

            return Response::json(['stav' => \MiroCMS\Core\Stav::souhrn($kontroly), 'verze' => MIROCMS_VERSION, 'cas' => date('c'), 'kontroly' => $kontroly]);
        }

        $stranka = $this->app->db()->one('SELECT * FROM {stranky} WHERE seo_link = ? AND zobrazit = 1 AND jazyk = ?', [ltrim($path, '/'), Jazyk::sloupecWebu()]);
        if ($stranka !== null) {
            $this->protejsek = ['stranky', 'ids', $stranka, ''];
            if (($formular = $this->upravaNaMiste('stranka', $stranka, ltrim($path, '/'))) !== null) {
                return $this->stranka($stranka['titulek'], $formular, ['noindex' => true]);
            }

            return $this->stranka($stranka['titulek'], $this->view->render('stranka', ['stranka' => $stranka]), ['popis' => $stranka['popis']]);
        }

        return $this->nenalezeno();
    }

    /** Web Push: obsah posledního oznámení, přihlášení a zrušení odběru, manifest webové aplikace (kvůli iOS). */
    private function push(string $path): ?Response
    {
        $web = $this->app->settings();
        $push = new \MiroCMS\Core\Push($this->app->db(), $web);
        if (!$push->zapnuto()) {
            return null;
        }
        $koren = $this->app->request->origin() . $this->app->url('');
        $ikona = $web->get('favicon') === '' ? '' : (preg_match('#^https?://#i', $web->get('favicon')) ? $web->get('favicon') : rtrim($koren, '/') . '/' . ltrim($web->get('favicon'), '/'));
        if ($path === '/push.json') {
            return Response::json($push->zprava() + ['ikona' => $ikona]);
        }
        if ($path === '/manifest.webmanifest') {
            return new Response((string) json_encode([
                'name' => $web->get('nazev_webu'), 'short_name' => mb_substr($web->get('nazev_webu'), 0, 12), 'start_url' => $this->app->url(''), 'scope' => $this->app->url(''),
                'display' => 'standalone', 'background_color' => '#ffffff', 'theme_color' => $web->get('brand_akcent') ?: '#ffffff',
                'icons' => $ikona === '' ? [] : [['src' => $ikona, 'sizes' => '256x256', 'purpose' => 'any']],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, ['Content-Type' => 'application/manifest+json; charset=utf-8']);
        }
        if ($this->app->request->isPost() && in_array($path, ['/push/odber', '/push/zrusit'], true)) {
            $data = json_decode((string) file_get_contents('php://input', false, null, 0, 4000), true);
            $endpoint = is_array($data) && is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';
            $antispam = new \MiroCMS\Core\Antispam($this->app->db(), $web);
            if ($path === '/push/zrusit') {
                $push->odhlas($endpoint);

                return Response::json(['ok' => true]);
            }
            if ($antispam->pocet($this->app->request->ip(), 'push', 0, 60) >= 10 || !$push->prihlas($endpoint, (string) ($data['keys']['p256dh'] ?? ''), (string) ($data['keys']['auth'] ?? ''))) {
                return Response::json(['ok' => false], 400);
            }
            $antispam->zapis($this->app->request->ip(), 'push', 0);

            return Response::json(['ok' => true]);
        }

        return null;
    }

    private function autor(int $idu): Response
    {
        $autor = $this->app->db()->one("SELECT idu, jmeno, url, pozice, foto, bio FROM {user} WHERE idu = ? AND blokovat = 0 AND jmeno <> ''", [$idu]);
        if ($autor === null) {
            return $this->nenalezeno();
        }
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$clanky, $celkem] = $this->clanky->odAutora($idu, $strana);
        $foto = $autor['foto'] === '' ? '' : (preg_match('#^(https?:)?/#i', $autor['foto']) ? $autor['foto'] : $this->app->request->basePath() . '/' . $autor['foto']);
        $hlavicka = ['nazev' => $autor['jmeno'], 'popis' => '<div class="mc-autor mc-autor-stranka">' . ($foto !== '' ? '<img src="' . e($foto) . '" alt="" width="96" height="96">' : '') . '<div>'
            . ($autor['pozice'] !== '' ? '<span>' . e($autor['pozice']) . '</span>' : '')
            . (trim((string) $autor['bio']) !== '' ? '<p>' . nl2br(e(trim((string) $autor['bio']))) . '</p>' : '')
            . ($autor['url'] !== '' ? '<p><a href="' . e($autor['url']) . '" rel="me noopener">' . e($autor['url']) . '</a></p>' : '') . '</div></div>'];

        return $this->stranka($autor['jmeno'], $this->view->render('vypis', ['rubrika' => $hlavicka] + $this->proVypis($clanky, $celkem, $strana, 'autor/' . $idu)), ['popis' => t('Články autora') . ' ' . $autor['jmeno']]);
    }

    private function stitek(string $seo): Response
    {
        $stitek = $this->app->db()->one('SELECT * FROM {stitky} WHERE seo_link = ?', [$seo]);
        if ($stitek === null) {
            return $this->nenalezeno();
        }
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$clanky, $celkem] = $this->clanky->seStitkem((int) $stitek['ids'], $strana);
        // štítek s úvodem je stránka tématu: nadpis bez mřížky, úvod, obrázek a vlastní popis pro vyhledávače
        $tema = trim((string) $stitek['popis']) !== '';
        $obrazek = $stitek['obrazek'] === '' ? '' : (preg_match('#^(https?:)?/#i', $stitek['obrazek']) ? $stitek['obrazek'] : $this->app->request->basePath() . '/' . $stitek['obrazek']);
        $hlavicka = ['nazev' => ($tema ? '' : '#') . $stitek['nazev'], 'popis' => ($obrazek !== '' ? '<img class="mc-tema-obrazek" src="' . e($obrazek) . '" alt="">' : '') . ($tema ? $stitek['popis'] : '')];

        return $this->stranka($tema ? $stitek['nazev'] : t('Štítek') . ' ' . $stitek['nazev'], $this->view->render('vypis', ['rubrika' => $hlavicka] + $this->proVypis($clanky, $celkem, $strana, 'stitek/' . $seo)), [
            'popis' => $tema ? mb_strimwidth(trim(strip_tags((string) $stitek['popis'])), 0, 300, '…') : '',
            'obrazek' => $obrazek === '' ? '' : (preg_match('#^https?://#i', $obrazek) ? $obrazek : $this->app->request->origin() . $obrazek),
        ]);
    }

    private function hlavniStranka(): Response
    {
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$clanky, $celkem] = $this->clanky->naHlavniStranku($strana);

        return $this->stranka('', $this->view->render('vypis', $this->proVypis($clanky, $celkem, $strana, '')), [
            'hlavni' => true,
            'popis' => $this->app->settings()->get('popis_webu'),
        ]);
    }

    private function rubrika(string $seo): Response
    {
        $rubrika = $this->app->db()->one('SELECT * FROM {topic} WHERE seo_link = ? AND jazyk = ?', [$seo, Jazyk::sloupecWebu()]);
        if ($rubrika === null) {
            return $this->nenalezeno();
        }
        $this->protejsek = ['topic', 'idt', $rubrika, 'rubrika/'];
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$clanky, $celkem] = $this->clanky->zRubriky((int) $rubrika['idt'], $strana);

        return $this->stranka(
            $rubrika['nazev'],
            $this->view->render('vypis', ['rubrika' => $rubrika] + $this->proVypis($clanky, $celkem, $strana, 'rubrika/' . $seo)),
            ['popis' => strip_tags($rubrika['popis']), 'rubrika' => (int) $rubrika['idt']],
        );
    }

    private function clanek(string $seo): Response
    {
        $nahled = $this->app->request->get('nahled') === '1' && $this->app->auth()->user() !== null;
        $this->ctenari?->meritClanek(true); // měkký paywall se počítá jen tady, ne ve výpisech
        $clanek = $this->clanky->podleSeo($seo, $nahled);
        $this->ctenari?->meritClanek(false);
        if ($clanek === null || ((int) $clanek['typ_clanku'] === 2 && !$nahled)) {
            return $this->nenalezeno();
        }
        if ($clanek['jazyk'] !== Jazyk::sloupecWebu()) {
            // článek patří do jiné jazykové verze, než ze které přišel požadavek
            if ($clanek['jazyk'] !== '' && !in_array($clanek['jazyk'], Jazyk::dalsi($this->app->settings()), true)) {
                // jeho jazyková verze je vypnutá: přesměrování by vedlo zpět na tutéž adresu
                return $this->nenalezeno();
            }
            $this->app->jazykPrefix = $clanek['jazyk'];

            return Response::redirect($this->app->url('clanek/' . $clanek['seo_link']) . ($nahled ? '?nahled=1' : ''), 301);
        }
        // úprava přímo na webu pracuje se surovým textem z databáze (bez zámku čtenářů, osnovy a vložených přehrávačů)
        $surovy = $this->app->auth()->user() === null ? null : $this->app->db()->one('SELECT * FROM {clanky} WHERE idc = ?', [$clanek['idc']]);
        if ($surovy !== null && ($formular = $this->upravaNaMiste('clanek', $surovy, 'clanek/' . $clanek['seo_link'])) !== null) {
            return $this->stranka($clanek['titulek'], $formular, ['noindex' => true]);
        }
        if (!$nahled) {
            $this->app->db()->run('UPDATE {clanky} SET visit = visit + 1 WHERE idc = ?', [$clanek['idc']]);
        }

        $casti = new View([MIROCMS_SYSTEM . '/views/front']);
        $clanek['shrnuti_html'] = $casti->render('shrnuti', ['body' => array_values(array_filter(array_map(trim(...), preg_split('/\R/', (string) $clanek['shrnuti']) ?: [])))]);
        $clanek['faq_html'] = $casti->render('faq', ['faq' => Seo::faq($clanek['faq'])]);
        if (!empty($clanek['zamceno'])) {
            $clanek['text'] .= $this->ctenari->zamekHtml($clanek, $this->view);
        }
        $zdarma = $this->ctenari?->stavZdarma();
        if ($zdarma !== null && !$zdarma['vycerpano']) {
            $clanek['text'] .= '<p class="mc-paywall-info">' . e(t('Čtete %s. z %s článků, které máte tento měsíc zdarma.', $zdarma['precteno'], $zdarma['limit']))
                . ' <a href="' . e($this->app->url('ctenar')) . '">' . e(t('Přihlásit se')) . '</a></p>';
        }
        $clanek = (new TypyObsahu($this->app))->dopln($clanek);
        if ($this->ctenari !== null && !$nahled) {
            $clanek['text'] .= $this->ctenari->ulozitHtml($clanek);
        } // přehrávač, živá reportáž, hodnocení recenze
        $interakce = new Interakce($this->app, new View([MIROCMS_SYSTEM . '/views/front']));
        $clanek['reklama_html'] = (new Reklama($this->app))->html('pod-clankem', (int) $clanek['tema']);
        $clanek['hodnoceni_html'] = $nahled ? '' : $interakce->hodnoceniHtml($clanek);
        $clanek['komentare_html'] = $nahled ? '' : $interakce->komentareHtml($clanek);
        $clanek['stitky'] = $this->app->db()->all('SELECT s.nazev, s.seo_link FROM {stitky} s JOIN {clanky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [$clanek['idc']]);

        $obsah = $this->view->render($this->sablonaClanku($clanek), [
            'clanek' => $clanek,
            'rezim' => 'cely',
            'poradi' => 0,
            'url' => $this->app->url(...),
            // díly seriálu mají přednost; jinak se související články vyberou samy podle štítků a rubriky
            'souvisejici' => $this->clanky->zeSkupiny($clanek) ?: ($this->app->settings()->bool('souvisejici_auto') ? $this->clanky->podobne($clanek) : []),
        ]);

        return $this->stranka($clanek['seo_titulek'] !== '' ? $clanek['seo_titulek'] : $clanek['titulek'], $obsah, [
            'clanek' => $clanek,
            'popis' => $clanek['seo_popis'] !== '' ? $clanek['seo_popis'] : mb_strimwidth(trim(strip_tags($clanek['uvod'])), 0, 300, '…'),
            'klicova_slova' => $clanek['t_slova'],
            'obrazek' => $clanek['obrazek'],
            'typ' => 'article',
        ]);
    }

    private function hledani(): Response
    {
        $q = mb_substr($this->app->request->get('q'), 0, 100);
        // hledání je nejdražší dotaz webu a necachuje se: nejvýš 30 hledání za minutu z jedné adresy
        $antispam = new \MiroCMS\Core\Antispam($this->app->db(), $this->app->settings());
        if (mb_strlen($q) >= 3) {
            if ($antispam->pocet($this->app->request->ip(), 'hledani', 0, 1) >= 30) {
                return new Response(t('Příliš mnoho hledání za sebou. Zkuste to prosím za chvíli.'), 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '60']);
            }
            $antispam->zapis($this->app->request->ip(), 'hledani', 0);
        }
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$clanky, $celkem] = mb_strlen($q) >= 3 ? $this->clanky->hledej($q, $strana) : [[], 0];

        return $this->stranka(
            t('Vyhledávání'),
            $this->view->render('vypis', ['hledano' => $q] + $this->proVypis($clanky, $celkem, $strana, 'hledani', ['q' => $q])),
            ['noindex' => true],
        );
    }

    private function rss(): Response
    {
        $xml = Cache::text($this->app, 'rss|' . Jazyk::sloupecWebu(), fn (): string => $this->view->render('rss', [
            'web' => $this->app->settings(),
            'clanky' => $this->clanky->naHlavniStranku(1, 20)[0],
            'adresa' => $this->app->request->origin() . $this->app->url(''),
        ]));

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    private function nenalezeno(): Response
    {
        // než web odpoví 404, zkusí přesměrování ze staré adresy (ruční i po změně adresy článku)
        $cil = Rozsireni::je($this->app->settings(), 'presmerovani')
            ? $this->app->db()->one('SELECT * FROM {presmerovani} WHERE z_adresy = ?', [trim($this->app->request->path(), '/')])
            : null;
        if ($cil !== null) {
            $this->app->db()->run('UPDATE {presmerovani} SET pocet = pocet + 1 WHERE idp = ?', [$cil['idp']]);

            return Response::redirect(preg_match('#^https?://#i', $cil['na_adresu']) ? $cil['na_adresu'] : $this->app->url($cil['na_adresu']), 301);
        }

        // přehled nenalezených adres pro správce (Přesměrování); roboti zkoušející cizí systémy se nezapisují
        $cesta = mb_substr(trim($this->app->request->path(), '/'), 0, 255);
        if ($cesta !== '' && !preg_match('#\.(php|asp|aspx|env|git|sql|bak|ini|xml|txt|js|css|map|png|jpe?g|gif|ico|webp)$|^(wp-|\.|cgi-bin|vendor/|admin/)#i', $cesta) && mb_check_encoding($cesta, 'UTF-8')) {
            try {
                if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {nenalezeno}') < 2000 || $this->app->db()->value('SELECT 1 FROM {nenalezeno} WHERE cesta = ?', [$cesta]) !== null) {
                    $this->app->db()->run('INSERT INTO {nenalezeno} (cesta, pocet, naposledy) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE pocet = pocet + 1, naposledy = NOW()', [$cesta]);
                }
            } catch (\Throwable) {
                // přehled je jen pomůcka - chyba zápisu nesmí změnit odpověď
            }
        }

        return $this->stranka(t('Stránka nenalezena'), $this->view->render('nenalezeno', ['url' => $this->app->url(...), 'nejctenejsi' => $this->clanky->nejctenejsi(5, 30)]), ['noindex' => true], 404);
    }

    /**
     * @param list<array<string, mixed>> $clanky
     * @param array<string, string> $parametry další parametry stránkovacích odkazů
     * @return array<string, mixed>
     */
    private function proVypis(array $clanky, int $celkem, int $strana, string $cesta, array $parametry = []): array
    {
        $this->zaKoncem = $strana > 1 && $clanky === [];
        // "poradi" (od nuly) dovoluje layoutu vysázet první článek výpisu jinak - jako otvírák
        $nahledy = array_map(fn (array $clanek, int $poradi): string => $this->view->render($this->sablonaClanku($clanek), [
            'clanek' => $clanek,
            'rezim' => (int) $clanek['typ_clanku'] === 2 ? 'kratky' : 'nahled',
            'poradi' => $strana === 1 ? $poradi : $poradi + 1000,
            'url' => $this->app->url(...),
            'souvisejici' => [],
        ]), $clanky, array_keys($clanky));

        return [
            'nahledy' => $nahledy,
            'celkem' => $celkem,
            'strana' => $strana,
            'stran' => max(1, (int) ceil($celkem / $this->clanky->naStranku())),
            'strankaUrl' => fn (int $s): string => $this->app->url($cesta) . (($query = http_build_query($parametry + ($s > 1 ? ['strana' => $s] : []))) !== '' ? '?' . $query : ''),
            'rubrika' => null,
            'hledano' => null,
            'hlavni' => $cesta === '',
            'url' => $this->app->url(...),
        ];
    }

    /**
     * Přepínač jazyků pro šablonu: kód => [název, adresa, je aktivní]. U článku vede na jeho překlad, jinak na úvod verze.
     * Prázdné pole = web má jediný jazyk.
     *
     * @param array<string, mixed>|null $clanek
     * @return array<string, array{nazev:string, url:string, aktivni:bool}>
     */
    private function jazyky(?array $clanek): array
    {
        $web = $this->app->settings();
        $dalsi = Jazyk::dalsi($web);
        if ($dalsi === []) {
            return [];
        }
        $preklady = [];
        if ($clanek !== null) {
            $original = (int) ($clanek['preklad_z'] ?: $clanek['idc']);
            $preklady = array_map(fn (string $seo): string => 'clanek/' . $seo, $this->app->db()->pairs('SELECT jazyk, seo_link FROM {clanky} WHERE (idc = ? OR preklad_z = ?) AND visible = 1 AND datum <= NOW()', [$original, $original]));
        } elseif ($this->protejsek !== null) {
            // rubrika nebo stránka: originál + jeho překlady
            [$tabulka, $klic, $radek, $cesta] = $this->protejsek;
            $original = (int) ($radek['preklad_z'] ?: $radek[$klic]);
            $preklady = array_map(fn (string $seo): string => $cesta . $seo, $this->app->db()->pairs("SELECT jazyk, seo_link FROM {{$tabulka}} WHERE ({$klic} = ? OR preklad_z = ?) AND zobrazit = 1", [$original, $original]));
        }
        $koren = $this->app->request->basePath() . '/';
        $vysledek = [];
        foreach ([Jazyk::vychozi($web), ...$dalsi] as $kod) {
            $sloupec = Jazyk::sloupec($web, $kod);
            $predpona = $sloupec === '' ? '' : $sloupec . '/';
            $vysledek[$kod] = [
                'nazev' => Jazyk::DOSTUPNE[$kod][0],
                'url' => $koren . $predpona . ($preklady[$sloupec] ?? ''),
                'aktivni' => $kod === Jazyk::kod(),
                'preklad' => isset($preklady[$sloupec]),
            ];
        }

        return $vysledek;
    }

    /** Šablona článku: cla_<soubor>.php z layoutu; když chybí, použije se standardní. */
    /** Odkaz „Upravit zde“ pro právě zobrazenou stránku nebo článek; vypíše ho stranka() přihlášenému, který na to má právo. */
    private string $upravitZde = '';

    /**
     * Úprava stránky nebo článku přímo na webu. Bez práva nedělá nic; s právem připraví odkaz „Upravit zde“
     * a při ?upravit=text vrátí formulář s editorem místo obsahu. Ukládá administrace (akce uloz_text).
     *
     * @param array<string, mixed> $zaznam řádek rs_stranky nebo rs_clanky
     */
    private function upravaNaMiste(string $typ, array $zaznam, string $cesta): ?string
    {
        $auth = $this->app->auth();
        if ($auth->user() === null || !($typ === 'clanek' ? $auth->smiUpravitClanek($zaznam) : $auth->maModul('stranky'))) {
            return null;
        }
        $adresa = $this->app->url($cesta);
        if ($this->app->request->get('upravit') !== 'text') {
            // koncept je vidět jen v náhledu – bez něj by „Upravit zde“ skončilo na stránce Nenalezeno
            $this->upravitZde = $adresa . ($this->app->request->get('nahled') === '1' ? '?nahled=1&upravit=text' : '?upravit=text');

            return null;
        }
        $zamceno = '';
        if ($typ === 'clanek') {
            // stejný zámek jako v administraci: dva lidé nesmí přepisovat tentýž článek
            if ($zaznam['zamek_kdo'] !== null && (int) $zaznam['zamek_kdo'] !== $auth->id() && strtotime((string) $zaznam['zamek_cas']) > time() - 180) {
                $zamceno = (string) $this->app->db()->value("SELECT IF(jmeno = '', user, jmeno) FROM {user} WHERE idu = ?", [$zaznam['zamek_kdo']]);
            } else {
                $this->app->db()->update('clanky', ['zamek_kdo' => $auth->id(), 'zamek_cas' => date('Y-m-d H:i:s')], ['idc' => $zaznam['idc']]);
            }
        }

        return $this->view->render('upravit', [
            'app' => $this->app, 'typ' => $typ, 'zaznam' => $zaznam, 'zamceno' => $zamceno,
            'zpet' => $adresa . ($this->app->request->get('nahled') === '1' ? '?nahled=1' : ''),
            'akce' => $this->app->url('admin.php?modul=' . ($typ === 'clanek' ? 'clanky' : 'stranky') . '&akce=uloz_text'),
            'chyba' => $this->app->request->get('chyba') === '1',
        ]);
    }

    private function sablonaClanku(array $clanek): string
    {
        $soubor = 'cla_' . ($clanek['sablona_soubor'] ?? 'standard');

        return preg_match('/^cla_[a-z0-9_-]+$/', $soubor) && $this->view->exists($soubor) ? $soubor : 'cla_standard';
    }

    /**
     * Složí stránku: obsah vloží do Hlavního bloku, okolo vykreslí sloupce s bloky a vše obalí layoutem.
     *
     * @param array<string, mixed> $meta
     */
    /** Odkaz na účet čtenáře do hlavičky (jen se zapnutým rozšířením Čtenáři). */
    private function ucetOdkaz(): string
    {
        if ($this->ctenari === null) {
            return '';
        }
        $ctenar = $this->ctenari->prihlaseny();

        return $this->view->render('ucet_odkaz', [
            'url' => $this->app->url('ctenar'), 'prihlasen' => $ctenar !== null, 'jmeno' => mb_substr(trim((string) ($ctenar['jmeno'] ?? '')), 0, 40),
        ]);
    }

    private function stranka(string $titulek, string $obsah, array $meta = [], int $status = 200): Response
    {
        if ($this->zaKoncem) {
            $this->zaKoncem = false;

            return $this->nenalezeno();
        }
        $web = $this->app->settings();
        $bloky = new Bloky($this->app, $this->view);
        $seo = new Seo($this->app);
        $clanek = $meta['clanek'] ?? null;
        $meta['rubrika'] ??= null;
        // vizuální editor bloků: ?upravit=1 pro přihlášeného uživatele s přístupem k blokům
        $upravit = $this->app->request->get('upravit') === '1' && $this->app->auth()->maModul('bloky');
        unset($meta['clanek']);
        if ($status === 200 && empty($meta['noindex']) && !$upravit) {
            Statistika::zaznamenej($this->app, $clanek === null ? null : (int) $clanek['idc']);
        }

        $jazyky = $this->jazyky($clanek);
        $html = $this->view->render('base', [
            'web' => $web,
            'titulek' => $titulek,
            'meta' => $meta + ['hlavni' => false, 'popis' => '', 'klicova_slova' => $web->get('klicova_slova'), 'obrazek' => '', 'typ' => 'website', 'noindex' => false],
            'obsah' => $obsah,
            'zony' => $bloky->zony(!empty($meta['hlavni']), $clanek !== null ? (int) $clanek['tema'] : ($meta['rubrika'] ?? null), $upravit),
            'rozvrzeni' => $bloky->rozvrzeni(),
            'hlava' => $seo->hlava($titulek, $meta + ['jazyky' => $jazyky], $clanek),
            'pata' => ($upravit ? $this->view->render('vizual', ['app' => $this->app, 'rozvrzeni' => $bloky->rozvrzeni()]) : $seo->pata())
                . ($this->upravitZde !== '' && !$upravit ? '<a class="mc-upravit-zde" href="' . e($this->upravitZde) . '">' . e(t('Upravit zde')) . '</a>' : ''),
            'rubriky' => $bloky->rubrikyMenu(),
            'stranky' => $bloky->strankyMenu(),
            'jazyk' => Jazyk::kod(),
            'jazyky_html' => $jazyky === [] ? '' : $this->view->render('jazyky', ['jazyky' => $jazyky]),
            'ucet_html' => $this->ucetOdkaz(),
            'url' => $this->app->url(...),
            'kanonicka' => $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')),
        ]);
        $html = ObrazkyHtml::dopln($this->app->db(), $html); // rozměry a barva podkladu obrázků – méně poskakování stránky
        // zamčený článek s měkkým paywallem se liší podle čtenáře (počítadlo v cookie) - do společné cache nepatří
        $mereny = $clanek !== null && (int) ($clanek['pristup'] ?? 0) > 0 && $web->int('paywall_zdarma') > 0;
        if ($status === 200 && empty($meta['noindex']) && $this->app->request->get('nahled') === '' && !$mereny) {
            Cache::uloz($this->app, $html, $clanek === null ? null : (int) $clanek['idc']);
        }

        return Response::html($html, $status);
    }
}
