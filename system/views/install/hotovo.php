<?php
/**
 * @var string $base
 * @var bool $jizNainstalovano
 * @var bool $smazano instalátor se po sobě smazal sám
 */
?>
<!doctype html>
<html lang="<?= e($jazyk ?? 'cs') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('Instalace MiroCMS')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($base) ?>/image/mirocms-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($base) ?>/image/mirocms-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($base) ?>/image/mirocms-znacka-180.png">
<link rel="stylesheet" href="<?= e($base) ?>/image/install.css?v=<?= e(MIROCMS_VERSION) ?>">
</head>
<body>
<main class="instalator">
<header class="uvod">
	<div class="znacka"><?php $vyska = 40; $jenZnacka = false; require MIROCMS_SYSTEM . '/views/admin/logo.php'; ?></div>
<?php if ($jizNainstalovano): ?>
	<h1><?= e(t('MiroCMS je už nainstalován')) ?></h1>
	<p><?= e(t('Soubor config.php existuje, instalátor proto nic nemění.')) ?></p>
<?php else: ?>
	<h1><?= e(t('Hotovo, web běží')) ?></h1>
	<p><?= e(t('Databáze je připravena a konfigurace zapsána.')) ?></p>
<?php endif ?>
</header>
<?php if ($smazano): ?>
<p class="hlaska hlaska-ok"><?= e(t('Soubor install.php se z bezpečnostních důvodů smazal sám – nic dalšího dělat nemusíte.')) ?></p>
<?php else: ?>
<p class="hlaska <?= $jizNainstalovano ? 'hlaska-chyba' : 'hlaska-ok' ?>"><?= e(t('Z bezpečnostních důvodů teď ze serveru smažte soubor')) ?> <strong>install.php</strong>.</p>
<?php endif ?>
<div class="akce">
	<a class="tlacitko" href="<?= e($base) ?>/admin.php"><?= e(t('Přejít do administrace')) ?></a>
	<a class="tlacitko druhe" href="<?= e($base) ?>/"><?= e(t('Zobrazit web')) ?></a>
</div>
</main>
</body>
</html>
