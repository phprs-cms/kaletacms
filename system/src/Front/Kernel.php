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
 *   /                           úvodní stránka (Nastavení → Základní), bez ní výpis novinek
 *   /novinky                    výpis novinek
 *   /novinky/<seo-link>         novinka (+ .md pro jazykové modely)
 *   /novinky/kategorie/<seo>    novinky v kategorii
 *   /novinky/stitek/<seo>       novinky se štítkem
 *   /hledani?q=...              vyhledávání
 *   /rss.xml                    RSS kanál novinek
 *   /<adresa>                   stránka
 *   robots.txt, sitemap.xml, llms.txt, feed.json... viz Seo
 */
final class Kernel
{
    private readonly View $view;
    private readonly Clanky $novinky;

    /** Kategorie nebo stránka, kterou požadavek zobrazuje - přepínač jazyků podle ní najde protějšek v jiné verzi. */
    private ?array $protejsek = null;

    /** Sdílený stav stavitele pro celou stránku (stavba stránky, záhlaví, patička, obálka) – jedno CSS bez opakování. */
    private ?\MiroCMS\Stavitel\Kontext $kontext = null;

    /** Složka šablony (layoutu), kterou web právě používá. */
    private string $layout = Layouty::VYCHOZI;

    /** Požadovaná stránka výpisu je až za jeho koncem - odpoví se 404. */
    private bool $zaKoncem = false;

    /** Odkaz „Upravit zde“ pro právě zobrazenou stránku nebo novinku; vypíše ho stranka() přihlášenému, který na to má právo. */
    private string $upravitZde = '';

    public function __construct(private readonly App $app)
    {
        $app->request->setOrigin($app->settings()->get('adresa_webu'));
        $app->casovePasmo();
        // po aktualizaci systému (i automatické) se databáze upraví hned při první návštěvě, ne až po přihlášení administrátora
        if ($app->settings()->int('verze_db') < MIROCMS_VERZE_DB) {
            \MiroCMS\Core\Migrace::proved($app->db(), $app->settings());
        }
        // jazyková verze: /en/novinky/x -> jazyk "en", cesta "/novinky/x"; adresy z $app->url() pak dostávají předponu samy
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
        // nastavená šablona chybí (smazaná složka) - web se vykreslí výchozí
        if (!preg_match('/^[a-z0-9_-]+$/i', $layout) || !is_file(MIROCMS_ROOT . '/layout/' . $layout . '/base.php')) {
            $layout = Layouty::VYCHOZI;
        }
        $this->layout = $layout;
        // šablona se hledá nejdřív v layoutu webu, potom mezi systémovými - layout tak může přepsat cokoli
        $this->view = new View([MIROCMS_ROOT . '/layout/' . $layout, MIROCMS_SYSTEM . '/views/front']);
        $this->novinky = new Clanky($app->db(), $app->settings(), $app->request->basePath());
    }

