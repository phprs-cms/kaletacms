<?php

declare(strict_types=1);

/* ---------- 3.32 (integration): the dashboard's latest enquiries are named by who and what; Take it sits beside the Who cell ---------- */
$i332Dash = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/dashboard.php');
$i332Kernel = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
$i332Work = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/my_work/list.php');
check('[source-pin] 3.32: the dashboard names its latest enquiries with RecordTitle::enquiry (the form under it) and reads the columns it needs; My work\'s unassigned list has the Who cell and Take it in one row', [
    str_contains($i332Dash, 'bdi(Kaleta\\Core\\RecordTitle::enquiry($p))'), str_contains($i332Dash, "e(\$p['formular'] !== '' ? \$p['formular'] : t('Enquiry'))"),
    str_contains($i332Kernel, "SELECT idp, datum, formular, tema, zdroj, email, data, anonymizovano, stav FROM {poptavky} WHERE ' . Modules\\Enquiries::NOT_SPAM . ' AND ' . Modules\\Enquiries::visible("),
    str_contains($i332Work, "<?= bdi((string) (\$r['name'] ?? \$r['title'])) ?>"), substr_count($i332Work, 'data-enquiry-take'),
    Kaleta\Core\RecordTitle::enquiry(['formular' => 'Contact', 'email' => 'olivia@example.com', 'data' => json_encode([['Name', 'Olivia Hart'], ['Message', 'We need forty brackets for March']])])],
    [true, false, true, true, 1, 'Olivia Hart – We need forty brackets for March']);

