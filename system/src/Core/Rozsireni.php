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
        'novinky' => ['Novinky', 'Krátké zprávy redakce v bloku Novinky.', false],
        'komentare' => ['Komentáře a hodnocení', 'Diskuse pod články s moderací a antispamem, hodnocení článků hvězdičkami.', true],
        'ankety' => ['Ankety', 'Anketa v bloku na webu, jeden hlas na čtenáře.', false],
        'statistika' => ['Statistika', 'Vlastní měření návštěvnosti bez cookies.', true],
        'presmerovani' => ['Přesměrování', 'Správa přesměrování 301 ze starých adres.', true],
        'newsletter' => ['Newsletter', 'Přihlášení k odběru s potvrzením e-mailem a rozesílka výběru článků odběratelům.', false],
        'jazyky' => ['Jazykové verze webu', 'Web ve více jazycích: každá další verze (/en/, /de/…) má své rubriky, články a stránky, přepínač jazyků a značky hreflang. Jazyky vyberete v Nastavení → Základní.', false],
        'push' => ['Oznámení v prohlížeči', 'Web Push: čtenář si jedním klepnutím zapne oznámení o nových článcích (blok Oznámení). Bez cizí služby a bez osobních údajů; po vydání článku se rozešle samo.', false],
        'ctenari' => ['Čtenáři a zamčený obsah', 'Registrace čtenářů, články jen pro přihlášené nebo pro předplatitele s ukázkou pro ostatní. Předplatné si čtenáři platí přes Stripe, nebo ho zapisuje administrátor ručně.', false],
        'api' => ['Veřejné API', 'Čtecí JSON API pro mobilní aplikaci nebo jiný web: /api/clanky, /api/clanky/<adresa>, /api/rubriky.', false],
        'asistent' => ['AI asistent v editoru', 'Návrhy titulků, perexu, shrnutí, SEO popisu a štítků, korektura a popisy obrázků přímo při psaní článku. Potřebuje vlastní klíč Claude API (níže); text článku se při použití posílá službě Anthropic.', false],
        'claude' => ['Napojení na Claude', 'MCP server na adrese /mcp: Claude umí s právy vašeho účtu číst a psát články, spravovat bloky a tvořit šablony webu. Přístupový token si každý vytvoří v nabídce Můj účet.', false],
        'reklama' => ['Reklamní systém', 'Bannery a reklamní kódy v blocích a pod články, plánování, počítání zobrazení a prokliků, ads.txt.', false],
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
