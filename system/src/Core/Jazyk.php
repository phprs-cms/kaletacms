<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Jazyk webu a překlady textů šablon.
 *
 * Texty v šablonách jsou česky a obalené funkcí t('Celý článek'); pro jiný jazyk se hledají ve slovníku
 * system/jazyky/<kód>.php (česky => překlad). Co ve slovníku chybí, zůstane česky - web se nikdy nerozbije.
 * Jazyk celého webu určuje Nastavení (jazyk_webu); rozšíření "jazyky" přidává další jazykové verze
 * na adresách /en/… - každá má své stránky, kategorie a novinky. Nový jazyk = slovníky system/jazyky/<kód>.php
 * (web), admin-<kód>.php a install-<kód>.php + záznam v DOSTUPNE.
 */
final class Jazyk
{
    /** kód => [název v daném jazyce, locale pro Open Graph] */
    public const array DOSTUPNE = [
        'cs' => ['Čeština', 'cs_CZ'], 'en' => ['English', 'en_US'],
    ];

    /** Kódy z DOSTUPNE pro typy polí Nastavení (vyber:… / seznam:…). */
    public const string KODY = 'cs|en';

    private static string $kod = 'cs';
    private static string $sloupec = '';

    /** @var array<string, string> */
    private static array $slovnik = [];

    /** Jazyky, do kterých je přeložená administrace (slovník system/jazyky/admin-<kód>.php). */
    public const array ADMINISTRACE = ['cs' => 'Čeština', 'en' => 'English'];

    /** @param string $sada "" = texty webu, "admin-" = texty administrace */
    public static function nastav(string $kod, string $sada = ''): void
    {
        self::$kod = isset(self::DOSTUPNE[$kod]) ? $kod : 'cs';
        $soubor = MIROCMS_SYSTEM . '/jazyky/' . $sada . self::$kod . '.php';
        self::$slovnik = self::$kod !== 'cs' && is_file($soubor) ? require $soubor : [];
    }

    /**
     * Jazyk právě zobrazené verze webu; zároveň si zapamatuje hodnotu sloupce "jazyk" pro dotazy.
     */
    public static function nastavWeb(Settings $s, string $kod): void
    {
        self::nastav($kod);
        self::$sloupec = self::sloupec($s, self::$kod);
    }

    /**
     * Provede funkci s texty webu v jiném jazyce a vrátí jazyk zpět. Pro obsah, jehož jazyk nezávisí na tom,
     * kdo ho zrovna vytváří – typicky e-mail návštěvníkovi (spouští ho správce v administraci nebo úloha na pozadí).
     *
     * @template T
     * @param callable(): T $funkce
     * @return T
     */
    public static function docasne(string $kod, callable $funkce, string $sada = ''): mixed
    {
        [$kodPred, $slovnikPred] = [self::$kod, self::$slovnik];
        self::nastav($kod, $sada); // sada "admin-" = e-mail uživateli administrace v jazyce jeho administrace
        try {
            return $funkce();
        } finally {
            [self::$kod, self::$slovnik] = [$kodPred, $slovnikPred];
        }
    }

    /** Hodnota sloupce "jazyk" pro právě zobrazenou verzi webu ('' = výchozí jazyk). Jen '' nebo dvě malá písmena. */
    public static function sloupecWebu(): string
    {
        return self::$sloupec;
    }

    public static function kod(): string
    {
        return self::$kod;
    }

    public static function t(string $text, string|int ...$hodnoty): string
    {
        $preklad = self::$slovnik[$text] ?? $text;

        return $hodnoty === [] ? $preklad : sprintf($preklad, ...$hodnoty);
    }

    /** Výchozí jazyk webu. */
    public static function vychozi(Settings $s): string
    {
        return isset(self::DOSTUPNE[$s->get('jazyk_webu')]) ? $s->get('jazyk_webu') : 'cs';
    }

    /**
     * Další jazykové verze webu (bez výchozího jazyka); prázdné, když je rozšíření vypnuté.
     *
     * @return list<string>
     */
    public static function dalsi(Settings $s): array
    {
        if (!Rozsireni::je($s, 'jazyky')) {
            return [];
        }

        return array_values(array_diff(array_intersect(explode(',', $s->get('jazyky_dalsi')), array_keys(self::DOSTUPNE)), [self::vychozi($s)]));
    }

    /** Hodnota sloupce "jazyk" pro daný jazyk: výchozí jazyk webu se ukládá jako prázdný řetězec. */
    public static function sloupec(Settings $s, string $kod): string
    {
        return $kod === self::vychozi($s) || !in_array($kod, self::dalsi($s), true) ? '' : $kod;
    }
}
