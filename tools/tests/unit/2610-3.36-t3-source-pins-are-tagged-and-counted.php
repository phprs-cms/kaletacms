<?php

declare(strict_types=1);

// 3.36 T3 (Q6): a check that compares the text of a product file passes when the behaviour is broken and fails when a
// correct refactoring moves a line. Each one carries [source-pin] in its label, the runner prints how many there are, and
// the number may only go down: no new pins – write a behaviour test; replace a pin with one when its code is next touched.
// Structural rules that walk the whole tree (no Czech query parameters, every table in the names map …) are not pins.

/** The pins of 3.36.0 T3; lower it when you replace pins, never raise it. */
const UNIT_SOURCE_PIN_CEILING = 306;

$t3pAll = [];
$t3pUntagged = [];
foreach (glob(KALETA_ROOT . '/tools/tests/unit/[0-9][0-9][0-9][0-9]-*.php') ?: [] as $t3pFile) {
    foreach (unit_source_pins($t3pFile) as $t3pPin) {
        $t3pAll[] = $t3pPin;
        if (!$t3pPin['tagged']) {
            $t3pUntagged[] = basename($t3pFile) . ':' . $t3pPin['line'];
        }
    }
}
$t3pTagged = 0;
foreach (glob(KALETA_ROOT . '/tools/tests/unit/[0-9][0-9][0-9][0-9]-*.php') ?: [] as $t3pFile) {
    $t3pTokens = array_values(array_filter(token_get_all((string) file_get_contents($t3pFile)), fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT], true)));
    foreach ($t3pTokens as $t3pI => $t3pToken) { // a label that starts with the tag: check( '[source-pin] …
        $t3pTagged += is_array($t3pToken) && $t3pToken[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]\[source-pin\] /', $t3pToken[1]) === 1
            && ($t3pTokens[$t3pI - 1] ?? null) === '(' && is_array($t3pTokens[$t3pI - 2] ?? null) && $t3pTokens[$t3pI - 2][1] === 'check' ? 1 : 0;
    }
}
check('3.36 T3 (Q6): every check that pins the text of a product file says so: the tag source-pin in brackets opens its label', $t3pUntagged, []);
check('3.36 T3 (Q6): … and only those carry the tag (a pin replaced by a behaviour test loses it)', $t3pTagged, count($t3pAll));
check('3.36 T3 (Q6): no new source pins – ' . count($t3pAll) . ' now, at most ' . UNIT_SOURCE_PIN_CEILING . ' (write a behaviour test instead; lower the ceiling when you replace pins)',
    count($t3pAll) <= UNIT_SOURCE_PIN_CEILING, true);

$t3pProbe = sys_get_temp_dir() . '/kaleta-t3p-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($t3pProbe, <<<'PHP'
<?php
$a = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Db.php');
$part = substr($a, 10, 300);
check('pin through a variable', str_contains($a, 'x'), true);
check('pin through a part', strpos($part, 'y') !== false, true);
check('[source-pin] direct', str_contains((string) file_get_contents(KALETA_ROOT . '/image/admin.js'), 'z'), true);
check('a rule over the tree', array_filter(glob(KALETA_SYSTEM . '/src/*.php'), fn ($f) => str_contains(file_get_contents($f), 'x')), []);
check('behaviour', Kaleta\Core\Db::quoteName('a'), '`a`');
check('a test file is no product', str_contains((string) file_get_contents(KALETA_ROOT . '/tools/test.sh'), 'x'), true);
$a = 'a plain text';
check('the variable no longer holds a source', str_contains($a, 'plain'), true);
PHP);
check('3.36 T3 (Q6): the pin finder sees a search in a product file directly, through a variable and through a part of it – and not a rule over the tree, a behaviour, a test file or a reused variable',
    array_map(fn (array $p): string => $p['line'] . ($p['tagged'] ? ' tagged' : ''), unit_source_pins($t3pProbe)), ['4', '5', '6 tagged']);
unlink($t3pProbe);
