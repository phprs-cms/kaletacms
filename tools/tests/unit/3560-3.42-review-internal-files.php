<?php

declare(strict_types=1);

use Kaleta\Core\InternalFiles;

/* ---------- 3.42 review: internal files – a move between audiences, the version under a lock ---------- */
$r342fFolder = fn (string $audience, array $roles = [], array $departments = []): array => ['audience' => $audience, 'roles' => $roles, 'departments' => $departments];
check('3.42 review M3: InternalFiles::audienceOf – folders for everyone compare equal, roles and departments by their sorted sets, never across kinds', [
    InternalFiles::audienceOf($r342fFolder('all')) === InternalFiles::audienceOf($r342fFolder('all', [4])),
    InternalFiles::audienceOf($r342fFolder('roles', [9, 4])) === InternalFiles::audienceOf($r342fFolder('roles', [4, 9])),
    InternalFiles::audienceOf($r342fFolder('roles', [4])) === InternalFiles::audienceOf($r342fFolder('roles', [4, 9])),
    InternalFiles::audienceOf($r342fFolder('roles', [4])) === InternalFiles::audienceOf($r342fFolder('all')),
    InternalFiles::audienceOf($r342fFolder('depts', [], [3])) === InternalFiles::audienceOf($r342fFolder('roles', [3])),
    InternalFiles::audienceOf($r342fFolder('depts', [], [7, 3]))],
    [true, true, false, false, false, 'depts:3,7']);
check('3.42 review M3: the refusal of a move to another audience is translated in cs, de and es', array_map(
    fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Only an administrator can move a file to a folder with other people in its audience.']), ['cs', 'de', 'es']),
    [true, true, true]);
