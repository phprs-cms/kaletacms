<?php
// 3.43 A: one canonical action name for the publish gate (N155), the send_quote total (N142), second-step fields long enough
// for a backup code (N126), Claude sessions per connection and user (N167)

declare(strict_types=1);

use Kaleta\Admin\Module;
use Kaleta\Core\AgentJournal;
use Kaleta\Core\BackupCodes;
use Kaleta\Core\Language;
use Kaleta\Core\Money;
use Kaleta\Mcp\Confirmation;

// N155: every module with LIVE_ACTIONS – the canonical name runs the method and is a live action; no other spelling of it
// (a trailing or doubled underscore, an underscore inside a word, capitals) resolves to any method at all
$a343Modules = [];
foreach (glob(KALETA_SYSTEM . '/src/Admin/Modules/*.php') ?: [] as $a343File) {
    $a343Class = 'Kaleta\\Admin\\Modules\\' . basename($a343File, '.php');
    if (class_exists($a343Class) && is_subclass_of($a343Class, Module::class) && $a343Class::LIVE_ACTIONS !== []) {
        $a343Modules[$a343Class] = (new ReflectionClass($a343Class))->newInstanceWithoutConstructor();
    }
}
$a343Aliases = static function (string $action): array {
    $aliases = [$action . '_', $action . '__', '_' . $action, str_replace('_', '__', $action), strtoupper($action), ucfirst($action), $action . ' ', $action . "\n"];
    for ($i = 1; $i < strlen($action); $i++) {
        if ($action[$i] !== '_' && $action[$i - 1] !== '_') {
            $aliases[] = substr($action, 0, $i) . '_' . substr($action, $i); // de_lete → actionDeLete = actionDelete in PHP
        }
    }

    return array_values(array_unique(array_diff($aliases, [$action])));
};
$a343Leaks = [];
$a343Canonical = [];
foreach ($a343Modules as $a343Class => $a343Module) {
    foreach ($a343Class::LIVE_ACTIONS as $a343Action) {
        $a343Method = Module::methodFor($a343Module, $a343Action);
        $a343Canonical[] = $a343Method !== null && Module::isLiveAction($a343Class::LIVE_ACTIONS, $a343Method);
        foreach ($a343Aliases($a343Action) as $a343Alias) {
            if (Module::methodFor($a343Module, $a343Alias) !== null) {
                $a343Leaks[] = $a343Class . ':' . $a343Alias;
            }
        }
    }
}
check('3.43 N155: the modules with live actions are found; each live action runs under its own name and counts as live', [count($a343Modules) >= 4, array_unique($a343Canonical)], [true, [true]]);
check('3.43 N155: no alias of a live action (save_, delete__, de_lete, SAVE …) resolves to a method', $a343Leaks, []);
$a343Tags = $a343Modules['Kaleta\\Admin\\Modules\\Tags'] ?? null;
check('3.43 N155: ordinary actions still resolve; a gate on the resolved method knows save in any case', [
    $a343Tags !== null ? Module::methodFor($a343Tags, 'list') : null, $a343Tags !== null ? Module::methodFor($a343Tags, 'save') : null,
    Module::isLiveAction(['save_variant'], 'actionSaveVariant'), Module::isLiveAction(['save'], 'actionsave'), Module::isLiveAction(['save'], 'actionSaveVariant')],
    ['actionList', 'actionSave', true, true, false]);
// every action method of every admin module round-trips, so the strict name never hides an existing screen
$a343Broken = [];
foreach (glob(KALETA_SYSTEM . '/src/Admin/{Modules/*.php,*.php}', GLOB_BRACE) ?: [] as $a343File) {
    preg_match_all('/function (action[A-Za-z0-9_]+)\(/', (string) file_get_contents($a343File), $a343M);
    foreach ($a343M[1] as $a343Name) {
        $a343Snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($a343Name, 6)));
        if ('action' . str_replace('_', '', ucwords($a343Snake, '_')) !== $a343Name) {
            $a343Broken[] = basename($a343File) . ':' . $a343Name;
        }
    }
}
check('3.43 N155: every action method has a canonical snake-case name that maps back to it exactly', $a343Broken, []);

// N142: the summary shows the quote's real total (DECIMAL text → minor units), never a hundredth of it
$a343Quote = ['totals' => ['net' => '12500.50', 'vat' => '2625.11', 'gross' => '15125.61'], 'currency' => 'CZK'];
check('3.43 N142: the send_quote total is the gross amount as the document shows it', [
    Language::runWith('en', static fn (): string => Confirmation::quoteTotal($a343Quote), 'admin-') === Language::runWith('en', static fn (): string => Money::format(1512561, 'CZK'), 'admin-'),
    Language::runWith('en', static fn (): string => Confirmation::quoteTotal($a343Quote), 'admin-') !== Language::runWith('en', static fn (): string => Money::format(15125, 'CZK'), 'admin-'), // the old cast
    str_contains(preg_replace('/\D/', '', Language::runWith('en', static fn (): string => Confirmation::quoteTotal($a343Quote), 'admin-')) ?? '', '1512561'),
    Language::runWith('en', static fn (): string => Confirmation::quoteTotal(['totals' => ['gross' => '0.00'], 'currency' => 'EUR']), 'admin-') !== ''],
    [true, true, true, true]);

// N126: a backup code fits the second-step field in every way it may be typed
[$a343Codes] = BackupCodes::generate();
$a343Longest = max(array_map(static fn (string $c): int => max(strlen($c), strlen(str_replace('-', ' ', $c)), strlen(' ' . str_replace('-', ' - ', $c) . ' ')), $a343Codes));
check('3.43 N126: the field takes a code with dashes, with spaces and with spaces around the dashes; the codes are hex (no numeric keypad)', [
    $a343Longest <= BackupCodes::INPUT_MAX, strlen($a343Codes[0]), (bool) preg_match('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/D', $a343Codes[0])], [true, 19, true]);

// N167: the session of a connection is its name and the user
check('3.43 N167: every user\'s "Claude" has its own session label; an empty name is Claude; the label fits the column', [
    AgentJournal::connectionLabel('Claude', 'jana'), AgentJournal::connectionLabel('Claude', 'petr') !== AgentJournal::connectionLabel('Claude', 'jana'),
    AgentJournal::connectionLabel('  ', 'jana'), AgentJournal::connectionLabel('Claude', ''), mb_strlen(AgentJournal::connectionLabel(str_repeat('x', 150), str_repeat('y', 150)))],
    ['Claude · jana', true, 'Claude · jana', 'Claude', 100]);
