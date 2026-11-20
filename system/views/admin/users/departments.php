<?php
/**
 * Users → Departments (3.42, Core\Departments): the tree of departments with the head and the number of people, and the
 * form for a new one or the one being edited (?department=<id>).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var list<array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>, depth: int}> $tree
 * @var array<int, int> $members department id => active people in it
 * @var array<int, string> $people active accounts, id => name
 * @var array{id: int, name: string, head_id: int|null, parent_id: int|null, enquiry_forms: list<string>} $editing
 * @var array<string, bool> $forms 3.47: the forms to tick (Users::departmentForms) => enquiries came from it
 */
$editingId = $editing['id'];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url(Kaleta\Admin\Account::path('colleagues') . '&view=departments')) ?>"><?= e(t('Org chart')) ?></a></p>
<p class="napoveda"><?= e(t('Departments group the people of the company. Each person gets a department and whom they report to in their account; everyone sees the result in My account → Org chart.')) ?></p>
<?php if ($tree === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('No departments yet.'), 'text' => t('Add the first one below, e.g. Sales or Production.'), 'action' => null]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Department')) ?></th><th scope="col"><?= e(t('Head')) ?></th><th scope="col"><?= e(t('People')) ?></th><th scope="col"><?= e(t('New enquiries from forms')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($tree as $d): ?>
<tr>
	<td><?= e(str_repeat('– ', $d['depth'])) ?><a href="<?= e($module->url('departments', ['department' => $d['id']])) ?>"><?= bdi($d['name']) ?></a></td>
	<td><?= $d['head_id'] !== null ? e(Kaleta\Core\Assignment::label($app->db(), $d['head_id'])) : '–' ?></td>
	<td class="cislo"><?= $members[$d['id']] ?? 0 ?></td>
	<td><?= $d['enquiry_forms'] !== [] ? e(implode(', ', $d['enquiry_forms'])) : '–' ?></td>
	<td class="akce"><a href="<?= e($module->url('departments', ['department' => $d['id']])) ?>"><?= e(t('Edit')) ?></a> / <form class="vradku" method="post" action="<?= e($module->url('department_delete')) ?>" data-potvrdit="<?= e(t('Remove the department? Its people will belong to no department; departments under it move one level up.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Remove')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<h2><?= e($editingId > 0 ? t('Edit the department') : t('New department')) ?></h2>
<form class="formular" method="post" action="<?= e($module->url('department_save')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= $editingId ?>">
<div class="radek"><label for="department-name"><?= e(t('Name')) ?></label><input class="textpole siroke" type="text" id="department-name" name="name" value="<?= e($editing['name']) ?>" maxlength="<?= Kaleta\Core\Departments::NAME ?>" required></div>
<div class="radek"><label for="department-head"><?= e(t('Head')) ?></label><select id="department-head" name="head_id">
	<option value=""><?= e(t('nobody')) ?></option>
<?php foreach ($people as $uid => $name): ?>
	<option value="<?= $uid ?>"<?= $editing['head_id'] === $uid ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
</select></div>
<div class="radek"><label for="department-parent"><?= e(t('Part of')) ?></label><select id="department-parent" name="parent_id">
	<option value=""><?= e(t('nothing – a top department')) ?></option>
<?php foreach ($tree as $d): if ($d['id'] === $editingId) { continue; } ?>
	<option value="<?= $d['id'] ?>"<?= $editing['parent_id'] === $d['id'] ? ' selected' : '' ?>><?= e(str_repeat('– ', $d['depth']) . $d['name']) ?></option>
<?php endforeach ?>
</select></div>
<?php $ticked = array_fill_keys(array_map('mb_strtolower', $editing['enquiry_forms']), true); // 3.47 (U67): forms as boxes to tick ?>
<div class="radek"><span class="popisek" id="department-forms"><?= e(t('New enquiries from forms')) ?></span><div>
<?php if ($forms === []): ?>
	<span class="napoveda"><?= e(t('No forms on the site yet.')) ?></span>
<?php else: ?>
	<div class="volby volby-pod-sebou" role="group" aria-labelledby="department-forms">
<?php foreach ($forms as $form => $sent): ?>
		<label><input type="checkbox" name="enquiry_forms[]" value="<?= e((string) $form) ?>"<?= isset($ticked[mb_strtolower((string) $form)]) ? ' checked' : '' ?>> <?= bdi((string) $form) ?><?= $sent ? '' : ' <span class="smltxt">' . e(t('(no enquiries yet)')) . '</span>' ?></label>
<?php endforeach ?>
	</div>
<?php endif ?>
	<span class="napoveda"><?= e(t('A new enquiry from a ticked form is handled by the head – while the head is away, by their deputy. Tick none to assign enquiries by hand.')) ?></span></div></div>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Uložit')) ?></button><?php if ($editingId > 0): ?> <a class="navigace" href="<?= e($module->url('departments')) ?>"><?= e(t('Cancel')) ?></a><?php endif ?></p>
</form>
