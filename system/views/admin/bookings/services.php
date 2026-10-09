<?php
/**
 * Bookable services (3.0): the list and the form of one.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $staff
 * @var array<string, mixed>|null $edit the service being edited ([] = a new one, null = none)
 */
$names = array_column($staff, 'name', 'id');
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a> <a class="navigace" href="<?= e($module->url('staff')) ?>"><?= e(t('People')) ?></a></p>
<p><a class="tl" href="<?= e($module->url('services', ['new' => 1])) ?>"><?= e(t('New service')) ?></a></p>
<?php if ($services === [] && $staff === []): // the set-up order (3.5): a person first, then the service they offer ?>
<?= $app->view->render('admin/empty', ['icon' => 'rezervace', 'heading' => t('Add a person first'), 'text' => t('A service needs someone who offers it. Add the person (or one entry for the whole business) with their weekly hours, then the service.'), 'action' => [$module->url('staff_edit'), t('New person')]]) ?>
<?php elseif ($services === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'rezervace', 'heading' => t('Add a service'), 'text' => t('No services yet. A service is what the visitor books: a haircut, a consultation, a tyre change – with how long it takes.'), 'action' => [$module->url('services', ['new' => 1]), t('New service')]]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Service')) ?></th><th scope="col"><?= e(t('Duration')) ?></th><th scope="col"><?= e(t('Buffer')) ?></th><th scope="col"><?= e(t('Price')) ?></th><th scope="col"><?= e(t('Offered by')) ?></th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($services as $s): ?>
<tr<?= $s['active'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($module->url('services', ['id' => $s['id']])) ?>"><strong><?= e($s['name']) ?></strong></a><?= $s['active'] ? '' : ' <span class="smltxt">(' . e(t('switched off')) . ')</span>' ?><?= $s['requires_confirmation'] ? ' <span class="smltxt">(' . e(t('requires confirmation')) . ')</span>' : '' ?><?= $s['description'] !== '' ? '<br><span class="smltxt">' . e($s['description']) . '</span>' : '' ?></td>
	<td><?= e(t('%d min', $s['duration_min'])) ?></td>
	<td><?= $s['buffer_min'] > 0 ? e(t('%d min', $s['buffer_min'])) : '—' ?></td>
	<td><?= e($s['price_text']) ?></td>
	<td><?= $s['staff'] === [] ? '<span class="smltxt">' . e(t('nobody yet')) . '</span>' : e(implode(', ', array_map(fn (int $id): string => (string) ($names[$id] ?? '#' . $id), $s['staff']))) ?></td>
	<td class="stred"><form method="post" action="<?= e($module->url('service_delete')) ?>" data-potvrdit="<?= e(t('Delete the service?')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php if ($edit !== null): ?>
<h2><?= e($edit === [] ? t('New service') : (string) $edit['name']) ?></h2>
<form class="formular" method="post" action="<?= e($module->url('service_save')) ?>">
<?= $csrf ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
<div class="radek"><label for="name"><?= e(t('Name')) ?></label><div><input class="textpole siroke" id="name" name="name" required maxlength="150" value="<?= e((string) ($edit['name'] ?? '')) ?>" placeholder="<?= e(t('e.g. Haircut')) ?>"></div></div>
<div class="radek"><label for="duration_min"><?= e(t('Duration')) ?></label><div><input class="textpole kratke" type="number" id="duration_min" name="duration_min" min="5" max="480" step="5" required value="<?= (int) ($edit['duration_min'] ?? 30) ?>"> <?= e(t('minutes')) ?>
<span class="napoveda"><?= e(t('The offered times step by the duration (up to an hour; longer services every 15 minutes).')) ?></span></div></div>
<div class="radek"><label for="buffer_min"><?= e(t('Buffer')) ?></label><div><input class="textpole kratke" type="number" id="buffer_min" name="buffer_min" min="0" max="240" step="5" value="<?= (int) ($edit['buffer_min'] ?? 0) ?>"> <?= e(t('minutes kept free after the appointment')) ?></div></div>
<div class="radek"><label for="price_text"><?= e(t('Price')) ?></label><div><input class="textpole" id="price_text" name="price_text" maxlength="60" value="<?= e((string) ($edit['price_text'] ?? '')) ?>" placeholder="<?= e(t('e.g. from 450 CZK')) ?>"><span class="napoveda"><?= e(t('Shown as written. No payments – the customer pays on the spot.')) ?></span></div></div>
<div class="radek"><label for="description"><?= e(t('Description')) ?></label><div><input class="textpole siroke" id="description" name="description" maxlength="500" value="<?= e((string) ($edit['description'] ?? '')) ?>"></div></div>
<div class="radek"><span><?= e(t('Offered by')) ?></span><div>
<?php if ($staff === []): ?><span class="napoveda"><?= e(t('Nobody can offer it yet –')) ?> <a href="<?= e($module->url('staff_edit')) ?>"><?= e(t('add a person first')) ?></a>.</span><?php endif ?>
<?php foreach ($staff as $m): ?><label class="vradku"><input type="checkbox" name="staff[]" value="<?= (int) $m['id'] ?>"<?= in_array($m['id'], $edit['staff'] ?? [], true) ? ' checked' : '' ?>> <?= e($m['name']) ?></label> <?php endforeach ?>
</div></div>
<div class="radek"><label for="sort_order"><?= e(t('Order')) ?></label><div><input class="textpole kratke" type="number" id="sort_order" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>"></div></div>
<div class="radek"><span></span><div><label><input type="checkbox" name="requires_confirmation" value="1"<?= !empty($edit['requires_confirmation']) ? ' checked' : '' ?>> <?= e(t('Requires confirmation')) ?></label>
<span class="napoveda"><?= e(t('A request, not a booking: the time is held, you accept it, decline it or propose other times. The customer is told by e-mail.')) ?></span></div></div>
<div class="radek"><span></span><div><label><input type="checkbox" name="active" value="1"<?= ($edit['active'] ?? true) ? ' checked' : '' ?>> <?= e(t('Offered to visitors')) ?></label></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<?php endif ?>
