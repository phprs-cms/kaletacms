<?php
/**
 * The people who take bookings (3.0) and the days off of everyone.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var list<array<string, mixed>> $staff
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $offs days off still to come (everyone's and personal)
 * @var int $unlinked 3.49 B: active people not linked to an account (an absence in an account closes only linked people's times)
 */
$names = array_column($services, 'name', 'id');
$staffNames = array_column($staff, 'name', 'id');
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a> <a class="navigace" href="<?= e($module->url('services')) ?>"><?= e(t('Services')) ?></a></p>
<p><a class="tl" href="<?= e($module->url('staff_edit')) ?>"><?= e(t('New person')) ?></a></p>
<?php if ($staff === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'rezervace', 'heading' => t('Nobody takes bookings yet.'), 'text' => t('Add the people (or just one entry for the whole business) with their weekly hours. Then add the services and tick who offers each.'), 'action' => [$module->url('staff_edit'), t('New person')]]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Person')) ?></th><th scope="col"><?= e(t('E-mail for notifications')) ?></th><th scope="col"><?= e(t('Services')) ?></th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($staff as $m): ?>
<tr<?= $m['active'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($module->url('staff_edit', ['id' => $m['id']])) ?>"><strong><?= e($m['name']) ?></strong></a><?= $m['active'] ? '' : ' <span class="smltxt">(' . e(t('switched off')) . ')</span>' ?></td>
	<td><?= $m['email'] !== '' ? e($m['email']) : '<span class="smltxt">' . e(t('the site e-mail')) . '</span>' ?></td>
	<td><?= $m['services'] === [] ? '<span class="smltxt">' . e(t('none yet')) . '</span>' : e(implode(', ', array_map(fn (int $id): string => (string) ($names[$id] ?? '#' . $id), $m['services']))) ?></td>
	<td class="stred"><form method="post" action="<?= e($module->url('staff_delete')) ?>" data-potvrdit="<?= e(t('Remove the person?')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Remove')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php if ($unlinked > 0): /* 3.49 B: one absence – what makes the rule work on a site from before 3.49 */ ?>
<form class="vradku" method="post" action="<?= e($module->url('staff_link')) ?>" data-staff-link><?= $csrf ?>
	<span class="napoveda"><?= e(t('%d people are not linked to an account – an absence in their account does not close their times.', $unlinked)) ?></span>
	<button class="navigace" type="submit"><?= e(t('Link by e-mail')) ?></button></form>
<?php endif ?>
<?php endif ?>
<h2><?= e(t('Days off')) ?></h2>
<p class="smltxt"><?= e(t('Holidays and closed days of the whole business. A closed day in the opening hours exceptions (Business details) counts as a day off for everyone too; a person has their own days off on their form.')) ?></p>
<?php if ($offs !== []): ?>
<ul>
<?php foreach ($offs as $o): ?>
<li><?= e(format_date($o['from'], true)) ?> – <?= e(format_date($o['to'], true)) ?>: <?= e($o['staff_id'] === null ? t('everyone') : (string) ($staffNames[$o['staff_id']] ?? '#' . $o['staff_id'])) ?><?= $o['note'] !== '' ? ' (' . e($o['note']) . ')' : '' ?>
<form class="vradku" method="post" action="<?= e($module->url('off_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button class="navigace" type="submit"><?= e(t('Remove')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('off_save')) ?>">
<?= $csrf ?><input type="hidden" name="staff_id" value="0">
<div class="radek"><label for="off_from"><?= e(t('Everyone off from')) ?></label><div><input class="textpole" type="date" id="off_from" name="off_from" required> <label><?= e(t('to')) ?> <input class="textpole" type="date" name="off_to"></label> <label><?= e(t('Note')) ?> <input class="textpole" name="note" maxlength="150"></label> <input class="tl" type="submit" value="<?= e(t('Add')) ?>"></div></div>
</form>
