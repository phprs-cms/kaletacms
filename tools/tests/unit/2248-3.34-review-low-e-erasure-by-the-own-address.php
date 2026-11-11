<?php

declare(strict_types=1);

/* ---------- 3.34 review low (e): Applications::ofEmail matches the application's own e-mail only ---------- */
$leStore = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php');
check('3.34 review low (e): no LIKE over the fields – only email_norm or the e-mail itself', [
    str_contains($leStore, "SELECT * FROM {applications} WHERE email_norm = ? OR LOWER(email) = ? ORDER BY id'"), str_contains($leStore, 'OR LOWER(data) LIKE ?')],
    [true, false]);
