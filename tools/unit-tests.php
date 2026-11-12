<?php
/**
 * Unit tests of the Kaleta core - no framework and no database: php tools/unit-tests.php [filter …]
 *
 * They guard what the smoke test (tools/test.sh) cannot detect: cryptography, parsing and text conversions.
 * A new test = another call of check('popis', $skutecne, $ocekavane) in a file of tools/tests/unit (tools/tests/README.md).
 *
 * The tests live in tools/tests/unit: bootstrap.php (check() and the set-up), then every NNNN-<topic>.php in name order,
 * all in one global scope. A filter runs only the files whose name starts with or contains it (php tools/unit-tests.php
 * 0310 fleet); what an earlier file prepares for them is replayed first, without counting its checks.
 */

declare(strict_types=1);

require __DIR__ . '/tests/unit/bootstrap.php';

$unitRunnerAll = [];
foreach (glob(__DIR__ . '/tests/unit/*') ?: [] as $unitRunnerFile) {
    $unitRunnerName = basename($unitRunnerFile);
    if (preg_match('/^\d{4}-[a-z0-9.-]+\.php$/', $unitRunnerName)) {
        $unitRunnerAll[substr($unitRunnerName, 0, 4)][] = $unitRunnerFile;
    } elseif (!in_array($unitRunnerName, ['bootstrap.php', 'README.md'], true)) {
        echo "  CHYBA  tools/tests/unit/{$unitRunnerName} is not NNNN-topic.php – it would never run\n";
        exit(1);
    }
}
ksort($unitRunnerAll, SORT_STRING);
foreach ($unitRunnerAll as $unitRunnerNumber => $unitRunnerSame) {
    if (count($unitRunnerSame) > 1) {
        echo "  CHYBA  tools/tests/unit: two files with the number {$unitRunnerNumber} – the order would not be clear\n";
        exit(1);
    }
}
$unitRunnerAll = array_merge(...array_values($unitRunnerAll));
$unitRunnerFilters = array_slice($argv, 1);
$unitRunnerSelected = $unitRunnerFilters === [] ? $unitRunnerAll : array_values(array_filter($unitRunnerAll, function (string $file) use ($unitRunnerFilters): bool {
    foreach ($unitRunnerFilters as $filter) {
        if ($filter !== '' && (str_starts_with(basename($file), $filter) || stripos(basename($file), $filter) !== false)) {
            return true;
        }
    }

    return false;
}));
if ($unitRunnerSelected === []) {
    echo '  CHYBA  no file of tools/tests/unit matches ' . implode(' ', $unitRunnerFilters) . "\n";
    exit(1);
}
$unitRunnerRun = $unitRunnerFilters === [] ? $unitRunnerAll : unit_test_closure($unitRunnerAll, $unitRunnerSelected);
foreach ($unitRunnerRun as $unitRunnerFile) {
    $unitRunnerQuiet = !in_array($unitRunnerFile, $unitRunnerSelected, true);
    require $unitRunnerFile;
}
$unitRunnerQuiet = false;
// 3.36 T3 (Q6): the checks that pin the text of a product file are counted – the number may only go down
echo $errors === 0 ? "  ok     jednotkové testy ({$total}; {$sourcePins} of them pin source text)\n" : "  NALEZENO CHYB: {$errors} z {$total}\n";
exit($errors === 0 ? 0 : 1);
