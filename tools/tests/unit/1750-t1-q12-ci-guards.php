<?php

declare(strict_types=1);

/* ---------- T1 (Q12): CI guards ---------- */
$t12Ci = (string) file_get_contents(KALETA_ROOT . '/.github/workflows/kontrola.yml');
$t12Browser = (string) file_get_contents(KALETA_ROOT . '/tools/test-browser.sh');
check('T1 Q12: CI checks the PHPStan download by its digest, runs node --check on every JS file and the migration-number guard; the browser packages are pinned exactly', [
    (bool) preg_match('~curl -sSL -o /tmp/phpstan\.phar \S+\n\s+echo "[0-9a-f]{64}  /tmp/phpstan\.phar" \| sha256sum -c -\n~', $t12Ci),
    str_contains($t12Ci, "git ls-files -z '*.js' '*.mjs' | xargs -0 -n1 node --check"), str_contains($t12Ci, 'run: php tools/check-migrations.php'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/test-migrations.sh'), 'php "$ROOT/tools/check-migrations.php"'),
    (bool) preg_match('/PLAYWRIGHT_CORE_VERSION=\d+\.\d+\.\d+; AXE_CORE_VERSION=\d+\.\d+\.\d+\n/', $t12Browser), (bool) preg_match('/(playwright-core|axe-core)@\d+[ "\n]/', $t12Browser . (string) file_get_contents(KALETA_ROOT . '/tools/screenshots.sh'))],
    [true, true, true, true, true, false]);
// the guard itself: no duplicate number, KALETA_DB_VERSION is the highest (the comparison with the last tag needs git – CI and test-migrations.sh)
$t12Numbers = array_map(fn (string $f): int => (int) substr(basename($f), 0, 4), glob(KALETA_SYSTEM . '/sql/migrace/[0-9]*') ?: []);
check('T1 Q12: no migration number is used twice and KALETA_DB_VERSION is the highest', [count($t12Numbers) === count(array_unique($t12Numbers)), max($t12Numbers) === KALETA_DB_VERSION], [true, true]);

