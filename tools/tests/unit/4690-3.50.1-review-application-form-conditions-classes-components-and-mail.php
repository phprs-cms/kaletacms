<?php

declare(strict_types=1);

/* ---------- 3.50.1 review: display conditions, a class or a component cannot shadow a published application form; a failed log clean-up sends no second copy ---------- */
use Kaleta\Builder\Build as R690Build;
use Kaleta\Builder\Publisher as R690Publisher;

$r690Form = fn (string $id, string $purpose, bool $file = false, array $extra = []): array => ['id' => $id, 'typ' => 'formular',
    'obsah' => ['nazev' => 'Form ' . $id, 'pole' => array_merge([['typ' => 'text', 'popisek' => 'Name']], $file ? [['typ' => 'soubor', 'popisek' => 'CV']] : [])] + ($purpose !== '' ? ['ucel' => $purpose] : [])] + $extra;
$r690Json = fn (array $section, array ...$children): string => (string) json_encode(['v' => 1, 'deti' => [['id' => 'sek690', 'typ' => 'sekce'] + $section + ['deti' => $children]]]);
$r690Until = ['podminky' => ['do' => '2020-01-01']];
$r690Published = $r690Json([], $r690Form('frm690', 'uchazec'));
check('3.50.1 review: shadowedApplicationForms – new display conditions on the application form or an ancestor or a class added to it hide it (no database at hand: every class counts); conditions already published, a later "do" date, removed ones, or a label change do not', [
    R690Build::shadowedApplicationForms($r690Published, $r690Json([], $r690Form('frm690', 'uchazec', false, $r690Until))),
    R690Build::shadowedApplicationForms($r690Published, $r690Json(['podminky' => ['prihlaseni' => 'ano']], $r690Form('frm690', 'uchazec'))),
    R690Build::shadowedApplicationForms($r690Json($r690Until, $r690Form('frm690', 'uchazec')), $r690Json(['podminky' => ['do' => '2020-01-02']], $r690Form('frm690', 'uchazec'))),
    R690Build::shadowedApplicationForms($r690Published, $r690Json(['tridy' => ['skryte690']], $r690Form('frm690', 'uchazec'))),
    R690Build::shadowedApplicationForms($r690Published, $r690Json([], $r690Form('frm690', 'uchazec', false, ['tridy' => ['skryte690']]))),
    R690Build::shadowedApplicationForms($r690Json($r690Until + ['tridy' => ['karta690']], $r690Form('frm690', 'uchazec')), $r690Json($r690Until + ['tridy' => ['karta690']], $r690Form('frm690', 'uchazec'))),
    R690Build::shadowedApplicationForms($r690Json($r690Until, $r690Form('frm690', 'uchazec')), $r690Published),
    R690Build::shadowedApplicationForms($r690Published, $r690Json(['tridy' => ['karta690']], $r690Form('frm691', '')))],
    [['frm690'], ['frm690'], [], ['frm690'], ['frm690'], [], [], []]);

$r690Component = fn (string $id, int $idm): array => ['id' => $id, 'typ' => 'komponenta', 'obsah' => ['komponenta' => (string) $idm]];
check('3.50.1 review: newComponentReferences – a new komponenta element, or one pointing to another component, beside a published application form; none for the same reference or without an application form', [
    R690Build::newComponentReferences($r690Published, $r690Json([], $r690Form('frm690', 'uchazec'), $r690Component('kmp690', 7))),
    R690Build::newComponentReferences($r690Json([], $r690Form('frm690', 'uchazec'), $r690Component('kmp690', 7)), $r690Json([], $r690Form('frm690', 'uchazec'), $r690Component('kmp690', 8))),
    R690Build::newComponentReferences($r690Json([], $r690Form('frm690', 'uchazec'), $r690Component('kmp690', 7)), $r690Json([], $r690Form('frm690', 'uchazec'), $r690Component('kmp690', 7))),
    R690Build::newComponentReferences($r690Json([], $r690Form('frm691', '')), $r690Json([], $r690Form('frm691', ''), $r690Component('kmp690', 7))),
    R690Build::newComponentReferences(null, $r690Published)],
    [[7], [8], [], [], []]);
