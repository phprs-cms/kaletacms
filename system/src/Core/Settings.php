<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Nastavení webu z tabulky rs_config (promenna => hodnota), stejně jako v MiroCMS 2.
 */
final class Settings
{
    /** Výchozí hodnoty; zároveň seznam všech známých proměnných. */
    public const array DEFAULTS = [
        'nazev_webu' => 'Můj magazín',
        'adresa_webu' => '',          // https://www.example.cz - z ní se skládají odkazy v e-mailech, kanálech a oznámeních (ne z hlavičky Host)
        'popis_webu' => '',
        'klicova_slova' => '',
        'email_webu' => '',
        'logo_webu' => '',
        'favicon' => '',
        'brand_akcent' => '',         // hlavní barva webu (#rrggbb); prázdné = barva šablony
        'tmavy_rezim' => 'vypnuto',   // tmavý vzhled webu: vypnuto | auto (podle zařízení čtenáře)
        'brand_pismo_titulky' => 'vychozi', // klíč z Front\Identita::PISMA_TITULKU
        'brand_pismo_text' => 'vychozi',            // obrázek místo textového názvu v záhlaví
        'text_paticky' => '',
        'soc_facebook' => '',
        'soc_instagram' => '',
        'soc_x' => '',
        'soc_youtube' => '',
        'soc_linkedin' => '',
        'casove_pasmo' => 'Europe/Prague', // časové pásmo webu: data článků, plánované vydání, statistiky (App::casovePasmo)
        'jazyk_webu' => 'cs',         // jazyk webu: texty šablon, <html lang>, strukturovaná data (Core\Jazyk)
        'jazyky_dalsi' => '',         // další jazykové verze na /en/, /de/… (rozšíření Jazykové verze), kódy oddělené čárkou
        'layout' => 'classic-newspaper', // = Front\Layouty::VYCHOZI
        'rozvrzeni' => 'tri',         // tri | dva | jeden | plna (Úprava bloků)
        'pocet_clanku' => '7',        // článků na hlavní stránce
        'pocet_novinek' => '3',
        'hlidat_platnost' => '1',     // po datu stažení článek zmizí z hlavní stránky
        'povolit_komentare' => '1',
        'udrzba' => '0',              // režim údržby: návštěvníci vidí oznámení, přihlášená redakce web
        'udrzba_text' => 'Na webu právě pracujeme. Zkuste to prosím za chvíli.',
        'webhook_url' => '',          // kam poslat údaje o právě vydaném článku (Make, Zapier...)
        'cache_stranek' => '1',       // cache celých stránek pro nepřihlášené čtenáře (5 minut)
        'komentare_jen_prihlaseni' => '0', // komentovat smí jen přihlášení čtenáři (rozšíření Čtenáři)
        'komentare_rezim' => 'hned',  // hned | schvalovat (komentář čeká na schválení)
        'povolit_hodnoceni' => '1',
        'doba_cteni' => '1',          // u delších článků doba čtení a ukazatel průběhu
        'kontrola_odkazu' => '1',     // na pozadí hledat v článcích nefunkční odkazy
        'kontrola_odkazu_cas' => '0',
        'sdileni' => '1',             // odkazy pro sdílení pod článkem
        'osnova_clanku' => '1',       // obsah článku z mezititulků (od tří H2)
        'souvisejici_auto' => '1',    // související články podle štítků a rubriky, když článek není v seriálu
        'upozorneni_komentare' => 'schvaleni', // e-mail redakci: schvaleni (čeká na schválení) | vse | nic
        'upozorneni_cas' => '0',
        'ulohy_token' => '',          // tajná část adresy /ulohy pro cron
        'ctenari_registrace' => '1',  // čtenáři se mohou sami registrovat (rozšíření Čtenáři)
        'zamek_odstavcu' => '2',      // kolik odstavců zamčeného článku vidí nepřihlášený jako ukázku
        'paywall_zdarma' => '0',      // měkký paywall: kolik zamčených článků měsíčně smí číst kdokoli zdarma (0 = vypnuto)
        'predplatne_url' => '',       // kde čtenář získá předplatné: stránka webu (/predplatne) nebo platební odkaz (https://…)
        'zamek_text' => '',           // vlastní text výzvy pod ukázkou
        'stripe_tajny_klic' => '',    // platby předplatného přes Stripe (Core\Stripe): tajný nebo omezený klíč (typ "tajne")
        'stripe_webhook_tajemstvi' => '', // tajemství webhooku whsec_… (typ "tajne") - ověřuje se jím každá zpráva o platbě
        'stripe_cena_mesic' => '',    // číslo měsíční ceny ve Stripe (price_…); prázdné = nenabízí se
        'stripe_cena_rok' => '',      // číslo roční ceny
        'stripe_cena_mesic_text' => '', // popis ceny u tlačítka, např. "99 Kč měsíčně"
        'stripe_cena_rok_text' => '',
        'statistika' => '1',          // vlastní měření návštěvnosti bez cookies
        'tajny_klic' => '',           // vznikne sám; podepisuje formuláře čtenářů a solí otisky statistiky
        'aktivni_anketa' => '0',
        // SEO a GEO
        'indexovani' => '1',          // 0 = celý web noindex + Disallow v robots.txt
        'schema_org' => '1',          // strukturovaná data JSON-LD
        'og_obrazek' => '',           // výchozí obrázek pro sdílení
        'overeni_google' => '',
        'overeni_bing' => '',
        'robots_extra' => '',
        'ai_crawlery' => 'povolit',   // povolit | zakazat (GPTBot, ClaudeBot, PerplexityBot...)
        'llms_txt' => '1',
        'markdown_clanky' => '1',     // /clanek/<adresa>.md
        'indexnow' => '0',            // po vydání článku oznámit adresu vyhledávačům (Bing, Seznam, Yandex)
        'indexnow_klic' => '',
        // měření
        'ga4_id' => '',
        'matomo_url' => '',
        'matomo_id' => '0',
        'plausible_domena' => '',
        'kod_hlava' => '',
        // soukromí a cookies
        'cookies_rezim' => 'vestavena', // zadna | vestavena | externi
        'cookies_externi_kod' => '',
        'cookies_text' => 'Používáme cookies k měření návštěvnosti. Pomáhají nám zjistit, co čtenáře zajímá.',
        'cookies_zasady_url' => '',
        'kod_marketing' => '',
        'cookies_evidence' => '1',    // zapisovat udělené souhlasy (doklad pro případnou kontrolu)
        'stav_token' => '',
        'zaloha_vzdalena' => 'vypnuto', // kopie zálohy mimo server: vypnuto | ftp | s3
        'zaloha_host' => '',          // FTP server, nebo adresa úložiště S3 (s3.eu-central-1.amazonaws.com)
        'zaloha_uzivatel' => '',      // jméno FTP / přístupový klíč S3
        'zaloha_heslo' => '',         // heslo FTP / tajný klíč S3 (typ "tajne")
        'zaloha_slozka' => '',        // složka na FTP / název bucketu
        'zaloha_region' => '',        // region S3 (eu-central-1…)
        'zaloha_vzdalena_stav' => '', // "RRRR-MM-DD HH:MM|ok" nebo text chyby
        'zalohy_auto' => '1',         // týdenní automatická záloha databáze
        'aktualizace_url' => '',      // adresa souboru aktualizace.json; prázdné = výchozí zdroj projektu
        'aktualizace_cache' => '',
        'odkaz_podpora' => '1',       // nenápadný odkaz „Podpořit MiroCMS“ v patičce administrace
        'aktualizace_auto' => '1',    // bezpečnostní vydání instalovat automaticky
        'aktualizace_pokus' => '',    // verze, kterou už údržba na pozadí zkoušela / oznámila
        'rozsireni' => '',            // zapnutá rozšíření (Core\Rozsireni); prázdné = výchozí sada
        'posta_rezim' => 'mail',      // mail = funkce mail() serveru | smtp = vlastní SMTP server
        'posta_od' => '',             // adresa odesílatele; prázdné = e-mail redakce
        'posta_odpoved' => '',        // adresa pro odpovědi (Reply-To)
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_sifrovani' => 'tls',    // tls (STARTTLS, port 587) | ssl (port 465) | zadne
        'smtp_uzivatel' => '',
        'smtp_heslo' => '',           // typ "tajne": nikdy se nevypisuje zpět do formuláře
        'newsletter_auto' => 'vypnuto', // automatický výběr nových článků: vypnuto | tydne | denne
        'newsletter_den' => '5',      // den v týdnu (1 = pondělí)
        'newsletter_hodina' => '7',
        'newsletter_uvod' => '',      // úvodní slovo automatických vydání
        'newsletter_auto_posledni' => '',
        'push_klic_verejny' => '',    // pár klíčů VAPID pro Web Push vznikne sám při prvním použití
        'push_klic_soukromy' => '',
        'push_zprava' => '',          // poslední oznámení (JSON) - čte ho service worker přes /push.json
        'push_ukazatel' => '',        // kam až došla rozesílka posledního oznámení; "hotovo" = rozesláno
        'oznameni_kontrola' => '0',   // kdy naposledy proběhla kontrola nově vydaných článků
        'ai_klic' => '',              // klíč Claude API pro AI asistenta v editoru (nikdy se nevypisuje zpět do formuláře)
        'ai_model' => 'claude-sonnet-5',
        'ads_txt' => '',
        'pruvodce_skryt' => '0',      // administrátor skryl první kroky na přehledu
        'demo_obsah' => '',           // co založil ukázkový obsah (JSON: články, rubriky, obrázky) - podle toho ho Core\Demo smaže
        'uklizeno_verze' => '',       // verze, po jejímž nasazení už proběhl jednorázový úklid zrušených souborů
        'verze_db' => '1',            // číslo poslední provedené migrace (system/sql/migrace)
    ];

