<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Search index of articles: the column ka_novinky.hledani holds the text in lowercase without diacritics, so a reader
 * finds "nábřeží" even after typing "nabrezi". For locked articles only the title and the intro are indexed - so the
 * locked text cannot be pieced together from search results.
 */
final class Search
{
    /**
     * Lowercase without diacritics, only letters and digits separated by a space. Arabic and Hebrew letters stay (an Arabic
     * site is searchable), without the vowel marks and the elongation, and with one form of alef and ya – the way people type
     * a query. Latin text gives the same tokens as before.
     */
    public static function normalize(string $text): string
    {
        $text = remove_diacritics(mb_strtolower(html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $text)), ENT_QUOTES | ENT_HTML5)));
        if (preg_match('/[\x{0590}-\x{06FF}]/u', $text) === 1) {
            $text = strtr((string) preg_replace('/[\x{0591}-\x{05C7}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']);

            return trim((string) preg_replace('/[^a-z0-9\x{0621}-\x{064A}\x{0660}-\x{0669}\x{066E}-\x{06D3}\x{06F0}-\x{06F9}\x{05D0}-\x{05EA}]+/u', ' ', $text));
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Recomputes the index of one article; called after every save (admin, Claude). */
    public static function index(Db $db, int $idc): void
    {
        $c = $db->one('SELECT titulek, uvod, text, t_slova FROM {novinky} WHERE idc = ?', [$idc]);
        if ($c !== null) {
            $db->update('novinky', ['hledani' => self::normalize($c['titulek'] . ' ' . $c['t_slova'] . ' ' . $c['uvod'] . ' ' . $c['text'])], ['idc' => $idc]);
        }
    }

    /** Fills in the index for articles that do not have it yet (after a system update); in batches so it does not hold up the request. */
    public static function complete(Db $db, int $batch = 100): int
    {
        $ids = array_column($db->all('SELECT idc FROM {novinky} WHERE hledani IS NULL LIMIT ' . max(1, $batch)), 'idc');
        foreach ($ids as $idc) {
            self::index($db, (int) $idc);
        }

        return count($ids);
    }

    /**
     * Search in pages and collection items without an index (a company site has hundreds of them, not thousands): all words
     * of the query regardless of diacritics and letter case. Returns matches with an excerpt of the text around the first
     * word found, sorted by relevance (a match in the title weighs most, then the number of occurrences in the text; on a tie
     * the site's order stays).
     *
     * @param list<array{titulek: string, adresa: string, text: string}> $candidates
     * @return list<array{titulek: string, adresa: string, uryvek: string}>
     */
    public static function find(string $q, array $candidates, int $limit = 20): array
    {
        $words = array_values(array_filter(explode(' ', self::normalize($q)), fn (string $s): bool => strlen($s) >= 2));
        if ($words === []) {
            return [];
        }
        $results = [];
        $score = [];
        foreach ($candidates as $k) {
            $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $k['text'])), ENT_QUOTES | ENT_HTML5)));
            $search = self::normalize($k['titulek'] . ' ' . $plain);
            foreach ($words as $word) {
                if (!str_contains($search, $word)) {
                    continue 2;
                }
            }
            // excerpt: Czech characters map 1:1 without diacritics, so a position in the text without diacritics matches the original too
            $position = mb_strpos(remove_diacritics(mb_strtolower($plain)), $words[0]);
            $from = $position === false ? 0 : max(0, $position - 60);
            $excerpt = mb_substr($plain, $from, 180);
            $results[] = ['titulek' => $k['titulek'], 'adresa' => $k['adresa'], 'uryvek' => ($from > 0 ? '…' : '') . $excerpt . (mb_strlen($plain) > $from + 180 ? '…' : '')];
            $name = self::normalize($k['titulek']);
            $text = self::normalize($plain);
            $score[] = array_sum(array_map(fn (string $s): int => (str_contains($name, $s) ? 100 : 0) + min(20, substr_count($text, $s)), $words));
        }
        // stable sort: the same score = the order in which the site sorts them
        $order = array_keys($results);
        array_multisort($score, SORT_DESC, $order, SORT_ASC, $results);

        return array_slice($results, 0, $limit);
    }

    /** Query for MATCH … AGAINST in BOOLEAN mode: all words of 3 or more characters with any ending. */
    public static function query(string $q): string
    {
        $words = array_filter(explode(' ', self::normalize($q)), fn (string $s): bool => strlen($s) >= 3);

        return implode(' ', array_map(fn (string $s): string => '+' . $s . '*', array_slice($words, 0, 8)));
    }
}
