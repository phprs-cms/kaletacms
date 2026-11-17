<?php
/**
 * Dictionaries of the admin scripts (3.42, audit Q18): php tools/js-dictionaries.php [--check|--fill]
 *
 * image/jazyky/admin-<code>.js hold the translations the admin scripts look up with T('English text'): one entry per line,
 * a tab, the JSON-escaped key, ": ", the JSON-escaped translation and a comma (the last entry too, so that merge=union in
 * .gitattributes never joins two entries without one). Czech, German and Spanish translate the English source;
 * admin-en.js translates the few texts that still have a Czech source; admin-de-du.js is an overlay (edited by hand).
 *
 *   (no option)  a report per language: entries, texts the scripts name literally, texts that reach the scripts from PHP
 *                (builder schema labels, library sections, data attributes – they appear as strings in system/), and entries
 *                that appear nowhere (candidates for removal – check them, a text can be built at run time).
 *   --check      exit 1 when a line of a dictionary is not an entry in that format, when a text a script names literally
 *                is missing from a dictionary, or when a translation drops or adds a placeholder (%s, %d) or a tag.
 *   --fill       adds what --check reports missing, taking the translation from the PHP admin dictionary of the same
 *                language (system/jazyky/admin-<code>.php) when it has one; the rest stays reported.
 *
 * Generating the files from the PHP dictionaries outright is not done: about 2 400 of the 3 000 entries reach the scripts
 * as data from PHP, and the German script texts were translated on their own (800 differ in wording from the PHP ones).
 * The unit test 3680 runs the check.
 */

declare(strict_types=1);

/** The admin scripts that load image/jazyky/admin-<code>.js (web.js uses the site's dictionaries). */
const KALETA_JS_ADMIN_SCRIPTS = ['admin', 'admin-menu', 'editor', 'klice', 'menu', 'pomocnik', 'stavitel', 'tema'];

/** Dictionaries that translate the English source texts. */
const KALETA_JS_LANGUAGES = ['cs', 'de', 'es'];

/** Texts that are the same word in English and a name in the code (find-czech DVOJZNACNE) – need no entry. */
const KALETA_JS_SAME_WORD = ['Tablet', 'Menu', 'Logo', 'Video', 'Text', 'E-mail', 'Web'];

/**
 * A dictionary file: [entries, lines that are neither an entry nor the frame].
 *
 * @return array{0: array<string, string>, 1: list<string>}
 */
function kaleta_js_dictionary(string $file): array
{
    $entries = [];
    $odd = [];
    foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $number => $line) {
        if (preg_match('/^\t("(?:[^"\\\\]|\\\\.)*"):\s*("(?:[^"\\\\]|\\\\.)*"),?$/', $line, $m)) {
            $key = json_decode($m[1]);
            $value = json_decode($m[2]);
            if (is_string($key) && is_string($value)) {
                $entries[$key] = $value;
                continue;
            }
        }
        if (trim($line) !== '' && !preg_match('#^(/\*.*\*/|window\.KALETA_PREKLAD = \{|Object\.assign\(window\.KALETA_PREKLAD, \{|\}\)?;)$#', trim($line))) {
            $odd[] = basename($file) . ':' . ($number + 1) . ': ' . mb_strimwidth($line, 0, 80, '…');
        }
    }

    return [$entries, $odd];
}

/**
 * The texts the admin scripts pass to T() as a literal: text => script.
 *
 * @return array<string, string>
 */
function kaleta_js_literal_texts(string $root): array
{
    $texts = [];
    foreach (KALETA_JS_ADMIN_SCRIPTS as $script) {
        $js = (string) file_get_contents($root . '/image/' . $script . '.js');
        preg_match_all("/\\bT\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*[,)]/", $js, $single);
        preg_match_all('/\bT\(\s*"((?:[^"\\\\]|\\\\.)*)"\s*[,)]/', $js, $double);
        foreach ([...$single[1], ...$double[1]] as $text) {
            $texts[stripcslashes($text)] ??= $script;
        }
    }

    return $texts;
}

/**
 * Placeholders and markup tags of a text, sorted (a translation must keep the same ones). Only real tags: "<address>" in
 * "/api/novinky/<address>" is a placeholder of a path, translated like a word.
 *
 * @return list<string>
 */
function kaleta_js_placeholders(string $text): array
{
    preg_match_all('/%(?:\d+\$)?[sd]|<\/?(?:a|b|br|code|em|i|kbd|strong|span)\b/i', $text, $m);
    sort($m[0]);

    return $m[0];
}

