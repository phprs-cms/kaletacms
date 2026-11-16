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

/* ---------- 3.41: the position of an application from the item page of a collection of the site's own ---------- */
$a341Jobs = [['idk' => 4, 'seo_link' => 'jobs']];
$a341Find = fn (int $idk): ?array => $idk === 7 ? ['idk' => 7, 'seo_link' => 'pozice'] : null;
check('3.41: an application form in the item template of any collection links the application to that item (not only a jobs-preset collection); other sources keep the jobs collections', [
    array_column(Applications::positionCollections($a341Jobs, 'kolekce:7', $a341Find), 'seo_link'),
    array_column(Applications::positionCollections([], 'kolekce:7', $a341Find), 'seo_link'),
    array_column(Applications::positionCollections($a341Jobs, 'kolekce:4', $a341Find), 'seo_link'),
    array_column(Applications::positionCollections($a341Jobs, 'kolekce:9', $a341Find), 'seo_link'),
    array_column(Applications::positionCollections($a341Jobs, 'stranka:27', $a341Find), 'seo_link'),
    array_column(Applications::positionCollections([], 'popup:2', $a341Find), 'seo_link')],
    [['jobs', 'pozice'], ['pozice'], ['jobs'], ['jobs'], ['jobs'], []]);
