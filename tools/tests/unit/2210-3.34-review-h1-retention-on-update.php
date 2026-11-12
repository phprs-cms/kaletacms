<?php

declare(strict_types=1);

use Kaleta\Core\Applications;

/* ---------- 3.34 review H1: the update never shortens the retention; moved applications wait for the administrator ---------- */
check('3.34 review H1: the starting retention – job_applications_months when set, otherwise the enquiries\' months (0 = keep stays keep), at most 120', [
    Applications::startingRetention(4, 24), Applications::startingRetention(0, 24), Applications::startingRetention(0, 0), Applications::startingRetention(0, 36), Applications::startingRetention(200, 0)],
    [4, 24, 0, 36, 120]);
$h1Migration = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0120-applications-move.php');
$h1Store = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php');
$h1Module = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Applications.php');
check('[source-pin] 3.34 review H1: migration 0120 takes enquiries_months when job_applications_months is 0 and clears the confirmation when something moved', [
    str_contains($h1Migration, "\$jobMonths > 0 ? \$jobMonths : (int) \$settings->get('enquiries_months')"),
    str_contains($h1Migration, "\$settings->set('applications_retention_confirmed', (string) max(0, \$firstMoved - 1));"),
    str_contains($h1Migration, "&& (int) \$settings->get('job_applications_months') > 0) {")],
    [true, true, false]);
check('[source-pin] 3.34 review H1: the clean-up leaves moved applications alone until confirmed; saving the settings confirms; a move by form asks again', [
    str_contains($h1Store, "' AND (source_enquiry_id IS NULL OR id <= ' . self::confirmedUpTo(\$app->settings()) . ')'"),
    str_contains($h1Module, "Store::confirmRetention(\$this->app);"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/ApplicationMove.php'), 'Applications::adoptRetention($db, $app->settings(), $ids);'),
    array_key_exists(Applications::RETENTION_CONFIRMED, Kaleta\Core\Settings::DEFAULTS) && Kaleta\Core\Settings::DEFAULTS[Applications::RETENTION_CONFIRMED] === ''],
    [true, true, true, true]);
check('[source-pin] 3.34 review H1: the settings tab and the list say how many moved applications wait and since when', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/settings.php'), 'data-applications-retention-pending'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/list.php'), 'data-applications-retention-pending'),
    substr_count($h1Module, "'awaiting' => Store::awaitingConfirmation(\$this->app)")],
    [true, true, 2]);
