<?php

declare(strict_types=1);

// 3.43 B (code-quality step 4): a bare `wait` in a smoke section waits for every background job of the suite's one shell –
// the php -S servers and the mail sink too – and never returns (a run once hung for 20 hours). A section waits for its own
// jobs with await_pids <pid>… (bootstrap.sh) or with `wait "$PID"`; `wait "${PIDS[@]}"` and an unquoted `wait $A $B` turn
// bare when the list is empty. The sections are read as shell: strings, comments and here-documents are masked first.

/**
 * The text of a shell script with everything that is not code (the inside of quoted strings, comments, here-document bodies)
 * replaced by "x" – same length, same line breaks – so a search for a command finds only commands. $( … ) inside a string is code.
 */
function r343b_mask_shell(string $s): string
{
    $out = $s;
    $n = strlen($s);
    $stack = [['code', 0]];
    $heredocs = [];
    $mask = function (int $from, int $to, string $with = 'x') use (&$out): void {
        for ($k = $from; $k < $to; $k++) {
            if ($out[$k] !== "\n") {
                $out[$k] = $with;
            }
        }
    };
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        $top = count($stack) - 1;
        $ctx = $stack[$top][0];
        if ($c === "\n" && $heredocs !== [] && $ctx === 'code') {
            $pos = $i + 1;
            foreach ($heredocs as $word) { // each body ends at a line that is just its word
                while ($pos < $n) {
                    $end = strpos($s, "\n", $pos);
                    $end = $end === false ? $n : $end;
                    $line = trim(substr($s, $pos, $end - $pos));
                    $mask($pos, $end);
                    $pos = $end + 1;
                    if ($line === $word) {
                        break;
                    }
                }
            }
            $heredocs = [];
            $i = $pos - 1;
            continue;
        }
        if ($ctx === 'code') {
            if ($c === '\\') {
                $i++;
            } elseif ($c === '#' && ($i === 0 || ctype_space($s[$i - 1]) || $s[$i - 1] === ';')) {
                $end = strpos($s, "\n", $i);
                $end = $end === false ? $n : $end;
                $mask($i, $end, ' ');
                $i = $end - 1;
            } elseif ($c === "'") {
                $stack[] = [$i > 0 && $s[$i - 1] === '$' ? 'ansi' : 'sq', 0];
            } elseif ($c === '"') {
                $stack[] = ['dq', 0];
            } elseif ($c === '$' && ($s[$i + 1] ?? '') === '(') {
                $stack[] = ['code', 0];
                $i++;
            } elseif ($c === '(') {
                $stack[$top][1]++;
            } elseif ($c === ')') {
                if ($stack[$top][1] > 0) {
                    $stack[$top][1]--;
                } elseif ($top > 0) {
                    array_pop($stack);
                }
            } elseif ($c === '<' && substr($s, $i, 2) === '<<' && ($s[$i + 2] ?? '') !== '<' && preg_match('/\G<<-?[ \t]*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1/', $s, $m, 0, $i) === 1) {
                $heredocs[] = $m[2];
                $i += strlen($m[0]) - 1;
            }
        } elseif ($ctx === 'dq') {
            if ($c === '\\') {
                $mask($i, min($i + 2, $n));
                $i++;
            } elseif ($c === '"') {
                array_pop($stack);
            } elseif ($c === '$' && ($s[$i + 1] ?? '') === '(') {
                $stack[] = ['code', 0];
                $i++;
            } else {
                $mask($i, $i + 1);
            }
        } else { // sq, ansi ($'…' knows escapes)
            if ($ctx === 'ansi' && $c === '\\') {
                $mask($i, min($i + 2, $n));
                $i++;
            } elseif ($c === "'") {
                array_pop($stack);
            } else {
                $mask($i, $i + 1);
            }
        }
    }

    return $out;
}

/** @return list<string> "line: command" of every wait in a shell text that is not `wait "$ONE_VARIABLE"` */
function r343b_bare_waits(string $s): array
{
    $masked = r343b_mask_shell($s);
    preg_match_all('/(?:^|[;&|(){}!]|\b(?:then|do|else|elif)\b)[ \t]*\bwait\b([^;&|)}\n]*)/m', $masked, $found, PREG_OFFSET_CAPTURE);
    $bad = [];
    foreach ($found[1] as [$args, $at]) {
        $raw = trim(substr($s, $at, strlen(rtrim($args))));
        $plain = trim((string) preg_replace('/\s*\d*>>?\s*\S+/', '', $raw)); // redirections such as 2>/dev/null
        if (preg_match('/^"\$\{?[A-Za-z_][A-Za-z0-9_]*\}?"$/', $plain) !== 1) {
            $bad[] = (substr_count(substr($s, 0, $at), "\n") + 1) . ': ' . trim('wait ' . $raw);
        }
    }

    return $bad;
}

$r343bProbe = <<<'SH'
sleep 1 & wait
x=1; wait # a comment
wait $A $B
wait "${PIDS[@]}" || true
true && wait
for p in $L; do wait; done
echo "then wait for it"; expect "a label" "$(sq "SELECT 1")|wait" "x"
wait "$SERVER_PID" 2>/dev/null || true
for p in $L; do wait "$p"; done
await_pids "${PIDS[@]}"
# a bare wait in a comment
cat > "$WORK/x.php" <<'PHP'
<?php // wait
wait
PHP
echo 'it'"'"'s'; wait "${ONE}"
SH;
check('3.43 B (step 4): the bare-wait finder flags a bare wait, an unquoted and an array wait, and not strings, comments, here-documents or wait "$PID"',
    r343b_bare_waits($r343bProbe), ['1: wait', '2: wait', '3: wait $A $B', '4: wait "${PIDS[@]}"', '5: wait', '6: wait']);

$r343bBad = [];
foreach (glob(KALETA_ROOT . '/tools/tests/smoke/[0-9][0-9][0-9][0-9]-*.sh') ?: [] as $r343bFile) {
    foreach (r343b_bare_waits((string) file_get_contents($r343bFile)) as $r343bHit) {
        $r343bBad[] = basename($r343bFile) . ':' . $r343bHit;
    }
}
check('3.43 B (step 4): no smoke section waits for all background jobs – await_pids <pid>… or wait "$PID" instead', $r343bBad, []);

// await_pids itself: with no pid it returns at once (a background job of the same shell is still running), with pids it waits
$r343bDef = preg_match('/^await_pids\(\) \{.*\}$/m', (string) file_get_contents(KALETA_ROOT . '/tools/tests/smoke/bootstrap.sh'), $r343bM) === 1 ? $r343bM[0] : 'await_pids() { :; }';
$r343bStart = microtime(true);
$r343bOut = (string) shell_exec('bash -c ' . escapeshellarg($r343bDef . '; sleep 3 & S=$!; await_pids; echo "empty:$?"; (exit 4) & P=$!; await_pids "$P"; echo "one:$?"; kill $S'));
check('3.43 B (step 4): await_pids without pids returns at once, with a pid it waits and passes on its status',
    [trim($r343bOut), microtime(true) - $r343bStart < 2.5], ["empty:0\none:4", true]);
