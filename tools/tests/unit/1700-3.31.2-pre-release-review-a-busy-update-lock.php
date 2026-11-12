<?php

declare(strict_types=1);

use Kaleta\Core\Updater as U3312;

/* ---------- 3.31.2 (pre-release review): a busy update lock postpones the automatic try instead of a backup storm ---------- */
check('3.31.2: Updater::postponed – the tries made so far stay, the next one comes BUSY_WAIT seconds later; a version not tried yet waits with no try counted', [
    U3312::BUSY_WAIT, U3312::postponed('3.31.3|1|100', '3.31.3', 1000), U3312::postponed('', '3.31.3', 1000), U3312::postponed('3.31.2', '3.31.3', 1000), U3312::postponed('3.31.2|2|5', '3.31.3', 1000),
    U3312::attempt(U3312::postponed('', '3.31.3', 1000))],
    [600, '3.31.3|1|1600', '3.31.3|0|1600', '3.31.3|0|1600', '3.31.3|0|1600', ['version' => '3.31.3', 'tries' => 0, 'next' => 1600, 'final' => false]]);
$b3312Lock = fopen(KALETA_ROOT . '/storage/cache/aktualizace.zamek', 'c');
$b3312Held = $b3312Lock !== false && flock($b3312Lock, LOCK_EX | LOCK_NB) ? U3312::installRunning() : null;
if ($b3312Lock !== false) {
    flock($b3312Lock, LOCK_UN);
    fclose($b3312Lock);
}
$u3312Run = substr($u3312 = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Updater.php'), (int) strpos($u3312, 'public static function runInBackground('), 7600);
check('[source-pin] 3.31.2: the background job checks the update lock before the pre-update backup and, on BUSY, stores a postponement (not the due state); a postponed try is waited for and then due', [
    $b3312Held, U3312::installRunning(),
    strpos($u3312Run, 'if (self::installRunning()) {') < strpos($u3312Run, "Backup::create(\$app->db(), 'predaktualizaci');"),
    str_contains($u3312Run, "\$s->set('update_attempt', self::postponed(\$before, \$version, time()));"), str_contains($u3312Run, "\$s->set('update_attempt', \$before);"),
    str_contains($u3312Run, "\$retryDue = !\$attempt['final'] && \$attempt['next'] > 0 && \$attempt['next'] <= time();"),
    str_contains($u3312Run, "if (\$install && \$attempt['version'] === \$version && \$attempt['next'] > time()) {")],
    [true, false, true, true, false, true, true]);

