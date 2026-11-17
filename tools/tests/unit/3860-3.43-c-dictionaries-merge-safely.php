<?php

declare(strict_types=1);

/* ---------- 3.43 C: the dictionaries merge with merge=union (.gitattributes) – no duplicate key, every entry closed by its comma ---------- */

/**
 * Problems of one dictionary file that a union merge can leave behind: a key written twice (PHP and JS keep the last one
 * silently, so a merged duplicate would hide a translation), a last entry without its comma (two branches appending
 * after it would be joined without one) and an entry that spans lines (union merges line by line, so such an entry could
 * be torn apart – 3.43 review M1: multi-line texts use "\n").
 *
 * @return list<string>
 */
function c343_dictionary_problems(string $file): array
{
    $name = basename($file);
    $content = (string) file_get_contents($file);
    $problems = [];
    $seen = [];
    if (str_ends_with($file, '.php')) {
        $tokens = token_get_all($content);
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token === '[') {
                $depth++;
                continue;
            }
            if ($token === ']') {
                $depth--;
                continue;
            }
            if ($depth === 1 && is_array($token) && in_array($token[0], [T_START_HEREDOC, T_ENCAPSED_AND_WHITESPACE], true)) {
                $problems[] = "entry spans lines – {$name}:{$token[2]}";
                continue;
            }
            if ($depth !== 1 || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (str_contains($token[1], "\n")) {
                $problems[] = "entry spans lines – {$name}:{$token[2]} (write \\n)";
            }
            $next = $i + 1;
            while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            if (!isset($tokens[$next]) || !is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_ARROW) {
                continue;
            }
            $key = $token[1][0] === "'"
                ? str_replace(["\\\\", "\\'"], ["\\", "'"], substr($token[1], 1, -1))
                : stripcslashes(substr($token[1], 1, -1));
            if (isset($seen[$key])) {
                $problems[] = "duplicate – {$name}:{$token[2]} (first {$seen[$key]}): " . mb_strimwidth($key, 0, 60, '…');
            }
            $seen[$key] = $token[2];
        }
        if (!preg_match('/,( *\/\/[^\n]*)?\n\];\n?$/', $content)) {
            $problems[] = "last entry without a comma – {$name}";
        }
    } else {
        foreach (preg_split('/\R/', $content) ?: [] as $number => $line) {
            if (preg_match('/^\t("(?:[^"\\\\]|\\\\.)*"):/', $line, $m) && is_string($key = json_decode($m[1]))) {
                if (isset($seen[$key])) {
                    $problems[] = "duplicate – {$name}:" . ($number + 1) . " (first {$seen[$key]}): " . mb_strimwidth($key, 0, 60, '…');
                }
                $seen[$key] = $number + 1;
            }
        }
        if (!preg_match('/",\n\}\)?;\n?$/', $content)) {
            $problems[] = "last entry without a comma – {$name}";
        }
    }

    return $problems;
}

$c343Files = [...glob(KALETA_SYSTEM . '/jazyky/*.php') ?: [], ...glob(KALETA_ROOT . '/image/jazyky/*.js') ?: []];
check('3.43 C: no dictionary has a duplicate key or a last entry without its comma (merge=union keeps both sides)',
    [count($c343Files) > 25, array_merge(...array_map('c343_dictionary_problems', $c343Files))], [true, []]);

// the check on what a careless union merge produces: the same key from both branches, and an entry appended after one without a comma
$c343Dir = sys_get_temp_dir() . '/kaleta-c343-' . bin2hex(random_bytes(4));
mkdir($c343Dir);
file_put_contents($c343Dir . '/cs.php', "<?php\nreturn [\n    'Saved' => 'Uloženo',\n    'It\\'s new' => 'Nové',\n    \"It's new\" => 'Nové znovu',\n    'Saved' => 'Uloženo',\n    'Hello,\n\nbye' => 'Ahoj,\n\nmějte se',\n];\n");
file_put_contents($c343Dir . '/admin-cs.js', "/* test */\nwindow.KALETA_PREKLAD = {\n\t\"Saved\": \"Uloženo\",\n\t\"Saved\": \"Uloženo\",\n\t\"New\": \"Nové\"\n};\n");
file_put_contents($c343Dir . '/admin-es.php', "<?php\nreturn [\n    'Saved' => 'Guardado'\n];\n");
check('3.43 C: the dictionary check names duplicates from either quoting, an entry that spans lines and a last entry without its comma', [
    c343_dictionary_problems($c343Dir . '/cs.php'),
    c343_dictionary_problems($c343Dir . '/admin-cs.js'),
    c343_dictionary_problems($c343Dir . '/admin-es.php'),
], [
    ["duplicate – cs.php:5 (first 4): It's new", 'duplicate – cs.php:6 (first 3): Saved', 'entry spans lines – cs.php:7 (write \\n)', 'entry spans lines – cs.php:9 (write \\n)'],
    ['duplicate – admin-cs.js:4 (first 3): Saved', 'last entry without a comma – admin-cs.js'],
    ['last entry without a comma – admin-es.php'],
]);
array_map('unlink', glob($c343Dir . '/*') ?: []);
rmdir($c343Dir);
