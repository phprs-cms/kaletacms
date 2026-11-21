<?php

declare(strict_types=1);

/* ---------- 3.50 C (U33): the customer page lists the open tasks of its enquiries and deals ---------- */
use Kaleta\Admin\Modules\Contacts as C350Contacts;

$c350Task = fn (int $id, string $target, ?string $due): array => ['id' => $id, 'title' => 'T' . $id, 'target' => $target, 'due_at' => $due, 'done_at' => null, 'group' => 'upcoming'];
$c350Records = [
    ['target' => 'enquiry:5', 'kind' => 'enquiry', 'label' => 'Kitchen', 'url' => '/admin.php?module=enquiries&action=detail&id=5#tasks'],
    ['target' => 'enquiry:6', 'kind' => 'enquiry', 'label' => 'No task here', 'url' => '/e6'],
    ['target' => 'deal:2', 'kind' => 'deal', 'label' => 'New website', 'url' => '/d2'],
    ['target' => 'deal:3', 'kind' => 'deal', 'label' => 'Service', 'url' => '/d3'],
];
$c350Rows = C350Contacts::relatedTaskRows($c350Records, ['enquiry:5' => $c350Task(11, 'enquiry:5', '2026-12-03 09:00:00'), 'deal:2' => $c350Task(12, 'deal:2', null),
    'deal:3' => $c350Task(13, 'deal:3', '2026-11-30 09:00:00'), 'contact:9' => $c350Task(14, 'contact:9', '2026-11-01 09:00:00')]);
check('3.50 C (U33): the next open task of each enquiry and deal of the customer, with where it belongs – the earliest due first, no date last; a record without a task and a task of another record are left out', [
    array_column($c350Rows, 'id'), array_column($c350Rows, 'kind'), array_column($c350Rows, 'label'), $c350Rows[1]['url'], C350Contacts::relatedTaskRows([], []), C350Contacts::relatedTaskRows($c350Records, []),
], [[13, 11, 12], ['deal', 'enquiry', 'deal'], ['Service', 'Kitchen', 'New website'], '/admin.php?module=enquiries&action=detail&id=5#tasks', [], []]);
