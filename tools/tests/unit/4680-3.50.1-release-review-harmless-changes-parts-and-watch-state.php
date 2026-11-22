<?php

declare(strict_types=1);

/* ---------- 3.50.1 release review: harmless classes and wider conditions publish, site parts and chosen pages in the watch, a bounded watch state ---------- */
use Kaleta\Builder\ApplicationFormWatch as W680;
use Kaleta\Builder\Build as B680;
use Kaleta\Builder\Popups as P680;

$b680Form = fn (array $over = []): array => $over + ['id' => 'frm680', 'typ' => 'formular', 'obsah' => ['nazev' => 'Apply', 'ucel' => \Kaleta\Core\Applications::PURPOSE, 'pole' => []]];
$b680Json = fn (array $section, array ...$children): string => (string) json_encode(['v' => 1, 'deti' => [['id' => 'sek680', 'typ' => 'sekce'] + $section + ['deti' => $children]]]);
$b680Published = $b680Json([], $b680Form());
check('3.50.1 release review: shadowedApplicationForms with the hiding classes known – a spacing class on the form or its section, or the form moved into a section with one, passes; a class that hides is refused', [
    B680::shadowedApplicationForms($b680Published, $b680Json(['tridy' => ['space680']], $b680Form()), ['gone680']),
    B680::shadowedApplicationForms($b680Published, $b680Json([], $b680Form(['tridy' => ['space680']])), ['gone680']),
    B680::shadowedApplicationForms($b680Published, (string) json_encode(['v' => 1, 'deti' => [['id' => 'oth680', 'typ' => 'sekce', 'tridy' => ['card680'], 'deti' => [$b680Form()]]]]), []),
    B680::shadowedApplicationForms($b680Published, $b680Json(['tridy' => ['space680', 'gone680']], $b680Form()), ['gone680']),
    B680::shadowedApplicationForms($b680Published, $b680Json([], $b680Form(['tridy' => ['gone680']])), ['gone680']),
    B680::shadowedApplicationForms($b680Published, $b680Json(['tridy' => ['space680']], $b680Form()))],
    [[], [], [], ['frm680'], ['frm680'], ['frm680']]);
$b680Until = fn (string $date): array => ['podminky' => ['do' => $date]];
check('3.50.1 release review: shadowedApplicationForms – a later "do" date, an earlier "od", one more language or a condition dropped passes; an earlier "do", a new condition or a new language list is refused', [
    B680::shadowedApplicationForms($b680Json($b680Until('2026-12-31'), $b680Form()), $b680Json($b680Until('2027-06-30'), $b680Form())),
    B680::shadowedApplicationForms($b680Json(['podminky' => ['od' => '2026-12-01']], $b680Form()), $b680Json(['podminky' => ['od' => '2026-11-01']], $b680Form())),
    B680::shadowedApplicationForms($b680Json(['podminky' => ['jazyky' => ['']]], $b680Form()), $b680Json(['podminky' => ['jazyky' => ['', 'en']]], $b680Form())),
    B680::shadowedApplicationForms($b680Json(['podminky' => ['do' => '2026-12-31', 'prihlaseni' => 'ne']], $b680Form()), $b680Json($b680Until('2026-12-31'), $b680Form())),
    B680::shadowedApplicationForms($b680Json($b680Until('2026-12-31'), $b680Form()), $b680Json($b680Until('2026-12-01'), $b680Form())),
    B680::shadowedApplicationForms($b680Json($b680Until('2026-12-31'), $b680Form()), $b680Json(['podminky' => ['do' => '2026-12-31', 'prihlaseni' => 'ano']], $b680Form())),
    B680::shadowedApplicationForms($b680Json(['podminky' => ['jazyky' => ['', 'en']]], $b680Form()), $b680Json(['podminky' => ['jazyky' => ['en']]], $b680Form())),
    B680::shadowedApplicationForms($b680Published, $b680Json([], $b680Form($b680Until('2030-01-01'))))],
    [[], [], [], [], ['frm680'], ['frm680'], ['frm680'], ['frm680']]);
check('3.50.1 release review: conditionsNarrower – pure comparison of two condition sets', [
    B680::conditionsNarrower([], []), B680::conditionsNarrower(['do' => '2026-01-01'], []), B680::conditionsNarrower([], ['do' => '2026-01-01']),
    B680::conditionsNarrower(['parametr' => ['nazev' => 'a', 'hodnota' => 'b']], ['parametr' => ['nazev' => 'a', 'hodnota' => 'c']])],
    [false, false, true, true]);

$b680Slot = ['id' => 'kmp680', 'typ' => 'komponenta', 'obsah' => ['komponenta' => 5]];
check('3.50.1 release review: Build::applicationFormPresence decodes once – holds and shows a form, holds a hidden one, holds through a component that does not show, holds nothing', [
    B680::applicationFormPresence($b680Published, [], [], []),
    B680::applicationFormPresence($b680Json(['tridy' => ['gone680']], $b680Form()), ['gone680'], [], []),
    B680::applicationFormPresence($b680Json([], $b680Slot), [], [5], []),
    B680::applicationFormPresence($b680Json([], $b680Slot), [], [5], [5]),
    B680::applicationFormPresence($b680Json([], $b680Form(['obsah' => ['ucel' => '', 'pole' => []]])), [], [], []),
    B680::applicationFormPresence('not json', [], [], [])],
    [[true, true], [true, false], [true, false], [true, true], [false, false], [false, false]]);

