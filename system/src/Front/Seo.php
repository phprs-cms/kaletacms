<?php

declare(strict_types=1);

namespace MiroCMS\Front;

use MiroCMS\Core\App;

/**
 * SEO, GEO, měření a souhlasy: robots.txt, sitemap.xml, llms.txt, Markdown verze novinky,
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

        // jen zapnuté jazykové verze; obsah vypnutého jazyka na webu není
        $jazyky = ['', ...\MiroCMS\Core\Jazyk::dalsi($this->app->settings())];
        $vJazyku = ' AND jazyk IN (' . implode(',', array_fill(0, count($jazyky), '?')) . ')';
        $xml = [$url('', null, '1.0')];
        foreach (array_slice($jazyky, 1) as $jazyk) {
            $xml[] = $url('', null, '0.9', $jazyk);
        }
        $uvod = $this->app->settings()->int('titulni_stranka');
        foreach ($db->all('SELECT seo_link, zmeneno, jazyk FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND smazano IS NULL AND ids <> ? AND (preklad_z IS NULL OR preklad_z <> ?)' . $vJazyku, [$uvod, $uvod, ...$jazyky]) as $r) {
            $xml[] = $url($r['seo_link'], $r['zmeneno'], '0.8', $r['jazyk']);
        }
        foreach ($db->all('SELECT k.seo_link AS kolekce, p.seo_link, p.jazyk, COALESCE(p.zmeneno, p.datum) AS zmena FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.jazyk IN (' . implode(',', array_fill(0, count($jazyky), '?')) . ') LIMIT 5000', $jazyky) as $r) {
            $xml[] = $url($r['kolekce'] . '/' . $r['seo_link'], $r['zmena'], '0.5', $r['jazyk']);
        }
        // výpis novinek, kategorie a štítky jen tam, kde nějaká vydaná novinka je
        $vydane = 'visible = 1 AND datum <= NOW() AND smazano IS NULL';
        foreach ($db->all("SELECT jazyk, MAX(COALESCE(zmeneno, datum)) AS zmena FROM {novinky} WHERE {$vydane}{$vJazyku} GROUP BY jazyk", $jazyky) as $r) {
            $xml[] = $url('novinky', $r['zmena'], '0.6', $r['jazyk']);
        }
        foreach ($db->all("SELECT k.seo_link, k.jazyk FROM {kategorie} k WHERE EXISTS (SELECT 1 FROM {novinky} n WHERE n.tema = k.idt AND n.{$vydane}) AND k.jazyk IN (" . implode(',', array_fill(0, count($jazyky), '?')) . ')', $jazyky) as $r) {
            $xml[] = $url('novinky/kategorie/' . $r['seo_link'], null, '0.4', $r['jazyk']);
        }
        foreach ($db->all("SELECT seo_link, jazyk, COALESCE(zmeneno, datum) AS zmena FROM {novinky} WHERE {$vydane} AND noindex = 0{$vJazyku} ORDER BY datum DESC LIMIT 45000", $jazyky) as $r) {
            $xml[] = $url('novinky/' . $r['seo_link'], $r['zmena'], '0.5', $r['jazyk']);
        }

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $xml) . "\n</urlset>\n";
    }

    /**
     * JSON Feed 1.1 (https://jsonfeed.org) - moderní obdoba RSS s plným textem.
     *
     * @param list<array<string, mixed>> $novinky
     * @return array<string, mixed>
     */
    public function jsonFeed(array $novinky): array
    {
        $s = $this->app->settings();

        return [
            'version' => 'https://jsonfeed.org/version/1.1', 'title' => $s->get('nazev_webu'), 'description' => $s->get('popis_webu'),
            'home_page_url' => $this->web, 'feed_url' => $this->web . 'feed.json', 'language' => \MiroCMS\Core\Jazyk::kod(),
            'items' => array_map(fn (array $c): array => array_filter([
                'id' => 'novinka-' . $c['idc'], 'url' => $this->web . 'novinky/' . $c['seo_link'], 'title' => $c['titulek'],
                'summary' => trim(strip_tags($c['uvod'])), 'content_html' => $c['uvod'] . $c['text'],
                'image' => $c['obrazek'] !== '' ? $this->absolutni($c['obrazek']) : null,
                'date_published' => date('c', strtotime($c['datum'])), 'date_modified' => $c['zmeneno'] ? date('c', strtotime($c['zmeneno'])) : null,
                'authors' => $c['autor_jm'] !== null ? [['name' => $c['autor_jm']]] : null, 'tags' => [$c['tema_jm']],
            ]), $novinky),
        ];
    }

    /**
     * IndexNow: oznámí vyhledávačům (Bing, Seznam, Yandex) novou nebo změněnou adresu.
     * Volá se po uložení vydané novinky; selhání se ignoruje, web kvůli němu nesmí čekat.
     */
    /** @param string $adresa adresa na webu od kořene serveru (App::urlNovinky()) */
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
        $radky[] = '## ' . t('Stránky');
        $uvod = $s->int('titulni_stranka');
        foreach ($db->all('SELECT ids, titulek, seo_link, popis FROM {stranky} WHERE zobrazit = 1 AND jazyk = ? ORDER BY poradi, titulek', [\MiroCMS\Core\Jazyk::sloupecWebu()]) as $r) {
            $radky[] = '- [' . $r['titulek'] . '](' . $this->web . ((int) $r['ids'] === $uvod ? '' : $r['seo_link']) . ')' . ($r['popis'] !== '' ? ': ' . $r['popis'] : '');
        }
        array_push($radky, '', '## ' . t('Novinky'));
        foreach ($db->all('SELECT titulek, seo_link, uvod FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND noindex = 0 AND smazano IS NULL AND jazyk = ? ORDER BY datum DESC LIMIT 30', [\MiroCMS\Core\Jazyk::sloupecWebu()]) as $c) {
            $radky[] = '- [' . $c['titulek'] . '](' . $this->web . 'novinky/' . $c['seo_link'] . $md . '): ' . mb_strimwidth(trim(strip_tags($c['uvod'])), 0, 200, '…');
        }

        return implode("\n", $radky) . "\n";
    }

    /** @param array<string, mixed> $clanek */
    public function clanekMarkdown(array $clanek): string
    {
        $hlava = ['# ' . $clanek['titulek'], ''];
        $hlava[] = '- ' . t('Autor') . ': ' . ($clanek['autor_jm'] ?? $this->app->settings()->get('nazev_webu'));
        $hlava[] = '- ' . t('Vydáno') . ': ' . date('Y-m-d', strtotime($clanek['datum'])) . ($clanek['zmeneno'] ? ', ' . t('aktualizováno') . ': ' . date('Y-m-d', strtotime($clanek['zmeneno'])) : '');
        $hlava[] = '- ' . t('Kategorie') . ': ' . $clanek['tema_jm'];
        $hlava[] = '- ' . t('Zdroj') . ': ' . $this->web . 'novinky/' . $clanek['seo_link'];

        return implode("\n", $hlava) . "\n\n" . self::htmlNaMarkdown($clanek['uvod']) . "\n\n" . self::htmlNaMarkdown($clanek['text']) . "\n";
    }

    /**
     * Značky před </head>.
     *
     * @param array<string, mixed> $meta     meta údaje stránky (typ, popis, obrazek, noindex...)
     * @param array<string, mixed>|null $clanek celá novinka, jde-li o stránku novinky
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
        } // noindex jednotlivé stránky nebo novinky vypíše šablona podle $meta['noindex'] (a vynechá kanonickou adresu)
        if ($s->get('overeni_google') !== '') {
            $h[] = '<meta name="google-site-verification" content="' . e($s->get('overeni_google')) . '">';
        }
        if ($s->get('overeni_bing') !== '') {
            $h[] = '<meta name="msvalidate.01" content="' . e($s->get('overeni_bing')) . '">';
        }
        if (($meta['obrazek'] ?? '') === '' && $s->get('og_obrazek') !== '') {
            $h[] = '<meta property="og:image" content="' . e($this->absolutni($s->get('og_obrazek'))) . '">';
        }
        // jazykové verze: hreflang jen na existující překlady (novinka, stránka, kategorie), na úvodu na úvod každé verze
        $vychozi = \MiroCMS\Core\Jazyk::vychozi($s);
        foreach ($meta['jazyky'] ?? [] as $kod => $j) {
            if ($j['preklad'] || ($meta['hlavni'] ?? false)) {
                $h[] = '<link rel="alternate" hreflang="' . e($kod) . '" href="' . e($this->app->request->origin() . $j['url']) . '">';
                if ($kod === $vychozi) {
                    // návštěvník v jazyce, který web nemá, dostane výchozí verzi
                    $h[] = '<link rel="alternate" hreflang="x-default" href="' . e($this->app->request->origin() . $j['url']) . '">';
                }
            }
        }
        $h[] = '<meta property="og:locale" content="' . \MiroCMS\Core\Jazyk::DOSTUPNE[\MiroCMS\Core\Jazyk::kod()][1] . '">';
        if (($meta['popis'] ?? '') !== '') {
            $h[] = '<meta property="og:description" content="' . e($meta['popis']) . '">';
        }
        $h[] = '<meta name="twitter:card" content="' . (($meta['obrazek'] ?? '') !== '' || $s->get('og_obrazek') !== '' ? 'summary_large_image' : 'summary') . '">';
        if ($clanek !== null && $s->bool('markdown_clanky')) {
            $h[] = '<link rel="alternate" type="text/markdown" href="' . e($this->web . 'novinky/' . $clanek['seo_link'] . '.md') . '">';
        }
        if ($s->bool('schema_org')) {
            $h[] = '<script type="application/ld+json">' . json_encode($this->strukturovanaData($titulek, $meta, $clanek), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        }
        // design systém (tokeny a pořadí vrstev kaskády) a styl stavby stránky, pokud jde o stránku ze stavitele
        $h[] = '<style>' . \MiroCMS\Stavitel\DesignSystem::css(\MiroCMS\Stavitel\DesignSystem::nacti($s), $this->app->request->basePath()) . ($meta['css'] ?? '') . '</style>';
        $h[] = Identita::hlava($s, $this->app->request->basePath());
        // společné prvky webu (fotogalerie, prohlížečka fotek, video, sdílení…) pro všechny šablony
        $verze = rawurlencode(MIROCMS_VERSION);
        $h[] = '<link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '?v=' . $verze . '">';
        $h[] = '<script src="' . e($this->app->url('image/web.js')) . '?v=' . $verze . '" defer' . self::textySkriptu() . '></script>';
        $h[] = $this->mereni();
        if (trim($s->get('kod_hlava')) !== '') {
            $h[] = $s->get('kod_hlava');
        }

        return implode("\n", array_filter($h)) . "\n";
    }

    /** České texty, které návštěvníkovi vypisuje image/web.js (tam jsou obalené T() nebo A()); slovník webu je překládá jako každý jiný text. */
    public const array TEXTY_SKRIPTU = ['Předchozí fotka', 'Další fotka', 'Zavřít'];

    /**
     * Atribut data-texty pro značku <script> s image/web.js: překlady textů skriptu (česky => překlad) jako JSON.
     * Bez dalšího požadavku a bez inline skriptu; česká verze nepotřebuje nic – skript má češtinu v sobě.
     */
    private static function textySkriptu(): string
    {
        $preklady = [];
        foreach (self::TEXTY_SKRIPTU as $cesky) {
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
        $maMarketing = $marketing !== '';
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
     * Marketingový kód podle režimu cookies: bez lišty se vypíše rovnou; vestavěná lišta ho po souhlasu
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
        // firma z Nastavení → Firma (Organization nebo LocalBusiness s adresou, otevírací dobou a mapou)
        $vydavatel = Firma::schema($s, $this->web, $this->absolutni(...));
        if ($clanek === null) {
            $graf = [
                ['@type' => 'WebSite', '@id' => $this->web . '#web', 'name' => $s->get('nazev_webu'), 'url' => $this->web,
                    'description' => $s->get('popis_webu'), 'inLanguage' => \MiroCMS\Core\Jazyk::kod(), 'publisher' => ['@id' => $vydavatel['@id']],
                    'potentialAction' => ['@type' => 'SearchAction', 'target' => $this->web . 'hledani?q={q}', 'query-input' => 'required name=q']],
                $vydavatel,
            ];
            if (!empty($meta['faq'])) {
                // stránka ze stavitele s otázkami a odpověďmi – vedle údajů o webu a firmě, ne místo nich
                $graf[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $d): array => [
                    '@type' => 'Question', 'name' => $d[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $d[1]],
                ], $meta['faq'])];
            }
            if (count($meta['drobecky'] ?? []) > 1) {
                $graf[] = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn (array $d, int $i): array => array_filter([
                    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $d[0], 'item' => $d[1] !== '' ? $this->app->request->origin() . $d[1] : null,
                ]), $meta['drobecky'], array_keys($meta['drobecky']))];
            }

            return ['@context' => 'https://schema.org', '@graph' => $graf];
        }

        return ['@context' => 'https://schema.org', '@graph' => [
            array_filter([
                '@type' => 'BlogPosting',
                'headline' => mb_substr($clanek['titulek'], 0, 110),
                'description' => $meta['popis'] ?? '',
                'image' => $clanek['obrazek'] !== '' ? [$this->absolutni($clanek['obrazek'])] : null,
                'datePublished' => date('c', strtotime($clanek['datum'])),
                'dateModified' => date('c', strtotime($clanek['aktualizovano'] ?? $clanek['zmeneno'] ?? $clanek['datum'])),
                'author' => $clanek['autor_jm'] !== null ? array_filter(['@type' => 'Person', 'name' => $clanek['autor_jm'],
                    'jobTitle' => $clanek['autor_pozice'] ?? '', 'description' => trim((string) ($clanek['autor_bio'] ?? '')), 'image' => ($clanek['autor_foto'] ?? '') !== '' ? $this->absolutni($clanek['autor_foto']) : '',
                    'sameAs' => ($clanek['autor_url'] ?? '') !== '' ? $clanek['autor_url'] : '']) : $vydavatel,
                'publisher' => $vydavatel,
                'articleSection' => $clanek['tema_jm'],
                'keywords' => implode(', ', array_column($clanek['stitky'] ?? [], 'nazev')) ?: null,
                'mainEntityOfPage' => $this->web . 'novinky/' . $clanek['seo_link'],
                'inLanguage' => \MiroCMS\Core\Jazyk::kod(),
            ]),
            ...($this->faqData($clanek)),
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $s->get('nazev_webu'), 'item' => $this->web],
                ['@type' => 'ListItem', 'position' => 2, 'name' => t('Novinky'), 'item' => $this->web . 'novinky'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $clanek['tema_jm'], 'item' => $this->web . 'novinky/kategorie/' . $clanek['tema_seo']],
                ['@type' => 'ListItem', 'position' => 4, 'name' => $clanek['titulek']],
            ]],
        ]];
    }

    /**
     * Otázky a odpovědi novinky: text "otázka \n odpověď \n\n ..." -> dvojice.
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

    /** Jednoduchý převod HTML na Markdown - nadpisy, odstavce, seznamy, odkazy, citace, obrázky. */
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
