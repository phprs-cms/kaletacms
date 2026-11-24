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

    /** Chinese characters and kana – words without spaces between them (SiteSearch matches them anywhere); a regex class. */
    public const string CJK = '\p{Han}\p{Hiragana}\p{Katakana}\x{30FC}';

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

        $text = trim((string) preg_replace('/[^\p{L}\p{Nd}\p{M}]+/u', ' ', $text));

        // Chinese and Japanese have no spaces around a Latin word or a number ("使用WordPress搭建", "价格100元"), Korean none
        // before a particle: a run of CJK is cut off its neighbours, Hangul off Latin letters and digits, so "wordpress",
        // "100" and "s24" (갤럭시S24) are words of their own, as the query reads them (SiteSearch::words)
        return (string) preg_replace('/(?<=[' . self::CJK . '])(?=[\p{L}\p{Nd}])(?![' . self::CJK . '])|(?<=[\p{L}\p{Nd}])(?<![' . self::CJK . '])(?=[' . self::CJK . '])'
            . '|(?<=\p{Hangul})(?=[\p{Latin}\p{Nd}])|(?<=[\p{Latin}\p{Nd}])(?=\p{Hangul})/u', ' ', $text);
    }

    /**
     * A search of news in the admin list, the palette and MCP list_news (3.52, A7; 3.53, B1): each word in the title, the
     * address or the index (intro, text, keywords – normalize()d like the stored index, so diacritics do not matter there
     * either). A word whose normal form has several pieces ("R&B" → "r b", "e-mail", "s.r.o.") is a phrase: the pieces in a
     * row from the start of a word, so "R&B" does not find "sektor bank". One character of a spaced script ("dům v Praze",
     * "5") is no filter; a word that normalizes to one letter or to nothing ("C++", "—") is looked for in the title and the
     * address only – one letter would match almost every index. One Han, Hangul or Kana character is a word. A word of any
     * script reaches the index (3.53, A1), so no query lets every news item through. $alias = the table alias with its dot.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function newsWords(string $q, string $alias = 'c.'): array
    {
        return self::indexWords($q, $alias, ['titulek', 'seo_link']);
    }

    /**
     * newsWords() for any table with a hledani index (3.53, B4): each word in $columns (the search collation) or in the
     * index by its normal form, with the same rules for phrases, one character and words without a normal form. Pages
     * and collection items may have rows not indexed yet (hledani NULL – right after the update, an import): those are
     * searched in $text (collation LIKE) and $json (Db::likeJson) instead, as 3.52 did. $indexed false (the database before
     * migration 0141, SearchIndex::ready) = the 3.52 search of $columns, $text and $json only. $alias = the alias with its dot.
     *
     * @param list<string> $columns
     * @param list<string> $text
     * @param list<string> $json
     * @return array{0: string, 1: list<string>}
     */
    public static function indexWords(string $q, string $alias, array $columns, bool $indexed = true, array $text = [], array $json = []): array
    {
        $with = fn (array $names): array => array_map(fn (string $c): string => $alias . $c, $names);
        if (!$indexed) {
            return Db::likeWords($with([...$columns, ...$text]), $q, $with($json));
        }

        return Db::likeWords($with($columns), $q, more: function (string $word) use ($alias, $with, $text, $json): ?array {
            $normal = self::normalize($word);
            $index = match (true) {
                str_contains($normal, ' ') && preg_match('/[' . self::CJK . ']/u', $normal) === 1 => self::mixedCjk($normal, $alias . 'hledani'),
                str_contains($normal, ' ') => ['CONCAT(\' \', ' . $alias . 'hledani) LIKE ?', ['% ' . addcslashes($normal, '%_\\') . '%']],
                self::longEnough($normal) => [$alias . 'hledani LIKE ?', ['%' . addcslashes($normal, '%_\\') . '%']],
                $normal !== '' && preg_match('/^[\p{L}\p{N}]$/u', $word) === 1 => ['1 = 1', []],
                default => null,
            };
            if ($index === null || $index[0] === '1 = 1' || ($text === [] && $json === [])) {
                return $index;
            }
            // a row not indexed yet: the word in its text and fields, as before the index (removed with the fallback in 3.54)
            [$late, $params] = Db::likeWords($with($text), $word, $with($json), maxWords: 1);

            return ['(' . $index[0] . ' OR (' . $alias . 'hledani IS NULL AND ' . $late . '))', [...$index[1], ...$params]];
        });
    }

    /**
     * A word with Chinese or Japanese next to Latin letters or digits ("使用WordPress" → "使用 wordpress"): the CJK run need not
     * start a run of the text ("我们使用 wordpress…"), so its pieces are ANDed – a CJK piece anywhere, any other piece from
     * the start of a word; a piece too short is no filter. null = no piece long enough.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    private static function mixedCjk(string $normal, string $column): ?array
    {
        [$sql, $params] = [[], []];
        foreach (explode(' ', $normal) as $piece) {
            if (!self::longEnough($piece)) {
                continue;
            }
            $cjk = preg_match('/[' . self::CJK . ']/u', $piece) === 1;
            $sql[] = $cjk ? $column . ' LIKE ?' : 'CONCAT(\' \', ' . $column . ') LIKE ?';
            $params[] = ($cjk ? '%' : '% ') . addcslashes($piece, '%_\\') . '%';
        }

        return $sql === [] ? null : ['(' . implode(' AND ', $sql) . ')', $params];
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
     * The endings stem() takes off a query word (3.52, B2; 3.53, B3), longest first, in the normalized form (no diacritics;
     * Cyrillic as written, ё as е). Czech, Slovak and Polish cases, plurals and adjective endings (-ách = -ach, -ów = -ow),
     * English and French plurals, German plural and case endings, Hungarian case suffixes (one round), Spanish and Portuguese
     * plurals (then SECOND_ROUND), Italian plurals, Russian and Ukrainian cases. Only the query is stemmed – the stored
     * text stays as it is and a text word matches when it starts with the stem. Every other language of Language::CODES is
     * in NO_STEMMER (a unit test checks both lists cover them all).
     */
    public const array ENDINGS = [
        'cs' => ['ami', 'ech', 'ach', 'ove', 'ovi', 'eho', 'emu', 'iho', 'imu', 'ymi', 'imi', 'ych', 'ich', 'um', 'em', 'ou', 'ym', 'im', 'y', 'a', 'e', 'i', 'u', 'o'],
        'sk' => ['ami', 'ach', 'och', 'ove', 'ovi', 'eho', 'emu', 'iho', 'imu', 'ymi', 'ych', 'ich', 'om', 'ov', 'em', 'ou', 'ym', 'im', 'y', 'a', 'e', 'i', 'u', 'o'],
        'pl' => ['ami', 'ach', 'ego', 'emu', 'ymi', 'imi', 'ych', 'ich', 'iem', 'owi', 'ow', 'om', 'em', 'ej', 'ym', 'im', 'ie', 'a', 'e', 'i', 'o', 'u', 'y'],
        'en' => ['ies', 'es', 's'],
        'de' => ['ern', 'en', 'er', 'em', 'es', 'e', 'n', 's'],
        'hu' => ['nak', 'nek', 'ban', 'ben', 'bol', 'rol', 'tol', 'hoz', 'hez', 'val', 'vel', 'nal', 'nel', 'ba', 'be', 'ra', 're', 'ig', 'ok', 'ek', 'ak', 'ot', 'et', 'at', 'on', 'en', 'k', 't', 'n'],
        'es' => ['es', 's'],
        'pt' => ['oes', 'aes', 'ais', 'eis', 'es', 's'],
        'fr' => ['euses', 'euse', 'eaux', 'aux', 'ees', 'es', 'ee', 's', 'x', 'e'],
        'it' => ['ioni', 'ione', 'i', 'e', 'a', 'o'],
        'ru' => ['ями', 'ами', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ых', 'их', 'ая', 'яя', 'ое', 'ее', 'ой', 'ей', 'ий', 'ый', 'ую', 'юю', 'ом', 'ем', 'ам', 'ям', 'ах', 'ях', 'ов', 'ев', 'а', 'я', 'о', 'е', 'ы', 'и', 'у', 'ю', 'ь'],
        'uk' => ['ями', 'ами', 'ого', 'ому', 'ими', 'ах', 'ях', 'ів', 'ям', 'ам', 'ою', 'ею', 'ом', 'ем', 'ій', 'ий', 'их', 'ої', 'ая', 'а', 'я', 'о', 'е', 'и', 'і', 'у', 'ю', 'ь', 'ї'],
    ];

    /** Spanish and Portuguese (3.53, B3): after the plural the gender vowel goes too – "servicios" → "servicio" → "servici". */
    public const array SECOND_ROUND = ['es' => ['a', 'o', 'e'], 'pt' => ['a', 'o', 'e']];

    /**
     * The languages of Language::CODES without endings (3.53, B3): a query word is matched by prefix only – it finds the
     * longer forms of a base form, not the other way round. Cheap stemmers for them were not prototyped (docs: limits).
     */
    public const array NO_STEMMER = ['bg', 'bs', 'ca', 'da', 'el', 'et', 'fi', 'ga', 'hr', 'is', 'lt', 'lv', 'mk', 'mt', 'nl', 'no', 'ro', 'sl', 'sq', 'sr', 'sv', 'tr',
        'hi', 'id', 'ar', 'ja', 'ko', 'vi', 'zh'];

    /** Languages written in Cyrillic themselves: a Cyrillic word there keeps its own rules (none for bg, bs, mk, sr), elsewhere it is Russian. */
    private const array CYRILLIC = ['ru', 'uk', 'bg', 'bs', 'mk', 'sr'];

    /** A stem keeps at least this many letters – a shorter word is searched as it is ("auto" stays "auto", "IT" stays "it"). */
    public const int STEM_MIN = 4;

    /**
     * A normalized query word without its ending (3.52, B2; 3.53, B3): "stavby" → "stavb", "uslugami" → "uslug", "services"
     * → "servic", "москве" → "москв". The ending list is picked by the language and by the script of the word: a Cyrillic
     * word on a site of a Latin language is stemmed as Russian, a Latin word on a Russian site is not stemmed, Serbian is
     * not stemmed in either script. Characters, not bytes (mb_*); a word with a digit stays as it is. The stem is always a
     * prefix of the word with at least STEM_MIN letters, or the word itself.
     */
    public static function stem(string $word, string $language): string
    {
        if (preg_match('/^\p{L}+$/u', $word) !== 1) {
            return $word;
        }
        $key = Language::base($language);
        if (preg_match('/^\p{Cyrillic}+$/u', $word) === 1 && !in_array($key, self::CYRILLIC, true)) {
            $key = 'ru';
        }
        $stem = self::cut($word, self::ENDINGS[$key] ?? []);

        return self::cut($stem, self::SECOND_ROUND[$key] ?? []);
    }

    /** @param list<string> $endings the word without the first of the endings it has, when STEM_MIN letters stay */
    private static function cut(string $word, array $endings): string
    {
        $length = mb_strlen($word);
        foreach ($endings as $ending) {
            if (str_ends_with($word, $ending) && $length - mb_strlen($ending) >= self::STEM_MIN) {
                return mb_substr($word, 0, $length - mb_strlen($ending));
            }
        }

        return $word;
    }

    /**
     * The beginnings a text word may have to match one query word (3.52, B2): the word, its stem and – in Czech, Slovak
     * and (3.53, B3) Polish – the stem with or without the mobile e: "koupelna" (koupeln) also finds "koupelen", "služby" (služb) "služeb" and
     * "služeb" (služeb) "služby". Normalized, without duplicates, the word itself first.
     *
     * @return list<string>
     */
    public static function terms(string $word, string $language): array
    {
        $stem = self::stem($word, $language);
        $terms = [$word, $stem];
        if (in_array(Language::base($language), ['cs', 'sk', 'pl'], true) && preg_match('/^[a-z]+$/D', $stem) === 1) {
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
