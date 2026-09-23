<?php
/**
 * Systémový blok: anketa. Před hlasováním formulář, potom výsledky.
 *
 * @var array<string, mixed> $anketa
 * @var list<array<string, mixed>> $odpovedi
 * @var int $celkem
 * @var bool $hlasoval
 * @var string $akce
 * @var string $pole
 * @var string $zpet
 */
?>
<div class="anketa">
	<p class="anketa-otazka"><strong><?= e($anketa['otazka'] !== '' ? $anketa['otazka'] : $anketa['titulek']) ?></strong></p>
<?php if ($hlasoval): ?>
<?php foreach ($odpovedi as $o): $procent = $celkem > 0 ? (int) round($o['pocitadlo'] / $celkem * 100) : 0; ?>
	<div class="anketa-vysledek"><span><?= e($o['odpoved']) ?></span><span><?= $procent ?> %</span><i style="width:<?= $procent ?>%"></i></div>
<?php endforeach ?>
	<p><small><?= e(t('Hlasovalo')) ?>: <?= $celkem ?><?= $anketa['uzavrena'] ? ' · ' . e(t('anketa je uzavřena')) : '' ?></small></p>
<?php else: ?>
	<form method="post" action="<?= e($akce) ?>">
		<?= $pole ?>
		<input type="hidden" name="ida" value="<?= (int) $anketa['ida'] ?>">
		<input type="hidden" name="zpet" value="<?= e($zpet) ?>">
<?php foreach ($odpovedi as $o): ?>
		<label class="anketa-volba"><input type="radio" name="ido" value="<?= (int) $o['ido'] ?>" required> <?= e($o['odpoved']) ?></label>
<?php endforeach ?>
		<p><button type="submit"><?= e(t('Hlasovat')) ?></button></p>
	</form>
<?php endif ?>
</div>
