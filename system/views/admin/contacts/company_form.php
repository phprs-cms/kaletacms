<?php
/**
 * The fields of a company (3.28): name, domain (contacts with an address there join it by themselves – never a free-mail
 * domain), registration and VAT number, the responsible person, a note.
 *
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var array<string, mixed> $co the company (id 0 = a new one)
 * @var array<string, string> $draft a refused form's fields
 * @var array<int, string> $people who may be responsible
 */
$value = fn (string $key): string => (string) ($draft[$key] ?? $co[$key] ?? '');
$ownerId = (int) ($draft['owner_id'] ?? $co['owner_id'] ?? 0);
?>
<form class="formular" method="post" action="<?= e($module->url('company_save')) ?>" data-company-form>
	<?= $csrf ?><?php if ((int) $co['id'] > 0): ?><input type="hidden" name="company" value="<?= (int) $co['id'] ?>"><?php endif ?>
	<div class="radek"><label for="firma-nazev"><?= e(t('Company name')) ?></label><div><input class="textpole siroke" type="text" id="firma-nazev" name="name" maxlength="150" value="<?= e($value('name')) ?>" required></div></div>
	<div class="radek"><label for="firma-domena"><?= e(t('Domain')) ?></label><div><input class="textpole" type="text" id="firma-domena" name="domain" maxlength="190" value="<?= e($value('domain')) ?>" placeholder="example.com">
		<span class="napoveda"><?= e(t('New contacts with an e-mail address at this domain join the company by themselves. Never a free-mail domain such as gmail.com.')) ?></span></div></div>
	<div class="radek"><label for="firma-ico"><?= e(t('Registration number')) ?></label><div><input class="textpole" type="text" id="firma-ico" name="registration_no" maxlength="40" value="<?= e($value('registration_no')) ?>"></div></div>
	<div class="radek"><label for="firma-dic"><?= e(t('VAT number')) ?></label><div><input class="textpole" type="text" id="firma-dic" name="vat_id" maxlength="40" value="<?= e($value('vat_id')) ?>"></div></div>
	<div class="radek"><label for="firma-odpovedny"><?= e(t('Responsible person')) ?></label><div><select id="firma-odpovedny" name="owner_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= $ownerId === (int) $userId ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select> <span class="napoveda"><?= e(t('Gets an e-mail when someone else chooses them. It does not change who may open the customer.')) ?></span></div></div>
	<div class="radek"><label for="firma-poznamka"><?= e(t('Note')) ?></label><div><textarea class="textbox nizky" id="firma-poznamka" name="note" rows="3" maxlength="<?= Kaleta\Core\Contacts::MAX_TEXT ?>"><?= e($value('note')) ?></textarea></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t((int) $co['id'] > 0 ? 'Save' : 'Create the company')) ?></button></p>
</form>
