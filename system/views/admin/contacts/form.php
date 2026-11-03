<?php
/**
 * The fields of a contact (3.28): new and edit. An address that already belongs to another contact is refused (open it, or
 * merge the two); the company is chosen from the list or named (an existing one by name, otherwise a new one).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var array<string, mixed> $c the contact (id 0 = a new one)
 * @var array<string, string> $draft a refused form's fields
 * @var list<array<string, mixed>> $companies
 * @var array<int, string> $people who may be responsible
 */
use Kaleta\Core\Language;

$value = fn (string $key): string => (string) ($draft[$key] ?? $c[$key] ?? '');
$companyId = (int) ($draft['company_id'] ?? $c['company_id'] ?? 0);
$ownerId = (int) ($draft['owner_id'] ?? $c['owner_id'] ?? 0);
?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" data-contact-form>
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
	<div class="radek"><label for="kontakt-jmeno"><?= e(t('Full name')) ?></label><div><input class="textpole siroke" type="text" id="kontakt-jmeno" name="name" maxlength="150" value="<?= e($value('name')) ?>" autocomplete="off"></div></div>
	<div class="radek"><label for="kontakt-email"><?= e(t('E-mail')) ?></label><div><input class="textpole siroke" type="email" id="kontakt-email" name="email" maxlength="190" value="<?= e($value('email')) ?>" autocomplete="off">
		<span class="napoveda"><?= e(t('One contact per e-mail address – new enquiries, bookings and subscriptions from it join this contact.')) ?></span></div></div>
	<div class="radek"><label for="kontakt-telefon"><?= e(t('Phone')) ?></label><div><input class="textpole" type="tel" id="kontakt-telefon" name="phone" maxlength="40" value="<?= e($value('phone')) ?>" autocomplete="off"></div></div>
	<div class="radek"><label for="kontakt-firma"><?= e(t('Company')) ?></label><div><select id="kontakt-firma" name="company_id"><option value="0">—</option>
<?php foreach ($companies as $co): ?>
		<option value="<?= (int) $co['id'] ?>"<?= $companyId === $co['id'] ? ' selected' : '' ?>><?= e($co['name']) ?></option>
<?php endforeach ?>
	</select>
		<label class="zakaznik-nova-firma"><span><?= e(t('or a new company')) ?></span> <input class="textpole" type="text" name="company_new" maxlength="150" value="<?= e($draft['company_new'] ?? '') ?>"></label></div></div>
	<div class="radek"><label for="kontakt-odpovedny"><?= e(t('Responsible person')) ?></label><div><select id="kontakt-odpovedny" name="owner_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= $ownerId === (int) $userId ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select> <span class="napoveda"><?= e(t('Gets an e-mail when someone else chooses them. It does not change who may open the customer.')) ?></span></div></div>
	<div class="radek"><label for="kontakt-jazyk"><?= e(t('Language')) ?></label><div><select id="kontakt-jazyk" name="language"><option value="">—</option>
<?php foreach (Language::AVAILABLE as $code => [$name]): ?>
		<option value="<?= e($code) ?>"<?= $value('language') === $code ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select> <span class="napoveda"><?= e(t('The language to write to them in.')) ?></span></div></div>
	<div class="radek"><label for="kontakt-shrnuti"><?= e(t('Summary')) ?></label><div><textarea class="textbox nizky" id="kontakt-shrnuti" name="summary" rows="4" maxlength="<?= Kaleta\Core\Contacts::MAX_TEXT ?>"><?= e($value('summary')) ?></textarea>
		<span class="napoveda"><?= e(t('What the team should know about this customer. Only administration users see it.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t($c['id'] > 0 ? 'Save' : 'Create the contact')) ?></button></p>
</form>
