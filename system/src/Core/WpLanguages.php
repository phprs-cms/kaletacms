<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Languages and translations of a multilingual WordPress export (3.9): what Polylang and WPML write into a WXR file, read
 * without trusting it. Pure functions – Core\WpFile collects the raw values, Core\WpImport decides what they become.
 *
 * Polylang (taxonomies, so the WordPress exporter carries them):
 *  - every language is a term of the taxonomy `language` (slug cs, en…; the description is a serialized array with the
 *    locale cs_CZ), and a post names its language as <category domain="language" nicename="en">;
 *  - posts translated into each other share a term of `post_translations` (named uniqid('pll_')), whose description is a
 *    serialized map language slug => post id; a post names its group as <category domain="post_translations" nicename="pll_…">;
 *  - categories and tags share a term of `term_translations` the same way (language slug => term id). The language of a
 *    term itself is a relationship the exporter does not write, so a term without translations gets the language of the
 *    posts that use it. A group is never made for a single object (Polylang's should_update_translation_group()).
 * WPML keeps languages in its own table (icl_translations), which the core exporter does not export. Its "Export and
 * Import" add-on writes them as custom fields – postmeta of posts and termmeta of terms:
 *  - _wpml_import_language_code (en, pt-br, zh-hans…), _wpml_import_translation_group (shared by an original and its
 *    translations) and _wpml_import_source_language_code (empty on the original);
 *  - a post duplicated by WPML keeps _icl_lang_duplicate_of = the id of its original.
 * Without either, a multilingual site still shows the language in the address (/en/…, ?lang=en) – urlLanguage().
 *
 * The serialized descriptions are parsed by a small reader of PHP's serialize() format (scalars and nested arrays only),
 * never by unserialize(): the file comes from another site.
 */
final class WpLanguages
{
    public const string POLYLANG = 'polylang';
    public const string WPML = 'wpml';

    /** The custom fields of WPML Export and Import (postmeta of posts, termmeta of terms). */
    public const string WPML_LANGUAGE = '_wpml_import_language_code';
    public const string WPML_GROUP = '_wpml_import_translation_group';
    public const string WPML_DUPLICATE = '_icl_lang_duplicate_of';

    /** Taxonomies of the WPML core on posts – only a sign that the site was multilingual. */
    public const array WPML_TAXONOMIES = ['translation_priority'];

    /** The longest serialized description that is read (a translation group of 40 languages is about 1 kB). */
    private const int MAX_SERIALIZED = 20000;

    /**
     * Our language code (a key of Language::AVAILABLE) for a WordPress language: the locale first (cs_CZ – a Polylang
     * slug may be "cz"), then the code or slug itself (en, pt-br, zh-hans, nb → no); null = a language this site cannot offer.
     */
    public static function code(string $raw, string $locale = ''): ?string
    {
        foreach ([$locale, $raw] as $value) {
            $value = strtolower(trim($value));
            if ($value === '') {
                continue;
            }
            if (isset(Language::AVAILABLE[$value])) {
                return $value;
            }
            $base = (string) preg_split('/[-_]/', $value)[0];
            $base = in_array($base, ['nb', 'nn'], true) ? 'no' : $base;
            if (isset(Language::AVAILABLE[$base])) {
                return $base;
            }
        }

        return null;
    }

    /**
     * The language a link names: the first segment of its path (/en/about/) or the Polylang parameter (?lang=en), when it
     * is one of $codes; '' = none.
     *
     * @param list<string> $codes
     */
    public static function urlLanguage(string $link, array $codes): string
    {
        $query = [];
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $lang = is_string($query['lang'] ?? null) ? strtolower($query['lang']) : '';
        if ($lang !== '' && in_array($lang, $codes, true)) {
            return $lang;
        }
        $first = strtolower(explode('/', trim((string) parse_url($link, PHP_URL_PATH), '/'))[0]);

        return $first !== '' && in_array($first, $codes, true) ? $first : '';
    }

    /**
     * A Polylang translation group (the description of a post_translations or term_translations term): language slug =>
     * id. Other keys (Polylang Pro keeps "sync" there) and anything that is not a positive id are left out.
     *
     * @return array<string, int>
     */
    public static function translationMap(string $serialized): array
    {
        $map = [];
        foreach (self::unserializeArray($serialized) ?? [] as $language => $id) {
            if (is_string($language) && preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/iD', $language) === 1 && (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $map[strtolower($language)] = (int) $id;
            }
        }

        return $map;
    }

    /** The locale of a Polylang language term (the description is a serialized array with "locale"); '' = none. */
    public static function locale(string $serialized): string
    {
        $locale = (self::unserializeArray($serialized) ?? [])['locale'] ?? '';

        return is_string($locale) && preg_match('/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8})*$/D', $locale) === 1 ? $locale : '';
    }

    /**
     * The language of a menu by the languages of what it links to (a vote; ties go to the first language reached): Polylang
     * keeps one menu per language and assigns them to theme locations in its options, which the export does not contain.
     *
     * @param list<string> $languages the language of each item that has one
     */
    public static function majority(array $languages): string
    {
        $votes = array_count_values(array_filter($languages, fn (string $l): bool => $l !== ''));
        $best = '';
        foreach ($votes as $language => $count) {
            if ($best === '' || $count > $votes[$best]) {
                $best = (string) $language;
            }
        }

        return $best;
    }

    /**
     * Reads a value written by PHP's serialize() when it is an array of scalars and nested arrays (at most four levels and
     * 1,000 entries); null = anything else (an object, a reference, a damaged or too long string).
     *
     * @return array<array-key, mixed>|null
     */
    public static function unserializeArray(string $serialized): ?array
    {
        if ($serialized === '' || strlen($serialized) > self::MAX_SERIALIZED || $serialized[0] !== 'a') {
            return null;
        }
        $position = 0;
        try {
            $value = self::readValue($serialized, $position, 0);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return is_array($value) && $position === strlen($serialized) ? $value : null;
    }

    /** @throws \UnexpectedValueException */
    private static function readValue(string $s, int &$position, int $depth): mixed
    {
        $match = function (string $pattern) use ($s, &$position): array {
            if (preg_match($pattern . 'A', $s, $m, 0, $position) !== 1) {
                throw new \UnexpectedValueException('not a serialized value');
            }
            $position += strlen($m[0]);

            return $m;
        };
        switch ($s[$position] ?? '') {
            case 'N':
                $match('/N;/');

                return null;
            case 'b':
                return $match('/b:([01]);/')[1] === '1';
            case 'i':
                return (int) $match('/i:(-?\d{1,18});/')[1];
            case 'd':
                return (float) $match('/d:(-?[0-9.Ee+-]{1,40}|NAN|-?INF);/')[1];
            case 's':
                $length = (int) $match('/s:(\d{1,6}):"/')[1];
                if (substr($s, $position + $length, 2) !== '";') {
                    throw new \UnexpectedValueException('a string of a wrong length');
                }
                $value = substr($s, $position, $length);
                $position += $length + 2;

                return $value;
            case 'a':
                $count = (int) $match('/a:(\d{1,4}):\{/')[1];
                if ($depth >= 4 || $count > 1000) {
                    throw new \UnexpectedValueException('too deep or too long');
                }
                $array = [];
                for ($i = 0; $i < $count; $i++) {
                    $key = self::readValue($s, $position, $depth + 1);
                    if (!is_int($key) && !is_string($key)) {
                        throw new \UnexpectedValueException('a key must be a number or a string');
                    }
                    $array[$key] = self::readValue($s, $position, $depth + 1);
                }
                $match('/\}/');

                return $array;
            default:
                throw new \UnexpectedValueException('an object, a reference or an unknown type');
        }
    }
}