$w680Popup = ['aktivni' => 1, 'valid_until' => null, 'spoustec' => 'cas', 'hodnota' => 5, 'pravidla' => (string) json_encode(['kde' => 'vybrane', 'stranky' => [7]] + P680::defaultRules())];
$w680Collection = ['pravidla' => (string) json_encode(['kde' => 'vybrane', 'kolekce' => ['jobs']] + P680::defaultRules())] + $w680Popup;
check('3.50.1 release review: popupCanShow with the live pages known – a pop-up limited to a page that can show opens; to a hidden or trashed one, or a collection without item pages, it does not; news or the whole site always', [
    W680::popupCanShow($w680Popup, '2026-11-22', [7], []), W680::popupCanShow($w680Popup, '2026-11-22', [], []), W680::popupCanShow($w680Popup, '2026-11-22'),
    W680::popupCanShow($w680Collection, '2026-11-22', [], ['jobs']), W680::popupCanShow($w680Collection, '2026-11-22', [], []),
    W680::popupCanShow(['pravidla' => (string) json_encode(['kde' => 'vybrane', 'stranky' => [7], 'novinky' => true] + P680::defaultRules())] + $w680Popup, '2026-11-22', [], []),
    W680::popupCanShow(['pravidla' => (string) json_encode(P680::defaultRules())] + $w680Popup, '2026-11-22', [], [])],
    [true, false, true, true, false, true, true]);
check('3.50.1 release review: partCanShow – the default part always; a variant on a page that can show, or for news, the list, a collection with item pages or pages under a parent; a variant whose pages are all hidden not', [
    W680::partCanShow(['varianta' => '', 'stranky' => null, 'pravidla' => null], [], []),
    W680::partCanShow(['varianta' => 'jobs', 'stranky' => '[7,8]', 'pravidla' => null], [8], []),
    W680::partCanShow(['varianta' => 'jobs', 'stranky' => '[7,8]', 'pravidla' => null], [9], []),
    W680::partCanShow(['varianta' => 'jobs', 'stranky' => '[]', 'pravidla' => '{"vypis":true}'], [], []),
    W680::partCanShow(['varianta' => 'jobs', 'stranky' => '[]', 'pravidla' => '{"kolekce":["jobs"]}'], [], ['jobs']),
    W680::partCanShow(['varianta' => 'jobs', 'stranky' => '[]', 'pravidla' => '{"kolekce":["jobs"]}'], [], [])],
    [true, true, false, true, true, false]);
$w680Key = W680::partKey('paticka', 'zh-hant', str_repeat('v', 40));
check('3.50.1 release review: the key of a site part fits a notification target (40 characters), differs by variant and survives the stored state', [
    strlen($w680Key) <= 40, $w680Key !== W680::partKey('paticka', 'zh-hant', 'other'), W680::state(W680::encode(['reachable' => [$w680Key], 'alerted' => [$w680Key => 'Footer']]))],
    [true, true, ['reachable' => [$w680Key], 'alerted' => [$w680Key => 'Footer']]]);

// the state: compact (ids by kind, no titles); thousands of watched pages stay under the TEXT column; the 3.50.1 form is still read
$w680Many = ['reachable' => array_merge(array_map(fn (int $i): string => 'form:page:' . (100000 + $i), range(1, 5000)), ['form:popup:3', $w680Key]),
    'alerted' => array_fill_keys(array_map(fn (int $i): string => 'form:page:' . $i, range(1, W680::KEEP_ALERTED)), str_repeat('Kariéra – pracovní místo ', 8))];
$w680Encoded = W680::encode($w680Many);
$w680Huge = W680::encode(['reachable' => array_map(fn (int $i): string => 'form:page:' . (1000000000 + $i), range(1, 20000)), 'alerted' => []]);
$w680Back = W680::state($w680Encoded);
check('3.50.1 release review: watch state – 5,000 watched pages with 100 reported Czech titles encode under the limit and read back whole; 20,000 are cut to fit, never over 64 kB', [
    strlen($w680Encoded) <= W680::MAX_STATE, count($w680Back['reachable']), count($w680Back['alerted']), in_array($w680Key, $w680Back['reachable'], true),
    strlen($w680Huge) <= W680::MAX_STATE, count(W680::state($w680Huge)['reachable']) > 1000,
    W680::state('{"reachable":{"form:page:3":"Careers","form:part:0123456789abcdef":"Footer"},"alerted":{"form:popup:2":"Jobs"}}')],
    [true, 5002, W680::KEEP_ALERTED, true, true, true, ['reachable' => ['form:page:3', 'form:part:0123456789abcdef'], 'alerted' => ['form:popup:2' => 'Jobs']]]);
