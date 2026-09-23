<?php
/**
 * Hodnocení článku hvězdičkami.
 *
 * @var array<string, mixed> $clanek
 * @var string $akce
 * @var float $prumer
 * @var bool $hlasoval
 * @var string $pole
 */
?>
<section class="hodnoceni obal-uzky" id="hodnoceni" aria-label="<?= e(t('Hodnocení článku')) ?>">
<?php if ($clanek['mn_hodnoceni'] > 0): ?>
	<p class="hodnoceni-vysledek"><span class="hvezdy" style="--hodnota:<?= number_format($prumer, 2, '.', '') ?>" aria-hidden="true">★★★★★</span> <?= e(t('%s z 5', cislo($prumer))) ?> <small>(<?= e(t('hodnoceno %s×', (int) $clanek['mn_hodnoceni'])) ?>)</small></p>
<?php endif ?>
<?php if ($hlasoval): ?>
	<p><small><?= e(t('Děkujeme za váš hlas.')) ?></small></p>
<?php else: ?>
	<form method="post" action="<?= e($akce) ?>" class="hodnoceni-formular">
		<?= $pole ?>
		<input type="hidden" name="idc" value="<?= (int) $clanek['idc'] ?>">
		<span><?= e(t('Ohodnoťte článek:')) ?></span>
<?php for ($z = 1; $z <= 5; $z++): ?>
		<button type="submit" name="znamka" value="<?= $z ?>" aria-label="<?= e(t('%s z 5', $z)) ?>" title="<?= e(t('%s z 5', $z)) ?>">★</button>
<?php endfor ?>
	</form>
<?php endif ?>
</section>
