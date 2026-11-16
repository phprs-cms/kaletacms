<?php

declare(strict_types=1);

use Kaleta\Builder\Elements\Form;
use Kaleta\Core\Applications;

/* ---------- 3.41: a job application form offers only the CV types the server keeps ---------- */
$a341Cs = require KALETA_SYSTEM . '/jazyky/cs.php';
check('3.41: the file fields of a job application form offer only the CV types (no ZIP, spreadsheet or drawing the server would refuse after sending); other forms keep the attachment types', [
    Form::fileExtensions(true) === Applications::CV_EXTENSIONS, Form::fileExtensions(false) === Form::ATTACHMENT_EXTENSIONS,
    array_values(array_intersect(['zip', 'xls', 'xlsx', 'dwg', 'dxf', 'heic', 'webp'], Form::fileExtensions(true))),
    // the hint under the field names what is accepted – never "or ZIP" on an application form
    isset($a341Cs['Up to %d MB: PDF, Word or OpenDocument document, text or image (JPG, PNG).'])],
    [true, true, [], true]);
