<?php

declare(strict_types=1);

/* ---------- 3.32 (pre-release review): Create deal from an enquiry – 0 keeps the enquiry's customer, "—" (none) is explicitly nobody ---------- */
$r332View = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/pipeline/form.php');
$r332Pipeline = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pipeline.php');
check('[source-pin] 3.32: the Create-deal form from an enquiry tells "the enquiry\'s customer" (0) from "—" (none); the save keeps the linked customer for 0', [
    str_contains($r332View, "\$none = \$fromEnquiry ? 'none' : '0';"), substr_count($r332View, '<option value="<?= $none ?>"'),
    str_contains($r332View, "t('The enquiry’s customer')"), str_contains($r332View, "t('The customer’s company')"),
    str_contains($r332Pipeline, "\$chosen = \$this->request->post(\$key) === 'none' ? 0 : \$this->request->postInt(\$key);"),
    str_contains($r332Pipeline, "if (\$chosen > 0 || !\$fromEnquiry || \$this->request->post(\$key) === 'none') {")],
    [true, 2, true, true, true, true]);

