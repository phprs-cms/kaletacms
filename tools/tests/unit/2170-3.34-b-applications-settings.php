<?php

declare(strict_types=1);

/* ---------- 3.34 (B): the settings tab of applications, the builder properties only with the feature, deletion for administrators ---------- */
$s337Module = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Applications.php');
$s337View = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/settings.php');
check('[source-pin] 3.34 B: the settings tab – administrators only; the four settings, the templates per language, who has the section', [
    substr_count($s337Module, "return \$this->error('Only an administrator can change the settings of applications.', 403);"),
    array_map(fn (string $key): bool => str_contains($s337View, 'name="' . $key . '"'), ['applications_months', 'applications_pool_months', 'applications_notify_full', 'applications_claude']),
    str_contains($s337View, 'name="<?= e($field) ?>_text"'), str_contains($s337View, 'data-applications-people'),
    array_map(fn (string $key): bool => array_key_exists($key, Kaleta\Core\Settings::DEFAULTS), ['applications_months', 'applications_pool_months', 'applications_notify_full', 'applications_claude', 'application_templates'])],
    [2, [true, true, true, true], true, true, [true, true, true, true, true]]);
$s337Form = fn (?array $extensions): array => array_keys(array_column(Kaleta\Builder\Build::schema(true, 'en', false, $extensions)['prvky'], 'vlastnosti', 'typ')['formular']);
check('3.34 B: Purpose and the pool consent are offered in the builder only while job applications are on; the full schema (contracts, null) keeps them', [
    array_values(array_intersect(['ucel', 'souhlas_uchovani'], $s337Form(['poptavky']))), array_values(array_intersect(['ucel', 'souhlas_uchovani'], $s337Form(['poptavky', 'applications']))),
    array_values(array_intersect(['ucel', 'souhlas_uchovani'], $s337Form(null)))],
    [[], ['ucel', 'souhlas_uchovani'], ['ucel', 'souhlas_uchovani']]);
