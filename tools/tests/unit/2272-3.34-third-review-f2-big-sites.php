<?php

declare(strict_types=1);

/* ---------- 3.34 third review F2: a big site – the sweep, the index of source_contact_id, the move inside a visit ---------- */
$f2Root = KALETA_ROOT . '/system';
$f2Apps = (string) file_get_contents($f2Root . '/src/Core/Applications.php');
check('3.34 third review F2: the sweep runs from the clean-up once a day and reads enquiries only for a file the applications leave unlisted',
    [str_contains($f2Apps, "if (\$s->get('applications_swept_on') !== Clock::today()) {"), str_contains($f2Apps, 'while ($candidates !== [] && ($enquiries = ')],
    [true, true]);
check('3.34 third review F2: source_contact_id has its key in the schema and in migration 0119',
    [str_contains((string) file_get_contents($f2Root . '/sql/schema.sql'), 'KEY ix_applications_contact (source_contact_id)'),
        str_contains((string) file_get_contents($f2Root . '/sql/migrace/0119-applications.sql'), 'KEY ix_applications_contact (source_contact_id)')],
    [true, true]);
check('3.34 third review F2: a data migration works ~2 s inside a visitor\'s request, the full budget from cron and the administration',
    [Kaleta\Core\Migration::VISITOR_SECONDS, Kaleta\Core\Migration::$seconds,
        str_contains((string) file_get_contents($f2Root . '/src/Front/Kernel.php'), "if (!in_array(\$app->request->path(), ['/tasks', '/ulohy'], true)) {"),
        str_contains((string) file_get_contents($f2Root . '/sql/migrace/0120-applications-move.php'), ': $budget;')],
    [2.0, 15.0, true, true]);
