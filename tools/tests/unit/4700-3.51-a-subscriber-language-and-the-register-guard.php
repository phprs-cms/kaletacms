<?php

declare(strict_types=1);

/* ---------- 3.51 A1, A3: a sign-up keeps its language; an address on the send register gets one confirmation e-mail a week at most ---------- */
use Kaleta\Front\Subscription as A700Subscription;

check('3.51 A3: the guard is a week', A700Subscription::REGISTER_CONFIRM_DAYS, 7);
check('3.51 A3: mayConfirmAgain() – an address off the register is always mailed; on the register only when the last confirmation e-mail is a week old (or its time unreadable)', [
    A700Subscription::mayConfirmAgain('2026-11-22 09:00:00', false, '2026-11-22 10:00:00'),
    A700Subscription::mayConfirmAgain('2026-11-22 09:00:00', true, '2026-11-22 10:00:00'),
    A700Subscription::mayConfirmAgain('2026-11-15 10:00:01', true, '2026-11-22 10:00:00'),
    A700Subscription::mayConfirmAgain('2026-11-15 10:00:00', true, '2026-11-22 10:00:00'),
    A700Subscription::mayConfirmAgain('2026-10-01 10:00:00', true, '2026-11-22 10:00:00'),
    A700Subscription::mayConfirmAgain('', true, '2026-11-22 10:00:00')],
    [true, false, false, true, true, true]);

// the language column the sign-up writes is the language version being shown ('' = the default language)
$a700Settings = new Kaleta\Core\Settings(new Kaleta\Core\Db('invalid-a700:', '', ''));
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($a700Settings, ['site_language' => 'cs', 'extensions' => 'jazyky', 'additional_languages' => 'en,de']);
(new ReflectionProperty(Kaleta\Core\Settings::class, 'lazyRead'))->setValue($a700Settings, array_fill_keys((new ReflectionMethod(Kaleta\Core\Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));
$a700Before = [Kaleta\Core\Language::code(), Kaleta\Core\Language::siteColumn()];
$a700Columns = [];
foreach (['en', 'cs', 'de', 'fr'] as $a700Code) {
    Kaleta\Core\Language::setSite($a700Settings, $a700Code);
    $a700Columns[] = Kaleta\Core\Language::siteColumn();
}
Kaleta\Core\Language::set($a700Before[0]); // the files after this one see the language they saw before
(new ReflectionProperty(Kaleta\Core\Language::class, 'column'))->setValue(null, $a700Before[1]);
check('3.51 A1: Language::siteColumn() – what subscribe() stores – is the additional language shown, \'\' for the default one and for a language the site does not offer', $a700Columns, ['en', '', 'de', '']);
