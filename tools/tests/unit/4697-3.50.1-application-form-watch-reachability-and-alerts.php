<?php

declare(strict_types=1);

/* ---------- 3.50.1: the hourly application form watch – what takes a form off the site, alerting once and resolving; pop-up pages the form did not offer stay ---------- */
use Kaleta\Builder\ApplicationFormWatch as W697;
use Kaleta\Builder\Build as B697;
use Kaleta\Builder\Popups as P697;

$w697Form = fn (array $over = []): array => $over + ['id' => 'f697', 'typ' => 'formular', 'obsah' => ['ucel' => \Kaleta\Core\Applications::PURPOSE, 'pole' => []]];
$w697Build = fn (array $form, array $section = []): string => (string) json_encode(['v' => 1, 'deti' => [$section + ['id' => 's697', 'typ' => 'sekce', 'deti' => [$form]]]]);
check('3.50.1 watch: Build::showsApplicationForm – a plain application form shows; hidden by its style, an ancestor\'s style, display conditions or a hiding class it does not', [
    B697::showsApplicationForm($w697Build($w697Form()), [], []),
    B697::showsApplicationForm($w697Build($w697Form(['styl' => ['mobil' => ['zobrazeni' => 'none']]])), [], []),
    B697::showsApplicationForm($w697Build($w697Form(), ['styl' => ['zaklad' => ['zobrazeni' => 'none']]]), [], []),
    B697::showsApplicationForm($w697Build($w697Form(['podminky' => [['typ' => 'jazyk', 'hodnota' => 'en']]])), [], []),
    B697::showsApplicationForm($w697Build($w697Form(), ['tridy' => ['gone697']]), ['gone697'], []),
    B697::showsApplicationForm($w697Build($w697Form(['tridy' => ['card697']])), ['gone697'], []),
    B697::showsApplicationForm($w697Build(['id' => 'f697', 'typ' => 'formular', 'obsah' => ['ucel' => '', 'pole' => []]]), [], []),
    B697::showsApplicationForm(null, [], [])],
    [true, false, false, false, false, true, false, false]);
$w697Slot = ['id' => 'k697', 'typ' => 'komponenta', 'obsah' => ['komponenta' => 7]];
check('3.50.1 watch: Build::showsApplicationForm – a component that shows an application form counts, unless the element placing it is hidden; another component does not', [
    B697::showsApplicationForm($w697Build($w697Slot), [], [7]),
    B697::showsApplicationForm($w697Build($w697Slot + ['styl' => ['tablet' => ['zobrazeni' => 'none']]]), [], [7]),
    B697::showsApplicationForm($w697Build($w697Slot), [], [8])],
    [true, false, false]);

$w697Page = ['zobrazit' => 1, 'smazano' => null, 'heslo_hash' => null, 'valid_until' => null];
check('3.50.1 watch: pageCanShow – visible, not in the trash, without a password and still true; hidden, trashed, with a password or expired is not', [
    W697::pageCanShow($w697Page, '2026-11-22'), W697::pageCanShow(['valid_until' => '2026-11-22'] + $w697Page, '2026-11-22'),
    W697::pageCanShow(['zobrazit' => 0] + $w697Page, '2026-11-22'), W697::pageCanShow(['smazano' => '2026-11-20 10:00:00'] + $w697Page, '2026-11-22'),
    W697::pageCanShow(['heslo_hash' => 'x'] + $w697Page, '2026-11-22'), W697::pageCanShow(['valid_until' => '2026-11-21'] + $w697Page, '2026-11-22')],
    [true, true, false, false, false, false]);