check('3.50.1 review: fileFormsAndComponents – a form with a file field that is not an application form counts, an application form with one does not; the components a build embeds', [
    R690Build::fileFormsAndComponents($r690Json([], $r690Form('frm692', '', true))),
    R690Build::fileFormsAndComponents($r690Json([], $r690Form('frm693', 'uchazec', true), $r690Component('kmp691', 9), $r690Component('kmp692', 9))),
    R690Build::fileFormsAndComponents(null)],
    [[true, []], [false, [9]], [false, []]]);

$r690Guard = function (?bool $allowed, string $draft) use ($r690Published): string {
    R690Build::$purposeAllowed = $allowed;
    try {
        R690Publisher::guard(['stavba' => $r690Published, 'stavba_koncept' => $draft]);

        return 'ok';
    } catch (\DomainException $e) {
        return Kaleta\Mcp\Translator::message($e->getMessage());
    } finally {
        R690Build::$purposeAllowed = null;
    }
};
check('3.50.1 review: Publisher::guard – without Applications a date condition on the application form is refused (in English for Claude); with the section it publishes', [
    $r690Guard(false, $r690Json($r690Until, $r690Form('frm690', 'uchazec'))), $r690Guard(true, $r690Json($r690Until, $r690Form('frm690', 'uchazec')))],
    ['Only a user who may open Applications can hide a job application form or add another form with a file field beside it – not published, the draft stays saved.', 'ok']);
check('3.50.1 review: the refusal of taking an application form off the site has its English message for Claude', Kaleta\Mcp\Translator::message(R690Publisher::APPLICATION_FORM_OFF_SITE_REFUSED),
    'Only a user who may open Applications can take a job application form off the site – nothing was changed.');

// Mail: the queue row is written, then the 1-in-50 clean-up of the log fails – the copy waits in the queue and is not sent at once
// (an SQLite connection put into Db, a trigger refuses every delete; the SMTP server cannot be reached, so a send would fail)
$r690Result = ['pdo_sqlite is not loaded – the check did not run'];
if (extension_loaded('pdo_sqlite')) {
    $r690Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $r690Pdo->exec('CREATE TABLE ka_posta (id INTEGER PRIMARY KEY AUTOINCREMENT, komu TEXT, predmet TEXT, vytvoreno TEXT, pokusu INTEGER, odeslano TEXT, chyba TEXT, telo TEXT, dalsi_pokus TEXT)');
    $r690Pdo->exec("INSERT INTO ka_posta (komu, predmet, vytvoreno, pokusu) VALUES ('old@example.com', 'Old', '2000-01-01 00:00:00', 1)");
    $r690Pdo->exec("CREATE TRIGGER r690_locked BEFORE DELETE ON ka_posta BEGIN SELECT RAISE(ABORT, 'lock wait timeout'); END");
    $r690Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    (new ReflectionProperty(Kaleta\Core\Db::class, 'pdo'))->setValue($r690Db, $r690Pdo);
    (new ReflectionProperty(Kaleta\Core\Db::class, 'english'))->setValue($r690Db, false);
    $r690Settings = new Kaleta\Core\Settings($r690Db);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($r690Settings, ['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '1',
        'smtp_encryption' => 'zadne', 'smtp_user' => '', 'mail_from' => 'web@example.com', 'site_name' => 'R690']);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'lazyRead'))->setValue($r690Settings, array_fill_keys((new ReflectionMethod(Kaleta\Core\Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));
    $r690Tried = 0;
    for ($r690I = 0; $r690I < 300; $r690I++) { // the clean-up runs 1 in 50 sends – 300 make a miss of every one about 1 in 400,000
        $r690Tried += Kaleta\Core\Mail::send($r690Settings, 'copy-r690@example.com', 'Copy R690', 'The second copy.', '', [], true, '', [], 0, false) || Kaleta\Core\Mail::$error !== '' ? 1 : 0;
    }
    Kaleta\Core\Mail::close();
    (new ReflectionProperty(Kaleta\Core\Mail::class, 'pending'))->setValue(null, false);
    $r690Result = [$r690Tried === 0, (int) $r690Pdo->query("SELECT COUNT(*) FROM ka_posta WHERE komu = 'copy-r690@example.com' AND odeslano IS NULL AND telo IS NOT NULL")->fetchColumn() - 300,
        (int) $r690Pdo->query("SELECT COUNT(*) FROM ka_posta WHERE pokusu > 0 AND komu = 'copy-r690@example.com'")->fetchColumn()];
}
check('3.50.1 review: Mail – with the clean-up of the log failing, a copy meant only for the queue stays queued (each one row, never tried at once)', $r690Result, [true, 0, 0]);
