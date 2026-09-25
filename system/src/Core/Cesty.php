<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Systémové adresy webu v jazyce verze: česká verze má /novinky, /novinky/kategorie/…, /novinky/stitek/… a /hledani,
 * každá jiná /news, /news/category/…, /news/tag/… a /search.
 *
 * Kód uvnitř systému pracuje s českými (vnitřními) cestami; App::url() je převede na veřejné a Front\Kernel veřejné
 * zase na vnitřní. Druhá podoba adresy (třeba stará /novinky na anglickém webu) přesměruje natrvalo na platnou, takže
 * odkazy a pozice ve vyhledávačích zůstanou. Má-li web vlastní stránku s adresou news nebo search, zůstane jí a systém
 * použije české slovo.
 */
final class Cesty
{
    /** vnitřní (české) slovo => anglické */
    private const array PRVNI = ['novinky' => 'news', 'hledani' => 'search'];
    private const array DRUHE = ['kategorie' => 'category', 'stitek' => 'tag'];

    /** @var array<string, bool>|null anglická slova, která na webu zabírá vlastní stránka */
    private static ?array $obsazene = null;

    /** Anglická slova se použijí pro každý jazyk kromě češtiny. */
    public static function anglicky(string $jazyk): bool
    {
        return $jazyk !== 'cs';
    }

    /** Vnitřní cesta (bez úvodního lomítka, i s ?dotazem) na veřejnou pro daný jazyk verze. */
    public static function verejna(string $cesta, string $jazyk, ?Db $db): string
    {
        if (!self::anglicky($jazyk) || !preg_match('#^(novinky|hledani)(?=$|[/?.])#', $cesta, $m) || self::obsazeno(self::PRVNI[$m[1]], $db)) {
            return $cesta;
        }
        $zbytek = substr($cesta, strlen($m[1]));
        if ($m[1] === 'novinky' && preg_match('#^/(kategorie|stitek)(?=/)#', $zbytek, $d)) {
            $zbytek = '/' . self::DRUHE[$d[1]] . substr($zbytek, strlen($d[0]));
        }

        return self::PRVNI[$m[1]] . $zbytek;
    }

    /**
     * Veřejná cesta požadavku (s úvodním lomítkem, bez jazykové předpony) na vnitřní. Vrací [vnitřní, kanonická]:
     * kanonická je podoba, kterou má adresa v tomto jazyce mít; když se liší od požadované, Front\Kernel přesměruje.
     *
     * @return array{0: string, 1: string}
     */
    public static function vnitrni(string $cesta, string $jazyk, ?Db $db): array
    {
        if (!preg_match('#^/(novinky|hledani|news|search)(?=$|[/.])#', $cesta, $m)) {
            return [$cesta, $cesta];
        }
        $slovo = $m[1];
        $cesky = array_search($slovo, self::PRVNI, true);
        if ($cesky !== false && self::obsazeno($slovo, $db)) {
            return [$cesta, $cesta]; // vlastní stránka webu
        }
        $vnitrni = '/' . ($cesky !== false ? $cesky : $slovo) . substr($cesta, strlen($m[0]));
        if (($cesky !== false ? $cesky : $slovo) === 'novinky') {
            $vnitrni = (string) preg_replace_callback('#^/novinky/(category|tag|kategorie|stitek)(?=/)#',
                fn (array $d): string => '/novinky/' . (array_search($d[1], self::DRUHE, true) ?: $d[1]), $vnitrni);
        }

        return [$vnitrni, '/' . self::verejna(ltrim($vnitrni, '/'), $jazyk, $db)];
    }

    /** Zabírá anglické slovo vlastní stránka webu (třeba stránka „news“ z doby před 1.2)? */
    private static function obsazeno(string $slovo, ?Db $db): bool
    {
        if ($db === null) {
            return false;
        }
        if (self::$obsazene === null) {
            self::$obsazene = [];
            try {
                foreach ($db->all("SELECT seo_link FROM {stranky} WHERE seo_link IN ('news', 'search') AND smazano IS NULL") as $r) {
                    self::$obsazene[$r['seo_link']] = true;
                }
            } catch (\Throwable) {
                // web před instalací nebo bez tabulky – žádná vlastní stránka
            }
        }

        return isset(self::$obsazene[$slovo]);
    }

    /** Po změně adres stránek (testy, uložení stránky) se obsazená slova zjistí znovu. */
    public static function zapomen(): void
    {
        self::$obsazene = null;
    }
}
