<?php

declare(strict_types=1);

namespace Kaleta\Admin\Moduly;

use Kaleta\Admin\Modul;
use Kaleta\Core\Aktualizace;
use Kaleta\Core\Response;
use Kaleta\Core\Rozsireni;
use Kaleta\Core\Stav;
use Kaleta\Core\Zaloha;
use Kaleta\Front\Layouty;

/**
 * Nastavení webu (tabulka ka_nastaveni) rozdělené do záložek.
 * Každá záložka má šablonu views/admin/config/<zalozka>.php a seznam polí s typem - podle něj se hodnoty čistí.
 */
class Konfigurace extends Modul
{
    public const string IDENT = 'config';
    public const string NAZEV = 'Nastavení';
    public const string SKUPINA = 'Správa';
    public const string IKONA = 'nastaveni';
    public const bool JEN_ADMIN = true;

    public const array ZALOZKY = [
        'zakladni' => 'Základní', 'firma' => 'Firma', 'seo' => 'SEO a GEO',
        'mereni' => 'Měření', 'cookies' => 'Soukromí a cookies', 'posta' => 'Pošta', 'zalohy' => 'Zálohy a aktualizace', 'stav' => 'Stav systému',
    ];

    /** Typy firmy pro pole firma_typ (vyber:…). */
    private const string TYPY_FIRMY = 'Organization|LocalBusiness|HomeAndConstructionBusiness|ProfessionalService|LegalService|AccountingService|MedicalBusiness|AutomotiveBusiness|Store|FoodEstablishment|LodgingBusiness|SportsActivityLocation|EducationalOrganization';

    public const array SITE = ['soc_facebook' => 'Facebook', 'soc_instagram' => 'Instagram', 'soc_x' => 'X (Twitter)', 'soc_youtube' => 'YouTube', 'soc_linkedin' => 'LinkedIn'];

