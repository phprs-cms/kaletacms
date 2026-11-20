<?php

declare(strict_types=1);

use Kaleta\Builder\Check;
use Kaleta\Builder\Elements\Form;
use Kaleta\Core\Files;
use Kaleta\Core\Language;

/* ---------- 3.47 C: a body over post_max_size comes back to its form, the browser checks sizes first, the audit names a form the server cannot honour ---------- */
$c347Mb = 1048576;

check('3.47 C1: a body over post_max_size is recognised – above the limit only; equal is accepted; 0 = no limit', [
    Files::overPostLimit(8 * $c347Mb + 1, 8 * $c347Mb), Files::overPostLimit(8 * $c347Mb, 8 * $c347Mb), Files::overPostLimit(500, 8 * $c347Mb),
    Files::overPostLimit(99 * $c347Mb, 0), Files::postLimit() >= 0],
    [true, false, false, false, true]);

check('3.47 C1: the "size" message names the form\'s limit per file and the server\'s for the whole form (never a number from the address)', Language::runWith('en', fn (): array => [
    Form::messages('size', 2 * $c347Mb, 8 * $c347Mb), Form::messages('size', 3 * $c347Mb, 0)]),
    ['The files are too large, so nothing was sent. One file can have up to 2 MB, all files together up to 8 MB.',
        'The files are too large, so nothing was sent. One file can have up to 3 MB, all files together up to 3 MB.']);

check('3.47 C1: a form with a file field posts with its id, source and page in the address (English names, encoded); one without does not', [
    Form::action('/formular', true, 'f1', 'stranka:7', '/kontakt'), Form::action('/en/formular', true, 'a b', 'cast:paticka:en', '/en/o nás'), Form::action('/formular', false, 'f1', 'stranka:7', '/kontakt')],
    ['/formular?form=f1&source=stranka%3A7&back=%2Fkontakt', '/en/formular?form=a%20b&source=cast%3Apaticka%3Aen&back=%2Fen%2Fo%20n%C3%A1s', '/formular']);

$c347Limit = Form::effectiveLimit(['max_priloha' => 10], 2 * $c347Mb);
$c347Attributes = Language::runWith('en', fn (): string => Form::limitAttributes($c347Limit, 8 * $c347Mb));
check('3.47 C2: a form limit of 10 MB under a server limit of 2 MB gives 2 MB; the form carries the server\'s whole-body limit and both messages', [
    $c347Limit, Form::effectiveLimit(['max_priloha' => 10], 0), Form::ownLimit(['max_priloha' => 99]),
    str_contains($c347Attributes, ' data-max-post="8388608"'), str_contains($c347Attributes, 'data-file-too-large="The file is larger than 2 MB."'),
    str_contains($c347Attributes, 'data-files-too-large="The files are larger than 8 MB together."'), str_contains(Form::limitAttributes($c347Limit, 0), 'data-max-post')],
    [2 * $c347Mb, 10 * $c347Mb, 25 * $c347Mb, true, true, true, false]);

$c347Fields = new ReflectionMethod(Form::class, 'fields');
$c347File = ['typ' => 'soubor', 'popisek' => 'CV', 'povinne' => false];
$c347Html = Language::runWith('en', fn (): array => [$c347Fields->invoke(null, $c347File, 2, 'f9', false, '', $c347Limit), $c347Fields->invoke(null, $c347File, 2, 'f9', true, '', $c347Limit),
    $c347Fields->invoke(null, ['typ' => 'text', 'popisek' => 'Name', 'povinne' => false], 1, 'f9', true, '', $c347Limit)]);
check('3.47 C1/C2: each file field carries its limit for the browser; a refused file field says why, other fields keep their message', [
    str_contains($c347Html[0], 'type="file"') && str_contains($c347Html[0], ' data-max-bytes="2097152"'),
    str_contains($c347Html[1], 'This file cannot be sent: it is larger than 2 MB or of a type the form does not accept.'),
    str_contains($c347Html[2], 'Please fill in this field correctly.'), str_contains($c347Html[2], 'data-max-bytes')],
    [true, true, true, false]);

$c347Form = fn (string $id, int $mb, int $files): array => ['id' => $id, 'typ' => 'formular', 'obsah' => ['max_priloha' => $mb,
    'pole' => [['typ' => 'text', 'popisek' => 'Name'], ...array_fill(0, $files, ['typ' => 'soubor', 'popisek' => 'File'])]]];
$c347Build = fn (array ...$forms): array => ['v' => 1, 'deti' => [['id' => 's1', 'typ' => 'sekce', 'deti' => $forms]]];
$c347Ids = fn (array $findings): array => array_map(fn (array $f): string => (string) $f['id'], $findings);
check('3.47 C3: formLimits – nothing at or below the server\'s limits; a form above the per-file limit; the sum of its file fields above the whole-body limit; a form without a file field never', [
    Check::formLimits($c347Build($c347Form('a', 2, 1)), 2 * $c347Mb, 8 * $c347Mb), $c347Ids(Check::formLimits($c347Build($c347Form('b', 25, 1)), 2 * $c347Mb, 8 * $c347Mb)),
    $c347Ids(Check::formLimits($c347Build($c347Form('c', 10, 3)), 0, 8 * $c347Mb)), Check::formLimits($c347Build($c347Form('d', 25, 0)), 2 * $c347Mb, 8 * $c347Mb),
    Check::formLimits($c347Build($c347Form('e', 25, 3)), 0, 0)],
    [[], ['b'], ['c'], [], []]);
check('3.47 C3: the findings name the numbers of the form and the server', Language::runWith('en', fn (): array => array_column(Check::formLimits($c347Build($c347Form('f', 25, 5)), 2 * $c347Mb, 8 * $c347Mb), 'zprava')), [
    'The form allows attachments up to 25 MB, but this server accepts 2 MB per file – larger files are refused. Lower the limit, or ask the hosting to raise upload_max_filesize and post_max_size.',
    'The form has 5 file fields of up to 2 MB each, but this server accepts 8 MB for the whole form.']);
