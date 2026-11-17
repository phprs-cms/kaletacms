<?php

declare(strict_types=1);

/* ---------- 3.45 C (code-quality step 7): ONLY= and FROM= for tools/test.sh ---------- */
// smoke_select of test.sh runs as it is over made-up file names: which sections a partial run sources, whether it installs
// (a setup section 0020–0040 selected) or restores the snapshot, whether it runs the unit tests, and that a pattern matching
// no section fails instead of giving a green run of nothing.
$c345Script = (string) file_get_contents(KALETA_ROOT . '/tools/test.sh');
$c345Select = preg_match('/^smoke_select\(\) \{\n.*?^\}\n/ms', $c345Script, $c345M) === 1 ? $c345M[0] : "smoke_select() { return 9; }\n";
$c345Files = ['0010-syntaxe-php.sh', '0020-cista-databaze.sh', '0030-jednotkove-testy.sh', '0040-instalace.sh', '0050-web.sh', '0060-administrace.sh',
    '1500-3.26-comments.sh', '1520-3.27-a-tasks.sh', '2300-3.35-a-passkey-sign-in.sh', '2400-3.35-passkey-confirmations.sh', '3900-3.44-a-quote.sh'];
$c345Run = function (string $only, string $from) use ($c345Select, $c345Files): string {
    $script = 'set -euo pipefail' . "\n" . $c345Select . 'SMOKE_FILES=(' . implode(' ', array_map(fn (string $f): string => escapeshellarg('/x/smoke/' . $f), $c345Files)) . ")\n"
        . 'ONLY=' . escapeshellarg($only) . ' FROM=' . escapeshellarg($from) . "\n"
        . 'if smoke_select > /dev/null; then s=0; else s=$?; fi; names=(); for f in "${SMOKE_FILES[@]}"; do names+=("${f##*/}"); done' . "\n"
        . 'echo "$s|${SMOKE_PARTIAL:-}|${SMOKE_SETUP_PICKED:-}|${SMOKE_UNIT:-}|${SMOKE_TOTAL:-}|${SMOKE_PREPARE:-}|$(printf "%s " "${names[@]}" | cut -c1-1000)"';

    return trim((string) shell_exec('bash -c ' . escapeshellarg($script) . ' 2>&1'));
};
$c345Setup = '0020-cista-databaze.sh 0030-jednotkove-testy.sh 0040-instalace.sh ';
$c345Prep = '0060-administrace.sh|' . $c345Setup . '0060-administrace.sh ';
check('3.45 C (step 7): smoke_select – no ONLY/FROM keeps every section; ONLY takes numbers and parts of names (comma list); FROM starts at the first number >= it or a matching name; both combine; the setup sections and 0060 (sign-in, only preparing) stay', [
    $c345Run('', ''),
    $c345Run('1520', ''),
    $c345Run('0060,passkey', ''),
    $c345Run('', '1510'),
    $c345Run('', 'passkey'),
    $c345Run('passkey', '2400'),
    $c345Run('0030', ''),
    $c345Run('0010,0050', ''),
    $c345Run('nothing-like-this', ''),
    $c345Run('', '9999'),
], array_map('rtrim', [
    '0|0|0|1|11||' . implode(' ', $c345Files) . ' ',
    '0|1|0|0|11|' . $c345Prep . '1520-3.27-a-tasks.sh ',
    '0|1|0|0|11||' . $c345Setup . '0060-administrace.sh 2300-3.35-a-passkey-sign-in.sh 2400-3.35-passkey-confirmations.sh ',
    '0|1|0|0|11|' . $c345Prep . '1520-3.27-a-tasks.sh 2300-3.35-a-passkey-sign-in.sh 2400-3.35-passkey-confirmations.sh 3900-3.44-a-quote.sh ',
    '0|1|0|0|11|' . $c345Prep . '2300-3.35-a-passkey-sign-in.sh 2400-3.35-passkey-confirmations.sh 3900-3.44-a-quote.sh ',
    '0|1|0|0|11|' . $c345Prep . '2400-3.35-passkey-confirmations.sh ',
    '0|1|1|1|11|' . $c345Prep,
    '0|1|0|0|11|0060-administrace.sh|0010-syntaxe-php.sh ' . $c345Setup . '0050-web.sh 0060-administrace.sh ',
    '1|1|0|0|11|0060-administrace.sh|' . implode(' ', $c345Files) . ' ',
    '1|1|0|0|11|0060-administrace.sh|' . implode(' ', $c345Files) . ' ',
]));

