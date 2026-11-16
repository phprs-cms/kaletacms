<?php

declare(strict_types=1);

use Kaleta\Core\Departments;
use Kaleta\Core\Inbox;
use Kaleta\Core\Language;

/* ---------- 3.42 review: the team hierarchy – what a stand-in and a manager are told, the forms of a department ---------- */
$r342fSettings = $reportSettings([]);
$r342fMail = fn (string $kind, string $lang = 'en'): string => Inbox::mail($r342fSettings, ['jazyk' => $lang, 'register' => ''], ['kind' => $kind, 'actor_name' => '', 'excerpt' => ''],
    ['kind' => 'quote', 'id' => 7, 'title' => 'Q-2026-0007', 'path' => ''], 'https://x/1', 'https://x/2')[0];
check('3.42 review M4: every quote kind e-mails its own subject with the number only – none of them says "Review by today", and an unknown kind says what happened', [
    $r342fMail('quote_prepared'), $r342fMail('quote_expiring'), $r342fMail('quote_expired'), $r342fMail('quote_accepted'), $r342fMail('quote_declined'),
    $r342fMail('quote_prepared', 'cs'), $r342fMail('fleet_expiry'),
    array_filter(array_combine(Inbox::KINDS, array_map($r342fMail, Inbox::KINDS)), fn (string $subject): bool => str_contains($subject, 'Review by today')) === ['review_due' => 'Review by today: “Q-2026-0007”'] ? 'only review_due' : 'other'],
    ['Draft quote prepared: Quote Q-2026-0007', 'Quote expires soon: Quote Q-2026-0007', 'Quote expired: Quote Q-2026-0007', 'Quote accepted: Quote Q-2026-0007',
        'Quote declined: Quote Q-2026-0007', 'Připravený koncept nabídky: ' . Language::runWith('cs', fn (): string => Inbox::where(['kind' => 'quote', 'id' => 7, 'title' => 'Q-2026-0007']), 'admin-'),
        'A date of a site comes up', 'only review_due']);
check('3.42 review L1: the forms of a department fit their column together (FORMS × 120 characters would not)', [
    Departments::FORMS_TOTAL, Departments::FORMS * 120 + Departments::FORMS - 1 > Departments::FORMS_TOTAL,
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['The names of the forms are too long together – at most 1,000 characters.']), ['cs', 'de', 'es'])],
    [1000, true, [true, true, true]]);
