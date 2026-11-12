<?php

declare(strict_types=1);

// 3.35 re-review N1: a new host without the confirmation is refused on top of the fresh-proof rule, never instead of it –
// the legacy combined General form (tab=general without general_split) carries the security fields too
$r361Settings = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php');
$r361Fn = substr($r361Settings, (int) strpos($r361Settings, 'private function signInChangeRefusal('), (int) strpos($r361Settings, 'public const string HOST_CHANGE_CONFIRM') - (int) strpos($r361Settings, 'private function signInChangeRefusal('));
check('[source-pin] 3.35 re-review N1: signInChangeRefusal has no early return for the host change; the proof runs for every other sensitive key', [
    str_contains($r361Fn, "return [['site_url']"),
    substr_count($r361Fn, 'return '),
    str_contains($r361Fn, "\$unconfirmed[] = 'site_url';"),
    strpos($r361Fn, "'require_2fa'") < strpos($r361Fn, '->freshProof('),
    str_contains($r361Fn, 'return [[...$unconfirmed, ...$sensitive], '),
], [false, 3, true, true, true]);
