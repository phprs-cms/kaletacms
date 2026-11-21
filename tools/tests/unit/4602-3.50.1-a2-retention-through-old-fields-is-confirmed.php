<?php

declare(strict_types=1);

/* ---------- 3.50.1 A2: a retention period sent through a section's old field names is confirmed, not saved at once (D1) ---------- */
use Kaleta\Core\Retention as A602Retention;

$a602Stored = ['enquiries_months' => '12', 'job_applications_months' => '0', 'quotes_months' => '36', 'whistleblowing_retention_months' => '24', 'enquiries_expiry' => 'anonymise'];
$a602Before = fn (string $k): string => $a602Stored[$k] ?? '';
check('3.50.1 A2: holdWith – a longer period is saved, a shorter one is held, the same value is saved (no change), several at once split', [
    A602Retention::holdWith($a602Before, ['enquiries_months' => '24']),
    A602Retention::holdWith($a602Before, ['enquiries_months' => '6']),
    A602Retention::holdWith($a602Before, ['quotes_months' => '36']),
    A602Retention::holdWith($a602Before, ['quotes_months' => '48', 'whistleblowing_retention_months' => '12'])],
    [[['enquiries_months' => '24'], []], [[], ['enquiries_months' => '6']], [['quotes_months' => '36'], []],
        [['quotes_months' => '48'], ['whistleblowing_retention_months' => '12']]]);
check('3.50.1 A2: holdWith – a number where there was no limit (0 = keep) and delete after anonymise are held; a posted period that inherits a shortened one is held with it, a consequence alone (bookings, an inheriting period not posted) is no setting of the proposal', [
    A602Retention::holdWith(fn (string $k): string => $k === 'quotes_months' ? '0' : $a602Before($k), ['quotes_months' => '120']),
    A602Retention::holdWith($a602Before, ['enquiries_expiry' => 'delete']),
    A602Retention::holdWith($a602Before, ['enquiries_months' => '6', 'job_applications_months' => '0']),
    array_column(A602Retention::changesWith($a602Before, fn (string $k): string => $k === 'enquiries_months' ? '6' : $a602Before($k), ['enquiries_months' => '6']), 'consequence', 'key')],
    [[[], ['quotes_months' => '120']], [[], ['enquiries_expiry' => 'delete']], [[], ['enquiries_months' => '6', 'job_applications_months' => '0']],
        ['enquiries' => false, 'job_enquiries' => true, 'bookings' => true]]);
