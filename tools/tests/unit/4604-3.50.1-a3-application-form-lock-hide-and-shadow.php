<?php

declare(strict_types=1);

/* ---------- 3.50.1 A3a: a published application form cannot be hidden or shadowed by a form with a file field (SEC-l1, D2) ---------- */
use Kaleta\Builder\Build as A604Build;
use Kaleta\Builder\Publisher as A604Publisher;

$a604Form = fn (string $id, string $purpose, string $label = 'Name', bool $file = false, array $style = []): array => ['id' => $id, 'typ' => 'formular',
    'obsah' => ['nazev' => 'Form ' . $id, 'pole' => array_merge([['typ' => 'text', 'popisek' => $label]], $file ? [['typ' => 'soubor', 'popisek' => 'CV']] : [])] + ($purpose !== '' ? ['ucel' => $purpose] : [])]
    + ($style !== [] ? ['styl' => $style] : []);
$a604Json = fn (array $sectionStyle, array ...$forms): string => (string) json_encode(['v' => 1, 'deti' => [['id' => 'sek604', 'typ' => 'sekce'] + ($sectionStyle !== [] ? ['styl' => $sectionStyle] : []) + ['deti' => $forms]]]);
$a604Hide = ['zaklad' => ['zobrazeni' => 'none']];
$a604Published = $a604Json([], $a604Form('frm001', 'uchazec'), $a604Form('frm003', '', 'Message', true));
check('3.50.1 A3a: shadowedApplicationForms – hiding the application form (its own style, an ancestor\'s, in mobil only) is reported; the same hiding already published, or a label change, is not', [
    A604Build::shadowedApplicationForms($a604Published, $a604Json([], $a604Form('frm001', 'uchazec', 'Name', false, $a604Hide), $a604Form('frm003', '', 'Message', true))),
    A604Build::shadowedApplicationForms($a604Published, $a604Json($a604Hide, $a604Form('frm001', 'uchazec'), $a604Form('frm003', '', 'Message', true))),
    A604Build::shadowedApplicationForms($a604Published, $a604Json([], $a604Form('frm001', 'uchazec', 'Name', false, ['mobil' => ['zobrazeni' => 'none']]), $a604Form('frm003', '', 'Message', true))),
    A604Build::shadowedApplicationForms($a604Json($a604Hide, $a604Form('frm001', 'uchazec')), $a604Json($a604Hide, $a604Form('frm001', 'uchazec', 'Full name'))),
    A604Build::shadowedApplicationForms($a604Published, $a604Json([], $a604Form('frm001', 'uchazec', 'Full name'), $a604Form('frm003', '', 'Your message', true)))],
    [['frm001'], ['frm001'], ['frm001'], [], []]);
check('3.50.1 A3a: shadowedApplicationForms – a new form with a file field beside it, a file field added to an enquiry form, the id swapped with the application form; nothing without a published application form', [
    A604Build::shadowedApplicationForms($a604Published, $a604Json([], $a604Form('frm001', 'uchazec'), $a604Form('frm003', '', 'Message', true), $a604Form('frm009', '', 'CV', true))),
    A604Build::shadowedApplicationForms($a604Json([], $a604Form('frm001', 'uchazec'), $a604Form('frm003', '', 'Message')), $a604Json([], $a604Form('frm001', 'uchazec'), $a604Form('frm003', '', 'Message', true))),
    A604Build::shadowedApplicationForms($a604Published, $a604Json([], $a604Form('frm001', '', 'Name', true), $a604Form('frm002', 'uchazec'), $a604Form('frm003', '', 'Message', true))),
    A604Build::shadowedApplicationForms($a604Json([], $a604Form('frm003', '', 'Message')), $a604Json($a604Hide, $a604Form('frm003', '', 'Message', true), $a604Form('frm009', '', 'CV', true))),
    A604Build::shadowedApplicationForms(null, $a604Published), A604Build::shadowedApplicationForms($a604Published, null)],
    [['frm009'], ['frm003'], ['frm001'], [], [], []]);

$a604Guard = function (?bool $allowed, string $draft) use ($a604Published): string {
    A604Build::$purposeAllowed = $allowed;
    try {
        A604Publisher::guard(['stavba' => $a604Published, 'stavba_koncept' => $draft]);

        return 'ok';
    } catch (\DomainException $e) {
        return Kaleta\Mcp\Translator::message($e->getMessage());
    } finally {
        A604Build::$purposeAllowed = null;
    }
};
$a604Hidden = $a604Json($a604Hide, $a604Form('frm001', 'uchazec'), $a604Form('frm003', '', 'Message', true));
check('3.50.1 A3a: Publisher::guard – without Applications hiding is refused (in English for Claude), a label change publishes; with the section everything publishes', [
    $a604Guard(false, $a604Hidden), $a604Guard(false, $a604Json([], $a604Form('frm001', 'uchazec', 'Full name'), $a604Form('frm003', '', 'Message', true))), $a604Guard(true, $a604Hidden)],
    ['Only a user who may open Applications can hide a job application form or add another form with a file field beside it – not published, the draft stays saved.', 'ok', 'ok']);
