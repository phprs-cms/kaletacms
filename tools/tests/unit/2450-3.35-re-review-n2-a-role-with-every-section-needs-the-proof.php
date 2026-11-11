<?php

declare(strict_types=1);

// 3.35 re-review N2: a custom role that gets every section passes it to its members – Roles save asks for the acting
// administrator's fresh proof, and assigning such a role in Users counts like every section ticked by hand
use Kaleta\Admin\Modules\Roles;
use Kaleta\Admin\Modules\Users;

$r362All = ['pages', 'news', 'enquiries'];
check('3.35 re-review N2: Roles::grantsEverySection – a new role or one that lacked a section; never fewer than all', [
    Roles::grantsEverySection(null, 'pages,news,enquiries', $r362All), Roles::grantsEverySection('pages', 'enquiries,news,pages', $r362All),
    Roles::grantsEverySection('pages,news,enquiries', 'pages,news,enquiries', $r362All), Roles::grantsEverySection(null, 'pages,news', $r362All),
    Roles::grantsEverySection('pages', 'pages,news', $r362All),
], [true, true, false, false, false]);
check('3.35 re-review N2: a role\'s sections given in Users count like sections ticked by hand', [
    Users::elevates(1, 1, $r362All, ['pages'], $r362All), Users::elevates(1, 1, $r362All, $r362All, $r362All), Users::elevates(null, 0, ['pages'], [], $r362All),
], [true, false, false]);
$r362Roles = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Roles.php');
$r362Users = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Users.php');
check('3.35 re-review N2: the proof runs before the role is written; Users passes the custom role\'s sections; the form shows the proof', [
    strpos($r362Roles, '->freshProof(') < strpos($r362Roles, '$this->db->transaction('),
    str_contains($r362Roles, "'passkey' => Auth::PASSKEY_PROOF,"),
    str_contains($r362Users, "\$custom !== null && \$data['role'] !== null => array_values(array_filter(explode(',', (string) \$custom['moduly'])))"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/roles/form.php'), 'data-klic-potvrdit'),
], [true, true, true, true]);
check('3.35 re-review N2: the refusal is translated (cs, de, es, de-du)', [
    array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code, fn (): bool => t(Roles::EVERY_SECTION_NEEDS_PASSWORD) !== Roles::EVERY_SECTION_NEEDS_PASSWORD, 'admin-'), ['cs', 'de', 'es']),
    isset((require KALETA_SYSTEM . '/jazyky/admin-de-du.php')[Roles::EVERY_SECTION_NEEDS_PASSWORD]),
], [[true, true, true], true]);
