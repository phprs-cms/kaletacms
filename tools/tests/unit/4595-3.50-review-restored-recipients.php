<?php

declare(strict_types=1);

/* ---------- 3.50 review: a publish cannot bring back other recipients of a published application form (a restored older version) ---------- */
use Kaleta\Builder\Build as R595Build;
use Kaleta\Builder\Publisher as R595Publisher;

$r595Form = fn (string $id, string $purpose, string $to, string $bcc): array => ['id' => $id, 'typ' => 'formular', 'obsah' => ['nazev' => 'Apply', 'pole' => [['typ' => 'text', 'popisek' => 'Name']]]
    + ($purpose !== '' ? ['ucel' => $purpose] : []) + ($to !== '' ? ['prijemce' => $to] : []) + ($bcc !== '' ? ['skryta_kopie' => $bcc] : [])];
$r595Json = fn (array ...$forms): string => (string) json_encode(['v' => 1, 'deti' => [['id' => 'sek595', 'typ' => 'sekce', 'deti' => $forms]]]);
$r595Published = $r595Json($r595Form('frm001', 'uchazec', 'hr-new@example.com', 'boss-new@example.com'), $r595Form('frm003', '', 'office@example.com', ''));
check('3.50 review: changedApplicationRecipients – an application form (nested) with an old recipient or hidden copy; the same ones, an enquiry form\'s change, a removed form (droppedApplicationForms says it) or no build are none', [
    R595Build::changedApplicationRecipients($r595Published, $r595Json($r595Form('frm001', 'uchazec', 'hr-old@example.com', 'boss-new@example.com'), $r595Form('frm003', '', 'office@example.com', ''))),
    R595Build::changedApplicationRecipients($r595Published, $r595Json($r595Form('frm001', 'uchazec', 'hr-new@example.com', ''), $r595Form('frm003', '', 'office@example.com', ''))),
    R595Build::changedApplicationRecipients($r595Published, $r595Json($r595Form('frm001', 'uchazec', 'hr-new@example.com', 'boss-new@example.com'), $r595Form('frm003', '', 'old@example.com', 'x@example.com'))),
    R595Build::changedApplicationRecipients($r595Published, $r595Json($r595Form('frm003', '', 'office@example.com', ''))),
    R595Build::droppedApplicationForms($r595Published, $r595Json($r595Form('frm003', '', 'office@example.com', ''))),
    R595Build::changedApplicationRecipients(null, $r595Published), R595Build::changedApplicationRecipients($r595Published, null)],
    [['frm001'], ['frm001'], [], [], ['frm001'], [], []]);

$r595Guard = function (?bool $allowed, ?string $draft) use ($r595Published): string {
    R595Build::$purposeAllowed = $allowed;
    try {
        R595Publisher::guard(['stavba' => $r595Published, 'stavba_koncept' => $draft]);

        return 'ok';
    } catch (\DomainException $e) {
        return Kaleta\Mcp\Translator::message($e->getMessage());
    } finally {
        R595Build::$purposeAllowed = null;
    }
};
$r595Old = $r595Json($r595Form('frm001', 'uchazec', 'hr-old@example.com', 'boss-old@example.com'), $r595Form('frm003', '', 'office@example.com', ''));
check('3.50 review: Publisher::guard – without Applications a draft with the old recipients is refused (in English for Claude), the published ones or no draft pass; with the section or without a person (null) it publishes', [
    $r595Guard(false, $r595Old), $r595Guard(false, $r595Published), $r595Guard(false, null), $r595Guard(true, $r595Old), $r595Guard(null, $r595Old),
    $r595Guard(false, $r595Json($r595Form('frm003', '', 'office@example.com', '')))],
    ['Only a user who may open Applications can change the recipients of a job application form – not published, the draft stays saved.', 'ok', 'ok', 'ok', 'ok',
        'Only a user who may open Applications can remove or replace a job application form – not published, the draft stays saved.']);
