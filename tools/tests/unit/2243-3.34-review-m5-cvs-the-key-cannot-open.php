<?php

declare(strict_types=1);

/* ---------- 3.34 review M5: a CV list the key cannot open is never "no attachments"; no list is sealed under a lost key ---------- */
$m5Store = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php');
check('3.34 review M5: locked lists are named (detail, list, System status, MCP), the clean-up waits, a missing key seals with the database key (db:) and the clean-up reseals', [
    str_contains($m5Store, "'attachments_locked' => self::attachmentsLocked(\$s, \$row['attachments'] ?? null)"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/detail.php'), 'data-application-files-locked'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/list.php'), 'data-application-locked'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php'), 'Applications::lockedCount($app)'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/ApplicationTools.php'), "['attachments_locked' => true]"),
    str_contains($m5Store, "if (Vault::state(\$s) === 'missing') {"), str_contains($m5Store, 'self::resealHeld($app);'),
    str_contains($m5Store, "fn (array \$r): bool => self::rowFiles(\$app->settings(), \$r) === null), 'id'));")], // 3.34 third review F5: only a row whose files cannot be located
    [true, true, true, true, true, true, true, true]);
check('3.34 review M5: the texts of the key name the CVs of job applicants', array_map(fn (string $state): bool => str_contains(Kaleta\Core\Vault::describe($state), 'CV'), ['config', 'pending', 'missing', 'database']), [true, true, true, true]);
