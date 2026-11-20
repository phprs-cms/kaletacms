<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Applications as ApplicationsModule;

/* ---------- 3.47 review (Fable): F3 the one Save of Handling, F5/F9 a block pasted twice in a script ---------- */

// F3: the Handling form posts what it showed (was_status, was_reject_reason); only a status part the person changed on the
// page is written – a colleague's move on the board meanwhile is not reverted by an untouched select
check('3.47 review F3: the status part counts as changed for another status or another reason of a rejection – not for the same status, nor for a reason left behind a status that needs none', [
    ApplicationsModule::statusTouched('new', 'other', 'new', ''), ApplicationsModule::statusTouched('interview', '', 'new', ''),
    ApplicationsModule::statusTouched('rejected', 'other', 'rejected', 'position_filled'), ApplicationsModule::statusTouched('rejected', 'other', 'rejected', 'other'),
    ApplicationsModule::statusTouched('reviewed', 'other', 'reviewed', 'position_filled'), ApplicationsModule::statusTouched('rejected', 'other', 'interview', '')],
    [false, true, true, false, false, true]);

// F5/F9: a merge left the sign-in script with one block twice (the second copy silently wins a later edit). A rule over every
// script of the administration and the web: no six non-blank lines (200 characters or more) appear twice in one file.
$r347Twice = [];
foreach (glob(KALETA_ROOT . '/image/*.js') ?: [] as $r347File) {
    $r347Lines = array_map('trim', file($r347File) ?: []);
    $r347Seen = [];
    for ($r347At = 0; $r347At + 6 <= count($r347Lines); $r347At++) {
        $r347Chunk = array_slice($r347Lines, $r347At, 6);
        if (in_array('', $r347Chunk, true) || strlen(implode('', $r347Chunk)) < 200) {
            continue;
        }
        $r347Key = implode("\n", $r347Chunk);
        if (isset($r347Seen[$r347Key]) && $r347At - $r347Seen[$r347Key] >= 6) {
            $r347Twice[basename($r347File)] = 'lines ' . ($r347Seen[$r347Key] + 1) . ' and ' . ($r347At + 1);
        }
        $r347Seen[$r347Key] ??= $r347At;
    }
}
check('3.47 review F5/F9: no script in image/ carries a block of six lines twice (a merge leftover)', $r347Twice, []);
