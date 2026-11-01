<?php
/**
 * The "Security" tab (3.23, C12 – part of General until then): two-factor sign-in and the automatic suspension; the
 * firewall keeps its own tab next to it. For the variables and the $field function see list.php.
 */
?>
<fieldset>
<legend><?= e(t('Sign-in and access')) ?></legend>
<div class="radek">
	<label for="require_2fa"><?= e(t('Two-factor sign-in')) ?></label>
	<div><select id="require_2fa" name="require_2fa">
<?php foreach (['' => 'dobrovolné', 'spravci' => 'required for administrators', 'vsichni' => 'required for all users'] as $k => $n): ?>
		<option value="<?= e($k) ?>"<?= ($values['require_2fa'] ?? '') === $k ? ' selected' : '' ?>><?= e(t($n)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Anyone who must have it and has not turned it on yet can only reach My account after signing in until they set it up.')) ?></span></div>
</div>
<?php $autoSuspend = explode(',', $values['auto_suspend'] ?? ''); ?>
<div class="radek" id="auto_suspend">
	<span class="popisek"><?= e(t('Suspend automatically')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="auto_suspend[]" value="<?= e(Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS) ?>"<?= in_array(Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS, $autoSuspend, true) ? ' checked' : '' ?>> <?= e(t('block accounts nobody has used for %d days (never the last administrator)', Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS)) ?></label><br>
		<label><input type="checkbox" name="auto_suspend[]" value="<?= e(Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS) ?>"<?= in_array(Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS, $autoSuspend, true) ? ' checked' : '' ?>> <?= e(t('revoke Claude connections nobody has used for %d days', Kaleta\Core\SecurityHygiene::CONNECTION_DAYS)) ?></label>
		<span class="napoveda"><?= e(t('Checked once a day. While this is off, System status and the site audit only report unused accounts and connections. A blocked account shows the reason in Users and an administrator can reactivate it; a revoked connection has to be connected again.')) ?></span>
	</div>
</div>
</fieldset>
<p class="napoveda"><?= $module->hint(t('Blocking addresses, countries and probing of the public site is in Settings → Firewall.')) ?></p>
