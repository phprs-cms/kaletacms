<?php

declare(strict_types=1);

use Kaleta\Core\Comments;
use Kaleta\Core\Diff;
use Kaleta\Core\FileSignature;

/* ---------- 3.47 security audit of 20 November: N171 (bounded markup check), I51 (what the builder and the nonce get) ---------- */

// N171: the two tag patterns of FileSignature::MARKUP stop at the next "<" and after 2000 characters. Without the JIT a
// 64 KB file of "<a " took seconds per check (and could end at the backtrack limit); run in a child PHP with pcre.jit=0.
$n171Script = <<<'PHP'
require $argv[1] . '/system/bootstrap.php';
$cases = ['padded' => substr(str_repeat('<a ', 21846), 0, 65536), 'padded-x' => substr(str_repeat('<a x', 16384), 0, 65536),
    'one-open' => '<a ' . str_repeat('x ', 32766)];
$out = ['jit' => ini_get('pcre.jit')];
foreach ($cases as $name => $text) {
    $start = microtime(true);
    $plain = Kaleta\Core\FileSignature::isPlainText($text, strlen($text));
    $out[$name] = [$plain, preg_last_error() === PREG_NO_ERROR, microtime(true) - $start < 1.0];
}
echo json_encode($out);
PHP;
$n171File = sys_get_temp_dir() . '/kaleta-u4290-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($n171File, "<?php\n" . $n171Script);
$n171Started = microtime(true);
$n171Out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d pcre.jit=0 ' . escapeshellarg($n171File) . ' ' . escapeshellarg(KALETA_ROOT) . ' 2>&1');
$n171Seconds = microtime(true) - $n171Started;
unlink($n171File);
check('3.47 N171: 64 KB of padded "<a " runs without the JIT in well under a second per check, with no PCRE error, and counts as plain text (no tag closes there)',
    [json_decode($n171Out, true), $n171Seconds < 5.0],
    [['jit' => '0', 'padded' => [true, true, true], 'padded-x' => [true, true, true], 'one-open' => [true, true, true]], true]);

$n171Plain = fn (string $text): bool => FileSignature::isPlainText($text, strlen($text));
check('3.47 N171: the bounded patterns still find a javascript: link and an on…= handler, also with spaces and line breaks, and ignore "a < b and on = 3"', [
    $n171Plain('<a href="javascript:alert(1)">x</a>'), $n171Plain('<p class=x onclick = "a">'), $n171Plain("<div\nonmouseover=\"x\">"),
    $n171Plain('<A HREF="JavaScript:x">'), $n171Plain('<a href="' . str_repeat('x', 1990) . 'javascript:y">'),
    $n171Plain('a < b and on = 3'), $n171Plain("Notes: if x<y then\nrun on=3"), $n171Plain('<b>bold</b> and plain words'),
    $n171Plain('<a href="/x">javascript: a word in the text</a>')],
    [false, false, false, false, false, true, true, true, true]);

// I51: what the builder (image/stavitel.js) puts into the page with el(…, {html}) is escaped on the server – the version
// compare (Core\Diff through Builder\DraftHistory::compare) and the comments (Core\Comments::html through forBuilder).
$i51Hostile = '<img src=x onerror=alert(1)> "quoted" \'single\' <script>alert(2)</script> & more';
$i51Text = htmlspecialchars($i51Hostile); // the compare takes HTML and works on its text: the hostile characters arrive as text
$i51Diff = [Diff::html('', $i51Text)['html'], Diff::html('plain words', $i51Text)['html'], Diff::html($i51Text, '')['html'], Diff::html($i51Hostile, '')['html'],
    \Kaleta\Builder\DraftHistory::compare(null, (string) json_encode(['v' => 1, 'deti' => [['id' => 'a', 'typ' => 'text', 'obsah' => ['html' => '<p>' . $i51Text . '</p>']]]]))['sekce'][0]['html'] ?? 'missing'];
$i51Comment = Comments::html("@eva look: <img src=x onerror=alert(1)>\n\"x\" & <script>y</script>", [7 => ['user' => 'eva', 'name' => '<b>Eva</b>']]);
$i51Tags = fn (string $html): array => array_values(array_unique(preg_match_all('/<\s*([a-z0-9]+)/i', $html, $m) ? array_map('strtolower', $m[1]) : []));
check('3.47 I51: the version compare the builder shows as HTML has only <p>, <ins> and <del> – hostile text comes out escaped, also through DraftHistory::compare', [
    array_values(array_unique(array_merge(...array_map($i51Tags, $i51Diff)))), str_contains(implode('', $i51Diff), '&lt;img src=x onerror=alert(1)&gt;'),
    str_contains(implode('', $i51Diff), '&lt;script&gt;'), str_contains($i51Diff[4], '&lt;img src=x onerror=alert(1)&gt;')],
    [['p', 'ins', 'del'], true, true, true]);
check('3.47 I51: a comment the builder shows as HTML has only the mention <span> and <br> – the text and the person\'s name are escaped', [
    $i51Tags($i51Comment), str_contains($i51Comment, '&lt;img src=x onerror=alert(1)&gt;'), str_contains($i51Comment, '@&lt;b&gt;Eva&lt;/b&gt;'),
    str_contains($i51Comment, '&lt;script&gt;y&lt;/script&gt;')],
    [['span', 'br'], true, true, true]);

// I51: Front\Csp::withNonce blesses <script> tags in a preview, so only code an administrator wrote may reach it. A rule over
// the whole tree: every call in system/ and extensions/, with its argument, is one of the four administrator settings.
$i51Calls = [];
foreach ([KALETA_SYSTEM, KALETA_ROOT . '/extensions'] as $i51Root) {
    if (!is_dir($i51Root)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($i51Root, FilesystemIterator::SKIP_DOTS)) as $i51Path) {
        if ($i51Path->getExtension() !== 'php' || $i51Path->getFilename() === 'Csp.php') {
            continue;
        }
        $i51Text = (string) file_get_contents($i51Path->getPathname());
        for ($i51At = strpos($i51Text, 'withNonce('); $i51At !== false; $i51At = strpos($i51Text, 'withNonce(', $i51At + 1)) {
            // the argument up to the parenthesis that closes the call
            for ($i51End = $i51At + 10, $i51Depth = 1; $i51Depth > 0 && $i51End < strlen($i51Text); $i51End++) {
                $i51Depth += ['(' => 1, ')' => -1][$i51Text[$i51End]] ?? 0;
            }
            $i51Calls[] = substr($i51Path->getPathname(), strlen(KALETA_ROOT) + 1) . ': ' . substr($i51Text, $i51At + 10, $i51End - $i51At - 11);
        }
    }
}
sort($i51Calls);
check('3.47 I51: Csp::withNonce is called only with administrator-set code – the head code, the external cookie bar, the marketing code and a page\'s head code (set in the administration only, never through MCP)',
    $i51Calls, [
        'system/src/Front/Seo.php: $marketing',
        'system/src/Front/Seo.php: $s->get(\'cookies_external_code\')',
        'system/src/Front/Seo.php: $s->get(\'head_code\')',
        'system/src/Front/Seo.php: (string) $meta[\'kod_hlavicky\']',
    ]);
$i51Mcp = (new ReflectionClassConstant(\Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue();
check('3.47 I51: … and none of those settings is writable through MCP (the page head code is refused by its own rule)',
    array_map(fn (string $key): int => (int) preg_match($i51Mcp, $key), ['head_code', 'marketing_code', 'cookies_external_code', 'site_name']),
    [0, 0, 0, 1]);
