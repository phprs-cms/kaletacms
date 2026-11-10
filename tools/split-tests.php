<?php
/**
 * T2 (code-quality audit Q3): splits the two monolithic test files along their existing section headers.
 *
 *   php tools/split-tests.php [--root=<checkout>]
 *
 * Input: the monolithic tools/unit-tests.php (sections start with a top-level `/* ---------- … ---------- *\/` comment)
 * and tools/test.sh (sections start with a line `echo "== …"`), as they were up to 3.32.0.
 * Output: tools/tests/unit/bootstrap.php + NNNN-<topic>.php, tools/tests/smoke/bootstrap.sh + NNNN-<topic>.sh, and the
 * two files rewritten as thin runners that keep their names, options, environment, output and exit codes.
 *
 * Purely mechanical: every section keeps its text and its place in the run order. A unit file gets only what moving it
 * needs – `declare(strict_types=1)`, the `use` imports that were in force at its place and that it names, and paths that
 * pointed at tools/ through __DIR__ – plus two literal adjustments that follow from the move (reported when made):
 * the T1 guards that read tools/test.sh read the whole smoke suite (smoke_test_source()), and the expected trace frame
 * "tools/unit-tests.php:" names the file the code now lives in. bootstrap.sh + the smoke files + the runner's last
 * lines are byte for byte the old test.sh.
 *
 * Re-runnable: restore the monolithic files and run it again – it removes the generated files first.
 *   git checkout r332 -- tools/unit-tests.php tools/test.sh && php tools/split-tests.php
 * Once the split has landed, the files in tools/tests/ are the source and this script is not needed any more.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    } else {
        fwrite(STDERR, "Usage: php tools/split-tests.php [--root=<checkout>]\n");
        exit(2);
    }
}
$unitSource = $root . '/tools/unit-tests.php';
$smokeSource = $root . '/tools/test.sh';
$unitDir = $root . '/tools/tests/unit';
$smokeDir = $root . '/tools/tests/smoke';

$unitText = (string) @file_get_contents($unitSource);
$smokeText = (string) @file_get_contents($smokeSource);
if (str_contains($unitText, '/tests/unit/bootstrap.php') || str_contains($smokeText, '/tests/smoke')) {
    fwrite(STDERR, "tools/unit-tests.php or tools/test.sh is already the split runner – restore the monolithic files first:\n"
        . "  git checkout r332 -- tools/unit-tests.php tools/test.sh && php tools/split-tests.php\n");
    exit(1);
}
if ($unitText === '' || $smokeText === '') {
    fwrite(STDERR, "tools/unit-tests.php or tools/test.sh is missing under {$root}\n");
    exit(1);
}

/** A file name part from a section header: ASCII, lower case, at most about 50 characters on a word boundary. */
function split_slug(string $title): string
{
    $title = (string) preg_replace('/[\x{2013}\x{2014}\x{2026}]/u', ' ', $title);
    $decomposed = class_exists(Normalizer::class) ? (string) Normalizer::normalize($title, Normalizer::FORM_D) : $title;
    $ascii = (string) preg_replace('/[^\x20-\x7e]/', '', (string) preg_replace('/\p{Mn}/u', '', strtr($decomposed, ['ß' => 'ss'])));
    $slug = trim((string) preg_replace('/[^a-z0-9.]+/', '-', strtolower($ascii)), '-.');
    $slug = (string) preg_replace('/\.(?![0-9])|(?<![0-9])\./', '-', $slug); // dots only inside a version number
    $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
    if (strlen($slug) > 50) {
        $cut = strrpos(substr($slug, 0, 51), '-');
        $slug = substr($slug, 0, $cut !== false && $cut > 20 ? $cut : 50);
    }

    return $slug !== '' ? $slug : 'section';
}

/** Removes what an earlier run generated in $dir (only bootstrap.* and NNNN-*.<ext>). */
function split_clean(string $dir, string $ext): void
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "cannot create {$dir}\n");
        exit(1);
    }
    foreach (array_merge(glob($dir . '/[0-9][0-9][0-9][0-9]-*.' . $ext) ?: [], glob($dir . '/bootstrap.' . $ext) ?: []) as $file) {
        unlink($file);
    }
}

/* ---------- unit tests ---------- */

