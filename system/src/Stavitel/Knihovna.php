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
        $radaTlacitek = fn (array ...$tlacitka): array => $s($n('kontejner', [], $tlacitka), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'zalamovani' => 'wrap', 'mezera' => 's']]);

        return [
            'uvod' => ['nazev' => 'Úvodní pás', 'popis' => 'Velký nadpis, podtitul a dvě tlačítka.', 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($z($n('nadpis', ['text' => 'Pomáháme firmám růst – rychle a bez starostí']), 'h1'), ['zaklad' => ['velikost_pisma' => '5', 'max_radek' => '20ch']]),
                $t($n('text', ['html' => '<p>Jednou nebo dvěma větami řekněte, co děláte, pro koho a proč právě vy.</p>']), 'podtitul'),
                $radaTlacitek($n('tlacitko', ['text' => 'Nezávazná poptávka', 'odkaz' => '/kontakt']), $n('tlacitko', ['text' => 'Naše služby', 'odkaz' => '/sluzby', 'varianta' => 'obrys'])),
            ]), ['zaklad' => ['odsazeni_y' => '3xl'], 'mobil' => ['odsazeni_y' => '2xl']])],

            'uvod-obrazek' => ['nazev' => 'Úvod s obrázkem', 'popis' => 'Text a tlačítka vlevo, obrázek vpravo; na telefonu pod sebou.', 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], [
                    $n('kontejner', [], [
                        $s($z($n('nadpis', ['text' => 'Řemeslo, na které se můžete spolehnout']), 'h1'), ['zaklad' => ['velikost_pisma' => '4']]),
                        $t($n('text', ['html' => '<p>Popište hlavní přínos pro zákazníka. Krátce, konkrétně a jeho slovy.</p>']), 'podtitul'),
                        $radaTlacitek($n('tlacitko', ['text' => 'Kontaktujte nás', 'odkaz' => '/kontakt'])),
                    ]),
                    $s($n('obrazek', ['alt' => '', 'priorita' => true]), ['zaklad' => ['sirka' => '100%', 'zaobleni' => 'l', 'pomer_stran' => '4/3', 'prizpusobeni' => 'cover']]),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => '2xl', 'zarovnani' => 'center'], 'tablet' => ['sloupce' => '1', 'mezera' => 'xl']]),
            ]), ['zaklad' => ['odsazeni_y' => '2xl']])],

            'vyhody' => ['nazev' => 'Výhody', 'popis' => 'Nadpis a tři karty s hlavními důvody, proč si vybrat vás.', 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => 'Proč právě my']), 'nadpis-sekce'),
                $n('mrizka', [], array_map(fn (array $d): array => $t($n('kontejner', [], [$z($n('nadpis', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]), 'karta'), [
                    ['Zkušenosti', 'Za patnáct let jsme dokončili stovky zakázek po celé republice.'],
                    ['Férová cena', 'Cenu znáte předem a platíte jen za to, co je opravdu hotové.'],
                    ['Rychlost', 'Na poptávku odpovídáme do jednoho pracovního dne.'],
                ])),
            ])],

            'sluzby' => ['nazev' => 'Služby s obrázky', 'popis' => 'Karty služeb s obrázkem, popisem a odkazem.', 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => 'Co pro vás uděláme']), 'nadpis-sekce'),
                $n('mrizka', [], array_map(fn (string $nazev): array => $t($n('kontejner', [], [
                    $s($n('obrazek', ['alt' => $nazev]), ['zaklad' => ['sirka' => '100%', 'pomer_stran' => '3/2', 'prizpusobeni' => 'cover', 'zaobleni' => 's']]),
                    $z($n('nadpis', ['text' => $nazev]), 'h3'),
                    $n('text', ['html' => '<p>Krátký popis služby a pro koho je.</p>']),
                    $n('tlacitko', ['text' => 'Více informací', 'varianta' => 'odkaz', 'odkaz' => '/sluzby']),
                ]), 'karta'), ['Návrh', 'Realizace', 'Servis'])),
            ])],

            'cisla' => ['nazev' => 'Čísla', 'popis' => 'Pás se čtyřmi výraznými čísly v hlavní barvě.', 'stavba' => fn (): array => $s($n('sekce', [], [
                $s($n('mrizka', [], array_map(fn (array $d): array => $s($n('kontejner', [], [
                    $s($z($n('nadpis', ['text' => $d[0]]), 'p'), ['zaklad' => ['velikost_pisma' => '4', 'tloustka_pisma' => '800']]),
                    $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => '2xs', 'zarovnani_textu' => 'center']]), [['15+', 'let na trhu'], ['1 200', 'hotových zakázek'], ['98 %', 'spokojených zákazníků'], ['24 h', 'na odpověď']])),
                    ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '4', 'mezera' => 'l'], 'tablet' => ['sloupce' => '2']]),
            ]), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'primarni', 'barva' => 'na-primarni']])],

            'reference' => ['nazev' => 'Reference', 'popis' => 'Co o vás říkají zákazníci – citáty se jménem.', 'stavba' => fn (): array => $n('sekce', [], [
                $t($n('nadpis', ['text' => 'Co říkají naši zákazníci']), 'nadpis-sekce'),
                $s($n('mrizka', [], [
                    $t($n('citat', ['text' => 'Vše proběhlo přesně podle domluvy, včas a v rozpočtu. Rádi se vrátíme.', 'autor' => 'Petr Svoboda', 'pozice' => 'jednatel, Svoboda s.r.o.']), 'karta'),
                    $t($n('citat', ['text' => 'Oceňujeme rychlou komunikaci a to, že nám vždy poradili to nejlepší řešení.', 'autor' => 'Lucie Dvořáková', 'pozice' => 'provozní ředitelka']), 'karta'),
                ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:20rem', 'mezera' => 'l']]),
            ])],

            'faq' => ['nazev' => 'Otázky a odpovědi', 'popis' => 'Nejčastější dotazy zákazníků – i jako strukturovaná data pro vyhledávače.', 'stavba' => fn (): array => $n('sekce', ['sirka' => 'uzka'], [
                $n('nadpis', ['text' => 'Časté otázky']),
                $n('faq'),
            ])],

            'vyzva' => ['nazev' => 'Výzva k akci', 'popis' => 'Barevný box s nadpisem, větou a tlačítkem.', 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], [
                    $z($n('nadpis', ['text' => 'Máte projekt? Pojďme ho probrat.']), 'h2'),
                    $n('text', ['html' => '<p>Ozvěte se nám – do 24 hodin se vám ozveme s návrhem dalšího postupu.</p>']),
                    $n('tlacitko', ['text' => 'Napište nám', 'odkaz' => '/kontakt', 'varianta' => 'sekundarni']),
                ]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'zarovnani' => 'center', 'mezera' => 's', 'zarovnani_textu' => 'center',
                    'odsazeni_y' => '2xl', 'odsazeni_x' => 'l', 'pozadi' => 'primarni', 'barva' => 'na-primarni', 'zaobleni' => 'l']]),
            ])],

            'novinky' => ['nazev' => 'Poslední novinky', 'popis' => 'Tři nejnovější novinky a odkaz na všechny.', 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('kontejner', [], [$z($n('nadpis', ['text' => 'Novinky']), 'h2'), $n('tlacitko', ['text' => 'Všechny novinky', 'odkaz' => '/novinky', 'varianta' => 'odkaz'])]),
                    ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'baseline', 'zalamovani' => 'wrap', 'mezera' => 's']]),
                $n('novinky'),
            ])],

            'kontakt' => ['nazev' => 'Kontakt', 'popis' => 'Adresa a kontakty vlevo, otevírací doba vpravo.', 'stavba' => fn (): array => $n('sekce', [], [
                $s($n('mrizka', [], [
                    $n('kontejner', [], [
                        $z($n('nadpis', ['text' => 'Kontakt']), 'h2'),
                        $n('text', ['html' => '<p><strong>Název firmy s.r.o.</strong><br>Ulice 123, 110 00 Praha</p><p>Telefon: <a href="tel:+420123456789">+420 123 456 789</a><br>E-mail: <a href="mailto:info@example.cz">info@example.cz</a></p>']),
                    ]),
                    $t($n('kontejner', [], [
                        $z($n('nadpis', ['text' => 'Otevírací doba']), 'h3'),
                        $n('seznam', ['polozky' => "Po–Pá 8:00–17:00\nSo po domluvě", 'styl' => 'bez']),
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
     * Nová kopie sekce (nová id) a názvy tříd, které používá.
     *
     * @return array{prvek: array<string, mixed>, tridy: list<string>}|null
     */
    public static function sekci(string $klic): ?array
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
