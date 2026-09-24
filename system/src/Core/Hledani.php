<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Vyhledávací index článků: sloupec mc_novinky.hledani drží text malými písmeny bez diakritiky, takže čtenář
 * najde "nábřeží" i po zadání "nabrezi". U zamčených článků se indexuje jen titulek a perex - z výsledků
 * hledání tak nejde po kouskách vyčíst zamčený text.
 */
final class Hledani
{
    /** Malá písmena bez diakritiky, jen písmena a číslice oddělené mezerou. */
    public static function normalizuj(string $text): string
    {
        $text = bez_diakritiky(mb_strtolower(html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $text)), ENT_QUOTES | ENT_HTML5)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Přepočítá index jednoho článku; volá se po každém uložení (administrace, Claude). */
    public static function indexuj(Db $db, int $idc): void
    {
        $c = $db->one('SELECT titulek, uvod, text, t_slova FROM {novinky} WHERE idc = ?', [$idc]);
        if ($c !== null) {
            $db->update('novinky', ['hledani' => self::normalizuj($c['titulek'] . ' ' . $c['t_slova'] . ' ' . $c['uvod'] . ' ' . $c['text'])], ['idc' => $idc]);
        }
    }

    /** Doplní index článkům, které ho ještě nemají (po aktualizaci systému); po dávkách, aby nezdržel požadavek. */
    public static function dopln(Db $db, int $davka = 100): int
    {
        $ids = array_column($db->all('SELECT idc FROM {novinky} WHERE hledani IS NULL LIMIT ' . max(1, $davka)), 'idc');
        foreach ($ids as $idc) {
            self::indexuj($db, (int) $idc);
        }

        return count($ids);
    }

    /**
     * Hledání ve stránkách a položkách kolekcí bez indexu (je jich na firemním webu stovky, ne tisíce): všechna slova
     * dotazu bez ohledu na diakritiku a velikost písmen. Vrací shody s úryvkem textu kolem prvního nalezeného slova.
     *
     * @param list<array{titulek: string, adresa: string, text: string}> $kandidati
     * @return list<array{titulek: string, adresa: string, uryvek: string}>
     */
    public static function najdi(string $q, array $kandidati, int $limit = 20): array
    {
        $slova = array_values(array_filter(explode(' ', self::normalizuj($q)), fn (string $s): bool => strlen($s) >= 2));
        if ($slova === []) {
            return [];
        }
        $vysledky = [];
        foreach ($kandidati as $k) {
            $prosty = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $k['text'])), ENT_QUOTES | ENT_HTML5)));
            $hledat = self::normalizuj($k['titulek'] . ' ' . $prosty);
            foreach ($slova as $slovo) {
                if (!str_contains($hledat, $slovo)) {
                    continue 2;
                }
            }
            // úryvek: české znaky se bez diakritiky mapují 1:1, pozice v textu bez diakritiky tedy sedí i v originále
            $pozice = mb_strpos(bez_diakritiky(mb_strtolower($prosty)), $slova[0]);
            $od = $pozice === false ? 0 : max(0, $pozice - 60);
            $uryvek = mb_substr($prosty, $od, 180);
            $vysledky[] = ['titulek' => $k['titulek'], 'adresa' => $k['adresa'], 'uryvek' => ($od > 0 ? '…' : '') . $uryvek . (mb_strlen($prosty) > $od + 180 ? '…' : '')];
            if (count($vysledky) >= $limit) {
                break;
            }
        }

        return $vysledky;
    }

    /** Dotaz pro MATCH … AGAINST v režimu BOOLEAN: všechna slova od 3 znaků s libovolnou koncovkou. */
    public static function dotaz(string $q): string
    {
        $slova = array_filter(explode(' ', self::normalizuj($q)), fn (string $s): bool => strlen($s) >= 3);

        return implode(' ', array_map(fn (string $s): string => '+' . $s . '*', array_slice($slova, 0, 8)));
    }
}