$tokens = token_get_all($unitText);
$line = 1;
$depth = 0;
$cuts = [];           // byte offsets where a section starts
$uses = [];           // [offset, aliases[], text] of the top-level use statements
$offset = 0;
$statementStart = true;
$count = count($tokens);
for ($i = 0; $i < $count; $i++) {
    $t = $tokens[$i];
    $text = is_array($t) ? $t[1] : $t;
    if (is_array($t) && $t[0] === T_COMMENT && $depth === 0 && str_starts_with($text, '/* ---------- ') && ($offset === 0 || $unitText[$offset - 1] === "\n")) {
        $cuts[] = $offset;
    }
    if (is_array($t) && $t[0] === T_USE && $depth === 0 && $statementStart) {
        $end = strpos($unitText, ';', $offset);
        $statement = substr($unitText, $offset, (int) $end - $offset + 1);
        $aliases = [];
        foreach (explode(',', (string) preg_replace('/^use\s+|;$/', '', $statement)) as $part) {
            $part = trim($part);
            $aliases[] = preg_match('/\sas\s+(\w+)$/i', $part, $m) ? $m[1] : substr((string) strrchr('\\' . $part, '\\'), 1);
        }
        $uses[] = [$offset, $aliases, $statement];
    }
    if (!is_array($t)) {
        if ($text === '{' || $text === '(' || $text === '[') {
            $depth++;
        } elseif ($text === '}' || $text === ')' || $text === ']') {
            $depth--;
        }
    } elseif (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
        $depth++;
    }
    if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
        $statementStart = $depth === 0 && ($text === ';' || $text === '}' || (is_array($t) && $t[0] === T_OPEN_TAG));
    }
    $offset += strlen($text);
}
if ($depth !== 0 || count($cuts) < 2) {
    fwrite(STDERR, "unit-tests.php: unexpected structure (depth {$depth}, " . count($cuts) . " sections)\n");
    exit(1);
}

// the prelude (doc comment, ini, bootstrap, use imports, check()) and the summary at the end
$prelude = substr($unitText, 0, $cuts[0]);
$summaryPattern = "/\necho \\\$errors === 0 \\? \"  ok     jednotkové testy \\(\\{\\\$total\\}\\)\\\\n\" : \"  NALEZENO CHYB: \\{\\\$errors\\} z \\{\\\$total\\}\\\\n\";\nexit\\(\\\$errors === 0 \\? 0 : 1\\);\n$/";
if (!preg_match($summaryPattern, $unitText, $summaryMatch, PREG_OFFSET_CAPTURE)) {
    fwrite(STDERR, "unit-tests.php: the summary lines at the end were not found\n");
    exit(1);
}
$bodyEnd = $summaryMatch[0][1] + 1;
// bootstrap.php below is the prelude of 3.32.0 with check() taught the filter; a changed prelude needs a look first
if (sha1($prelude) !== '89a5be80f3f483adff66c776493fe8d1428369ab') {
    fwrite(STDERR, "unit-tests.php: the part before the first section changed since 3.32.0 – carry the change into the bootstrap template of this script, then update the digest\n");
    exit(1);
}

split_clean($unitDir, 'php');
$unitFiles = [];
$adjustments = ['smoke_test_source()' => 0, 'trace path' => 0];
foreach ($cuts as $k => $start) {
    $end = $cuts[$k + 1] ?? $bodyEnd;
    $body = substr($unitText, $start, $end - $start);
    $header = strtok($body, "\n");
    $title = trim((string) preg_replace('~^/\* -{5,}\s*|\s*-{5,}\s*(\*/)?\s*$~', '', (string) $header));
    $name = sprintf('%04d-%s.php', ($k + 1) * 10, split_slug($title));

    // tools/ moved two levels up: dirname(__DIR__) was the checkout, __DIR__ was tools/; string literals are left alone
    $out = '';
    $bodyTokens = token_get_all('<?php ' . $body);
    array_shift($bodyTokens);
    $n = count($bodyTokens);
    $named = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $bodyTokens[$i];
        if (is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'dirname' && ($bodyTokens[$i + 1] ?? null) === '(' && is_array($bodyTokens[$i + 2] ?? null) && $bodyTokens[$i + 2][0] === T_DIR && ($bodyTokens[$i + 3] ?? null) === ')') {
            $out .= 'dirname(__DIR__, 3)';
            $i += 3;
            continue;
        }
        if (is_array($t) && $t[0] === T_DIR) {
            $out .= 'dirname(__DIR__, 2)';
            continue;
        }
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($t[1], 'tools/unit-tests.php:')) {
            $out .= str_replace('tools/unit-tests.php:', 'tools/tests/unit/' . $name . ':', $t[1]);
            $adjustments['trace path']++;
            continue;
        }
        if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED], true)) {
            $named[strtolower(explode('\\', $t[1])[0])] = true;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    $before = $out;
    $out = str_replace("file_get_contents(KALETA_ROOT . '/tools/test.sh')", 'smoke_test_source()', $out);
    $adjustments['smoke_test_source()'] += substr_count($before, "file_get_contents(KALETA_ROOT . '/tools/test.sh')");

    // the use imports in force at this place (earlier statements) that the section names
    $imports = '';
    foreach ($uses as [$at, $aliases, $statement]) {
        if ($at < $start && array_filter($aliases, fn (string $a): bool => isset($named[strtolower($a)]))) {
            $imports .= $statement . "\n";
        }
    }
    file_put_contents($unitDir . '/' . $name, "<?php\n\ndeclare(strict_types=1);\n\n" . ($imports !== '' ? $imports . "\n" : '') . $out);
    $unitFiles[] = $name;
}

