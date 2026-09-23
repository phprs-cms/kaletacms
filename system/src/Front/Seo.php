<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\App;

/**
 * SEO, GEO, měření a souhlasy: robots.txt, sitemap.xml, llms.txt, Markdown verze článku,
 * značky do <head> (ověření, strukturovaná data, měřicí kódy) a cookie lišta před </body>.
 * Vše se řídí Nastavením; layouty jen vypíší proměnné $hlava a $pata.
 */
final class Seo
{
    /** Roboti AI služeb, kterých se týká přepínač v Nastavení. */
    private const array AI_ROBOTI = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'cohere-ai'];

    private readonly string $web;

    /** Kořen webu bez předpony jazykové verze. */
    private readonly string $koren;

    public function __construct(private readonly App $app)
    {
        $this->web = $app->request->origin() . $app->url('');
        $this->koren = $app->request->origin() . $app->request->basePath() . '/';
    }

    public function robotsTxt(): string
    {
        $s = $this->app->settings();
        if (!$s->bool('indexovani')) {
            return "# Indexování webu je vypnuté v Nastavení.\nUser-agent: *\nDisallow: /\n";
        }
        $radky = ['User-agent: *', 'Disallow: /admin.php', 'Disallow: /hledani', 'Disallow: /*?nahled=', ''];
        if ($s->get('ai_crawlery') === 'zakazat') {
            foreach (self::AI_ROBOTI as $robot) {
                $radky[] = 'User-agent: ' . $robot;
            }
            array_push($radky, 'Disallow: /', '');
        }
        if (trim($s->get('robots_extra')) !== '') {
            array_push($radky, trim($s->get('robots_extra')), '');
        }
        $radky[] = 'Sitemap: ' . $this->web . 'sitemap.xml';

        return implode("\n", $radky) . "\n";
    }

    public function sitemapXml(): string
    {
        $db = $this->app->db();
        // mapa webu je jedna pro všechny jazykové verze: adresa dostane předponu podle jazyka záznamu
        $url = fn (string $cesta, ?string $zmena = null, string $priorita = '0.5', string $jazyk = ''): string => '<url><loc>' . e($this->koren . ($jazyk !== '' ? $jazyk . '/' : '') . $cesta) . '</loc>'
            . ($zmena !== null ? '<lastmod>' . date('c', strtotime($zmena)) . '</lastmod>' : '') . '<priority>' . $priorita . '</priority></url>';

        $xml = [$url('', (string) $db->value('SELECT MAX(COALESCE(zmeneno, datum)) FROM {clanky} WHERE visible = 1 AND datum <= NOW()') ?: null, '1.0')];
        foreach (\MiroCMS\Core\Jazyk::dalsi($this->app->settings()) as $jazyk) {
            $xml[] = $url('', null, '0.9', $jazyk);
        }
        foreach ($db->all('SELECT seo_link, jazyk FROM {topic} WHERE zobrazit = 1') as $r) {
            $xml[] = $url('rubrika/' . $r['seo_link'], null, '0.6', $r['jazyk']);
        }
        foreach ($db->all('SELECT seo_link, zmeneno, jazyk FROM {stranky} WHERE zobrazit = 1') as $r) {
            $xml[] = $url($r['seo_link'], $r['zmeneno'], '0.4', $r['jazyk']);
        }
        foreach ($db->all('SELECT seo_link, jazyk, COALESCE(zmeneno, datum) AS zmena FROM {clanky} WHERE visible = 1 AND noindex = 0 AND datum <= NOW() AND typ_clanku = 1 ORDER BY datum DESC LIMIT 45000') as $r) {
            $xml[] = $url('clanek/' . $r['seo_link'], $r['zmena'], '0.8', $r['jazyk']);
        }

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $xml) . "\n</urlset>\n";
    }

    /** Podcastový kanál (RSS 2.0 + iTunes): články se zvukovým souborem, pro Apple Podcasts, Spotify a další aplikace. */
    public function podcastXml(): string
    {
        $s = $this->app->settings();
        $epizody = $this->app->db()->all(
            "SELECT titulek, seo_link, uvod, datum, medium_url, obrazek FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND pristup = 0 AND jazyk = ?
             AND (medium_url LIKE '%.mp3' OR medium_url LIKE '%.m4a' OR medium_url LIKE '%.ogg' OR medium_url LIKE '%.oga' OR medium_url LIKE '%.wav' OR medium_url LIKE '%.aac') ORDER BY datum DESC LIMIT 300",
            [\MiroCMS\Core\Jazyk::sloupecWebu()],
        );
        $obal = $s->get('og_obrazek') !== '' ? $s->get('og_obrazek') : $s->get('logo_webu');
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel>'
            . '<title>' . e($s->get('nazev_webu')) . '</title><link>' . e($this->web) . '</link><description>' . e($s->get('popis_webu') ?: $s->get('nazev_webu')) . '</description>'
            . '<language>' . \MiroCMS\Core\Jazyk::kod() . '</language><itunes:author>' . e($s->get('nazev_webu')) . '</itunes:author><itunes:explicit>false</itunes:explicit>'
            . ($obal !== '' ? '<itunes:image href="' . e($this->absolutni($obal)) . '"/>' : '') . "\n";
        foreach ($epizody as $e) {
            $soubor = preg_match('#^(https?:)?//#i', $e['medium_url']) ? null : MIROCMS_ROOT . '/' . ltrim((string) parse_url($e['medium_url'], PHP_URL_PATH), '/');
            $typ = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav'][strtolower(pathinfo((string) parse_url($e['medium_url'], PHP_URL_PATH), PATHINFO_EXTENSION))] ?? 'audio/ogg';
            $xml .= '<item><title>' . e($e['titulek']) . '</title><link>' . e($this->web . 'clanek/' . $e['seo_link']) . '</link><guid isPermaLink="true">' . e($this->web . 'clanek/' . $e['seo_link']) . '</guid>'
                . '<pubDate>' . date('r', strtotime($e['datum'])) . '</pubDate><description>' . e(trim(strip_tags($e['uvod']))) . '</description>'
                . '<enclosure url="' . e($this->absolutni($e['medium_url'])) . '" length="' . ($soubor !== null && is_file($soubor) ? filesize($soubor) : 0) . '" type="' . $typ . '"/></item>' . "\n";
        }

        return $xml . "</channel></rss>\n";
    }

    /** Google News sitemap: články za poslední dva dny. */
    public function sitemapNewsXml(): string
    {
        $nazev = e($this->app->settings()->get('nazev_webu'));
        $xml = [];
        foreach ($this->app->db()->all('SELECT titulek, seo_link, datum FROM {clanky} WHERE visible = 1 AND noindex = 0 AND komercni = 0 AND typ_clanku = 1 AND datum <= NOW() AND datum > NOW() - INTERVAL 2 DAY AND jazyk = ? ORDER BY datum DESC LIMIT 1000', [\MiroCMS\Core\Jazyk::sloupecWebu()]) as $c) {
            $xml[] = '<url><loc>' . e($this->web . 'clanek/' . $c['seo_link']) . '</loc><news:news><news:publication><news:name>' . $nazev . '</news:name><news:language>' . \MiroCMS\Core\Jazyk::kod() . '</news:language></news:publication>'
                . '<news:publication_date>' . date('c', strtotime($c['datum'])) . '</news:publication_date><news:title>' . e($c['titulek']) . '</news:title></news:news></url>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n" . implode("\n", $xml) . "\n</urlset>\n";
    }

    /**
     * JSON Feed 1.1 (https://jsonfeed.org) - moderní obdoba RSS s plným textem.
     *
     * @param list<array<string, mixed>> $clanky
     * @return array<string, mixed>
     */
    public function jsonFeed(array $clanky): array
    {
        $s = $this->app->settings();

        return [
            'version' => 'https://jsonfeed.org/version/1.1', 'title' => $s->get('nazev_webu'), 'description' => $s->get('popis_webu'),
            'home_page_url' => $this->web, 'feed_url' => $this->web . 'feed.json', 'language' => \MiroCMS\Core\Jazyk::kod(),
            'items' => array_map(fn (array $c): array => array_filter([
                'id' => 'clanek-' . $c['idc'], 'url' => $this->web . 'clanek/' . $c['seo_link'], 'title' => $c['titulek'],
                'summary' => trim(strip_tags($c['uvod'])), 'content_html' => $c['uvod'] . $c['text'],
                'image' => $c['obrazek'] !== '' ? $this->absolutni($c['obrazek']) : null,
                'date_published' => date('c', strtotime($c['datum'])), 'date_modified' => $c['zmeneno'] ? date('c', strtotime($c['zmeneno'])) : null,
                'authors' => $c['autor_jm'] !== null ? [['name' => $c['autor_jm']]] : null, 'tags' => [$c['tema_jm']],
            ]), $clanky),
        ];
    }

    /**
     * IndexNow: oznámí vyhledávačům (Bing, Seznam, Yandex) novou nebo změněnou adresu.
     * Volá se po uložení vydaného článku; selhání se ignoruje, web kvůli němu nesmí čekat.
     */
    /** @param string $adresa adresa na webu od kořene serveru (App::urlClanku()) */
    public function indexNow(string $adresa): void
    {
        $s = $this->app->settings();
        $host = (string) parse_url($this->web, PHP_URL_HOST);
        if (!$s->bool('indexnow') || $s->get('indexnow_klic') === '' || !$s->bool('indexovani') || in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test')) {
            return;
        }
        $data = json_encode(['host' => $host, 'key' => $s->get('indexnow_klic'), 'keyLocation' => $this->app->request->origin() . $this->app->request->basePath() . '/' . $s->get('indexnow_klic') . '.txt', 'urlList' => [$this->app->request->origin() . $adresa]]);
        @file_get_contents('https://api.indexnow.org/indexnow', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json; charset=utf-8\r\n", 'content' => $data, 'timeout' => 3, 'ignore_errors' => true,
        ]]));
    }

    /** llms.txt - průvodce webem pro jazykové modely (https://llmstxt.org). */
    public function llmsTxt(): string
    {
        $s = $this->app->settings();
        $db = $this->app->db();
        $md = $s->bool('markdown_clanky') ? '.md' : '';
        $radky = ['# ' . $s->get('nazev_webu'), ''];
        if ($s->get('popis_webu') !== '') {
            array_push($radky, '> ' . str_replace("\n", ' ', $s->get('popis_webu')), '');
        }
        $radky[] = '## ' . t('Rubriky');
        foreach ($db->all('SELECT nazev, seo_link, popis FROM {topic} WHERE zobrazit = 1 AND jazyk = ? ORDER BY hodnost DESC, nazev', [\MiroCMS\Core\Jazyk::sloupecWebu()]) as $r) {
            $popis = trim(strip_tags($r['popis']));
            $radky[] = '- [' . $r['nazev'] . '](' . $this->web . 'rubrika/' . $r['seo_link'] . ')' . ($popis !== '' ? ': ' . $popis : '');
        }
        array_push($radky, '', '## ' . t('Nejnovější články'));
        foreach ($db->all('SELECT titulek, seo_link, uvod FROM {clanky} WHERE visible = 1 AND datum <= NOW() AND typ_clanku = 1 AND noindex = 0 AND jazyk = ? ORDER BY datum DESC LIMIT 30', [\MiroCMS\Core\Jazyk::sloupecWebu()]) as $c) {
            $radky[] = '- [' . $c['titulek'] . '](' . $this->web . 'clanek/' . $c['seo_link'] . $md . '): ' . mb_strimwidth(trim(strip_tags($c['uvod'])), 0, 200, '…');
        }

        return implode("\n", $radky) . "\n";
    }

    /** @param array<string, mixed> $clanek */
    public function clanekMarkdown(array $clanek): string
    {
        $hlava = ['# ' . $clanek['titulek'], ''];
        $hlava[] = '- ' . t('Autor') . ': ' . ($clanek['autor_jm'] ?? $this->app->settings()->get('nazev_webu'));
        $hlava[] = '- ' . t('Vydáno') . ': ' . date('Y-m-d', strtotime($clanek['datum'])) . ($clanek['zmeneno'] ? ', ' . t('aktualizováno') . ': ' . date('Y-m-d', strtotime($clanek['zmeneno'])) : '');
        $hlava[] = '- ' . t('Rubrika') . ': ' . $clanek['tema_jm'];
        $hlava[] = '- ' . t('Zdroj') . ': ' . $this->web . 'clanek/' . $clanek['seo_link'];

        $shrnuti = array_filter(array_map(trim(...), preg_split('/\R/', (string) $clanek['shrnuti']) ?: []));
        if ($shrnuti !== []) {
            array_push($hlava, '', '## ' . t('Ve zkratce'), '', ...array_map(fn (string $b): string => '- ' . $b, $shrnuti));
        }

        return implode("\n", $hlava) . "\n\n" . self::htmlNaMarkdown($clanek['uvod']) . "\n\n" . self::htmlNaMarkdown($clanek['text']) . "\n";
    }

    /**
     * Značky před </head>.
     *
     * @param array<string, mixed> $meta     meta údaje stránky (typ, popis, obrazek, noindex...)
     * @param array<string, mixed>|null $clanek celý článek, jde-li o stránku článku
     */
    public function hlava(string $titulek, array $meta, ?array $clanek): string
    {
        $s = $this->app->settings();
        $h = [];
        if ($s->get('cookies_rezim') === 'externi' && trim($s->get('cookies_externi_kod')) !== '') {
            $h[] = $s->get('cookies_externi_kod');
        }
        if (!$s->bool('indexovani')) {
            $h[] = '<meta name="robots" content="noindex, nofollow">';
        } elseif ($clanek !== null && $clanek['noindex']) {
            $h[] = '<meta name="robots" content="noindex, follow">';
        }
        if ($s->get('overeni_google') !== '') {
            $h[] = '<meta name="google-site-verification" content="' . e($s->get('overeni_google')) . '">';
        }
        if ($s->get('overeni_bing') !== '') {
            $h[] = '<meta name="msvalidate.01" content="' . e($s->get('overeni_bing')) . '">';
        }
        if (($meta['obrazek'] ?? '') === '' && $s->get('og_obrazek') !== '') {
            $h[] = '<meta property="og:image" content="' . e($this->absolutni($s->get('og_obrazek'))) . '">';
        }
        // jazykové verze: hreflang u článku jen na existující překlady, jinde na úvod každé verze
        foreach ($meta['jazyky'] ?? [] as $kod => $j) {
            if ($j['preklad'] || ($clanek === null && ($meta['hlavni'] ?? false))) {
                $h[] = '<link rel="alternate" hreflang="' . e($kod) . '" href="' . e($this->app->request->origin() . $j['url']) . '">';
            }
        }
        $h[] = '<meta property="og:locale" content="' . \MiroCMS\Core\Jazyk::DOSTUPNE[\MiroCMS\Core\Jazyk::kod()][1] . '">';
        if (($meta['popis'] ?? '') !== '') {
            $h[] = '<meta property="og:description" content="' . e($meta['popis']) . '">';
        }
        $h[] = '<meta name="twitter:card" content="' . (($meta['obrazek'] ?? '') !== '' || $s->get('og_obrazek') !== '' ? 'summary_large_image' : 'summary') . '">';
        if ($clanek !== null && $s->bool('markdown_clanky')) {
            $h[] = '<link rel="alternate" type="text/markdown" href="' . e($this->web . 'clanek/' . $clanek['seo_link'] . '.md') . '">';
        }
        if ($s->bool('schema_org')) {
            $h[] = '<script type="application/ld+json">' . json_encode($this->strukturovanaData($titulek, $meta, $clanek), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        }
        $h[] = Identita::hlava($s, $this->app->request->basePath());
        // společné prvky článku (fotogalerie, prohlížečka fotek...) pro všechny šablony
        $verze = rawurlencode(MIROCMS_VERSION);
        $h[] = '<link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '?v=' . $verze . '">';
        $push = new \MiroCMS\Core\Push($this->app->db(), $s);
        $sPush = $push->zapnuto() && $push->verejnyKlic() !== '';
        if ($sPush) {
            $h[] = '<link rel="manifest" href="' . e($this->app->url('manifest.webmanifest')) . '"><meta name="mc-push" content="' . e($push->verejnyKlic()) . '" data-koren="' . e($this->app->url('')) . '">';
        }
        $h[] = '<script src="' . e($this->app->url('image/web.js')) . '?v=' . $verze . '" defer' . self::textySkriptu($sPush) . '></script>';
        $h[] = '<style>@media (max-width: 760px) { .jen-pocitac { display: none !important; } } @media (min-width: 761px) { .jen-mobil { display: none !important; } }</style>';
        $h[] = $this->mereni();
        if (trim($s->get('kod_hlava')) !== '') {
            $h[] = $s->get('kod_hlava');
        }

        return implode("\n", array_filter($h)) . "\n";
    }

    /** České texty, které čtenáři vypisuje image/web.js (tam jsou obalené T() nebo A()); slovník webu je překládá jako každý jiný text. */
    public const array TEXTY_SKRIPTU = ['Předchozí fotka', 'Další fotka', 'Zavřít'];

    /** Totéž pro oznámení Web Push – posílají se jen na webu, kde jsou oznámení zapnutá. */
    public const array TEXTY_SKRIPTU_PUSH = [
        'Zapnout oznámení', 'Vypnout oznámení', 'Oznámení jsou v tomto prohlížeči zapnutá.', 'Oznámení jsou vypnutá.',
        'Oznámení se nepodařilo zapnout.', 'Oznámení se nepodařilo zapnout. Zkuste to později.',
        'Oznámení máte pro tento web v prohlížeči zakázaná. Povolíte je v nastavení webu u adresního řádku.',
    ];

    /**
     * Atribut data-texty pro značku <script> s image/web.js: překlady textů skriptu (česky => překlad) jako JSON.
     * Bez dalšího požadavku a bez inline skriptu; česká verze nepotřebuje nic – skript má češtinu v sobě.
     */
    private static function textySkriptu(bool $sPush): string
    {
        $preklady = [];
        foreach ([...self::TEXTY_SKRIPTU, ...($sPush ? self::TEXTY_SKRIPTU_PUSH : [])] as $cesky) {
            if (t($cesky) !== $cesky) {
                $preklady[$cesky] = t($cesky);
            }
        }

        return $preklady === [] ? '' : ' data-texty="' . e((string) json_encode($preklady, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    }

    /** Cookie lišta vestavěného řešení a marketingové kódy; vkládá se před </body>. */
    public function pata(): string
    {
        $s = $this->app->settings();
        $rezim = $s->get('cookies_rezim');
        $marketing = trim($s->get('kod_marketing'));
        $html = $marketing === '' ? '' : self::cekaNaSouhlas($marketing, $rezim);
        // na marketing se lišta ptá i kvůli kódům reklamních sítí z rozšíření Reklama – jinak by se nikdy nespustily
        $maMarketing = $marketing !== '' || (\MiroCMS\Core\Rozsireni::je($s, 'reklama')
            && $this->app->db()->value("SELECT 1 FROM {reklama} WHERE aktivni = 1 AND typ = 'kod' LIMIT 1") !== null);
        if ($rezim !== 'vestavena' || (!$this->meriSCookies() && !$maMarketing)) {
            return $html;
        }
        $view = new \MiroCMS\Core\View([MIROCMS_SYSTEM . '/views/front']);

        return $html . $view->render('cookies', [
            'text' => $s->get('cookies_text'),
            'zasady' => $s->get('cookies_zasady_url'),
            'analytika' => $this->meriSCookies(),
            'marketing' => $maMarketing,
            'evidence' => $s->bool('cookies_evidence') ? $this->app->url('souhlas') : '',
        ]);
    }

    /**
     * Marketingový kód (vlastní i kód reklamní sítě) podle režimu cookies: bez lišty se vypíše rovnou; vestavěná lišta ho po souhlasu
     * rozbalí ze značky <template>; u externí služby dostanou skripty značení, kterému rozumí Cookiebot a služby s ním kompatibilní
     * (stejné jako u měřicích kódů) – spustí je až ona.
     */
    public static function cekaNaSouhlas(string $kod, string $rezim): string
    {
        return match ($rezim) {
            'zadna' => $kod,
            'externi' => (string) preg_replace('/<script(?![^>]*\btype\s*=)/i', '<script type="text/plain" data-cookieconsent="marketing"', $kod),
            default => '<template data-souhlas="marketing">' . $kod . '</template>',
        };
    }

    private function meriSCookies(): bool
    {
        $s = $this->app->settings();

        return $s->get('ga4_id') !== '' || ($s->get('matomo_url') !== '' && $s->int('matomo_id') > 0);
    }

    private function mereni(): string
    {
        $s = $this->app->settings();
        // se souhlasem: skript je "text/plain", dokud ho lišta (vestavěná i Cookiebot) nepovolí
        $ceka = $s->get('cookies_rezim') !== 'zadna';
        $atr = $ceka ? ' type="text/plain" data-souhlas="analytika" data-cookieconsent="statistics"' : '';
        $kod = '';
        if ($s->get('ga4_id') !== '') {
            $id = $s->get('ga4_id');
            $kod .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
                . ($ceka ? "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});" : '')
                . "gtag('js',new Date());gtag('config','{$id}');</script>\n"
                . "<script async{$atr} src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>\n";
        }
        if ($s->get('matomo_url') !== '' && $s->int('matomo_id') > 0) {
            $adresa = json_encode(rtrim($s->get('matomo_url'), '/') . '/', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
            $kod .= "<script{$atr}>var _paq=window._paq=window._paq||[];_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);(function(){var u={$adresa};_paq.push(['setTrackerUrl',u+'matomo.php']);_paq.push(['setSiteId','{$s->int('matomo_id')}']);var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();</script>\n";
        }
        if ($s->get('plausible_domena') !== '') {
            $kod .= '<script defer data-domain="' . e($s->get('plausible_domena')) . '" src="https://plausible.io/js/script.js"></script>' . "\n";
        }

        return $kod;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $clanek
     * @return array<string, mixed>
     */
    private function strukturovanaData(string $titulek, array $meta, ?array $clanek): array
    {
        $s = $this->app->settings();
        $vydavatel = array_filter([
            '@type' => 'Organization',
            'name' => $s->get('nazev_webu'),
            'url' => $this->web,
            'logo' => $s->get('logo_webu') !== '' ? $this->absolutni($s->get('logo_webu')) : null,
            'sameAs' => array_values(array_filter(array_map($s->get(...), ['soc_facebook', 'soc_instagram', 'soc_x', 'soc_youtube', 'soc_linkedin']))) ?: null,
        ]);
        if ($clanek === null) {
            return ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $s->get('nazev_webu'), 'url' => $this->web,
                'description' => $s->get('popis_webu'), 'inLanguage' => \MiroCMS\Core\Jazyk::kod(), 'publisher' => $vydavatel,
                'potentialAction' => ['@type' => 'SearchAction', 'target' => $this->web . 'hledani?q={q}', 'query-input' => 'required name=q']];
        }

        return ['@context' => 'https://schema.org', '@graph' => [
            array_filter([
                '@type' => (int) ($clanek['zive'] ?? 0) > 0 ? 'LiveBlogPosting' : 'NewsArticle',
                'headline' => mb_substr($clanek['titulek'], 0, 110),
                'description' => $meta['popis'] ?? '',
                'image' => $clanek['obrazek'] !== '' ? [$this->absolutni($clanek['obrazek'])] : null,
                'datePublished' => date('c', strtotime($clanek['datum'])),
                'dateModified' => date('c', strtotime($clanek['aktualizovano'] ?? $clanek['zmeneno'] ?? $clanek['datum'])),
                'author' => $clanek['autor_jm'] !== null ? array_filter(['@type' => 'Person', 'name' => $clanek['autor_jm'], 'url' => $this->web . 'autor/' . (int) $clanek['autor'],
                    'jobTitle' => $clanek['autor_pozice'] ?? '', 'description' => trim((string) ($clanek['autor_bio'] ?? '')), 'image' => ($clanek['autor_foto'] ?? '') !== '' ? $this->absolutni($clanek['autor_foto']) : '',
                    'sameAs' => ($clanek['autor_url'] ?? '') !== '' ? $clanek['autor_url'] : '']) : $vydavatel,
                'publisher' => $vydavatel,
                'articleSection' => $clanek['tema_jm'],
                'keywords' => implode(', ', array_column($clanek['stitky'] ?? [], 'nazev')) ?: null,
                'mainEntityOfPage' => $this->web . 'clanek/' . $clanek['seo_link'],
                'inLanguage' => \MiroCMS\Core\Jazyk::kod(),
                'coverageStartTime' => (int) ($clanek['zive'] ?? 0) > 0 ? date('c', strtotime($clanek['datum'])) : null,
                'coverageEndTime' => (int) ($clanek['zive'] ?? 0) === 2 ? date('c', strtotime($clanek['zmeneno'] ?? $clanek['datum'])) : null,
                'liveBlogUpdate' => (int) ($clanek['zive'] ?? 0) > 0 && empty($clanek['zamceno']) ? array_map(fn (array $z): array => [
                    '@type' => 'BlogPosting', 'headline' => mb_strimwidth(trim(strip_tags($z['text'])), 0, 110, '…'), 'datePublished' => date('c', strtotime($z['cas'])), 'articleBody' => trim(strip_tags($z['text'])),
                ], $this->app->db()->all('SELECT cas, text FROM {zive} WHERE idc = ? ORDER BY idz DESC LIMIT 50', [$clanek['idc']])) ?: null : null,
                'associatedMedia' => ($clanek['medium_url'] ?? '') !== '' && empty($clanek['zamceno']) ? ['@type' => preg_match('#\.(mp3|m4a|ogg|oga|wav|aac)$|spotify#i', $clanek['medium_url']) ? 'AudioObject' : 'VideoObject', 'name' => $clanek['titulek'],
                    'description' => $meta['popis'] ?? $clanek['titulek'], 'uploadDate' => date('c', strtotime($clanek['datum'])), 'contentUrl' => $this->absolutni($clanek['medium_url']),
                    'thumbnailUrl' => $clanek['obrazek'] !== '' ? $this->absolutni($clanek['obrazek']) : null] : null,
                // zamčený obsah: vyhledávače vědí, že nejde o maskování (cloaking)
                'isAccessibleForFree' => (int) ($clanek['pristup'] ?? 0) > 0 ? 'False' : null,
                'hasPart' => (int) ($clanek['pristup'] ?? 0) > 0 ? ['@type' => 'WebPageElement', 'isAccessibleForFree' => 'False', 'cssSelector' => '.clanek-text'] : null,
            ]),
            ...(($clanek['recenze_hodnoceni'] ?? null) !== null && $clanek['recenze_predmet'] !== '' ? [[
                '@type' => 'Review', 'itemReviewed' => ['@type' => 'Thing', 'name' => $clanek['recenze_predmet']],
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $clanek['recenze_hodnoceni'], 'bestRating' => 100, 'worstRating' => 0],
                'author' => $clanek['autor_jm'] !== null ? ['@type' => 'Person', 'name' => $clanek['autor_jm']] : $vydavatel, 'publisher' => $vydavatel,
                'url' => $this->web . 'clanek/' . $clanek['seo_link'],
            ]] : []),
            ...($this->faqData($clanek)),
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $s->get('nazev_webu'), 'item' => $this->web],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $clanek['tema_jm'], 'item' => $this->web . 'rubrika/' . $clanek['tema_seo']],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $clanek['titulek']],
            ]],
        ]];
    }

    /**
     * Otázky a odpovědi článku: text "otázka \n odpověď \n\n ..." -> dvojice.
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function faq(?string $text): array
    {
        $dvojice = [];
        foreach (preg_split('/\R\s*\R/', trim((string) $text)) ?: [] as $blok) {
            $radky = preg_split('/\R/', trim($blok), 2) ?: [];
            if (count($radky) === 2 && trim($radky[0]) !== '' && trim($radky[1]) !== '') {
                $dvojice[] = [trim($radky[0]), trim($radky[1])];
            }
        }

        return $dvojice;
    }

    /** @return list<array<string, mixed>> */
    private function faqData(array $clanek): array
    {
        $faq = self::faq($clanek['faq'] ?? '');

        return $faq === [] ? [] : [['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $d): array => [
            '@type' => 'Question', 'name' => $d[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $d[1]],
        ], $faq)]];
    }

    private function absolutni(string $adresa): string
    {
        if (preg_match('#^https?://#i', $adresa)) {
            return $adresa;
        }

        return str_starts_with($adresa, '/') ? $this->app->request->origin() . $adresa : $this->koren . $adresa;
    }

    /** Jednoduchý převod HTML článku na Markdown - nadpisy, odstavce, seznamy, odkazy, citace, obrázky. */
    private static function htmlNaMarkdown(string $html): string
    {
        $md = preg_replace('/\s+/', ' ', $html) ?? $html;
        $nahrady = [
            '#<h2[^>]*>(.*?)</h2>#i' => "\n\n## $1\n\n", '#<h3[^>]*>(.*?)</h3>#i' => "\n\n### $1\n\n", '#<h4[^>]*>(.*?)</h4>#i' => "\n\n#### $1\n\n",
            '#<(strong|b)>(.*?)</\1>#i' => '**$2**', '#<(em|i)>(.*?)</\1>#i' => '*$2*',
            '#<a [^>]*href="([^"]*)"[^>]*>(.*?)</a>#i' => '[$2]($1)',
            '#<img [^>]*src="([^"]*)"[^>]*alt="([^"]*)"[^>]*>#i' => '![$2]($1)', '#<img [^>]*src="([^"]*)"[^>]*>#i' => '![]($1)',
            '#<figcaption[^>]*>(.*?)</figcaption>#i' => "\n*$1*\n",
            '#<li[^>]*>(.*?)</li>#i' => "\n- $1", '#</(ul|ol)>#i' => "\n\n",
            '#<blockquote[^>]*>(.*?)</blockquote>#i' => "\n\n> $1\n\n",
            '#<br\s*/?>#i' => "\n", '#</p>#i' => "\n\n", '#<hr[^>]*>#i' => "\n\n---\n\n",
        ];
        $md = preg_replace(array_keys($nahrady), array_values($nahrady), $md) ?? $md;
        $md = html_entity_decode(strip_tags($md), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $md = preg_replace(['/[ \t]+\n/', '/\n{3,}/', '/^[ \t]+/m'], ["\n", "\n\n", ''], $md) ?? $md;

        return trim($md);
    }
}
