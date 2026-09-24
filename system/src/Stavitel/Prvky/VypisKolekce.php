<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Core\Jazyk;
use MiroCMS\Stavitel\Kolekce;
use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Výpis kolekce: vnitřek prvku je vzor jedné položky a zopakuje se pro každou položku kolekce (reference, tým, produkty…).
 * V textech, obrázcích a odkazech uvnitř se {{pole}} nahradí hodnotou položky: {{nazev}}, {{url}}, {{datum}} a vlastní pole.
 */
final class VypisKolekce extends Prvek
{
    public const string TYP = 'kolekce';
    public const string NAZEV = 'Výpis kolekce';
    public const string POPIS = 'Karty z kolekce (reference, tým, produkty…) – vnitřek je vzor jedné položky, {{pole}} se doplní samo.';
    public const string IKONA = 'kolekce';
    public const string SKUPINA = 'Dynamické';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['div', 'ul'];

    public static function vlastnosti(): array
    {
        return [
            'kolekce' => ['typ' => 'text', 'popisek' => 'Kolekce', 'vychozi' => '', 'max' => 110],
            'pocet' => ['typ' => 'cislo', 'popisek' => 'Nejvýš položek', 'vychozi' => 12, 'min' => 1, 'max' => 100],
            'razeni' => ['typ' => 'vyber', 'popisek' => 'Řazení', 'vychozi' => 'poradi', 'moznosti' => ['poradi' => 'podle pořadí v administraci', 'nazev' => 'podle názvu', 'nejnovejsi' => 'nejnovější první',
                'pole' => 'podle pole – vzestupně', 'pole_sestupne' => 'podle pole – sestupně']],
            'razeni_pole' => ['typ' => 'text', 'popisek' => 'Pole pro řazení (klíč, např. cena)', 'vychozi' => '', 'max' => 31],
            'filtr_pole' => ['typ' => 'text', 'popisek' => 'Filtrovat podle pole (klíč, nepovinné)', 'vychozi' => '', 'max' => 31],
            'filtr_hodnota' => ['typ' => 'text', 'popisek' => 'Jen položky s hodnotou', 'vychozi' => '', 'max' => 200],
            'filtry' => ['typ' => 'prepinac', 'popisek' => 'Tlačítka filtru pro návštěvníky (podle pole výše)', 'vychozi' => false],
            'strankovani' => ['typ' => 'prepinac', 'popisek' => 'Stránkovat (po „Nejvýš položek“)', 'vychozi' => false],
            'prazdne' => ['typ' => 'text', 'popisek' => 'Text, když kolekce nemá položky', 'vychozi' => '', 'max' => 300],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.mc-kolekce-filtry, .mc-kolekce-strany { display: flex; flex-wrap: wrap; gap: var(--mc-mezera-xs); margin: 0 0 var(--mc-mezera-m); padding: 0; list-style: none; }
.mc-kolekce-strany { justify-content: center; margin: var(--mc-mezera-l) 0 0; }
.mc-kolekce-filtry a, .mc-kolekce-strany a { display: block; padding: 0.4em 0.9em; border: 1px solid var(--mc-barva-linka); border-radius: var(--mc-zaobleni-plne); color: inherit; text-decoration: none; }
.mc-kolekce-filtry a:hover, .mc-kolekce-strany a:hover { border-color: var(--mc-barva-primarni); }
.mc-kolekce-filtry a[aria-current], .mc-kolekce-strany a[aria-current] { background: var(--mc-barva-primarni); border-color: var(--mc-barva-primarni); color: var(--mc-barva-na-primarni); }';
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:18rem', 'mezera' => 'l']];
    }

    public static function vychoziDeti(): array
    {
        // třída karta z knihovny sekcí (editor ji při vložení založí, pokud na webu ještě není)
        return [['tridy' => ['karta']] + \MiroCMS\Stavitel\Stavba::novy('kontejner', [], [
            ['znacka' => 'h3'] + \MiroCMS\Stavitel\Stavba::novy('nadpis', ['text' => '{{nazev}}']),
            \MiroCMS\Stavitel\Stavba::novy('tlacitko', ['text' => t('Více informací'), 'odkaz' => '{{url}}', 'varianta' => 'odkaz']),
        ])];
    }

    /** Vnitřek pro každou položku (volá Stavba při vykreslení). */
    public static function opakuj(array $p, Kontext $k, callable $vnitrek): string
    {
        $o = $p['obsah'];
        $kolekce = $o['kolekce'] === '' ? null : Kolekce::podleSeo($k->app->db(), (string) $o['kolekce']);
        if ($kolekce === null) {
            return $k->editor ? '<p>' . e(t('Vyberte kolekci v panelu Obsah.')) . '</p>' : '';
        }
        $r = $k->app->request;
        $db = $k->app->db();
        // návštěvníkův filtr a strana jsou v adrese pod klíčem podle id prvku (výpisů může být na stránce víc)
        $parFiltr = 'f-' . $p['id'];
        $parStrana = 's-' . $p['id'];
        $filtrPole = preg_match(Kolekce::VZOR_KLICE, (string) $o['filtr_pole']) ? (string) $o['filtr_pole'] : '';
        $hodnotyFiltru = $filtrPole !== '' && $o['filtry'] ? Kolekce::hodnotyPole($db, (int) $kolekce['idk'], Jazyk::sloupecWebu(), $filtrPole) : [];
        $zvoleny = in_array($r->get($parFiltr), $hodnotyFiltru, true) ? $r->get($parFiltr) : '';
        $filtr = $filtrPole === '' ? null : [$filtrPole, $zvoleny !== '' ? $zvoleny : (string) $o['filtr_hodnota']];
        $strana = $o['strankovani'] ? max(1, $r->getInt($parStrana, 1)) : 1;
        [$polozky, $celkem] = Kolekce::polozky($db, (int) $kolekce['idk'], Jazyk::sloupecWebu(), (int) $o['pocet'], (string) $o['razeni'], $filtr, $strana, (string) $o['razeni_pole']);
        $k->okoli[$p['id']] = ['pred' => self::filtry($hodnotyFiltru, $zvoleny, $parFiltr, $k), 'za' => $o['strankovani'] ? self::strany($celkem, (int) $o['pocet'], $strana, $parStrana, $zvoleny !== '' ? [$parFiltr => $zvoleny] : [], $k) : ''];
        $hodnoty = array_map(fn (array $polozka): array => Kolekce::hodnoty($kolekce, $polozka, $k->url(...)), $polozky);
        if ($hodnoty === []) {
            if (!$k->editor) {
                return $o['prazdne'] !== '' ? '<p>' . e($o['prazdne']) . '</p>' : '';
            }
            $hodnoty = [Kolekce::ukazka($kolekce)]; // v editoru vzor s popisky polí, ať je co navrhovat
        }
        [$predtim, $hloubka] = [$k->polozka, $k->vSmycce];
        $k->vSmycce++;
        $html = '';
        foreach ($hodnoty as $h) {
            $k->polozka = $h;
            $html .= $vnitrek();
        }
        [$k->polozka, $k->vSmycce] = [$predtim, $hloubka];

        return $html;
    }

    /** Tlačítka filtru (odkazy – fungují bez JavaScriptu a jdou sdílet). */
    private static function filtry(array $hodnoty, string $zvoleny, string $parametr, Kontext $k): string
    {
        if ($hodnoty === []) {
            return '';
        }
        $odkaz = fn (string $hodnota, string $text): string => '<li><a href="' . e($k->cesta . ($hodnota !== '' ? '?' . http_build_query([$parametr => $hodnota]) : '')) . '"'
            . ($hodnota === $zvoleny ? ' aria-current="true"' : '') . '>' . e($text) . '</a></li>';

        return '<ul class="mc-kolekce-filtry" aria-label="' . e(t('Filtr')) . '">' . $odkaz('', t('Vše')) . implode('', array_map(fn (string $h): string => $odkaz($h, $h), $hodnoty)) . '</ul>';
    }

    /** @param array<string, string> $zachovat další parametry adresy (zvolený filtr) */
    private static function strany(int $celkem, int $naStranu, int $strana, string $parametr, array $zachovat, Kontext $k): string
    {
        $stran = (int) ceil($celkem / max(1, $naStranu));
        if ($stran < 2) {
            return '';
        }
        $html = '';
        for ($i = 1; $i <= $stran; $i++) {
            $dotaz = http_build_query($zachovat + ($i > 1 ? [$parametr => $i] : []));
            $html .= '<li><a href="' . e($k->cesta . ($dotaz !== '' ? '?' . $dotaz : '')) . '"' . ($i === $strana ? ' aria-current="page"' : '') . '>' . $i . '</a></li>';
        }

        return '<ul class="mc-kolekce-strany" aria-label="' . e(t('Stránky výpisu')) . '">' . $html . '</ul>';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $okoli = $k->okoli[$p['id']] ?? ['pred' => '', 'za' => ''];
        unset($k->okoli[$p['id']]);
        $vypis = $deti === '' ? '' : '<' . $p['znacka'] . $a . '>' . $deti . '</' . $p['znacka'] . '>';

        // filtry a stránkování jsou kolem mřížky (ne v ní, jinak by byly jako další karta)
        return $okoli['pred'] === '' && $okoli['za'] === '' ? $vypis : '<div class="mc-kolekce">' . $okoli['pred'] . $vypis . $okoli['za'] . '</div>';
    }
}