// tools/smoke-needs.php: a helper and a variable a selected section uses come from the last earlier section that defines
// them, and what that one needs in turn; a section already in the run, a later one and a shell command add nothing
$c345Dir = sys_get_temp_dir() . '/kaleta-c345-' . bin2hex(random_bytes(4));
mkdir("$c345Dir/tests/smoke", 0777, true);
file_put_contents("$c345Dir/test.sh", "WORK=x\n");
file_put_contents("$c345Dir/tests/smoke/bootstrap.sh", "ROOT=x\n");
foreach ([
    '0100-a.sh' => "mcp() { curl -s \"\$B\"; }\nTOKEN_A=1\n",
    '0200-b.sh' => "mcp() { curl -s \"\$B\" -H x; }\npage_id() { echo \"\$TOKEN_A\"; }\n",
    '0300-c.sh' => "IDS=\$(page_id)\nls -l\n",
    '0400-d.sh' => "expect x \"\$(mcp list)\" y; echo \"\$IDS \$WORK \$ROOT \$LATER\"; cat | grep x\n",
    '0500-e.sh' => "LATER=1\n",
] as $c345Name => $c345Body) {
    file_put_contents("$c345Dir/tests/smoke/$c345Name", $c345Body);
}
$c345Needs = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(KALETA_ROOT . '/tools/smoke-needs.php') . ' ' . escapeshellarg("$c345Dir/tests/smoke") . ' ' . escapeshellarg("$c345Dir/tests/smoke/0400-d.sh"));
$c345NeedsC = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(KALETA_ROOT . '/tools/smoke-needs.php') . ' ' . escapeshellarg("$c345Dir/tests/smoke") . ' ' . escapeshellarg("$c345Dir/tests/smoke/0400-d.sh") . ' ' . escapeshellarg("$c345Dir/tests/smoke/0300-c.sh"));
array_map('unlink', glob("$c345Dir/tests/smoke/*") ?: []);
unlink("$c345Dir/test.sh");
rmdir("$c345Dir/tests/smoke");
rmdir("$c345Dir/tests");
rmdir($c345Dir);
check('3.45 C (step 7): smoke-needs – the last earlier definer of a called helper and a read variable joins the run, transitively; one already in the run adds nothing', [$c345Needs, $c345NeedsC],
    ["0100-a.sh TOKEN_A\n0200-b.sh mcp\n0300-c.sh IDS\n", "0100-a.sh TOKEN_A\n0200-b.sh page_id\n"]);

// the loop of test.sh: a section that only prepares a partial run shows its failures as (prep) and does not count them
$c345From = strpos($c345Script, 'SECTION_N=0');
$c345To = $c345From === false ? false : strpos($c345Script, "\ndone\n", $c345From);
$c345Tmp = sys_get_temp_dir() . '/kaleta-c345l-' . bin2hex(random_bytes(4));
mkdir($c345Tmp);
file_put_contents("$c345Tmp/a.sh", "echo '  CHYBA  a prepares'; ERRORS=\$((ERRORS+1))\n");
file_put_contents("$c345Tmp/b.sh", "echo '  CHYBA  b selected'; ERRORS=\$((ERRORS+1))\n");
file_put_contents("$c345Tmp/run.sh", "set -euo pipefail\nWORK=" . escapeshellarg($c345Tmp) . "; ERRORS=0; SMOKE_PREPARE=' a.sh'\nmail_sink_guard() { :; }\nSMOKE_FILES=(\"\$WORK/a.sh\" \"\$WORK/b.sh\")\n"
    . ($c345From === false || $c345To === false ? 'exit 7' : substr($c345Script, $c345From, $c345To + 6 - $c345From)) . "echo \"ERRORS=\$ERRORS\"\n");
