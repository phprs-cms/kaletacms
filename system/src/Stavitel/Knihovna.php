<?php

declare(strict_types=1);

namespace Kaleta\Stavitel;

/**
 * Knihovna sekcí pro firemní web: hotové stavby z tokenů design systému a pár sdílených tříd, takže po vložení hned sedí
 * do barev a písem webu. Editor vkládá kopii (nová id); třídy, které sekce používá, se založí, když na webu ještě nejsou.
 */
final class Knihovna
{
    /** Sdílené třídy knihovny (název => styl). Založí se při prvním vložení sekce, která je používá; pak patří webu. */
    public const array TRIDY = [
        'karta' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's', 'odsazeni_y' => 'l', 'odsazeni_x' => 'l', 'pozadi' => 'plocha', 'zaobleni' => 'm']],
        'nadpis-sekce' => ['zaklad' => ['max_radek' => 'var(--ka-sirka-textu)', 'okraj_dole' => 'l']],
        'podtitul' => ['zaklad' => ['velikost_pisma' => '1', 'barva' => 'tlumeny', 'max_radek' => 'var(--ka-sirka-textu)']],
    ];

    /** @return array<string, array{nazev:string, popis:string, stavba:callable(): array}> */
    private static function sekce(): array
    {
        $n = Stavba::novy(...);
        $s = fn (array $p, array $styl): array => ['styl' => $styl] + $p;       // prvek s vlastním stylem
        $t = fn (array $p, string ...$tridy): array => ['tridy' => $tridy] + $p; // prvek s třídami
        $z = fn (array $p, string $znacka): array => ['znacka' => $znacka] + $p;
        $adresa = fn (string $stranka): string => '/' . slugify(t($stranka)); // stránky zakládá instalátor pod přeloženým názvem
        $radaTlacitek = fn (array ...$tlacitka): array => $s($n('kontejner', [], $tlacitka), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'zalamovani' => 'wrap', 'mezera' => 's']]);

        return [
            'uvod' => ['nazev' => t('Úvodní pás'), 'popis' => t('Velký nadpis, podtitul a dvě tlačítka.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($z($n('nadpis', ['text' => t('Pomáháme firmám růst – rychle a bez starostí')]), 'h1'), ['zaklad' => ['velikost_pisma' => '5', 'max_radek' => '20ch']]),
                $t($n('text', ['html' => '<p>' . t('Jednou nebo dvěma větami řekněte, co děláte, pro koho a proč právě vy.') . '</p>']), 'podtitul'),
                $radaTlacitek($n('tlacitko', ['text' => t('Nezávazná poptávka'), 'odkaz' => $adresa('Kontakt')]), $n('tlacitko', ['text' => t('Naše služby'), 'odkaz' => $adresa('Služby'), 'varianta' => 'obrys'])),
            ]), ['zaklad' => ['odsazeni_y' => '3xl'], 'mobil' => ['odsazeni_y' => '2xl']])],

            'uvod-obrazek' => ['nazev' => t('Úvod s obrázkem'), 'popis' => t('Text a tlačítka vlevo, obrázek vpravo; na telefonu pod sebou.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], [
                    $n('kontejner', [], [
                        $s($z($n('nadpis', ['text' => t('Řemeslo, na které se můžete spolehnout')]), 'h1'), ['zaklad' => ['velikost_pisma' => '4']]),
                        $t($n('text', ['html' => '<p>' . t('Popište hlavní přínos pro zákazníka. Krátce, konkrétně a jeho slovy.') . '</p>']), 'podtitul'),
                        $radaTlacitek($n('tlacitko', ['text' => t('Kontaktujte nás'), 'odkaz' => $adresa('Kontakt')])),
                    ]),
                    $s($n('obrazek', ['alt' => '', 'priorita' => true]), ['zaklad' => ['sirka' => '100%', 'zaobleni' => 'l', 'pomer_stran' => '4/3', 'prizpusobeni' => 'cover']]),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'xl']]),
            ]), ['zaklad' => ['odsazeni_y' => '2xl']])],

            'vyhody' => ['nazev' => t('Výhody'), 'popis' => t('Nadpis a tři karty s hlavními důvody, proč si vybrat vás.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Proč právě my')]), 'nadpis-sekce'),
                $n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', [], [$z($n('nadpis', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]), 'karta'), [
                    [t('Zkušenosti'), t('Za patnáct let jsme dokončili stovky zakázek po celé republice.')],
                    [t('Férová cena'), t('Cenu znáte předem a platíte jen za to, co je opravdu hotové.')],
                    [t('Rychlost'), t('Na poptávku odpovídáme do jednoho pracovního dne.')],
                ])),
            ])],

            'sluzby' => ['nazev' => t('Služby s obrázky'), 'popis' => t('Karty služeb s obrázkem, popisem a odkazem.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Co pro vás uděláme')]), 'nadpis-sekce'),
                $n('mrizka', [], array_map(fn (string $nazev): array => $t($n('kontejner', [], [
                    $s($n('obrazek', ['alt' => $nazev]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '3/2', 'prizpusobeni' => 'cover', 'zaobleni' => 's']]),
                    $z($n('nadpis', ['text' => $nazev]), 'h3'),
                    $n('text', ['html' => '<p>' . t('Krátký popis služby a pro koho je.') . '</p>']),
                    $n('tlacitko', ['text' => t('Více informací'), 'varianta' => 'odkaz', 'odkaz' => $adresa('Služby')]),
                ]), 'karta'), [t('Návrh'), t('Realizace'), t('Servis')])),
            ])],

            'cisla' => ['nazev' => t('Čísla'), 'popis' => t('Pás se čtyřmi výraznými čísly v hlavní barvě.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '4', 'tloustka_pisma' => '800']]),
                    $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs', 'zarovnani_textu' => 'center']]), [['15+', t('let na trhu')], [t('1 200'), t('hotových zakázek')], [t('98 %'), t('spokojených zákazníků')], ['24 h', t('na odpověď')]])),
                    ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2']]),
            ]), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'primarni', 'barva' => 'na-primarni']])],

            'reference' => ['nazev' => t('Reference'), 'popis' => t('Co o vás říkají zákazníci – citáty se jménem.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Co říkají naši zákazníci')]), 'nadpis-sekce'),
                $s($n('mrizka', [], [
                    $t($n('citat', ['text' => t('Vše proběhlo přesně podle domluvy, včas a v rozpočtu. Rádi se vrátíme.'), 'autor' => t('Petr Svoboda'), 'pozice' => t('jednatel, Svoboda s.r.o.')]), 'karta'),
                    $t($n('citat', ['text' => t('Oceňujeme rychlou komunikaci a to, že nám vždy poradili to nejlepší řešení.'), 'autor' => t('Lucie Dvořáková'), 'pozice' => t('provozní ředitelka')]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:20rem', 'mezera' => 'l']]),
            ])],

            'faq' => ['nazev' => t('Otázky a odpovědi'), 'popis' => t('Nejčastější dotazy zákazníků – i jako strukturovaná data pro vyhledávače.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $n('nadpis', ['text' => t('Časté otázky')]),
                $n('faq'),
            ])],

            'vyzva' => ['nazev' => t('Výzva k akci'), 'popis' => t('Barevný box s nadpisem, větou a tlačítkem.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], [
                    $z($n('nadpis', ['text' => t('Máte projekt? Pojďme ho probrat.')]), 'h2'),
                    $n('text', ['html' => '<p>' . t('Ozvěte se nám – do 24 hodin se vám ozveme s návrhem dalšího postupu.') . '</p>']),
                    $n('tlacitko', ['text' => t('Napište nám'), 'odkaz' => $adresa('Kontakt'), 'varianta' => 'sekundarni']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'zarovnani' => 'center', 'mezera' => 's', 'zarovnani_textu' => 'center',
                    'odsazeni_y' => '2xl', 'odsazeni_x' => 'l', 'pozadi' => 'primarni', 'barva' => 'na-primarni', 'zaobleni' => 'l']]),
            ])],

            'novinky' => ['nazev' => t('Poslední novinky'), 'popis' => t('Tři nejnovější novinky a odkaz na všechny.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], [$z($n('nadpis', ['text' => t('Novinky')]), 'h2'), $n('tlacitko', ['text' => t('Všechny novinky'), 'odkaz' => '/novinky', 'varianta' => 'odkaz'])]),
                    ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'baseline', 'zalamovani' => 'wrap', 'mezera' => 's']]),
                $n('novinky'),
            ])],

            'poptavka' => ['nazev' => t('Poptávkový formulář'), 'popis' => t('Nadpis, věta a formulář – zprávy chodí do Poptávek a e-mailem.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $z($n('nadpis', ['text' => t('Napište nám')]), 'h2'),
                $t($n('text', ['html' => '<p>' . t('Popište, co potřebujete – ozveme se vám do jednoho pracovního dne.') . '</p>']), 'podtitul'),
                $s($n('formular'), ['zaklad' => ['okraj_nahore' => 'm']]),
            ])],

            'kontakt' => ['nazev' => t('Kontakt'), 'popis' => t('Adresa a kontakty vlevo, otevírací doba vpravo.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    // údaje z Nastavení → Firma: vyplní se jednou a platí i pro patičku a vyhledávače
                    $s($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Kontaktní údaje')]), 'h2'),
                        $n('udaje', ['udaj' => 'firma']),
                        $z($n('udaje', ['udaj' => 'adresa']), 'address'),
                        $n('udaje', ['udaj' => 'telefon']),
                        $n('udaje', ['udaj' => 'email']),
                        $n('udaje', ['udaj' => 'mapa']),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs']]),
                    $t($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Otevírací doba')]), 'h3'),
                        $n('udaje', ['udaj' => 'hodiny']),
                    ]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'xl'], 'tablet' => ['sloupce' => '1']]),
            ])],

            /* ---------- úvod ---------- */

            'uvod-stred' => ['nazev' => t('Úvod na střed'), 'popis' => t('Krátký štítek, velký nadpis a tlačítko – vše na středu.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => t('Nové: servis do 48 hodin')]), 'p'), ['zaklad' => ['velikost_pisma' => '-1', 'tloustka_pisma' => '600', 'barva' => 'primarni', 'velka_pismena' => 'uppercase', 'proklad' => '0.06em']]),
                    $s($z($n('nadpis', ['text' => t('Postaráme se o vše od návrhu po předání')]), 'h1'), ['zaklad' => ['velikost_pisma' => '5', 'max_radek' => '20ch']]),
                    $t($n('text', ['html' => '<p>' . t('Jedna firma, jeden kontakt, jasná cena. Vy se věnujete svému, my ostatnímu.') . '</p>']), 'podtitul'),
                    $n('tlacitko', ['text' => t('Domluvit schůzku'), 'odkaz' => $adresa('Kontakt')]),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'zarovnani' => 'center', 'zarovnani_textu' => 'center', 'mezera' => 'm']]),
            ]), ['zaklad' => ['odsazeni_y' => '3xl'], 'mobil' => ['odsazeni_y' => '2xl']])],

            'uvod-tmavy' => ['nazev' => t('Úvod na tmavém pozadí'), 'popis' => t('Výrazný úvod s tmavým pozadím – obrázek pozadí nastavíte ve stylu sekce.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($z($n('nadpis', ['text' => t('Kvalita, která vydrží desítky let')]), 'h1'), ['zaklad' => ['velikost_pisma' => '5', 'max_radek' => '20ch', 'barva' => 'pozadi']]),
                $s($n('text', ['html' => '<p>' . t('Pracujeme s poctivými materiály a na každou zakázku dáváme záruku.') . '</p>']), ['zaklad' => ['velikost_pisma' => '1', 'max_radek' => 'var(--ka-sirka-textu)', 'barva' => 'pozadi']]),
                $radaTlacitek($n('tlacitko', ['text' => t('Nezávazná poptávka'), 'odkaz' => $adresa('Kontakt')])),
            ]), ['zaklad' => ['odsazeni_y' => '3xl', 'pozadi' => 'text', 'zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm'], 'mobil' => ['odsazeni_y' => '2xl']])],

            'uvod-video' => ['nazev' => t('Úvod s videem'), 'popis' => t('Nadpis a text vlevo, video vpravo (YouTube nebo Vimeo).'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $s($z($n('nadpis', ['text' => t('Podívejte se, jak pracujeme')]), 'h1'), ['zaklad' => ['velikost_pisma' => '4']]),
                        $t($n('text', ['html' => '<p>' . t('Dvouminutové video řekne víc než dlouhý text. Vložte adresu videa v panelu Obsah.') . '</p>']), 'podtitul'),
                        $radaTlacitek($n('tlacitko', ['text' => t('Kontaktujte nás'), 'odkaz' => $adresa('Kontakt')])),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]),
                    $n('video', ['url' => '']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1']]),
            ]), ['zaklad' => ['odsazeni_y' => '2xl']])],

            'nadpis-stranky' => ['nazev' => t('Nadpis stránky'), 'popis' => t('Nadpis a úvodní věta na začátek podstránky.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($z($n('nadpis', ['text' => t('Nadpis stránky')]), 'h1'), ['zaklad' => ['velikost_pisma' => '4']]),
                $t($n('text', ['html' => '<p>' . t('Jednou větou, o čem stránka je.') . '</p>']), 'podtitul'),
            ]), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'plocha', 'zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']])],

            /* ---------- služby a obsah ---------- */

            'vyhody-seznam' => ['nazev' => t('Výhody se seznamem'), 'popis' => t('Text vlevo, seznam s fajfkami vpravo.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $n('nadpis', ['text' => t('Co u nás dostanete')]),
                        $t($n('text', ['html' => '<p>' . t('Víc než jen hotovou práci. Staráme se, aby spolupráce byla příjemná od první schůzky.') . '</p>']), 'podtitul'),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                    $n('seznam', ['polozky' => implode("\n", [t('Cenu znáte předem'), t('Pevný termín dokončení'), t('Záruka na veškerou práci'), t('Úklid po sobě je samozřejmost')]), 'styl' => 'fajfky']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'strida' => ['nazev' => t('Obrázek a text střídavě'), 'popis' => t('Dva řádky: obrázek s textem, podruhé obráceně.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], array_map(fn (array $d): array => $s($n('mrizka', [], [
                    $s($n('obrazek', ['alt' => $d[0]]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '4/3', 'prizpusobeni' => 'cover', 'zaobleni' => 'l'] + ($d[2] ? ['poradi' => '2'] : []), 'tablet' => ['poradi' => '0']]),
                    $s($n('kontejner', [], [$n('nadpis', ['text' => $d[0]]), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]), [
                    [t('Návrh přesně pro vás'), t('Zaměříme prostor, probereme vaše zvyky a připravíme návrh, který si prohlédnete dřív, než začneme.'), false],
                    [t('Poctivé provedení'), t('Pracujeme s ověřenými materiály a každý detail kontrolujeme. Na výsledek dáváme záruku.'), true],
                ])), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xl']]),
            ])],

            'proces' => ['nazev' => t('Jak to probíhá'), 'popis' => t('Čtyři očíslované kroky spolupráce.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Jak to probíhá')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '3', 'tloustka_pisma' => '800', 'barva' => 'primarni']]),
                    $z($n('nadpis', ['text' => $d[1]]), 'h3'),
                    $n('text', ['html' => '<p>' . $d[2] . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs', 'linka_nahore' => '2px solid var(--ka-barva-primarni)', 'odsazeni_y' => 's']]), [
                    ['01', t('Poptávka'), t('Napíšete nám, co potřebujete.')], ['02', t('Návrh a cena'), t('Do týdne dostanete návrh a pevnou cenu.')],
                    ['03', t('Realizace'), t('Pracujeme v domluveném termínu.')], ['04', t('Předání'), t('Vše projdeme společně a předáme.')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2']]),
            ])],

            'sluzby-seznam' => ['nazev' => t('Služby s cenami'), 'popis' => t('Seznam služeb s krátkým popisem a cenou „od“.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $t($n('nadpis', ['text' => t('Služby a ceny')]), 'nadpis-sekce'),
                $s($n('kontejner', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($n('kontejner', [], [$z($n('nadpis', ['text' => $d[0]]), 'h3'), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul')]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs']]),
                    $s($z($n('nadpis', ['text' => $d[2]]), 'p'), ['zaklad' => ['tloustka_pisma' => '700', 'velikost_pisma' => '1']]),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'baseline', 'mezera' => 'm', 'odsazeni_y' => 'm', 'linka_dole' => '1px solid var(--ka-barva-linka)'], 'mobil' => ['smer' => 'column', 'mezera' => 'xs']]), [
                    [t('Konzultace'), t('Hodina s odborníkem u vás nebo online.'), t('od 900 Kč')],
                    [t('Návrh na míru'), t('Návrh včetně vizualizace a rozpočtu.'), t('od 4 500 Kč')],
                    [t('Realizace'), t('Kompletní provedení podle návrhu.'), t('dle rozsahu')],
                ])), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column']]),
            ])],

            'cenik' => ['nazev' => t('Ceník – tři balíčky'), 'popis' => t('Tři karty s cenou a výčtem, prostřední zvýrazněná.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Vyberte si balíček')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($t($n('kontejner', [], [
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'),
                    $s($z($n('nadpis', ['text' => $d[1]]), 'p'), ['zaklad' => ['velikost_pisma' => '3', 'tloustka_pisma' => '800']]),
                    $n('seznam', ['polozky' => $d[2], 'styl' => 'fajfky']),
                    $n('tlacitko', ['text' => t('Mám zájem'), 'odkaz' => $adresa('Kontakt'), 'varianta' => $d[3] ? 'primarni' : 'obrys']),
                ]), 'karta'), $d[3] ? ['zaklad' => ['ramecek' => '2px solid var(--ka-barva-primarni)', 'stin' => 'm']] : []), [
                    [t('Základ'), t('2 900 Kč'), t('Konzultace') . "\n" . t('Návrh řešení'), false],
                    [t('Standard'), t('6 900 Kč'), t('Konzultace') . "\n" . t('Návrh řešení') . "\n" . t('Realizace') . "\n" . t('Rok servisu zdarma'), true],
                    [t('Na míru'), t('dle dohody'), t('Vše ze Standardu') . "\n" . t('Vlastní termíny') . "\n" . t('Osobní správce zakázky'), false],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:16rem', 'mezera' => 'l', 'zarovnani' => 'stretch']]),
            ])],

            'karty-odkazy' => ['nazev' => t('Rozcestník'), 'popis' => t('Tři karty jako odkazy na podstránky.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', ['odkaz' => $d[2]], [
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                    $s($z($n('nadpis', ['text' => t('Více →')]), 'p'), ['zaklad' => ['barva' => 'primarni', 'tloustka_pisma' => '600']]),
                ]), 'karta'), [
                    [t('Služby'), t('Co všechno pro vás uděláme.'), $adresa('Služby')], [t('O nás'), t('Kdo jsme a jak pracujeme.'), $adresa('O nás')], [t('Kontakt'), t('Kde nás najdete a jak se spojit.'), $adresa('Kontakt')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'text-sloupce' => ['nazev' => t('Text ve dvou sloupcích'), 'popis' => t('Nadpis a delší text rozdělený do dvou sloupců.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Nadpis sekce')]), 'nadpis-sekce'),
                $s($n('mrizka', [], [
                    $n('text', ['html' => '<p>' . t('Sem napište první část textu. Dva sloupce se dobře čtou u delších popisů služeb nebo postupů.') . '</p>']),
                    $n('text', ['html' => '<p>' . t('A sem druhou. Na telefonu se sloupce samy seřadí pod sebe.') . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'xl'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'galerie' => ['nazev' => t('Galerie'), 'popis' => t('Mřížka šesti obrázků – ukázky práce, prostory, výrobky.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Ukázky naší práce')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (int $i): array => $s($n('obrazek', ['alt' => t('Ukázka %s', (string) $i)]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '1', 'prizpusobeni' => 'cover', 'zaobleni' => 'm']]), range(1, 6))),
                    ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 's'], 'mobil' => ['sloupce' => '2']]),
            ])],

            'portfolio' => ['nazev' => t('Realizace'), 'popis' => t('Karty hotových zakázek s obrázkem, názvem a místem.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Naše realizace')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($n('obrazek', ['alt' => $d[0]]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '4/3', 'prizpusobeni' => 'cover', 'zaobleni' => 'm']]),
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'),
                    $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs']]), [
                    [t('Rodinný dům'), t('Brno, 2026')], [t('Kancelář'), t('Praha, 2025')], [t('Rekonstrukce bytu'), t('Olomouc, 2025')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:18rem', 'mezera' => 'l']]),
            ])],

            'video' => ['nazev' => t('Video'), 'popis' => t('Nadpis a video přes celou šířku obsahu.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $t($n('nadpis', ['text' => t('Video')]), 'nadpis-sekce'),
                $n('video', ['url' => '']),
            ])],

            /* ---------- důvěra ---------- */

            'reference-jedna' => ['nazev' => t('Velký citát'), 'popis' => t('Jedna výrazná reference na středu.'), 'stavba' => fn (): array => $s($n('sekce', ['sirka' => 'uzka'], [
                $s($n('citat', ['text' => t('Nejlepší firma, se kterou jsme kdy spolupracovali. Termíny drželi na den a výsledek předčil naše očekávání.'), 'autor' => t('Martina Horáková'), 'pozice' => t('majitelka kavárny')]),
                    ['zaklad' => ['velikost_pisma' => '2', 'zarovnani_textu' => 'center']]),
            ]), ['zaklad' => ['odsazeni_y' => '2xl', 'pozadi' => 'primarni-jemna']])],

            'recenze' => ['nazev' => t('Hodnocení zákazníků'), 'popis' => t('Tři krátké recenze s hvězdičkami.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Hodnocení zákazníků')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => '★★★★★']), 'p'), ['zaklad' => ['barva' => 'primarni', 'proklad' => '0.12em']]),
                    $n('text', ['html' => '<p>' . $d[0] . '</p>']),
                    $s($z($n('nadpis', ['text' => $d[1]]), 'p'), ['zaklad' => ['tloustka_pisma' => '600', 'velikost_pisma' => '-1']]),
                ]), 'karta'), [
                    [t('Rychlé, čisté a za domluvenou cenu.'), t('Tomáš K.')], [t('Ochotní lidé, skvělá komunikace.'), t('Jana P.')], [t('Doporučuji všem známým.'), t('Pavel S.')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'loga' => ['nazev' => t('Loga klientů'), 'popis' => t('Řada log firem, se kterými spolupracujete.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($t($n('nadpis', ['text' => t('Spolupracujeme s')]), 'podtitul'), ['zaklad' => ['zarovnani_textu' => 'center', 'okraj_dole' => 'l']]),
                $s($n('mrizka', [], array_map(fn (int $i): array => $s($n('obrazek', ['alt' => t('Logo klienta %s', (string) $i)]), ['zaklad' => ['sirka' => '100%', 'vyska' => '3rem', 'prizpusobeni' => 'contain', 'pruhlednost' => '0.8']]), range(1, 6))),
                    ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '6', 'mezera' => 'l', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '3']]),
            ])],

            'cisla-svetla' => ['nazev' => t('Čísla na světlém pozadí'), 'popis' => t('Tři velká čísla s popiskem, bez barevného pásu.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '5', 'tloustka_pisma' => '800', 'barva' => 'primarni']]),
                    $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs']]), [
                    ['20+', t('let zkušeností')], ['500+', t('spokojených zákazníků')], [t('5 let'), t('záruka na práci')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'mobil' => ['sloupce' => '1']]),
            ])],

            'zaruky' => ['nazev' => t('Záruky'), 'popis' => t('Pruh se čtyřmi krátkými sliby – doprava, záruka, termín, platba.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '1', 'tloustka_pisma' => '700']]), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs']]), [
                    [t('Doprava zdarma'), t('V okruhu 50 km.')], [t('Záruka 5 let'), t('Na veškerou práci.')], [t('Pevný termín'), t('Nebo sleva za každý den.')], [t('Platba po předání'), t('Žádné velké zálohy.')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2']]),
            ]), ['zaklad' => ['odsazeni_y' => 'l', 'pozadi' => 'plocha']])],

            /* ---------- o firmě ---------- */

            'pribeh' => ['nazev' => t('Náš příběh'), 'popis' => t('Obrázek a text o tom, kdo jste a jak jste začínali.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('obrazek', ['alt' => t('Náš tým')]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '3/4', 'prizpusobeni' => 'cover', 'zaobleni' => 'l'], 'tablet' => ['pomer_stran' => '4/3']]),
                    $s($n('kontejner', [], [
                        $n('nadpis', ['text' => t('Začínali jsme v malé dílně')]),
                        $n('text', ['html' => '<p>' . t('Popište, jak firma vznikla a co vás žene dál. Lidé nakupují od lidí – příběh jim pomůže rozhodnout se.') . '</p><p>' . t('Doplňte, čím se lišíte od ostatních a na co jste hrdí.') . '</p>']),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2fr 3fr', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'tym' => ['nazev' => t('Tým'), 'popis' => t('Karty lidí s fotkou, jménem a rolí. Pro větší tým použijte kolekci.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Kdo pro vás pracuje')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($n('obrazek', ['alt' => $d[0]]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '1', 'prizpusobeni' => 'cover', 'zaobleni' => 'plne']]),
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'podtitul'),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs', 'zarovnani_textu' => 'center', 'zarovnani' => 'center']]), [
                    [t('Jan Novák'), t('majitel')], [t('Eva Nováková'), t('návrhy a zaměření')], [t('Petr Dvořák'), t('vedoucí realizace')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:12rem', 'mezera' => 'l']]),
            ])],

            'hodnoty' => ['nazev' => t('Naše hodnoty'), 'popis' => t('Tři až čtyři zásady, podle kterých pracujete.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Na čem si zakládáme')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [$z($n('nadpis', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]),
                    ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs', 'linka_nahore' => '1px solid var(--ka-barva-linka)', 'odsazeni_y' => 's']]), [
                    [t('Poctivost'), t('Říkáme, co uděláme, a děláme, co říkáme.')], [t('Řemeslo'), t('Každý detail děláme tak, jako by byl pro nás.')], [t('Ohleduplnost'), t('K zákazníkům, sousedům i přírodě.')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '3', 'mezera' => 'l'], 'tablet' => ['sloupce' => '1']]),
            ])],

            'historie' => ['nazev' => t('Historie'), 'popis' => t('Milníky firmy s rokem a krátkým popisem.'), 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $t($n('nadpis', ['text' => t('Naše cesta')]), 'nadpis-sekce'),
                $s($n('kontejner', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['tloustka_pisma' => '800', 'barva' => 'primarni', 'velikost_pisma' => '1']]),
                    $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '6rem 1fr', 'mezera' => 'm', 'odsazeni_y' => 's', 'linka_dole' => '1px solid var(--ka-barva-linka)'], 'mobil' => ['sloupce' => '1', 'mezera' => '2xs']]), [
                    ['2005', t('Založení firmy v garáži rodinného domu.')], ['2012', t('Nová dílna a první zaměstnanci.')], ['2020', t('Pětistá dokončená zakázka.')], ['2026', t('Otevření showroomu.')],
                ])), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column']]),
            ])],

            'kariera' => ['nazev' => t('Kariéra'), 'popis' => t('Pozvánka do týmu s volnými pozicemi a odkazem.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $n('nadpis', ['text' => t('Přidejte se k nám')]),
                        $t($n('text', ['html' => '<p>' . t('Hledáme šikovné lidi, kteří mají rádi dobře odvedenou práci.') . '</p>']), 'podtitul'),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                    $t($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Volné pozice')]), 'h3'),
                        $n('seznam', ['polozky' => t('Truhlář / truhlářka') . "\n" . t('Montážník') . "\n" . t('Obchodník'), 'styl' => 'fajfky']),
                        $n('tlacitko', ['text' => t('Poslat životopis'), 'odkaz' => $adresa('Kontakt')]),
                    ]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'pobocky' => ['nazev' => t('Pobočky'), 'popis' => t('Karty poboček s adresou, telefonem a otevírací dobou.'), 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => t('Kde nás najdete')]), 'nadpis-sekce'),
                $s($n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', [], [
                    $z($n('nadpis', ['text' => $d[0]]), 'h3'),
                    $n('text', ['html' => '<p>' . $d[1] . '<br>' . $d[2] . '</p><p>' . $d[3] . '</p>']),
                ]), 'karta'), [
                    [t('Praha'), t('Dlouhá 12'), t('110 00 Praha'), t('Po–Pá 8–17')], [t('Brno'), t('Masarykova 5'), t('602 00 Brno'), t('Po–Pá 9–17')], [t('Ostrava'), t('Nádražní 20'), t('702 00 Ostrava'), t('Po–Čt 8–16')],
                ])), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:16rem', 'mezera' => 'l']]),
            ])],

            /* ---------- kontakt a výzvy ---------- */

            'vyzva-pruh' => ['nazev' => t('Výzva v pruhu'), 'popis' => t('Úzký pruh: věta vlevo, tlačítko vpravo.'), 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => t('Potřebujete poradit? Zavolejte nám.')]), 'p'), ['zaklad' => ['velikost_pisma' => '2', 'tloustka_pisma' => '700']]),
                    $n('tlacitko', ['text' => t('Kontaktujte nás'), 'odkaz' => $adresa('Kontakt'), 'varianta' => 'sekundarni']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'center', 'mezera' => 'm', 'zalamovani' => 'wrap']]),
            ]), ['zaklad' => ['odsazeni_y' => 'l', 'pozadi' => 'primarni', 'barva' => 'na-primarni']])],

            'kontakt-formular' => ['nazev' => t('Kontakt s formulářem'), 'popis' => t('Údaje firmy z Nastavení vlevo, poptávkový formulář vpravo.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [
                        $z($n('nadpis', ['text' => t('Kontaktní údaje')]), 'h2'),
                        $n('udaje', ['udaj' => 'firma']), $z($n('udaje', ['udaj' => 'adresa']), 'address'),
                        $n('udaje', ['udaj' => 'telefon']), $n('udaje', ['udaj' => 'email']), $n('udaje', ['udaj' => 'hodiny']), $n('udaje', ['udaj' => 'mapa']),
                    ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'xs']]),
                    $t($n('kontejner', [], [$z($n('nadpis', ['text' => t('Napište nám')]), 'h2'), $n('formular')]), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2fr 3fr', 'mezera' => '2xl'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],

            'faq-dva' => ['nazev' => t('Otázky ve dvou sloupcích'), 'popis' => t('Nadpis vlevo, otázky a odpovědi vpravo.'), 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $s($n('kontejner', [], [$n('nadpis', ['text' => t('Časté otázky')]), $t($n('text', ['html' => '<p>' . t('Nenašli jste odpověď? Napište nám.') . '</p>']), 'podtitul')]),
                        ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's']]),
                    $n('faq'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '1fr 2fr', 'mezera' => '2xl'], 'tablet' => ['sloupce' => '1', 'mezera' => 'l']]),
            ])],
        ];
    }

    /**
     * Ukázkové weby pro instalaci: předvolba vzhledu (DesignSystem::PREDVOLBY) a sekce pro stránky Úvod, O nás, Služby, Kontakt.
     * Stránka bez sekcí zůstane textová. Sekce „nadpis-stranky“ dostane název stránky.
     */
    public const array WEBY = [
        'firemni' => ['nazev' => 'Firemní web', 'popis' => 'Univerzální web služeb: výhody, čísla, reference, novinky.', 'predvolba' => 'firemni', 'stranky' => [
            ['uvod', 'vyhody', 'cisla', 'reference', 'novinky', 'vyzva'], [], ['nadpis-stranky', 'sluzby', 'faq', 'vyzva'], ['nadpis-stranky', 'kontakt', 'poptavka'],
        ]],
        'remeslo' => ['nazev' => 'Řemeslo a služby', 'popis' => 'Teplé barvy, postup práce, realizace a záruky.', 'predvolba' => 'remeslo', 'stranky' => [
            ['uvod-obrazek', 'zaruky', 'proces', 'portfolio', 'reference', 'vyzva'], ['nadpis-stranky', 'pribeh', 'hodnoty', 'tym'],
            ['nadpis-stranky', 'strida', 'sluzby-seznam', 'faq', 'vyzva-pruh'], ['nadpis-stranky', 'kontakt-formular'],
        ]],
        'poradenstvi' => ['nazev' => 'Poradenství a agentura', 'popis' => 'Elegantní vzhled, klienti, balíčky služeb a tým.', 'predvolba' => 'elegantni', 'stranky' => [
            ['uvod-stred', 'loga', 'vyhody-seznam', 'cisla-svetla', 'reference-jedna', 'vyzva'], ['nadpis-stranky', 'pribeh', 'tym', 'historie', 'kariera'],
            ['nadpis-stranky', 'sluzby', 'cenik', 'faq-dva'], ['nadpis-stranky', 'kontakt-formular', 'pobocky'],
        ]],
    ];

    /** Šablony nové stránky (Nová stránka → Začít podle šablony): klíč => [název, sekce]. Zásady mají vlastní text. */
    public const array SABLONY_STRANEK = [
        'o-nas' => ['O nás', ['nadpis-stranky', 'pribeh', 'hodnoty', 'tym']],
        'sluzby' => ['Služby', ['nadpis-stranky', 'sluzby', 'proces', 'faq', 'vyzva']],
        'landing' => ['Prodejní stránka (landing page)', ['uvod', 'vyhody', 'reference', 'cenik', 'faq', 'vyzva']],
        'reference' => ['Reference a realizace', ['nadpis-stranky', 'portfolio', 'reference', 'vyzva']],
        'kariera' => ['Kariéra', ['nadpis-stranky', 'kariera', 'poptavka']],
        'kontakt' => ['Kontakt', ['nadpis-stranky', 'kontakt-formular']],
        'zasady' => ['Zásady ochrany osobních údajů', []],
    ];

    /** Kostra zásad ochrany osobních údajů pro poptávkový formulář (HTML v jazyce webu); hranaté závorky doplní správce. */
    public static function textZasad(): string
    {
        $o = fn (string $nadpis, string $text): string => '<h2>' . e(t($nadpis)) . '</h2><p>' . e(t($text)) . '</p>';

        return '<p>' . e(t('Tyto zásady vysvětlují, jak [NÁZEV FIRMY], IČO [IČO], se sídlem [ADRESA] zpracovává osobní údaje, které nám svěříte.')) . '</p>'
            . $o('Jaké údaje zpracováváme', 'Jméno, e-mail, telefon a obsah zprávy, které vyplníte v poptávkovém formuláři.')
            . $o('Proč a na jakém základě', 'Abychom vám mohli odpovědět a připravit nabídku – jde o opatření před uzavřením smlouvy a náš oprávněný zájem odpovědět na dotaz.')
            . $o('Jak dlouho', 'Poptávky mažeme automaticky po [POČET] měsících, pokud z nich nevznikne smlouva.')
            . $o('Kdo k údajům má přístup', 'Jen my a poskytovatel hostingu [NÁZEV HOSTINGU], který pro nás web provozuje.')
            . $o('Cookies a měření návštěvnosti', 'Web měří návštěvnost bez cookies. Cookies třetích stran použije jen s vaším souhlasem.')
            . $o('Vaše práva', 'Máte právo na přístup k údajům, opravu, výmaz, omezení zpracování a námitku. Stížnost můžete podat u Úřadu pro ochranu osobních údajů.')
            . $o('Kontakt', 'Napište nám na [E-MAIL] nebo zavolejte na [TELEFON].');
    }

    /** Náhrada sekce s prvkem vypnutého rozšíření: kontakt bez formuláře má aspoň údaje firmy a otevírací dobu. */
    private const array NAHRADY = ['kontakt-formular' => 'kontakt'];

    /**
     * Stavba stránky ukázkového webu ze sekcí (v jazyce instalace); použité třídy se založí.
     *
     * @param list<string> $sekce
     * @param list<string> $bezTypu sekce s těmito prvky se vynechají nebo nahradí (vypnutá rozšíření)
     * @param bool $bezObrazku vynechat prázdné obrázky (ukázkový web z instalace fotky nemá, na webu by po nich zůstalo prázdné místo)
     */
    public static function stranka(\Kaleta\Core\Db $db, array $sekce, string $titulek, string $jazyk, array $bezTypu = [], bool $bezObrazku = false): array
    {
        [$stavba, $tridy] = self::sestav($sekce, $titulek, $jazyk, $bezTypu, $bezObrazku);
        self::zalozTridy($db, $tridy);

        return $stavba;
    }

    /**
     * Stavba stránky ze sekcí a třídy, které používá (bez zápisu do databáze – viz stranka()).
     *
     * @param list<string> $sekce
     * @param list<string> $bezTypu
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function sestav(array $sekce, string $titulek, string $jazyk, array $bezTypu = [], bool $bezObrazku = false): array
    {
        $stavba = ['v' => Stavba::VERZE, 'deti' => []];
        $tridy = [];
        foreach ($sekce as $klic) {
            $s = self::sekci($klic, $jazyk);
            if ($s !== null && $bezTypu !== [] && self::obsahujeTyp($s['prvek'], $bezTypu)) {
                $s = isset(self::NAHRADY[$klic]) && !in_array(self::NAHRADY[$klic], $sekce, true) ? self::sekci(self::NAHRADY[$klic], $jazyk) : null;
            }
            $prvek = $s === null ? null : ($bezObrazku ? self::bezObrazku($s['prvek']) : $s['prvek']);
            if ($prvek === null) {
                continue;
            }
            if ($klic === 'nadpis-stranky') {
                $prvek['deti'][0]['obsah']['text'] = e($titulek);
            }
            $stavba['deti'][] = $prvek;
            array_push($tridy, ...$s['tridy']);
        }

        return [$stavba, array_values(array_unique($tridy))];
    }

    /**
     * Prvek bez prázdných obrázků: mřížka, ve které zbude jediný prvek (text vedle obrázku), se nahradí tímto prvkem;
     * prvek, ve kterém zbudou jen nadpisy (loga klientů), se vynechá celý – null.
     */
    private static function bezObrazku(array $p): ?array
    {
        if ($p['typ'] === 'obrazek' && ($p['obsah']['src'] ?? '') === '') {
            return null;
        }
        if (!isset($p['deti'])) {
            return $p;
        }
        $deti = array_values(array_filter(array_map(self::bezObrazku(...), $p['deti'])));
        if (count($deti) < count($p['deti'])) {
            if ($p['typ'] === 'mrizka' && count($deti) === 1) {
                return $deti[0];
            }
            if (array_filter($deti, fn (array $d): bool => $d['typ'] !== 'nadpis') === []) {
                return null;
            }
        }
        $p['deti'] = $deti;

        return $p;
    }

    /** Kategorie v panelu Hotové sekce (klíč => název). */
    public const array KATEGORIE = ['uvod' => 'Úvod', 'obsah' => 'Služby a obsah', 'duvera' => 'Důvěra', 'firma' => 'O firmě', 'akce' => 'Kontakt a výzvy'];

    /** Kategorie sekcí (ostatní jsou „obsah“). */
    private const array KATEGORIE_SEKCI = [
        'uvod' => 'uvod', 'uvod-obrazek' => 'uvod', 'uvod-stred' => 'uvod', 'uvod-tmavy' => 'uvod', 'uvod-video' => 'uvod', 'nadpis-stranky' => 'uvod',
        'reference' => 'duvera', 'reference-jedna' => 'duvera', 'recenze' => 'duvera', 'loga' => 'duvera', 'cisla' => 'duvera', 'cisla-svetla' => 'duvera', 'zaruky' => 'duvera',
        'pribeh' => 'firma', 'tym' => 'firma', 'hodnoty' => 'firma', 'historie' => 'firma', 'kariera' => 'firma', 'pobocky' => 'firma',
        'vyzva' => 'akce', 'vyzva-pruh' => 'akce', 'poptavka' => 'akce', 'kontakt' => 'akce', 'kontakt-formular' => 'akce', 'faq' => 'akce', 'faq-dva' => 'akce',
    ];

    /**
     * @param list<string>|null $rozsireni zapnutá rozšíření (null = všechna) – sekce s prvky vypnutých (novinky, formulář) se nenabízejí
     * @return list<array{klic:string, nazev:string, popis:string, kategorie:string}>
     */
    public static function seznam(?array $rozsireni = null): array
    {
        $vypnute = Stavba::vypnuteTypy($rozsireni);
        $sekce = array_filter(self::sekce(), fn (string $klic): bool => $vypnute === [] || !self::obsahujeTyp(self::vytvor($klic)['prvek'] ?? [], $vypnute), ARRAY_FILTER_USE_KEY);

        return array_map(fn (string $klic, array $s): array => ['klic' => $klic, 'nazev' => $s['nazev'], 'popis' => $s['popis'], 'kategorie' => self::KATEGORIE_SEKCI[$klic] ?? 'obsah'],
            array_keys($sekce), $sekce);
    }

    /** @param list<string> $typy */
    private static function obsahujeTyp(array $prvek, array $typy): bool
    {
        if (in_array($prvek['typ'] ?? '', $typy, true)) {
            return true;
        }
        foreach ($prvek['deti'] ?? [] as $d) {
            if (self::obsahujeTyp($d, $typy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nová kopie sekce (nová id) a názvy tříd, které používá. Ukázkové texty jsou v jazyce stránky, do které sekce přijde
     * (ne v jazyce administrace) – překlady ve slovníku webu system/jazyky/<kód>.php.
     *
     * @return array{prvek: array<string, mixed>, tridy: list<string>}|null
     */
    public static function sekci(string $klic, string $jazyk = 'cs'): ?array
    {
        return \Kaleta\Core\Jazyk::docasne($jazyk, fn (): ?array => self::vytvor($klic));
    }

    /** @return array{prvek: array<string, mixed>, tridy: list<string>}|null */
    private static function vytvor(string $klic): ?array
    {
        $sekce = self::sekce()[$klic] ?? null;
        if ($sekce === null) {
            return null;
        }
        [$stavba] = Stavba::vycisti(['v' => 1, 'deti' => [($sekce['stavba'])()]]);
        $prvek = $stavba['deti'][0];
        $prvek['popis'] = $sekce['nazev'];
        $tridy = [];
        $projdi = function (array $p) use (&$projdi, &$tridy): void {
            foreach ($p['tridy'] ?? [] as $t) {
                $tridy[$t] = true;
            }
            foreach ($p['deti'] ?? [] as $d) {
                $projdi($d);
            }
        };
        $projdi($prvek);

        return ['prvek' => $prvek, 'tridy' => array_keys($tridy)];
    }

    /** Založí chybějící třídy knihovny (existující třídu webu nikdy nepřepíše). */
    public static function zalozTridy(\Kaleta\Core\Db $db, array $nazvy): void
    {
        foreach ($nazvy as $nazev) {
            if (isset(self::TRIDY[$nazev])) {
                $db->run('INSERT IGNORE INTO {tridy} (nazev, styl, zmeneno) VALUES (?, ?, NOW())', [$nazev, (string) json_encode(self::TRIDY[$nazev], JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
}
