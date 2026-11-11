<?php

declare(strict_types=1);

/* ---------- 3.34 review M1: sealPending walks the moved rows with an id cursor; the settings tab counts the CVs still waiting ---------- */
$m1Store = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php');
check('3.34 review M1: an id cursor (applications_seal_after), no fixed first 500 rows; the end starts over; the count of waiting CVs', [
    str_contains($m1Store, "AND attachments IS NOT NULL AND id > ? ORDER BY id LIMIT 200', [\$after]"),
    str_contains($m1Store, 'ORDER BY id LIMIT 500'),
    str_contains($m1Store, "\$s->set('applications_seal_after', '0');"),
    str_contains($m1Store, 'public static function unsealedCount(App $app): int'),
    array_key_exists('applications_seal_after', Kaleta\Core\Settings::DEFAULTS),
    Kaleta\Core\Applications::SEAL_BATCH >= 200,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/settings.php'), 'data-applications-unsealed')],
    [true, false, true, true, true, true, true]);
