<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Bezpečný dialekt šablon: co smí obsahovat PHP soubor šablony ukládaný přes napojení na Claude.
 *
 * Šablona je prezentační vrstva - vypisuje data, která jí systém předal. Nesmí sahat na soubory, databázi,
 * síť ani na kód systému. Kontrola je povolovací (whitelist): co není výslovně dovoleno, neprojde.
 * Vestavěné šablony dialektem projít musí (hlídá tools/testy.php), takže jejich kopie jdou dál upravovat.
 */
final class SablonaKontrola
{
    /** Funkce, které šablona smí volat. */
    private const array FUNKCE = [
        // pomocné funkce systému
        'e', 't', 'datum', 'datum_slovy', 'cislo', 'slugify', 'bez_diakritiky',
        // text
        'strlen', 'mb_strlen', 'substr', 'mb_substr', 'mb_strimwidth', 'mb_strtolower', 'mb_strtoupper', 'mb_str_split', 'strtolower', 'strtoupper', 'ucfirst', 'ucwords', 'lcfirst',
        'trim', 'ltrim', 'rtrim', 'nl2br', 'strip_tags', 'htmlspecialchars', 'htmlspecialchars_decode', 'html_entity_decode', 'sprintf', 'number_format', 'str_replace', 'str_contains',
        'str_starts_with', 'str_ends_with', 'str_repeat', 'str_pad', 'strpos', 'mb_strpos', 'strrpos', 'strstr', 'strrchr', 'strtr', 'wordwrap', 'implode', 'explode', 'join', 'str_split',
        'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split', 'preg_quote', 'rawurlencode', 'urlencode', 'http_build_query', 'parse_url', 'json_encode',
        'pathinfo', 'basename', 'strcmp', 'strcasecmp', 'strnatcmp', 'strnatcasecmp', 'substr_count', 'mb_substr_count', 'strrev', 'nl_langinfo', 'md5', 'crc32',
        // čísla a čas
        'count', 'round', 'floor', 'ceil', 'min', 'max', 'abs', 'intdiv', 'intval', 'floatval', 'strval', 'boolval', 'date', 'time', 'strtotime', 'mktime', 'checkdate', 'range',
        // pole
        'in_array', 'array_map', 'array_filter', 'array_slice', 'array_keys', 'array_values', 'array_column', 'array_merge', 'array_key_exists', 'array_key_first', 'array_key_last',
        'array_chunk', 'array_reverse', 'array_sum', 'array_unique', 'array_search', 'array_fill_keys', 'array_fill', 'array_flip', 'array_pad', 'array_combine', 'array_shift', 'array_pop',
        'array_unshift', 'array_push', 'array_splice', 'array_diff', 'array_intersect', 'array_intersect_key', 'array_diff_key', 'array_reduce', 'array_walk', 'sort', 'rsort', 'usort',
        'uasort', 'uksort', 'ksort', 'krsort', 'asort', 'arsort', 'shuffle', 'current', 'key', 'next', 'reset', 'end',
        // typy
        'is_array', 'is_string', 'is_int', 'is_float', 'is_numeric', 'is_bool', 'is_null', 'is_callable', 'ctype_digit',
    ];

    /** Funkce, které berou zpětné volání: [funkce => pořadí argumentu]. Argument musí být uzávěr nebo povolená funkce. */
    private const array ZPETNA_VOLANI = ['array_map' => 0, 'array_filter' => 1, 'usort' => 1, 'uasort' => 1, 'uksort' => 1, 'array_walk' => 1, 'array_reduce' => 1, 'preg_replace_callback' => 1];

    /** Metody objektů, které šablona dostává ($web = nastavení webu jen ke čtení). */
    private const array METODY = ['get', 'int', 'bool'];

    /** Proměnné s funkcí, které šabloně předává systém. */
    private const array PREDANE_FUNKCE = ['$url', '$strankaUrl', '$menu_html'];

