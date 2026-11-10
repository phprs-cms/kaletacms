<?php

declare(strict_types=1);

/* ---------- T1 (Q5): clean-up off GET requests, two indexes, the customers' order ---------- */
$t5Enq = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php');
$t5List = substr($t5Enq, (int) strpos($t5Enq, 'protected function actionList('), 3000);
$t5Kernel = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
$t5Schema = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
$t5Pages = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pages.php');
check('T1 Q5: the Enquiries list, the Pages list and the admin kernel delete nothing on a GET; the daily jobs do it (trash and threads once a day, retention in the clean-up)', [
    str_contains($t5List, 'deleteExpired('), str_contains($t5List, 'purgeApplications('), str_contains($t5Kernel, 'emptyTrash(') || str_contains(substr($t5Pages, (int) strpos($t5Pages, 'protected function actionList('), 2000), 'sweepContent('),
    Kaleta\Core\Scheduler::JOBS['trash'][0], Kaleta\Core\Scheduler::JOBS['trash'][1], isset(Kaleta\Core\Scheduler::jobs()['trash']),
    isset($adminCs[Kaleta\Core\Scheduler::JOBS['trash'][2]], $adminDe[Kaleta\Core\Scheduler::JOBS['trash'][2]], $a3312Es[Kaleta\Core\Scheduler::JOBS['trash'][2]]),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Notifications.php'), '\Kaleta\Admin\Modules\Enquiries::deleteExpired($app->db(), $s);')],
    [false, false, false, 86400, 'any', true, true, true]);
check('T1 Q5: migration 0118 and the schema add ix_poptavky_datum and ix_notifications_target; the customers sort by activity without a filesort term', [
    str_contains($t5Schema, 'KEY ix_poptavky_datum (datum)'), str_contains($t5Schema, 'KEY ix_notifications_target (target)'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0118-cleanup-indexes.sql'), 'ADD KEY'), KALETA_DB_VERSION, Kaleta\Core\Contacts::SORTS['activity']],
    [true, true, 2, 118, 'c.last_activity_at DESC, c.id DESC']);