    /**
     * Pole jednotlivých záložek: klíč v ka_nastaveni => typ.
     * text | tajne (klíč: nevypisuje se zpět, prázdné pole = beze změny; tajne:/regex/ navíc hlídá tvar) | radky (víceřádkový text) | kod (HTML/JS - zadává jen administrátor) | url | email | ano | cislo:min:max | vyber:a|b | seznam:a|b (zaškrtávací pole, ukládá se "a,b") | vzor:/regex/
     */
    private const array POLE = [
        'zakladni' => [
            'nazev_webu' => 'text', 'adresa_webu' => 'vzor:#^https?://[a-z0-9.-]+(:\d+)?$#i', 'popis_webu' => 'radky', 'email_webu' => 'email', 'text_paticky' => 'text',
            'soc_facebook' => 'url', 'soc_instagram' => 'url', 'soc_x' => 'url', 'soc_youtube' => 'url', 'soc_linkedin' => 'url',
            'titulni_stranka' => 'cislo:0:4294967295', 'pocet_clanku' => 'cislo:1:100', 'sdileni' => 'ano', 'kontrola_odkazu' => 'ano', 'osnova_clanku' => 'ano', 'souvisejici_auto' => 'ano', 'cache_stranek' => 'ano', 'udrzba' => 'ano', 'udrzba_text' => 'text', 'webhook_url' => 'url', 'webhook_poptavky' => 'url', 'vynutit_2fa' => 'vyber:|spravci|vsichni',
            'casove_pasmo' => 'pasmo', 'jazyk_webu' => 'vyber:' . \Kaleta\Core\Jazyk::KODY, 'jazyky_dalsi' => 'seznam:' . \Kaleta\Core\Jazyk::KODY,
        ],
        'firma' => [
            'firma_nazev' => 'text', 'firma_typ' => 'vyber:' . self::TYPY_FIRMY, 'firma_ico' => 'vzor:/^(\d{6,10})?$/', 'firma_dic' => 'vzor:/^([A-Z]{2}[A-Z0-9]{6,12})?$/',
            'firma_ulice' => 'text', 'firma_mesto' => 'text', 'firma_psc' => 'vzor:/^[A-Z0-9 -]{0,10}$/i', 'firma_zeme' => 'vzor:/^[A-Z]{2}$/',
            'firma_telefon' => 'vzor:/^[+()\d\s\/.-]{0,30}$/', 'firma_email' => 'email', 'firma_hodiny' => 'hodiny', 'firma_mapa' => 'url', 'firma_gps' => 'vzor:/^(-?\d{1,2}(\.\d+)?,\s*-?\d{1,3}(\.\d+)?)?$/',
        ],
        'seo' => [
            'indexovani' => 'ano', 'schema_org' => 'ano', 'og_obrazek' => 'text', 'overeni_google' => 'vzor:/^[A-Za-z0-9_-]{0,100}$/',
            'overeni_bing' => 'vzor:/^[A-Za-z0-9]{0,64}$/', 'robots_extra' => 'radky', 'ai_crawlery' => 'vyber:povolit|zakazat', 'llms_txt' => 'ano', 'markdown_clanky' => 'ano', 'indexnow' => 'ano',
        ],
        'mereni' => [
            'ga4_id' => 'vzor:/^(G-[A-Z0-9]{4,20})?$/', 'matomo_url' => 'url', 'matomo_id' => 'cislo:0:99999',
            'plausible_domena' => 'vzor:/^([a-z0-9.-]{3,100})?$/', 'kod_hlava' => 'kod', 'statistika' => 'ano',
        ],
        'cookies' => ['cookies_rezim' => 'vyber:zadna|vestavena|externi', 'cookies_externi_kod' => 'kod', 'cookies_text' => 'radky', 'cookies_zasady_url' => 'text', 'kod_marketing' => 'kod', 'cookies_evidence' => 'ano'],
        'posta' => ['posta_rezim' => 'vyber:mail|smtp', 'posta_od' => 'email', 'posta_odpoved' => 'email', 'smtp_host' => 'vzor:/^[A-Za-z0-9.-]{0,120}$/', 'smtp_port' => 'cislo:1:65535',
            'smtp_sifrovani' => 'vyber:tls|ssl|zadne', 'smtp_uzivatel' => 'text', 'smtp_heslo' => 'tajne'],
        'rozsireni' => ['ai_poskytovatel' => 'vyber:' . \Kaleta\Core\Asistent::POSKYTOVATELE_KLICE, 'ai_klic' => 'tajne', 'ai_model' => 'vzor:#^[A-Za-z0-9._:/-]{0,80}$#'],
        'zalohy' => ['zaloha_vzdalena' => 'vyber:vypnuto|ftp|s3', 'zaloha_host' => 'vzor:#^[A-Za-z0-9.:/-]{0,150}$#', 'zaloha_uzivatel' => 'text', 'zaloha_heslo' => 'tajne',
            'zaloha_slozka' => 'vzor:#^[A-Za-z0-9._/-]{0,150}$#', 'zaloha_region' => 'vzor:/^[a-z0-9-]{0,40}$/', 'zalohy_auto' => 'ano', 'aktualizace_auto' => 'ano', 'aktualizace_url' => 'url'],
        'stav' => ['stav_token' => 'vzor:/^[A-Za-z0-9]{0,64}$/'],
    ];

    /**
     * Pole záložky. Základní záložka má navíc název a popis webu pro každou další jazykovou verzi
     * (nazev_webu_en, popis_webu_de…) - prázdná hodnota znamená „stejné jako ve výchozím jazyce“.
     *
     * @return array<string, string>
     */
    private function pole(string $zalozka): array
    {
        $pole = self::POLE[$zalozka];
        if ($zalozka === 'zakladni') {
            foreach (\Kaleta\Core\Jazyk::dalsi($this->app->settings()) as $jazyk) {
                $pole += ['nazev_webu_' . $jazyk => 'text', 'popis_webu_' . $jazyk => 'radky'];
            }
        }

        return $pole;
    }

    /** Neplatné hodnoty: hláška s názvy polí, jak je vidí uživatel, a zadané hodnoty zpět do zvýrazněných polí. */
    private function neulozeno(string $zalozka, array $chyby, array $zadane): Response
    {
        $sablona = (string) @file_get_contents(KALETA_SYSTEM . '/views/admin/config/' . $zalozka . '.php');
        $nazvy = array_map(fn (string $klic): string => preg_match('/\$pole\(\s*\'' . preg_quote($klic, '/') . '\',\s*\'([^\']+)\'/', $sablona, $m) ? '„' . t($m[1]) . '“' : $klic, $chyby);
        $this->app->session->set('konfigurace_chybne', ['zalozka' => $zalozka, 'pole' => $chyby, 'hodnoty' => $zadane]);

        return $this->zpet(t('Tato pole nemají platný tvar a neuložila se: %s. Opravte je prosím (jsou zvýrazněná), ostatní nastavení je uložené.', implode(', ', $nazvy)), '', static::IDENT === 'config' ? ['zalozka' => $zalozka] : [], 'chyba');
    }

