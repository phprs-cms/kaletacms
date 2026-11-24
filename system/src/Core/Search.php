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
     * The version of normalize() (3.53): what the stored indexes were made with. A change of normalize() that changes the
     * tokens of some text raises it, and the stored indexes are re-made (Core\SearchIndex). 1 = up to 3.52 (Latin, Arabic
     * and Hebrew only), 2 = every script, the Latin letters of every European alphabet and Vietnamese folded.
     */
    public const int VERSION = 2;

    /**
     * The Latin letters remove_diacritics() does not know (3.53, A1), by the plain letters they fold to: Polish,
     * Romanian (comma and cedilla forms), Turkish, Latvian, Lithuanian, Maltese, Icelandic, Esperanto, Catalan (l·l),
     * pinyin and Vietnamese, ligatures. Lower case only – fold() lowers first. Cyrillic letters written with a combining
     * mark (NFD) are composed again here, before the marks go: й, ї and ў stay letters of their own.
     */
    private const array FOLD_LETTERS = [
        'a' => 'ąăāảạằắẳẵặầấẩẫậǎªȧ', 'c' => 'ċĉ', 'd' => 'ð', 'e' => 'ęēėẻẽẹềếểễệȩ', 'g' => 'ğģġĝ', 'h' => 'ħĥ',
        'i' => 'ıīįỉịǐĩ', 'j' => 'ĵ', 'k' => 'ķ', 'l' => 'ļŀ', 'n' => 'ņ', 'o' => 'ōỏọồốổỗộơờớởỡợǒŏºȯ',
        's' => 'șşŝſ', 't' => 'țţ', 'u' => 'ūųủụưừứửữựǔǖǘǚǜũŭ', 'y' => 'ỳỷỹỵȳ',
    ];

    /** Letters that fold to more than one (3.53, A1); Cyrillic NFD forms composed (see FOLD_LETTERS). */
    private const array FOLD_MORE = [
        'þ' => 'th', 'œ' => 'oe', 'l·l' => 'll', 'ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', 'ﬅ' => 'st', 'ﬆ' => 'st',
        "и\u{0306}" => 'й', "і\u{0308}" => 'ї', "у\u{0306}" => 'ў',
    ];

    /**
     * Letters of other scripts normalize() folds the way people type a query (3.53, A1): ё as е, Greek without the
     * accent (tonos, dialytika) and the final sigma as σ; superscript digits as digits (m² → m2). й, і, ї, є, ґ, ъ stay.
     */
    private const array SCRIPT_FOLD = [
        'ё' => 'е', 'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ϋ' => 'υ',
        'ΐ' => 'ι', 'ΰ' => 'υ', 'ς' => 'σ', '¹' => '1', '²' => '2', '³' => '3',
    ];

    /**
     * Lower case without diacritics, Latin letters folded to plain ones (3.53, A1): mb_strtolower first, then
     * remove_diacritics(), then FOLD_LETTERS / FOLD_MORE, then the combining marks U+0300–036F go – after lower-casing, so
     * "İ" (i + U+0307) becomes "i", and a text written decomposed (NFD) folds as the composed one. No intl: a static table
     * gives the same index on every host. remove_diacritics() itself stays as it is (stored keys elsewhere use it).
     * Other characters pass unchanged.
     */
    public static function fold(string $text): string
    {
        static $table = null;
        if ($table === null) {
            $table = self::FOLD_MORE;
            foreach (self::FOLD_LETTERS as $plain => $letters) {
                foreach (mb_str_split($letters) as $letter) {
                    $table[$letter] = $plain;
                }
            }
        }

        return (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', strtr(remove_diacritics(mb_strtolower($text)), $table));
    }

    /**
     * Lower case without diacritics, only letters and digits separated by a space (version 2, 3.53). Letters of every
     * script stay (Cyrillic, Greek, CJK, Hangul, Devanagari…), with digits (\p{Nd} only – superscripts become digits);
     * Latin letters through fold(), full-width ASCII as ASCII, half-width kana as full-width (mb_convert_kana), ё as е,
     * Greek without accents and with one sigma. Combining marks of other scripts stay (Devanagari vowel signs), the Latin
     * ones and the Arabic and Hebrew ones go. Arabic and Hebrew as before: without the vowel marks (harakat) and the
     * elongation (tatweel), with one form of alef (أ إ آ ٱ → ا) and ya (ى → ي), and – 3.12 – taa marbuta as ha (ة → ه), the
     * way people type a query. Typical Czech, Slovak, English, German and Arabic text gives the same tokens as version 1;
     * a change here raises VERSION (the stored indexes are re-made).
     */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], $text)), ENT_QUOTES | ENT_HTML5);
        $text = strtr(self::fold(mb_convert_kana($text, 'asKV', 'UTF-8')), self::SCRIPT_FOLD);
        if (preg_match('/[\x{0590}-\x{06FF}]/u', $text) === 1) {
            $text = strtr((string) preg_replace('/[\x{0591}-\x{05C7}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        }
        // the remaining Arabic marks (Quranic signs) and the extended Latin combining ranges separate as before
        $text = (string) preg_replace('/[\x{0610}-\x{061A}\x{06D6}-\x{06ED}\x{1AB0}-\x{1AFF}\x{1DC0}-\x{1DFF}\x{20D0}-\x{20FF}\x{FE20}-\x{FE2F}]/u', ' ', $text);

        return trim((string) preg_replace('/[^\p{L}\p{Nd}\p{M}]+/u', ' ', $text));
    }

    /**
     * A search of news in the admin list and MCP list_news (3.52, A7): each word in the title, the address or the index
     * (intro, text, keywords – normalize()d like the stored index, so diacritics do not matter there either). A word whose
     * normalized form is shorter than 2 letters is no filter when it is a plain letter or digit ("dům v Praze"); a word with
     * punctuation ("C++" → "c") is looked for in the title and the address only – one letter would match almost every index. $alias = the table alias with its dot.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function newsWords(string $q, string $alias = 'c.'): array
    {
        return Db::likeWords([$alias . 'titulek', $alias . 'seo_link'], $q, more: function (string $word) use ($alias): ?array {
            $normal = self::normalize($word);

            if (self::longEnough($normal)) { // 3.53 (A5): one Han, Hangul or Kana character is a word too
                return [$alias . 'hledani LIKE ?', ['%' . addcslashes($normal, '%_\\') . '%']];
            }

            // A one-letter word ("v", "k", "5") narrows nothing, so it lets every row pass; "C++" stays in the title.
            return preg_match('/^[\p{L}\p{N}]+$/u', $word) === 1 ? ['1 = 1', []] : null;
        });
    }

    /**
     * Is a query (or one word of it) long enough to be searched (3.53, A5) – the one gate of the site search, the admin
     * search, the palette and MCP: two or more characters, or one Chinese, Japanese or Korean character (a whole word
     * there). One Latin letter or digit is not.
     */
    public static function longEnough(string $q): bool
    {
        $q = trim($q);

        return mb_strlen($q) >= 2 || preg_match('/^[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}]$/u', $q) === 1;
    }

    /** Recomputes the index of one article; called after every save (admin, Claude). The text: SearchIndex::text (3.53). */
    public static function index(Db $db, int $idc): void
    {
        $c = $db->one('SELECT titulek, uvod, text, t_slova FROM {novinky} WHERE idc = ?', [$idc]);
        if ($c !== null) {
            // derived data: never in the undo journal of a Claude session (3.53, A4 – the undo empties it instead)
            $db->unjournaled(fn () => $db->run('UPDATE {novinky} SET hledani = ? WHERE idc = ?', [SearchIndex::text('novinky', $c), $idc]));
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
