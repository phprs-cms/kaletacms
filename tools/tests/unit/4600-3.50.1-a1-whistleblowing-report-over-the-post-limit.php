<?php

declare(strict_types=1);

/* ---------- 3.50.1 A1: the whistleblowing report form tells the browser and the reporter the real limits (UX-c1) ---------- */
use Kaleta\Core\Language as A600Language;
use Kaleta\Front\Whistleblowing as A600Report;

$a600Language = A600Language::code();
A600Language::set('en');
check('3.50.1 A1: the hint under the file field says the real total when the server accepts less than 5 × 10 MB in one body (8 MB), and the plain limits with no limit (0) or a large one', [
    A600Report::attachmentHint(8 * 1048576), A600Report::attachmentHint(0), A600Report::attachmentHint(64 * 1048576)],
    ['Up to 5 files, each up to 10 MB and together up to 8 MB: PDF, image, document or ZIP.',
        'Up to 5 files, each up to 10 MB: PDF, image, document or ZIP.', 'Up to 5 files, each up to 10 MB: PDF, image, document or ZIP.']);
$a600With = A600Report::limitAttributes(8 * 1048576);
$a600Without = A600Report::limitAttributes(0);
check('3.50.1 A1: the report form carries the limits for the browser – the body limit and both messages with 8 MB, only the one-file message with no limit; never data-formular (D4)', [
    str_contains($a600With, 'data-max-post="8388608"'), str_contains($a600With, 'data-file-too-large="The file is larger than 10 MB."'), str_contains($a600With, 'data-files-too-large="The files are larger than 8 MB together."'),
    str_contains($a600Without, 'data-max-post'), str_contains($a600Without, 'data-file-too-large="The file is larger than 10 MB."'), str_contains($a600Without, 'data-files-too-large'),
    str_contains($a600With . $a600Without, 'data-formular')],
    [true, true, true, false, true, false, false]);
A600Language::set($a600Language);
