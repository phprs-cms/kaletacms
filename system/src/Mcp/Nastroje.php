<?php

declare(strict_types=1);

namespace MiroCMS\Mcp;

use MiroCMS\Admin\Moduly\Galerie;
use MiroCMS\Admin\Moduly\Kategorie;
use MiroCMS\Admin\Moduly\Stranky;
use MiroCMS\Core\App;
use MiroCMS\Core\Jazyk;
use MiroCMS\Front\Identita;
use MiroCMS\Front\Layouty;
use MiroCMS\Stavitel\Casti;
use MiroCMS\Stavitel\DesignSystem;
use MiroCMS\Stavitel\Knihovna;
use MiroCMS\Stavitel\Kolekce;
use MiroCMS\Stavitel\Publikace;
use MiroCMS\Stavitel\Stavba;
use MiroCMS\Stavitel\ZHtml;

/**
 * Nástroje, které MCP server nabízí Claudovi. Každý nástroj respektuje práva uživatele, jehož tokenem se Claude hlásí:
 * autor pracuje jen se svými novinkami a nevydává, editor s veškerým obsahem, správce navíc se šablonami webu.
 *
 * Chyba určená Claudovi (špatný vstup, chybějící právo) se hlásí výjimkou InvalidArgumentException / DomainException.
 */
