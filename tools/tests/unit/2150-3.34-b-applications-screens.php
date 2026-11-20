<?php

declare(strict_types=1);

use Kaleta\Core\Applications;

/* ---------- 3.34 (B): job applications – the list (waiting, with selected) and the detail in two columns ---------- */
check('3.34 B: the tab Waiting – new, no reply, received before the start of the day a week ago; a filter value, not a status', [
    Applications::filter(['status' => 'waiting'])['status'], Applications::waitingBefore('2026-11-11'),
    Applications::where(Applications::filter(['status' => 'waiting']), '2026-11-11'),
    array_keys(Kaleta\Admin\Modules\Applications::TABS)[1], in_array('waiting', Applications::STATUSES, true)],
    ['waiting', '2026-11-04 00:00:00', ["status = 'new' AND replies IS NULL AND received_at < ?", ['2026-11-04 00:00:00']], 'waiting', false]);
$b334Module = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Applications.php');
$b334List = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/list.php');
check('[source-pin] 3.34 B: with selected – a status, a responsible person, and delete only for administrators (the action refuses others, the button is not offered)', [
    method_exists(Kaleta\Admin\Modules\Applications::class, 'actionBulk'), substr_count($b334Module, "if (!\$this->app->auth()->isAdmin()) {\n                return \$this->error('Only an administrator can delete applications"),
    str_contains($b334Module, "if (!\$this->app->auth()->isAdmin()) {\n            return \$this->error('Only an administrator can delete applications"),
    str_contains($b334List, 'data-vybrat-vse="uchazeci-hromadne"'), str_contains($b334List, "<?php if (\$admin): ?>\n\t<span class=\"uchazeci-hromadne-krok\"><button class=\"navigace nebezpecne\" type=\"submit\" name=\"do\" value=\"delete\""),
], [true, 1, true, true, true]);
// the detail (two columns, Delete only for administrators, other applications from the address): a rendered test in 4270 since 3.47 (U64)
