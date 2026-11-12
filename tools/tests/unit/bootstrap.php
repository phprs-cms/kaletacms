<?php
/**
 * Shared set-up of the unit tests (tools/unit-tests.php runs it first, then every NNNN-*.php of this folder in name order,
 * all in one global scope – a later file may use what an earlier one prepared). See tools/tests/README.md.
 */

declare(strict_types=1);

// the HTML parser of PHP 8.3 (Compat\Html5Parser) takes the run to about 120 MB, next to the 128 MB default of a local php.ini
ini_set('memory_limit', '512M');

require dirname(__DIR__, 3) . '/system/bootstrap.php';

$errors = 0;
$total = 0;
/** 3.36 T3 (Q6): how many checks carry [source-pin] in their label – they pin the text of a product file (unit_source_pins()) */
$sourcePins = 0;
/** true while the runner replays an earlier file only for what a selected file needs (php tools/unit-tests.php <filter>) */
$unitRunnerQuiet = false;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $errors, $total, $unitRunnerQuiet, $unitRunnerFile, $sourcePins;
    if ($unitRunnerQuiet) {
        return;
    }
    $total++;
    $sourcePins += str_contains($label, '[source-pin]') ? 1 : 0;
    if (($labels = getenv('KALETA_UNIT_LABELS')) !== false && $labels !== '') { // the list of check names (T2 proof)
        file_put_contents($labels, basename((string) $unitRunnerFile) . "\t" . json_encode($label, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    if ($actual === $expected) {
        return;
    }
    $errors++;
    echo "  CHYBA  {$label}\n         čekal jsem: " . var_export($expected, true) . "\n         dostal jsem: " . var_export($actual, true) . "\n";
}

/** The whole smoke test as one text – the runner tools/test.sh, bootstrap.sh and every section – for the guards that read it. */
function smoke_test_source(): string
{
    $dir = KALETA_ROOT . '/tools/tests/smoke';
    $text = (string) file_get_contents($dir . '/bootstrap.sh');
    foreach (glob($dir . '/[0-9][0-9][0-9][0-9]-*.sh') ?: [] as $file) {
        $text .= (string) file_get_contents($file);
    }

    return $text . (string) file_get_contents(KALETA_ROOT . '/tools/test.sh');
}

/**
 * The files a selected file needs when it runs alone: for every variable it reads before it assigns it, the last earlier
 * file that assigns it – and, in turn, what that file needs. Static and approximate (tokens, no execution); an extra file
 * only costs time, its checks are not counted.
 *
 * @param list<string> $files all files in run order
 * @param list<string> $selected
 * @return list<string> $selected plus what they need, in run order
 */
function unit_test_closure(array $files, array $selected): array
{
    $assigns = [];
    $needs = [];
    $outputs = ['preg_match' => true, 'preg_match_all' => true, 'exec' => true, 'parse_str' => true, 'openssl_sign' => true, 'similar_text' => true];
    foreach ($files as $file) {
        $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)), fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $assigns[$file] = [];
        $seen = [];
        $needs[$file] = [];
        $parens = []; // per open parenthesis: 'params' (fn/function/catch), the called function's name, or ''
        $braces = 0;
        // state outside variables: the active language a file leaves set (Language::set at the top level) – every file reads it
        $needs[$file]['@language'] = true;
        foreach ($tokens as $i => $t) {
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $braces++;
            } elseif ($t === '}') {
                $braces--;
            }
            if ($braces === 0 && $parens === [] && is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/(^|\\\\)Language$/', $t[1])
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON && is_array($tokens[$i + 2] ?? null) && in_array($tokens[$i + 2][1], ['set', 'setSite', 'setAdminRegister'], true)) {
                $assigns[$file]['@language'] = true;
            }
            if ($t === '(') {
                $prev = $tokens[$i - 1] ?? null;
                $prev2 = $tokens[$i - 2] ?? null;
                $parens[] = is_array($prev) && in_array($prev[0], [T_FN, T_FUNCTION, T_CATCH], true) || ($prev === '&' && is_array($prev2) && in_array($prev2[0], [T_FN, T_FUNCTION], true))
                    ? 'params' : (is_array($prev) && $prev[0] === T_STRING ? strtolower($prev[1]) : '');
                continue;
            }
            if ($t === ')') {
                array_pop($parens);
                continue;
            }
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            $name = $t[1];
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;
            if ($next === '=') {
                $assigns[$file][$name] = true;
            }
            if (isset($seen[$name]) || in_array($name, ['$this', '$errors', '$total', '$argv', '$argc', '$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_ENV', '$_REQUEST', '$_SESSION', '$unitRunnerQuiet', '$unitRunnerFile'], true)) {
                continue;
            }
            $seen[$name] = true;
            $top = end($parens);
            $defines = $next === '=' || (is_array($prev) && $prev[0] === T_AS) || ($prev === '&' && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_AS)
                || (is_array($prev) && $prev[0] === T_DOUBLE_ARROW && $next === ')') || $top === 'params' || ($top !== false && isset($outputs[$top]) && $prev === ',')
                || ($prev === '[' || $prev === ',') && in_array($next, [',', ']'], true); // [$a, $b] = … (a read inside [...] only costs an extra file)
            if (!$defines) {
                $needs[$file][$name] = true;
            }
        }
    }
    $wanted = array_fill_keys($selected, true);
    $queue = $selected;
    while ($queue !== []) {
        $file = array_shift($queue);
        $at = array_search($file, $files, true);
        foreach (array_keys($needs[$file]) as $name) {
            for ($j = (int) $at - 1; $j >= 0; $j--) {
                if (isset($assigns[$files[$j]][$name])) {
                    if (!isset($wanted[$files[$j]])) {
                        $wanted[$files[$j]] = true;
                        $queue[] = $files[$j];
                    }
                    break;
                }
            }
        }
    }

    return array_values(array_filter($files, fn (string $f): bool => isset($wanted[$f])));
}

