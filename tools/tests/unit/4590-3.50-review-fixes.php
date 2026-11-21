<?php

declare(strict_types=1);

/* ---------- 3.50 review: the hidden copy of an application form is locked; a page progressing between killed batches is not skipped; an off-site file in a removed folder counts as gone ---------- */
use Kaleta\Builder\Build as R590Build;
use Kaleta\Core\RemoteBackup as R590Remote;
use Kaleta\Core\WebImport as R590Import;

$r590Form = fn (string $id, string $purpose, string $to, string $bcc): array => ['id' => $id, 'typ' => 'formular', 'obsah' => ['nazev' => 'Apply', 'pole' => [['typ' => 'text', 'popisek' => 'Name']]]
    + ($purpose !== '' ? ['ucel' => $purpose] : []) + ($to !== '' ? ['prijemce' => $to] : []) + ($bcc !== '' ? ['skryta_kopie' => $bcc] : [])];
$r590Previous = ['v' => 1, 'deti' => [$r590Form('frm001', 'uchazec', 'hr@example.com', 'boss@example.com'), $r590Form('frm002', 'uchazec', 'hr@example.com', ''), $r590Form('frm003', '', 'office@example.com', '')]];
$r590Input = ['v' => 1, 'deti' => [$r590Form('frm001', 'uchazec', 'hr@example.com', 'me@evil.example'), $r590Form('frm002', 'uchazec', 'hr@example.com', 'me@evil.example'), $r590Form('frm003', '', 'office@example.com', 'me@example.org')]];
$r590Bcc = fn (array $build): array => array_map(fn (array $p): string => (string) ($p['obsah']['skryta_kopie'] ?? ''), $build['deti']);
R590Build::$purposeAllowed = false;
[$r590Locked, $r590Errors] = R590Build::sanitize($r590Input, false, $r590Previous);
R590Build::$purposeAllowed = true;
[$r590Open] = R590Build::sanitize($r590Input, false, $r590Previous);
R590Build::$purposeAllowed = null;
check('3.50 review (SECURITY-1): without Applications the hidden copy (Bcc) of an application form stays as it was – changed or newly added – with the recipients\' notice; an enquiry form\'s Bcc changes freely; with the section it changes', [
    $r590Bcc($r590Locked), array_keys(array_filter($r590Errors, fn (string $v, string $k): bool => str_contains($k, '.obsah.'), ARRAY_FILTER_USE_BOTH)),
    Kaleta\Mcp\Translator::message((string) ($r590Errors['deti[0].obsah.skryta_kopie'] ?? '')), $r590Bcc($r590Open)],
    [['boss@example.com', '', 'me@example.org'], ['deti[0].obsah.skryta_kopie', 'deti[1].obsah.skryta_kopie'],
        'Only a user who may open Applications can change the recipients of a job application form – kept as they were.', ['me@evil.example', 'me@evil.example', 'me@example.org']]);

check('3.50 review (CORRECTNESS-1): attemptsUsed – another address or no marker 0; a batch that died without new downloads counts; one that mapped new downloads (postup past postup_od) does not', [
    R590Import::attemptsUsed([], 'https://old.example/a/'),
    R590Import::attemptsUsed(['adresa' => 'https://old.example/b/', 'pokusy' => 2], 'https://old.example/a/'),
    R590Import::attemptsUsed(['adresa' => 'https://old.example/a/', 'pokusy' => 1, 'postup' => 0, 'postup_od' => 0], 'https://old.example/a/'),
    R590Import::attemptsUsed(['adresa' => 'https://old.example/a/', 'pokusy' => 2, 'postup' => 5, 'postup_od' => 5], 'https://old.example/a/'),
    R590Import::attemptsUsed(['adresa' => 'https://old.example/a/', 'pokusy' => 2, 'postup' => 7, 'postup_od' => 5], 'https://old.example/a/'),
    R590Import::attemptsUsed(['adresa' => 'https://old.example/a/', 'pokusy' => 1], 'https://old.example/a/')],
    [0, 0, 1, 2, 0, 1]);

// a fake target: the folders it has and their names; a folder that is not there (or one it cannot read) lists as false
$r590Target = fn (array $folders): Closure => fn (string $dir): array|false => $folders[$dir] ?? false;
check('3.50 review (CORRECTNESS-2): goneFrom – a listing of the folder that lacks the file; a removed folder whose parent (or grandparent) listing lacks it; a folder that is there but cannot be read, or listings failing up to the backup folder, keep the entry', [
    R590Remote::goneFrom($r590Target(['private/x/2025' => ['a.bin']]), 'private/x/2025/b.bin'),
    R590Remote::goneFrom($r590Target(['private/x/2025' => ['b.bin']]), 'private/x/2025/b.bin'),
    R590Remote::goneFrom($r590Target(['private/x' => ['2024']]), 'private/x/2025/b.bin'),
    R590Remote::goneFrom($r590Target(['private' => ['y'], '.' => ['private']]), 'private/x/2025/b.bin'),
    R590Remote::goneFrom($r590Target(['private/x' => ['2025']]), 'private/x/2025/b.bin'),
    R590Remote::goneFrom($r590Target([]), 'private/x/2025/b.bin'),
    R590Remote::goneFrom($r590Target(['.' => ['kaleta-1.zip']]), 'kaleta-2.zip')],
    [true, false, true, true, false, false, true]);
check('3.50 review (CORRECTNESS-2): deleteOrder – entries that failed DELETE_TRIES times go after the others, each group in its order', R590Remote::deleteOrder(
    ['a', 'b', 'c', 'd', 'e'], ['a' => R590Remote::DELETE_TRIES, 'b' => 1, 'd' => R590Remote::DELETE_TRIES + 2]), ['b', 'c', 'e', 'a', 'd']);
