<?php
/**
 * My account.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var list<array<string, mixed>> $keys the account's sign-in keys (passkeys)
 * @var list<string> $backupCodes  backup codes just created (shown only once)
 * @var string $newSecret      two-factor sign-in being turned on (in progress)
 * @var string $uri
 * @var int $codesLeft
 * @var bool $claude  the Claude connection extension is enabled
 * @var list<array<string, mixed>> $tokens  personal tokens
 * @var list<array<string, mixed>> $apps  apps connected via OAuth (Claude connector)
 * @var string $newToken  token just created (shown only once)
 * @var string $mcpUrl
 * @var bool $teamExists the site has a people collection – the My e-mail signature tab (3.14)
 * @var bool $fresh  a passkey or backup-code step a few minutes ago – passkey changes need no password (3.35)
 * @var bool $passkeyOnly  the account signs in with a passkey only (its choice or the site's, Core\SignInPolicy)
 * @var bool $sitePasskeyOnly  the site requires passkeys of accounts that have one
 * @var bool $workingPasskey  the account has a passkey that signs in without a password
 * @var bool $passkeyHost  this host is the site's canonical one – passkeys work here
 * @var bool $codesFromPasskey  the backup codes shown came with the first passkey
 */
$fresh ??= false;
$passkeyOnly ??= false;
$sitePasskeyOnly ??= false;
$workingPasskey ??= false;
$passkeyHost ??= true;
$action = e($app->url('admin.php?action=account'));
?>
<?php if ($teamExists ?? false): ?>
<?= $app->view->render('admin/account_tabs', ['app' => $app, 'current' => '']) ?>
<?php endif ?>
<?php if ($backupCodes !== []): ?>
<div class="hlaska hlaska-ok">
<?php if (!empty($codesFromPasskey)): ?>
	<p><strong><?= e(t('Your backup codes')) ?></strong> <?= e(t('Save them somewhere safe – each works once after your password when you do not have your passkey. They will not be shown again.')) ?></p>
<?php else: ?>
	<p><strong><?= e(t('Two-factor sign-in is on.')) ?></strong> <?= e(t('Save your backup codes – each works once when you do not have your phone. They will not be shown again.')) ?></p>
<?php endif ?>
	<p class="zalozni-kody"><?= implode(' &nbsp; ', array_map(e(...), $backupCodes)) ?></p>
</div>
<?php endif ?>

