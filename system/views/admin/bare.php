<?php
/**
 * A focused frame for one decision (3.11, UX310-8): the logo, the site name, the heading and the content – no menu, no
 * search, no draft-look bar. The OAuth consent screen uses it, the same way the sign-in page stands on its own. 3.13.1: with
 * image/editor.css too – the access choices are option cards and the labels are pills from there (without it they were bare
 * radio buttons and run-on text).
 *
 * @var Kaleta\Core\App $app
 * @var string $heading
 * @var string $content ready-made HTML
 */
?>
<!doctype html>
<html lang="<?= e(Kaleta\Core\Language::code()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/tema.js')) ?>?v=<?= e(KALETA_VERSION) ?>"></script>
<title><?= e($heading) ?> – Kaleta</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/kaleta-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/kaleta-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/kaleta-znacka-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
</head>
<body class="login">
<main class="login-karta login-karta-siroka">
<?= $app->view->render('admin/logo', ['height' => 32]) ?>
<p class="login-web"><?= e($app->settings()->get('site_name')) ?></p>
<h1><?= e($heading) ?></h1>
<?= $content ?>
</main>
</body>
</html>
