<?php
/**
 * My account → Sign-in and security (3.47, U68): the password, two-factor sign-in, passkeys, backup codes and "passkey only".
 *
 * @var Kaleta\Core\App $app
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string $action  the address the forms post to (this tab)
 * @var string $proofNote  the note shown instead of a password field to an account that signs in with a passkey only
 * @var bool $passkeyOnly
 * @var bool $fresh
 * @var list<array<string, mixed>> $keys
 * @var string $newSecret
 * @var string $uri
 * @var int $codesLeft
 * @var list<array<string, mixed>> $tokens
 * @var list<array<string, mixed>> $apps
 * @var bool $recent
 * @var bool $renewCodes
 * @var bool $sitePasskeyOnly
 * @var bool $workingPasskey
 * @var bool $passkeyHost
 * @var callable(array<string, mixed>): bool $elsewhere
 */
?>
<form class="formular" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?><input type="hidden" name="co" value="heslo">
<fieldset><legend><?= e(t('Change password')) ?></legend>
<?php if ($passkeyOnly): ?>
<?= $proofNote ?>
<?php else: ?>
<div class="radek"><label for="soucasne"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="soucasne" name="soucasne" size="30" autocomplete="current-password" required></div></div>
<?php endif ?>
<div class="radek"><label for="nove"><?= e(t('New password')) ?></label><div><input class="textpole" type="password" id="nove" name="nove" size="30" minlength="10" autocomplete="new-password" required><span class="napoveda"><?= e(t('At least 10 characters.')) ?></span></div></div>
<div class="radek"><label for="nove2"><?= e(t('New password again')) ?></label><div><input class="textpole" type="password" id="nove2" name="nove2" size="30" autocomplete="new-password" required></div></div>
<?php if ($tokens !== []): ?>
<div class="radek"><span class="popisek"><?= e(t('Connections')) ?></span><div class="volby"><input type="hidden" name="zrusit_tokeny" value="0"><label><input type="checkbox" name="zrusit_tokeny" value="1" checked> <?= e(t('also revoke connection tokens (Claude, API)')) ?></label>
	<span class="napoveda"><?= e(t('A token works without the password and without two-factor sign-in. If you are changing the password because you suspect misuse, leave this ticked and create the connection again afterwards.')) ?></span></div></div>
<?php endif ?>
<?php if ($apps !== []): ?>
<p class="napoveda"><?= e(t('Apps connected to this account (the Claude connector) are disconnected with the new password – connect them again in the Claude app.')) ?></p>
<?php endif ?>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Změnit heslo')) ?>"></p>
</form>

<form class="formular" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?>
<fieldset id="two-factor"><legend><?= e(t('Two-factor sign-in')) ?></legend>
<?php if ($user['totp_tajemstvi'] !== ''): ?>
<p><span class="stitek stitek-vydano"><?= e(t('on')) ?></span> <?= e(t('When signing in you enter a code from the app in addition to your password. Backup codes left: %d.', $codesLeft)) ?></p>
<input type="hidden" name="co" value="totp_vypni">
<?php if ($passkeyOnly): ?>
<?= $proofNote ?>
<?php else: ?>
<div class="radek"><label for="vyp-heslo"><?= e(t('Password to confirm')) ?></label><div><input class="textpole" type="password" id="vyp-heslo" name="soucasne" size="30" autocomplete="current-password" required></div></div>
<?php endif ?>
<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Turn off two-factor sign-in')) ?></button></p>
<?php elseif ($newSecret !== ''): ?>
<input type="hidden" name="co" value="totp_potvrd">
<ol>
	<li><?= e(t('In your authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Aegis…) add a new account by scanning the QR code:')) ?><br>
		<span class="totp-qr"><?= Kaleta\Core\Qr::svg($uri, t('QR code for the authenticator app')) ?></span><br>
		<?= e(t('Cannot scan it? Add the account by typing the key:')) ?><br><code class="totp-klic"><?= e(trim(chunk_split($newSecret, 4, ' '))) ?></code><br><small><a href="<?= e($uri) ?>"><?= e(t('On a phone you can tap here – the link opens your authenticator app.')) ?></a></small></li>
	<li><?= e(t('Enter the six-digit code shown by the app:')) ?></li>
