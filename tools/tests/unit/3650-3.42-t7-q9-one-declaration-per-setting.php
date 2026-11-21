<?php

declare(strict_types=1);

use Kaleta\Core\Settings;

/* ---------- 3.42 T7 (audit Q9): every setting is declared once in Settings::DEFAULTS, the other lists only refer to it ---------- */

$q9Declared = array_keys(Settings::DEFAULTS);
$q9Fields = Kaleta\Core\Settings::fields();
$q9Lists = [
    'Vault::SECRET_SETTINGS' => Kaleta\Core\Vault::SECRET_SETTINGS,
    'SiteExport::SETTINGS' => Kaleta\Core\SiteExport::SETTINGS,
    'Settings::PER_LANGUAGE' => Settings::PER_LANGUAGE,
    'Settings::LAZY' => Settings::LAZY,
    'Blueprint::SETTINGS' => Kaleta\Core\Blueprint::SETTINGS,
    'Booking::SETTINGS' => array_keys(Kaleta\Core\Booking::SETTINGS),
    'OldSettingsKeys::KEYS (new names)' => array_values(Kaleta\Core\OldSettingsKeys::KEYS),
    'TestSeams::ADDRESSES' => Kaleta\Core\TestSeams::ADDRESSES,
    'Settings::fields()' => array_merge(...array_map('array_keys', array_values($q9Fields))),
];
check('3.42 Q9: every key a list names (secret, export, per language, lazy, blueprint, booking, old names, test seams, form types) is declared in Settings::DEFAULTS',
    array_filter(array_map(fn (array $keys): array => array_values(array_diff($keys, $q9Declared)), $q9Lists)), []);

// the code reads and writes settings by literal names – each must be declared (a key built at run time, "site_name_" . $lang, is not a literal)
$q9Undeclared = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_ROOT . '/system', FilesystemIterator::SKIP_DOTS)) as $q9File) {
    if ($q9File->getExtension() !== 'php') {
        continue;
    }
    preg_match_all('/(?:settings\(\)|settings|\$s)->(?:get|set|bool|int|secretUnreadable)\(\s*\'([a-z0-9_]+)\'\s*[,)]/', (string) file_get_contents($q9File->getPathname()), $q9m);
    foreach (array_diff($q9m[1], $q9Declared) as $q9Key) {
        $q9Undeclared[$q9Key] = substr($q9File->getPathname(), strlen(KALETA_ROOT) + 1);
    }
}
ksort($q9Undeclared);
check('3.42 Q9: no setting is read or written under a literal name that Settings::DEFAULTS does not declare (the 16 stray keys of the audit are declared)', $q9Undeclared, []);
check('3.42 Q9: the stray keys of the audit are declared with an empty default (reading them gives what it gave before)', array_map(fn (string $k): string => Settings::DEFAULTS[$k] ?? 'missing', [
    'newsletter_service', 'newsletter_key', 'newsletter_list', 'newsletter_webhook', 'google_locations', 'google_rating', 'google_reviews', 'google_reviews_synced',
    'cookie_scan', 'company_address', 'company_vat', 'addons_enabled', 'backup_test_url', 'newsletter_test_url', 'webhook_test_url',
]), array_fill(0, 15, ''));

// the form: every row of a settings tab has a type in FIELDS of that tab, and every typed key appears in its tab's view
$q9TypesOnly = ['vzhled', 'presmerovani']; // types for values from the Claude connection, saved by other screens
$q9Rows = [];
foreach (glob(KALETA_ROOT . '/system/views/admin/settings/*.php') as $q9View) {
    $q9Tab = basename($q9View, '.php');
    $q9Text = (string) file_get_contents($q9View);
    preg_match_all('/\$field\(\s*\'([a-z0-9_]+)\'/', $q9Text, $q9m);
    if ($q9m[1] !== [] && $q9Tab !== 'list') { // list.php defines $field itself
        $q9Rows['row without a type'][$q9Tab] = array_values(array_diff($q9m[1], array_keys($q9Fields[$q9Tab] ?? [])));
    }
    if (isset($q9Fields[$q9Tab])) {
        $q9Rows['type without a row'][$q9Tab] = array_values(array_filter(array_keys($q9Fields[$q9Tab]), fn (string $k): bool => !str_contains($q9Text, "'$k'") && !str_contains($q9Text, "\"$k\"") && !str_starts_with($k, 'social_')));
    }
}
check('3.42 Q9: every $field() row of a settings view has a type in FIELDS of its tab, and every type of a tab appears in its view (social_* are drawn in a loop)',
    array_filter(array_map('array_filter', $q9Rows)), []);
check('3.42 Q9: every FIELDS tab has its view, except the two that only type values for the Claude connection',
    array_values(array_filter(array_keys($q9Fields), fn (string $tab): bool => !is_file(KALETA_ROOT . "/system/views/admin/settings/$tab.php") && !in_array($tab, $q9TypesOnly, true))), []);

// one place to read what the code knows about a setting
check('3.42 Q9: Settings::declaration() collects the default, secret, export, per language, lazy and the older names of a key; null for an unknown one', [
    Settings::declaration('newsletter_key'), Settings::declaration('site_name'), Settings::declaration('look_draft')['lazy'] ?? null, Settings::declaration('no_such_setting'),
], [
    ['default' => '', 'secret' => true, 'export' => false, 'per_language' => false, 'lazy' => false, 'old_names' => ['newsletter_klic']],
    ['default' => 'Můj web', 'secret' => false, 'export' => true, 'per_language' => true, 'lazy' => false, 'old_names' => array_keys(array_filter(Kaleta\Core\OldSettingsKeys::KEYS, fn (string $n): bool => $n === 'site_name'))],
    true, null,
]);
check('3.42 Q9: a lazily loaded setting is never a secret, exported, per language or a translated default (those are read on every page or need the full row set)',
    array_values(array_filter(Settings::LAZY, fn (string $k): bool => in_array($k, [...Kaleta\Core\Vault::SECRET_SETTINGS, ...Kaleta\Core\SiteExport::SETTINGS, ...Settings::PER_LANGUAGE, 'maintenance_text', 'cookies_text'], true))), []);
