<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

/**
 * Typ prvku stavitele. Každý typ = jedna třída v Stavitel\Prvky se schématem obsahu (VLASTNOSTI), povolenými HTML značkami,
 * výchozím stylem a vykreslením. Výstup je vždy jedna značka na prvek (výjimky jsou jen složené prvky jako FAQ nebo výpis novinek).
 *
 * Pole obsahu – typy: text (řádek), inline (krátký text s tučným/kurzívou/odkazem), html (formátovaný text), radky (víc řádků),
 * odkaz, obrazek, vyber, cislo, prepinac, polozky (seznam objektů s poli „pole“).
 */
abstract class Prvek
{
    public const string TYP = '';
    public const string NAZEV = '';
    public const string POPIS = '';
    public const string IKONA = 'blok';
    public const string SKUPINA = 'Obsah';
    /** Může obsahovat další prvky. */
    public const bool KONTEJNER = false;
    /** Povolené značky, první je výchozí. */
    public const array ZNACKY = ['div'];
    /** Smí vložit a měnit jen správce (vlastní HTML). */
    public const bool JEN_SPRAVCE = false;
    /** Nabízí se jen v částech webu (záhlaví, patička, obálky) – logo, navigace, obsah stránky. */
    public const bool JEN_CASTI = false;

    /** @return array<string, array<string, mixed>> pole obsahu: klíč => [typ, popisek, vychozi, moznosti, pole, max] */
    public static function vlastnosti(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> výchozí vnitřek nově vloženého kontejneru (editor mu dá nová id) */
    public static function vychoziDeti(): array
    {
        return [];
    }

    /** @return array<string, array<string, string>> výchozí styl nově vloženého prvku */
    public static function vychoziStyl(): array
    {
        return [];
    }

    /** Základní CSS typu (vrstva „stavitel“), vypíše se jen na stránkách, kde typ je. */
    public static function zakladniCss(): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $p       vyčištěný prvek (typ, znacka, obsah, …)
     * @param string               $a       hotové atributy (id, class, data-mc-id) začínající mezerou
     * @param string               $deti    vykreslené vnořené prvky
     */
    abstract public static function vykresli(array $p, string $a, string $deti, Kontext $k): string;
}