$c345Loop = (string) shell_exec('bash ' . escapeshellarg("$c345Tmp/run.sh") . ' 2>&1');
array_map('unlink', glob("$c345Tmp/*") ?: []);
rmdir($c345Tmp);
check('3.45 C (step 7): a preparing section\'s failure is shown as (prep) and not counted; a selected section\'s still is', [
    str_contains($c345Loop, '  (prep) a prepares'), str_contains($c345Loop, 'a.sh only prepares this partial run: its 1 failure(s)'), str_contains($c345Loop, '  CHYBA  b selected'), str_contains($c345Loop, 'ERRORS=1')],
    [true, true, true, true]);

// the full run keeps its strictness: the section loop (3990-…) is untouched by the selection, the last line names a partial
// run, the snapshot is saved after the install and restored before the site's server starts, and the install is skipped only
// when restored
$c345Smoke = KALETA_ROOT . '/tools/tests/smoke/';
$c345Install = (string) file_get_contents($c345Smoke . '0040-instalace.sh');
$c345Copy = (string) file_get_contents($c345Smoke . '0020-cista-databaze-a-kopie-projektu.sh');
check('3.45 C (step 7): the partial run is wired – selection before the loop, restore in 0020 before the server, the install skipped only when restored, the snapshot saved at its end, the last line says "partial run"', [
    strpos($c345Script, 'smoke_select || exit 1') < strpos($c345Script, 'SECTION_N=0'),
    str_contains($c345Script, 'echo "VŠE V POŘÁDKU$SMOKE_SCOPE"') && str_contains($c345Script, '(partial run: '),
    strpos($c345Copy, 'smoke_snapshot_restore') < strpos($c345Copy, 'exec php -S "127.0.0.1:$PORT" system/dev-router.php'),
    (bool) preg_match('/^if \[ "\$\{SMOKE_RESTORE:-0\}" = 1 \]; then mail_sink_default; .*return 0; fi$/m', $c345Install),
    str_ends_with(rtrim($c345Install), 'smoke_snapshot_save'),
    str_contains((string) file_get_contents($c345Smoke . '0030-jednotkove-testy.sh'), 'if [ "${SMOKE_UNIT:-1}" = 1 ]; then php "$ROOT/tools/unit-tests.php"'),
], [true, true, true, true, true, true]);

/* ---------- 3.45 C (Q11, T6): PHPStan level 6 blocking, level 8 reported, templates at level 2 ---------- */
$c345Ci = (string) file_get_contents(KALETA_ROOT . '/.github/workflows/kontrola.yml');
$c345Level8 = substr($c345Ci, (int) strpos($c345Ci, '  phpstan-level8:'), 2000);
check('3.45 C (Q11): phpstan.neon.dist is level 6, the templates have their own level-2 config, CI blocks on both and reports level 8 without failing; the views config stays out of the package', [
    (bool) preg_match('/^    level: 6$/m', (string) file_get_contents(KALETA_ROOT . '/phpstan.neon.dist')),
    (bool) preg_match('/^    level: 2$/m', (string) file_get_contents(KALETA_ROOT . '/phpstan-views.neon.dist')) && str_contains((string) file_get_contents(KALETA_ROOT . '/phpstan-views.neon.dist'), "        - system/views\n"),
    str_contains($c345Ci, 'run: php -d memory_limit=2G /tmp/phpstan.phar analyse -c phpstan.neon.dist --no-progress'),
    str_contains($c345Ci, 'run: php -d memory_limit=2G /tmp/phpstan.phar analyse -c phpstan-views.neon.dist --no-progress'),
    str_contains($c345Level8, 'continue-on-error: true') && str_contains($c345Level8, '--level=8 --no-progress --error-format=json > /tmp/level8.json || true'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/release.php'), "'phpstan-views.neon.dist'"),
], [true, true, true, true, true, true]);