<form class="formular" method="post" action="<?= $action ?>">
<?= $csrf ?><input type="hidden" name="co" value="profil">
<fieldset><legend><?= e(t('My details')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Přihlašovací jméno')) ?></span><div><?= e($user['user']) ?> <span class="napoveda"><?= e(t('Changed by an administrator in Users.')) ?></span></div></div>
<div class="radek"><label for="jmeno"><?= e(t('Jméno')) ?></label><div><input class="textpole siroke" type="text" id="jmeno" name="jmeno" value="<?= e($user['jmeno']) ?>" maxlength="100"><span class="napoveda"><?= e(t('Shown with news items on the site.')) ?></span></div></div>
<div class="radek"><label for="email"><?= e(t('Email')) ?></label><input class="textpole siroke" type="email" id="email" name="email" value="<?= e($user['email']) ?>" maxlength="190"></div>
<div class="radek"><label for="email-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="email-heslo" name="soucasne" size="30" autocomplete="current-password"><span class="napoveda"><?= e(t('Needed only when you change the e-mail – a password reset goes there. The old address gets a notice.')) ?></span></div></div>
<div class="radek"><label for="url"><?= e(t('My website')) ?></label><input class="textpole siroke" type="url" id="url" name="url" value="<?= e($user['url']) ?>" maxlength="255" placeholder="https://"></div>
<div class="radek"><label for="pozice"><?= e(t('Position in the company')) ?></label><input class="textpole siroke" type="text" id="pozice" name="pozice" value="<?= e($user['pozice']) ?>" maxlength="100" placeholder="<?= e(t('e.g. head of sales')) ?>"></div>
<div class="radek"><label for="foto"><?= e(t('My photo')) ?></label><div><input class="textpole siroke" type="text" id="foto" name="foto" value="<?= e($user['foto']) ?>" maxlength="255" data-obrazek><span class="napoveda"><?= e(t('A square photo, 300 × 300 px is enough.')) ?></span></div></div>
<div class="radek"><label for="bio"><?= e(t('A few sentences about me')) ?></label><div><textarea class="textbox nizky" id="bio" name="bio" rows="4" maxlength="1200"><?= e((string) $user['bio']) ?></textarea><span class="napoveda"><?= e(t('Shown as a short profile under your news items. What you do in the company and your background.')) ?></span></div></div>
<div class="radek"><label for="jazyk"><?= e(t('Administration language')) ?></label><div><select id="jazyk" name="jazyk">
<?php
// the selected language is the one the admin actually runs in (without an own choice, the site language, if the admin supports it)
$adminLanguage = Kaleta\Core\Language::base($user['jazyk'] ?: Kaleta\Core\Language::defaults($app->settings()));
$adminLanguage = isset(Kaleta\Core\Language::ADMIN_LANGUAGES[$adminLanguage]) ? $adminLanguage : 'cs';
foreach (Kaleta\Core\Language::ADMIN_LANGUAGES as $languageCode => $languageName): ?>
	<option value="<?= e($languageCode) ?>"<?= $adminLanguage === $languageCode ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
</select><span class="napoveda">Language · Jazyk</span></div></div>
<?php if ($adminLanguage === 'de'): ?>
<div class="radek"><label for="register"><?= e(t('Form of address in German')) ?></label><div><select id="register" name="register">
	<option value="formal"<?= ($user['register'] ?? '') !== 'informal' ? ' selected' : '' ?>><?= e(t('Formal (Sie)')) ?></option>
	<option value="informal"<?= ($user['register'] ?? '') === 'informal' ? ' selected' : '' ?>><?= e(t('Informal (du)')) ?></option>
</select><span class="napoveda"><?= e(t('How the German administration addresses you. The texts for visitors have their own setting in Settings.')) ?></span></div></div>
<?php endif ?>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save details')) ?>"></p>
</form>

<form class="formular" method="post" action="<?= $action ?>" id="notifications">
<?= $csrf ?><input type="hidden" name="co" value="notifications">
<fieldset><legend><?= e(t('Notifications by e-mail')) ?></legend>
<p class="napoveda"><?= e(t('Notifications tell you when a colleague mentions you, comments on what you are responsible for or assigns you something, and when a review date comes. They are always under the bell at the top of the administration; here you choose whether they also come by e-mail.')) ?></p>
<?php $notifyEmail = Kaleta\Core\Inbox::emailMode((string) ($user['notify_email'] ?? '')); ?>
<div class="radek"><span class="popisek" id="notify_email_popisek"><?= e(t('E-mail')) ?></span><div role="radiogroup" aria-labelledby="notify_email_popisek">
<div class="karty-volby karty-volby-text">
<?php foreach ([
    'instant' => ['Right away', 'One e-mail for each notification, as it happens.'],
    'daily' => ['Once a day', 'One summary in the morning (around 8:00) of what you have not read yet.'],
    'off' => ['Never', 'No e-mail – only the bell in the administration.'],
] as $key => [$name, $description]): ?>
	<label class="karta-volba"><input type="radio" name="notify_email" value="<?= e($key) ?>"<?= $notifyEmail === $key ? ' checked' : '' ?>><strong><?= e(t($name)) ?></strong><span><?= e(t($description)) ?></span></label>
<?php endforeach ?>
</div>
<?php if (Kaleta\Core\Mail::addresses((string) $user['email']) === []): ?>
<span class="napoveda"><?= e(t('Your account has no e-mail address – add it above, otherwise notifications are only under the bell.')) ?></span>
<?php endif ?>
</div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save the choice')) ?>"></p>
</form>

<form class="formular" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?><input type="hidden" name="co" value="heslo">
<fieldset><legend><?= e(t('Změna hesla')) ?></legend>
<div class="radek"><label for="soucasne"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="soucasne" name="soucasne" size="30" autocomplete="current-password" required></div></div>
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
<p><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span> <?= e(t('When signing in you enter a code from the app in addition to your password. Backup codes left: %d.', $codesLeft)) ?></p>
<input type="hidden" name="co" value="totp_vypni">
<div class="radek"><label for="vyp-heslo"><?= e(t('Password to confirm')) ?></label><div><input class="textpole" type="password" id="vyp-heslo" name="soucasne" size="30" autocomplete="current-password" required></div></div>
<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Turn off two-factor sign-in')) ?></button></p>
<?php elseif ($newSecret !== ''): ?>
<input type="hidden" name="co" value="totp_potvrd">
<ol>
	<li><?= e(t('In your authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Aegis…) add a new account by scanning the QR code:')) ?><br>
		<span class="totp-qr"><?= Kaleta\Core\Qr::svg($uri, t('QR code for the authenticator app')) ?></span><br>
		<?= e(t('Cannot scan it? Add the account by typing the key:')) ?><br><code class="totp-klic"><?= e(trim(chunk_split($newSecret, 4, ' '))) ?></code><br><small><a href="<?= e($uri) ?>"><?= e(t('On a phone you can tap here – the link opens your authenticator app.')) ?></a></small></li>
	<li><?= e(t('Enter the six-digit code shown by the app:')) ?></li>
</ol>
<div class="radek"><label for="kod"><?= e(t('Code from the app')) ?></label><div><input class="textpole" type="text" id="kod" name="kod" size="12" maxlength="7" inputmode="numeric" autocomplete="one-time-code" required autofocus></div></div>
<div class="radek"><label for="zap-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="zap-heslo" name="soucasne" size="30" autocomplete="current-password" required><span class="napoveda"><?= e(t('Needed to turn on two-factor sign-in.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Confirm and turn on')) ?>"></p>
<?php else: ?>
<p><?= e(t('Your account is protected by a password only. With two-factor sign-in, nobody can sign in without your phone – even if they guess or steal the password.')) ?></p>
<input type="hidden" name="co" value="totp_start">
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
<p class="hlaska hlaska-varovani"><?= e(t('Passkeys work only at the main address of the site:')) ?> <a href="<?= e(rtrim($app->settings()->get('site_url'), '/') . $app->url('admin.php?action=account')) ?>"><?= e(rtrim($app->settings()->get('site_url'), '/')) ?></a></p>
<?php endif ?>
<?php if ($sitePasskeyOnly && !$workingPasskey): ?>
<p class="hlaska hlaska-varovani"><?= e(t('This site asks everyone to sign in with a passkey. Add one for this device below – until then you sign in as before.')) ?></p>
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
	<td><?= !empty($k['discoverable']) ? '<span class="stitek stitek-vydano">' . e(t('without a password')) . '</span>' : '<span class="stitek">' . e(t('second step only')) . '</span>' ?></td>
	<td class="akce"><?php if (empty($k['discoverable'])): ?><button class="navigace" type="button" data-klic-pridat data-nahradit="<?= $kid ?>"><?= e(t('Register again')) ?></button> <?php endif ?><button class="navigace nebezpecne" type="submit" name="idk" value="<?= $kid ?>" data-potvrdit="<?= e(t('Remove this passkey? It will no longer sign you in.')) ?>"><?= e(t('Smazat')) ?></button></td>
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
<?php else: ?>
<div class="radek"><label for="klic-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="klic-heslo" name="soucasne" size="30" autocomplete="current-password" data-klic-heslo><span class="napoveda"><?= e(t('Needed to add or remove a passkey – or sign in again with your passkey and do it within a few minutes.')) ?></span></div></div>
<?php endif ?>
<p class="tlacitka"><button class="navigace" type="button" data-klic-pridat><?= e(t('Add a passkey from this device')) ?></button></p>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="napoveda" data-klic-nepodporuje hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p>
</fieldset>
</form>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php if ($workingPasskey): ?>
<form class="formular" method="post" action="<?= $action ?>" autocomplete="off">
<?= $csrf ?><input type="hidden" name="co" value="passkey_only">
<fieldset id="passkey-only"><legend><?= e(t('Passkey only')) ?></legend>
<?php if ($sitePasskeyOnly): ?>
<p><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span> <?= e(t('This site requires it: you sign in with your passkey. After your password only the passkey or a backup code finishes the sign-in.')) ?></p>
<?php else: ?>
<div class="radek"><span class="popisek"><?= e(t('Sign-in')) ?></span><div class="volby"><input type="hidden" name="passkey_only" value="0"><label><input type="checkbox" name="passkey_only" value="1"<?= $passkeyOnly ? ' checked' : '' ?> aria-describedby="passkey-only-napoveda"> <?= e(t('sign in with a passkey only')) ?></label>
	<span class="napoveda" id="passkey-only-napoveda"><?= e(t('Your password then never signs you in with the code from the app: after it only your passkey or a backup code finishes the sign-in. You can still change or reset the password. Lost your passkey and the backup codes? An administrator can reset your passkeys.')) ?></span></div></div>
<?php if (!$fresh): ?>
<div class="radek"><label for="passkey-only-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="passkey-only-heslo" name="soucasne" size="30" autocomplete="current-password"></div></div>
<?php endif ?>
<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save the choice')) ?></button></p>
<?php endif ?>
</fieldset>
</form>
<?php endif ?>

<?php if ($claude): ?>
<?php // the buttons of the listed connections (switch, disconnect, revoke) belong to this form through their form attribute:
// the form below then has "Create token" as its only button, so Enter in the token name creates the token (3.10 audit, N310-7) ?>
<form id="claude-connections" method="post" action="<?= $action ?>"><?= $csrf ?></form>
<form class="formular" method="post" action="<?= $action ?>">
<?= $csrf ?>
<fieldset id="claude"><legend><?= e(t('Claude connection')) ?></legend>
<?php if ($newToken !== ''): ?>
<div class="hlaska hlaska-ok">
	<p><strong><?= e(t('The token has been created.')) ?></strong> <?= e(t('Copy it now – it will not be shown again.')) ?></p>
	<p><code class="totp-klic"><?= e($newToken) ?></code></p>
	<p><?= e(t('In Claude Code, run:')) ?></p>
	<p><code class="totp-klic" style="font-size:12px">claude mcp add --transport http kaleta <?= e($mcpUrl) ?> --header "Authorization: Bearer <?= e($newToken) ?>"</code></p>
	<p class="napoveda"><?= e(t('In the Claude app you do not need a token: add a custom connector with the address %s and confirm access by signing in.', $mcpUrl)) ?></p>
</div>
<?php endif ?>
<p><?= e(t('The easiest way is to add a custom connector in the Claude app with the address %s – Claude sends you here to sign in and confirm access, no token to copy. The token below is for Claude Code and other tools without sign-in.', $mcpUrl)) ?></p>
<?= $app->view->render('admin/mcp_address', ['url' => $mcpUrl, 'id' => 'mcp-adresa-ucet']) ?>
<?php $accessLabel = array_map(fn (string $label): string => t($label), Kaleta\Front\OAuth::ACCESS_LABELS); ?>
<?php
// 3.10: the tool set of a connection and the button that switches it ("<set>:token:<id>" or "<set>:app:<client_id>")
// 3.26: a "work" connection (D-W10) switches to all tools like the essentials do
$toolSetLabel = fn (string $set): string => '<span class="stitek">' . e(match ($set) { 'essentials' => t('essentials'), 'work' => t('work tools'), default => t('all tools') }) . '</span>';
$toolSetSwitch = fn (string $set, string $target): string => '<button class="navigace" type="submit" form="claude-connections" name="tool_set_switch" value="' . e(($set === 'all' ? 'essentials' : 'all') . ':' . $target) . '">'
    . e($set === 'all' ? t('Switch to essentials') : t('Switch to all tools')) . '</button>';
?>
<?php if ($apps !== []): ?>
<h2><?= e(t('Connected applications')) ?></h2>
<?php foreach ($apps as $a): ?>
<p><span class="stitek"><?= e($a['nazev']) ?></span> <span class="stitek"><?= e($accessLabel[$a['access']] ?? $accessLabel['read']) ?></span> <?= $toolSetLabel((string) $a['tool_set']) ?> <?= e(t('connected %s', format_date($a['vytvoren']))) ?>, <?= e($a['pouzit'] ? t('naposledy použita %s', format_date($a['pouzit'], true)) : t('zatím nepoužita')) ?>
	<?= $toolSetSwitch((string) $a['tool_set'], 'app:' . $a['klient']) ?>
	<button class="navigace nebezpecne" type="submit" form="claude-connections" name="odpojit_klient" value="<?= e($a['klient']) ?>" data-potvrdit="<?= e(t('Disconnect the application? It will not get into the website until you allow it again.')) ?>"><?= e(t('Disconnect')) ?></button></p>
<?php endforeach ?>
<?php endif ?>
<p><?= e(t('Claude will work with the site')) ?> <strong><?= e(t('in your name and with your permissions')) ?></strong>: <?= e(t((int) $user['admin'] === 2 ? 'write and edit pages and news, and manage categories, collections and the look of the site.' : 'write and edit news.')) ?> <?= e(t('With “Drafts only”, pages and news stay drafts until you publish them; with “Can also publish”, Claude publishes when you ask it to. All its changes are in the Change log.')) ?></p>
<h2><?= e(t('Personal tokens (Claude Code and other tools)')) ?></h2>
<p class="napoveda"><?= e(t('For tools without sign-in. Protect a token like a password.')) ?></p>
<?php foreach ($tokens as $t): ?>
<p><span class="stitek"><?= e($t['nazev']) ?></span> <span class="stitek"><?= e($accessLabel[$t['access']] ?? $accessLabel['read']) ?></span> <?= $toolSetLabel((string) $t['tool_set']) ?> <?= e(t('created %s', format_date($t['vytvoren']))) ?>, <?= e($t['pouzit'] ? t('naposledy použit %s', format_date($t['pouzit'], true)) : t('zatím nepoužit')) ?>,
	<?= $t['expirace'] === null ? e(t('no expiry')) : ($t['expirace'] < date('Y-m-d H:i:s') ? '<strong>' . e(t('expired %s', format_date($t['expirace']))) . '</strong>' : e(t('valid until %s', format_date($t['expirace'])))) ?>
	<?= $toolSetSwitch((string) $t['tool_set'], 'token:' . (int) $t['idt']) ?>
	<button class="navigace nebezpecne" type="submit" form="claude-connections" name="smaz_token" value="<?= (int) $t['idt'] ?>" data-potvrdit="<?= e(t('Revoke the token? Claude will no longer be able to sign in with it.')) ?>"><?= e(t('Revoke token')) ?></button></p>
<?php endforeach ?>
<div class="radek"><label for="token-nazev"><?= e(t('Name of the new token')) ?></label><div><input class="textpole" type="text" id="token-nazev" name="nazev" maxlength="100" size="30" placeholder="<?= e(t('e.g. Claude on my laptop')) ?>"></div></div>
<div class="radek"><label for="token-platnost"><?= e(t('Valid for')) ?></label><div><select id="token-platnost" name="platnost">
<?php foreach (Kaleta\Admin\Account::TOKEN_LIFETIMES as $days): ?>
	<option value="<?= $days ?>"<?= $days === 365 ? ' selected' : '' ?>><?= e($days === 0 ? t('no expiry') : ($days === 365 ? t('1 year') : t('%d days', $days))) ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('An expired token stops working on its own; you then create a new one. A token nobody uses for %d days is reported in System status.', Kaleta\Core\SecurityHygiene::CONNECTION_DAYS)) ?></span></div></div>
<?= $app->view->render('admin/connection-access', ['role' => t(Kaleta\Core\Auth::TYPES[(int) $user['admin']] ?? ''), 'selected' => 'full', 'toolSet' => 'all']) ?>
<div class="radek"><label for="token-heslo"><?= e(t('Current password')) ?></label><div><input class="textpole" type="password" id="token-heslo" name="soucasne" size="30" autocomplete="current-password" required><span class="napoveda"><?= e(t('Needed to create a token – a token works without the password and without two-factor sign-in.')) ?></span></div></div>
<p class="tlacitka"><button class="tl" type="submit" name="co" value="token_novy"><?= e(t('Create token')) ?></button></p>
</fieldset>
</form>
<?php endif ?>
