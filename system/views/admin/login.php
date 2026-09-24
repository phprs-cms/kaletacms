<?php
/**
 * Přihlášení do administrace.
 *
 * @var Kaleta\Core\App $app
 * @var string|null $chyba
 * @var string $login
 * @var bool $kod  druhý krok: heslo už sedí, čeká se na kód z ověřovací aplikace
 */
?>
<!doctype html>
<html lang="<?= e(Kaleta\Core\Jazyk::kod()) ?>">
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
<?= $app->view->render('admin/logo', ['vyska' => 36]) ?>
<h3><?= e(t('Přihlášení do administrace')) ?></h3>
<?php if ($chyba !== null): ?>
<p class="hlaska hlaska-chyba" role="alert"><?= e($chyba) ?></p>
<?php elseif ($app->request->get('heslo') === 'zmeneno'): ?>
<p class="hlaska hlaska-ok" role="status"><?= e(t('Heslo je změněno. Přihlaste se novým heslem.')) ?></p>
<?php endif ?>
<form method="post" action="<?= e($app->url('admin.php')) ?>">
<?= $app->session->csrfField() ?>
<?php if ($kod): ?>
<input type="hidden" name="krok" value="kod">
<p><?= e(t('Zadejte šestimístný kód z ověřovací aplikace. Nemáte telefon? Použijte jeden ze záložních kódů.')) ?></p>
<div class="login-pole"><label for="kod"><?= e(t('Ověřovací kód:')) ?></label> <input class="textpole" type="text" id="kod" name="kod" size="20" maxlength="12" inputmode="numeric" autocomplete="one-time-code" required autofocus></div>
<?php else: ?>
<div class="login-pole"><label for="user"><?= e(t('Přihlašovací jméno')) ?></label> <input class="textpole" type="text" id="user" name="user" value="<?= e($login) ?>" size="20" maxlength="40" autocomplete="username" required autofocus></div>
<div class="login-pole"><label for="password"><?= e(t('Heslo')) ?></label> <input class="textpole" type="password" id="password" name="password" size="20" autocomplete="current-password" required></div>
<?php endif ?>
<p><input class="tl" type="submit" value="<?= e(t($kod ? 'Ověřit kód' : 'Přihlásit se')) ?>"></p>
</form>
<?php if ($kod && !empty($klice)): ?>
<form method="post" action="<?= e($app->url('admin.php')) ?>" data-klice="<?= e($app->url('admin.php')) ?>">
<?= $app->session->csrfField() ?>
<p class="login-nebo"><?= e(t('nebo')) ?></p>
<p><button class="tl" type="button" data-klic-prihlasit><?= e(t('Přihlásit se otiskem prstu nebo klíčem')) ?></button></p>
<p class="hlaska hlaska-chyba" data-klic-chyba hidden role="alert"></p>
<p class="smltxt" data-klic-nepodporuje hidden><?= e(t('Tento prohlížeč přihlašovací klíče nepodporuje, nebo web neběží na HTTPS.')) ?></p>
</form>
<script src="<?= e($app->url('image/klice.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>
<?php if (!$kod): ?>
<p class="login-odkaz"><a href="<?= e($app->url('admin.php?akce=heslo')) ?>"><?= e(t('Zapomenuté heslo?')) ?></a></p>
<?php endif ?>
</div>
</body>
</html>
