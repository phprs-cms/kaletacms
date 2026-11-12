<?php

declare(strict_types=1);

use Kaleta\Mcp\Confirmation;

/* ---------- 3.39 review L3: a run without a person never waits for a yes that cannot come ---------- */

$l3Answer = Confirmation::answer('reply_to_enquiry', '1900000600-abcdef01-' . str_repeat('0', 32), false);
check('3.39 review L3: the confirmation answer and the server instructions tell a run without a person what to do – a scheduled run reports it as partial, an automation set up for exactly this action repeats the call with the code', [
    str_contains($l3Answer['next'], 'Do not stall'), str_contains($l3Answer['next'], 'report_agent_run'), str_contains(Confirmation::INSTRUCTION, 'never wait for a yes'),
    str_contains(Confirmation::INSTRUCTION, 'repeats the call with the code'),
], [true, true, true, true]);
