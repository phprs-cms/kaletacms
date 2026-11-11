<?php

declare(strict_types=1);

use Kaleta\Builder\Build;

/* ---------- 3.34 review low (f): only someone who may open Applications changes a Form's Purpose ---------- */
$lfForm = fn (string $id, string $purpose): array => ['id' => $id, 'typ' => 'formular', 'obsah' => ['nazev' => 'Apply', 'pole' => [['typ' => 'text', 'popisek' => 'Name']]] + ($purpose !== '' ? ['ucel' => $purpose] : [])];
$lfPrevious = ['v' => 1, 'deti' => [$lfForm('frm001', 'uchazec'), $lfForm('frm002', '')]];
$lfInput = ['v' => 1, 'deti' => [$lfForm('frm001', ''), $lfForm('frm002', 'uchazec'), $lfForm('frm003', 'uchazec')]];
$lfPurposes = fn (array $build): array => array_map(fn (array $p): string => (string) ($p['obsah']['ucel'] ?? ''), $build['deti']);
Build::$purposeAllowed = false;
[$lfLocked, $lfErrors] = Build::sanitize($lfInput, false, $lfPrevious);
$lfSchema = Build::schema(false, 'en', false, ['poptavky', 'applications']);
Build::$purposeAllowed = true;
[$lfOpen] = Build::sanitize($lfInput, false, $lfPrevious);
Build::$purposeAllowed = null;
[$lfCli] = Build::sanitize($lfInput, false, $lfPrevious);
$lfFormSchema = array_values(array_filter($lfSchema['prvky'] ?? $lfSchema['elements'] ?? [], fn (array $e): bool => ($e['typ'] ?? '') === 'formular'))[0] ?? [];
check('3.34 review low (f): without the section the Purpose stays as it was (a new form gets none) with a notice; with it, or with no person (imports), it changes', [
    $lfPurposes($lfLocked), count(array_filter(array_keys($lfErrors), fn (string $k): bool => str_ends_with($k, '.obsah.ucel'))), $lfPurposes($lfOpen), $lfPurposes($lfCli),
    $lfFormSchema['vlastnosti']['ucel']['jen_cteni'] ?? false],
    [['uchazec', '', ''], 3, ['', 'uchazec', 'uchazec'], ['', 'uchazec', 'uchazec'], true]);
check('3.34 review low (f): the admin and MCP requests set it from the section; the builder shows the property read-only', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php'), '\Kaleta\Builder\Build::$purposeAllowed = $app->auth()->hasModule(\Kaleta\Core\Applications::SECTION);'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php'), '\Kaleta\Builder\Build::$purposeAllowed = $this->app->auth()->hasModule(\Kaleta\Core\Applications::SECTION);'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), "el('select', { disabled: !!def.jen_cteni,")],
    [true, true, true]);
