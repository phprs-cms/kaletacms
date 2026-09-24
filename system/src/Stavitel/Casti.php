<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use MiroCMS\Core\Db;

/**
 * Části webu ze stavitele (tabulka mc_casti): záhlaví a patička na všech stránkách a obálky kolem obsahu, který skládá
 * systém (detail novinky, výpis novinek, stránka 404). Část bez publikované stavby = část ze šablony (layout).
 */
final class Casti
{
    /** typ => [název, popis] */
    public const array TYPY = [
        'hlavicka' => ['Záhlaví', 'Logo a navigace nahoře na každé stránce.'],
        'paticka' => ['Patička', 'Kontakty, odkazy a copyright dole na každé stránce.'],
        'novinka' => ['Detail novinky', 'Obálka kolem textu novinky – třeba výzva k akci nebo další novinky pod článkem.'],
        'vypis' => ['Výpis novinek', 'Obálka kolem výpisu novinek, kategorie, štítku a hledání.'],
        'nenalezeno' => ['Stránka nenalezena (404)', 'Obálka kolem hlášení, že stránka neexistuje – třeba s odkazy dál.'],
    ];

    /** Obálky – obsahují prvek „Obsah stránky“, kam systém vloží svůj obsah. */
    public const array OBALKY = ['novinka', 'vypis', 'nenalezeno'];

    /** @return array<string, mixed>|null řádek části */
    public static function radek(Db $db, string $typ, string $jazyk): ?array
    {
        return $db->one('SELECT * FROM {casti} WHERE typ = ? AND jazyk = ?', [$typ, $jazyk]);
    }

    /** Publikovaná stavba části (nebo rozpracovaná pro náhled v editoru); null = část ze šablony. */
    public static function stavba(Db $db, string $typ, string $jazyk, bool $koncept = false): ?array
    {
        $r = self::radek($db, $typ, $jazyk);

        return $r === null ? null : Stavba::zJson($koncept ? ($r['stavba_koncept'] ?? $r['stavba']) : $r['stavba']);
    }

    /** Stavba, se kterou se část poprvé otevře ve staviteli (odpovídá tomu, co dosud kreslila šablona). */
    public static function vychozi(string $typ, string $jazyk): array
    {
        return \MiroCMS\Core\Jazyk::docasne($jazyk, function () use ($typ): array {
            $n = Stavba::novy(...);
            $s = fn (array $p, array $styl): array => ['styl' => $styl] + $p;
            $z = fn (array $p, string $znacka): array => ['znacka' => $znacka] + $p;
            $deti = match ($typ) {
                'hlavicka' => [$s($z($n('sekce', [], [
                    $s($n('kontejner', [], [$n('logo'), $n('navigace')]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'center', 'mezera' => 'm']]),
                ]), 'header'), ['zaklad' => ['odsazeni_y' => 's', 'pozadi' => 'pozadi', 'linka_dole' => '1px solid var(--mc-barva-linka)', 'pozice' => 'sticky', 'odshora' => '0', 'vrstva' => '10']])],
                'paticka' => [$s($z($n('sekce', [], [
                    $s($n('mrizka', [], [
                        $n('kontejner', [], [$s($z($n('udaje', ['udaj' => 'nazev']), 'p'), ['zaklad' => ['tloustka_pisma' => '700']]), $n('udaje', ['udaj' => 'popis']), $n('udaje', ['udaj' => 'email'])]),
                        $n('kontejner', [], [$n('udaje', ['udaj' => 'site']), $n('udaje', ['udaj' => 'rss'])]),
                    ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'l'], 'mobil' => ['sloupce' => '1']]),
                    $s($n('udaje', ['udaj' => 'copyright']), ['zaklad' => ['okraj_nahore' => 'l', 'velikost_pisma' => '-1', 'barva' => 'tlumeny']]),
                ]), 'footer'), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'plocha', 'linka_nahore' => '1px solid var(--mc-barva-linka)']])],
                'novinka' => [$n('obsah', [], []), Knihovna::sekci('vyzva', \MiroCMS\Core\Jazyk::kod())['prvek']],
                default => [$n('obsah', [], [])],
            };

            return Stavba::vycisti(['v' => Stavba::VERZE, 'deti' => $deti])[0];
        });
    }

    public static function klicRevize(string $typ, string $jazyk): string
    {
        return $typ . ':' . $jazyk;
    }
}
