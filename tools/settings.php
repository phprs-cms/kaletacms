<?php
/**
 * Settings registry (3.42, audit Q9): php tools/settings.php [key|prefix]
 *
 * Every setting is declared once in Core\Settings: its default in DEFAULTS and the rest in DECLARATIONS (3.50) – the form
 * type by tab (Settings::fields), secret, export, Claude's access (Settings::claudeMaySet), per language and lazy loading;
 * its older names come from Core\OldSettingsKeys (Settings::declaration).
 * This prints all of it for one key, the keys starting with a prefix, or every key. The unit test 3650 keeps the lists
 * consistent with DEFAULTS, 4540–4542 the declaration with the mirrors.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Settings;

$fields = Settings::fields();
$filter = $argv[1] ?? '';

$types = [];
foreach ($fields as $tab => $keys) {
    foreach ($keys as $key => $type) {
        $types[$key] = $tab . ': ' . $type;
    }
}

$shown = 0;
foreach (array_keys(Settings::DEFAULTS) as $key) {
    if ($filter !== '' && $key !== $filter && !str_starts_with($key, $filter)) {
        continue;
    }
    $d = Settings::declaration($key) ?? [];
    $flags = array_keys(array_filter([
        'secret' => $d['secret'] ?? false, 'export' => $d['export'] ?? false, 'per-language' => $d['per_language'] ?? false,
        'lazy' => $d['lazy'] ?? false, 'mcp' => Settings::claudeMaySet($key),
    ]));
    printf(
        "%-34s default %-14s type %-28s %s%s\n",
        $key,
        json_encode(mb_strimwidth((string) ($d['default'] ?? ''), 0, 12, '…'), JSON_UNESCAPED_UNICODE),
        mb_strimwidth($types[$key] ?? '-', 0, 28, '…'),
        implode(' ', $flags),
        ($d['old_names'] ?? []) !== [] ? '  (was ' . implode(', ', $d['old_names']) . ')' : '',
    );
    $shown++;
}
if ($shown === 0) {
    fwrite(STDERR, "No setting matches \"$filter\".\n");
    exit(1);
}
