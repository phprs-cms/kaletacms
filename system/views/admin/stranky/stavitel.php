<?php
/**
 * Builder stránek na celou obrazovku. Celé ovládání skládá image/stavitel.js z dat níže; bez JavaScriptu se jen vysvětlí proč.
 * Plátno je skutečná stránka webu (?stavba=koncept&editor=1) – co editor ukazuje, je přesně to, co uvidí návštěvník.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, mixed> $data  stavba, schéma, knihovna, třídy, adresy akcí (Moduly\Stranky::akceStavitel)
 * @var string $titulek
 */
$verze = rawurlencode(KALETA_VERSION);
$jazyk = Kaleta\Core\Jazyk::kod();
?>
<!doctype html>
<html lang="<?= e($jazyk) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/tema.js')) ?>?v=<?= $verze ?>"></script>
<title><?= e(t('Builder')) ?>: <?= e($titulek) ?> – Kaleta</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/kaleta-znacka.svg">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= $verze ?>">
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= $verze ?>">
<link rel="stylesheet" href="<?= e($app->url('image/stavitel.css')) ?>?v=<?= $verze ?>">
</head>
<body class="stavitel-telo">
<?= $app->session->csrfField() ?>
<noscript><p class="hlaska hlaska-chyba"><?= e(t('Builder potřebuje JavaScript. Obsah stránky jde upravit i bez něj ve formuláři stránky.')) ?></p></noscript>
<div class="st-uzky" role="note"><p><strong><?= e(t('Builder potřebuje větší obrazovku.')) ?></strong> <?= e(t('Stránky skládejte na počítači nebo tabletu. Na telefonu upravíte texty tlačítkem „Upravit zde“ přímo na webu.')) ?></p>
	<p><a href="<?= e($app->url('admin.php')) ?>"><?= e(t('Zpět do administrace')) ?></a></p></div>
<div class="st" id="stavitel" hidden></div>
<script type="application/json" id="stavitel-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if ($jazyk !== 'cs' && is_file(KALETA_ROOT . '/image/jazyky/admin-' . $jazyk . '.js')): ?>
<script src="<?= e($app->url('image/jazyky/admin-' . $jazyk . '.js')) ?>?v=<?= $verze ?>"></script>
<?php endif ?>
<script src="<?= e($app->url('image/admin.js')) ?>?v=<?= $verze ?>" defer></script>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= $verze ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" data-max-soubor="<?= Kaleta\Core\Soubory::limit() ?>" data-max-soubor-text="<?= e(Kaleta\Core\Soubory::limitText()) ?>" data-max-strana="<?= Kaleta\Core\Obrazky::MAX_STRANA ?>" defer></script>
<script src="<?= e($app->url('image/stavitel.js')) ?>?v=<?= $verze ?>" defer></script>
</body>
</html>