    protected function akceVypis(): Response
    {
        if (static::IDENT === 'config' && $this->request->get('zalozka') === 'rozsireni') {
            return Response::redirect($this->app->url('admin.php?modul=rozsireni')); // Rozšíření mají vlastní položku v nabídce
        }
        $zalozka = $this->zalozka($this->request->get('zalozka'));
        $nastaveni = $this->app->settings();
        $hodnoty = [];
        foreach ($this->pole($zalozka) as $klic => $typ) {
            $hodnoty[$klic] = $nastaveni->get($klic);
            if (str_starts_with($typ, 'tajne') && $hodnoty[$klic] !== '') {
                $hodnoty[$klic] = '…' . substr($hodnoty[$klic], -4); // do stránky jde jen konec klíče pro kontrolu
            }
        }

        $chybne = $this->app->session->get('konfigurace_chybne');
        $this->app->session->set('konfigurace_chybne', null);
        $chybne = is_array($chybne) && ($chybne['zalozka'] ?? '') === $zalozka ? $chybne : ['pole' => [], 'hodnoty' => []];

        return $this->view('vypis', 'Nastavení', [
            'zalozka' => $zalozka,
            'chybnaPole' => $chybne['pole'],
            'hodnoty' => $chybne['hodnoty'] + $hodnoty + ['layout' => $nastaveni->get('layout')],
            'layouty' => Layouty::seznam(),
            'kontroly' => $zalozka === 'stav' ? Stav::kontroly($this->app) : [],
            'vzdalenaStav' => $nastaveni->get('zaloha_vzdalena_stav'),
            'ulohyToken' => $nastaveni->get('ulohy_token'),
            'chybyLog' => $zalozka === 'stav' ? self::konecSouboru(KALETA_ROOT . '/storage/log/chyby.log', 40) : [],
            'posta' => $zalozka === 'posta' ? $this->db->all('SELECT komu, predmet, vytvoreno, odeslano, pokusu, dalsi_pokus, chyba FROM {posta} ORDER BY idp DESC LIMIT 30') : [],
            'zapnutaRozsireni' => Rozsireni::zapnuta($nastaveni),
            'stranky' => $zalozka === 'zakladni' ? $this->db->pairs("SELECT ids, titulek FROM {stranky} WHERE zobrazit = 1 AND jazyk = '' ORDER BY poradi, titulek") : [],
            'zalohy' => $zalozka === 'zalohy' ? Zaloha::seznam() : [],
            'aktualizace' => $zalozka === 'zalohy' ? (new Aktualizace($nastaveni))->stav() : null,
            'adresaWebu' => $this->app->request->origin() . $this->app->url(''),
            'souhlasy' => $zalozka === 'cookies' ? $this->db->all("SELECT kategorie, COUNT(*) AS pocet FROM {souhlasy} WHERE cas > NOW() - INTERVAL 30 DAY GROUP BY kategorie ORDER BY pocet DESC") : [],
        ]);
    }