$w697Popup = ['aktivni' => 1, 'valid_until' => null, 'pravidla' => json_encode(P697::defaultRules()), 'spoustec' => 'cas', 'hodnota' => 5];
$w697Rules = fn (array $over): string => (string) json_encode($over + P697::defaultRules());
check('3.50.1 watch: popupCanShow – switched on, true, in its period, shown somewhere, with a trigger a visitor meets; off, expired, out of its period, nowhere, by click or with a huge count, delay or scroll is not', [
    W697::popupCanShow($w697Popup, '2026-11-22'),
    W697::popupCanShow(['spoustec' => 'stranky', 'hodnota' => 10] + $w697Popup, '2026-11-22'), W697::popupCanShow(['spoustec' => 'necinnost', 'hodnota' => 120] + $w697Popup, '2026-11-22'),
    W697::popupCanShow(['spoustec' => 'posun', 'hodnota' => 100] + $w697Popup, '2026-11-22'), W697::popupCanShow(['spoustec' => 'odchod'] + $w697Popup, '2026-11-22'),
    W697::popupCanShow(['pravidla' => $w697Rules(['kde' => 'vybrane', 'novinky' => true])] + $w697Popup, '2026-11-22'),
    W697::popupCanShow(['aktivni' => 0] + $w697Popup, '2026-11-22'), W697::popupCanShow(['valid_until' => '2026-11-21'] + $w697Popup, '2026-11-22'),
    W697::popupCanShow(['pravidla' => $w697Rules(['od' => '2026-12-01'])] + $w697Popup, '2026-11-22'), W697::popupCanShow(['pravidla' => $w697Rules(['do' => '2026-11-21'])] + $w697Popup, '2026-11-22'),
    W697::popupCanShow(['pravidla' => $w697Rules(['kde' => 'vybrane'])] + $w697Popup, '2026-11-22'),
    W697::popupCanShow(['spoustec' => 'klik'] + $w697Popup, '2026-11-22'), W697::popupCanShow(['spoustec' => 'stranky', 'hodnota' => 11] + $w697Popup, '2026-11-22'),
    W697::popupCanShow(['spoustec' => 'cas', 'hodnota' => 3600] + $w697Popup, '2026-11-22'), W697::popupCanShow(['spoustec' => 'posun', 'hodnota' => 101] + $w697Popup, '2026-11-22')],
    [true, true, true, true, true, true, false, false, false, false, false, false, false, false, false]);

$w697Start = W697::changes(W697::state(''), ['form:page:3' => ['reachable' => true, 'title' => 'Careers'], 'form:popup:2' => ['reachable' => false, 'title' => 'Jobs']]);
$w697Off = W697::changes($w697Start['state'], ['form:page:3' => ['reachable' => false, 'title' => 'Careers'], 'form:popup:2' => ['reachable' => false, 'title' => 'Jobs']]);
$w697Again = W697::changes($w697Off['state'], ['form:page:3' => ['reachable' => false, 'title' => 'Careers']]);
$w697Back = W697::changes($w697Again['state'], ['form:page:3' => ['reachable' => true, 'title' => 'Careers']]);
$w697Gone = W697::changes($w697Back['state'], []);
check('3.50.1 watch: changes – a reachable form that is not reachable alerts once (never one that was not reachable), stays quiet while it stays off, resolves when it is back, and a page that is gone alerts too', [
    $w697Start['alert'], $w697Start['state']['reachable'], $w697Off['alert'], $w697Off['resolve'], $w697Again['alert'], $w697Again['state']['alerted'],
    $w697Back['alert'], $w697Back['resolve'], $w697Back['state'], $w697Gone['alert']],
    [[], ['form:page:3'], ['form:page:3' => 'Careers'], [], [], ['form:page:3' => 'Careers'],
    [], ['form:page:3'], ['reachable' => ['form:page:3'], 'alerted' => []], ['form:page:3' => '']]);
check('3.50.1 watch: state – keys other than form:page:<id> / form:popup:<id> and anything that does not decode are dropped; the alerted keys stay bounded', [
    W697::state('{"reachable":{"form:page:3":"A","x":"B","form:news:4":"C"},"alerted":[1]}'), W697::state('not json'),
    count(W697::changes(['reachable' => array_map(fn (int $i): string => 'form:page:' . $i, range(1, 150)), 'alerted' => []], [])['state']['alerted'])],
    [['reachable' => ['form:page:3'], 'alerted' => []], ['reachable' => [], 'alerted' => []], W697::KEEP_ALERTED]);
check('3.50.1 watch: the bell – kind form_unreachable leads to Applications for everyone notified, says what happened, and is a known kind that no stand-in copies', [
    Kaleta\Core\Inbox::accountPlace(W697::KIND), in_array(W697::KIND, Kaleta\Core\Inbox::KINDS, true), in_array(W697::KIND, Kaleta\Core\Inbox::STAND_IN_KINDS, true),
    Kaleta\Core\Inbox::where(Kaleta\Core\Inbox::accountPlace(W697::KIND)), Kaleta\Core\Inbox::parse('form:page:3'), Kaleta\Core\Scheduler::JOBS['application_forms'][0] ?? null],
    [['kind' => 'applications', 'id' => 0, 'title' => '', 'path' => 'admin.php?module=applications'], true, false, 'Applications', null, 3600]);
check('3.50.1 review: Popups::keepUnoffered – a pop-up\'s stored pages the settings form did not list (more than 500) stay chosen; unticking a listed one drops it', [
    P697::keepUnoffered([3, 700, 701], [5], [3, 5]), P697::keepUnoffered([3], [], [3]), P697::keepUnoffered(['jobs', 'old'], ['jobs'], ['jobs'])],
    [[5, 700, 701], [], ['jobs', 'old']]);
