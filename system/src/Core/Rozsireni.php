<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Rozšíření - volitelné části systému, které administrátor zapíná a vypíná v Nastavení.
 *
 * Systém je záměrně uzavřený: všechna rozšíření jsou součástí balíčku a vznikají v projektu MiroCMS.
 * Cizí plug-iny se neinstalují. Vypnuté rozšíření zmizí z menu administrace i z webu, data zůstávají.
 */
final class Rozsireni
{
    /** klíč => [název, popis, zapnuto ve výchozím stavu] */
    public const array SEZNAM = [
        'statistika' => ['Statistika', 'Vlastní měření návštěvnosti bez cookies.', true],
        'presmerovani' => ['Přesměrování', 'Správa přesměrování 301 ze starých adres – po přechodu z jiného webu nezbytné.', true],
        'jazyky' => ['Jazykové verze webu', 'Web ve více jazycích: každá další verze (/en/…) má své stránky, kategorie a novinky, přepínač jazyků a značky hreflang. Jazyky vyberete v Nastavení → Základní.', false],
        'api' => ['Veřejné API', 'Čtecí JSON API pro jiný web nebo aplikaci: /api/novinky, /api/novinky/<adresa>, /api/kategorie, /api/stranky.', false],
        'asistent' => ['AI asistent v editoru', 'Návrhy titulků, perexu, SEO popisu a štítků, korektura a popisy obrázků přímo při psaní. Potřebuje vlastní klíč Claude API (níže); text se při použití posílá službě Anthropic.', false],
        'claude' => ['Napojení na Claude', 'MCP server na adrese /mcp: Claude umí s právy vašeho účtu číst a psát novinky a stránky a tvořit šablony webu. Přístupový token si každý vytvoří v nabídce Můj účet.', false],
    ];

    /** @return list<string> */
    public static function zapnuta(Settings $settings): array
    {
        $ulozeno = $settings->get('rozsireni');
        if ($ulozeno === '') {
            return array_keys(array_filter(self::SEZNAM, fn (array $r): bool => $r[2]));
        }

        return array_values(array_intersect(explode(',', $ulozeno), array_keys(self::SEZNAM)));
    }

    public static function je(Settings $settings, string $klic): bool
    {
        return $klic === '' || in_array($klic, self::zapnuta($settings), true);
    }

    /** @param list<string> $klice */
    public static function uloz(Settings $settings, array $klice): void
    {
        $klice = array_values(array_intersect($klice, array_keys(self::SEZNAM)));
        // prázdný řetězec znamená "výchozí stav", proto se prázdný výběr ukládá jako "-"
        $settings->set('rozsireni', $klice === [] ? '-' : implode(',', $klice));
    }
}