    protected function akceUloz(): Response
    {
        $zalozka = $this->zalozka($this->request->post('zalozka'));
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        $nastaveni = $this->app->settings();
        $chyby = [];
        $zadane = [];
        foreach ($this->pole($zalozka) as $klic => $typ) {
            // "kod" se neořezává ani jinak neupravuje - je to HTML/JS vložené administrátorem
            $hodnota = $typ === 'kod' ? (string) ($_POST[$klic] ?? '') : $this->request->post($klic);
            if (str_starts_with($typ, 'seznam:')) {
                $nastaveni->set($klic, implode(',', array_intersect($this->request->postList($klic), explode('|', substr($typ, 7)))));
                continue;
            }
            if (str_starts_with($typ, 'tajne')) {
                if ($this->request->postBool($klic . '_smazat')) {
                    $nastaveni->set($klic, '');
                } elseif ($hodnota !== '' && $typ !== 'tajne' && !preg_match(substr($typ, 6), $hodnota)) {
                    $chyby[] = $klic; // hodnota se do hlášky nikdy nevypisuje, jen název pole
                } elseif ($hodnota !== '') {
                    $nastaveni->set($klic, mb_substr($hodnota, 0, 300));
                }
                continue;
            }
            $cista = self::vycisti($typ, $hodnota, $this->request->postBool($klic));
            if ($cista === null) {
                $chyby[] = $klic;
                $zadane[$klic] = mb_substr($hodnota, 0, 2000); // vrátí se do formuláře k opravě (tajné klíče ne)
                continue;
            }
            $nastaveni->set($klic, $cista);
        }
        if ($zalozka === 'seo' && $nastaveni->bool('indexnow') && $nastaveni->get('indexnow_klic') === '') {
            $nastaveni->set('indexnow_klic', bin2hex(random_bytes(16)));
        }
        if ($zalozka === 'rozsireni') {
            Rozsireni::uloz($nastaveni, $this->request->postList('rozsireni'));
            if (($this->request->post('ai_klic') !== '' || $this->request->post('ai_poskytovatel') !== $this->request->post('ai_poskytovatel_puvodni')) && $nastaveni->get('ai_klic') !== '' && ($chybaKlice = (new \Kaleta\Core\Asistent($nastaveni))->overKlic()) !== null) {
                return $this->zpet(t('Nastavení je uložené, ale klíč asistenta nefunguje: %s', t($chybaKlice)), '', static::IDENT === 'config' ? ['zalozka' => $zalozka] : [], 'chyba');
            }
        }
        if ($this->request->postBool('novy_token_ulohy')) {
            $nastaveni->set('ulohy_token', bin2hex(random_bytes(16)));
        }
        if ($this->request->postBool('novy_token')) {
            $nastaveni->set('stav_token', bin2hex(random_bytes(16)));
        }

        return $chyby === []
            ? $this->zpet('Nastavení bylo uloženo.', '', static::IDENT === 'config' ? ['zalozka' => $zalozka] : [])
            : $this->neulozeno($zalozka, $chyby, $zadane);
    }

    protected function akceZalohuj(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        try {
            $soubor = Zaloha::vytvor($this->db);
        } catch (\Throwable $e) {
            return $this->zpet(t('Zálohu se nepodařilo vytvořit: %s', t($e->getMessage())), '', ['zalozka' => 'zalohy'], 'chyba');
        }
        $vzdalena = \Kaleta\Core\VzdalenaZaloha::nahraj($this->app->settings(), (string) Zaloha::cesta($soubor));
        if ($vzdalena !== null) {
            return $this->zpet(t('Záloha %s je hotová, ale kopii mimo server se nepodařilo nahrát: %s', $soubor, t($vzdalena)), '', ['zalozka' => 'zalohy'], 'chyba');
        }

        return $this->zpet(t('Záloha %s je hotová.', $soubor), '', ['zalozka' => 'zalohy']);
    }

