<?php

declare(strict_types=1);

/* ---------- 3.50.1 review: pop-up rules that show it in fewer places, an earlier "true until" date and a hiding class are told apart from harmless saves ---------- */
use Kaleta\Builder\Build as R694Build;
use Kaleta\Builder\Popups as R694Popups;

$r694Rules = fn (array $over = []): array => R694Popups::sanitizeRules($over + ['kde' => 'vybrane', 'stranky' => [3, 5], 'kolekce' => ['jobs'], 'novinky' => true]);
check('3.50.1 review: Popups::rulesNarrowed – the same rules in another order, without a deleted page or a collection with no detail, or wider ones are no narrowing', [
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['stranky' => [5, 3]]), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(['stranky' => [3, 5, 99]]), $r694Rules(), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(['kolekce' => ['jobs', 'old']]), $r694Rules(), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['kde' => 'vse']), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(['do' => '2030-01-01']), $r694Rules(['do' => '']), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(['zarizeni' => 'telefon']), $r694Rules(), [3, 5], ['jobs'])],
    [false, false, false, false, false, false]);
check('3.50.1 review: Popups::rulesNarrowed – a live page, a collection or the news dropped, the whole site to chosen places, an end or a later start, one device, a campaign or a referrer narrow', [
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['stranky' => [3]]), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['kolekce' => []]), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['novinky' => false]), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(['kde' => 'vse']), $r694Rules(), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['do' => '2020-01-01']), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(['od' => '2020-01-01']), $r694Rules(['od' => '2020-02-01']), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['zarizeni' => 'pocitac']), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['utm' => 'spring']), [3, 5], ['jobs']),
    R694Popups::rulesNarrowed($r694Rules(), $r694Rules(['odkud' => 'example.com']), [3, 5], ['jobs'])],
    [true, true, true, true, true, true, true, true, true]);
check('3.50.1 review: Validity::endsEarlier – a first or an earlier "true until" date ends earlier; the same, a later one or none does not', [
    Kaleta\Core\Validity::endsEarlier(null, '2020-01-01'), Kaleta\Core\Validity::endsEarlier('2031-01-01', '2030-01-01'),
    Kaleta\Core\Validity::endsEarlier('2030-01-01', '2030-01-01'), Kaleta\Core\Validity::endsEarlier('2030-01-01', '2031-01-01'), Kaleta\Core\Validity::endsEarlier('2030-01-01', null)],
    [true, true, false, false, false]);
check('3.50.1 review: Build::classHides – display none in a state of the style or in the class CSS hides; other values, none or a deleted class do not', [
    R694Build::classHides(['styl' => ['mobil' => ['zobrazeni' => 'none']], 'css' => '']), R694Build::classHides(['styl' => [], 'css' => 'color: red; display:none;']),
    R694Build::classHides(['styl' => ['zaklad' => ['zobrazeni' => 'flex']], 'css' => 'grid-display: none;']), R694Build::classHides(null)],
    [true, true, false, false]);
$r694Build = (string) json_encode(['v' => 1, 'deti' => [['id' => 's694', 'typ' => 'sekce', 'tridy' => ['wrap694'], 'deti' => [
    ['id' => 'f694', 'typ' => 'formular', 'tridy' => ['card694'], 'obsah' => ['ucel' => \Kaleta\Core\Applications::PURPOSE, 'pole' => []]],
    ['id' => 't694', 'typ' => 'text', 'tridy' => ['note694'], 'obsah' => ['text' => 'x']],
    ['id' => 'k694', 'typ' => 'komponenta', 'tridy' => ['slot694'], 'obsah' => ['komponenta' => '12']]]]]]);
check('3.50.1 review: Build::applicationFormClasses – the classes on an application form and its ancestors, and on a component holding one; not on a sibling', [
    R694Build::applicationFormClasses($r694Build), R694Build::applicationFormClasses($r694Build, [12]), R694Build::applicationFormClasses(null)],
    [['wrap694', 'card694'], ['wrap694', 'card694', 'slot694'], []]);
