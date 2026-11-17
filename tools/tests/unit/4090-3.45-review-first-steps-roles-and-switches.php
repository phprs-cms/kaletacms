<?php

declare(strict_types=1);

/* ---------- 3.45 review (security-2026-11-17-release-3.45): M1, M2, L1–L8 ---------- */
// M1 (the guide's own class names) is checked by smoke 4090 and the browser test (the dashboard's first steps keep their
// width); M2 by smoke 4090 against the database – here the two conditions themselves
check('3.45 review M2: OFFICE_REPLIED (status 2 or a reply, not status 1) and OFFICE_FOLLOW_UP (a task about an enquiry, a deal or a quote)', [
    Kaleta\Admin\Kernel::OFFICE_REPLIED, Kaleta\Admin\Kernel::OFFICE_FOLLOW_UP,
], ["(stav = 2 OR (replies IS NOT NULL AND replies NOT IN ('', '[]')))", "(target LIKE 'enquiry:%' OR target LIKE 'deal:%' OR target LIKE 'quote:%')"]);

// L1, L2, L3: the snapshot of partial runs lives in the user's private cache and is never sourced; its key covers system/;
// test.sh starts at a section with START= (FROM= is the release of test-update.sh and test-migrations.sh)
$r345Boot = (string) file_get_contents(KALETA_ROOT . '/tools/tests/smoke/bootstrap.sh');
$r345Test = (string) file_get_contents(KALETA_ROOT . '/tools/test.sh');
check('3.45 review L1–L3: no temp directory, no sourced vars.sh, a private directory check, system/ in the key; START= instead of FROM=', [
    str_contains($r345Boot, 'TMPDIR'), str_contains($r345Boot, 'vars.sh'), str_contains($r345Boot, 'smoke_snapshot_private "$SMOKE_SNAPSHOT_DIR"'),
    str_contains($r345Boot, 'foreach (["install.php", "system", "tools/tests/smoke/bootstrap.sh"] as $p)'),
    str_contains($r345Test, '${FROM'), str_contains($r345Test, 'from="${START:-}"'),
], [false, false, true, true, false, true]);

// L4, L5: the level names stay reserved for new roles (smoke 4090 saves an old role called Team member); level 0 is a team
// member wherever it is listed
check('3.45 review L4/L5: the reserved level names; Auth::TYPES[0] is "team member", translated in every admin language', [
    Kaleta\Admin\Modules\Roles::RESERVED_NAMES, Kaleta\Core\Auth::TYPES[Kaleta\Core\Auth::AUTHOR],
    array_map(fn (string $c): ?string => (require KALETA_SYSTEM . '/jazyky/admin-' . $c . '.php')['team member'] ?? null, ['cs', 'de', 'es']),
], [['News author', 'Team member', 'Editor', 'Administrator'], 'team member', ['člen týmu', 'Teammitglied', 'miembro del equipo']]);

// L6, L7: smoke 4090 (Hide with an unknown list answers 400; a finished office list stores office_steps_hidden)

// L8: over MCP a yes/no setting takes 1/0, true/false, ano/ne, yes/no, on/off (and empty) and refuses any other word; the
// form's verifyValue is unchanged (an unknown word is off there, as the checkbox sends nothing)
$r345S = Kaleta\Admin\Modules\Settings::class;
check('3.45 review L8: strictSwitchRefuses – unknown words of a yes/no setting only; verifyValue as before', [
    array_map(fn (string $v): bool => $r345S::strictSwitchRefuses('share_buttons', $v), ['enabled', 'y', 'ja', 'si', 'yes', 'NO', ' on ', 'off', '1', '0', 'true', 'false', 'ano', 'ne', '']),
    $r345S::strictSwitchRefuses('site_name', 'enabled'), $r345S::strictSwitchRefuses('nonexistent', 'enabled'),
    $r345S::verifyValue('share_buttons', 'enabled'),
], [[true, true, true, true, false, false, false, false, false, false, false, false, false, false, false], false, false, '0']);