</ol>
<div class="radek"><label for="kod"><?= e(t('Code from the app')) ?></label><div><input class="textpole" type="text" id="kod" name="kod" size="12" maxlength="12" inputmode="numeric" autocomplete="one-time-code" required autofocus></div></div>
<?php if ($passkeyOnly): ?>
<?= $proofNote ?>
<?php else: ?>
<div class="radek"><label for="zap-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="zap-heslo" name="soucasne" size="30" autocomplete="current-password" required><span class="napoveda"><?= e(t('Needed to turn on two-factor sign-in.')) ?></span></div></div>
<?php endif ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Confirm and turn on')) ?>"></p>
<?php else: ?>
<p><?= e(t('Your account is protected by a password only. With two-factor sign-in, nobody can sign in without your phone – even if they guess or steal the password.')) ?></p>
<input type="hidden" name="co" value="totp_start">
<?php if ($passkeyOnly): ?>
<?= $proofNote ?>
<?php elseif (!$recent): // 3.46 (N138): the new secret is made only after a proof ?>
<div class="radek"><label for="start-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="start-heslo" name="soucasne" size="30" autocomplete="current-password" required><span class="napoveda"><?= e(t('Needed to set up two-factor sign-in.')) ?></span></div></div>
<?php endif ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Turn on two-factor sign-in')) ?>"></p>
<?php endif ?>
</fieldset>
</form>

