<?php
/**
 * Prázdný stav výpisu: ikona, co tu bude, a zřetelná první akce.
 * Použití: <?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => '…', 'text' => '…', 'akce' => [$url, 'Popisek']]) ?>
 *
 * @var string $ikona    klíč do sady views/admin/ikony.php
 * @var string $nadpis   už přeložený text
 * @var string $text     už přeložený text (nepovinný)
 * @var array{0:string,1:string}|null $akce  [adresa, přeložený popisek] – nepovinné
 */
$svg = require __DIR__ . '/ikony.php';
?>
<div class="prazdny-stav">
	<div class="prazdny-stav-ikona"><?= $svg($ikona) ?></div>
	<p class="prazdny-stav-nadpis"><?= e($nadpis) ?></p>
<?php if (($text ?? '') !== ''): ?>
	<p class="prazdny-stav-text"><?= e($text) ?></p>
<?php endif ?>
<?php if (!empty($akce)): ?>
	<p><a class="tl" href="<?= e($akce[0]) ?>"><?= e($akce[1]) ?></a></p>
<?php endif ?>
</div>
