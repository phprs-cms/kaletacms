<?php
/**
 * Migration-number guard (T1, Q12): php tools/check-migrations.php [tag]
 *
 * Core\Migration::files() keys the migrations by their number, so a duplicate number silently drops a file, and a number
 * merged below one a release already shipped never runs on the sites past it. This fails when:
 *  - two files in system/sql/migrace share a number, or a file there is not named NNNN-name.sql|php;
 *  - KALETA_DB_VERSION is not the highest number;
 *  - compared with the previous release tag (the newest v* tag reachable from HEAD, or the tag given): a shipped migration
 *    is gone or renamed, or a new migration has a number at or below the highest shipped one.
 * Without git tags (a shallow clone) the tag comparison is skipped and says so; CI fetches the full history.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$folder = 'system/sql/migrace';
$errors = [];

/** @param list<string> $names @return array<int, list<string>> number => file names */
function by_number(array $names, array &$errors, string $where): array
{
    $numbers = [];
    foreach ($names as $name) {
        if ($name === '' || $name === '.gitkeep') {
            continue;
        }
        if (!preg_match('/^(\d{4})-[a-z0-9-]+\.(sql|php)$/', $name, $m)) {
            $errors[] = "$where: $name is not named NNNN-name.sql or NNNN-name.php";
            continue;
        }
        $numbers[(int) $m[1]][] = $name;
    }
    ksort($numbers);

    return $numbers;
}

$current = by_number(array_map('basename', glob("$root/$folder/*") ?: []), $errors, 'now');
foreach ($current as $number => $names) {
    if (count($names) > 1) {
        $errors[] = sprintf('migration number %04d is used twice: %s', $number, implode(', ', $names));
    }
}
$latest = $current === [] ? 0 : max(array_keys($current));
preg_match('/const KALETA_DB_VERSION = (\d+);/', (string) file_get_contents("$root/system/bootstrap.php"), $m);
if ((int) ($m[1] ?? -1) !== $latest) {
    $errors[] = 'KALETA_DB_VERSION (' . ($m[1] ?? '?') . ") is not the highest migration number ($latest)";
}

$git = static function (string $args) use ($root): string {
    return trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null'));
};
$tag = $argv[1] ?? '';
if ($tag === '') {
    foreach (explode("\n", $git('tag --merged HEAD --sort=-v:refname')) as $candidate) {
        if (preg_match('/^v\d+\.\d+\.\d+$/', $candidate) && $git('rev-list -n 1 ' . escapeshellarg($candidate)) !== $git('rev-parse HEAD')) {
            $tag = $candidate;
            break;
        }
    }
}
if ($tag === '') {
    echo "  --     migration numbers: no release tag in this clone, the comparison with the last release is skipped\n";
} else {
    $listing = $git('ls-tree --name-only ' . escapeshellarg($tag . ':' . $folder));
    if ($listing === '') {
        $errors[] = "cannot list $folder of $tag";
    }
    $shipped = by_number(explode("\n", $listing), $errors, $tag);
    $shippedLatest = $shipped === [] ? 0 : max(array_keys($shipped));
    foreach ($shipped as $number => $names) {
        foreach ($names as $name) {
            if (!in_array($name, $current[$number] ?? [], true)) {
                $errors[] = "$name shipped in $tag and is gone or renamed – a shipped migration never changes its number or name";
            }
        }
    }
    foreach ($current as $number => $names) {
        if (!isset($shipped[$number]) && $number <= $shippedLatest) {
            $errors[] = sprintf('%s: number %04d is not above %04d, the highest in %s – sites past it would never run it', implode(', ', $names), $number, $shippedLatest, $tag);
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        echo "  CHYBA  $error\n";
    }
    exit(1);
}
echo "  ok     migration numbers: unique, KALETA_DB_VERSION = $latest" . ($tag !== '' ? ", every new one above $tag" : '') . "\n";
