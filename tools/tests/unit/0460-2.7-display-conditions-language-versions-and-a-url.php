<?php

declare(strict_types=1);

/* ---------- 2.7: display conditions – language versions and a URL parameter ---------- */
$conditionErrors = [];
check('2.7: conditions – languages and a URL parameter are kept, unknown and unsafe values dropped with a message', [
    Kaleta\Builder\Build::sanitizeConditions(['prihlaseni' => 'ne', 'jazyky' => ['', 'de', 'de', 'xx', 7], 'parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']], 'p', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => 'varianta'], 'p', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => ['nazev' => 'a b', 'hodnota' => 'x']], 'p2', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => ['nazev' => 'v', 'hodnota' => 'x"y']], 'p3', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => ['nazev' => '', 'hodnota' => '']], 'p4', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['jazyky' => 'de'], 'p5', $conditionErrors),
    array_keys($conditionErrors),
], [
    ['prihlaseni' => 'ne', 'jazyky' => ['', 'de'], 'parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']],
    ['parametr' => ['nazev' => 'varianta']], [], [], [], [],
    ['p', 'p2', 'p3'],
]);
check('2.7: old builds without the new conditions sanitize as before', Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'nadpis', 'id' => 'c1', 'podminky' => ['prihlaseni' => 'ano', 'od' => '2026-01-01']]]])[0]['deti'][0]['podminky'], ['prihlaseni' => 'ano', 'od' => '2026-01-01']);
$conditionApp = new Kaleta\Core\App([], new Kaleta\Core\Request(['utm_campaign' => 'jaro', 'varianta' => ''], [], []));
$conditionContext = new Kaleta\Builder\Context($conditionApp);
$conditionApp->languagePrefix = 'de';
check('2.7: meetsConditions – language version of the visit', [
    Kaleta\Builder\Build::meetsConditions(['jazyky' => ['de']], $conditionContext), Kaleta\Builder\Build::meetsConditions(['jazyky' => ['', 'en']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['jazyky' => ['']], (function () use ($conditionApp) { $conditionApp->languagePrefix = ''; return new Kaleta\Builder\Context($conditionApp); })()),
], [true, false, true]);
check('2.7: meetsConditions – URL parameter present, with and without an exact value', [
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'leto']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'varianta']], $conditionContext), // ?variant without a value counts as present
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'chybi']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign'], 'jazyky' => ['de'], 'od' => '2000-01-01'], $conditionContext), // all must hold
], [true, true, false, true, false, false]);
check('2.7: only a language condition keeps the page in the cache (language versions have their own addresses)', [
    Kaleta\Builder\Build::conditionsBypassCache(['jazyky' => ['de']]), Kaleta\Builder\Build::conditionsBypassCache(['parametr' => ['nazev' => 'utm_campaign']]),
    Kaleta\Builder\Build::conditionsBypassCache(['jazyky' => [''], 'od' => '2026-01-01']), Kaleta\Builder\Build::conditionsBypassCache(['prihlaseni' => 'ne']),
], [false, true, true, true]);
$conditionsCs = ['prihlaseni' => 'ne', 'od' => '2026-03-01', 'jazyky' => ['', 'de'], 'parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']];
$conditionsEn = Kaleta\Mcp\Vocabulary::elementToEnglish(['typ' => 'sekce', 'podminky' => $conditionsCs])['conditions'];
check('2.7: Vocabulary – conditions in English and back', [$conditionsEn, Kaleta\Mcp\Vocabulary::elementToCzech(['type' => 'section', 'conditions' => $conditionsEn])['podminky'],
    Kaleta\Mcp\Vocabulary::elementToCzech(['type' => 'section', 'conditions' => ['url_parameter' => 'varianta']])['podminky']],
    [['signed_in' => 'no', 'from' => '2026-03-01', 'languages' => ['', 'de'], 'url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']], $conditionsCs, ['parametr' => 'varianta']]);