    private const array ZAKAZANE_PROMENNE = ['$_GET', '$_POST', '$_COOKIE', '$_SERVER', '$_FILES', '$_ENV', '$_SESSION', '$_REQUEST', '$GLOBALS', '$this', '$app', '$db'];

    /** Zakázané prvky jazyka: [token => čím se provinil]. */
    private const array ZAKAZANE_TOKENY = [
        T_EVAL => 'eval', T_INCLUDE => 'include', T_INCLUDE_ONCE => 'include_once', T_REQUIRE => 'require', T_REQUIRE_ONCE => 'require_once', T_NEW => 'new (vytváření objektů)',
        T_EXIT => 'exit / die', T_GLOBAL => 'global', T_GOTO => 'goto', T_NAMESPACE => 'namespace', T_CLASS => 'class', T_TRAIT => 'trait', T_INTERFACE => 'interface',
        T_ENUM => 'enum', T_HALT_COMPILER => '__halt_compiler', T_CLONE => 'clone', T_THROW => 'throw', T_TRY => 'try', T_DOUBLE_COLON => 'volání tříd (Trida::metoda)',
        T_DOLLAR_OPEN_CURLY_BRACES => '${…} v řetězci', T_UNSET_CAST => 'unset',
    ];

    /**
     * @return list<string> nalezené prohřešky (řádek + popis); prázdné pole = soubor je v pořádku
     */
    public static function over(string $php): array
    {
        try {
            $tokeny = token_get_all($php, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return ['řádek ' . $e->getLine() . ': syntaktická chyba – ' . $e->getMessage()];
        }
        // jen významné tokeny (bez mezer a komentářů), jednotně jako [id, text, řádek]
        $t = [];
        $radek = 1;
        foreach ($tokeny as $tok) {
            if (is_array($tok)) {
                $radek = $tok[2];
                if ($tok[0] === T_CLOSE_TAG) {
                    $t[] = [0, ';', $radek]; // konec bloku PHP odděluje příkazy stejně jako středník
                } elseif (!in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                    $t[] = [$tok[0], $tok[1], $radek];
                }
            } else {
                $t[] = [0, $tok, $radek];
            }
        }

        $chyby = [];
        $uzavery = self::PREDANE_FUNKCE; // proměnné, do kterých šablona sama přiřadila uzávěr
        foreach ($t as $i => [$id, $text]) {
            if ($id === T_VARIABLE && ($t[$i + 1][1] ?? '') === '=' && in_array($t[$i + 2][0] ?? 0, [T_FN, T_FUNCTION, T_STATIC], true)) {
                $uzavery[] = $text;
            }
        }

        foreach ($t as $i => [$id, $text, $r]) {
            $dalsi = $t[$i + 1][1] ?? '';
            $predchozi = $t[$i - 1] ?? [0, '', 0];
            $vada = match (true) {
                isset(self::ZAKAZANE_TOKENY[$id]) => 'šablona nesmí použít ' . self::ZAKAZANE_TOKENY[$id],
                $id === 0 && $text === '`' => 'šablona nesmí spouštět příkazy (zpětné apostrofy)',
                $id === 0 && $text === '$' => 'proměnné proměnné ($$x) nejsou v šabloně povolené',
                $id === T_USE && $predchozi[1] !== ')' => 'šablona nesmí importovat třídy (use)',
                $id === T_FUNCTION && ($t[$i + 1][0] ?? 0) === T_STRING => 'šablona nesmí definovat pojmenované funkce – použijte uzávěr ($f = function () { … })',
                $id === T_VARIABLE && in_array($text, self::ZAKAZANE_PROMENNE, true) => 'proměnná ' . $text . ' není šablonám dostupná – pracujte jen s daty, která šablona dostává',
                $id === T_VARIABLE && $dalsi === '(' && !in_array($text, $uzavery, true) => 'volat jde jen ' . implode(', ', self::PREDANE_FUNKCE) . ' a uzávěry, které si šablona sama definuje – ne ' . $text . '()',
                in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && (($t[$i + 2][1] ?? '') === '(' || ($t[$i + 1][0] ?? 0) !== T_STRING)
                    && !in_array($dalsi, self::METODY, true) => 'u předaných objektů jde volat jen ' . implode('(), ', self::METODY) . '() – ne ' . $dalsi . '()',
                in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && $dalsi === '(' && !in_array($predchozi[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true)
                    && !in_array(strtolower(ltrim($text, '\\')), self::FUNKCE, true) => 'funkce ' . $text . '() není v šablonách povolená',
                in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true) && $dalsi !== '(' => 'šablona nesmí odkazovat na třídy a konstanty systému (' . $text . ')',
                $dalsi === '(' && in_array($text, [')', ']', '}'], true) && $id === 0 && ($t[$i + 2][1] ?? '') !== '' && self::jeVolaniVyrazu($t, $i) => 'volat výsledek výrazu jako funkci není v šabloně povolené',
                default => null,
            };
            if ($vada === null && $id === T_STRING && $dalsi === '(' && isset(self::ZPETNA_VOLANI[strtolower($text)])) {
                $vada = self::vadaZpetnehoVolani($t, $i, self::ZPETNA_VOLANI[strtolower($text)], $uzavery);
            }
            if ($vada !== null) {
                $chyby[] = 'řádek ' . $r . ': ' . $vada;
            }
        }

        return array_values(array_unique($chyby));
    }

    /** ")(" je volání výsledku výrazu jen tehdy, když závorka nepatří řídicí konstrukci (if (…) (…), fn () => (…)). */
    private static function jeVolaniVyrazu(array $t, int $i): bool
    {
        if ($t[$i][1] !== ')') {
            return true;
        }
        // najdi otevírací závorku k této zavírací a podívej se, co jí předchází
        $hloubka = 0;
        for ($j = $i; $j >= 0; $j--) {
            $hloubka += $t[$j][1] === ')' ? 1 : ($t[$j][1] === '(' ? -1 : 0);
            if ($hloubka === 0) {
                return !in_array($t[$j - 1][0] ?? 0, [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_MATCH, T_FN, T_FUNCTION, T_USE, T_ISSET, T_EMPTY, T_ARRAY, T_LIST], true);
            }
        }

        return true;
    }

    /** Argument se zpětným voláním musí být uzávěr, vlastní uzávěr v proměnné, nebo povolená funkce zapsaná jako jmeno(...). */
    private static function vadaZpetnehoVolani(array $t, int $i, int $poradi, array $uzavery): ?string
    {
        $hloubka = 0;
        $arg = 0;
        for ($j = $i + 1; isset($t[$j]); $j++) {
            $z = $t[$j][1];
            if (in_array($z, ['(', '[', '{'], true)) {
                $hloubka++;
                if ($hloubka === 1) {
                    continue;
                }
            } elseif (in_array($z, [')', ']', '}'], true)) {
                if (--$hloubka === 0) {
                    return null; // argument se zpětným voláním chybí (např. array_filter bez funkce)
                }
            } elseif ($z === ',' && $hloubka === 1) {
                $arg++;
                continue;
            }
            if ($hloubka === 1 && $arg === $poradi) {
                [$id, $text] = $t[$j];
                $ok = in_array($id, [T_FN, T_FUNCTION, T_STATIC], true)
                    || ($id === T_VARIABLE && in_array($text, $uzavery, true) && in_array($t[$j + 1][1] ?? '', [',', ')'], true))
                    || ($id === T_STRING && in_array(strtolower($text), self::FUNKCE, true) && ($t[$j + 1][1] ?? '') === '(' && ($t[$j + 2][0] ?? 0) === T_ELLIPSIS);

                return $ok ? null : 'funkce ' . $t[$i][1] . '() smí dostat jen uzávěr (fn … => …) nebo povolenou funkci zapsanou jako jmeno(...), ne název funkce v řetězci či proměnné';
            }
        }

        return null;
    }
}
