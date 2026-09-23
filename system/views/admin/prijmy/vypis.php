<?php
/**
 * Příjmy: rozcestník zdrojů, ze kterých web žije (Moduly\Prijmy).
 *
 * @var list<array{nazev:string, zapnuto:bool, cislo:int, popisek:string, text:string, odkaz:array{0:string,1:string}, poznamka:string}> $karty
 */
?>
<p class="hlaska"><?= e(t('Čtyři způsoby, jak může web vydělávat nebo si držet čtenáře. Každý se zapíná a nastavuje jinde – tady je vidíte pohromadě.')) ?></p>
<div class="prijmy">
<?php foreach ($karty as $k): ?>
	<section class="prijmy-karta">
		<h3><?= e(t($k['nazev'])) ?> <span class="stitek <?= $k['zapnuto'] ? 'stitek-vydano' : 'stitek-koncept' ?>"><?= e(t($k['zapnuto'] ? 'zapnuté' : 'vypnuté')) ?></span></h3>
<?php if ($k['zapnuto']): ?>
		<p class="prijmy-cislo"><strong><?= e(cislo($k['cislo'], 0)) ?></strong> <span><?= e(t($k['popisek'])) ?></span></p>
<?php endif ?>
		<p><?= e(t($k['text'])) ?></p>
<?php if ($k['poznamka'] !== ''): ?>
		<p class="smltxt"><?= e($k['poznamka']) ?></p>
<?php endif ?>
		<p><a class="navigace" href="<?= e($k['odkaz'][0]) ?>"><?= e(t($k['odkaz'][1])) ?></a></p>
	</section>
<?php endforeach ?>
</div>
