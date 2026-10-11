<?php
/**
 * User: name, sign-in and role. Permissions follow from the role; manual settings are hidden in "Podrobné nastavení" (Advanced settings).
 *
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var array<string, mixed> $author
 * @var array<string, string> $errors
 * @var bool $isSelf  the admin is editing their own account
 * @var array<string, string> $modules  ident => name (sections to which access is set)
 * @var list<string> $hasModules
 * @var bool $manual  access to sections is set manually (differs from the default for the role)
 * @var list<array<string, mixed>> $customRoles  roles from Uživatelé → Role (Users → Roles)
 * @var list<array<string, mixed>> $connections  the user's Claude connections (Core\SecurityHygiene::connections)
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
$role = [
    0 => ['News author', 'Writes and edits their own news. An editor publishes them.'],
    1 => ['Editor', 'Manages all site content – pages, news, media – and publishes.'],
    2 => ['Administrator', 'Everything, including users, appearance and site settings.'],
];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a></p>
<?php if (($summary ?? '') !== ''): ?>
<p class="hlaska"><strong><?= e(t('What this user may do now:')) ?></strong> <?= e($summary) ?></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" autocomplete="off">
<?= $csrf ?>
<input type="hidden" name="idu" value="<?= (int) $author['idu'] ?>">
<div class="radek">
	<label for="jmeno"><?= e(t('Jméno a příjmení')) ?></label>
	<div><input class="textpole siroke" type="text" id="jmeno" name="jmeno" value="<?= e($author['jmeno']) ?>" maxlength="100"><span class="napoveda"><?= e(t('Zobrazuje se u novinek.')) ?></span></div>
</div>
<div class="radek">
	<label for="user"><?= e(t('Přihlašovací jméno')) ?></label>
	<div><input class="textpole" type="text" id="user" name="user" value="<?= e($author['user']) ?>" maxlength="40" size="30" required><?= $error('user') ?></div>
</div>
<div class="radek">
	<label for="email"><?= e(t('Email')) ?></label>
	<div><input class="textpole siroke" type="email" id="email" name="email" value="<?= e($author['email']) ?>" maxlength="190"><?= $error('email') ?></div>
</div>
<div class="radek">
	<label for="password"><?= e(t($author['idu'] ? 'New password' : 'Password')) ?></label>
	<div><input class="textpole" type="password" id="password" name="password" size="30" minlength="10" autocomplete="new-password">
	<label style="font-weight:normal"><input type="checkbox" data-ukaz-heslo="password"> <?= e(t('zobrazit')) ?></label><?= $error('password') ?>
	<span class="napoveda"><?= e(t('At least 10 characters.')) ?> <?= e(t($author['idu'] ? 'Leave empty if you are not changing the password.' : 'The user can change it later under My account.')) ?></span>
<?php if (!$author['idu']): ?>
	<label style="font-weight:normal"><input type="checkbox" name="pozvat" value="1"> <?= e(t('Instead of a password, send an e-mail invitation – the user sets their own password')) ?></label>
<?php endif ?>
	</div>
</div>

<fieldset>
<legend><?= e(t('Roles')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ($role as $value => [$name, $description]): ?>
	<label class="karta-volba">
		<input type="radio" name="admin" value="<?= $value ?>"<?= (int) $author['admin'] === $value && empty($author['role']) ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
		<strong><?= e(t($name)) ?></strong>
		<span><?= e(t($description)) ?></span>
	</label>
<?php endforeach ?>
<?php foreach ($customRoles as $customRole): ?>
	<label class="karta-volba">
		<input type="radio" name="admin" value="r<?= (int) $customRole['idr'] ?>"<?= (int) ($author['role'] ?? 0) === (int) $customRole['idr'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
		<strong><?= e($customRole['nazev']) ?></strong>
		<span><?= e($customRole['popis'] !== '' ? $customRole['popis'] : t('Custom role')) ?></span>
	</label>
<?php endforeach ?>
</div>
<p class="napoveda"><a href="<?= e($app->url('admin.php?module=roles')) ?>"><?= e(t('Custom role')) ?></a> – <?= e(t('a named set of sections, for example only Enquiries for a salesperson.')) ?></p>
<?php if ($isSelf): ?>
<p class="napoveda"><?= e(t('You cannot remove administrator rights from your own account.')) ?></p>
<?php endif ?>
</fieldset>

<details class="pokrocile"<?= $manual || $author['blokovat'] || !empty($author['url']) ? ' open' : '' ?>>
<summary><?= e(t('Detailed settings')) ?></summary>
<div class="radek">
	<label for="url"><?= e(t('User\'s website')) ?></label>
	<input class="textpole siroke" type="url" id="url" name="url" value="<?= e($author['url']) ?>" maxlength="255" placeholder="https://">
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Access to areas')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="rucne" value="1"<?= $manual ? ' checked' : '' ?>> <?= e(t('set manually (otherwise by role)')) ?></label><br>
<?php foreach ($modules as $ident => $name): ?>
		<label style="margin-left:22px"><input type="checkbox" name="moduly[]" value="<?= e($ident) ?>"<?= in_array($ident, $hasModules, true) ? ' checked' : '' ?>> <?= e(t($name)) ?></label><br>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('An author usually has only News, an editor all content sections, an administrator everything. With a custom role, the role\'s sections apply.')) ?></span>
	</div>
</div>
<?php if (!empty($author['totp_tajemstvi'])): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Two-factor sign-in')) ?></span>
	<div class="volby"><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span> <label><input type="checkbox" name="totp_reset" value="1"> <?= e(t('turn off (the user lost both their phone and backup codes)')) ?></label></div>
</div>
<?php endif ?>
<?php if (!$isSelf): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Block account')) ?></span>
	<div class="volby"><label><input type="checkbox" name="blokovat" value="1"<?= $author['blokovat'] ? ' checked' : '' ?>> <?= e(t('user cannot sign in')) ?></label>
<?php if ($author['blokovat'] && !empty($author['blokovano_automaticky'])): ?>
	<span class="napoveda"><?= e(t('Blocked automatically on %s – nobody had used the account for %d days. Untick the box and save to reactivate the account.', format_date($author['blokovano_automaticky']), Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS)) ?></span>
<?php endif ?>
	</div>
</div>
<?php endif ?>
</details>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($author['idu'] ? 'Uložit' : 'Add user')) ?>"></p>
</form>
<?php if ($author['idu'] && ($connections ?? []) !== []): ?>
<fieldset id="napojeni">
<legend><?= e(t('Claude connections')) ?></legend>
<p class="napoveda"><?= e(t('Personal tokens and connected applications of this account. A connection nobody has used for %d days is reported in System status; revoke what is not needed any more.', Kaleta\Core\SecurityHygiene::CONNECTION_DAYS)) ?></p>
<?php $accessLabel = array_map(fn (string $label): string => t($label), Kaleta\Front\OAuth::ACCESS_LABELS); ?>
<?php foreach ($connections as $c): ?>
<form class="vradku" method="post" action="<?= e($module->url('revoke_connection')) ?>" data-potvrdit="<?= e(t('Revoke the connection? Claude will no longer be able to sign in with it.')) ?>"><?= $csrf ?><input type="hidden" name="idu" value="<?= (int) $author['idu'] ?>"><input type="hidden" name="user" value="<?= e($author['user']) ?>">
<p><span class="stitek"><?= e($c['name']) ?></span> <span class="stitek"><?= e(t($c['kind'] === 'token' ? 'personal token' : 'connected application')) ?></span> <span class="stitek"><?= e($accessLabel[$c['access']] ?? $accessLabel['read']) ?></span>
	<?= e(t('created %s', format_date($c['created']))) ?>, <?= e($c['used'] ? t('last used %s', format_date($c['last'], true)) : t('never used')) ?>, <?= e($c['expiry'] !== null ? t('valid until %s', format_date($c['expiry'])) : t('no expiry')) ?>
	<?php if ($c['kind'] === 'token'): ?><input type="hidden" name="idt" value="<?= (int) $c['id'] ?>"><?php else: ?><input type="hidden" name="klient" value="<?= e($c['id']) ?>"><?php endif ?>
	<button class="navigace nebezpecne" type="submit"><?= e(t('Revoke')) ?></button></p>
</form>
<?php endforeach ?>
</fieldset>
<?php endif ?>
<?php if ($author['idu'] && $author['email'] !== '' && !$author['blokovat']): ?>
<div class="navigace-radek akce-dole"><form class="vradku" method="post" action="<?= e($module->url('password_link')) ?>" data-potvrdit="<?= e(t('Send the user an e-mail link to set a new password?')) ?>"><?= $csrf ?><input type="hidden" name="idu" value="<?= (int) $author['idu'] ?>"><input type="hidden" name="user" value="<?= e($author['user']) ?>"><button class="navigace" type="submit"><?= e(t('Send a new password link')) ?></button></form></div>
<?php endif ?>
