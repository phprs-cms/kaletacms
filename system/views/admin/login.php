<?php
/**
 * Sign-in to the admin.
 *
 * @var Kaleta\Core\App $app
 * @var string|null $error
 * @var string $login
 * @var bool $code  second step: the password is already correct, waiting for the code from the authenticator app
 * @var bool $keys  the second step may be the passkey
 * @var array{code: bool, backup: bool, passkey: bool} $steps  the second steps the account may use (3.35, Core\SignInPolicy)
 * @var bool $passkeyHost  this host is the site's canonical one – passkeys work here (3.35)
 * @var string $canonicalAdmin  the administration at the canonical host
 */
$steps ??= ['code' => true, 'backup' => true, 'passkey' => !empty($keys)];
$passkeyHost ??= true;
$canonicalAdmin ??= '';
?>
<!doctype html>
<html lang="<?= e(Kaleta\Core\Language::code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/tema.js')) ?>?v=<?= e(KALETA_VERSION) ?>"></script>
<title><?= e(t('Přihlášení')) ?> – Kaleta</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/kaleta-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/kaleta-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/kaleta-znacka-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
</head>
<body class="login">
<div class="login-karta">
<?= $app->view->render('admin/logo', ['height' => 36]) ?>
<h1><?= e(t('Sign in to the administration')) ?></h1>
<?php if ($error !== null): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e($error) ?></p>
<?php elseif ($app->request->get('password_changed') === '1' || $app->request->get('password') === 'zmeneno'): // "password" in an address trips firewalls and log scrubbers; the old form stays for a sign-in page opened before 3.9 ?>
<p class="hlaska hlaska-ok" role="status"><?= e(t('The password has been changed. Sign in with the new password.')) ?></p>
<?php endif ?>
<?php $demo = Kaleta\Core\Demo::account(); ?>
<?php if ($demo !== null && !$code): // the public demo (2.6): the shared account is filled in ?>
<p class="hlaska hlaska-ok"><?= e(t('This is the public demo of Kaleta. Sign in as %s with the password %s. Everything you change disappears at the next hourly reset.', $demo['user'], $demo['password'])) ?></p>
<?php endif ?>
<form method="post" action="<?= e($app->url('admin.php')) ?>">
<?= $app->session->csrfField() ?>
<?php if ($code && ($steps['code'] || $steps['backup'])): ?>
<input type="hidden" name="krok" value="kod">
<?php if ($steps['code']): ?>
<p><?= e(t('Enter the six-digit code from your authenticator app. No phone? Use one of your backup codes.')) ?></p>
<div class="login-pole"><label for="kod"><?= e(t('Verification code:')) ?></label> <input class="textpole" type="text" id="kod" name="kod" size="20" maxlength="12" inputmode="numeric" autocomplete="one-time-code" required autofocus></div>
<?php else: // 3.35: an account with a passkey and no app (or "passkey only") – the passkey below, or a backup code ?>
<p><?= e(t('Your account signs in with a passkey. Confirm with it below – or, if you lost it, enter one of your backup codes.')) ?></p>
<div class="login-pole"><label for="kod"><?= e(t('Backup code:')) ?></label> <input class="textpole" type="text" id="kod" name="kod" size="20" maxlength="12" autocomplete="one-time-code" required></div>
<?php endif ?>
<p><input class="tl" type="submit" value="<?= e(t('Verify code')) ?>"></p>
<?php elseif ($code): ?>
<p><?= e(t('Your account signs in with a passkey. Confirm the sign-in with it below. Lost it and have no backup codes? Ask an administrator of the site to reset your passkeys.')) ?></p>
<?php else: ?>
<div class="login-pole"><label for="user"><?= e(t('User name or e-mail')) ?></label> <input class="textpole" type="text" id="user" name="user" value="<?= e($demo !== null && $login === '' ? $demo['user'] : $login) ?>" size="20" maxlength="190" autocomplete="<?= $passkeyHost ? 'username webauthn' : 'username' ?>" required autofocus></div>
<div class="login-pole"><label for="password"><?= e(t('Password')) ?></label> <input class="textpole" type="password" id="password" name="password" size="20" autocomplete="current-password" required<?= $demo !== null ? ' value="' . e($demo['password']) . '"' : '' ?>></div>
<p><input class="tl" type="submit" value="<?= e(t('Přihlásit se')) ?>"></p>
<?php endif ?>
</form>
<?php if (!$code && $passkeyHost && $demo === null): // 3.35: the sign-in without a user name and password ?>
<form method="post" action="<?= e($app->url('admin.php')) ?>" data-klice="<?= e($app->url('admin.php')) ?>">
<?= $app->session->csrfField() ?>
<p class="login-nebo"><?= e(t('or')) ?></p>
<p><button class="tl" type="button" data-klic-bezhesla><?= e(t('Sign in with a passkey')) ?></button></p>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="smltxt" data-klic-nepodporuje hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p>
</form>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php elseif (!$passkeyHost && $canonicalAdmin !== '' && (!$code || !empty($keys))): ?>
<p class="smltxt"><?= e(t('Passkeys work only at the main address of the site:')) ?> <a href="<?= e($canonicalAdmin) ?>"><?= e($canonicalAdmin) ?></a></p>
<?php endif ?>
<?php if ($code && !empty($keys) && $passkeyHost): ?>
<form method="post" action="<?= e($app->url('admin.php')) ?>" data-klice="<?= e($app->url('admin.php')) ?>">
<?= $app->session->csrfField() ?>
<p class="login-nebo"><?= e(t('or')) ?></p>
<p><button class="tl" type="button" data-klic-prihlasit><?= e(t('Sign in with fingerprint or passkey')) ?></button></p>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="smltxt" data-klic-nepodporuje hidden><?= e(t('This browser does not support passkeys, or the site is not running on HTTPS.')) ?></p>
</form>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>
<?php if (!$code): ?>
<p class="login-odkaz"><a href="<?= e($app->url('admin.php?action=password')) ?>"><?= e(t('Forgotten your password?')) ?></a></p>
<?php endif ?>
<?= $app->view->render('admin/agency', ['app' => $app, 'withLogo' => true]) ?>
</div>
</body>
</html>