/**
 * 3.36 T3 (Q6): the checks of one unit file that pin the text of a product file – a search (str_contains, strpos,
 * substr_count, preg_match …) in what file_get_contents() read from one named file of system/, image/, extensions/ or an
 * entry file, directly or through a variable that holds such a text (or a part of it). A check that walks the tree (glob,
 * a directory iterator, foreach) is a structural rule, not a pin. Static and approximate, on tokens; each pin's label must
 * carry the tag [source-pin] (the runner counts them, and the count may only go down – replace a pin with a behaviour test
 * when its code is next touched).
 *
 * @return list<array{line: int, label: string, tagged: bool}>
 */
function unit_source_pins(string $file): array
{
    $tokens = array_values(array_filter(token_get_all((string) file_get_contents($file)), fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $count = count($tokens);
    $text = fn (int $from, int $to): string => implode(' ', array_map(fn ($t): string => is_array($t) ? $t[1] : $t, array_slice($tokens, $from, $to - $from)));
    $reads = '/file_get_contents \( (?:\( string \) )?(?:KALETA_SYSTEM|KALETA_ROOT \. \'\/(?:system|image|extensions|admin\.php|index\.php|install\.php))/';
    $walks = '/\b(glob|RecursiveDirectoryIterator|RecursiveIteratorIterator|scandir|foreach|smoke_test_source)\b/';
    $searches = '/\b(str_contains|strpos|stripos|strrpos|substr_count|preg_match|preg_match_all|str_starts_with|str_ends_with) \(/';
    $sources = []; // variables holding the text of one product file
    $pins = [];
    $depth = 0;
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($t === '}') {
            $depth--;
        }
        // $x = … file_get_contents(KALETA_SYSTEM …) …;  or  $x = substr($known, …);  at the top level
        if ($depth === 0 && is_array($t) && $t[0] === T_VARIABLE && ($tokens[$i + 1] ?? null) === '=' && !in_array($tokens[$i - 1] ?? null, ['(', ','], true)) {
            for ($end = $i + 2; $end < $count && $tokens[$end] !== ';'; $end++);
            $rhs = $text($i + 2, $end);
            $derived = false;
            foreach (array_keys($sources) as $name) {
                $derived = $derived || preg_match('/' . preg_quote($name, '/') . '\b/', $rhs) === 1;
            }
            if ((preg_match($reads, $rhs) === 1 || $derived) && preg_match($walks, $rhs) !== 1) {
                $sources[$t[1]] = true;
            } else {
                unset($sources[$t[1]]);
            }
            continue;
        }
        if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'check' || ($tokens[$i + 1] ?? null) !== '(' || (is_array($tokens[$i - 1] ?? null) && in_array($tokens[$i - 1][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true))) {
            continue;
        }
        // the call's arguments, and where the label (the first argument) ends
        $open = 0;
        $labelEnd = null;
        for ($end = $i + 1; $end < $count; $end++) {
            $e = $tokens[$end];
            if (in_array($e, ['(', '['], true) || (is_array($e) && in_array($e[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) || $e === '{') {
                $open++;
            } elseif (in_array($e, [')', ']', '}'], true)) {
                $open--;
                if ($open === 0) {
                    break;
                }
            } elseif ($e === ',' && $open === 1 && $labelEnd === null) {
                $labelEnd = $end;
            }
        }
        $labelEnd ??= $end;
        $args = $text($labelEnd, $end);
        $usesSource = preg_match($reads, $args) === 1;
        foreach (array_keys($sources) as $name) {
            $usesSource = $usesSource || preg_match('/' . preg_quote($name, '/') . '\b/', $args) === 1;
        }
        if ($usesSource && preg_match($searches, $args) === 1 && preg_match($walks, $args) !== 1) {
            $label = $text($i + 2, $labelEnd);
            $pins[] = ['line' => $t[2], 'label' => $label, 'tagged' => str_contains($label, '[source-pin]')];
        }
        $i = $end;
    }

    return $pins;
}
