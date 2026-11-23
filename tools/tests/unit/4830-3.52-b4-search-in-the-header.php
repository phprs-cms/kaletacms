<?php

declare(strict_types=1);

// 3.52 B4: the Search element as an icon that opens the field (Popover API, no script) and a header template with it

$b4Context = new Kaleta\Builder\Context(new Kaleta\Core\App([]));
$b4Field = Kaleta\Builder\Elements\Search::render(['id' => 'x', 'obsah' => ['napoveda' => '', 'tlacitko' => 'Go', 'podoba' => 'pole']], '', '', $b4Context);
$b4Icon = Kaleta\Builder\Elements\Search::render(['id' => 'x', 'obsah' => ['napoveda' => '', 'tlacitko' => 'Go', 'podoba' => 'ikona']], ' data-ka-id="x"', '', $b4Context);
check('3.52 B4: the field appearance stays as it was – one form, no popover', [str_contains($b4Field, 'popover'), str_contains($b4Field, 'ka-hledani--ikona'), substr_count($b4Field, '<input')],
    [false, false, 1]);
check('3.52 B4: the icon appearance – the form keeps the attributes, a button opens the popover with the field, the field gets the focus', [
    str_starts_with($b4Icon, '<form data-ka-id="x" class="ka-hledani ka-hledani--ikona" role="search"'),
    str_contains($b4Icon, '<button type="button" class="ka-hledani-tl" popovertarget="hl-x-pole"'),
    str_contains($b4Icon, '<div id="hl-x-pole" popover'),
    str_contains($b4Icon, ' required autofocus>'),
    str_contains($b4Icon, '<script'),
    str_ends_with($b4Icon, '</button></div></form>')],
    [true, true, true, true, false, true]);
check('3.52 B4: the base CSS styles what the icon appearance writes', [str_contains(Kaleta\Builder\Elements\Search::baseCss(), '.ka-hledani--ikona > .ka-hledani-tl'),
    str_contains(Kaleta\Builder\Elements\Search::baseCss(), '.ka-hledani--ikona > [popover]:popover-open'), str_contains(Kaleta\Builder\Elements\Search::rtlCss(), 'left: anchor(left)')],
    [true, true, true]);

[$b4Clean] = Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['id' => 'a1', 'typ' => 'hledani', 'obsah' => ['podoba' => 'nic']], ['id' => 'a2', 'typ' => 'hledani', 'obsah' => ['podoba' => 'ikona']]]]);
check('3.52 B4: an unknown appearance falls back to the field, the icon is kept', array_column(array_column($b4Clean['deti'], 'obsah'), 'podoba'), ['pole', 'ikona']);

$b4Vocabulary = Kaleta\Mcp\Vocabulary::class;
check('3.52 B4: MCP speaks English – appearance: icon in, podoba: ikona stored and back', [
    $b4Vocabulary::elementToCzech(['type' => 'search', 'content' => ['appearance' => 'icon']]),
    $b4Vocabulary::elementToEnglish(['typ' => 'hledani', 'obsah' => ['podoba' => 'pole']])],
    [['typ' => 'hledani', 'obsah' => ['podoba' => 'ikona']], ['type' => 'search', 'content' => ['appearance' => 'field']]]);

$b4Header = Kaleta\Builder\PartTemplates::build('hlavicka', 's-hledanim', 'en');
$b4Json = (string) json_encode($b4Header);
check('3.52 B4: the header template "With search" – the logo, the menu and the search icon', [
    array_column(Kaleta\Builder\PartTemplates::forType('hlavicka'), 'klic'),
    str_contains($b4Json, '"typ":"logo"') && str_contains($b4Json, '"typ":"navigace"'),
    str_contains($b4Json, '"typ":"hledani"') && str_contains($b4Json, '"podoba":"ikona"')],
    [['klasicka', 'na-stred', 's-listou', 'minimalni', 's-hledanim'], true, true]);
