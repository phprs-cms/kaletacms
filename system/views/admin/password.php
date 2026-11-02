<?php
/**
 * Reset of a forgotten admin password (Admin\PasswordReset).
 *
 * @var Kaleta\Core\App $app
 * @var string $step zadost | heslo | neplatny
 * @var bool $sent
 * @var ?string $error
 * @var string $token
 * @var string $account
 * @var string $signedIn the user name signed in in this browser when it is another account ('' = nobody)
 */
?>
<!doctype html>
<html lang="<?= e(Kaleta\Core\Language::code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/tema.js')) ?>?v=<?= e(KALETA_VERSION) ?>"></script>
<title><?= e(t('Forgotten password')) ?> – Kaleta</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/kaleta-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/kaleta-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/kaleta-znacka-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
</head>
<body class="login">
<div class="login-karta">
<?= $app->view->render('admin/logo', ['height' => 36]) ?>
<h1><?= e(t($step === 'heslo' ? 'New password' : 'Forgotten password')) ?></h1>
<?php if ($error !== null): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e($error) ?></p>
<?php endif ?>
<?php if ($step === 'heslo'): ?>
<form method="post" action="<?= e($app->url('admin.php?action=password')) ?>">
<?= $app->session->csrfField() ?>
<input type="hidden" name="token" value="<?= e($token) ?>">
<?php // the account's name for password managers: without it a browser saves the new password to another name it knows ?>
<input type="text" name="user" value="<?= e($account) ?>" autocomplete="username" readonly hidden>
<?php if ($signedIn !== ''): ?>
<p class="hlaska hlaska-varovani" role="status"><?= e(t('This browser is signed in as %s. Setting the password signs it out; then sign in as %s.', $signedIn, $account)) ?></p>
<?php endif ?>
<p><?= e(t('Account: %s', $account)) ?></p>
<div class="login-pole"><label for="password"><?= e(t('New password')) ?></label> <input class="textpole" type="password" id="password" name="password" size="20" minlength="10" autocomplete="new-password" required autofocus></div>
<div class="login-pole"><label for="password2"><?= e(t('Heslo znovu')) ?></label> <input class="textpole" type="password" id="password2" name="password2" size="20" minlength="10" autocomplete="new-password" required></div>
<p class="smltxt"><?= e(t('At least 10 characters. Two-factor sign-in stays on.')) ?></p>
<p><input class="tl" type="submit" value="<?= e(t('Set password')) ?>"></p>
</form>
<?php elseif ($sent): ?>
<p class="hlaska hlaska-ok" role="status"><?= e(t('If such an account exists and has an e-mail address, we have sent it a link for setting a new password. It is valid for one hour.')) ?></p>
<?php elseif ($step === 'zadost'): ?>
<form method="post" action="<?= e($app->url('admin.php?action=password')) ?>">
<?= $app->session->csrfField() ?>
<p><?= e(t('Enter the user name or e-mail of your account. We will send you a link for setting a new password.')) ?></p>
<div class="login-pole"><label for="kdo"><?= e(t('User name or e-mail')) ?></label> <input class="textpole" type="text" id="kdo" name="kdo" size="20" maxlength="190" autocomplete="username" required autofocus></div>
<p><input class="tl" type="submit" value="<?= e(t('Send link')) ?>"></p>
</form>
<?php endif ?>
<p class="login-odkaz"><a href="<?= e($app->url($step === 'neplatny' ? 'admin.php?action=password' : 'admin.php')) ?>"><?= e(t($step === 'neplatny' ? 'Request a new link' : 'Back to sign-in')) ?></a></p>
</div>
</body>
</html>
