<?php

declare(strict_types=1);

/* ---------- 3.49 P1 (the 3.48 carry-over): the browser steps in files – tools/tests/browser/lib.mjs and NNNN-<topic>.mjs ---------- */

$p1Dir = KALETA_ROOT . '/tools/tests/browser';
$p1Files = array_map('basename', glob($p1Dir . '/*') ?: []);
$p1Steps = array_values(array_filter($p1Files, fn (string $f): bool => $f !== 'lib.mjs'));
$p1Numbers = array_map(fn (string $f): string => substr($f, 0, 4), $p1Steps);
check('3.49 P1: the folder holds lib.mjs and step files named NNNN-<topic>.mjs (lower case, digits, dots and hyphens), each number once, in steps of 10',
    [in_array('lib.mjs', $p1Files, true), array_values(array_filter($p1Steps, fn (string $f): bool => preg_match('/^\d{4}-[a-z0-9.-]+\.mjs$/D', $f) !== 1)),
        count($p1Numbers) === count(array_unique($p1Numbers)), array_values(array_filter($p1Numbers, fn (string $n): bool => (int) $n % 10 !== 0)), count($p1Steps) >= 30],
    [true, [], true, [], true]);

// a step file is one default export – nothing at the top level of the module that could carry a value from one run (or one
// file) to the next; what the steps share is t, what changes is t.state
$p1Shape = [];
foreach ($p1Steps as $p1File) {
    $p1Text = (string) file_get_contents($p1Dir . '/' . $p1File);
    if (preg_match('/\A(?:\/\/[^\n]*\n)*export default async \(t\) => \{\n.*\n\};\n\z/s', $p1Text) !== 1 || preg_match('/^(?:let|var|const|import|export(?! default async \(t\)))\b/m', $p1Text) === 1
        || !str_contains($p1Text, 'await step(')) {
        $p1Shape[] = $p1File;
    }
}
check('3.49 P1: every step file is `export default async (t) => { … };` with its steps – no import, no module variable, no second export', $p1Shape, []);

$p1Runner = (string) file_get_contents(KALETA_ROOT . '/tools/test-browser.mjs');
$p1Lib = (string) file_get_contents($p1Dir . '/lib.mjs');
check('3.49 P1: tools/test-browser.mjs only runs the folder (no step of its own), refuses another name and a number used twice; the five values that change live in state; STEPS_FILE lists the steps with their proof() calls',
    [str_contains($p1Runner, 'await step('), str_contains($p1Runner, "readdirSync(dir).filter((f) => f !== 'lib.mjs').sort()"), str_contains($p1Runner, 'is not NNNN-topic.mjs – it would never run'),
        str_contains($p1Runner, 'two files with the number'), str_contains($p1Lib, "export const state = { where: '', inertHandlers: false, steps: 0, newsItem: '', equipment: 0 };"),
        preg_match('/^let (?!proofCalls = 0;$)/m', $p1Lib), str_contains($p1Lib, 'appendFileSync(STEPS_FILE, `${name}\t${proofCalls - proofsBefore}\n`)')],
    [false, true, true, true, true, 0, true]);
