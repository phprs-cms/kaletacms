<?php

declare(strict_types=1);

/* ---------- 3.51 R0.6: the three texts of the e-mails to visitors – per language, empty = the default of the reader's language ---------- */
use Kaleta\Admin\Modules\Settings as R793Module;
use Kaleta\Core\Settings as R793Settings;

$r793Settings = function (array $values): R793Settings {
    $s = new R793Settings(new Kaleta\Core\Db('invalid-r793:', '', ''));
    (new ReflectionProperty(R793Settings::class, 'values'))->setValue($s, $values + ['site_language' => 'cs', 'additional_languages' => 'en,de']);
    (new ReflectionProperty(R793Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(R793Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));

    return $s;
};
$r793Changed = $r793Settings(['mail_identity' => 'Píše {{company}}, {{address}}', 'mail_identity_en' => '', 'mail_identity_de' => 'Von {{company}}, {{address}}', 'newsletter_reason' => '']);
check('3.51 R0.6: wording() – the stored value of the language; for the default language ("" or its code) the stored base; a stored empty value gives the default; an empty English field on a Czech site with a changed base gives the English default', [
    $r793Changed->wording('mail_identity', 'de'), $r793Changed->wording('mail_identity', ''), $r793Changed->wording('mail_identity', 'cs'),
    $r793Changed->wording('mail_identity', 'en'), $r793Changed->wording('newsletter_reason', ''), $r793Changed->wording('newsletter_reason', 'de'),
    $r793Changed->wording('mail_refuse_text', 'en')], [
    'Von {{company}}, {{address}}', 'Píše {{company}}, {{address}}', 'Píše {{company}}, {{address}}',
    'Sent by {{company}}, {{address}}.', 'Tento e-mail dostáváte, protože jste se přihlásili k odběru novinek z webu {{site}} a přihlášení potvrdili.',
    'Sie erhalten diese E-Mail, weil Sie sich für Neuigkeiten von {{site}} angemeldet und die Anmeldung bestätigt haben.',
    'If you do not want e-mails like this from us, tell us here: {{link}}']);
$r793English = $r793Settings(['site_language' => 'en', 'additional_languages' => 'cs']);
check('3.51 R0.6: on an English site nothing stored gives the English default, the Czech version the Czech one; storedWording() is what the form shows', [
    $r793English->wording('mail_identity', ''), $r793English->wording('mail_identity', 'cs'), $r793Changed->storedWording('mail_identity'), $r793English->storedWording('mail_identity')],
    ['Sent by {{company}}, {{address}}.', 'Odesílatel: {{company}}, {{address}}.', 'Píše {{company}}, {{address}}', '']);
check('3.51 R0.6: the templates – mail_identity needs {{company}} and {{address}}, mail_refuse_text {{link}}, at most 300 characters; empty is accepted; a language variant is checked alike', [
    R793Module::verifyValue('mail_identity', 'Od {{company}}'), R793Module::verifyValue('mail_identity', '{{address}} – {{company}}'), R793Module::verifyValue('mail_identity', ''),
    R793Module::verifyValue('mail_refuse_text', 'Write to us'), R793Module::verifyValue('mail_refuse_text', 'Here: {{link}}'), R793Module::verifyValue('mail_refuse_text_en', 'No link'),
    R793Module::verifyValue('newsletter_reason', str_repeat('a', 301)), R793Module::verifyValue('newsletter_reason', 'Because of {{site}}.'),
    R793Module::verifyValue('mail_identity', '{{company}} {{address}}' . str_repeat('x', 300))],
    [null, '{{address}} – {{company}}', '', null, 'Here: {{link}}', null, null, 'Because of {{site}}.', null]);
check('3.51 R0.6: Claude may not change them (update_settings), in no language; they are exported and per language, on the Mail tab', [
    array_map(R793Settings::claudeMaySet(...), ['mail_identity', 'newsletter_reason', 'mail_refuse_text', 'mail_identity_en', 'mail_refuse_text_de']),
    array_values(array_intersect(R793Settings::WORDINGS, R793Settings::keysWith('export'))), array_values(array_intersect(R793Settings::WORDINGS, R793Settings::keysWith('per_language'))),
    array_keys(array_intersect_key(R793Settings::fields()['mail'], array_flip(R793Settings::WORDINGS)))],
    [[false, false, false, false, false], R793Settings::WORDINGS, R793Settings::WORDINGS, R793Settings::WORDINGS]);
