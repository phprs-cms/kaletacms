<?php
/**
 * 3.46 (N127, Admin\FreshProof): "Confirm it is you" – a POST of an administrator's section came without a recent proof.
 * The form goes again to the same address with the same fields, now with the current password (or after the passkey
 * confirmation, which submits it by itself – data-klic-odeslat in image/klice.js). After a form with files only the proof
 * goes (admin.php?action=confirm) and the person chooses the file again.
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var string $reason why the change waits (FreshProof::NEEDED, WRONG, PASSKEY or Auth::CONFIRM_LOCKED)
 * @var string $action where the form goes
 * @var list<array{0: string, 1: string}> $fields the posted fields, sent again unchanged
 * @var bool $files the form carried files – they have to be chosen again
 * @var string $back the section the change came from
 * @var bool $passkeyOnly the account signs in with a passkey only – its password confirms nothing
 * @var bool $passkeyConfirm the account has a passkey and the page is on the site's own host
 */
$klice = $passkeyConfirm ? ' data-klice="' . e($app->url('admin.php?action=account')) . '" data-klic-odeslat' : '';
?>
<p class="hlaska <?= $reason === Kaleta\Admin\FreshProof::WRONG || $reason === Kaleta\Core\Auth::CONFIRM_LOCKED ? 'hlaska-chyba' : 'hlaska-varovani' ?>" role="alert"><?= e(t($reason)) ?></p>
<form class="formular" method="post" action="<?= e($action) ?>"<?= $klice ?> id="confirm-proof">
<?= $csrf ?>
<?php foreach ($fields as [$name, $value]): ?>
<input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
<?php endforeach ?>
<fieldset><legend><?= e(t('Confirm it is you')) ?></legend>
<?php if ($files): ?>
<p class="napoveda"><?= e(t('The file you chose was not sent. Confirm here, then choose it again and submit the form.')) ?></p>
<?php endif ?>
<?php if (!$passkeyOnly): ?>
<div class="radek"><label for="confirm-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="confirm-heslo" name="<?= e(Kaleta\Admin\FreshProof::FIELD) ?>" size="30" autocomplete="current-password" required autofocus>
<span class="napoveda"><?= e(t('Then you have 10 minutes for further changes without the password.')) ?></span></div></div>
<?php endif ?>
<?php if ($passkeyConfirm): ?>
<div class="radek"><span class="popisek"><?= e(t('Your passkey')) ?></span><div><button class="navigace" type="button" data-klic-potvrdit><?= e(t('Confirm with your passkey')) ?></button>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="napoveda" data-klic-nepodporuje hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p></div></div>
<?php elseif ($passkeyOnly): ?>
<p class="napoveda"><?= e(t('Open this page on the site’s own address to confirm with your passkey, or sign in again with your passkey.')) ?></p>
<?php endif ?>
</fieldset>
<p class="tlacitka"><?php if (!$passkeyOnly): ?><button class="tl" type="submit"><?= e(t('Confirm and continue')) ?></button> <?php endif ?><a class="navigace" href="<?= e($back) ?>"><?= e(t('Cancel')) ?></a></p>
</form>
<?php if ($passkeyConfirm): ?>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>
