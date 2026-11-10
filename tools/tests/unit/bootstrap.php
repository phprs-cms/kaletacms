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
/** true while the runner replays an earlier file only for what a selected file needs (php tools/unit-tests.php <filter>) */
$unitRunnerQuiet = false;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $errors, $total, $unitRunnerQuiet, $unitRunnerFile;
    if ($unitRunnerQuiet) {
        return;
    }
    $total++;
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