/**
 * What --check reports: lines in another format, literal texts missing from a dictionary, placeholders that differ.
 *
 * @return list<string>
 */
function kaleta_js_check(string $root): array
{
    $findings = [];
    $literal = kaleta_js_literal_texts($root);
    $english = kaleta_js_dictionary($root . '/image/jazyky/admin-en.js')[0];
    foreach ([...KALETA_JS_LANGUAGES, 'en', 'de-du'] as $code) {
        $file = $root . '/image/jazyky/admin-' . $code . '.js';
        [$entries, $odd] = kaleta_js_dictionary($file);
        array_push($findings, ...array_map(fn (string $line): string => 'not an entry – ' . $line, $odd));
        foreach ($entries as $key => $value) {
            if (kaleta_js_placeholders((string) $key) !== kaleta_js_placeholders($value)) {
                $findings[] = 'placeholders differ – admin-' . $code . '.js: ' . $key;
            }
        }
        if (!in_array($code, KALETA_JS_LANGUAGES, true)) {
            continue;
        }
        foreach ($literal as $text => $script) {
            // a text with a Czech source is translated by admin-en.js (find-czech --js checks that one); the rest needs an entry here
            if (!isset($entries[$text]) && !isset($english[$text]) && !in_array($text, KALETA_JS_SAME_WORD, true) && preg_match('/\p{L}/u', $text)) {
                $findings[] = 'missing – admin-' . $code . '.js: ' . $text . ' (image/' . $script . '.js)';
            }
        }
    }

    return $findings;
}

/**
 * Adds the literal texts a dictionary misses, with the translation of the PHP admin dictionary of that language, before the
 * closing brace (the format of the file stays). Returns how many entries each language got.
 *
 * @return array<string, int>
 */
function kaleta_js_fill(string $root): array
{
    $literal = kaleta_js_literal_texts($root);
    $english = kaleta_js_dictionary($root . '/image/jazyky/admin-en.js')[0];
    $added = [];
    foreach (KALETA_JS_LANGUAGES as $code) {
        $file = $root . '/image/jazyky/admin-' . $code . '.js';
        $entries = kaleta_js_dictionary($file)[0];
        $php = is_file($root . '/system/jazyky/admin-' . $code . '.php') ? require $root . '/system/jazyky/admin-' . $code . '.php' : [];
        $add = [];
        foreach (array_keys($literal) as $text) {
            if (!isset($entries[$text]) && !isset($english[$text]) && isset($php[$text]) && is_string($php[$text])) {
                $add[] = "\t" . json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ': ' . json_encode($php[$text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if ($add !== []) {
            $content = (string) file_get_contents($file);
            file_put_contents($file, (string) preg_replace('/"\s*,?(\s*\n\};\s*)$/', '",' . "\n" . implode(",\n", $add) . ',$1', $content, 1));
        }
        $added[$code] = count($add);
    }

    return $added;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $option = $argv[1] ?? '';
    if ($option === '--check') {
        $findings = kaleta_js_check($root);
        echo $findings === [] ? "The admin script dictionaries are complete.\n" : implode("\n", $findings) . "\n";
        exit($findings === [] ? 0 : 1);
    }
    if ($option === '--fill') {
        foreach (kaleta_js_fill($root) as $code => $added) {
            echo 'admin-' . $code . '.js: ' . $added . " added\n";
        }
        exit(0);
    }
    // the report
    $literal = kaleta_js_literal_texts($root);
    $scripts = '';
    foreach (glob($root . '/image/*.js') ?: [] as $file) {
        $scripts .= file_get_contents($file);
    }
    $php = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/system', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/jazyky/')) {
            $php .= file_get_contents($file->getPathname());
        }
    }
    $quoted = fn (string $text): array => [json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "'" . addcslashes($text, "'\\") . "'"];
    foreach ([...KALETA_JS_LANGUAGES, 'en'] as $code) {
        $entries = kaleta_js_dictionary($root . '/image/jazyky/admin-' . $code . '.js')[0];
        $named = $fromPhp = $nowhere = 0;
        foreach (array_keys($entries) as $key) {
            $key = (string) $key;
            if (isset($literal[$key])) {
                $named++;
            } elseif (array_filter($quoted($key), fn (string $q): bool => str_contains($scripts, $q) || str_contains($php, $q)) !== []) {
                $fromPhp++;
            } else {
                $nowhere++;
            }
        }
        printf("admin-%s.js: %d entries – %d named by a script, %d strings in the code (from PHP or a script's list), %d nowhere\n", $code, count($entries), $named, $fromPhp, $nowhere);
    }
}