$unitBootstrap = <<<'PHP'
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

PHP;
file_put_contents($unitDir . '/bootstrap.php', $unitBootstrap);

$unitRunner = <<<'PHP'
<?php
/**
 * Unit tests of the Kaleta core - no framework and no database: php tools/unit-tests.php [filter …]
 *
 * They guard what the smoke test (tools/test.sh) cannot detect: cryptography, parsing and text conversions.
 * A new test = another call of check('popis', $skutecne, $ocekavane) in a file of tools/tests/unit (tools/tests/README.md).
 *
 * The tests live in tools/tests/unit: bootstrap.php (check() and the set-up), then every NNNN-<topic>.php in name order,
 * all in one global scope. A filter runs only the files whose name starts with or contains it (php tools/unit-tests.php
 * 0310 fleet); what an earlier file prepares for them is replayed first, without counting its checks.
 */

declare(strict_types=1);

require __DIR__ . '/tests/unit/bootstrap.php';

$unitRunnerAll = [];
foreach (glob(__DIR__ . '/tests/unit/*') ?: [] as $unitRunnerFile) {
    $unitRunnerName = basename($unitRunnerFile);
    if (preg_match('/^\d{4}-[a-z0-9.-]+\.php$/', $unitRunnerName)) {
        $unitRunnerAll[substr($unitRunnerName, 0, 4)][] = $unitRunnerFile;
    } elseif (!in_array($unitRunnerName, ['bootstrap.php', 'README.md'], true)) {
        echo "  CHYBA  tools/tests/unit/{$unitRunnerName} is not NNNN-topic.php – it would never run\n";
        exit(1);
    }
}
ksort($unitRunnerAll, SORT_STRING);
foreach ($unitRunnerAll as $unitRunnerNumber => $unitRunnerSame) {
    if (count($unitRunnerSame) > 1) {
        echo "  CHYBA  tools/tests/unit: two files with the number {$unitRunnerNumber} – the order would not be clear\n";
        exit(1);
    }
}
$unitRunnerAll = array_merge(...array_values($unitRunnerAll));
$unitRunnerFilters = array_slice($argv, 1);
$unitRunnerSelected = $unitRunnerFilters === [] ? $unitRunnerAll : array_values(array_filter($unitRunnerAll, function (string $file) use ($unitRunnerFilters): bool {
    foreach ($unitRunnerFilters as $filter) {
        if ($filter !== '' && (str_starts_with(basename($file), $filter) || stripos(basename($file), $filter) !== false)) {
            return true;
        }
    }

    return false;
}));
if ($unitRunnerSelected === []) {
    echo '  CHYBA  no file of tools/tests/unit matches ' . implode(' ', $unitRunnerFilters) . "\n";
    exit(1);
}
$unitRunnerRun = $unitRunnerFilters === [] ? $unitRunnerAll : unit_test_closure($unitRunnerAll, $unitRunnerSelected);
foreach ($unitRunnerRun as $unitRunnerFile) {
    $unitRunnerQuiet = !in_array($unitRunnerFile, $unitRunnerSelected, true);
    require $unitRunnerFile;
}
$unitRunnerQuiet = false;

PHP;
file_put_contents($unitSource, $unitRunner . substr($unitText, $bodyEnd));

/* ---------- smoke test ---------- */

