<?php

declare(strict_types=1);

/* ---------- 3.34 review M2: moving back to Enquiries – verified copies first, the application goes last ---------- */
$m2Move = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/ApplicationMove.php');
$m2Apply = strpos($m2Move, 'private static function moveBack(');
$m2Body = $m2Apply === false ? '' : substr($m2Move, $m2Apply);
check('3.34 review M2: a locked list skips the row; each copy is read back and compared; a failure or a rollback removes the copies; the sealed files go after the commit', [
    str_contains($m2Body, 'if (Applications::attachmentsLocked($s, $row[\'attachments\'] ?? null)) {'),
    str_contains($m2Body, "hash_equals(hash('sha256', \$file['content']), hash('sha256', \$check))"),
    substr_count($m2Body, '$forget($written);') >= 3,
    strpos($m2Body, '@unlink($path); // the sealed copy') > strpos($m2Body, '$db->transaction('),
    str_contains($m2Body, "->rowCount() !== 1) {\n                    throw new \\RuntimeException("),
    str_contains($m2Move, "return ['moved' => \$moved, 'left' => \$preview['move'], 'skipped' => \$skipped, ")],
    [true, true, true, true, true, true]);
