<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Admin\Moduly\Galerie;
use Kaleta\Admin\Moduly\Kategorie;
use Kaleta\Admin\Moduly\Stranky;
use Kaleta\Core\App;
use Kaleta\Core\Jazyk;
use Kaleta\Front\Identita;
use Kaleta\Stavitel\Casti;
use Kaleta\Stavitel\DesignSystem;
use Kaleta\Stavitel\Knihovna;
use Kaleta\Stavitel\Kolekce;
use Kaleta\Stavitel\Publikace;
use Kaleta\Stavitel\Stavba;
use Kaleta\Stavitel\ZHtml;

/**
 * Nástroje, které MCP server nabízí Claudovi. Každý nástroj respektuje práva uživatele, jehož tokenem se Claude hlásí:
 * autor pracuje jen se svými novinkami a nevydává, editor s veškerým obsahem, správce navíc se šablonami webu.
 *
 * Chyba určená Claudovi (špatný vstup, chybějící právo) se hlásí výjimkou InvalidArgumentException / DomainException.
 */
final class Nastroje
{
    /** Největší soubor nahraný přes MCP (base64 v jednom volání nástroje). */
    private const int MAX_NAHRANI = 12 * 1024 * 1024;

    /** Nastavení, která smí MCP měnit (ostatní – e-mail, webhooky, 2FA, pošta, zálohy – jen v administraci). */
    private const string NASTAVENI_MCP = '/^(nazev_webu|popis_webu|text_paticky|titulni_stranka|soc_(facebook|instagram|x|youtube|linkedin)|pocet_clanku|sdileni|osnova_clanku|souvisejici_auto|firma_[a-z]+|tmavy_rezim|tmavy_prepinac|(nazev|popis)_webu_[a-z]{2})$/';

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array<string, mixed>> definice nástrojů pro tools/list */
    public function seznam(): array
    {
        $s = fn (array $vlastnosti, array $povinne = []): array => ['type' => 'object', 'properties' => $vlastnosti === [] ? new \stdClass() : $vlastnosti, 'required' => $povinne];
        $text = fn (string $popis): array => ['type' => 'string', 'description' => $popis];
        $cislo = fn (string $popis): array => ['type' => 'integer', 'description' => $popis];
        $novinka = [
            'titulek' => $text('Titulek novinky'), 'uvod' => $text('Perex jako HTML (1-2 odstavce)'), 'text' => $text('Text jako HTML'),
            'kategorie' => $text('Název nebo adresa (seo_link) kategorie'), 'stitky' => $text('Štítky oddělené čárkou'),
            'seo_titulek' => $text('Titulek pro vyhledávače (nepovinné)'), 'seo_popis' => $text('Popis pro vyhledávače, do 160 znaků'),
            'obrazek' => $text('Adresa hlavního obrázku (z nástroje seznam_medii)'), 'obrazek_popis' => $text('Popisek hlavního obrázku (prázdné = z knihovny médií)'),
            'faq' => $text('Otázky a odpovědi: otázka na řádku, odpověď pod ní, mezi dvojicemi prázdný řádek'),
            'datum' => $text('Datum vydání RRRR-MM-DD HH:MM; budoucí = naplánování'),
            'vydat' => ['type' => 'boolean', 'description' => 'true = vydat (jen s právem vydávat a na výslovný pokyn uživatele), jinak koncept'],
        ];
        $stranka = [
            'titulek' => $text('Název stránky (zobrazí se v navigaci a jako nadpis)'), 'text' => $text('Obsah stránky jako HTML'),
            'adresa' => $text('Část adresy za doménou (seo_link); bez ní vznikne z názvu'), 'popis' => $text('Popis pro vyhledávače, do 160 znaků'),
            'v_menu' => ['type' => 'boolean', 'description' => 'true = odkaz v hlavní navigaci webu'], 'poradi' => $cislo('Pořadí v navigaci, menší = dřív'),
            'zobrazit' => ['type' => 'boolean', 'description' => 'true = stránka je na webu vidět (jen na výslovný pokyn uživatele), jinak skrytá'],
            'seo_titulek' => $text('Titulek pro vyhledávače (nepovinné, jinak název)'), 'obrazek' => $text('Obrázek pro sdílení na sociálních sítích (cesta z médií)'),
            'noindex' => ['type' => 'boolean', 'description' => 'true = skrýt stránku před vyhledávači'],
            'nadrazena' => $cislo('ID nadřazené stránky – adresa bude /nadrazena/stranka (0 = žádná)'),
            'jazyk' => $text('jazyková verze stránky u vícejazyčného webu (kód, např. en; prázdné = výchozí jazyk)'),
            'preklad_z' => $cislo('ID protějšku ve výchozím jazyce (u stránky jiné jazykové verze) – přepínač jazyků a hreflang'),
            'zverejnit_od' => $text('naplánované zveřejnění skryté stránky RRRR-MM-DD HH:MM (jen na výslovný pokyn uživatele; prázdné = zrušit)'),
        ];
        $cil = ['id' => $cislo('ID stránky'), 'cast' => $text('Místo stránky část webu (jen správce): ' . implode(' | ', array_keys(Casti::TYPY)) . ' – záhlaví, patička, obálky detailu novinky, výpisu a 404'),
            'jazyk' => $text('Jazyk části webu u vícejazyčného webu (prázdné = výchozí)'),
            'varianta' => $text('Varianta záhlaví nebo patičky (klíč ze seznam_casti; prázdné = výchozí podoba)'),
            'kolekce' => $text('Místo stránky šablona detailu položek kolekce (adresa kolekce z seznam_kolekci, jen správce)')];
        $nastroje = [
            ['info_o_webu', 'Název webu, úvodní stránka, šablona, počty stránek a novinek, role přihlášeného uživatele a jeho oprávnění.', $s([])],
            ['seznam_stranek', 'Stránky webu (Úvod, O nás, Služby, Kontakt…) s adresami.', $s([])],
            ['nacti_stranku', 'Celá stránka včetně HTML obsahu.', $s(['id' => $cislo('ID stránky')], ['id'])],
            ['vytvor_stranku', 'Založí stránku (editor a správce). Bez "zobrazit": true zůstane skrytá.', $s($stranka, ['titulek'])],
            ['uprav_stranku', 'Změní zadaná pole stránky; ostatní ponechá.', $s(['id' => $cislo('ID stránky')] + $stranka, ['id'])],
            ['nacti_menu', 'Menu webu (hlavní nebo v patičce) pro jazykovou verzi: položky s podmenu a jestli se hlavní menu zatím skládá automaticky ze stránek „v menu“.',
                $s(['umisteni' => $text('hlavni (výchozí) | paticka'), 'jazyk' => $text('jazyková verze (prázdné = výchozí)')])],
            ['uloz_menu', 'Uloží celé menu (správce). Položky: {"typ":"stranka","ids":5,"text":""} (prázdný text = název stránky) | {"typ":"odkaz","text":"…","url":"https://… nebo /cesta","nove_okno":false} | {"typ":"novinky"} | {"typ":"skupina","text":"Služby"} – každá může mít "deti" (jedna úroveň podmenu). null = hlavní menu zase automaticky. Menu nemá koncept – projeví se na webu hned; skrytá stránka se v něm ukáže až po zveřejnění.',
                $s(['umisteni' => $text('hlavni | paticka'), 'jazyk' => $text('jazyková verze (prázdné = výchozí)'), 'polozky' => ['type' => ['array', 'null'], 'items' => ['type' => 'object'], 'description' => 'položky menu']], ['umisteni', 'polozky'])],
            ['stavba_schema', 'Jak se skládá stránka v builderu: typy prvků a jejich pole, vlastnosti stylu, tokeny design systému (barvy, mezery, písmo), hotové sekce knihovny a sdílené třídy webu. Načti před prvním použitím nástrojů stavba_*. Vrací stručný přehled (prvek na řádek); úplné definice vybraných prvků přes parametr prvky.',
                $s(['prvky' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'typy prvků, pro které chceš úplnou definici (popisky polí, výchozí děti), např. ["formular","karusel"]'],
                    'uplne' => ['type' => 'boolean', 'description' => 'true = celé schéma se všemi popisky (velké)']])],
            ['stavba_nacti', 'Stavba stránky nebo části webu (strom prvků s id) – rozpracovaný koncept, jinak publikovaná verze. Vynechává výchozí hodnoty. Stránka bez stavby vrátí stavbu z jejího textu.', $s($cil)],
            ['stavba_uprav', 'Dílčí úpravy konceptu podle id prvků (id ze stavba_nacti) – oprava textu, odkazu nebo stylu bez posílání celé stavby. Operace: '
                . '{"op":"uprav","id":"…","obsah":{…},"styl":{"mobil":{"mezera":"s"}},"tridy":[…]} (obsah a styl se slučují, null hodnotu odebere) | {"op":"nahrad","id":"…","prvek":{…}} | {"op":"smaz","id":"…"} | '
                . '{"op":"vloz","prvky":[…],"do":"id rodiče nebo null = kořen","pozice":0 | "za":"id" | "pred":"id"} | {"op":"presun","id":"…","do":…,"za":…}.',
                $s($cil + ['operace' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'seznam operací, provedou se postupně'],
                    'publikovat' => ['type' => 'boolean', 'description' => 'true = publikovat (jen na výslovný pokyn uživatele)']], ['operace'])],
            ['seznam_trid', 'Sdílené třídy webu (karta, tmava…) s jejich stylem po stavech a vlastním CSS. Třídu dostane prvek v poli "tridy".', $s(['nazev' => $text('jen tahle třída (nepovinné)')])],
            ['uloz_tridy', 'Založí nebo změní sdílené třídy (správce) – změna se hned projeví na celém webu. Zadej CSS jako v bloku <style>: pravidla jedné třídy (.karta { … }), '
                . '.karta:hover { … } a @media (max-width: 1023px) = tablet, (max-width: 767px) = mobil. Tokeny var(--ka-…), i přepis tokenů v třídě (--ka-barva-text: #fff) pro tmavé pásy.',
                $s(['css' => $text('pravidla tříd; slučují se se stávajícími – samotné .karta:hover nebo @media nechá základ třídy beze změny'),
                    'nahradit' => ['type' => 'boolean', 'description' => 'true = třídy z css nahradit celé (základ i všechny stavy)'],
                    'smazat' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'názvy tříd ke smazání']])],
            ['stavba_z_html', 'DOPORUČENÁ CESTA pro novou stránku nebo sekce: napiš sémantické HTML (section/header, h1–h3, p, ul, a, img, figure, blockquote, details) a vzhled do bloku <style> jako pravidla jedné třídy (.karta { … }, .karta:hover { … }) s tokeny var(--ka-…); '
                . 'breakpointy od desktopu dolů: @media (max-width: 1023px) = tablet, @media (max-width: 767px) = mobil. Prvek s třídou z <style> nedostane výchozí styl – rozložení (display:grid, gap) patří do třídy. Převede se na stavbu a třídy; vrátí hlášení, co převést nešlo. Uloží se jako koncept.',
                $s(['html' => $text('HTML obsahu (bez <html>/<head>); <style> smí být uvnitř. Záhlaví a patičku skládej z prvků logo, navigace a udaje přes stavba_uloz – HTML je nepřevede.'), 'id' => $cislo('ID stránky; bez něj (a bez cast) vznikne nová skrytá stránka s názvem z parametru titulek'), 'cast' => $cil['cast'], 'jazyk' => $cil['jazyk'], 'titulek' => $text('Název nové stránky (když není id)'),
                    'rezim' => $text('nahradit (výchozí) = celá stavba z HTML | pridat = sekce na konec stávající stavby'), 'prepsat_tridy' => ['type' => 'boolean', 'description' => 'true = třídy, které už na webu jsou, se přepíšou stylem z <style>; jinak zůstanou'],
                    'publikovat' => ['type' => 'boolean', 'description' => 'true = hned publikovat (jen na výslovný pokyn uživatele); jinak koncept k náhledu']], ['html'])],
            ['stavba_uloz', 'Uloží celou stavbu stránky (strom z stavba_nacti s úpravami) jako koncept. Pro drobné úpravy obsahu a stylu jednotlivých prvků. Vrátí vyčištěnou stavbu, chyby a kontrolu před publikováním.',
                $s($cil + ['stavba' => ['type' => 'object', 'description' => '{"v":1,"deti":[…]} podle stavba_schema'], 'publikovat' => ['type' => 'boolean', 'description' => 'true = publikovat (jen na výslovný pokyn uživatele)']], ['stavba'])],
            ['vloz_sekci', 'Vloží hotovou sekci z knihovny (úvod, výhody, služby, čísla, reference, faq, výzva, novinky, kontakt) na konec konceptu stránky nebo části webu.', $s($cil + ['sekce' => $text('klíč sekce ze stavba_schema → knihovna')], ['sekce'])],
            ['publikuj_stavbu', 'Publikuje koncept stavby stránky nebo části webu (jen na výslovný pokyn uživatele). Předchozí verze zůstane v historii.', $s($cil)],
            ['stavba_verze', 'Publikované verze stavby stránky nebo části webu (posledních 20): idr, kdy, kdo. Starší verzi načte do konceptu obnov_verzi.', $s($cil)],
            ['obnov_verzi', 'Načte starší publikovanou verzi (idr ze stavba_verze) do konceptu – na webu se ukáže až po publikování.', $s($cil + ['idr' => $cislo('ID verze ze stavba_verze')], ['idr'])],
            ['zahod_koncept', 'Zahodí rozpracovaný koncept stavby – vrátí se publikovaná podoba (jen na výslovný pokyn uživatele; nejde vrátit).', $s($cil)],
            ['seznam_casti', 'Části webu z builderu (záhlaví, patička, obálky novinky, výpisu a 404) a varianty záhlaví a patičky: klíč, název, stránky, na kterých platí, a stav (správce).', $s([])],
            ['uloz_variantu', 'Založí nebo změní variantu záhlaví či patičky pro vybrané stránky (správce) – např. záhlaví bez menu pro kampaňovou stránku. Nová začíná kopií výchozí podoby jako koncept; '
                . 'pak ji uprav stavba_* s parametrem varianta a publikuj. smazat = true variantu odstraní (vybrané stránky dostanou výchozí podobu).',
                $s(['cast' => $text('hlavicka | paticka'), 'jazyk' => $cil['jazyk'], 'varianta' => $text('klíč existující varianty – jen při úpravě nebo smazání'), 'nazev' => $text('název varianty, např. Kampaň bez menu'),
                    'stranky' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ID stránek, na kterých varianta platí'],
                    'smazat' => ['type' => 'boolean', 'description' => 'true = variantu smazat (jen na výslovný pokyn uživatele)']], ['cast'])],
            ['uprav_design_system', 'Změní vzhled celého webu (správce): barvy, písma, velikosti, šířku, zaoblení – nebo použije předvolbu. Nezadané hodnoty zůstanou. Vrátí kontrolu čitelnosti barev.',
                $s(['predvolba' => $text('firemni | remeslo | pratelsky | elegantni | technologie (nepovinné)'), 'ds' => ['type' => 'object', 'description' => 'Změny, např. {"barvy":{"primarni":"#0f766e"},"pismo_titulky":"klasicke","zaobleni":"l"} – klíče viz stavba_schema → design_system']])],
            ['seznam_kolekci', 'Kolekce webu (reference, tým, produkty…) s poli a počty položek. Na web je dostane prvek „kolekce“ (Výpis kolekce) ve stavbě; uvnitř se {{klic}} nahradí hodnotou položky ({{nazev}}, {{url}} = detail, {{datum}} a vlastní pole).', $s([])],
            ['vytvor_kolekci', 'Založí kolekci (správce). Pole: seznam {popisek, typ}; typ = ' . implode(' | ', array_keys(Kolekce::TYPY_POLI)) . '. Klíč pole vznikne z popisku.',
                $s(['nazev' => $text('Název, např. Reference'), 'adresa' => $text('Adresa kolekce v URL (nepovinné, jinak z názvu), např. guide'),
                    'pole' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{"popisek":"Citát","typ":"radky"},{"popisek":"Logo","typ":"obrazek"}]'],
                    'detail' => ['type' => 'boolean', 'description' => 'true = každá položka má vlastní stránku /<kolekce>/<položka>']], ['nazev'])],
            ['uprav_kolekci', 'Změní název, adresu, stránky položek nebo pole kolekce (správce). Pole = celý nový seznam; u stávajících pošli i "klic" (hodnoty položek zůstanou), pole bez klíče je nové, vynechané pole zmizí z formuláře.',
                $s(['kolekce' => $text('současná adresa (seo_link) kolekce'), 'nazev' => $text('nový název (nepovinné)'), 'adresa' => $text('nová adresa v URL (nepovinné)'),
                    'detail' => ['type' => 'boolean', 'description' => 'stránky položek zapnuté / vypnuté (nepovinné)'],
                    'pole' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{"klic":"citat","popisek":"Citát","typ":"radky"},{"popisek":"Nové pole","typ":"text"}] (nepovinné)']], ['kolekce'])],
            ['seznam_polozek_kolekce', 'Položky kolekce včetně hodnot polí, po 50 na stránku (celkem vrací počet). Filtr: hledaný text v názvu a hodnotách, pole=hodnota, jazyk, jen zobrazené.', $s([
                'kolekce' => $text('adresa (seo_link) kolekce'), 'hledat' => $text('text v názvu nebo hodnotách polí (nepovinné)'),
                'pole' => $text('klíč pole pro přesnou shodu (nepovinné)'), 'hodnota' => $text('hodnota pole pro přesnou shodu'),
                'jazyk' => $text('jazyková verze (prázdné = výchozí; nepovinné)'), 'jen_zobrazene' => ['type' => 'boolean', 'description' => 'jen položky zobrazené na webu'],
                'strana' => $cislo('stránka od 1'),
            ], ['kolekce'])],
            ['uloz_polozku_kolekce', 'Přidá položku do kolekce, nebo změní existující (s id). Bez "zobrazit": true zůstane skrytá.',
                $s(['kolekce' => $text('adresa (seo_link) kolekce'), 'id' => $cislo('ID položky – jen při úpravě'), 'nazev' => $text('Název položky (u nové povinný, při úpravě jen když se mění)'),
                    'adresa' => $text('Adresa položky v URL (nepovinné, jinak z názvu), např. install'), 'jazyk' => $text('jazyková verze položky u vícejazyčného webu (prázdné = výchozí)'),
                    'data' => ['type' => 'object', 'description' => 'Hodnoty polí podle klíčů ze seznam_kolekci, např. {"citat":"…","logo":"media/…"}'],
                    'poradi' => $cislo('Pořadí, menší = dřív'), 'zobrazit' => ['type' => 'boolean', 'description' => 'true = položka je na webu (jen na pokyn uživatele)']], ['kolekce'])],
            ['seznam_novinek', 'Seznam novinek (nejnovější první).', $s(['stav' => $text('vse | vydane | plan | koncepty'), 'kategorie' => $text('název nebo adresa kategorie'), 'hledat' => $text('text v titulku'), 'limit' => $cislo('1-50, výchozí 20')])],
            ['nacti_novinku', 'Celá novinka včetně textu a štítků.', $s(['id' => $cislo('ID novinky (idc)')], ['id'])],
            ['vytvor_novinku', 'Založí novinku. Bez "vydat": true vznikne koncept.', $s($novinka, ['titulek', 'kategorie'])],
            ['uprav_novinku', 'Změní zadaná pole novinky; ostatní ponechá. Předchozí verze se uloží do historie.', $s(['id' => $cislo('ID novinky')] + $novinka, ['id'])],
            ['seznam_kategorii', 'Kategorie novinek s počty.', $s([])],
            ['vytvor_kategorii', 'Založí kategorii novinek (editor a správce).', $s(['nazev' => $text('Název'), 'popis' => $text('Popis (HTML)')], ['nazev'])],
            ['seznam_medii', 'Naposledy nahrané obrázky a soubory s adresami a rozměry.', $s(['limit' => $cislo('1-50, výchozí 20'), 'hledat' => $text('text v názvu (nepovinné)')])],
            ['nahraj_soubor', 'Nahraje soubor do Médií: obrázek (JPG, PNG, WebP, GIF – zmenší se a dostane WebP/AVIF varianty), SVG (vyčistí se), písmo WOFF2 pro design system nebo přílohu (PDF…). '
                . 'Zadej url veřejného souboru (https – obrázek, písmo, PDF; u větších souborů vždy url), nebo data v base64 (nejvýš ' . (self::MAX_NAHRANI >> 20) . ' MB). Vrátí adresu pro prvek obrázek, obrazek_pozadi nebo vlastni_pisma.',
                $s(['nazev' => $text('název souboru s příponou, např. tym-praha.jpg'), 'data' => $text('obsah souboru v base64'), 'url' => $text('https adresa souboru ke stažení (místo data)'),
                    'popis' => $text('popis obrázku pro nevidomé (alt); jinak z názvu')], ['nazev'])],
            ['nahled_odkaz', 'Podepsaný odkaz na náhled konceptu stránky nebo části webu – otevře ho kdokoli i bez přihlášení (uživatel, kolega, prohlížeč), platí jen pro tenhle cíl a jen omezenou dobu. Vyhledávače ho neindexují.',
                $s($cil + ['minut' => $cislo('platnost v minutách, výchozí 60, nejvýš ' . \Kaleta\Core\Nahled::MAX_MINUT)])],
            ['uprav_nastaveni', 'Změní nastavení webu (správce) – hned se projeví na webu. Klíče: nazev_webu, popis_webu, text_paticky, logo_webu, favicon a og_obrazek – obrázek pro sdílení 1200×630 (cesta media/… z nahraj_soubor nebo image/…), titulni_stranka (ID úvodní stránky), soc_facebook|instagram|x|youtube|linkedin (URL), '
                . 'pocet_clanku, sdileni, osnova_clanku, souvisejici_auto (1/0), tmavy_rezim (vypnuto | auto = podle zařízení | tmavy = vždy tmavý), tmavy_prepinac (1/0 = přepínač vzhledu pro návštěvníky), údaje firmy firma_nazev, firma_typ, firma_ico, firma_dic, firma_rejstrik (zápis v rejstříku), firma_zastupce (kdo firmu zastupuje), firma_ulice, firma_mesto, firma_psc, firma_zeme (CZ), firma_telefon, firma_hodiny (den na řádek), firma_mapa, firma_gps; nazev_webu_en… pro jazykové verze. Bez parametru vrátí současné hodnoty.',
                $s(['nastaveni' => ['type' => 'object', 'description' => '{"klic":"hodnota"}']])],
            ['seznam_poptavek', 'Poptávky z formulářů webu (rozšíření Formuláře a poptávky; jen s právem k Poptávkám), nejnovější první: datum, formulář, stránka, kampaň (utm), e-mail, stav a vyplněná pole. Obsahují osobní údaje – používej je jen k tomu, oč uživatel žádá.',
                $s(['stav' => $text('nove | prectene | vyrizene | vse (výchozí)'), 'hledat' => $text('text v e-mailu nebo obsahu (nepovinné)'), 'limit' => $cislo('1-50, výchozí 20')])],
            ['seznam_presmerovani', 'Přesměrování starých adres (rozšíření Přesměrování) a nejčastější adresy, které skončily chybou 404.', $s([])],
            ['uloz_presmerovani', 'Přidá nebo změní přesměrování (správce): ze staré cesty na webu na novou cestu nebo https adresu. Typ 301 = natrvalo (výchozí), 302 = dočasně.',
                $s(['z' => $text('stará cesta, např. /docs nebo /o-nas'), 'na' => $text('nová cesta (/guide) nebo https://…'), 'typ' => $cislo('301 nebo 302'), 'smazat' => ['type' => 'boolean', 'description' => 'true = přesměrování ze staré cesty smazat']], ['z'])],
            ['smaz_stranku', 'Přesune stránku do koše (jen na výslovný pokyn uživatele; editor nebo správce). Z koše jde 30 dní obnovit v administraci. Úvodní stránku smazat nejde.', $s(['id' => $cislo('ID stránky')], ['id'])],
        ];

        if (!\Kaleta\Core\Rozsireni::je($this->app->settings(), 'novinky')) {
            $nastroje = array_filter($nastroje, fn (array $n): bool => !in_array($n[0], self::NOVINKOVE, true));
        }

        return array_values(array_map(fn (array $n): array => ['name' => $n[0], 'description' => $n[1], 'inputSchema' => $n[2]], $nastroje));
    }

    /** @return list<string> české názvy všech nástrojů (i vypnutých rozšíření) */
    public function nazvy(): array
    {
        return [...array_column($this->seznam(), 'name'), ...self::NOVINKOVE];
    }

    /** Nástroje rozšíření Novinky – s vypnutým rozšířením se nenabízejí ani nespustí. */
    private const array NOVINKOVE = ['seznam_novinek', 'nacti_novinku', 'vytvor_novinku', 'uprav_novinku', 'seznam_kategorii', 'vytvor_kategorii'];

    public function meni(string $nazev): bool
    {
        return in_array($nazev, ['obnov_verzi', 'zahod_koncept', 'uloz_variantu', 'vytvor_kolekci', 'uprav_kolekci', 'uloz_polozku_kolekce', 'stavba_z_html', 'stavba_uloz', 'stavba_uprav', 'uloz_tridy', 'nahraj_soubor', 'uprav_nastaveni', 'uloz_presmerovani', 'smaz_stranku', 'vloz_sekci', 'publikuj_stavbu', 'uprav_design_system', 'vytvor_stranku', 'uprav_stranku', 'vytvor_novinku', 'uprav_novinku', 'vytvor_kategorii', 'uloz_menu'], true);
    }

    /** @param array<string, mixed> $a */
    public function zavolej(string $nazev, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $web = $this->app->settings();
        $jenAdmin = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        if (in_array($nazev, self::NOVINKOVE, true) && !\Kaleta\Core\Rozsireni::je($web, 'novinky')) {
            throw new \DomainException('Novinky jsou na tomto webu vypnuté (Rozšíření).');
        }
        switch ($nazev) {
            case 'info_o_webu':
                return [
                    'web' => $web->get('nazev_webu'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $web->get('popis_webu'),
                    'sablona' => $web->get('layout'), 'uvodni_stranka' => $web->int('titulni_stranka') ?: null, 'verze_kaleta' => KALETA_VERSION,
                    'stranek' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NULL'),
                    'novinek_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL'),
                    'uzivatel' => $auth->user()['user'], 'role' => \Kaleta\Core\Auth::TYPY[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->smiVydavat(),
                    'smi_upravovat_stranky' => $auth->maModul('stranky'),
                ];

            case 'seznam_stranek':
                $uvod = $web->int('titulni_stranka');

                return array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'titulek' => $r['titulek'], 'adresa' => $this->app->request->origin() . $this->app->url((int) $r['ids'] === $uvod ? '' : $r['seo_link']),
                    'uvodni' => (int) $r['ids'] === $uvod, 'zobrazena' => (bool) $r['zobrazit'], 'v_menu' => (bool) $r['v_menu'], 'jazyk' => $r['jazyk']],
                    $db->all('SELECT ids, titulek, seo_link, zobrazit, v_menu, jazyk FROM {stranky} WHERE smazano IS NULL ORDER BY jazyk, poradi, titulek'));

            case 'nacti_stranku':
                return $this->stranka((int) ($a['id'] ?? 0));

            case 'vytvor_stranku':
            case 'uprav_stranku':
                if (!$auth->maModul('stranky')) {
                    throw new \DomainException('Stránky smí upravovat editor nebo správce.');
                }

                return $this->ulozStranku($nazev === 'uprav_stranku' ? $this->stranka((int) ($a['id'] ?? 0)) : null, $a);

            case 'nacti_menu':
            case 'uloz_menu':
                $umisteni = isset(\Kaleta\Core\Menu::UMISTENI[$a['umisteni'] ?? '']) ? $a['umisteni'] : 'hlavni';
                $jazykMenu = in_array($a['jazyk'] ?? '', Jazyk::dalsi($web), true) ? $a['jazyk'] : '';
                if ($nazev === 'uloz_menu') {
                    if (!$auth->isAdmin()) {
                        throw new \DomainException('Menu smí upravovat jen správce.');
                    }
                    \Kaleta\Core\Menu::uloz($db, $umisteni, $jazykMenu, is_array($a['polozky'] ?? null) ? $a['polozky'] : null);
                    \Kaleta\Front\Cache::vymaz();
                }
                $ulozene = \Kaleta\Core\Menu::nacti($db, $umisteni, $jazykMenu);

                return ['umisteni' => $umisteni, 'jazyk' => $jazykMenu, 'automaticke' => $ulozene === null, 'polozky' => $ulozene ?? [],
                    'na_webu' => \Kaleta\Core\Menu::polozky($this->app, $umisteni, $jazykMenu, $web->int('titulni_stranka')),
                    'stranky' => $db->all('SELECT ids, titulek, zobrazit FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$jazykMenu])];

            case 'stavba_schema':
                $schema = Stavba::schema($auth->isAdmin(), Jazyk::vychozi($web), $auth->isAdmin(), \Kaleta\Core\Rozsireni::zapnuta($web));
                $vybrane = is_array($a['prvky'] ?? null) ? array_values(array_filter($schema['prvky'], fn (array $p): bool => in_array($p['typ'], $a['prvky'], true))) : [];
                if ($vybrane !== [] && empty($a['uplne'])) {
                    return ['prvky' => $vybrane];
                }

                return (empty($a['uplne']) ? Stavba::prehled($schema) : $schema) + [
                    'komponenty' => array_map(fn (array $k): array => ['id' => (string) $k['idm'], 'nazev' => $k['nazev'], 'vlastnosti' => $k['vlastnosti']], \Kaleta\Stavitel\Komponenty::vsechny($db))
                        + ['pozn' => 'Použití: {"typ":"komponenta","obsah":{"komponenta":"<id>","hodnoty":{"<klic>":"hodnota"}}}; prázdná hodnota = výchozí.'],
                    'casti_webu' => array_map(fn (array $t): string => $t[0] . ' – ' . $t[1], Casti::TYPY) + ['pozn' => 'Prvky ze skupiny „Části webu“ (logo, navigace, udaje, obsah) patří jen do částí; obálka (novinka, vypis, nenalezeno) musí obsahovat právě jeden prvek „obsah“.'],
                    'knihovna' => empty($a['uplne']) ? array_column(array_map(fn (array $k): array => ['klic' => $k['klic'], 'popis' => $k['nazev'] . ' – ' . $k['popis']], Knihovna::seznam(\Kaleta\Core\Rozsireni::zapnuta($web))), 'popis', 'klic')
                        : Knihovna::seznam(\Kaleta\Core\Rozsireni::zapnuta($web)),
                    'tridy_webu' => array_column($db->all('SELECT nazev FROM {tridy} ORDER BY nazev'), 'nazev'),
                    'design_system' => DesignSystem::nacti($web) + ['predvolby' => array_map(fn (array $p): string => $p[0] . ' – ' . $p[1], DesignSystem::PREDVOLBY),
                        'pisma_titulku' => array_keys(Identita::PISMA_TITULKU), 'pisma_textu' => array_keys(Identita::PISMA_TEXTU)],
                    'css_tokeny' => 'V <style> a vlastním CSS používej var(--ka-barva-primarni|sekundarni|text|tlumeny|pozadi|plocha|linka|primarni-jemna|na-primarni), var(--ka-mezera-2xs…3xl), var(--ka-krok--1…5) pro velikost písma, var(--ka-zaobleni), var(--ka-stin-s|m|l), var(--ka-sirka).',
                ];

            case 'stavba_nacti':
                $cil = $this->cilStavby($a);

                return $this->popisCile($cil) + ['publikovana' => $cil['stavba'] !== null,
                    'neulozene_zmeny' => $cil['koncept'] !== null && $cil['koncept'] !== $cil['stavba'], 'stavba' => Stavba::kompaktni($this->stavbaCile($cil))];

            case 'stavba_uprav':
                $cil = $this->cilStavby($a);
                $chybyOperaci = [];
                $stavba = \Kaleta\Stavitel\Upravy::proved($this->stavbaCile($cil), is_array($a['operace'] ?? null) ? $a['operace'] : [], $chybyOperaci);

                return $this->ulozStavbu($cil, $stavba, !empty($a['publikovat'])) + ['chyby_operaci' => $chybyOperaci];

            case 'seznam_trid':
                $radky = isset($a['nazev']) ? $db->all('SELECT nazev, styl, css FROM {tridy} WHERE nazev = ?', [(string) $a['nazev']]) : $db->all('SELECT nazev, styl, css FROM {tridy} ORDER BY nazev');

                return array_map(fn (array $r): array => ['nazev' => $r['nazev'], 'styl' => json_decode((string) $r['styl'], true) ?: new \stdClass(), 'css' => (string) $r['css']], $radky);

            case 'uloz_tridy':
                $jenAdmin();
                $prevod = ZHtml::preved('<style>' . str_ireplace('</style', '', (string) ($a['css'] ?? '')) . '</style>', true);
                $ulozeno = [];
                foreach (array_unique(array_merge(array_keys($prevod['tridy']), array_keys($prevod['tridy_styl']))) as $trida) {
                    // slučuje se: pravidlo jen pro :hover nebo @media nechá základ třídy a ostatní stavy (nahradit: true = celá třída znovu)
                    $puvodni = empty($a['nahradit']) ? $db->one('SELECT styl, css FROM {tridy} WHERE nazev = ?', [$trida]) : null;
                    $styl = ($prevod['tridy_styl'][$trida] ?? []) + (json_decode((string) ($puvodni['styl'] ?? ''), true) ?: []);
                    $css = $prevod['tridy'][$trida] ?? (string) ($puvodni['css'] ?? '');
                    $db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE styl = VALUES(styl), css = VALUES(css), zmeneno = NOW()',
                        [$trida, (string) json_encode($styl ?: new \stdClass(), JSON_UNESCAPED_UNICODE), $css]);
                    $ulozeno[] = $trida;
                }
                $smazano = [];
                foreach (is_array($a['smazat'] ?? null) ? $a['smazat'] : [] as $trida) {
                    if (is_string($trida) && $db->delete('tridy', ['nazev' => $trida]) > 0) {
                        $smazano[] = $trida;
                    }
                }
                \Kaleta\Front\Cache::vymaz();

                return ['ulozeno' => $ulozeno, 'smazano' => $smazano, 'hlaseni' => $prevod['hlaseni']];

            case 'stavba_z_html':
                $cil = $this->cilStavby($a, true);
                ['stavba' => $stavba, 'hlaseni' => $hlaseni] = ZHtml::doWebu($db, (string) ($a['html'] ?? ''), $auth->isAdmin(), !empty($a['prepsat_tridy']));
                if (empty($a['prepsat_tridy'])) {
                    $hlaseni = array_map(fn (string $h): string => str_ends_with($h, 'ponechána beze změny.') ? substr($h, 0, -1) . ' (prepsat_tridy: true ji přepíše).' : $h, $hlaseni);
                }
                if (($a['rezim'] ?? '') === 'pridat') {
                    $stavba['deti'] = array_merge($this->stavbaCile($cil)['deti'], $stavba['deti']);
                }

                return $this->ulozStavbu($cil, $stavba, !empty($a['publikovat'])) + ['hlaseni' => $hlaseni];

            case 'stavba_uloz':
                if (!is_array($a['stavba'] ?? null)) {
                    throw new \InvalidArgumentException('Parametr stavba musí být objekt {"v":1,"deti":[…]}.');
                }

                return $this->ulozStavbu($this->cilStavby($a), $a['stavba'], !empty($a['publikovat']));

            case 'vloz_sekci':
                $cil = $this->cilStavby($a);
                $sekce = Knihovna::sekci((string) ($a['sekce'] ?? ''), $cil['jazyk']) ?? throw new \InvalidArgumentException('Sekce v knihovně není. Klíče: ' . implode(', ', array_column(Knihovna::seznam(), 'klic')) . '.');
                Knihovna::zalozTridy($db, $sekce['tridy']);
                $stavba = $this->stavbaCile($cil);
                $stavba['deti'][] = $sekce['prvek'];

                return $this->ulozStavbu($cil, $stavba, false);

            case 'publikuj_stavbu':
                $cil = $this->cilStavby($a);
                if (($cil['koncept'] ?? $cil['stavba']) === null) {
                    throw new \InvalidArgumentException('Není co publikovat.');
                }
                if (!$auth->smiVydavat()) {
                    throw new \DomainException('Publikovat smí jen editor nebo správce; koncept zůstává uložený.');
                }
                $this->publikujCil($cil);

                return $this->popisCile($cil) + ['stav' => 'publikováno', 'adresa' => $this->adresaCile($cil)]
                    + $this->kontrolaCile($cil, Stavba::zJson($cil['koncept'] ?? $cil['stavba']));

            case 'stavba_verze':
                $cil = $this->cilStavby($a);

                return $this->popisCile($cil) + ['verze' => array_map(fn (array $r): array => ['idr' => (int) $r['idr'], 'kdy' => substr((string) $r['datum'], 0, 16), 'kdo' => $r['kdo']],
                    Publikace::seznam($db, $cil['revize']))];

            case 'obnov_verzi':
                $cil = $this->cilStavby($a);
                $json = Publikace::nacti($db, $cil['revize'], (int) ($a['idr'] ?? 0)) ?? throw new \InvalidArgumentException('Verze neexistuje. Použij nástroj stavba_verze.');

                return $this->ulozStavbu($cil, Stavba::zJson($json), false);

            case 'zahod_koncept':
                $cil = $this->cilStavby($a);
                if ($cil['stavba'] === null) {
                    throw new \InvalidArgumentException('Zatím není publikovaná verze – není k čemu se vrátit.');
                }
                $r = $cil['radek'];
                match ($cil['druh']) {
                    'stranka' => $db->update('stranky', ['stavba_koncept' => null], ['ids' => $r['ids']]),
                    'kolekce' => $db->update('kolekce', ['stavba_koncept' => null], ['idk' => $r['idk']]),
                    default => $db->update('casti', ['stavba_koncept' => null], ['typ' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta']]),
                };

                return $this->popisCile($cil) + ['stav' => 'koncept zahozen – platí publikovaná podoba', 'adresa' => $this->adresaCile($cil)];

            case 'seznam_casti':
                $jenAdmin();

                return array_map(fn (array $r): array => ['cast' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta'], 'nazev' => $r['varianta'] !== '' ? $r['nazev'] : Casti::TYPY[$r['typ']][0] ?? $r['typ'],
                    'stranky' => $r['varianta'] !== '' ? array_map('intval', json_decode((string) $r['stranky'], true) ?: []) : null,
                    'publikovana' => (bool) $r['publikovana'], 'neulozene_zmeny' => (bool) $r['zmeny']],
                    $db->all('SELECT typ, jazyk, varianta, nazev, stranky, stavba IS NOT NULL AS publikovana, stavba_koncept IS NOT NULL AND (stavba IS NULL OR stavba_koncept <> stavba) AS zmeny FROM {casti} ORDER BY typ, jazyk, varianta'));

            case 'uloz_variantu':
                $jenAdmin();
                $typ = (string) ($a['cast'] ?? '');
                if (!in_array($typ, Casti::S_VARIANTAMI, true)) {
                    throw new \InvalidArgumentException('Varianty mají jen záhlaví a patička: ' . implode(', ', Casti::S_VARIANTAMI) . '.');
                }
                $jazyk = in_array($a['jazyk'] ?? '', Jazyk::dalsi($web), true) ? (string) $a['jazyk'] : '';
                $varianta = (string) ($a['varianta'] ?? '');
                if (!empty($a['smazat'])) {
                    $radek = $varianta !== '' ? Casti::radek($db, $typ, $jazyk, $varianta) : null;
                    if ($radek === null) {
                        throw new \InvalidArgumentException('Varianta neexistuje. Použij nástroj seznam_casti.');
                    }
                    Publikace::verze($this->app, ['cast' => Casti::klicRevize($typ, $jazyk, $varianta)], $radek['stavba'], null, $radek['zmeneno']);
                    $db->delete('casti', ['typ' => $typ, 'jazyk' => $jazyk, 'varianta' => $varianta]);
                    \Kaleta\Front\Cache::vymaz();

                    return ['cast' => $typ, 'varianta' => $varianta, 'stav' => 'varianta smazána – vybrané stránky mají výchozí podobu'];
                }
                $nazevVarianty = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($nazevVarianty === '') {
                    throw new \InvalidArgumentException('Varianta musí mít název.');
                }
                $stranky = array_values(array_filter(array_map('intval', is_array($a['stranky'] ?? null) ? $a['stranky'] : []),
                    fn (int $ids): bool => $db->value('SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = ? AND smazano IS NULL', [$ids, $jazyk]) !== null));
                $varianta = Casti::ulozVariantu($db, $typ, $jazyk, $varianta, $nazevVarianty, $stranky, Jazyk::obsahu($web, $jazyk));
                \Kaleta\Front\Cache::vymaz();

                return ['cast' => $typ, 'jazyk' => $jazyk, 'varianta' => $varianta, 'nazev' => $nazevVarianty, 'stranky' => $stranky,
                    'stav' => 'uloženo – stavbu varianty uprav stavba_* s parametrem varianta a publikuj; do publikování platí výchozí podoba'];

            case 'seznam_poptavek':
                if (!\Kaleta\Core\Rozsireni::je($web, 'poptavky') || !$auth->maModul('poptavky')) {
                    throw new \DomainException('Poptávky smí číst jen uživatel s právem k Poptávkám (rozšíření Formuláře a poptávky musí být zapnuté).');
                }
                $kde = [];
                $parametry = [];
                $stavy = ['nove' => 0, 'prectene' => 1, 'vyrizene' => 2];
                if (isset($stavy[$a['stav'] ?? ''])) {
                    $kde[] = 'stav = ?';
                    $parametry[] = $stavy[$a['stav']];
                }
                if (($a['hledat'] ?? '') !== '') {
                    $kde[] = '(email LIKE ? OR data LIKE ?)';
                    $hledat = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
                    array_push($parametry, $hledat, $hledat);
                }
                $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
                $nazvyStavu = array_flip($stavy);

                return array_map(fn (array $p): array => ['id' => (int) $p['idp'], 'datum' => substr((string) $p['datum'], 0, 16), 'formular' => $p['formular'], 'stranka' => $p['stranka'],
                    'kampan' => \Kaleta\Front\Formulare::kampanText((string) $p['kampan']), 'email' => $p['email'], 'stav' => $nazvyStavu[(int) $p['stav']] ?? '',
                    'pole' => array_map(fn (array $d): array => ['popisek' => $d[0], 'hodnota' => $d[1]], json_decode((string) $p['data'], true) ?: [])],
                    $db->all('SELECT idp, datum, formular, stranka, kampan, email, stav, data FROM {poptavky}' . ($kde !== [] ? ' WHERE ' . implode(' AND ', $kde) : '') . ' ORDER BY idp DESC LIMIT ' . $limit, $parametry));

            case 'uprav_design_system':
                $jenAdmin();
                $ds = isset($a['predvolba']) ? (DesignSystem::predvolba((string) $a['predvolba']) ?? throw new \InvalidArgumentException('Předvolba neexistuje: ' . implode(', ', array_keys(DesignSystem::PREDVOLBY)) . '.')) : DesignSystem::nacti($web);
                $zmeny = is_array($a['ds'] ?? null) ? $a['ds'] : [];
                foreach (['barvy', 'barvy_tmave'] as $skupina) {
                    if (is_array($zmeny[$skupina] ?? null)) {
                        $zmeny[$skupina] += $ds[$skupina];
                    }
                }
                $ds = DesignSystem::vycisti($zmeny + $ds);
                $web->set('design_system', (string) json_encode($ds, JSON_UNESCAPED_SLASHES));
                \Kaleta\Front\Cache::vymaz();

                return ['design_system' => $ds, 'citelnost' => DesignSystem::kontrasty($ds), 'nahled' => $this->app->request->origin() . $this->app->url('')];

            case 'seznam_kolekci':
                return array_map(fn (array $k): array => ['kolekce' => $k['seo_link'], 'nazev' => $k['nazev'], 'detail' => (bool) $k['detail'], 'pole' => $k['pole'],
                    'polozek' => (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ?', [$k['idk']])], Kolekce::vsechny($db));

            case 'vytvor_kolekci':
                $jenAdmin();
                $nazevKolekce = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($nazevKolekce === '') {
                    throw new \InvalidArgumentException('Chybí název kolekce.');
                }
                $seo = $this->adresaKolekce((string) ($a['adresa'] ?? '') !== '' ? (string) $a['adresa'] : $nazevKolekce, 0);
                $pole = Kolekce::vycistiPole($a['pole'] ?? []);
                $db->insert('kolekce', ['nazev' => $nazevKolekce, 'seo_link' => $seo, 'detail' => empty($a['detail']) ? 0 : 1, 'pole' => (string) json_encode($pole, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]);

                return ['kolekce' => $seo, 'pole' => $pole];

            case 'uprav_kolekci':
                $jenAdmin();
                $kolekce = $this->kolekce((string) ($a['kolekce'] ?? ''));
                $zmeny = ['zmeneno' => date('Y-m-d H:i:s')];
                if (isset($a['nazev']) && trim((string) $a['nazev']) !== '') {
                    $zmeny['nazev'] = mb_substr(trim((string) $a['nazev']), 0, 100);
                }
                if (isset($a['adresa']) && trim((string) $a['adresa']) !== '') {
                    $zmeny['seo_link'] = $this->adresaKolekce((string) $a['adresa'], (int) $kolekce['idk']);
                }
                if (array_key_exists('detail', $a)) {
                    $zmeny['detail'] = empty($a['detail']) ? 0 : 1;
                }
                if (is_array($a['pole'] ?? null)) {
                    $zmeny['pole'] = (string) json_encode(Kolekce::vycistiPole($a['pole']), JSON_UNESCAPED_UNICODE);
                }
                $db->update('kolekce', $zmeny, ['idk' => $kolekce['idk']]);
                \Kaleta\Front\Cache::vymaz();
                $nova = (array) $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$kolekce['idk']]);

                return ['kolekce' => $nova['seo_link'], 'nazev' => $nova['nazev'], 'detail' => (bool) $nova['detail'], 'pole' => json_decode((string) $nova['pole'], true) ?: []];

            case 'seznam_polozek_kolekce':
                $kolekce = $this->kolekce((string) ($a['kolekce'] ?? ''));

                $kde = ['idk = ?'];
                $par = [$kolekce['idk']];
                if (isset($a['jazyk']) && is_string($a['jazyk'])) {
                    $kde[] = 'jazyk = ?';
                    $par[] = $a['jazyk'];
                }
                if (!empty($a['jen_zobrazene'])) {
                    $kde[] = 'zobrazit = 1';
                }
                if (is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '') {
                    $kde[] = '(nazev LIKE ? OR data LIKE ?)';
                    $vzor = '%' . addcslashes(mb_substr(trim($a['hledat']), 0, 100), '%_\\') . '%';
                    array_push($par, $vzor, $vzor);
                }
                $radky = $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . implode(' AND ', $kde) . ' ORDER BY poradi, nazev LIMIT 5000', $par);
                if (is_string($a['pole'] ?? null) && $a['pole'] !== '') {
                    // přesná shoda hodnoty pole (JSON v databázi – filtruje se tady, bez závislosti na verzi MySQL)
                    $radky = array_values(array_filter($radky, fn (array $r): bool => (string) ((json_decode((string) $r['data'], true) ?: [])[$a['pole']] ?? '') === (string) ($a['hodnota'] ?? '')));
                }
                $strana = max(1, (int) ($a['strana'] ?? 1));

                return ['celkem' => count($radky), 'strana' => $strana, 'stran' => max(1, (int) ceil(count($radky) / 50)), 'polozky' => array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'nazev' => $r['nazev'], 'seo_link' => $r['seo_link'], 'poradi' => (int) $r['poradi'], 'zobrazit' => (bool) $r['zobrazit'],
                    'jazyk' => $r['jazyk'], 'data' => json_decode((string) $r['data'], true) ?: new \stdClass()], array_slice($radky, ($strana - 1) * 50, 50))];

            case 'uloz_polozku_kolekce':
                if (!$auth->maModul('kolekce')) {
                    throw new \DomainException('Kolekce smí upravovat editor nebo správce.');
                }
                $kolekce = $this->kolekce((string) ($a['kolekce'] ?? ''));
                $puvodni = isset($a['id']) ? $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [(int) $a['id'], $kolekce['idk']]) : null;
                if (isset($a['id']) && $puvodni === null) {
                    throw new \InvalidArgumentException('Položka v kolekci není. Použij seznam_polozek_kolekce.');
                }
                // při úpravě je název nepovinný – zůstane dosavadní
                $nazevPolozky = mb_substr(trim((string) ($a['nazev'] ?? $puvodni['nazev'] ?? '')), 0, 200);
                if ($nazevPolozky === '') {
                    throw new \InvalidArgumentException('Položka musí mít název.');
                }
                $chyby = [];
                $data = Kolekce::vycistiData($kolekce['pole'], (is_array($a['data'] ?? null) ? $a['data'] : []) + (json_decode((string) ($puvodni['data'] ?? '{}'), true) ?: []), $chyby);
                $adresa = trim((string) ($a['adresa'] ?? ''));
                $seo = $adresa !== '' ? slugify($adresa, 150) : ($puvodni['seo_link'] ?? slugify($nazevPolozky, 150));
                if ($seo === '' || $seo === '_ukazka') {
                    throw new \InvalidArgumentException('Neplatná adresa položky.');
                }
                for ($i = 2, $zaklad = $seo; $db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND idp <> ?', [$kolekce['idk'], $seo, (int) ($puvodni['idp'] ?? 0)]) !== null; $i++) {
                    $seo = $zaklad . '-' . $i;
                }
                $radek = ['nazev' => $nazevPolozky, 'seo_link' => $seo, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]
                    + (array_key_exists('jazyk', $a) ? ['jazyk' => Jazyk::sloupec($web, (string) $a['jazyk'])] : [])
                    + (array_key_exists('poradi', $a) ? ['poradi' => max(-9999, min(9999, (int) $a['poradi']))] : [])
                    + (array_key_exists('zobrazit', $a) ? ['zobrazit' => (int) (bool) $a['zobrazit']] : []);
                if ($puvodni !== null) {
                    $db->update('kolekce_polozky', $radek, ['idp' => $puvodni['idp']]);
                    $idp = (int) $puvodni['idp'];
                } else {
                    $idp = $db->insert('kolekce_polozky', $radek + ['idk' => $kolekce['idk'], 'datum' => date('Y-m-d H:i:s'), 'zobrazit' => 0]);
                }

                return ['id' => $idp, 'kolekce' => $kolekce['seo_link'], 'neplatna_pole' => array_keys($chyby),
                    'adresa' => $kolekce['detail'] ? $this->app->request->origin() . $this->app->url($kolekce['seo_link'] . '/' . $seo) : null];

            case 'seznam_novinek':
                $where = ['c.smazano IS NULL']; // koš se přes MCP nevypisuje ani needituje
                $p = [];
                if (($autori = $auth->spravovaniAutori()) !== null) {
                    $where[] = 'c.autor IN (' . implode(',', $autori) . ')';
                }
                $stavy = ['vydane' => 'c.visible = 1 AND c.datum <= NOW()', 'plan' => 'c.visible = 1 AND c.datum > NOW()', 'koncepty' => 'c.visible = 0'];
                if (isset($stavy[$a['stav'] ?? ''])) {
                    $where[] = $stavy[$a['stav']];
                }
                if (!empty($a['kategorie'])) {
                    $where[] = 'c.tema = ?';
                    $p[] = $this->kategorie((string) $a['kategorie']);
                }
                if (!empty($a['hledat'])) {
                    $where[] = 'c.titulek LIKE ?';
                    $p[] = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
                }

                return $db->all(
                    'SELECT c.idc AS id, c.titulek, c.seo_link, t.nazev AS kategorie, c.datum, c.visible AS vydana
                     FROM {novinky} c JOIN {kategorie} t ON t.idt = c.tema WHERE ' . implode(' AND ', $where) . ' ORDER BY c.datum DESC LIMIT ?',
                    [...$p, max(1, min(50, (int) ($a['limit'] ?? 20)))],
                );

            case 'nacti_novinku':
                $c = $this->novinka((int) ($a['id'] ?? 0));

                return array_intersect_key($c, array_flip(['idc', 'titulek', 'seo_link', 'uvod', 'text', 'obrazek', 'obrazek_popis', 'datum', 'visible', 'faq', 'seo_titulek', 'seo_popis']))
                    + ['kategorie' => $db->value('SELECT nazev FROM {kategorie} WHERE idt = ?', [$c['tema']]),
                        'stitky' => array_column($db->all('SELECT s.nazev FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ?', [$c['idc']]), 'nazev'),
                        'adresa' => $this->app->request->origin() . $this->app->url('novinky/' . $c['seo_link'])];

            case 'vytvor_novinku':
            case 'uprav_novinku':
                if (!$auth->maModul('novinky')) {
                    throw new \DomainException('K novinkám nemáš přístup (role uživatele).');
                }

                return $this->ulozNovinku($nazev === 'uprav_novinku' ? $this->novinka((int) ($a['id'] ?? 0)) : null, $a);

            case 'seznam_kategorii':
                return array_map(fn (array $r): array => ['id' => (int) $r['idt'], 'nazev' => $r['nazev'], 'adresa' => $r['seo_link'], 'jazyk' => $r['jazyk'], 'novinek' => (int) $r['pocet_clanku']], Kategorie::seznam($db));

            case 'vytvor_kategorii':
                if (!$auth->smiVydavat()) {
                    throw new \DomainException('Kategorie smí zakládat editor nebo správce.');
                }
                $jmeno = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100);
                if ($jmeno === '') {
                    throw new \InvalidArgumentException('Chybí název kategorie.');
                }
                $seo = $this->volnaAdresa('kategorie', 'idt', slugify($jmeno, 110));

                return ['id' => $db->insert('kategorie', ['nazev' => $jmeno, 'seo_link' => $seo, 'popis' => \Kaleta\Core\Html::bezpecne((string) ($a['popis'] ?? ''))]), 'adresa' => $seo];

            case 'seznam_medii':
                $hledat = is_string($a['hledat'] ?? null) && trim($a['hledat']) !== '' ? '%' . addcslashes(trim($a['hledat']), '%_\\') . '%' : null;

                return array_map(fn (array $o): array => $this->medium($o),
                    $db->all('SELECT * FROM {media}' . ($hledat !== null ? ' WHERE nazev LIKE ? OR obr_poloha LIKE ?' : '') . ' ORDER BY ido DESC LIMIT ?',
                        [...($hledat !== null ? [$hledat, $hledat] : []), max(1, min(50, (int) ($a['limit'] ?? 20)))]));

            case 'nahraj_soubor':
                return $this->nahrajSoubor($a);

            case 'nahled_odkaz':
                $cil = $this->cilStavby($a);
                $minut = max(1, min(10080, (int) ($a['minut'] ?? 60)));

                return $this->popisCile($cil) + ['nahled' => $this->nahledCile($cil, $minut), 'plati_do' => date('Y-m-d H:i', time() + $minut * 60)];

            case 'uprav_nastaveni':
                $jenAdmin();
                $zmeny = is_array($a['nastaveni'] ?? null) ? $a['nastaveni'] : [];
                $ulozeno = [];
                $chyby = [];
                foreach ($zmeny as $klic => $hodnota) {
                    $klic = (string) $klic;
                    if (in_array($klic, ['logo_webu', 'favicon', 'og_obrazek'], true)) {
                        // logo a ikona: soubor z Médií (nahraj_soubor) nebo ze systému (image/…); prázdné = bez loga / ikony
                        $cesta = ltrim(trim((string) $hodnota), '/');
                        $ok = $cesta === '' || (preg_match('#^(media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif)$#', $cesta) && !str_contains($cesta, '..') && is_file(KALETA_ROOT . '/' . $cesta));
                        if ($ok && $klic === 'favicon' && $cesta !== '') {
                            // ikony pro telefony a instalaci webu (media/ikona-<n>.png) se připraví hned, jako ve Vzhledu
                            $ok = \Kaleta\Core\Obrazky::ikony(KALETA_ROOT . '/' . $cesta);
                        } elseif ($klic === 'favicon') {
                            array_map(fn (int $n): bool => @unlink(KALETA_ROOT . '/media/ikona-' . $n . '.png'), \Kaleta\Core\Obrazky::IKONY);
                        }
                        if (!$ok) {
                            $chyby[$klic] = 'Cesta k souboru z Médií (media/…) nebo ze systému (image/…); ikona musí jít převést na PNG.';
                            continue;
                        }
                        $web->set($klic, $cesta);
                        $ulozeno[$klic] = $cesta;
                        continue;
                    }
                    $cista = preg_match(self::NASTAVENI_MCP, $klic) && is_scalar($hodnota) ? \Kaleta\Admin\Moduly\Konfigurace::overHodnotu($klic, is_bool($hodnota) ? ($hodnota ? '1' : '0') : (string) $hodnota) : null;
                    if ($cista !== null && $klic === 'titulni_stranka' && (int) $cista > 0
                        && $db->value('SELECT ids FROM {stranky} WHERE ids = ? AND zobrazit = 1 AND smazano IS NULL', [(int) $cista]) === null) {
                        $chyby[$klic] = 'Úvodní stránkou může být jen zveřejněná stránka.';
                        continue;
                    }
                    if ($cista === null) {
                        $chyby[$klic] = preg_match(self::NASTAVENI_MCP, $klic) ? 'Neplatná hodnota.' : 'Tohle nastavení přes MCP měnit nejde (jen v administraci).';
                        continue;
                    }
                    $web->set($klic, $cista);
                    $ulozeno[$klic] = $cista;
                }
                if ($ulozeno !== []) {
                    \Kaleta\Front\Cache::vymaz();
                }
                $aktualni = [];
                foreach (['nazev_webu', 'popis_webu', 'text_paticky', 'logo_webu', 'favicon', 'titulni_stranka', 'soc_facebook', 'soc_instagram', 'soc_x', 'soc_youtube', 'soc_linkedin', 'pocet_clanku',
                    'og_obrazek', 'firma_nazev', 'firma_typ', 'firma_ico', 'firma_dic', 'firma_rejstrik', 'firma_zastupce', 'firma_ulice', 'firma_mesto', 'firma_psc', 'firma_zeme', 'firma_telefon', 'firma_email', 'firma_hodiny', 'firma_mapa', 'firma_gps', 'tmavy_rezim', 'tmavy_prepinac'] as $klic) {
                    $aktualni[$klic] = $web->get($klic);
                }

                return ['ulozeno' => $ulozeno ?: new \stdClass(), 'chyby' => $chyby ?: new \stdClass(), 'nastaveni' => $aktualni];

            case 'seznam_presmerovani':
            case 'uloz_presmerovani':
                if (!\Kaleta\Core\Rozsireni::je($web, 'presmerovani')) {
                    throw new \DomainException('Rozšíření Přesměrování je vypnuté (Rozšíření v administraci).');
                }
                if ($nazev === 'uloz_presmerovani') {
                    $jenAdmin();
                    $z = trim((string) parse_url((string) ($a['z'] ?? ''), PHP_URL_PATH), '/ ');
                    $na = trim((string) ($a['na'] ?? ''));
                    if ($z === '' || !preg_match('#^[A-Za-z0-9/._~%-]{1,250}$#', $z)) {
                        throw new \InvalidArgumentException('Stará cesta musí být cesta na tomto webu, např. /stara-stranka.');
                    }
                    if (!empty($a['smazat'])) {
                        $db->delete('presmerovani', ['z_adresy' => $z]);
                    } else {
                        if (!preg_match('#^https?://[^\s]{3,240}$#i', $na) && !preg_match('#^/?[^\s:]{0,250}$#', $na)) {
                            throw new \InvalidArgumentException('Nová adresa musí být cesta (/nova) nebo https://… adresa.');
                        }
                        $na = preg_match('#^https?://#i', $na) ? $na : trim($na, '/');
                        \Kaleta\Admin\Moduly\Presmerovani::pridej($db, $z, $na);
                        $db->run('UPDATE {presmerovani} SET typ = ? WHERE z_adresy = ?', [(int) ($a['typ'] ?? 301) === 302 ? 302 : 301, $z]);
                        $db->delete('nenalezeno', ['cesta' => $z]);
                    }
                    \Kaleta\Front\Cache::vymaz();
                }

                return ['presmerovani' => $db->all('SELECT z_adresy AS z, na_adresu AS na, typ, pocet FROM {presmerovani} ORDER BY z_adresy LIMIT 500'),
                    'nenalezeno' => $db->all('SELECT cesta, pocet, naposledy FROM {nenalezeno} ORDER BY pocet DESC LIMIT 30')];

            case 'smaz_stranku':
                if (!$auth->smiVydavat()) {
                    throw new \DomainException('Stránku smí smazat editor nebo správce.');
                }
                $stranka = $this->stranka((int) ($a['id'] ?? 0));
                if ((int) $stranka['ids'] === $web->int('titulni_stranka')) {
                    throw new \DomainException('Úvodní stránku smazat nejde – nejdřív nastav jinou (uprav_nastaveni → titulni_stranka).');
                }
                $db->run('UPDATE {stranky} SET smazano = NOW(), zobrazit = 0 WHERE ids = ? AND smazano IS NULL', [(int) $stranka['ids']]);
                \Kaleta\Front\Cache::vymaz();

                return ['id' => (int) $stranka['ids'], 'stav' => 'v koši – obnovit jde 30 dní v administraci (Stránky → Koš)'];
        }
        throw new \InvalidArgumentException('Neznámý nástroj: ' . $nazev);
    }

    /**
     * @param array<string, mixed>|null $puvodni
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function ulozNovinku(?array $puvodni, array $a): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if ($puvodni !== null && $puvodni['visible'] && !$auth->smiVydavat()) {
            throw new \DomainException('Vydanou novinku může upravit jen editor nebo správce.');
        }
        $data = [];
        foreach (['titulek' => 255, 'uvod' => 0, 'text' => 0, 'faq' => 0, 'seo_titulek' => 255, 'seo_popis' => 320, 'obrazek' => 255, 'obrazek_popis' => 300] as $pole => $max) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = $max > 0 ? mb_substr((string) $a[$pole], 0, $max) : (string) $a[$pole];
            }
        }
        foreach (['uvod', 'text'] as $pole) {
            if (isset($data[$pole])) {
                $data[$pole] = \Kaleta\Core\Html::proUzivatele($data[$pole], $this->app->auth());
            }
        }
        if (array_key_exists('kategorie', $a)) {
            $data['tema'] = $this->kategorie((string) $a['kategorie']);
            // novinka přebírá jazykovou verzi kategorie – stejně jako při uložení v administraci
            $data['jazyk'] = (string) $db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$data['tema']]);
        }
        if (!empty($a['datum'])) {
            $ts = strtotime((string) $a['datum']);
            if ($ts === false) {
                throw new \InvalidArgumentException('Datum nemá platný tvar (RRRR-MM-DD HH:MM).');
            }
            $data['datum'] = date('Y-m-d H:i:s', $ts);
        }
        if (array_key_exists('vydat', $a)) {
            if ($a['vydat'] && !$auth->smiVydavat()) {
                throw new \DomainException('Uživatel nemá právo vydávat – novinku lze uložit jen jako koncept.');
            }
            $data['visible'] = (int) (bool) $a['vydat'];
        }
        if (($data['titulek'] ?? $puvodni['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Novinka musí mít titulek.');
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');

        if ($puvodni === null) {
            if (!isset($data['tema'])) {
                throw new \InvalidArgumentException('Chybí kategorie.');
            }
            $data += ['uvod' => '', 'text' => '', 'autor' => $auth->id(), 'datum' => date('Y-m-d H:i:s'), 'visible' => 0,
                'seo_link' => $this->volnaAdresa('novinky', 'idc', slugify($data['titulek'], 150))];
            $id = $db->insert('novinky', $data);
        } else {
            $id = (int) $puvodni['idc'];
            $db->insert('novinky_revize', ['idc' => $id, 'datum' => $puvodni['zmeneno'] ?? $puvodni['datum'], 'kdo' => $auth->id(), 'titulek' => $puvodni['titulek'], 'uvod' => $puvodni['uvod'], 'text' => $puvodni['text']]);
            $db->update('novinky', $data, ['idc' => $id]);
        }
        \Kaleta\Core\Hledani::indexuj($db, $id);
        if (array_key_exists('stitky', $a)) {
            $db->delete('novinky_stitky', ['idc' => $id]);
            foreach (array_slice(array_unique(array_filter(array_map(trim(...), explode(',', (string) $a['stitky'])))), 0, 20) as $stitek) {
                $seo = slugify($stitek, 90);
                $ids = $db->value('SELECT ids FROM {stitky} WHERE seo_link = ?', [$seo]) ?? $db->insert('stitky', ['nazev' => mb_substr($stitek, 0, 80), 'seo_link' => $seo]);
                $db->run('INSERT IGNORE INTO {novinky_stitky} (idc, ids) VALUES (?, ?)', [$id, (int) $ids]);
            }
        }
        $ulozena = $db->one('SELECT * FROM {novinky} WHERE idc = ?', [$id]);
        Galerie::zapisPouziti($db, $id, $ulozena['obrazek'], $ulozena['uvod'], $ulozena['text']);

        return ['id' => $id, 'stav' => !$ulozena['visible'] ? 'koncept' : (strtotime($ulozena['datum']) > time() ? 'naplánováno' : 'vydáno'),
            'nahled' => $this->app->request->origin() . $this->app->url('novinky/' . $ulozena['seo_link'] . '?nahled=1'),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?modul=novinky&akce=edit&id=' . $id)];
    }

    /**
     * @param array<string, mixed>|null $puvodni
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function ulozStranku(?array $puvodni, array $a): array
    {
        $db = $this->app->db();
        $data = [];
        foreach (['titulek' => 200, 'text' => 0, 'popis' => 300, 'seo_titulek' => 200, 'obrazek' => 255] as $pole => $max) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = $max > 0 ? mb_substr((string) $a[$pole], 0, $max) : (string) $a[$pole];
            }
        }
        foreach (['uvod', 'text'] as $pole) {
            if (isset($data[$pole])) {
                $data[$pole] = \Kaleta\Core\Html::proUzivatele($data[$pole], $this->app->auth());
            }
        }
        foreach (['v_menu', 'zobrazit', 'noindex'] as $pole) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = (int) (bool) $a[$pole];
            }
        }
        if (array_key_exists('poradi', $a)) {
            $data['poradi'] = max(0, min(65535, (int) $a['poradi']));
        }
        if (!$this->app->auth()->smiVydavat()) {
            // bez práva vydávat: zveřejněnou stránku neměnit, novou nechat skrytou (stejně jako v administraci)
            if ($puvodni !== null && $puvodni['zobrazit']) {
                throw new \DomainException('Zveřejněnou stránku smí upravit jen editor nebo správce.');
            }
            unset($data['zobrazit']);
        }
        if (($data['titulek'] ?? $puvodni['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Stránka musí mít název.');
        }
        $web = $this->app->settings();
        if (array_key_exists('jazyk', $a)) {
            $data['jazyk'] = Jazyk::sloupec($web, (string) $a['jazyk']);
            if ($data['jazyk'] === '' && !in_array((string) $a['jazyk'], ['', Jazyk::vychozi($web)], true)) {
                throw new \InvalidArgumentException('Jazyková verze „' . $a['jazyk'] . '“ není zapnutá (Rozšíření → Jazykové verze, jazyky v Nastavení).');
            }
        }
        $jazyk = $data['jazyk'] ?? (string) ($puvodni['jazyk'] ?? '');
        if (array_key_exists('preklad_z', $a) || array_key_exists('jazyk', $a)) {
            $data['preklad_z'] = $jazyk === '' ? null
                : ($db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ? AND smazano IS NULL", [(int) ($a['preklad_z'] ?? $puvodni['preklad_z'] ?? 0), (int) ($puvodni['ids'] ?? 0)]) ?: null);
        }
        // nadřazená stránka: stejný jazyk, ne ona sama ani její podstránka (jinak by vznikl kruh); adresa je /nadrazena/stranka
        $rodic = null;
        $zmenaRodice = array_key_exists('nadrazena', $a) || array_key_exists('jazyk', $a);
        if ($zmenaRodice) {
            $idRodice = (int) ($a['nadrazena'] ?? $puvodni['nadrazena'] ?? 0);
            $rodic = $idRodice > 0 ? $db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ? AND ids <> ? AND jazyk = ? AND smazano IS NULL', [$idRodice, (int) ($puvodni['ids'] ?? 0), $jazyk]) : null;
            if ($idRodice > 0 && ($rodic === null || ($puvodni !== null && str_starts_with($rodic['seo_link'] . '/', $puvodni['seo_link'] . '/')))) {
                throw new \InvalidArgumentException('Nadřazená stránka musí existovat, mít stejný jazyk a nesmí to být tahle stránka ani její podstránka.');
            }
            $data['nadrazena'] = $rodic !== null ? (int) $rodic['ids'] : null;
        } elseif ($puvodni !== null && $puvodni['nadrazena'] !== null) {
            $rodic = $db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ?', [(int) $puvodni['nadrazena']]);
        }
        if (array_key_exists('zverejnit_od', $a)) {
            $od = strtotime(str_replace('T', ' ', (string) $a['zverejnit_od'])) ?: null;
            if (!$this->app->auth()->smiVydavat()) {
                throw new \DomainException('Zveřejnění naplánuje jen editor nebo správce.');
            }
            $data['zverejnit_od'] = $od !== null && $od > time() && !($data['zobrazit'] ?? $puvodni['zobrazit'] ?? 0) ? date('Y-m-d H:i:s', $od) : null;
            if ($od !== null && $od <= time()) {
                throw new \InvalidArgumentException('Čas zveřejnění už proběhl – zadej budoucí čas, nebo stránku zveřejni parametrem zobrazit.');
            }
        }
        if (!empty($data['zobrazit'])) {
            $data['zverejnit_od'] = null; // zveřejněná stránka už na plán nečeká
        }
        if (array_key_exists('adresa', $a) || $puvodni === null || $zmenaRodice) {
            $zaklad = ($a['adresa'] ?? '') !== '' ? basename(str_replace('\\', '/', (string) $a['adresa']))
                : ($puvodni !== null ? basename((string) $puvodni['seo_link']) : $data['titulek']);
            $predpona = $rodic !== null ? $rodic['seo_link'] . '/' : '';
            $seo = $predpona . slugify($zaklad, max(20, 118 - strlen($predpona)));
            if ($rodic === null && (in_array($seo, Stranky::VYHRAZENE, true) || isset(\Kaleta\Core\Jazyk::DOSTUPNE[$seo]))) {
                throw new \InvalidArgumentException('Adresu „' . $seo . '“ používá systém, zvol jinou.');
            }
            if ($db->value('SELECT ids FROM {stranky} WHERE seo_link = ? AND ids <> ?', [$seo, (int) ($puvodni['ids'] ?? 0)]) !== null) {
                throw new \InvalidArgumentException('Stránka s adresou „' . $seo . '“ už existuje.');
            }
            $data['seo_link'] = $seo;
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');
        if ($puvodni === null) {
            $id = $db->insert('stranky', $data + ['text' => '', 'zobrazit' => 0, 'v_menu' => 0]);
            if (!empty($data['v_menu'])) {
                \Kaleta\Core\Menu::nastavStranku($db, $id, $jazyk, true);
            }
        } else {
            $id = (int) $puvodni['ids'];
            if (($data['titulek'] ?? $puvodni['titulek']) !== $puvodni['titulek'] || (string) ($data['text'] ?? $puvodni['text']) !== (string) $puvodni['text']) {
                Stranky::revize($db, $id, $this->app->auth()->id(), $puvodni['titulek'], (string) $puvodni['text']); // předchozí podoba do historie
            }
            $db->update('stranky', $data, ['ids' => $id]);
            if (isset($data['seo_link']) && $data['seo_link'] !== $puvodni['seo_link']) {
                Stranky::presun($db, $puvodni['seo_link'], $data['seo_link'], (bool) $puvodni['zobrazit']);
            }
        }
        $ulozena = $this->stranka($id);

        return ['id' => $id, 'stav' => $ulozena['zobrazit'] ? 'zveřejněná' : ($ulozena['zverejnit_od'] !== null ? 'skrytá, zveřejní se ' . substr((string) $ulozena['zverejnit_od'], 0, 16) : 'skrytá'),
            'adresa' => $this->app->request->origin() . $this->app->url(($ulozena['jazyk'] !== '' ? $ulozena['jazyk'] . '/' : '') . $ulozena['seo_link']),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?modul=stranky&akce=edit&id=' . $id)];
    }

    /** @return array<string, mixed> */
    private function stranka(int $id): array
    {
        $stranka = $this->app->db()->one('SELECT ids, titulek, seo_link, popis, seo_titulek, obrazek, noindex, text, zobrazit, zverejnit_od, v_menu, poradi, jazyk, preklad_z, nadrazena FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);
        if ($stranka === null) {
            throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');
        }

        return $stranka;
    }

    /**
     * Cíl stavby: stránka (id; bez id a se $zalozit nová skrytá stránka s názvem z „titulek“) nebo část webu (cast = typ, jazyk),
     * kterou smí měnit jen správce. Část, která ještě není, se založí s konceptem podle šablony.
     *
     * @return array{druh: string, radek: array<string, mixed>, stavba: ?string, koncept: ?string, jazyk: string}
     */
    private function cilStavby(array $a, bool $zalozit = false): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $web = $this->app->settings();
        if (isset($a['kolekce']) && $a['kolekce'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Šablonu detailu kolekce smí měnit jen správce webu.');
            }
            $radek = \Kaleta\Stavitel\Kolekce::podleSeo($db, (string) $a['kolekce']) ?? throw new \InvalidArgumentException('Kolekce neexistuje. Použij nástroj seznam_kolekci.');
            if ($radek['stavba'] === null && $radek['stavba_koncept'] === null) {
                // šablona, kterou by ukázal builder, dokud ji nikdo neupravil
                $radek['stavba_koncept'] = Stavba::naJson(\Kaleta\Stavitel\Kolekce::vychoziSablona($radek));
            }

            return ['druh' => 'kolekce', 'radek' => $radek, 'stavba' => $radek['stavba'], 'koncept' => $radek['stavba_koncept'], 'jazyk' => Jazyk::obsahu($web, ''),
                'revize' => ['cast' => 'kolekce:' . (int) $radek['idk']]];
        }
        if (isset($a['cast']) && $a['cast'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Části webu (záhlaví, patičku, obálky) smí měnit jen správce webu.');
            }
            $typ = (string) $a['cast'];
            if (!isset(Casti::TYPY[$typ])) {
                throw new \InvalidArgumentException('Neznámá část webu. Typy: ' . implode(', ', array_keys(Casti::TYPY)) . '.');
            }
            $jazyk = in_array($a['jazyk'] ?? '', Jazyk::dalsi($web), true) ? (string) $a['jazyk'] : '';
            $varianta = (string) ($a['varianta'] ?? '');
            if ($varianta !== '') {
                $radek = in_array($typ, Casti::S_VARIANTAMI, true) ? Casti::radek($db, $typ, $jazyk, $varianta) : null;
                if ($radek === null) {
                    throw new \InvalidArgumentException('Varianta neexistuje. Varianty záhlaví a patičky vypíše seznam_casti, založí uloz_variantu.');
                }
            } else {
                if (Casti::radek($db, $typ, $jazyk) === null) {
                    $db->insert('casti', ['typ' => $typ, 'jazyk' => $jazyk, 'stavba_koncept' => Stavba::naJson(Casti::vychozi($typ, Jazyk::obsahu($web, $jazyk))), 'zmeneno' => date('Y-m-d H:i:s')]);
                }
                $radek = (array) Casti::radek($db, $typ, $jazyk);
            }

            return ['druh' => 'cast', 'radek' => $radek, 'stavba' => $radek['stavba'], 'koncept' => $radek['stavba_koncept'], 'jazyk' => Jazyk::obsahu($web, $jazyk),
                'revize' => ['cast' => Casti::klicRevize($typ, $jazyk, $varianta)]];
        }
        if (!$auth->maModul('stranky')) {
            throw new \DomainException('Stránky smí upravovat editor nebo správce.');
        }
        if (!isset($a['id']) && $zalozit) {
            $a['id'] = $this->ulozStranku(null, ['titulek' => (string) ($a['titulek'] ?? '')])['id'];
        }
        $radek = $db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [(int) ($a['id'] ?? 0)]) ?? throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');

        return ['druh' => 'stranka', 'radek' => $radek, 'stavba' => $radek['stavba'], 'koncept' => $radek['stavba_koncept'], 'jazyk' => Jazyk::obsahu($web, $radek['jazyk']),
            'revize' => ['ids' => (int) $radek['ids']]];
    }

    /** Rozpracovaná, jinak publikovaná stavba cíle; textová stránka jako stavba z jejího textu. */
    private function stavbaCile(array $cil): array
    {
        return Stavba::zJson($cil['koncept'] ?? $cil['stavba'])
            ?? ($cil['druh'] === 'stranka' ? Stavba::zTextu($cil['radek']['titulek'], (string) $cil['radek']['text']) : ['v' => Stavba::VERZE, 'deti' => []]);
    }

    /** @return array<string, mixed> */
    private function popisCile(array $cil): array
    {
        return match ($cil['druh']) {
            'stranka' => ['id' => (int) $cil['radek']['ids'], 'titulek' => $cil['radek']['titulek']],
            'kolekce' => ['kolekce' => $cil['radek']['seo_link'], 'titulek' => 'Detail: ' . $cil['radek']['nazev'], 'detail_zapnuty' => (bool) $cil['radek']['detail']],
            default => ['cast' => $cil['radek']['typ'], 'jazyk' => $cil['radek']['jazyk'], 'titulek' => Casti::TYPY[$cil['radek']['typ']][0]]
                + ($cil['radek']['varianta'] !== '' ? ['varianta' => $cil['radek']['varianta']] : []),
        };
    }

    private function publikujCil(array $cil): void
    {
        $db = $this->app->db();
        if ($cil['druh'] === 'stranka') {
            Publikace::stranka($this->app, (array) $db->one('SELECT * FROM {stranky} WHERE ids = ?', [$cil['radek']['ids']]));
        } elseif ($cil['druh'] === 'kolekce') {
            $radek = (array) $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$cil['radek']['idk']]);
            // výchozí šablona, kterou nikdo neuložil, se publikuje taky (jinak by nebylo co publikovat)
            $radek['stavba_koncept'] ??= $cil['koncept'];
            Publikace::kolekce($this->app, $radek);
        } else {
            Publikace::cast($this->app, (array) Casti::radek($db, $cil['radek']['typ'], $cil['radek']['jazyk'], (string) $cil['radek']['varianta']));
        }
    }

    /** Vyčistí a uloží koncept (případně publikuje); vrací, co model potřebuje k další práci. */
    private function ulozStavbu(array $cil, array $vstup, bool $publikovat): array
    {
        $db = $this->app->db();
        [$stavba, $chyby] = Stavba::vycisti($vstup, $this->app->auth()->isAdmin(), Stavba::zJson($cil['koncept'] ?? $cil['stavba']));
        $r = $cil['radek'];
        if ($cil['druh'] === 'stranka') {
            $db->update('stranky', ['stavba_koncept' => Stavba::naJson($stavba)], ['ids' => $r['ids']]);
        } elseif ($cil['druh'] === 'kolekce') {
            $db->update('kolekce', ['stavba_koncept' => Stavba::naJson($stavba)], ['idk' => $r['idk']]);
            $cil['koncept'] = Stavba::naJson($stavba);
        } else {
            $db->update('casti', ['stavba_koncept' => Stavba::naJson($stavba)], ['typ' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta']]);
        }
        if ($publikovat) {
            $this->publikujCil($cil);
        }
        $parametry = match ($cil['druh']) {
            'stranka' => 'modul=stranky&akce=stavitel&id=' . (int) $r['ids'],
            'kolekce' => 'modul=kolekce&akce=stavitel&id=' . (int) $r['idk'],
            default => 'modul=casti&akce=stavitel&typ=' . $r['typ'] . '&jazyk=' . $r['jazyk'],
        };

        return $this->popisCile($cil) + ['stav' => $publikovat ? 'publikováno' : 'koncept – na webu se ukáže po publikování', 'prvku' => $this->pocetPrvku($stavba['deti']),
            'chyby' => $chyby, 'nahled' => $publikovat ? $this->adresaCile($cil) : $this->nahledCile($cil, 60),
            'stavitel' => $this->app->request->origin() . $this->app->url('admin.php?' . $parametry)] + $this->kontrolaCile($cil, $stavba);
    }

    /** Kontrola před publikováním jako v builderu (tlačítka bez odkazu, obrázky bez popisu, osnova nadpisů stránky); bez nálezů nic. */
    private function kontrolaCile(array $cil, array $stavba): array
    {
        $nalezy = \Kaleta\Stavitel\Kontrola::stavby($stavba, $cil['druh'] === 'stranka');

        return $nalezy === [] ? [] : ['kontrola' => $nalezy];
    }

    /** Podepsaný odkaz na koncept cíle (platí jen pro tenhle cíl a omezenou dobu). */
    private function nahledCile(array $cil, int $minut): string
    {
        $r = $cil['radek'];
        $podpis = match ($cil['druh']) {
            'stranka' => 'stranka:' . (int) $r['ids'],
            'kolekce' => 'kolekce:' . (int) $r['idk'],
            default => 'cast:' . $r['typ'] . ':' . $r['jazyk'],
        };
        $klic = \Kaleta\Core\Nahled::klic($this->app->db(), $this->app->settings(), $podpis, $minut);

        $varianta = $cil['druh'] === 'cast' && $r['varianta'] !== '' ? 'varianta=' . rawurlencode($r['varianta']) . '&' : '';

        return $this->adresaCile($cil) . '?' . ($cil['druh'] === 'cast' ? 'cast=' . $r['typ'] . '&' : '') . $varianta . 'stavba=koncept&nahled_klic=' . $klic;
    }

    /** Veřejná adresa, na které je cíl vidět (u části webu úvodní stránka, detail novinky, výpis, 404; u kolekce první položka). */
    private function adresaCile(array $cil): string
    {
        $r = $cil['radek'];
        if ($cil['druh'] === 'kolekce') {
            $polozka = $this->app->db()->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? ORDER BY zobrazit DESC, poradi, idp LIMIT 1', [$r['idk'], Jazyk::sloupecWebu()]);

            return $this->app->request->origin() . $this->app->url($r['seo_link'] . '/' . ($polozka ?? '_ukazka'));
        }
        if ($cil['druh'] === 'stranka') {
            $uvod = $this->app->settings()->int('titulni_stranka') === (int) $r['ids'];
            $cesta = $uvod ? '' : $r['seo_link'];
        } elseif ($r['varianta'] !== '') {
            // varianta se ukazuje na první stránce, pro kterou platí
            $ids = (int) ((json_decode((string) $r['stranky'], true) ?: [])[0] ?? 0);
            $cesta = (string) $this->app->db()->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
        } else {
            $cesta = match ($r['typ']) {
                'novinka', 'vypis' => 'novinky',
                'nenalezeno' => 'tahle-stranka-neexistuje',
                default => '',
            };
        }

        return $this->app->request->origin() . $this->app->url(($r['jazyk'] !== '' ? $r['jazyk'] . '/' : '') . $cesta);
    }

    private function pocetPrvku(array $deti): int
    {
        return array_sum(array_map(fn (array $p): int => 1 + $this->pocetPrvku($p['deti'] ?? []), $deti));
    }


    /** Médium pro výstup MCP: adresa pro stavbu (media/…), rozměry a jestli jde o obrázek. */
    private function medium(array $o): array
    {
        return ['id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'adresa' => $o['obr_poloha'], 'url' => $this->app->request->origin() . $this->app->url($o['obr_poloha']),
            'obrazek' => $o['nahl_poloha'] !== '', 'rozmery' => $o['nahl_poloha'] !== '' ? $o['obr_width'] . '×' . $o['obr_height'] : null];
    }

    /** @return array<string, mixed> */
    private function nahrajSoubor(array $a): array
    {
        $jmeno = basename(str_replace('\\', '/', trim((string) ($a['nazev'] ?? ''))));
        $pripona = strtolower(pathinfo($jmeno, PATHINFO_EXTENSION));
        if ($pripona === '') {
            throw new \InvalidArgumentException('Název souboru musí mít příponu (např. foto.jpg, logo.svg, pismo.woff2).');
        }
        if (is_string($a['url'] ?? null) && $a['url'] !== '') {
            $url = trim($a['url']);
            if (!str_starts_with(strtolower($url), 'https://')) {
                throw new \InvalidArgumentException('Stahovat jde jen z https adresy.');
            }
            try {
                $obsah = (new \Kaleta\Core\StahovaniObrazku($url))->stahni($url, false);
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException('Soubor se nepodařilo stáhnout: ' . $e->getMessage());
            }
        } else {
            $obsah = base64_decode(preg_replace('#^data:[^,]*,#', '', (string) ($a['data'] ?? '')) ?? '', true);
            if ($obsah === false || $obsah === '') {
                throw new \InvalidArgumentException('Chybí data souboru v base64 (parametr data), nebo url.');
            }
        }
        if (strlen($obsah) > self::MAX_NAHRANI) {
            throw new \InvalidArgumentException('Soubor je větší než ' . (self::MAX_NAHRANI >> 20) . ' MB.');
        }
        $docasny = tempnam(sys_get_temp_dir(), 'kaleta-mcp-');
        file_put_contents($docasny, $obsah);
        try {
            $data = match (true) {
                $pripona === 'svg' => Galerie::ulozSvgObsah($obsah, $jmeno),
                \Kaleta\Core\Soubory::jePriloha($jmeno) => \Kaleta\Core\Soubory::ulozSoubor($docasny, $jmeno),
                default => \Kaleta\Core\Obrazky::ulozSoubor($docasny, $jmeno),
            };
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } finally {
            @unlink($docasny);
        }
        if (is_string($a['popis'] ?? null) && trim($a['popis']) !== '') {
            $data['nazev'] = mb_substr(trim($a['popis']), 0, 150);
        }
        $data['ido'] = $this->app->db()->insert('media', $data + ['vlastnik' => $this->app->auth()->id(), 'sekce' => null, 'datum' => date('Y-m-d H:i:s')]);

        return $this->medium($data) + ['pouziti' => match (true) {
            $pripona === 'woff2' || $pripona === 'woff' => 'uprav_design_system {"ds":{"vlastni_pisma":[{"nazev":"…","soubor":"' . $data['obr_poloha'] . '"}],"pismo_titulky":"vlastni-1"}}',
            $data['nahl_poloha'] !== '' => 'prvek obrazek {"src":"' . $data['obr_poloha'] . '"} nebo styl obrazek_pozadi',
            default => 'odkaz na soubor: /' . $data['obr_poloha'],
        }];
    }

    /** @return array<string, mixed> */
    /** Volná adresa kolekce: ne systémová cesta, kód jazyka ani adresa jiné kolekce. */
    private function adresaKolekce(string $zadano, int $idk): string
    {
        $seo = slugify($zadano, 110);
        if ($seo === '' || in_array($seo, Stranky::VYHRAZENE, true) || isset(Jazyk::DOSTUPNE[$seo])
            || $this->app->db()->value('SELECT idk FROM {kolekce} WHERE seo_link = ? AND idk <> ?', [$seo, $idk]) !== null) {
            throw new \InvalidArgumentException('Adresu „' . $seo . '“ už používá systém nebo jiná kolekce.');
        }

        return $seo;
    }

    private function kolekce(string $seo): array
    {
        return Kolekce::podleSeo($this->app->db(), $seo) ?? throw new \InvalidArgumentException('Kolekce neexistuje. Použij seznam_kolekci.');
    }

    /** @return array<string, mixed> novinka, ke které má uživatel přístup */
    private function novinka(int $id): array
    {
        $novinka = $this->app->db()->one('SELECT * FROM {novinky} WHERE idc = ? AND smazano IS NULL', [$id]);
        $autori = $this->app->auth()->spravovaniAutori();
        if ($novinka === null || ($autori !== null && !in_array((int) $novinka['autor'], $autori, true))) {
            throw new \InvalidArgumentException('Novinka neexistuje nebo k ní uživatel nemá přístup.');
        }

        return $novinka;
    }

    private function kategorie(string $nazevNeboAdresa): int
    {
        $idt = $this->app->db()->value('SELECT idt FROM {kategorie} WHERE seo_link = ? OR nazev = ? LIMIT 1', [$nazevNeboAdresa, $nazevNeboAdresa]);
        if ($idt === null) {
            throw new \InvalidArgumentException('Kategorie „' . $nazevNeboAdresa . '“ neexistuje. Použij nástroj seznam_kategorii.');
        }

        return (int) $idt;
    }

    private function volnaAdresa(string $tabulka, string $klic, string $seo): string
    {
        $kandidat = $seo;
        for ($i = 2; $this->app->db()->value("SELECT {$klic} FROM {{$tabulka}} WHERE seo_link = ?", [$kandidat]) !== null; $i++) {
            $kandidat = $seo . '-' . $i;
        }

        return $kandidat;
    }
}
