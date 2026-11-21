<?php
/**
 * Empty state of a list: an icon, what will be here, and a clear first action.
 * Usage: <?= $app->view->render('admin/empty', ['icon' => 'clanek', 'heading' => t('…'), 'text' => t('…'), 'action' => [$url, t('Label')]]) ?>
 *
 * @var string $icon    key into the set in views/admin/icons.php
 * @var string $heading   already translated text
 * @var string $text     already translated text (optional)
 * @var array{0:string,1:string}|null $action  [url, translated label] – optional
 */
$svg = require __DIR__ . '/icons.php';
?>
<div class="prazdny-stav">
	<div class="prazdny-stav-ikona"><?= $svg($icon) ?></div>
	<p class="prazdny-stav-nadpis"><?= e($heading) ?></p>
<?php if (($text ?? '') !== ''): ?>
	<p class="prazdny-stav-text"><?= e($text) ?></p>
<?php endif ?>
<?php if (!empty($action)): ?>
	<p><a class="tl" href="<?= e($action[0]) ?>"><?= e($action[1]) ?></a></p>
<?php endif ?>
</div>
