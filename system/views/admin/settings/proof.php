<?php
/**
 * 3.35 review M3: the fresh proof a change of how people sign in needs (Admin\Modules\Settings::signInChangeRefusal) –
 * the current password, or "Confirm with your passkey" for an administrator who signs in with a passkey only (the form
 * carries data-klice, list.php); nothing to type when a proof within the window is there already.
 *
 * @var array{passkeysExist: bool, passkeyOnly: bool, fresh: bool, passkeyRequired: bool} $proof
 * @var string $proofHint what the proof is needed for
 */
?>
<?php if ($proof['fresh']): ?>
<p class="napoveda"><?= e(t('You confirmed who you are a moment ago – these changes need nothing more for the next few minutes.')) ?></p>
<?php elseif ($proof['passkeyOnly']): ?>
<div class="radek"><span class="popisek"><?= e(t('Your passkey')) ?></span><div><button class="navigace" type="button" data-klic-potvrdit><?= e(t('Confirm with your passkey')) ?></button>
	<span class="napoveda"><?= e($proofHint) ?> <?= e(t('Confirm first – the page reloads – then make the change and save within 10 minutes.')) ?></span>
	<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
	<p class="napoveda" data-klic-nepodporuje hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p></div></div>
<?php else: ?>
<div class="radek"><label for="settings-soucasne-<?= e($proofId) ?>"><?= e(t('Your current password')) ?></label><div><input class="textpole" type="password" id="settings-soucasne-<?= e($proofId) ?>" name="soucasne" size="30" autocomplete="current-password">
	<span class="napoveda"><?= e($proofHint) ?></span></div></div>
<?php endif ?>
