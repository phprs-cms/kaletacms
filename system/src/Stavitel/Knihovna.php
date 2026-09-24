<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

/**
 * Knihovna sekcí pro firemní web: hotové stavby z tokenů design systému a pár sdílených tříd, takže po vložení hned sedí
 * do barev a písem webu. Editor vkládá kopii (nová id); třídy, které sekce používá, se založí, když na webu ještě nejsou.
 */
final class Knihovna
{
    /** Sdílené třídy knihovny (název => styl). Založí se při prvním vložení sekce, která je používá; pak patří webu. */
    public const array TRIDY = [
        'karta' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 's', 'odsazeni_y' => 'l', 'odsazeni_x' => 'l', 'pozadi' => 'plocha', 'zaobleni' => 'm']],
        'nadpis-sekce' => ['zaklad' => ['max_radek' => 'var(--mc-sirka-textu)', 'okraj_dole' => 'l']],
        'podtitul' => ['zaklad' => ['velikost_pisma' => '1', 'barva' => 'tlumeny', 'max_radek' => 'var(--mc-sirka-textu)']],
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
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs', 'zarovnani_textu' => 'center']]), [['15+', t('let na trhu')], ['1 200', t('hotových zakázek')], ['98 %', t('spokojených zákazníků')], ['24 h', t('na odpověď')]])),
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
                        $z($n('nadpis', ['text' => t('Kontakt')]), 'h2'),
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
        ];
    }

    /** @return list<array{klic:string, nazev:string, popis:string}> */
    public static function seznam(): array
    {
        return array_map(fn (string $klic, array $s): array => ['klic' => $klic, 'nazev' => $s['nazev'], 'popis' => $s['popis']], array_keys(self::sekce()), self::sekce());
    }

    /**
     * Nová kopie sekce (nová id) a názvy tříd, které používá. Ukázkové texty jsou v jazyce stránky, do které sekce přijde
     * (ne v jazyce administrace) – překlady ve slovníku webu system/jazyky/<kód>.php.
     *
     * @return array{prvek: array<string, mixed>, tridy: list<string>}|null
     */
    public static function sekci(string $klic, string $jazyk = 'cs'): ?array
    {
        return \MiroCMS\Core\Jazyk::docasne($jazyk, fn (): ?array => self::vytvor($klic));
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
    public static function zalozTridy(\MiroCMS\Core\Db $db, array $nazvy): void
    {
        foreach ($nazvy as $nazev) {
            if (isset(self::TRIDY[$nazev])) {
                $db->run('INSERT IGNORE INTO {tridy} (nazev, styl, zmeneno) VALUES (?, ?, NOW())', [$nazev, (string) json_encode(self::TRIDY[$nazev], JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
}
