<?php
/**
 * Kaleta – proves that changed files differ from a git revision in comments only (used when comments are translated):
 * PHP compares the token stream without comments and whitespace, JavaScript compares acorn's tokens (NODE_PATH with acorn),
 * CSS and SQL compare the text without comments and whitespace, shell compares lines that are not
 * whole-line comments.
 *   php tools/check-comments-only.php [revision=HEAD] [file…]      (no files = every file changed since the revision)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$rev = $args !== [] && !is_file($root . '/' . $args[0]) ? array_shift($args) : 'HEAD';
$files = $args !== [] ? $args : array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git diff --name-only ' . escapeshellarg($rev))));

$php = function (string $code): string {
    $out = [];
    foreach (PhpToken::tokenize($code) as $t) {
        if (!$t->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
            $out[] = $t->id . ':' . ($t->is(T_INLINE_HTML) ? (string) preg_replace('/\s+/', ' ', $t->text) : $t->text);
        }
    }

    return implode("\n", $out);
};
$css = fn (string $code): string => (string) preg_replace('/\s+/', '', (string) preg_replace('#/\*.*?\*/#s', '', $code));
$sql = fn (string $code): string => (string) preg_replace('/\s+/', '', (string) preg_replace('/^\s*--.*$|\s--\s.*$/m', '', $code));
$sh = fn (string $code): string => implode("\n", array_filter(array_map('rtrim', explode("\n", $code)), fn (string $l): bool => !preg_match('/^\s*#(?!!)/', $l) && trim($l) !== ''));
$js = function (string $code) use ($root): string {
    $tmp = tempnam(sys_get_temp_dir(), 'js');
    file_put_contents($tmp, $code);
    $out = (string) shell_exec('node -e ' . escapeshellarg('const r=require("module").createRequire(process.env.NODE_PATH+"/");const a=r("acorn");const s=require("fs").readFileSync(process.argv[1],"utf8");const t=[];for(const k of a.tokenizer(s,{ecmaVersion:"latest"}))t.push(k.type.label+":"+(k.value===undefined?"":String(k.value)));console.log(t.join("\n"))') . ' ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);

    return $out;
};

$bad = 0;
foreach ($files as $f) {
    $new = @file_get_contents($root . '/' . $f);
    $old = shell_exec('cd ' . escapeshellarg($root) . ' && git show ' . escapeshellarg($rev . ':' . $f) . ' 2>/dev/null');
    if ($new === false || $old === null) {
        continue; // new or deleted file
    }
    $norm = match (pathinfo($f, PATHINFO_EXTENSION)) {
        'php' => $php, 'js', 'mjs' => $js, 'css' => $css, 'sql' => $sql, 'sh' => $sh, default => null,
    };
    if ($norm === null) {
        echo "  ?      $f (not checked)\n";
        continue;
    }
    if ($norm($old) !== $norm($new)) {
        $bad++;
        echo "  CHYBA  $f changes more than comments\n";
    }
}
echo $bad === 0 ? "comments only: OK (" . count($files) . " files)\n" : "NOT comments only: $bad files\n";
exit($bad === 0 ? 0 : 1);
