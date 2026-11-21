<?php

declare(strict_types=1);

/* ---------- 3.50.1 A3b–c: with Build::$purposeAllowed unset the lock follows the person signed in; removal is guarded too (D3) ---------- */
use Kaleta\Builder\Build as A605Build;
use Kaleta\Builder\Publisher as A605Publisher;

// an App whose Auth has a person (no database: the user row and the sections are set as Auth caches them)
$a605App = function (?array $user, array $modules): Kaleta\Core\App {
    $auth = (new ReflectionClass(Kaleta\Core\Auth::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Auth::class, 'user'))->setValue($auth, $user);
    (new ReflectionProperty(Kaleta\Core\Auth::class, 'modules'))->setValue($auth, $modules);
    $app = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\App::class, 'auth'))->setValue($app, $auth);

    return $app;
};
$a605Build = (string) json_encode(['v' => 1, 'deti' => [['id' => 'sek605', 'typ' => 'sekce', 'deti' => [['id' => 'frm605', 'typ' => 'formular', 'obsah' => ['nazev' => 'Apply', 'ucel' => 'uchazec', 'pole' => [['typ' => 'text', 'popisek' => 'Name']]]]]]]]);
$a605Plain = (string) json_encode(['v' => 1, 'deti' => [['id' => 'sek606', 'typ' => 'sekce', 'deti' => []]]]);
$a605Editor = ['idu' => 605, 'admin' => 1, 'jmeno' => 'Editor 605'];
$a605Try = function (callable $call): string {
    A605Build::$purposeAllowed = null;
    try {
        $call();

        return 'ok';
    } catch (\DomainException $e) {
        return Kaleta\Mcp\Translator::message($e->getMessage());
    }
};
check('3.50.1 A3b: guard with $purposeAllowed null – a signed-in editor without Applications cannot drop the form; with the section, or with nobody signed in (an import, the CLI), it publishes', [
    $a605Try(fn () => A605Publisher::guard(['stavba' => $a605Build, 'stavba_koncept' => $a605Plain], $a605App($a605Editor, []))),
    $a605Try(fn () => A605Publisher::guard(['stavba' => $a605Build, 'stavba_koncept' => $a605Plain], $a605App($a605Editor, ['applications']))),
    $a605Try(fn () => A605Publisher::guard(['stavba' => $a605Build, 'stavba_koncept' => $a605Plain], $a605App(null, []))),
    $a605Try(fn () => A605Publisher::guard(['stavba' => $a605Build, 'stavba_koncept' => $a605Plain]))],
    ['Only a user who may open Applications can remove or replace a job application form – not published, the draft stays saved.', 'ok', 'ok', 'ok']);
check('3.50.1 A3c: guardRemoval – deleting what holds an application form is refused for an editor without Applications; allowed with the section or for a build without one (or no build)', [
    $a605Try(fn () => A605Publisher::guardRemoval($a605Build, $a605App($a605Editor, []))),
    $a605Try(fn () => A605Publisher::guardRemoval($a605Build, $a605App($a605Editor, ['applications']))),
    $a605Try(fn () => A605Publisher::guardRemoval($a605Plain, $a605App($a605Editor, []))),
    $a605Try(fn () => A605Publisher::guardRemoval(false, $a605App($a605Editor, []))),
    $a605Try(fn () => A605Publisher::guardRemoval($a605Build, $a605App(null, [])))],
    ['Only a user who may open Applications can remove a job application form – nothing was deleted.', 'ok', 'ok', 'ok', 'ok']);