    /** Nastavení, která jdou vyplnit zvlášť pro každou další jazykovou verzi webu (klíč_en, klíč_de…). */
    public const array PODLE_JAZYKA = ['nazev_webu', 'popis_webu'];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** Databáze, ze které nastavení pochází (potřebuje ji fronta pošty). */
    public function db(): Db
    {
        return $this->db;
    }

    public function get(string $key): string
    {
        $this->values ??= $this->db->pairs('SELECT promenna, hodnota FROM {config}');
        // název a popis webu může mít jazyková verze (/en/, /de/…) vlastní; prázdné = jako ve výchozím jazyce
        if (in_array($key, self::PODLE_JAZYKA, true) && ($jazyk = Jazyk::sloupecWebu()) !== '' && ($this->values[$key . '_' . $jazyk] ?? '') !== '') {
            return $this->values[$key . '_' . $jazyk];
        }

        return $this->values[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    /** Hodnota pro danou jazykovou verzi ('' = výchozí jazyk) bez ohledu na to, ve které verzi běží požadavek. */
    public function proJazyk(string $key, string $jazyk): string
    {
        $this->values ??= $this->db->pairs('SELECT promenna, hodnota FROM {config}');
        $vlastni = $jazyk === '' ? '' : ($this->values[$key . '_' . $jazyk] ?? '');

        return $vlastni !== '' ? $vlastni : ($this->values[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO {config} (promenna, hodnota) VALUES (?, ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)',
            [$key, $value],
        );
        if ($this->values !== null) {
            $this->values[$key] = $value;
        }
    }
}
