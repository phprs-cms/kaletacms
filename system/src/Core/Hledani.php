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

    /** Dotaz pro MATCH … AGAINST v režimu BOOLEAN: všechna slova od 3 znaků s libovolnou koncovkou. */
    public static function dotaz(string $q): string
    {
        $slova = array_filter(explode(' ', self::normalizuj($q)), fn (string $s): bool => strlen($s) >= 3);

        return implode(' ', array_map(fn (string $s): string => '+' . $s . '*', array_slice($slova, 0, 8)));
    }
}
