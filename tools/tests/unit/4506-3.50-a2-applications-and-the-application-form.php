<?php

declare(strict_types=1);

/* ---------- 3.50 A2: the recipients of an application form are locked like its Purpose; a publish that drops the form is refused (N132) ---------- */
use Kaleta\Builder\Build as A506Build;
use Kaleta\Builder\Publisher as A506Publisher;

$a506Form = fn (string $id, string $purpose, string $to): array => ['id' => $id, 'typ' => 'formular', 'obsah' => ['nazev' => 'Apply', 'pole' => [['typ' => 'text', 'popisek' => 'Name']]]
    + ($purpose !== '' ? ['ucel' => $purpose] : []) + ($to !== '' ? ['prijemce' => $to] : [])];
$a506Previous = ['v' => 1, 'deti' => [$a506Form('frm001', 'uchazec', 'hr@example.com'), $a506Form('frm002', '', 'office@example.com')]];
$a506Input = ['v' => 1, 'deti' => [$a506Form('frm001', 'uchazec', 'someone@example.org'), $a506Form('frm002', '', 'sales@example.com')]];
$a506To = fn (array $build): array => array_map(fn (array $p): string => (string) ($p['obsah']['prijemce'] ?? ''), $build['deti']);
A506Build::$purposeAllowed = false;
[$a506Locked, $a506Errors] = A506Build::sanitize($a506Input, false, $a506Previous);
A506Build::$purposeAllowed = true;
[$a506Open] = A506Build::sanitize($a506Input, false, $a506Previous);
A506Build::$purposeAllowed = null;
[$a506Cli] = A506Build::sanitize($a506Input, false, $a506Previous);
check('3.50 N132: without Applications the recipients of an application form stay (with a notice); an enquiry form\'s change freely; with the section or with no person (imports) both change', [
    $a506To($a506Locked), array_keys(array_filter($a506Errors, fn (string $v, string $k): bool => str_ends_with($k, '.obsah.prijemce'), ARRAY_FILTER_USE_BOTH)), $a506To($a506Open), $a506To($a506Cli)],
    [['hr@example.com', 'sales@example.com'], ['deti[0].obsah.prijemce'], ['someone@example.org', 'sales@example.com'], ['someone@example.org', 'sales@example.com']]);
check('3.50 N132: the notice about kept recipients reaches Claude in English (Translator)', Kaleta\Mcp\Translator::message((string) ($a506Errors['deti[0].obsah.prijemce'] ?? '')),
    'Only a user who may open Applications can change the recipients of a job application form – kept as they were.');

$a506Json = fn (array ...$forms): string => A506Build::toJson(['v' => 1, 'deti' => $forms]);
$a506Published = $a506Json($a506Form('frm001', 'uchazec', 'hr@example.com'), $a506Form('frm002', '', ''));
check('3.50 N132: droppedApplicationForms – a removed application form, one replaced by a form without the Purpose, an untouched or a nested one, an enquiry form removed, nothing published before, an empty new build', [
    A506Build::droppedApplicationForms($a506Published, $a506Json($a506Form('frm002', '', ''))),
    A506Build::droppedApplicationForms($a506Published, $a506Json($a506Form('frm009', '', 'hr@example.com'))),
    A506Build::droppedApplicationForms($a506Published, $a506Json(['id' => 'sec001', 'typ' => 'sekce', 'deti' => [$a506Form('frm001', 'uchazec', 'hr@example.com')]])),
    A506Build::droppedApplicationForms($a506Published, $a506Json($a506Form('frm001', 'uchazec', ''))),
    A506Build::droppedApplicationForms(null, $a506Json()), A506Build::droppedApplicationForms($a506Published, null)],
    [['frm001'], ['frm001'], [], [], [], ['frm001']]);

$a506Guard = function (?bool $allowed, ?string $published, ?string $draft): string {
    A506Build::$purposeAllowed = $allowed;
    try {
        A506Publisher::guard(['stavba' => $published, 'stavba_koncept' => $draft]);

        return 'published';
    } catch (\DomainException $e) {
        return $e->getMessage() === A506Publisher::APPLICATION_FORM_REFUSED ? 'refused' : 'other';
    } finally {
        A506Build::$purposeAllowed = null;
    }
};
$a506Dropped = $a506Json($a506Form('frm002', '', ''));
check('3.50 N132: Publisher::guard refuses a publish that drops an application form only for a person without Applications; null (the CLI, imports) and the section publish; a draft that keeps it publishes', [
    $a506Guard(false, $a506Published, $a506Dropped), $a506Guard(true, $a506Published, $a506Dropped), $a506Guard(null, $a506Published, $a506Dropped),
    $a506Guard(false, $a506Published, $a506Published), $a506Guard(false, $a506Published, null)],
    ['refused', 'published', 'published', 'published', 'published']);
check('3.50 N132: the refusal is translated for Claude and the editor (en message, cs/de/es admin text)', [
    Kaleta\Mcp\Translator::message(A506Publisher::APPLICATION_FORM_REFUSED),
    ...array_map(fn (string $code): bool => Kaleta\Core\Language::runWith($code, fn (): string => t('Only a user who may open Applications can remove or replace a job application form. Nothing was published; your changes stay saved as a draft.'), 'admin-')
        !== 'Only a user who may open Applications can remove or replace a job application form. Nothing was published; your changes stay saved as a draft.', ['cs', 'de', 'es'])],
    ['Only a user who may open Applications can remove or replace a job application form – not published, the draft stays saved.', true, true, true]);
