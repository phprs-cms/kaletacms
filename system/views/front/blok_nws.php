<?php
/**
 * Blok: přihlášení k odběru newsletteru.
 *
 * @var string $akce
 * @var string $pole   skrytá pole antispamu
 * @var string $zpet
 * @var string $zprava
 */
?>
<?php if ($zprava !== ''): ?>
<p class="newsletter-zprava" role="status"><?= e($zprava) ?></p>
<?php endif ?>
<form class="hledani newsletter" method="post" action="<?= e($akce) ?>">
	<?= $pole ?>
	<input type="hidden" name="zpet" value="<?= e($zpet) ?>">
	<input type="email" name="email" placeholder="<?= e(t('váš e-mail')) ?>" aria-label="<?= e(t('E-mail pro odběr novinek')) ?>" maxlength="190" required autocomplete="email">
	<button type="submit"><?= e(t('Odebírat')) ?></button>
</form>
