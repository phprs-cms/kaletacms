<?php
/**
 * Name and properties of a component.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Components $module
 * @var string $csrf
 * @var array<string, mixed> $k
 */
use Kaleta\Builder\Components;

$properties = array_merge($k['vlastnosti'], array_fill(0, 3, ['klic' => '', 'popisek' => '', 'typ' => 'text', 'vychozi' => '']));
?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="idm" value="<?= (int) $k['idm'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Component name')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($k['nazev']) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Service card')) ?>"></div></div>
<fieldset>
<legend><?= e(t('Properties')) ?></legend>
<p class="napoveda"><?= e(t('What can differ between uses of the component – heading, text, image, link. In the builder you insert them into the component with a {{key}} tag, then fill in a value for each use (empty = default).')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Label')) ?></th><th scope="col"><?= e(t('Type')) ?></th><th scope="col"><?= e(t('Default value')) ?></th><th scope="col"><?= e(t('Značka')) ?></th></tr></thead>
<tbody>
<?php foreach ($properties as $i => $v): ?>
<tr>
	<td><input class="textpole" name="vlastnosti[<?= $i ?>][popisek]" value="<?= e($v['popisek']) ?>" maxlength="80" aria-label="<?= e(t('Label')) ?>"><input type="hidden" name="vlastnosti[<?= $i ?>][klic]" value="<?= e($v['klic']) ?>"></td>
	<td><select name="vlastnosti[<?= $i ?>][typ]" aria-label="<?= e(t('Type')) ?>">
<?php foreach (Components::TYPES as $type => $name): ?>
		<option value="<?= e($type) ?>"<?= $v['typ'] === $type ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></td>
	<td><input class="textpole" name="vlastnosti[<?= $i ?>][vychozi]" value="<?= e($v['vychozi']) ?>" maxlength="500" aria-label="<?= e(t('Default value')) ?>"></td>
	<td><?= $v['klic'] !== '' ? '<code>{{' . e($v['klic']) . '}}</code>' : '<span class="napoveda">' . e(t('created from the label')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save component')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
