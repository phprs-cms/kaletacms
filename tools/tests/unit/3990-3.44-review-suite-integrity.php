<?php

declare(strict_types=1);

/* ---------- 3.44 integration: a printed failure always fails tools/test.sh ---------- */
// A smoke run once ended with exit 0 although a section had printed CHYBA: ERRORS counts only in the suite's one shell, and a
// check run in a subshell (a pipe, $( ) or ( )) printed its failure without counting it. test.sh now writes each section's
// output to a file first and counts every line that starts with "  CHYBA  ". Here the loop of test.sh runs as it is, with two
// made-up sections: one counts its failure, one prints it from a subshell; the suite's end must report three errors.
$i344Script = (string) file_get_contents(KALETA_ROOT . '/tools/test.sh');
$i344From = strpos($i344Script, 'SECTION_N=0');
$i344To = $i344From === false ? false : strpos($i344Script, "\ndone\n", $i344From);
$i344Dir = sys_get_temp_dir() . '/kaleta-i344-' . bin2hex(random_bytes(4));
mkdir($i344Dir);
file_put_contents("$i344Dir/a.sh", "echo '  ok     a'\necho '  CHYBA  a counted'; ERRORS=\$((ERRORS+1))\n");
file_put_contents("$i344Dir/b.sh", "echo '  ok     b'\n( echo '  CHYBA  b in a subshell'; ERRORS=\$((ERRORS+1)) )\necho x | { echo '  CHYBA  b in a pipe'; ERRORS=\$((ERRORS+1)); }\n");
file_put_contents("$i344Dir/c.sh", "echo '  ok     c'\n");
$i344Loop = $i344From === false || $i344To === false ? 'exit 7' : substr($i344Script, $i344From, $i344To + 6 - $i344From);
file_put_contents("$i344Dir/run.sh", "set -euo pipefail\nWORK=" . escapeshellarg($i344Dir) . "; ERRORS=0\nmail_sink_guard() { :; }\nSMOKE_FILES=(\"\$WORK/a.sh\" \"\$WORK/b.sh\" \"\$WORK/c.sh\")\n"
    . $i344Loop . "echo \"ERRORS=\$ERRORS\"\n");
$i344Out = (string) shell_exec('bash ' . escapeshellarg("$i344Dir/run.sh") . ' 2>&1');
array_map('unlink', glob("$i344Dir/*") ?: []);
rmdir($i344Dir);
check('3.44 suite integrity: test.sh counts every printed CHYBA – a failure printed from a subshell is counted and named, the output keeps its order', [
    str_contains($i344Out, 'ERRORS=4'), str_contains($i344Out, 'b.sh: 2 failure(s) printed, 0 counted'),
    strpos($i344Out, '  ok     a') < strpos($i344Out, '  ok     b') && strpos($i344Out, '  ok     b') < strpos($i344Out, '  ok     c')],
    [true, true, true]);
check('3.44 suite integrity: the cleanup trap prints the output of a section that ends the suite early', [
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/tests/smoke/bootstrap.sh'), 'cat "$SECTION_OUT"')], [true]);