    protected function akceStahniZalohu(): Response
    {
        $cesta = Zaloha::cesta($this->request->get('soubor'));
        if ($cesta === null) {
            return $this->chyba('Záloha neexistuje.', 404);
        }

        return new Response((string) file_get_contents($cesta), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . basename($cesta) . '"',
            'Content-Length' => (string) filesize($cesta),
        ]);
    }

    protected function akceSmazZalohu(): Response
    {
        $cesta = Zaloha::cesta($this->request->post('soubor'));
        if ($this->request->isPost() && $cesta !== null) {
            unlink($cesta);
        }

        return $this->zpet('Záloha byla smazána.', '', ['zalozka' => 'zalohy']);
    }

    /** Vyprázdní záznam chyb aplikace. */
    protected function akceSmazLog(): Response
    {
        if ($this->request->isPost() && is_file(KALETA_ROOT . '/storage/log/chyby.log')) {
            file_put_contents(KALETA_ROOT . '/storage/log/chyby.log', '');
        }

        return $this->zpet('Záznam chyb je prázdný.', '', ['zalozka' => 'stav']);
    }

    /**
     * Posledních N řádků souboru bez načtení celého souboru do paměti.
     *
     * @return list<string>
     */
    private static function konecSouboru(string $soubor, int $radku): array
    {
        if (!is_file($soubor) || filesize($soubor) === 0) {
            return [];
        }
        $f = fopen($soubor, 'rb');
        fseek($f, -min(filesize($soubor), 64 * 1024), SEEK_END);
        $konec = (string) stream_get_contents($f);
        fclose($f);

        return array_slice(array_values(array_filter(explode("\n", $konec), fn (string $r): bool => trim($r) !== '')), -$radku);
    }

    /** Obnova databáze ze zálohy; těsně před ní vznikne pojistná záloha současného stavu. */
    protected function akceObnovZalohu(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet('', '', ['zalozka' => 'zalohy']);
        }
        try {
            $pojistna = Zaloha::vytvor($this->db, 'predobnovou');
            $prikazu = Zaloha::obnov($this->db, $this->request->post('soubor'));
        } catch (\Throwable $e) {
            $vraceno = false;
            if (isset($pojistna)) {
                // selhání uprostřed obnovy: databáze se sama vrátí do stavu před obnovou
                try {
                    Zaloha::obnov($this->db, $pojistna);
                    $vraceno = true;
                } catch (\Throwable) {
                    // vrácení se nepovedlo – správce ho spustí ručně ze zálohy $pojistna
                }
            }
            \Kaleta\Front\Cache::vymaz();

            return $this->zpet(t('Obnova se nezdařila: %s', t($e->getMessage())) . ' ' . ($vraceno ? t('Databáze je zpět ve stavu před obnovou.') : (isset($pojistna) ? t('Stav před obnovou je v záloze %s – obnovte ji prosím.', $pojistna) : '')), '', ['zalozka' => 'zalohy'], 'chyba');
        }
        \Kaleta\Front\Cache::vymaz();

        return $this->zpet(t('Databáze byla obnovena ze zálohy (příkazů: %d). Stav před obnovou je uložený v záloze %s.', $prikazu, $pojistna), '', ['zalozka' => 'zalohy']);
    }

    /** Znovu zjistí, zda je k dispozici novější verze. */
    protected function akceZkontroluj(): Response
    {
        if ($this->request->isPost()) {
            (new Aktualizace($this->app->settings()))->stav(true);
        }

        return $this->zpet('', '', ['zalozka' => 'zalohy']);
    }

    /** Stáhne, ověří a nainstaluje novou verzi. Před tím zazálohuje databázi. */
    protected function akceAktualizuj(): Response
    {
        if (!$this->request->isPost()) {
            return $this->zpet();
        }
        try {
            Zaloha::vytvor($this->db, 'predaktualizaci');
            $verze = (new Aktualizace($this->app->settings()))->nainstaluj();
        } catch (\Throwable $e) {
            return $this->zpet(t('Aktualizace se nezdařila: %s Na webu se nic nezměnilo.', t($e->getMessage())), '', ['zalozka' => 'zalohy'], 'chyba');
        }

        return $this->zpet(t('Systém byl aktualizován na verzi %s. Databáze se upraví sama při příštím načtení administrace.', $verze), '', ['zalozka' => 'zalohy']);
    }

    /** Záloha nahraných médií: ZIP složky media/ ke stažení. */
    protected function akceZalohaMedii(): Response
    {
        if (!class_exists(\ZipArchive::class) || !is_dir(KALETA_ROOT . '/media')) {
            return $this->zpet('Na serveru chybí rozšíření zip – média si stáhněte přes FTP.', '', ['zalozka' => 'zalohy'], 'chyba');
        }
        $soubor = KALETA_ROOT . '/storage/cache/media-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($soubor, \ZipArchive::CREATE);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS)) as $polozka) {
            // varianty pro srcset a sourozenci WebP/AVIF (foto.jpg.webp) se dají kdykoli vytvořit znovu - do zálohy jdou jen originály,
            // i nahraný originál ve WebP (foto.webp, jedna přípona)
            if ($polozka->isFile() && !preg_match('/(-1200|-nahled)\.[a-z]+$|\.[a-z0-9]+\.(webp|avif)$/i', $polozka->getFilename()) && !str_starts_with($polozka->getFilename(), '.')) {
                $zip->addFile($polozka->getPathname(), substr($polozka->getPathname(), strlen(KALETA_ROOT) + 1));
            }
        }
        $zip->close();
        if (!is_file($soubor)) {
            return $this->zpet('Ve složce media/ zatím nic není.', '', ['zalozka' => 'zalohy'], 'chyba');
        }
        register_shutdown_function(static fn () => @unlink($soubor));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="media-' . date('Ymd') . '.zip"');
        header('Content-Length: ' . filesize($soubor));
        readfile($soubor);
        exit;
    }

    /** Zkušební e-mail na e-mail webu - ověří, že server umí odesílat poštu. */
    protected function akceTestPosty(): Response
    {
        $komu = $this->app->settings()->get('email_webu');
        if (!$this->request->isPost() || $komu === '') {
            return $this->zpet('Nejprve vyplňte E-mail webu v záložce Základní.', '', ['zalozka' => $this->request->post('zalozka') === 'posta' ? 'posta' : 'stav'], 'chyba');
        }
        $web = $this->app->settings()->get('nazev_webu');
        // e-mail webu nemá účet s jazykem: zpráva jde ve výchozím jazyce webu (stejně jako ostatní pošta webu)
        [$predmet, $text] = \Kaleta\Core\Jazyk::docasne(\Kaleta\Core\Jazyk::vychozi($this->app->settings()), fn (): array => [
            t('Zkušební zpráva z %s', $web),
            t('Dobrý den,') . "\n\n" . t('tato zpráva potvrzuje, že web %s umí odesílat e-maily.', $web) . "\n\nKaleta " . KALETA_VERSION,
        ], 'admin-');
        $ok = \Kaleta\Core\Posta::odesli($this->app->settings(), $komu, $predmet, $text, doFronty: false);
        $zpet = $this->request->post('zalozka') === 'posta' ? 'posta' : 'stav';

        return $this->zpet(
            match (true) {
                !$ok => t('Odeslání selhalo: %s', t(\Kaleta\Core\Posta::$chyba)),
                $this->app->settings()->get('posta_rezim') === 'smtp' => t('Zpráva byla předána k odeslání na %s. Pokud nedorazí, zkontrolujte spam.', $komu),
                default => t('Zpráva byla předána k odeslání na %s. Pokud nedorazí, zkontrolujte spam – nebo nastavte odesílání přes SMTP (Nastavení → Pošta).', $komu),
            },
            '',
            ['zalozka' => $zpet],
            $ok ? 'ok' : 'chyba',
        );
    }

    protected function zalozka(string $zalozka): string
    {
        return isset(self::ZALOZKY[$zalozka]) ? $zalozka : 'zakladni';
    }

    /** @return string|null vyčištěná hodnota, null = neplatná */
    /**
     * Hodnota nastavení ověřená stejně jako ve formuláři administrace (pro MCP). null = neznámý klíč nebo neplatná hodnota.
     * Přepínače (typ ano) berou 1/0, true/false.
     */
    public static function overHodnotu(string $klic, string $hodnota): ?string
    {
        $typ = null;
        foreach (self::POLE as $pole) {
            $typ ??= $pole[$klic] ?? null;
        }
        if ($typ === null && preg_match('/^(nazev|popis)_webu_([a-z]{2})$/', $klic, $m)) {
            $typ = $m[1] === 'nazev' ? 'text' : 'radky';
        }
        if ($typ === null || str_starts_with($typ, 'tajne') || str_starts_with($typ, 'seznam')) {
            return null;
        }

        return self::vycisti($typ, trim($hodnota), in_array(strtolower(trim($hodnota)), ['1', 'true', 'ano'], true));
    }

    private static function vycisti(string $typ, string $hodnota, bool $zaskrtnuto): ?string
    {
        [$druh, $parametr] = explode(':', $typ, 2) + [1 => ''];

        return match ($druh) {
            'ano' => $zaskrtnuto ? '1' : '0',
            'text' => mb_substr(str_replace(["\r", "\n"], ' ', $hodnota), 0, 500),
            'radky' => mb_substr($hodnota, 0, 5000),
            'kod' => mb_substr($hodnota, 0, 20000),
            'email' => $hodnota === '' || filter_var($hodnota, FILTER_VALIDATE_EMAIL) ? $hodnota : null,
            'url' => $hodnota === '' || (preg_match('#^https?://#i', $hodnota) && filter_var($hodnota, FILTER_VALIDATE_URL)) ? rtrim($hodnota) : null,
            'cislo' => (function () use ($hodnota, $parametr): string {
                [$min, $max] = array_map(intval(...), explode(':', $parametr));

                return (string) max($min, min($max, (int) $hodnota));
            })(),
            'vyber' => in_array($hodnota, explode('|', $parametr), true) ? $hodnota : null,
            'pasmo' => in_array($hodnota, \DateTimeZone::listIdentifiers(), true) ? $hodnota : null,
            'vzor' => preg_match($parametr, $hodnota) ? $hodnota : null,
            'hodiny' => \Kaleta\Front\Firma::hodiny($hodnota) !== null ? mb_substr(trim($hodnota), 0, 1000) : null,
            default => null,
        };
    }
}
