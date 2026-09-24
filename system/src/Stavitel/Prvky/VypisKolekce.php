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
            'razeni' => ['typ' => 'vyber', 'popisek' => 'Řazení', 'vychozi' => 'poradi', 'moznosti' => ['poradi' => 'podle pořadí v administraci', 'nazev' => 'podle názvu', 'nejnovejsi' => 'nejnovější první']],
            'prazdne' => ['typ' => 'text', 'popisek' => 'Text, když kolekce nemá položky', 'vychozi' => '', 'max' => 300],
        ];
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
        $polozky = Kolekce::polozky($k->app->db(), (int) $kolekce['idk'], Jazyk::sloupecWebu(), (int) $o['pocet'], (string) $o['razeni']);
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

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        return $deti === '' ? '' : '<' . $p['znacka'] . $a . '>' . $deti . '</' . $p['znacka'] . '>';
    }
}
