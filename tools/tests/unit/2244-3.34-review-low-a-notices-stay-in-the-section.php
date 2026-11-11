<?php

declare(strict_types=1);

/* ---------- 3.34 review low (a): deletion_due and moved bell entries only for people who may open Applications ---------- */
check('3.34 review low (a): deletion_due checks Assignment::allowed; the move by form and migration 0120 clear the responsible person and the bell entries outside the section', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Applications.php'), "if (Assignment::allowed(\$db, 'application', \$due['assignee'])) {"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/ApplicationMove.php'), 'Applications::keepToSection($db, $ids);'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0120-applications-move.php'), "p.ident_modulu = \\'applications\\'")],
    [true, true, 2]);