<?php
// 3.35: passkeys for every account – the sign-in without a password, and the second step after the password. The rename
// forms stand outside the main form (no nesting): each row's field and button belong to theirs through the form attribute.
$oldKeys = array_values(array_filter($keys, static fn (array $k): bool => empty($k['discoverable'])));
foreach ($keys as $k): ?>
<form id="klic-prejmenuj-<?= (int) $k['idk'] ?>" method="post" action="<?= $action ?>"><?= $csrf ?><input type="hidden" name="co" value="klic_prejmenuj"><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"></form>
<?php endforeach ?>
<form class="formular" method="post" action="<?= $action ?>" data-klice="<?= $action ?>">
<?= $csrf ?>
<fieldset id="passkeys"><legend><?= e(t('Passkeys')) ?></legend>
<p><?= e(t('Sign in with your fingerprint, face, screen lock or a security key – without a user name and password. With your password, a passkey is the second step: once you have one, a password alone never signs you in.')) ?></p>
<?php if (!$passkeyHost): ?>
<p class="hlaska hlaska-varovani"><?= e(t('Passkeys work only at the main address of the site:')) ?> <a href="<?= e(rtrim($app->settings()->get('site_url'), '/') . $app->url(Kaleta\Admin\Account::path('security'))) ?>"><?= e(rtrim($app->settings()->get('site_url'), '/')) ?></a></p>
<?php endif ?>
<?php if ($sitePasskeyOnly && !$workingPasskey): ?>
<p class="hlaska hlaska-varovani"><?= e(t('This site asks everyone to sign in with a passkey. Add one for this device below – until then you sign in as before.')) ?></p>
<?php endif ?>
<?php if (array_filter($keys, $elsewhere) !== []): ?>
<p class="hlaska hlaska-varovani"><?= e(t('Some of your passkeys were registered for another address of the site and do not work here – the site address has changed since. Remove them and add a passkey from this device again.')) ?></p>
<?php endif ?>
<?php if ($oldKeys !== []): ?>
<p class="hlaska hlaska-varovani"><?= e(t('A passkey marked “second step only” was added before passkeys could sign in on their own. To sign in with it without a password, register it again on that device – the button in its row replaces it.')) ?></p>
<?php endif ?>
<?php if ($keys !== []): ?>
<div class="tab-obal">
<table class="vypis">
<caption class="navod-skryte"><?= e(t('Your passkeys')) ?></caption>
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Added')) ?></th><th scope="col"><?= e(t('Last used')) ?></th><th scope="col"><?= e(t('Signs in')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($keys as $k): $kid = (int) $k['idk']; ?>
<tr>
	<td><input class="textpole" type="text" name="nazev" form="klic-prejmenuj-<?= $kid ?>" value="<?= e($k['nazev']) ?>" maxlength="80" size="18" aria-label="<?= e(t('Name of the passkey %s', (string) $k['nazev'])) ?>"> <button class="navigace" type="submit" form="klic-prejmenuj-<?= $kid ?>"><?= e(t('Rename')) ?></button></td>
	<td class="cislo"><?= e(format_date((string) $k['vytvoreno'])) ?></td>
	<td class="cislo"><?= $k['pouzito'] !== null ? e(format_date((string) $k['pouzito'])) : '–' ?></td>
	<td><?= $elsewhere($k) ? '<span class="stitek">' . e(t('another address: %s', (string) $k['rp_id'])) . '</span>' : (!empty($k['discoverable']) ? '<span class="stitek stitek-vydano">' . e(t('without a password')) . '</span>' : '<span class="stitek">' . e(t('second step only')) . '</span>') ?></td>
	<td class="akce"><?php if (empty($k['discoverable'])): ?><button class="navigace" type="button" data-klic-pridat data-nahradit="<?= $kid ?>"><?= e(t('Register again')) ?></button> <?php endif ?><button class="navigace nebezpecne" type="submit" name="idk" value="<?= $kid ?>" data-potvrdit="<?= e(t('Remove this passkey? It will no longer sign you in.')) ?>"><?= e(t('Delete')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<input type="hidden" name="co" value="klic_smaz">
<p class="napoveda"><?= e(t('Backup codes left: %d. Each works once after your password when you do not have your passkey.', $codesLeft)) ?></p>
<?php endif ?>
<div class="radek"><label for="klic-nazev"><?= e(t('Device name')) ?></label><div><input class="textpole" type="text" id="klic-nazev" name="nazev" size="30" maxlength="80" placeholder="<?= e(t('e.g. MacBook, phone')) ?>"></div></div>
<?php if ($fresh): ?>
<p class="napoveda"><?= e(t('You confirmed your sign-in with a passkey or a backup code a moment ago – no password is needed to add or remove a passkey for the next few minutes.')) ?></p>
<?php elseif ($passkeyOnly): ?>
<?= $proofNote ?>
<?php else: ?>
<div class="radek"><label for="klic-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="klic-heslo" name="soucasne" size="30" autocomplete="current-password" data-klic-heslo><span class="napoveda"><?= e(t('Needed to add or remove a passkey – or sign in again with your passkey and do it within a few minutes.')) ?></span></div></div>
<?php endif ?>
<p class="tlacitka"><button class="navigace" type="button" data-klic-pridat><?= e(t('Add a passkey from this device')) ?></button></p>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="napoveda" data-klic-nepodporuje hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p>
</fieldset>
</form>
<?php if ($renewCodes): // 3.35: new backup codes for an account with a passkey and without the app ?>
<form class="formular" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?><input type="hidden" name="co" value="backup_codes_new">
<fieldset id="backup-codes"><legend><?= e(t('Backup codes')) ?></legend>
<p><?= e(t('Backup codes left: %d.', $codesLeft)) ?> <?= e(t('Lost them or used most of them? New codes replace them all – the old ones stop working at once. The new codes are shown only once.')) ?></p>
<?php if ($passkeyOnly && !$fresh): ?>
<?= $proofNote ?>
<?php elseif (!$fresh): ?>
<div class="radek"><label for="kody-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="kody-heslo" name="soucasne" size="30" autocomplete="current-password"><span class="napoveda"><?= e(t('Needed to create new backup codes – or sign in again with your passkey and do it within a few minutes.')) ?></span></div></div>
<?php endif ?>
<p class="tlacitka"><button class="navigace" type="submit" data-potvrdit="<?= e(t('Create new backup codes? The old ones will stop working.')) ?>"><?= e(t('New backup codes')) ?></button></p>
</fieldset>
</form>
<?php endif ?>
<?php if ($workingPasskey): ?>
<form class="formular" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?><input type="hidden" name="co" value="passkey_only">
<fieldset id="passkey-only"><legend><?= e(t('Passkey only')) ?></legend>
<?php if ($sitePasskeyOnly): ?>
<p><span class="stitek stitek-vydano"><?= e(t('on')) ?></span> <?= e(t('This site requires it: you sign in with your passkey. After your password only the passkey or a backup code finishes the sign-in.')) ?></p>
<?php else: ?>
<div class="radek"><span class="popisek"><?= e(t('Sign-in')) ?></span><div class="volby"><input type="hidden" name="passkey_only" value="0"><label><input type="checkbox" name="passkey_only" value="1"<?= $passkeyOnly ? ' checked' : '' ?> aria-describedby="passkey-only-napoveda"> <?= e(t('sign in with a passkey only')) ?></label>
	<span class="napoveda" id="passkey-only-napoveda"><?= e(t('Your password then never signs you in with the code from the app: after it only your passkey or a backup code finishes the sign-in. You can still change or reset the password. Lost your passkey and the backup codes? An administrator can reset your passkeys.')) ?></span></div></div>
<?php if ($passkeyOnly && !$fresh): ?>
<?= $proofNote ?>
<?php elseif (!$fresh): ?>
<div class="radek"><label for="passkey-only-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="passkey-only-heslo" name="soucasne" size="30" autocomplete="current-password"></div></div>
<?php endif ?>
<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save the choice')) ?></button></p>
<?php endif ?>
</fieldset>
</form>
<?php endif ?>
