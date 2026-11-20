<?php
/**
 * My account (3.47, U68: the shell of the tabs my details, absence, sign-in and security, Claude – the backup codes just made,
 * "Confirm with your passkey" on the tabs with guarded forms, then the tab's partial account_<tab>; signature and colleagues
 * have their own views).
 *
 * @var Kaleta\Core\App $app
 * @var string $tab  '' = my details, 'absence', 'security', 'claude' (Admin\Account::TABS)
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
 * @var bool $fresh  a passkey or backup-code step a few minutes ago – passkey changes need no password (3.35)
 * @var bool $recent  any proof a few minutes ago – setting up the authenticator app asks no password (3.46, N138)
 * @var bool $renewCodes  a passkey and no authenticator app – "New backup codes" is offered (3.35)
 * @var bool $passkeyOnly  the account signs in with a passkey only (its choice or the site's, Core\SignInPolicy)
 * @var bool $sitePasskeyOnly  the site requires passkeys of accounts that have one
 * @var bool $workingPasskey  the account has a passkey that signs in without a password
 * @var bool $passkeyHost  this host is the site's canonical one – passkeys work here
 * @var bool $codesFromPasskey  the backup codes shown came with the first passkey
 */
$fresh ??= false;
$recent ??= false;
$renewCodes ??= false;
$passkeyOnly ??= false;
$sitePasskeyOnly ??= false;
$workingPasskey ??= false;
$passkeyHost ??= true;
$siteRpId ??= '';
// 3.35 review M3: a passkey registered for another host of the site (before the site address moved) works nowhere here
$elsewhere = static fn (array $k): bool => $siteRpId !== '' && (string) ($k['rp_id'] ?? '') !== '' && (string) $k['rp_id'] !== $siteRpId;
$tab ??= '';
$action = e($app->url(Kaleta\Admin\Account::path($tab))); // 3.47 (U68): every form posts to its own tab
// 3.35 review M2: an account that signs in with a passkey only is never confirmed by its password (Auth::freshProof) –
// its forms show no password field, only this note; the strong proof comes from "Confirm with your passkey" below
$proofNote = '<p class="napoveda">' . e(t($fresh ? 'You confirmed it with your passkey a moment ago – no password is needed for the next few minutes.' : 'You sign in with a passkey only: confirm with your passkey at the top of this page first – your password does not confirm changes here.')) . '</p>';
?>
<?= $app->view->render('admin/account_tabs', ['app' => $app, 'current' => $tab]) ?>
<?php if ($backupCodes !== []): ?>
<div class="hlaska hlaska-ok">
<?php if (!empty($codesRenewed)): ?>
	<p><strong><?= e(t('Your new backup codes')) ?></strong> <?= e(t('The old codes no longer work. Save these somewhere safe – each works once after your password when you do not have your passkey. They will not be shown again.')) ?></p>
<?php elseif (!empty($codesFromPasskey)): ?>
	<p><strong><?= e(t('Your backup codes')) ?></strong> <?= e(t('Save them somewhere safe – each works once after your password when you do not have your passkey. They will not be shown again.')) ?></p>
<?php else: ?>
	<p><strong><?= e(t('Two-factor sign-in is on.')) ?></strong> <?= e(t('Save your backup codes – each works once when you do not have your phone. They will not be shown again.')) ?></p>
<?php endif ?>
	<p class="zalozni-kody"><?= implode(' &nbsp; ', array_map(e(...), $backupCodes)) ?></p>
</div>
<?php endif ?>

<?php $confirmForm = $passkeyOnly && !$fresh && in_array($tab, ['', 'security', 'claude'], true); // 3.47: only on the tabs with guarded forms ?>
<?php if ($confirmForm): // 3.35 review M2: the strong proof of a passkey-only account (Auth::confirmWithPasskey) ?>
<form class="formular" method="post" action="<?= $action ?>" data-klice="<?= $action ?>" id="confirm-passkey">
<?= $csrf ?>
<fieldset><legend><?= e(t('Confirm with your passkey')) ?></legend>
<p><?= e(t('You sign in with a passkey only. Changes of your e-mail, password, passkeys, backup codes, two-factor sign-in and tokens need a confirmation with your passkey – then you have 10 minutes for them.')) ?></p>
<p class="tlacitka"><button class="navigace" type="button" data-klic-potvrdit><?= e(t('Confirm with your passkey')) ?></button></p>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="napoveda" data-klic-nepodporuje hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p>
</fieldset>
</form>
<?php endif ?>
<?php
$partial = ['' => 'account_details', 'absence' => 'account_absence', 'security' => 'account_security', 'claude' => 'account_claude'][$tab] ?? 'account_details';
echo $app->view->render('admin/' . $partial, array_diff_key(get_defined_vars(), ['__file' => true, '__data' => true, 'view' => true]));
?>
<?php if ($tab === 'security' || $confirmForm): ?>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>
