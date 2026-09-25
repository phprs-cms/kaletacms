<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Nastavení webu z tabulky ka_nastaveni (promenna => hodnota).
 */
final class Settings
{
    /** Výchozí hodnoty; zároveň seznam všech známých proměnných. */
    public const array DEFAULTS = [
        'nazev_webu' => 'Můj web',
        'adresa_webu' => '',          // https://www.example.cz - z ní se skládají odkazy v e-mailech, kanálech a oznámeních (ne z hlavičky Host)
        'popis_webu' => '',
        'klicova_slova' => '',
        'email_webu' => '',
        'logo_webu' => '',
        'favicon' => '',
        'firma_nazev' => '',          // obchodní firma (s.r.o., OSVČ…) – Nastavení → Firma; na webu prvek Údaje firmy, pro vyhledávače schema.org
        'firma_typ' => 'LocalBusiness',
        'firma_ico' => '',
        'firma_dic' => '',
        'firma_ulice' => '',
        'firma_mesto' => '',
        'firma_psc' => '',
        'firma_zeme' => 'CZ',
        'firma_telefon' => '',
        'firma_email' => '',          // veřejný kontaktní e-mail firmy (web, schema.org); prázdné = e-mail webu
        'firma_hodiny' => '',         // otevírací doba po řádcích: „Po–Pá 8:00–17:00“ (Front\Firma::hodiny)
        'firma_mapa' => '',
        'firma_gps' => '',
        'poptavky_mesice' => '24',    // poptávky z formulářů starší než tolik měsíců se mažou (osobní údaje nemají ležet věčně); 0 = nemazat
        'design_system' => '',        // barvy, písma, škála a rozměry webu (JSON, Stavitel\DesignSystem); prázdné = výchozí
        'brand_akcent' => '',         // starší: hlavní barva webu, čte se jen dokud není uložen design_system
        'tmavy_rezim' => 'vypnuto',   // tmavý vzhled webu: vypnuto | auto (podle zařízení návštěvníka)
        'brand_pismo_titulky' => 'vychozi', // klíč z Front\Identita::PISMA_TITULKU
        'brand_pismo_text' => 'vychozi',            // obrázek místo textového názvu v záhlaví
        'text_paticky' => '',
        'soc_facebook' => '',
        'soc_instagram' => '',
        'soc_x' => '',
        'soc_youtube' => '',
        'soc_linkedin' => '',
        'casove_pasmo' => 'Europe/Prague', // časové pásmo webu: data novinek, plánované vydání, statistiky (App::casovePasmo)
        'jazyk_webu' => 'cs',         // jazyk webu: texty šablon, <html lang>, strukturovaná data (Core\Jazyk)
        'jazyky_dalsi' => '',         // další jazykové verze na /en/, /de/… (rozšíření Jazykové verze), kódy oddělené čárkou
        'layout' => 'zakladni',       // = Front\Layouty::VYCHOZI
        'titulni_stranka' => '0',     // stránka (ka_stranky.ids) jako úvod webu; 0 = výpis novinek
        'pocet_clanku' => '9',        // novinek na jednu stránku výpisu
        'udrzba' => '0',              // režim údržby: návštěvníci vidí oznámení, přihlášení správci web
        'udrzba_text' => 'Na webu právě pracujeme. Zkuste to prosím za chvíli.',
        'webhook_url' => '',          // kam poslat údaje o právě vydané novince (Make, Zapier...)
        'webhook_poptavky' => '',
        'vynutit_2fa' => '',          // '' | spravci | vsichni – povinné dvoufázové přihlášení     // kam poslat novou poptávku z formuláře (CRM, Make, Zapier, n8n…)
        'cache_stranek' => '1',       // cache celých stránek pro nepřihlášené návštěvníky (5 minut)
        'kontrola_odkazu' => '1',     // na pozadí hledat v novinkách nefunkční odkazy
        'kontrola_odkazu_cas' => '0',
        'sdileni' => '1',             // odkazy pro sdílení pod novinkou
        'osnova_clanku' => '1',       // obsah novinky z mezititulků (od tří H2)
        'souvisejici_auto' => '1',    // související novinky podle štítků a kategorie
        'ulohy_token' => '',          // tajná část adresy /ulohy pro cron
        'statistika' => '1',          // vlastní měření návštěvnosti bez cookies
        'tajny_klic' => '',           // vznikne sám; podepisuje odkazy a solí otisky statistiky
        // SEO a GEO
        'indexovani' => '1',          // 0 = celý web noindex + Disallow v robots.txt
        'schema_org' => '1',          // strukturovaná data JSON-LD
        'og_obrazek' => '',           // výchozí obrázek pro sdílení
        'overeni_google' => '',
        'overeni_bing' => '',
        'robots_extra' => '',
        'ai_crawlery' => 'povolit',   // povolit | zakazat (GPTBot, ClaudeBot, PerplexityBot...)
        'llms_txt' => '1',
        'markdown_clanky' => '1',     // /novinky/<adresa>.md
        'indexnow' => '0',            // po vydání novinky oznámit adresu vyhledávačům (Bing, Seznam, Yandex)
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
        'cookies_text' => 'Používáme cookies k měření návštěvnosti. Pomáhají nám zlepšovat web.',
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
        'aktualizace_auto' => '1',    // bezpečnostní vydání instalovat automaticky
        'aktualizace_pokus' => '',    // verze, kterou už údržba na pozadí zkoušela / oznámila
        'rozsireni' => '',            // zapnutá rozšíření (Core\Rozsireni); prázdné = výchozí sada
        'posta_rezim' => 'mail',      // mail = funkce mail() serveru | smtp = vlastní SMTP server
        'posta_od' => '',             // adresa odesílatele; prázdné = e-mail webu
        'posta_odpoved' => '',        // adresa pro odpovědi (Reply-To)
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_sifrovani' => 'tls',    // tls (STARTTLS, port 587) | ssl (port 465) | zadne
        'smtp_uzivatel' => '',
        'smtp_heslo' => '',           // typ "tajne": nikdy se nevypisuje zpět do formuláře
        'oznameni_kontrola' => '0',   // kdy naposledy proběhla kontrola nově vydaných novinek
        'uklid_udaju' => '0',         // kdy naposledy proběhl denní úklid osobních údajů (Core\Oznameni)
        'ai_poskytovatel' => 'anthropic', // anthropic | openai | google | mistral (Core\Asistent::POSKYTOVATELE)
        'ai_klic' => '',              // klíč API AI asistenta (nikdy se nevypisuje zpět do formuláře)
        'ai_model' => 'claude-sonnet-5',
        'pruvodce_skryt' => '0',      // administrátor skryl první kroky na přehledu
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

    /** Výchozí hodnoty, které jsou text pro návštěvníky – překládají se do jazyka webu, dokud je správce nezmění. */
    private const array PREKLADANE_VYCHOZI = ['udrzba_text', 'cookies_text'];

    public function get(string $key): string
    {
        $this->values ??= $this->db->pairs('SELECT promenna, hodnota FROM {nastaveni}');
        // název a popis webu může mít jazyková verze (/en/, /de/…) vlastní; prázdné = jako ve výchozím jazyce
        if (in_array($key, self::PODLE_JAZYKA, true) && ($jazyk = Jazyk::sloupecWebu()) !== '' && ($this->values[$key . '_' . $jazyk] ?? '') !== '') {
            return $this->values[$key . '_' . $jazyk];
        }

        if (isset($this->values[$key])) {
            return $this->values[$key];
        }

        // výchozí texty, které vidí návštěvník, v jazyce webu (anglický web nesmí ukázat českou údržbu ani cookie lištu)
        return in_array($key, self::PREKLADANE_VYCHOZI, true) ? t(self::DEFAULTS[$key]) : (self::DEFAULTS[$key] ?? '');
    }

    /** Hodnota pro danou jazykovou verzi ('' = výchozí jazyk) bez ohledu na to, ve které verzi běží požadavek. */
    public function proJazyk(string $key, string $jazyk): string
    {
        $this->values ??= $this->db->pairs('SELECT promenna, hodnota FROM {nastaveni}');
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
            'INSERT INTO {nastaveni} (promenna, hodnota) VALUES (?, ?) ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)',
            [$key, $value],
        );
        if ($this->values !== null) {
            $this->values[$key] = $value;
        }
    }
}