    public function handle(): Response
    {
        $request = $this->app->request;
        if ($this->app->settings()->bool('udrzba') && $request->path() !== '/mcp' && $this->app->auth()->user() === null) {
            return new Response('<!doctype html><html lang="' . e(Jazyk::kod()) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($this->app->settings()->get('nazev_webu')) . '</title>'
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
            return $this->uvod();
        }
        if ($path === '/novinky') {
            return $this->vypisNovinek();
        }
        if (preg_match('#^/novinky/kategorie/([a-z0-9-]+)$#', $path, $m)) {
            return $this->kategorie($m[1]);
        }
        if (preg_match('#^/novinky/stitek/([a-z0-9-]+)$#', $path, $m)) {
            return $this->stitek($m[1]);
        }
        if (preg_match('#^/novinky/([a-z0-9-]+)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_clanky')) {
            $novinka = $this->novinky->podleSeo($m[1]);

            return $novinka === null
                ? $this->nenalezeno()
                : new Response((new Seo($this->app))->clanekMarkdown($novinka), 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
        }
        if (preg_match('#^/novinky/([a-z0-9-]+)$#', $path, $m)) {
            return $this->novinka($m[1]);
        }
        if ($path === '/hledani') {
            return $this->hledani();
        }
        if ($path === '/rss.xml') {
            return $this->rss();
        }
        $seo = new Seo($this->app);
        if ($path === '/manifest.webmanifest') {
            return new Response(Identita::manifest($this->app->settings(), $this->app->request->basePath()), 200, ['Content-Type' => 'application/manifest+json; charset=utf-8']);
        }
        if ($path === '/favicon.ico') {
            // prohlížeče se ptají samy; místo celé stránky 404 odkaz na ikonu webu, nebo prázdná odpověď
            $ikona = is_file(MIROCMS_ROOT . '/media/ikona-32.png') ? $this->app->url('media/ikona-32.png') : null;

            return $ikona !== null ? Response::redirect($ikona, 301) : new Response('', 204, ['Cache-Control' => 'public, max-age=86400']);
        }
        if ($path === '/robots.txt') {
            return new Response($seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/sitemap.xml') {
            return new Response(Cache::text($this->app, 'sitemap', $seo->sitemapXml(...)), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
        }
        if ($path === '/feed.json') {
            $json = Cache::text($this->app, 'feed.json|' . Jazyk::sloupecWebu(), fn (): string => (string) json_encode($seo->jsonFeed($this->novinky->vypis(1, 20, true)[0]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return new Response($json, 200, ['Content-Type' => 'application/feed+json; charset=utf-8']);
        }
        if ($path === '/llms.txt' && $this->app->settings()->bool('llms_txt')) {
            return new Response(Cache::text($this->app, 'llms|' . Jazyk::sloupecWebu(), $seo->llmsTxt(...)), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
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
        if (str_starts_with($path, '/api/') && Rozsireni::je($this->app->settings(), 'api')) {
            return (new Api($this->app, $this->novinky))->handle($path);
        }
        if ($path === '/mcp') {
            return (new \MiroCMS\Mcp\Server($this->app))->handle();
        }
        if ($path === '/formular') {
            return (new Formulare($this->app))->zpracuj();
        }
        if ($path === '/ulohy') {
            // úlohy na pozadí pro cron: weby s malou návštěvností tak vydají naplánovanou novinku a odešlou poštu včas
            $token = $this->app->settings()->get('ulohy_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return new Response(t('Neplatný token.') . "\n", 403, ['Content-Type' => 'text/plain; charset=utf-8']);
            }
            $hotovo = [];
            try {
                \MiroCMS\Core\Oznameni::zpracuj($this->app);
                $hotovo[] = 'oznameni';
                \MiroCMS\Core\Zaloha::automaticka($this->app->db(), $this->app->settings());
                $hotovo[] = 'zalohy';
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

        // skrytou stránku vidí jen náhled stavitele (kdo smí upravovat stránky)
        $skryte = $request->get('stavba') === 'koncept' && $this->app->auth()->maModul('stranky');
        $stranka = $this->app->db()->one('SELECT * FROM {stranky} WHERE seo_link = ? AND jazyk = ? AND smazano IS NULL' . ($skryte ? '' : ' AND zobrazit = 1'), [ltrim($path, '/'), Jazyk::sloupecWebu()]);
        if ($stranka !== null) {
            if ((int) $stranka['ids'] === $this->idUvodu() && !$skryte) {
                return Response::redirect($this->app->url(''), 301); // úvodní stránka má jen jednu adresu – kořen webu
            }

            return $this->zobrazStranku($stranka, ltrim($path, '/'));
        }
        if (preg_match('#^/_sekce/([a-z0-9-]{1,40})$#', $path, $m) && $this->app->auth()->maModul('stranky')) {
            return $this->nahledSekce($m[1]);
        }
        if (preg_match('#^/_komponenta/(\d+)$#', $path, $m) && $this->app->auth()->isAdmin()) {
            return $this->nahledKomponenty((int) $m[1]);
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $path, $m) && $m[1] !== 'novinky') {
            return $this->detailKolekce($m[1], $m[2]);
        }

        return $this->nenalezeno();
    }

    /**
     * Náhled hotové sekce knihovny pro panel stavitele: jen sekce ve vzhledu webu, bez záhlaví a patičky.
     * Třídy knihovny se jen vykreslí z jejich výchozího stylu – do webu se nic nezapisuje.
     */
    private function nahledSekce(string $klic): Response
    {
        $sekce = \MiroCMS\Stavitel\Knihovna::sekci($klic, Jazyk::kod());
        if ($sekce === null) {
            return $this->nenalezeno();
        }
        $k = new \MiroCMS\Stavitel\Kontext($this->app);
        $html = \MiroCMS\Stavitel\Stavba::html(['deti' => [$sekce['prvek']]], $k);
        $tridy = '';
        foreach ($sekce['tridy'] as $t) {
            $tridy .= \MiroCMS\Stavitel\Styl::css('.' . $t, \MiroCMS\Stavitel\Knihovna::TRIDY[$t] ?? []);
        }
        $k->tridy = []; // styl tříd výše z knihovny, ne z databáze webu (na webu třída ještě nemusí být)
        $web = $this->app->settings();
        $css = \MiroCMS\Stavitel\DesignSystem::css(\MiroCMS\Stavitel\DesignSystem::nacti($web), $this->app->request->basePath()) . \MiroCMS\Stavitel\Stavba::css($this->app->db(), $k) . '@layer tridy {' . $tridy . '}';
        $layout = $this->app->url('layout/' . $this->layout . '/style.css');

        return new Response('<!doctype html><html lang="' . e(Jazyk::kod()) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<link rel="stylesheet" href="' . e($layout) . '"><link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '"><style>' . $css . 'body{margin:0}</style></head>'
            . '<body><main class="stavba">' . $html . '</main></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }

    /** Plátno editoru komponenty (jen správce): rozpracovaná komponenta s výchozími hodnotami vlastností. */
    private function nahledKomponenty(int $idm): Response
    {
        $komponenta = \MiroCMS\Stavitel\Komponenty::podleId($this->app->db(), $idm);
        if ($komponenta === null) {
            return $this->nenalezeno();
        }
        $k = $this->kontext();
        $k->polozka = \MiroCMS\Stavitel\Komponenty::hodnoty($komponenta, []);
        $k->editor = $this->app->request->get('editor') === '1';
        $html = \MiroCMS\Stavitel\Stavba::html(\MiroCMS\Stavitel\Stavba::zJson($komponenta['stavba_koncept'] ?? $komponenta['stavba']) ?? ['deti' => []], $k);
        [$k->polozka, $k->editor] = [null, false];

        return $this->stranka($komponenta['nazev'], $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $html]), ['stavba' => true, 'noindex' => true]);
    }

    /**
     * Stránka položky kolekce (/<kolekce>/<položka>) podle šablony detailu ze stavitele. Správce vidí v editoru koncept
     * šablony (?stavba=koncept&editor=1), a když kolekce ještě nemá položky, ukázku s popisky polí (/<kolekce>/_ukazka).
     */
    private function detailKolekce(string $seoKolekce, string $seo): Response
    {
        $db = $this->app->db();
        $r = $this->app->request;
        $kolekce = \MiroCMS\Stavitel\Kolekce::podleSeo($db, $seoKolekce);
        $koncept = $r->get('stavba') === 'koncept' && $this->app->auth()->isAdmin();
        if ($kolekce === null || (!$kolekce['detail'] && !$koncept)) {
            return $this->nenalezeno();
        }
        $polozka = $db->one('SELECT * FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND jazyk = ?' . ($koncept ? '' : ' AND zobrazit = 1'), [$kolekce['idk'], $seo, Jazyk::sloupecWebu()]);
        if ($polozka === null && !($koncept && $seo === '_ukazka')) {
            return $this->nenalezeno();
        }
        if ($polozka !== null) {
            $polozka['data'] = json_decode((string) $polozka['data'], true) ?: [];
        }
        $stavba = \MiroCMS\Stavitel\Stavba::zJson($koncept ? ($kolekce['stavba_koncept'] ?? $kolekce['stavba']) : $kolekce['stavba'])
            ?? \MiroCMS\Stavitel\Kolekce::vychoziSablona($kolekce);
        $this->drobecky([$kolekce['nazev'], ''], [$polozka['nazev'] ?? t('Ukázková položka'), '']);
        $k = $this->kontext();
        $k->polozka = $polozka !== null ? \MiroCMS\Stavitel\Kolekce::hodnoty($kolekce, $polozka, $this->app->url(...)) : \MiroCMS\Stavitel\Kolekce::ukazka($kolekce);
        $k->editor = $koncept && $r->get('editor') === '1';
        $k->zdroj = 'kolekce:' . (int) $kolekce['idk'];
        $html = \MiroCMS\Stavitel\Stavba::html($stavba, $k);
        [$k->polozka, $k->editor] = [null, false];

        // popis a obrázek pro vyhledávače a sdílení: první delší text a první obrázek položky
        $popis = '';
        $obrazek = '';
        foreach ($kolekce['pole'] as $pole) {
            $h = (string) ($polozka['data'][$pole['klic']] ?? '');
            if ($popis === '' && in_array($pole['typ'], ['radky', 'html'], true) && $h !== '') {
                $popis = mb_strimwidth(trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)), 0, 300, '…');
            }
            if ($obrazek === '' && $pole['typ'] === 'obrazek' && $h !== '') {
                $obrazek = preg_match('#^https?://#', $h) ? $h : $this->app->request->origin() . $this->app->url(ltrim($h, '/'));
            }
        }

        return $this->stranka((string) ($polozka['nazev'] ?? $kolekce['nazev']), $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $html]), [
            'popis' => $popis, 'obrazek' => $obrazek, 'stavba' => true, 'noindex' => $koncept,
        ]);
    }

    /** Číslo úvodní stránky v jazyce zobrazené verze webu (protějšek stránky z Nastavení); 0 = úvodem je výpis novinek. */
    private function idUvodu(): int
    {
        $id = $this->app->settings()->int('titulni_stranka');
        if ($id === 0 || Jazyk::sloupecWebu() === '') {
            return $id;
        }

        return (int) $this->app->db()->value('SELECT ids FROM {stranky} WHERE preklad_z = ? AND jazyk = ?', [$id, Jazyk::sloupecWebu()]);
    }

    private function uvod(): Response
    {
        $stranka = ($id = $this->idUvodu()) > 0 ? $this->app->db()->one('SELECT * FROM {stranky} WHERE ids = ? AND zobrazit = 1', [$id]) : null;

        return $stranka !== null ? $this->zobrazStranku($stranka, '', true) : $this->vypisNovinek(true);
    }

    /** @param array<string, mixed> $stranka */
    private function zobrazStranku(array $stranka, string $cesta, bool $uvod = false): Response
    {
        $this->protejsek = ['stranky', 'ids', $stranka, ''];
        if (!$uvod) {
            // podstránka: v drobečcích i nadřazené stránky (podle adresy sluzby/kuchyne → sluzby)
            $urovne = [];
            $useky = explode('/', (string) $stranka['seo_link']);
            for ($i = 1; $i < count($useky); $i++) {
                $nad = $this->app->db()->one('SELECT titulek, seo_link FROM {stranky} WHERE seo_link = ? AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL', [implode('/', array_slice($useky, 0, $i)), $stranka['jazyk']]);
                if ($nad !== null) {
                    $urovne[] = [$nad['titulek'], $this->app->url($nad['seo_link'])];
                }
            }
            $this->drobecky(...[...$urovne, [$stranka['titulek'], '']]);
        }
        // titulek a údaje pro vyhledávače a sdílení (vlastní titulek, obrázek, noindex – jako u novinek)
        $titulek = $stranka['seo_titulek'] !== '' ? $stranka['seo_titulek'] : ($uvod ? '' : $stranka['titulek']);
        $meta = [
            'popis' => $stranka['popis'] !== '' ? $stranka['popis'] : ($uvod ? $this->app->settings()->get('popis_webu') : ''),
            'hlavni' => $uvod, 'obrazek' => $stranka['obrazek'], 'noindex' => (bool) $stranka['noindex'],
        ];
        // náhled rozpracované stavby pro editor: ?stavba=koncept (jen kdo smí upravovat stránky), &editor=1 přidá značky pro výběr prvků
        $koncept = $this->app->request->get('stavba') === 'koncept' && $this->app->auth()->maModul('stranky');
        $stavba = \MiroCMS\Stavitel\Stavba::zJson($koncept ? ($stranka['stavba_koncept'] ?? $stranka['stavba']) : $stranka['stavba']);
        if ($stavba !== null) {
            $k = $this->kontext();
            $k->editor = $koncept && $this->app->request->get('editor') === '1' && $this->app->request->get('cast') === '';
            $k->zdroj = 'stranka:' . (int) $stranka['ids'];
            $html = \MiroCMS\Stavitel\Stavba::html($stavba, $k);
            $k->editor = false;
            if (!$koncept && $this->app->auth()->maModul('stranky')) {
                $this->upravitZde = $this->app->url('admin.php?modul=stranky&akce=stavitel&id=' . (int) $stranka['ids']);
            }

            return $this->stranka($titulek, $this->view->render('stranka', ['stranka' => $stranka, 'uvod' => $uvod, 'stavba' => $html]), [
                'stavba' => true, 'noindex' => $koncept || $meta['noindex'],
            ] + $meta);
        }
        if (($formular = $this->upravaNaMiste('stranka', $stranka, $cesta)) !== null) {
            return $this->stranka($stranka['titulek'], $formular, ['noindex' => true]);
        }

        return $this->stranka($titulek, $this->view->render('stranka', ['stranka' => $stranka, 'uvod' => $uvod, 'stavba' => null]), $meta);
    }

    private function vypisNovinek(bool $uvod = false): Response
    {
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$novinky, $celkem] = $this->novinky->vypis($strana);
        if (!$uvod) {
            $this->drobecky([t('Novinky'), '']);
        }

        return $this->stranka($uvod ? '' : t('Novinky'), $this->view->render('vypis', ['nadpis' => t('Novinky'), 'popis' => ''] + $this->proVypis($novinky, $celkem, $strana, $uvod ? '' : 'novinky')), [
            'hlavni' => $uvod,
            'popis' => $this->app->settings()->get('popis_webu'),
            'cast' => 'vypis',
        ]);
    }

    private function kategorie(string $seo): Response
    {
        $kategorie = $this->app->db()->one('SELECT * FROM {kategorie} WHERE seo_link = ? AND jazyk = ?', [$seo, Jazyk::sloupecWebu()]);
        if ($kategorie === null) {
            return $this->nenalezeno();
        }
        $this->protejsek = ['kategorie', 'idt', $kategorie, 'novinky/kategorie/'];
        $this->drobecky([t('Novinky'), $this->app->url('novinky')], [$kategorie['nazev'], '']);
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$novinky, $celkem] = $this->novinky->zKategorie((int) $kategorie['idt'], $strana);

        return $this->stranka(
            $kategorie['nazev'],
            $this->view->render('vypis', ['nadpis' => $kategorie['nazev'], 'popis' => $kategorie['popis']] + $this->proVypis($novinky, $celkem, $strana, 'novinky/kategorie/' . $seo)),
            ['popis' => strip_tags($kategorie['popis']), 'cast' => 'vypis'],
        );
    }

    private function stitek(string $seo): Response
    {
        $stitek = $this->app->db()->one('SELECT * FROM {stitky} WHERE seo_link = ?', [$seo]);
        if ($stitek === null) {
            return $this->nenalezeno();
        }
        $strana = max(1, $this->app->request->getInt('strana', 1));
        [$novinky, $celkem] = $this->novinky->seStitkem((int) $stitek['ids'], $strana);
        // štítek s popisem je stránka tématu: úvod a vlastní popis pro vyhledávače
        $tema = trim((string) $stitek['popis']) !== '';

        return $this->stranka($tema ? $stitek['nazev'] : t('Štítek') . ' ' . $stitek['nazev'], $this->view->render('vypis', [
            'nadpis' => ($tema ? '' : '#') . $stitek['nazev'], 'popis' => $tema ? (string) $stitek['popis'] : '',
        ] + $this->proVypis($novinky, $celkem, $strana, 'novinky/stitek/' . $seo)), [
            'popis' => $tema ? mb_strimwidth(trim(strip_tags((string) $stitek['popis'])), 0, 300, '…') : '',
            'cast' => 'vypis',
        ]);
    }

    private function novinka(string $seo): Response
    {
        $nahled = $this->app->request->get('nahled') === '1' && $this->app->auth()->user() !== null;
        $novinka = $this->novinky->podleSeo($seo, $nahled);
        if ($novinka === null) {
            return $this->nenalezeno();
        }
        if ($novinka['jazyk'] !== Jazyk::sloupecWebu()) {
            // novinka patří do jiné jazykové verze, než ze které přišel požadavek
            if ($novinka['jazyk'] !== '' && !in_array($novinka['jazyk'], Jazyk::dalsi($this->app->settings()), true)) {
                return $this->nenalezeno(); // její jazyková verze je vypnutá: přesměrování by vedlo zpět na tutéž adresu
            }
            $this->app->jazykPrefix = $novinka['jazyk'];

            return Response::redirect($this->app->url('novinky/' . $novinka['seo_link']) . ($nahled ? '?nahled=1' : ''), 301);
        }
        // úprava přímo na webu pracuje se surovým textem z databáze (bez osnovy a vložených přehrávačů)
        $surova = $this->app->auth()->user() === null ? null : $this->app->db()->one('SELECT * FROM {novinky} WHERE idc = ?', [$novinka['idc']]);
        if ($surova !== null && ($formular = $this->upravaNaMiste('novinka', $surova, 'novinky/' . $novinka['seo_link'])) !== null) {
            return $this->stranka($novinka['titulek'], $formular, ['noindex' => true]);
        }
        if (!$nahled) {
            $this->app->db()->run('UPDATE {novinky} SET visit = visit + 1 WHERE idc = ?', [$novinka['idc']]);
        }

        $this->drobecky([t('Novinky'), $this->app->url('novinky')], [$novinka['tema_jm'], $this->app->url('novinky/kategorie/' . $novinka['tema_seo'])], [$novinka['titulek'], '']);
        $novinka['faq_html'] = (new View([MIROCMS_SYSTEM . '/views/front']))->render('faq', ['faq' => Seo::faq($novinka['faq'])]);
        $novinka = (new TextNovinky($this->app))->dopln($novinka);
        $novinka['stitky'] = $this->app->db()->all('SELECT s.nazev, s.seo_link FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [$novinka['idc']]);

        $obsah = $this->view->render('novinka', [
            'novinka' => $novinka,
            'url' => $this->app->url(...),
            'souvisejici' => $this->app->settings()->bool('souvisejici_auto') ? $this->novinky->podobne($novinka) : [],
        ]);

        return $this->stranka($novinka['seo_titulek'] !== '' ? $novinka['seo_titulek'] : $novinka['titulek'], $obsah, [
            'clanek' => $novinka,
            'popis' => $novinka['seo_popis'] !== '' ? $novinka['seo_popis'] : mb_strimwidth(trim(strip_tags($novinka['uvod'])), 0, 300, '…'),
            'klicova_slova' => $novinka['t_slova'],
            'obrazek' => $novinka['obrazek'],
            'noindex' => (bool) $novinka['noindex'],
            'typ' => 'article',
            'cast' => 'novinka',
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
        [$novinky, $celkem] = mb_strlen($q) >= 3 ? $this->novinky->hledej($q, $strana) : [[], 0];
        // stránky a položky kolekcí s vlastní stránkou – bez ohledu na diakritiku, s úryvkem (novinky hledá fulltext výše)
        $stranky = [];
        if (mb_strlen($q) >= 3 && $strana === 1) {
            $db = $this->app->db();
            $uvod = $this->idUvodu();
            $kandidati = array_map(fn (array $s): array => ['titulek' => $s['titulek'], 'adresa' => (int) $s['ids'] === $uvod ? '' : $s['seo_link'], 'text' => (string) $s['text']],
                $db->all('SELECT ids, titulek, seo_link, text FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND smazano IS NULL AND jazyk = ? ORDER BY poradi LIMIT 500', [Jazyk::sloupecWebu()]));
            foreach ($db->all('SELECT p.nazev, p.seo_link, p.data, k.seo_link AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.jazyk = ? ORDER BY p.poradi LIMIT 2000', [Jazyk::sloupecWebu()]) as $p) {
                $data = json_decode((string) $p['data'], true);
                $kandidati[] = ['titulek' => $p['nazev'], 'adresa' => $p['kolekce'] . '/' . $p['seo_link'], 'text' => implode(' ', array_filter(is_array($data) ? $data : [], 'is_string'))];
            }
            $stranky = array_map(fn (array $v): array => ['titulek' => $v['titulek'], 'seo_link' => $v['adresa'], 'uryvek' => $v['uryvek']], \MiroCMS\Core\Hledani::najdi($q, $kandidati));
        }

        return $this->stranka(
            t('Vyhledávání'),
            $this->view->render('vypis', ['nadpis' => t('Vyhledávání'), 'popis' => '', 'hledano' => $q, 'nalezeneStranky' => $stranky] + $this->proVypis($novinky, $celkem, $strana, 'hledani', ['q' => $q])),
            ['noindex' => true],
        );
    }

    private function rss(): Response
    {
        $xml = Cache::text($this->app, 'rss|' . Jazyk::sloupecWebu(), fn (): string => $this->view->render('rss', [
            'web' => $this->app->settings(),
            'novinky' => $this->novinky->vypis(1, 20)[0],
            'adresa' => $this->app->request->origin() . $this->app->url(''),
        ]));

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    private function nenalezeno(): Response
    {
        // než web odpoví 404, zkusí přesměrování ze staré adresy (ruční, po importu i po změně adresy)
        $cil = Rozsireni::je($this->app->settings(), 'presmerovani')
            ? $this->app->db()->one('SELECT * FROM {presmerovani} WHERE z_adresy = ?', [trim($this->app->request->path(), '/')])
            : null;
        if ($cil !== null) {
            $this->app->db()->run('UPDATE {presmerovani} SET pocet = pocet + 1 WHERE idp = ?', [$cil['idp']]);

            return Response::redirect(preg_match('#^https?://#i', $cil['na_adresu']) ? $cil['na_adresu'] : $this->app->url($cil['na_adresu']), (int) ($cil['typ'] ?? 301) === 302 ? 302 : 301);
        }

        // přehled nenalezených adres pro správce (Přesměrování); roboti zkoušející cizí systémy se nezapisují
        $cesta = mb_substr(trim($this->app->request->path(), '/'), 0, 255);
        if ($cesta !== '' && $this->app->request->get('cast') === '' && !preg_match('#\.(php|asp|aspx|env|git|sql|bak|ini|xml|txt|js|css|map|png|jpe?g|gif|ico|webp)$|^(wp-|\.|cgi-bin|vendor/|admin/)#i', $cesta) && mb_check_encoding($cesta, 'UTF-8')) {
            try {
                if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {nenalezeno}') < 2000 || $this->app->db()->value('SELECT 1 FROM {nenalezeno} WHERE cesta = ?', [$cesta]) !== null) {
                    $this->app->db()->run('INSERT INTO {nenalezeno} (cesta, pocet, naposledy) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE pocet = pocet + 1, naposledy = NOW()', [$cesta]);
                }
            } catch (\Throwable) {
                // přehled je jen pomůcka - chyba zápisu nesmí změnit odpověď
            }
        }

        return $this->stranka(t('Stránka nenalezena'), $this->view->render('nenalezeno', ['url' => $this->app->url(...), 'stranky' => $this->strankyMenu()]), ['noindex' => true, 'cast' => 'nenalezeno'], 404);
    }

    /**
     * @param list<array<string, mixed>> $novinky
     * @param array<string, string> $parametry další parametry stránkovacích odkazů
     * @return array<string, mixed>
     */
    private function proVypis(array $novinky, int $celkem, int $strana, string $cesta, array $parametry = []): array
    {
        $this->zaKoncem = $strana > 1 && $novinky === [];

        return [
            'novinky' => $novinky,
            'celkem' => $celkem,
            'strana' => $strana,
            'stran' => max(1, (int) ceil($celkem / $this->novinky->naStranku())),
            'strankaUrl' => fn (int $s): string => $this->app->url($cesta) . (($query = http_build_query($parametry + ($s > 1 ? ['strana' => $s] : []))) !== '' ? '?' . $query : ''),
            'hledano' => null,
            'nalezeneStranky' => [],
            'url' => $this->app->url(...),
        ];
    }

    /**
     * Přepínač jazyků pro šablonu: kód => [název, adresa, je aktivní]. U novinky vede na její překlad, jinak na protějšek
     * stránky či kategorie, a když ho nemá, na úvod verze. Prázdné pole = web má jediný jazyk.
     *
     * @param array<string, mixed>|null $novinka
     * @return array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}>
     */
    private function jazyky(?array $novinka): array
    {
        $web = $this->app->settings();
        $dalsi = Jazyk::dalsi($web);
        if ($dalsi === []) {
            return [];
        }
        $preklady = [];
        if ($novinka !== null) {
            $original = (int) ($novinka['preklad_z'] ?: $novinka['idc']);
            $preklady = array_map(fn (string $seo): string => 'novinky/' . $seo, $this->app->db()->pairs('SELECT jazyk, seo_link FROM {novinky} WHERE (idc = ? OR preklad_z = ?) AND visible = 1 AND datum <= NOW()', [$original, $original]));
        } elseif ($this->protejsek !== null) {
            // kategorie nebo stránka: originál + jeho překlady
            [$tabulka, $klic, $radek, $cesta] = $this->protejsek;
            $original = (int) ($radek['preklad_z'] ?: $radek[$klic]);
            $podminka = $tabulka === 'stranky' ? ' AND zobrazit = 1' : '';
            $preklady = array_map(fn (string $seo): string => $cesta . $seo, $this->app->db()->pairs("SELECT jazyk, seo_link FROM {{$tabulka}} WHERE ({$klic} = ? OR preklad_z = ?){$podminka}", [$original, $original]));
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

    /**
     * Úprava stránky nebo novinky přímo na webu. Bez práva nedělá nic; s právem připraví odkaz „Upravit zde“
     * a při ?upravit=text vrátí formulář s editorem místo obsahu. Ukládá administrace (akce uloz_text).
     *
     * @param array<string, mixed> $zaznam řádek mc_stranky nebo mc_novinky
     */
    private function upravaNaMiste(string $typ, array $zaznam, string $cesta): ?string
    {
        $auth = $this->app->auth();
        if ($auth->user() === null || !($typ === 'novinka' ? $auth->smiUpravitClanek($zaznam) : $auth->maModul('stranky'))) {
            return null;
        }
        $adresa = $this->app->url($cesta);
        if ($this->app->request->get('upravit') !== 'text') {
            // koncept je vidět jen v náhledu – bez něj by „Upravit zde“ skončilo na stránce Nenalezeno
            $this->upravitZde = $adresa . ($this->app->request->get('nahled') === '1' ? '?nahled=1&upravit=text' : '?upravit=text');

            return null;
        }

        return $this->view->render('upravit', [
            'app' => $this->app, 'typ' => $typ, 'zaznam' => $zaznam,
            'zpet' => $adresa . ($this->app->request->get('nahled') === '1' ? '?nahled=1' : ''),
            'akce' => $this->app->url('admin.php?modul=' . ($typ === 'novinka' ? 'novinky' : 'stranky') . '&akce=uloz_text'),
            'chyba' => $this->app->request->get('chyba') === '1',
        ]);
    }

    /** @var list<array{titulek:string, seo_link:string, uvod:bool}>|null */
    private ?array $strankyMenu = null;

    /** Stránky do hlavní navigace šablony (úvodní stránka vede na kořen webu). */
    private function strankyMenu(): array
    {
        if ($this->strankyMenu === null) {
            $uvod = $this->idUvodu();
            $this->strankyMenu = array_map(
                fn (array $s): array => ['titulek' => $s['titulek'], 'seo_link' => (int) $s['ids'] === $uvod ? '' : $s['seo_link'], 'uvod' => (int) $s['ids'] === $uvod],
                $this->app->db()->all('SELECT ids, titulek, seo_link FROM {stranky} WHERE zobrazit = 1 AND v_menu = 1 AND jazyk = ? ORDER BY poradi, titulek', [Jazyk::sloupecWebu()]),
            );
        }

        return $this->strankyMenu;
    }

    /** Drobečková navigace zobrazené stránky (prvek Drobečky a BreadcrumbList): Úvod a zadané úrovně. */
    private function drobecky(array ...$urovne): void
    {
        $this->kontext()->drobecky = [[t('Úvod'), $this->app->url('')], ...$urovne];
    }

    /** @var array<string, list<array<string, mixed>>> umístění => položky menu (Core\Menu) */
    private array $menu = [];

    /** @return list<array<string, mixed>> */
    private function menu(string $umisteni): array
    {
        return $this->menu[$umisteni] ??= \MiroCMS\Core\Menu::polozky($this->app, $umisteni, Jazyk::sloupecWebu(), $this->idUvodu());
    }

    /**
     * Složí stránku: obsah obalí layoutem webu (hlavička s navigací, patička), doplní SEO a uloží do cache.
     *
     * @param array<string, mixed> $meta
     */
    private function kontext(): \MiroCMS\Stavitel\Kontext
    {
        if ($this->kontext === null) {
            $this->kontext = new \MiroCMS\Stavitel\Kontext($this->app);
            // cesta zobrazené stránky už pro obsah (odkazy filtru a stránkování výpisu kolekce, aktivní položka navigace)
            $this->kontext->cesta = (string) parse_url($this->app->url(ltrim($this->app->request->path(), '/')), PHP_URL_PATH);
        }

        return $this->kontext;
    }

    /**
     * Části webu ze stavitele: obálka kolem obsahu (novinka, výpis, 404), záhlaví a patička. Část bez publikované stavby
     * vrátí null a layout vykreslí svou. Správce vidí v editoru koncept části (?cast=<typ>&stavba=koncept&editor=1).
     *
     * @param array<string, mixed> $meta
     * @return array{0: string, 1: array{hlavicka: ?string, paticka: ?string}, 2: array<string, mixed>}
     */
    private function castiWebu(string $obsah, array $meta, string $jazykyHtml, string $cesta): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $k = $this->kontext();
        $k->menu = ['hlavni' => $this->menu('hlavni'), 'paticka' => $this->menu('paticka')];
        $k->cesta = $cesta;
        $k->jazyky = $jazykyHtml;
        $nahled = isset(\MiroCMS\Stavitel\Casti::TYPY[$r->get('cast')]) && $r->get('stavba') === 'koncept' && $this->app->auth()->isAdmin() ? $r->get('cast') : '';
        $editor = $r->get('editor') === '1' && ($nahled !== '' || ($r->get('stavba') === 'koncept' && $r->get('cast') === ''));
        $jazyk = Jazyk::sloupecWebu();
        // stránka webu může mít vlastní variantu záhlaví a patičky; v editoru varianty rozhoduje parametr ?varianta=
        $ids = ($this->protejsek[0] ?? '') === 'stranky' ? (int) $this->protejsek[2]['ids'] : null;
        $nahledVarianty = preg_match(\MiroCMS\Stavitel\Casti::VZOR_VARIANTY, $r->get('varianta')) ? $r->get('varianta') : '';
        $vykresli = function (string $typ) use ($db, $k, $nahled, $editor, $jazyk, $ids, $nahledVarianty): ?string {
            try {
                $varianta = $nahled === $typ ? $nahledVarianty : \MiroCMS\Stavitel\Casti::variantaStranky($db, $typ, $jazyk, $ids);
                $stavba = \MiroCMS\Stavitel\Casti::stavba($db, $typ, $jazyk, $nahled === $typ, $varianta);
            } catch (\Throwable $e) {
                error_log('Části webu: ' . $e->getMessage()); // web bez tabulky (před migrací) vykreslí části ze šablony

                return null;
            }
            if ($stavba === null) {
                return null;
            }
            $k->editor = $editor && $nahled === $typ;
            $k->zdroj = 'cast:' . $typ . ':' . $jazyk . ($varianta !== '' ? ':' . $varianta : '');
            $html = \MiroCMS\Stavitel\Stavba::html($stavba, $k);
            $k->editor = false;

            return $html;
        };

        $obalka = $meta['cast'] ?? null;
        unset($meta['cast']);
        if ($obalka !== null) {
            $k->obsah = $obsah;
            if (($html = $vykresli($obalka)) !== null) {
                $obsah = $html;
                $meta['stavba'] = true;
            }
            $k->obsah = '';
        }
        $casti = ['hlavicka' => $vykresli('hlavicka'), 'paticka' => $vykresli('paticka')];

        if ($k->typy !== []) {
            $meta['css'] = \MiroCMS\Stavitel\Stavba::css($db, $k)
                // plátno stavitele se po každé změně načítá znovu – přechod mezi stránkami by jen blikal a v prohlížeči hlásil přerušení
                . ($editor ? '@view-transition{navigation:none}' : '');
            if ($k->faq !== [] && !isset($meta['faq'])) {
                $meta['faq'] = $k->faq;
            }
        }
        if ($nahled !== '') {
            $meta['noindex'] = true;
        }

        return [$obsah, $casti, $meta];
    }

    private function stranka(string $titulek, string $obsah, array $meta = [], int $status = 200): Response
    {
        if ($this->zaKoncem) {
            $this->zaKoncem = false;

            return $this->nenalezeno();
        }
        $web = $this->app->settings();
        $seo = new Seo($this->app);
        $novinka = $meta['clanek'] ?? null;
        unset($meta['clanek']);
        if ($status === 200 && empty($meta['noindex'])) {
            Statistika::zaznamenej($this->app, $novinka === null ? null : (int) $novinka['idc']);
        }

        if (($meta['obrazek'] ?? '') !== '' && !preg_match('#^https?://#', $meta['obrazek'])) {
            // sociální sítě berou jen úplnou adresu obrázku
            $meta['obrazek'] = $this->app->request->origin() . $this->app->url(ltrim((string) preg_replace('#^' . preg_quote($this->app->request->basePath(), '#') . '/#', '', $meta['obrazek']), '/'));
        }
        $meta['drobecky'] ??= $this->kontext()->drobecky;
        $jazyky = $this->jazyky($novinka);
        $jazykyHtml = $jazyky === [] ? '' : $this->view->render('jazyky', ['jazyky' => $jazyky]);
        // kanonická adresa: cesta bez parametrů, u stránkování s číslem strany (strana 2 není kopie strany 1)
        $stranaVypisu = $this->app->request->getInt('strana', 1);
        $kanonicka = $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')) . ($stranaVypisu > 1 ? '?strana=' . $stranaVypisu : '');
        [$obsah, $casti, $meta] = $this->castiWebu($obsah, $meta, $jazykyHtml, (string) parse_url($kanonicka, PHP_URL_PATH));
        $html = $this->view->render('base', [
            'web' => $web,
            'titulek' => $titulek,
            'meta' => $meta + ['hlavni' => false, 'popis' => '', 'klicova_slova' => $web->get('klicova_slova'), 'obrazek' => '', 'typ' => 'website', 'noindex' => false],
            'obsah' => $obsah,
            'hlava' => $seo->hlava($titulek, $meta + ['jazyky' => $jazyky], $novinka),
            'pata' => $seo->pata() . ($this->upravitZde !== '' ? '<a class="mc-upravit-zde" href="' . e($this->upravitZde) . '">' . e(t('Upravit zde')) . '</a>' : ''),
            'stranky' => $this->strankyMenu(),
            'menu' => $this->menu('hlavni'),
            'menu_paticka' => $this->menu('paticka'),
            'menu_html' => \MiroCMS\Core\Menu::html(...),
            'jazyk' => Jazyk::kod(),
            'jazyky_html' => $jazykyHtml,
            'casti' => $casti,
            'url' => $this->app->url(...),
            'kanonicka' => $kanonicka,
        ]);
        $html = ObrazkyHtml::dopln($this->app->db(), $html); // rozměry a barva podkladu obrázků – méně poskakování stránky
        // image/web.js jen na stránkách, které ho potřebují (galerie a fotky v textu, video, sdílení, záložky, karusel, okno, formulář)
        if (!preg_match('/data-(vlozit|sdilet|kopirovat|zalozky|karusel|formular|odeslano)|popover role="dialog"|galerie|class="(?:text|perex)[" ][\s\S]*?<img|cookies-/', $html)) {
            $html = (string) preg_replace('#<script src="[^"]*/image/web\.js[^"]*"[^>]*></script>\n?#', '', $html);
        }
        if ($status === 200 && empty($meta['noindex']) && $this->app->request->get('nahled') === '') {
            Cache::uloz($this->app, $html, $novinka === null ? null : (int) $novinka['idc']);
        }

        return Response::html($html, $status);
    }
}
