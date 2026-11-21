<?php
/**
 * The internal files of a customer, a company, a deal or a quote (3.49 B, Admin\Modules\InternalFiles::box) in the side
 * column of the record's page: the files linked to it that the viewer can open (name, folder, date, a download), Unlink per
 * row, and "Link a file" – a choice by search (views/admin/pick.php, data-pick="files"; without script the most recently
 * changed ready files). Without a linked file only the closed "Link a file", never an empty box on every record. Only for
 * people with Internal files; files of folders not for the viewer are neither listed nor counted. No upload here: a new
 * file is uploaded in Internal files, where its folder (and so who sees it) is chosen knowingly.
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array{kind: string, id: int, files: list<array<string, mixed>>, choices: array<int, string>, linkUrl: string, unlinkUrl: string, searchUrl: string, fileUrl: string, downloadUrl: string} $box
 */
$hidden = $csrf . '<input type="hidden" name="kind" value="' . e($box['kind']) . '"><input type="hidden" name="record_id" value="' . (int) $box['id'] . '">';
$field = 'interni-soubor-' . $box['kind'] . '-' . (int) $box['id'];
?>
<section class="zakaznik-box" id="internal-files" data-internal-files-box="<?= e($box['kind']) ?>">
<?php if ($box['files'] !== []): ?>
<h2><?= e(t('Internal files')) ?> <small><?= format_count(count($box['files'])) ?></small></h2>
<ul class="obchody-box-seznam">
<?php foreach ($box['files'] as $f): ?>
	<li data-internal-file-row="<?= (int) $f['id'] ?>"><a href="<?= e($box['fileUrl'] . (int) $f['id']) ?>"><?= bdi((string) $f['name']) ?></a>
		<span class="napoveda"><?= e((string) $f['folder_name']) ?> · <?= e(format_date((string) $f['updated_at'])) ?><?= $f['status'] === 'draft' ? ' · ' . e(t('Draft')) : '' ?></span>
		<a href="<?= e($box['downloadUrl'] . (int) $f['id']) ?>"><?= e(t('Download')) ?></a>
		<form class="vradku" method="post" action="<?= e($box['unlinkUrl']) ?>"><?= $hidden ?><input type="hidden" name="file_id" value="<?= (int) $f['id'] ?>"><button class="navigace" type="submit"><?= e(t('Unlink')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<details class="zakaznik-upravit" data-internal-link><summary><?= e($box['files'] === [] ? t('Internal files') . ': ' . t('Link a file') : t('Link a file')) ?></summary>
<form class="formular" method="post" action="<?= e($box['linkUrl']) ?>"><?= $hidden ?>
	<div class="radek"><label for="<?= e($field) ?>"><?= e(t('File')) ?></label><div>
	<?= $app->view->render('admin/pick', ['id' => $field, 'name' => 'file_id', 'pick' => 'files', 'url' => $box['searchUrl'], 'options' => $box['choices'], 'selected' => '',
        'specials' => [['', '—']], 'attributes' => ['required' => 'required']]) ?>
	<span class="napoveda"><?= e(t('A file of Internal files; who may open it is decided by its folder.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Link a file')) ?></button></p>
</form>
</details>
</section>
