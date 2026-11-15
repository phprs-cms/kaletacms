<?php
/**
 * A manual booking (3.0): a customer who phoned or walked in. The same rules as on the site except the lead time.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $staff
 * @var array<string, mixed> $old what was typed before an error
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<fieldset><legend><?= e(t('Appointment')) ?></legend>
<div class="radek"><label for="sluzba"><?= e(t('Service')) ?></label><div><select id="sluzba" name="sluzba" required><option value=""><?= e(t('— choose —')) ?></option>
<?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) ($old['sluzba'] ?? 0) === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?> (<?= e(t('%d min', $s['duration_min'])) ?>)</option><?php endforeach ?>
</select></div></div>
<div class="radek"><label for="osoba"><?= e(t('Person')) ?></label><div><select id="osoba" name="osoba"><option value="0"><?= e(t('Anyone available')) ?></option>
<?php foreach ($staff as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (int) ($old['osoba'] ?? 0) === $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach ?>
</select></div></div>
<div class="radek"><label for="den"><?= e(t('Day and time')) ?></label><div><input class="textpole" type="date" id="den" name="den" required value="<?= e((string) ($old['den'] ?? date('Y-m-d'))) ?>"> <input class="textpole kratke" type="time" name="cas" required step="300" value="<?= e((string) ($old['cas'] ?? '')) ?>" aria-label="<?= e(t('Time')) ?>">
<span class="napoveda"><?= e(t('The time must be free for the person within their hours; the lead time for visitors does not apply here.')) ?></span></div></div>
</fieldset>
<fieldset><legend><?= e(t('Customer')) ?></legend>
<div class="radek"><label for="jmeno"><?= e(t('Name')) ?></label><div><input class="textpole siroke" id="jmeno" name="jmeno" required maxlength="150" value="<?= e((string) ($old['jmeno'] ?? '')) ?>"></div></div>
<div class="radek"><label for="email"><?= e(t('Email')) ?></label><div><input class="textpole siroke" type="email" id="email" name="email" maxlength="190" value="<?= e((string) ($old['email'] ?? '')) ?>"><span class="napoveda"><?= e(t('With an e-mail the customer gets the confirmation, the cancel link and the reminder.')) ?></span></div></div>
<div class="radek"><label for="telefon"><?= e(t('Phone')) ?></label><div><input class="textpole" type="tel" id="telefon" name="telefon" maxlength="30" value="<?= e((string) ($old['telefon'] ?? '')) ?>"></div></div>
<div class="radek"><label for="poznamka"><?= e(t('Note')) ?></label><div><textarea class="textbox nizky" id="poznamka" name="poznamka" rows="3" maxlength="1000"><?= e((string) ($old['poznamka'] ?? '')) ?></textarea></div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save booking')) ?>"></p>
</form>
