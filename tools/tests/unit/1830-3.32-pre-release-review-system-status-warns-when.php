<?php

declare(strict_types=1);

/* ---------- 3.32 (pre-release review): System status warns when the clean-up has not succeeded for 2 days; the dashboard's latest enquiries leave spam out ---------- */
$r332Now = strtotime('2026-11-10 12:00:00');
check('3.32: Scheduler::cleanupStale – a success older than 2 days is reported; a recent one, none yet, or no cleanup row are not; Health adds the row', [
    Kaleta\Core\Scheduler::cleanupStale([['name' => 'mail', 'last_ok' => '2026-01-01 00:00:00'], ['name' => 'cleanup', 'last_ok' => '2026-11-08 11:00:00']], $r332Now),
    Kaleta\Core\Scheduler::cleanupStale([['name' => 'cleanup', 'last_ok' => '2026-11-08 13:00:00']], $r332Now),
    Kaleta\Core\Scheduler::cleanupStale([['name' => 'cleanup', 'last_ok' => null]], $r332Now), Kaleta\Core\Scheduler::cleanupStale([], $r332Now),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php'), "\$add(t('Operation'), t('Personal data clean-up'), 'varovani',"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php'), "FROM {poptavky} WHERE ' . Modules\\Enquiries::NOT_SPAM . (isset(\$modules[\\Kaleta\\Core\\Applications::SECTION]) ? '' : Modules\\Enquiries::stillMoving(\$db, \$this->app->settings())) . ' ORDER BY idp DESC LIMIT 5'")], // 3.34 review M3: the enquiries still moving to Applications stay out too
    ['2026-11-08 11:00:00', null, null, null, true, true]);

