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
$b334Detail = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/applications/detail.php');
check('3.34 B: with selected – a status, a responsible person, and delete only for administrators (the action refuses others, the button is not offered)', [
    method_exists(Kaleta\Admin\Modules\Applications::class, 'actionBulk'), substr_count($b334Module, "if (!\$this->app->auth()->isAdmin()) {\n                return \$this->error('Only an administrator can delete applications"),
    str_contains($b334Module, "if (!\$this->app->auth()->isAdmin()) {\n            return \$this->error('Only an administrator can delete applications"),
    str_contains($b334List, 'data-vybrat-vse="uchazeci-hromadne"'), str_contains($b334List, "<?php if (\$admin): ?>\n\t<span class=\"uchazeci-hromadne-krok\"><button class=\"navigace nebezpecne\" type=\"submit\" name=\"do\" value=\"delete\""),
], [true, 1, true, true, true]);
check('3.34 B: the detail – two columns (the record, the side with status and responsibility), the reason shows only with Rejected, other applications from the address; the styles exist', [
    str_contains($b334Detail, '<div class="uchazec-detail"'), str_contains($b334Detail, '<aside class="uchazec-bok"'), str_contains($b334Detail, 'data-application-others'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/admin.css'), '.uchazec-stav:has(select[name="status"] option[value="rejected"]:checked) .uchazec-duvod'),
    str_contains($b334Detail, "<?php if (\$admin): ?>\n<form class=\"vradku\" method=\"post\" action=\"<?= e(\$module->url('delete')) ?>\""),
], [true, true, true, true, true]);
