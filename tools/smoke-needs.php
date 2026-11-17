<?php

declare(strict_types=1);

/**
 * Kaleta – what a partial smoke run needs besides the sections it selected (3.45, code-quality step 7; tools/test.sh ONLY= / FROM=).
 *
 *   php tools/smoke-needs.php <smoke dir> <selected section file>…
 *
 * For every helper function a section in the run calls (a "name() {" line elsewhere, called at a command position) and every
 * upper-case shell variable it reads ($NAME, ${NAME…}) that nothing in the run assigns, the last earlier section that defines
 * it joins the run – and, in turn, what that section needs. Static and approximate, like the unit runner's closure
 * (unit_test_closure in tools/tests/unit/bootstrap.php): state an earlier section left in the database is not seen.
 * Prints one line per added section: "<file> <name>,<name>…".
 */
if (PHP_SAPI !== 'cli' || $argc < 2) {
    fwrite(STDERR, "usage: php tools/smoke-needs.php <smoke dir> <section file>…\n");
    exit(2);
}

$dir = rtrim($argv[1], '/');
$all = array_map('basename', glob($dir . '/[0-9][0-9][0-9][0-9]-*.sh') ?: []);
sort($all);
$texts = [];
$functions = []; // file => [name => true]
$variables = []; // file => [NAME => true]
foreach ($all as $file) {
    $texts[$file] = $text = (string) file_get_contents("$dir/$file");
    preg_match_all('/^([a-z_][a-z0-9_]*)\(\) *\{/m', $text, $m);
    $functions[$file] = array_fill_keys($m[1], true);
    preg_match_all('/(?:^|[;&|{(`]|\b(?:then|do|else|local|export|readonly))[ \t]*([A-Z_][A-Z0-9_]*)(?:\[[^\]]*\])?\+?=/m', $text, $m);
    preg_match_all('/\b(?:for|read(?: -[a-z]+)*)[ \t]+([A-Z_][A-Z0-9_]*)\b/', $text, $m2);
    $variables[$file] = array_fill_keys(array_merge($m[1], $m2[1]), true);
}
// what the suite itself defines: bootstrap.sh and test.sh (WORK, ROOT, B, JAR, ERRORS…)
$suite = (string) file_get_contents($dir . '/bootstrap.sh') . (string) file_get_contents(dirname($dir, 2) . '/test.sh');
preg_match_all('/(?:^|[;&|{(`]|\b(?:then|do|else|local|export|readonly))[ \t]*([A-Z_][A-Z0-9_]*)(?:\[[^\]]*\])?\+?=/m', $suite, $m);
$suiteVariables = array_fill_keys($m[1], true);

$run = array_fill_keys(array_values(array_intersect($all, array_map('basename', array_slice($argv, 2)))), true);
$queue = array_keys($run);
$added = [];
while ($queue !== []) {
    $file = array_shift($queue);
    $text = $texts[$file];
    $wanted = [];
    preg_match_all('/(?:^|[;&|{(`!])[ \t]*(?:(?:then|do|else|elif|if|while|until|!)[ \t]+)*([a-z_][a-z0-9_]*)\b(?!\()/m', $text, $m);
    foreach (array_unique($m[1]) as $name) {
        $wanted[] = ['f', $name];
    }
    preg_match_all('/\$\{?([A-Z_][A-Z0-9_]*)/', $text, $m);
    foreach (array_unique($m[1]) as $name) {
        if (!isset($variables[$file][$name]) && !isset($suiteVariables[$name])) {
            $wanted[] = ['v', $name];
        }
    }
    foreach ($wanted as [$kind, $name]) {
        $table = $kind === 'f' ? $functions : $variables;
        $inRun = false;
        foreach (array_keys($run) as $other) {
            if (isset($table[$other][$name]) && strcmp($other, $file) <= 0) {
                $inRun = true;
                break;
            }
        }
        if ($inRun) {
            continue;
        }
        $by = null;
        foreach ($all as $candidate) { // the last earlier section that defines it
            if (strcmp($candidate, $file) >= 0) {
                break;
            }
            if (isset($table[$candidate][$name])) {
                $by = $candidate;
            }
        }
        if ($by === null) {
            continue; // not a helper of the suite (a shell built-in, a program, an environment variable)
        }
        $added[$by][$name] = true;
        if (!isset($run[$by])) {
            $run[$by] = true;
            $queue[] = $by;
        }
    }
}
ksort($added);
foreach ($added as $by => $names) {
    echo $by . ' ' . implode(',', array_keys($names)) . "\n";
}
