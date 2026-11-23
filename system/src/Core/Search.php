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
     * site is searchable), without the vowel marks (harakat) and the elongation (tatweel), with one form of alef (أ إ آ ٱ → ا)
     * and ya (ى → ي), and – 3.12 – taa marbuta as ha (ة → ه), the way people type a query. Latin text gives the same tokens
     * as before. A change here needs the stored news index re-made (migration 0089 empties the Arabic rows, complete()
     * fills them again).
     */
    public static function normalize(string $text): string
    {
        $text = remove_diacritics(mb_strtolower(html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $text)), ENT_QUOTES | ENT_HTML5)));
        if (preg_match('/[\x{0590}-\x{06FF}]/u', $text) === 1) {
            $text = strtr((string) preg_replace('/[\x{0591}-\x{05C7}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

            return trim((string) preg_replace('/[^a-z0-9\x{0621}-\x{064A}\x{0660}-\x{0669}\x{066E}-\x{06D3}\x{06F0}-\x{06F9}\x{05D0}-\x{05EA}]+/u', ' ', $text));
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /**
     * A search of news in the admin list and MCP list_news (3.52, A7): each word in the title, the address or the index
     * (intro, text, keywords – normalize()d like the stored index, so diacritics do not matter there either). A word whose
     * normalized form is shorter than 2 letters ("C++" → "c") is looked for in the title and the address only – one letter
     * would match almost every index. $alias = the table alias with its dot.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function newsWords(string $q, string $alias = 'c.'): array
    {
        return Db::likeWords([$alias . 'titulek', $alias . 'seo_link'], $q, more: function (string $word) use ($alias): ?array {
            $normal = self::normalize($word);

            return mb_strlen($normal) < 2 ? null : [$alias . 'hledani LIKE ?', ['%' . addcslashes($normal, '%_\\') . '%']];
        });
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
     * The endings stem() takes off a query word (3.52, B2), longest first, in the normalized form (no diacritics). Czech and
     * Slovak cases and plurals (-ách = -ach, -ům = -um), English plurals, German plural and case endings. Only the query is
     * stemmed – the stored text stays as it is and a text word matches when it starts with the stem.
     */
    public const array ENDINGS = [
        'cs' => ['ami', 'ech', 'ach', 'ove', 'ovi', 'um', 'em', 'ou', 'y', 'a', 'e', 'i', 'u', 'o'],
        'sk' => ['ami', 'ach', 'och', 'ove', 'ovi', 'om', 'ov', 'em', 'ou', 'y', 'a', 'e', 'i', 'u', 'o'],
        'en' => ['es', 's'],
        'de' => ['en', 'er', 'es', 'e'],
    ];

    /** A stem keeps at least this many letters – a shorter word is searched as it is ("auto" stays "auto", "IT" stays "it"). */
    public const int STEM_MIN = 4;

    /**
     * A normalized query word without its ending (3.52, B2): "stavby" → "stavb", "koupelna" → "koupeln", "services" →
     * "servic". Only for cs, sk, en and de (other languages: the word itself); a word with a digit stays as it is.
     */
    public static function stem(string $word, string $language): string
    {
        $endings = self::ENDINGS[Language::base($language)] ?? [];
        if ($endings === [] || preg_match('/^[a-z]+$/D', $word) !== 1) {
            return $word;
        }
        foreach ($endings as $ending) {
            if (str_ends_with($word, $ending) && strlen($word) - strlen($ending) >= self::STEM_MIN) {
                return substr($word, 0, -strlen($ending));
            }
        }

        return $word;
    }

    /**
     * The beginnings a text word may have to match one query word (3.52, B2): the word, its stem and – in Czech and Slovak
     * – the stem with or without the mobile e: "koupelna" (koupeln) also finds "koupelen", "služby" (služb) "služeb" and
     * "služeb" (služeb) "služby". Normalized, without duplicates, the word itself first.
     *
     * @return list<string>
     */
    public static function terms(string $word, string $language): array
    {
        $stem = self::stem($word, $language);
        $terms = [$word, $stem];
        if (in_array(Language::base($language), ['cs', 'sk'], true) && preg_match('/^[a-z]+$/D', $stem) === 1) {
            $consonant = '[bcdfghjklmnpqrstvwxz]';
            if ($stem !== $word && preg_match('/' . $consonant . $consonant . '$/', $stem) === 1) {
                $terms[] = substr($stem, 0, -1) . 'e' . substr($stem, -1); // koupeln → koupelen
            }
            if (preg_match('/' . $consonant . 'e' . $consonant . '$/', $stem) === 1 && strlen($stem) - 1 >= self::STEM_MIN) {
                $terms[] = substr($stem, 0, -2) . substr($stem, -1); // sluzeb → sluzb
            }
        }

        return array_values(array_unique($terms));
    }
}
