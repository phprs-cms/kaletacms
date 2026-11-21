<?php

declare(strict_types=1);

/* ---------- 3.49 integration (A meets B): the Attachments block offers the internal files linked to the record ---------- */
use Kaleta\Admin\AttachmentBox as I449Box;
use Kaleta\Core\Language as I449Language;

// AttachmentBox::data takes the records the e-mail is about as a fourth, optional parameter – the earlier callers stay valid
$i449Params = array_map(fn (ReflectionParameter $p): string => $p->getName() . ($p->isOptional() ? '?' : ''), (new ReflectionMethod(I449Box::class, 'data'))->getParameters());
check('3.49 integration: AttachmentBox::data($app, $chosen, $reload, $records = []) – the records are optional', $i449Params, ['app', 'chosen', 'reload', 'records?']);

// linkedRows: from what InternalFileLinks::ofRecords gives (already the folders the person sees) only ready files, never an
// SVG, never one already ticked, each once (a file linked to the customer and the quote), with the note the ticked rows have
$i449File = fn (int $id, string $status = 'ready', string $ext = 'pdf'): array => ['id' => $id, 'name' => "f$id.$ext", 'status' => $status, 'extension' => $ext, 'size' => 2048, 'folder_id' => 1];
check('3.49 integration: linkedRows keeps ready, non-SVG files not already chosen, each once, with the size note', [
    I449Box::linkedRows([$i449File(1), $i449File(2, 'draft'), $i449File(3, 'ready', 'svg'), $i449File(4), $i449File(1), $i449File(5)], [4]),
    I449Box::linkedRows([], [1]), count(I449Box::linkedRows(array_map($i449File, range(1, 80)), []))],
    [[['id' => 1, 'name' => 'f1.pdf', 'note' => 'PDF · ' . Kaleta\Core\Files::size(2048), 'size' => 2048], ['id' => 5, 'name' => 'f5.pdf', 'note' => 'PDF · ' . Kaleta\Core\Files::size(2048), 'size' => 2048]],
        [], I449Box::RECENT]);

// the view: a linked file is a row of the same list – an unticked internal_files[] box with its size (only a person attaches,
// D6) and the label the caller gives; a ticked file stays ticked
$i449App = new Kaleta\Core\App([]);
$i449Render = fn (array $chosen, array $linked): string => I449Language::runWith('cs', fn (): string => $i449App->view->render('admin/attachments', ['box' => ['chosen' => $chosen, 'linked' => $linked,
    'canFiles' => false, 'recent' => [], 'searchUrl' => '', 'maxBytes' => 1000, 'maxFiles' => 5, 'reload' => []], 'prefix' => 'odpoved', 'reloadForm' => 'odpoved-nacist',
    'linkedLabel' => t('Linked to the customer'), 'confirm' => 'x']), 'admin-');
$i449Html = $i449Render([['id' => 7, 'name' => 'Ticked.pdf', 'note' => 'PDF · 2 kB', 'size' => 2048]], [['id' => 9, 'name' => 'Ceník <b>.pdf', 'note' => 'PDF · 1 kB', 'size' => 1024]]);
check('3.49 integration: the linked file is an unticked internal_files[] row with data-bytes, its name escaped and the label in the admin\'s language; the chosen one stays ticked', [
    str_contains($i449Html, '<input type="checkbox" name="internal_files[]" value="9" data-bytes="1024"> Ceník &lt;b&gt;.pdf</label> <small class="napoveda">PDF · 1 kB · Připojeno k zákazníkovi</small>'),
    str_contains($i449Html, 'value="7" data-bytes="2048" checked>'), substr_count($i449Html, 'name="internal_files[]"'),
    str_contains($i449Render([], []), 'name="internal_files[]"')],
    [true, true, 2, false]);