final class Nastroje
{
    /** Šablony dodávané se systémem - přepsala by je aktualizace, proto se upravují jen jejich kopie. */
    private const array VESTAVENE_SABLONY = ['zakladni'];

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
        ];
        $cil = ['id' => $cislo('ID stránky'), 'cast' => $text('Místo stránky část webu (jen správce): ' . implode(' | ', array_keys(Casti::TYPY)) . ' – záhlaví, patička, obálky detailu novinky, výpisu a 404'),
            'jazyk' => $text('Jazyk části webu u vícejazyčného webu (prázdné = výchozí)')];
        $nastroje = [
            ['info_o_webu', 'Název webu, úvodní stránka, šablona, počty stránek a novinek, role přihlášeného uživatele a jeho oprávnění.', $s([])],
            ['seznam_stranek', 'Stránky webu (Úvod, O nás, Služby, Kontakt…) s adresami.', $s([])],
            ['nacti_stranku', 'Celá stránka včetně HTML obsahu.', $s(['id' => $cislo('ID stránky')], ['id'])],
            ['vytvor_stranku', 'Založí stránku (editor a správce). Bez "zobrazit": true zůstane skrytá.', $s($stranka, ['titulek'])],
            ['uprav_stranku', 'Změní zadaná pole stránky; ostatní ponechá.', $s(['id' => $cislo('ID stránky')] + $stranka, ['id'])],
            ['nacti_menu', 'Menu webu (hlavní nebo v patičce) pro jazykovou verzi: položky s podmenu a jestli se hlavní menu zatím skládá automaticky ze stránek „v menu“.',
                $s(['umisteni' => $text('hlavni (výchozí) | paticka'), 'jazyk' => $text('jazyková verze (prázdné = výchozí)')])],
            ['uloz_menu', 'Uloží celé menu (správce). Položky: {"typ":"stranka","ids":5,"text":""} (prázdný text = název stránky) | {"typ":"odkaz","text":"…","url":"https://… nebo /cesta","nove_okno":false} | {"typ":"novinky"} | {"typ":"skupina","text":"Služby"} – každá může mít "deti" (jedna úroveň podmenu). null = hlavní menu zase automaticky.',
                $s(['umisteni' => $text('hlavni | paticka'), 'jazyk' => $text('jazyková verze (prázdné = výchozí)'), 'polozky' => ['type' => ['array', 'null'], 'items' => ['type' => 'object'], 'description' => 'položky menu']], ['umisteni', 'polozky'])],
            ['stavba_schema', 'Jak se skládá stránka ve staviteli: typy prvků a jejich pole, vlastnosti stylu, tokeny design systému (barvy, mezery, písmo), hotové sekce knihovny a sdílené třídy webu. Načti před prvním použitím nástrojů stavba_*.', $s([])],
            ['stavba_nacti', 'Stavba stránky nebo části webu (strom prvků) – rozpracovaný koncept, jinak publikovaná verze. Stránka bez stavby vrátí stavbu z jejího textu.', $s($cil)],
            ['stavba_z_html', 'DOPORUČENÁ CESTA pro novou stránku nebo sekce: napiš sémantické HTML (section/header, h1–h3, p, ul, a, img, figure, blockquote, details) a vzhled do bloku <style> jako pravidla jedné třídy (.karta { … }) s tokeny var(--mc-…). Převede se na stavbu a třídy; vrátí hlášení, co převést nešlo. Uloží se jako koncept.',
                $s(['html' => $text('HTML obsahu (bez <html>/<head>); <style> smí být uvnitř. Záhlaví a patičku skládej z prvků logo, navigace a udaje přes stavba_uloz – HTML je nepřevede.'), 'id' => $cislo('ID stránky; bez něj (a bez cast) vznikne nová skrytá stránka s názvem z parametru titulek'), 'cast' => $cil['cast'], 'jazyk' => $cil['jazyk'], 'titulek' => $text('Název nové stránky (když není id)'),
                    'rezim' => $text('nahradit (výchozí) = celá stavba z HTML | pridat = sekce na konec stávající stavby'), 'prepsat_tridy' => ['type' => 'boolean', 'description' => 'true = třídy, které už na webu jsou, se přepíšou stylem z <style>; jinak zůstanou'],
                    'publikovat' => ['type' => 'boolean', 'description' => 'true = hned publikovat (jen na výslovný pokyn uživatele); jinak koncept k náhledu']], ['html'])],
            ['stavba_uloz', 'Uloží celou stavbu stránky (strom z stavba_nacti s úpravami) jako koncept. Pro drobné úpravy obsahu a stylu jednotlivých prvků. Vrátí vyčištěnou stavbu a chyby.',
                $s($cil + ['stavba' => ['type' => 'object', 'description' => '{"v":1,"deti":[…]} podle stavba_schema'], 'publikovat' => ['type' => 'boolean', 'description' => 'true = publikovat (jen na výslovný pokyn uživatele)']], ['stavba'])],
            ['vloz_sekci', 'Vloží hotovou sekci z knihovny (úvod, výhody, služby, čísla, reference, faq, výzva, novinky, kontakt) na konec konceptu stránky nebo části webu.', $s($cil + ['sekce' => $text('klíč sekce ze stavba_schema → knihovna')], ['sekce'])],
            ['publikuj_stavbu', 'Publikuje koncept stavby stránky nebo části webu (jen na výslovný pokyn uživatele). Předchozí verze zůstane v historii.', $s($cil)],
            ['uprav_design_system', 'Změní vzhled celého webu (správce): barvy, písma, velikosti, šířku, zaoblení – nebo použije předvolbu. Nezadané hodnoty zůstanou. Vrátí kontrolu čitelnosti barev.',
                $s(['predvolba' => $text('firemni | remeslo | pratelsky | elegantni | technologie (nepovinné)'), 'ds' => ['type' => 'object', 'description' => 'Změny, např. {"barvy":{"primarni":"#0f766e"},"pismo_titulky":"klasicke","zaobleni":"l"} – klíče viz stavba_schema → design_system']])],
            ['seznam_kolekci', 'Kolekce webu (reference, tým, produkty…) s poli a počty položek. Na web je dostane prvek „kolekce“ (Výpis kolekce) ve stavbě; uvnitř se {{klic}} nahradí hodnotou položky ({{nazev}}, {{url}} = detail, {{datum}} a vlastní pole).', $s([])],
            ['vytvor_kolekci', 'Založí kolekci (správce). Pole: seznam {popisek, typ}; typ = ' . implode(' | ', array_keys(Kolekce::TYPY_POLI)) . '. Klíč pole vznikne z popisku.',
                $s(['nazev' => $text('Název, např. Reference'), 'pole' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{"popisek":"Citát","typ":"radky"},{"popisek":"Logo","typ":"obrazek"}]'],
                    'detail' => ['type' => 'boolean', 'description' => 'true = každá položka má vlastní stránku /<kolekce>/<položka>']], ['nazev'])],
            ['seznam_polozek_kolekce', 'Položky kolekce včetně hodnot polí.', $s(['kolekce' => $text('adresa (seo_link) kolekce')], ['kolekce'])],
            ['uloz_polozku_kolekce', 'Přidá položku do kolekce, nebo změní existující (s id). Bez "zobrazit": true zůstane skrytá.',
                $s(['kolekce' => $text('adresa (seo_link) kolekce'), 'id' => $cislo('ID položky – jen při úpravě'), 'nazev' => $text('Název položky'),
                    'data' => ['type' => 'object', 'description' => 'Hodnoty polí podle klíčů ze seznam_kolekci, např. {"citat":"…","logo":"media/…"}'],
                    'poradi' => $cislo('Pořadí, menší = dřív'), 'zobrazit' => ['type' => 'boolean', 'description' => 'true = položka je na webu (jen na pokyn uživatele)']], ['kolekce', 'nazev'])],
            ['seznam_novinek', 'Seznam novinek (nejnovější první).', $s(['stav' => $text('vse | vydane | plan | koncepty'), 'kategorie' => $text('název nebo adresa kategorie'), 'hledat' => $text('text v titulku'), 'limit' => $cislo('1-50, výchozí 20')])],
            ['nacti_novinku', 'Celá novinka včetně textu a štítků.', $s(['id' => $cislo('ID novinky (idc)')], ['id'])],
            ['vytvor_novinku', 'Založí novinku. Bez "vydat": true vznikne koncept.', $s($novinka, ['titulek', 'kategorie'])],
            ['uprav_novinku', 'Změní zadaná pole novinky; ostatní ponechá. Předchozí verze se uloží do historie.', $s(['id' => $cislo('ID novinky')] + $novinka, ['id'])],
            ['seznam_kategorii', 'Kategorie novinek s počty.', $s([])],
            ['vytvor_kategorii', 'Založí kategorii novinek (editor a správce).', $s(['nazev' => $text('Název'), 'popis' => $text('Popis (HTML)')], ['nazev'])],
            ['seznam_medii', 'Naposledy nahrané obrázky s adresami a rozměry.', $s(['limit' => $cislo('1-50, výchozí 20')])],
            ['seznam_sablon', 'Šablony webu (layouty), která je aktivní a které jdou upravovat (správce).', $s([])],
            ['vytvor_sablonu', 'Zkopíruje existující šablonu pod novým názvem, aby se dala upravovat (správce).', $s(['nazev' => $text('složka nové šablony: malá písmena, číslice, pomlčky'), 'podle' => $text('zdrojová šablona, výchozí ' . Layouty::VYCHOZI), 'popisny_nazev' => $text('název pro výběr ve Vzhledu')], ['nazev'])],
            ['nacti_soubor_sablony', 'Přečte soubor šablony (base.php, novinka.php, vypis.php, stranka.php, style.css, info.php…).', $s(['sablona' => $text('složka šablony'), 'soubor' => $text('název souboru; bez něj vrátí seznam souborů')], ['sablona'])],
            ['uloz_soubor_sablony', 'Uloží soubor vlastní šablony (.php nebo .css). PHP se před uložením kontroluje. Vestavěné šablony upravit nejde.', $s(['sablona' => $text('složka šablony'), 'soubor' => $text('název souboru'), 'obsah' => $text('celý nový obsah souboru')], ['sablona', 'soubor', 'obsah'])],
            ['aktivuj_sablonu', 'Přepne web na danou šablonu (správce). Před tím ji ukaž uživateli v náhledu: adresa webu s ?sablona=<složka> funguje přihlášenému správci.', $s(['sablona' => $text('složka šablony')], ['sablona'])],
        ];

        return array_map(fn (array $n): array => ['name' => $n[0], 'description' => $n[1], 'inputSchema' => $n[2]], $nastroje);
    }

    public function meni(string $nazev): bool
    {
        return in_array($nazev, ['vytvor_kolekci', 'uloz_polozku_kolekce', 'stavba_z_html', 'stavba_uloz', 'vloz_sekci', 'publikuj_stavbu', 'uprav_design_system', 'vytvor_stranku', 'uprav_stranku', 'vytvor_novinku', 'uprav_novinku', 'vytvor_kategorii', 'uloz_menu', 'vytvor_sablonu', 'uloz_soubor_sablony', 'aktivuj_sablonu'], true);
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

        switch ($nazev) {
            case 'info_o_webu':
                return [
                    'web' => $web->get('nazev_webu'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $web->get('popis_webu'),
                    'sablona' => $web->get('layout'), 'uvodni_stranka' => $web->int('titulni_stranka') ?: null, 'verze_mirocms' => MIROCMS_VERSION,
                    'stranek' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NULL'),
                    'novinek_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL'),
                    'uzivatel' => $auth->user()['user'], 'role' => \MiroCMS\Core\Auth::TYPY[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->smiVydavat(),
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
                $umisteni = isset(\MiroCMS\Core\Menu::UMISTENI[$a['umisteni'] ?? '']) ? $a['umisteni'] : 'hlavni';
                $jazykMenu = in_array($a['jazyk'] ?? '', Jazyk::dalsi($web), true) ? $a['jazyk'] : '';
                if ($nazev === 'uloz_menu') {
                    if (!$auth->isAdmin()) {
                        throw new \DomainException('Menu smí upravovat jen správce.');
                    }
                    \MiroCMS\Core\Menu::uloz($db, $umisteni, $jazykMenu, is_array($a['polozky'] ?? null) ? $a['polozky'] : null);
                    \MiroCMS\Front\Cache::vymaz();
                }
                $ulozene = \MiroCMS\Core\Menu::nacti($db, $umisteni, $jazykMenu);

                return ['umisteni' => $umisteni, 'jazyk' => $jazykMenu, 'automaticke' => $ulozene === null, 'polozky' => $ulozene ?? [],
                    'na_webu' => \MiroCMS\Core\Menu::polozky($this->app, $umisteni, $jazykMenu, $web->int('titulni_stranka')),
                    'stranky' => $db->all('SELECT ids, titulek, zobrazit FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, titulek', [$jazykMenu])];

            case 'stavba_schema':
                return Stavba::schema($auth->isAdmin(), Jazyk::vychozi($web), $auth->isAdmin()) + [
                    'komponenty' => array_map(fn (array $k): array => ['id' => (string) $k['idm'], 'nazev' => $k['nazev'], 'vlastnosti' => $k['vlastnosti']], \MiroCMS\Stavitel\Komponenty::vsechny($db))
                        + ['pozn' => 'Použití: {"typ":"komponenta","obsah":{"komponenta":"<id>","hodnoty":{"<klic>":"hodnota"}}}; prázdná hodnota = výchozí.'],
                    'casti_webu' => array_map(fn (array $t): string => $t[0] . ' – ' . $t[1], Casti::TYPY) + ['pozn' => 'Prvky ze skupiny „Části webu“ (logo, navigace, udaje, obsah) patří jen do částí; obálka (novinka, vypis, nenalezeno) musí obsahovat právě jeden prvek „obsah“.'],
                    'knihovna' => Knihovna::seznam(),
                    'tridy_webu' => array_column($db->all('SELECT nazev FROM {tridy} ORDER BY nazev'), 'nazev'),
                    'design_system' => DesignSystem::nacti($web) + ['predvolby' => array_map(fn (array $p): string => $p[0] . ' – ' . $p[1], DesignSystem::PREDVOLBY),
                        'pisma_titulku' => array_keys(Identita::PISMA_TITULKU), 'pisma_textu' => array_keys(Identita::PISMA_TEXTU)],
                    'css_tokeny' => 'V <style> a vlastním CSS používej var(--mc-barva-primarni|sekundarni|text|tlumeny|pozadi|plocha|linka|primarni-jemna|na-primarni), var(--mc-mezera-2xs…3xl), var(--mc-krok--1…5) pro velikost písma, var(--mc-zaobleni), var(--mc-stin-s|m|l), var(--mc-sirka).',
                ];

            case 'stavba_nacti':
                $cil = $this->cilStavby($a);

                return $this->popisCile($cil) + ['publikovana' => $cil['stavba'] !== null,
                    'neulozene_zmeny' => $cil['koncept'] !== null && $cil['koncept'] !== $cil['stavba'], 'stavba' => $this->stavbaCile($cil)];

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
                $this->publikujCil($cil);

                return $this->popisCile($cil) + ['stav' => 'publikováno', 'adresa' => $this->adresaCile($cil)];

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
                \MiroCMS\Front\Cache::vymaz();

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
                $seo = slugify($nazevKolekce, 110);
                if (in_array($seo, Stranky::VYHRAZENE, true) || Kolekce::podleSeo($db, $seo) !== null) {
                    throw new \InvalidArgumentException('Adresu „' . $seo . '“ už používá systém nebo jiná kolekce.');
                }
                $pole = Kolekce::vycistiPole($a['pole'] ?? []);
                $db->insert('kolekce', ['nazev' => $nazevKolekce, 'seo_link' => $seo, 'detail' => empty($a['detail']) ? 0 : 1, 'pole' => (string) json_encode($pole, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]);

                return ['kolekce' => $seo, 'pole' => $pole];

            case 'seznam_polozek_kolekce':
                $kolekce = $this->kolekce((string) ($a['kolekce'] ?? ''));

                return array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'nazev' => $r['nazev'], 'seo_link' => $r['seo_link'], 'poradi' => (int) $r['poradi'], 'zobrazit' => (bool) $r['zobrazit'],
                    'jazyk' => $r['jazyk'], 'data' => json_decode((string) $r['data'], true) ?: new \stdClass()],
                    $db->all('SELECT * FROM {kolekce_polozky} WHERE idk = ? ORDER BY poradi, nazev', [$kolekce['idk']]));

            case 'uloz_polozku_kolekce':
                if (!$auth->maModul('kolekce')) {
                    throw new \DomainException('Kolekce smí upravovat editor nebo správce.');
                }
                $kolekce = $this->kolekce((string) ($a['kolekce'] ?? ''));
                $nazevPolozky = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 200);
                if ($nazevPolozky === '') {
                    throw new \InvalidArgumentException('Položka musí mít název.');
                }
                $puvodni = isset($a['id']) ? $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ? AND idk = ?', [(int) $a['id'], $kolekce['idk']]) : null;
                if (isset($a['id']) && $puvodni === null) {
                    throw new \InvalidArgumentException('Položka v kolekci není. Použij seznam_polozek_kolekce.');
                }
                $chyby = [];
                $data = Kolekce::vycistiData($kolekce['pole'], (is_array($a['data'] ?? null) ? $a['data'] : []) + (json_decode((string) ($puvodni['data'] ?? '{}'), true) ?: []), $chyby);
                $seo = $puvodni['seo_link'] ?? slugify($nazevPolozky, 150);
                for ($i = 2, $zaklad = $seo; $puvodni === null && $db->value('SELECT idp FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ?', [$kolekce['idk'], $seo]) !== null; $i++) {
                    $seo = $zaklad . '-' . $i;
                }
                $radek = ['nazev' => $nazevPolozky, 'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'zmeneno' => date('Y-m-d H:i:s')]
                    + (array_key_exists('poradi', $a) ? ['poradi' => max(-9999, min(9999, (int) $a['poradi']))] : [])
                    + (array_key_exists('zobrazit', $a) ? ['zobrazit' => (int) (bool) $a['zobrazit']] : []);
                if ($puvodni !== null) {
                    $db->update('kolekce_polozky', $radek, ['idp' => $puvodni['idp']]);
                    $idp = (int) $puvodni['idp'];
                } else {
                    $idp = $db->insert('kolekce_polozky', $radek + ['idk' => $kolekce['idk'], 'seo_link' => $seo, 'datum' => date('Y-m-d H:i:s'), 'zobrazit' => 0]);
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

                return ['id' => $db->insert('kategorie', ['nazev' => $jmeno, 'seo_link' => $seo, 'popis' => (string) ($a['popis'] ?? '')]), 'adresa' => $seo];

            case 'seznam_medii':
                return array_map(fn (array $o): array => ['id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'adresa' => $this->app->url($o['obr_poloha']), 'rozmery' => $o['obr_width'] . '×' . $o['obr_height']],
                    $db->all('SELECT * FROM {media} ORDER BY ido DESC LIMIT ?', [max(1, min(50, (int) ($a['limit'] ?? 20)))]));

            case 'seznam_sablon':
                $jenAdmin();

                return array_map(fn (string $slozka, array $l): array => ['sablona' => $slozka, 'nazev' => $l['nazev'], 'popis' => $l['popis'], 'aktivni' => $slozka === $web->get('layout'),
                    'lze_upravovat' => !in_array($slozka, self::VESTAVENE_SABLONY, true)], array_keys(Layouty::seznam()), Layouty::seznam());

            case 'vytvor_sablonu':
                $jenAdmin();
                $nova = (string) ($a['nazev'] ?? '');
                $podle = (string) ($a['podle'] ?? Layouty::VYCHOZI);
                if (!preg_match('/^[a-z][a-z0-9-]{2,40}$/', $nova) || is_dir(MIROCMS_ROOT . '/layout/' . $nova)) {
                    throw new \InvalidArgumentException('Název šablony: 3-40 znaků, malá písmena, číslice a pomlčky; složka ještě nesmí existovat.');
                }
                if (!isset(Layouty::seznam()[$podle])) {
                    throw new \InvalidArgumentException('Zdrojová šablona neexistuje.');
                }
                mkdir(MIROCMS_ROOT . '/layout/' . $nova, 0775);
                foreach (glob(MIROCMS_ROOT . '/layout/' . $podle . '/*.{php,css}', GLOB_BRACE) ?: [] as $soubor) {
                    $obsah = (string) file_get_contents($soubor);
                    // zkopírovaná šablona musí odkazovat na vlastní style.css
                    file_put_contents(MIROCMS_ROOT . '/layout/' . $nova . '/' . basename($soubor), str_replace("layout/{$podle}/", "layout/{$nova}/", $obsah));
                }
                file_put_contents(MIROCMS_ROOT . '/layout/' . $nova . '/info.php', "<?php\n\nreturn " . var_export([
                    'nazev' => mb_substr((string) ($a['popisny_nazev'] ?? $nova), 0, 60), 'popis' => 'Vlastní šablona (vychází z ' . $podle . ').',
                ], true) . ";\n");

                return ['sablona' => $nova, 'soubory' => $this->souborySablony($nova), 'nahled' => $this->app->request->origin() . $this->app->url('?sablona=' . $nova)];

            case 'nacti_soubor_sablony':
                $jenAdmin();
                $slozka = $this->sablona((string) ($a['sablona'] ?? ''));
                if (empty($a['soubor'])) {
                    return ['soubory' => $this->souborySablony($slozka)];
                }

                return (string) file_get_contents($this->souborSablony($slozka, (string) $a['soubor'], true));

            case 'uloz_soubor_sablony':
                $jenAdmin();
                $slozka = $this->sablona((string) ($a['sablona'] ?? ''));
                if (in_array($slozka, self::VESTAVENE_SABLONY, true)) {
                    throw new \DomainException('Vestavěnou šablonu by přepsala aktualizace systému. Nejprve ji zkopíruj nástrojem vytvor_sablonu a upravuj kopii.');
                }
                $cesta = $this->souborSablony($slozka, (string) ($a['soubor'] ?? ''), false);
                $obsah = (string) ($a['obsah'] ?? '');
                if (strlen($obsah) > 300 * 1024) {
                    throw new \InvalidArgumentException('Soubor je větší než 300 kB.');
                }
                if (str_ends_with($cesta, '.php')) {
                    // šablona je jen prezentační vrstva: nesmí na soubory, databázi, síť ani na kód systému (Core\SablonaKontrola)
                    $vady = \MiroCMS\Core\SablonaKontrola::over($obsah);
                    if ($vady !== []) {
                        throw new \InvalidArgumentException("Soubor se neuložil – šablona smí jen vypisovat data, která dostává:\n- " . implode("\n- ", array_slice($vady, 0, 12))
                            . "\nPovolené: výpis, if/foreach/match, uzávěry, \$web->get(), \$url(), e(), t(), datum() a běžné funkce pro text, čísla a pole. Pravidla: layout/CLAUDE.md.");
                    }
                } elseif (preg_match('#expression\s*\(|behavior\s*:#i', $obsah)) {
                    throw new \InvalidArgumentException('Styl obsahuje zastaralé spustitelné konstrukce (expression, behavior). Nic se neuložilo.');
                }
                file_put_contents($cesta, $obsah, LOCK_EX);

                return ['ulozeno' => basename($cesta), 'velikost' => strlen($obsah), 'nahled' => $this->app->request->origin() . $this->app->url('?sablona=' . $slozka)];

            case 'aktivuj_sablonu':
                $jenAdmin();
                $slozka = $this->sablona((string) ($a['sablona'] ?? ''));
                $web->set('layout', $slozka);

                return 'Web nyní používá šablonu ' . $slozka . '.';
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
        \MiroCMS\Core\Hledani::indexuj($db, $id);
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
        foreach (['v_menu', 'zobrazit', 'noindex'] as $pole) {
            if (array_key_exists($pole, $a)) {
                $data[$pole] = (int) (bool) $a[$pole];
            }
        }
        if (array_key_exists('poradi', $a)) {
            $data['poradi'] = max(0, min(65535, (int) $a['poradi']));
        }
        if (($data['titulek'] ?? $puvodni['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Stránka musí mít název.');
        }
        if (array_key_exists('adresa', $a) || $puvodni === null) {
            $seo = slugify((string) (($a['adresa'] ?? '') !== '' ? $a['adresa'] : $data['titulek']), 110);
            if (in_array($seo, Stranky::VYHRAZENE, true) || isset(\MiroCMS\Core\Jazyk::DOSTUPNE[$seo])) {
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
                \MiroCMS\Core\Menu::nastavStranku($db, $id, '', true);
            }
        } else {
            $id = (int) $puvodni['ids'];
            $db->update('stranky', $data, ['ids' => $id]);
            if (isset($data['seo_link']) && $data['seo_link'] !== $puvodni['seo_link'] && $puvodni['zobrazit']) {
                \MiroCMS\Admin\Moduly\Presmerovani::pridej($db, $puvodni['seo_link'], $data['seo_link']);
            }
        }
        $ulozena = $this->stranka($id);

        return ['id' => $id, 'stav' => $ulozena['zobrazit'] ? 'zveřejněná' : 'skrytá',
            'adresa' => $this->app->request->origin() . $this->app->url($ulozena['seo_link']),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?modul=stranky&akce=edit&id=' . $id)];
    }

    /** @return array<string, mixed> */
    private function stranka(int $id): array
    {
        $stranka = $this->app->db()->one('SELECT ids, titulek, seo_link, popis, seo_titulek, obrazek, noindex, text, zobrazit, v_menu, poradi, jazyk FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);
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
        if (isset($a['cast']) && $a['cast'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Části webu (záhlaví, patičku, obálky) smí měnit jen správce webu.');
            }
            $typ = (string) $a['cast'];
            if (!isset(Casti::TYPY[$typ])) {
                throw new \InvalidArgumentException('Neznámá část webu. Typy: ' . implode(', ', array_keys(Casti::TYPY)) . '.');
            }
            $jazyk = in_array($a['jazyk'] ?? '', Jazyk::dalsi($web), true) ? (string) $a['jazyk'] : '';
            if (Casti::radek($db, $typ, $jazyk) === null) {
                $db->insert('casti', ['typ' => $typ, 'jazyk' => $jazyk, 'stavba_koncept' => Stavba::naJson(Casti::vychozi($typ, Jazyk::obsahu($web, $jazyk))), 'zmeneno' => date('Y-m-d H:i:s')]);
            }
            $radek = (array) Casti::radek($db, $typ, $jazyk);

            return ['druh' => 'cast', 'radek' => $radek, 'stavba' => $radek['stavba'], 'koncept' => $radek['stavba_koncept'], 'jazyk' => Jazyk::obsahu($web, $jazyk)];
        }
        if (!$auth->maModul('stranky')) {
            throw new \DomainException('Stránky smí upravovat editor nebo správce.');
        }
        if (!isset($a['id']) && $zalozit) {
            $a['id'] = $this->ulozStranku(null, ['titulek' => (string) ($a['titulek'] ?? '')])['id'];
        }
        $radek = $db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [(int) ($a['id'] ?? 0)]) ?? throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');

        return ['druh' => 'stranka', 'radek' => $radek, 'stavba' => $radek['stavba'], 'koncept' => $radek['stavba_koncept'], 'jazyk' => Jazyk::obsahu($web, $radek['jazyk'])];
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
        return $cil['druh'] === 'stranka'
            ? ['id' => (int) $cil['radek']['ids'], 'titulek' => $cil['radek']['titulek']]
            : ['cast' => $cil['radek']['typ'], 'jazyk' => $cil['radek']['jazyk'], 'titulek' => Casti::TYPY[$cil['radek']['typ']][0]];
    }

    private function publikujCil(array $cil): void
    {
        $db = $this->app->db();
        if ($cil['druh'] === 'stranka') {
            Publikace::stranka($this->app, (array) $db->one('SELECT * FROM {stranky} WHERE ids = ?', [$cil['radek']['ids']]));
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
        } else {
            $db->update('casti', ['stavba_koncept' => Stavba::naJson($stavba)], ['typ' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta']]);
        }
        if ($publikovat) {
            $this->publikujCil($cil);
        }
        $adresa = $this->adresaCile($cil);
        $parametry = $cil['druh'] === 'stranka' ? 'modul=stranky&akce=stavitel&id=' . (int) $r['ids'] : 'modul=casti&akce=stavitel&typ=' . $r['typ'] . '&jazyk=' . $r['jazyk'];

        return $this->popisCile($cil) + ['stav' => $publikovat ? 'publikováno' : 'koncept – na webu se ukáže po publikování', 'prvku' => $this->pocetPrvku($stavba['deti']),
            'chyby' => $chyby, 'nahled' => $publikovat ? $adresa : $adresa . ($cil['druh'] === 'stranka' ? '?stavba=koncept' : '?cast=' . $r['typ'] . '&stavba=koncept'),
            'stavitel' => $this->app->request->origin() . $this->app->url('admin.php?' . $parametry)];
    }

    /** Veřejná adresa, na které je cíl vidět (u části webu úvodní stránka, detail novinky, výpis, 404). */
    private function adresaCile(array $cil): string
    {
        $r = $cil['radek'];
        if ($cil['druh'] === 'stranka') {
            $uvod = $this->app->settings()->int('titulni_stranka') === (int) $r['ids'];
            $cesta = $uvod ? '' : $r['seo_link'];
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


    /** @return array<string, mixed> */
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

    private function sablona(string $slozka): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]{2,40}$/', $slozka) || !isset(Layouty::seznam()[$slozka])) {
            throw new \InvalidArgumentException('Šablona neexistuje. Použij nástroj seznam_sablon.');
        }

        return $slozka;
    }

    /** @return list<string> */
    private function souborySablony(string $slozka): array
    {
        return array_map(basename(...), glob(MIROCMS_ROOT . '/layout/' . $slozka . '/*.{php,css}', GLOB_BRACE) ?: []);
    }

    private function souborSablony(string $slozka, string $soubor, bool $musiExistovat): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,40}\.(php|css)$/', $soubor)) {
            throw new \InvalidArgumentException('Název souboru: malá písmena, číslice, pomlčky a podtržítka, přípona .php nebo .css.');
        }
        $cesta = MIROCMS_ROOT . '/layout/' . $slozka . '/' . $soubor;
        if ($musiExistovat && !is_file($cesta)) {
            throw new \InvalidArgumentException('Soubor v šabloně není. Dostupné: ' . implode(', ', $this->souborySablony($slozka)));
        }

        return $cesta;
    }
}