$lines = explode("\n", $smokeText);
$headers = [];
foreach ($lines as $no => $row) {
    if (str_starts_with($row, 'echo "== ')) {
        $headers[] = $no;
    }
}
$tail = "if [ -s \"\$WORK/web/storage/log/chyby.log\" ]; then echo \"== záznam chyb aplikace:\"; cat \"\$WORK/web/storage/log/chyby.log\"; ERRORS=\$((ERRORS+1)); fi\n"
    . "echo; [ \"\$ERRORS\" -eq 0 ] && echo \"VŠE V POŘÁDKU\" || { echo \"NALEZENO CHYB: \$ERRORS\"; exit 1; }\n";
if (count($headers) < 2 || !str_ends_with($smokeText, "\n" . $tail)) {
    fwrite(STDERR, "test.sh: unexpected structure (" . count($headers) . " sections, or the last two lines changed)\n");
    exit(1);
}
$smokeBody = substr($smokeText, 0, -strlen($tail));
$offsets = [];
$pos = 0;
foreach ($lines as $no => $row) {
    $offsets[$no] = $pos;
    $pos += strlen($row) + 1;
}

split_clean($smokeDir, 'sh');
file_put_contents($smokeDir . '/bootstrap.sh', substr($smokeBody, 0, $offsets[$headers[0]]));
$smokeFiles = [];
foreach ($headers as $k => $no) {
    $start = $offsets[$no];
    $end = isset($headers[$k + 1]) ? $offsets[$headers[$k + 1]] : strlen($smokeBody);
    $title = (string) preg_replace('/^echo "== |"\s*$/', '', $lines[$no]);
    $name = sprintf('%04d-%s.sh', ($k + 1) * 10, split_slug(stripcslashes($title)));
    file_put_contents($smokeDir . '/' . $name, substr($smokeBody, $start, $end - $start));
    $smokeFiles[] = $name;
}

$smokeHead = implode("\n", array_slice($lines, 0, 5)); // the shebang and the description of the environment
$smokeRunner = $smokeHead . "\n" . <<<'SH'
#
# The checks live in tools/tests/smoke: bootstrap.sh (the settings above, the clean-up trap, the first helpers), then
# every NNNN-<topic>.sh in number order – all sourced into this one shell: one site, one cookie jar, one database, so a
# section may use what an earlier one left. A new section is a new file (tools/tests/README.md).
set -euo pipefail

SMOKE_DIR="$(cd "$(dirname "$0")" && pwd)/tests/smoke"
SMOKE_FILES=()
for SMOKE_FILE in "$SMOKE_DIR"/*; do
  case "${SMOKE_FILE##*/}" in
    bootstrap.sh|README.md) ;;
    [0-9][0-9][0-9][0-9]-*.sh)
      if [ "${#SMOKE_FILES[@]}" -gt 0 ] && [ "$(basename "${SMOKE_FILES[${#SMOKE_FILES[@]}-1]}" | cut -c1-4)" = "$(echo "${SMOKE_FILE##*/}" | cut -c1-4)" ]; then
        echo "  CHYBA  tools/tests/smoke: two files with the number $(echo "${SMOKE_FILE##*/}" | cut -c1-4) – the order would not be clear"; exit 1
      fi
      SMOKE_FILES+=("$SMOKE_FILE") ;;
    *) echo "  CHYBA  tools/tests/smoke/${SMOKE_FILE##*/} is not NNNN-topic.sh – it would never run"; exit 1 ;;
  esac
done

# shellcheck source=tests/smoke/bootstrap.sh
. "$SMOKE_DIR/bootstrap.sh"
for SMOKE_FILE in "${SMOKE_FILES[@]}"; do
  # shellcheck disable=SC1090
  . "$SMOKE_FILE"
done

SH;
file_put_contents($smokeSource, $smokeRunner . "\n" . $tail);
chmod($smokeSource, 0755);

foreach ([[$unitDir, $unitFiles, 'php'], [$smokeDir, $smokeFiles, 'sh']] as [$dir, $files, $ext]) {
    if (count(array_unique(array_map(fn (string $f): string => substr($f, 0, 4), $files))) !== count($files)) {
        fwrite(STDERR, "{$dir}: duplicate numbers\n");
        exit(1);
    }
}
echo 'tools/tests/unit: bootstrap.php + ' . count($unitFiles) . " files; adjustments: " . json_encode($adjustments) . "\n";
echo 'tools/tests/smoke: bootstrap.sh + ' . count($smokeFiles) . " files\n";
echo "runners rewritten: tools/unit-tests.php, tools/test.sh\n";
