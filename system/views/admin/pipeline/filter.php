<?php
/**
 * The filter of the board and the list (3.31): a word, the responsible person (mine, nobody, a person), the company, the
 * customer it was opened for (a chip that clears it) and – in the list – the status.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var array{owner: string, contact: int, company: int, search: string, status: string, view: string} $filter
 * @var array<int, string> $people
 * @var list<array<string, mixed>> $companies
 * @var string $contactName
 * @var string $companyName
 * @var bool $canContacts
 * @var int $me
 */
use Kaleta\Admin\Modules\Pipeline;

$filtered = $filter['search'] !== '' || $filter['owner'] !== '' || $filter['company'] > 0 || $filter['contact'] > 0 || ($filter['view'] === 'list' && $filter['status'] !== 'open');
$clear = $filter['view'] === 'list' ? $module->url('', ['view' => 'list']) : $module->url();
?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-pipeline-filter>
	<input type="hidden" name="module" value="pipeline">
<?php if ($filter['view'] === 'list'): ?>
	<input type="hidden" name="view" value="list">
<?php endif ?>
<?php if ($filter['contact'] > 0): ?>
	<input type="hidden" name="contact" value="<?= $filter['contact'] ?>">
<?php endif ?>
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Title, customer or company')) ?>"></label>
	<label><span><?= e(t('Responsible person')) ?></span> <select name="owner">
		<option value=""><?= e(t('Anyone')) ?></option>
		<option value="mine"<?= $filter['owner'] === 'mine' ? ' selected' : '' ?>><?= e(t('Mine')) ?></option>
		<option value="none"<?= $filter['owner'] === 'none' ? ' selected' : '' ?>><?= e(t('Nobody')) ?></option>
<?php foreach ($people as $id => $name): if ((int) $id === $me) { continue; } ?>
		<option value="<?= (int) $id ?>"<?= $filter['owner'] === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php if ($canContacts && ($companies !== [] || $filter['company'] > 0)): ?>
	<label><span><?= e(t('Company')) ?></span> <select name="company">
		<option value=""><?= e(t('Any')) ?></option>
<?php if ($filter['company'] > 0 && !in_array($filter['company'], array_column($companies, 'id'), true)): ?>
		<option value="<?= $filter['company'] ?>" selected><?= e($companyName) ?></option>
<?php endif ?>
<?php foreach ($companies as $co): ?>
		<option value="<?= (int) $co['id'] ?>"<?= $filter['company'] === $co['id'] ? ' selected' : '' ?>><?= e($co['name']) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
<?php if ($filter['view'] === 'list'): ?>
	<label><span><?= e(t('Status')) ?></span> <select name="status">
<?php foreach (Pipeline::STATUS_LABELS as $key => $label): ?>
		<option value="<?= e($key) ?>"<?= $filter['status'] === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<p class="zakaznici-filtr-tlacitka"><button class="tl" type="submit"><?= e(t('Filtrovat')) ?></button><?php if ($filtered): ?> <a class="navigace" href="<?= e($clear) ?>"><?= e(t('Clear filter')) ?></a><?php endif ?></p>
</form>
<?php if ($filter['contact'] > 0): ?>
<p class="obchody-zuzeni" data-pipeline-contact="<?= $filter['contact'] ?>"><?= e(t('Deals of the customer %s', $contactName)) ?> · <a href="<?= e($module->url('', array_diff_key(Pipeline::query($filter), ['contact' => 1]))) ?>"><?= e(t('Show everyone’s')) ?></a></p>
<?php endif ?>
