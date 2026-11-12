<?php

declare(strict_types=1);

use Kaleta\Core\Blueprint;
use Kaleta\Core\ClaudeInstructions;
use Kaleta\Core\Language;

/* ---------- 3.39 B (N111): blueprint instructions raise the notice; a shipped key is not taken over ---------- */

$n111Shipped = (string) array_key_first(Blueprint::available());
check('3.39 N111: a custom manifest may not use the key of a shipped blueprint; its own key is fine', [
    $n111Shipped !== '', Language::runWith('en', fn (): bool => str_contains((string) Blueprint::customRefusal(['key' => $n111Shipped]), 'ships with Kaleta'), 'admin-'),
    Blueprint::customRefusal(['key' => 'n111_own_blueprint']),
], [true, true, null]);
$n111Notice = ['at' => '2026-11-12 10:00:00', 'by' => 'Eva', 'via' => 'Claude', 'old' => '', 'new' => 'Reply within an hour.', 'blueprint' => 'Custom N111'];
check('3.39 N111: the notice names the blueprint that brought the instructions; a change of the instructions themselves reads as before', [
    Language::runWith('en', fn (): bool => str_contains(ClaudeInstructions::who($n111Notice), 'The blueprint “Custom N111” brought them') && str_contains(ClaudeInstructions::who($n111Notice), 'Claude'), 'admin-'),
    Language::runWith('en', fn (): bool => str_starts_with(ClaudeInstructions::who(['blueprint' => ''] + $n111Notice), 'Changed by Eva through the Claude connection Claude'), 'admin-'),
], [true, true]);
