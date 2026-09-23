<?php
/**
 * Průběh rozesílky. Dokud zbývají příjemci, formulář se sám odesílá po dávkách.
 *
 * @var MiroCMS\Admin\Moduly\NewsletterAdmin $modul
 * @var string $csrf
 * @var array<string, mixed> $vydani
 * @var int $zbyva
 */
?>
<?php if ($vydani['odeslano'] !== null): ?>
<p class="hlaska hlaska-ok"><?= e(t('Hotovo. Newsletter „%s“ odešel %s odběratelům.', $vydani['predmet'], (int) $vydani['pocet'])) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na newsletter')) ?></a></p>
<?php else: ?>
<p class="hlaska" role="status"><?= e(t('Rozesílám „%s“: odesláno %s, zbývá %s. Nechte stránku otevřenou.', $vydani['predmet'], (int) $vydani['pocet'], $zbyva)) ?></p>
<form method="post" action="<?= e($modul->url('rozeslat', ['id' => $vydani['idn']])) ?>" id="davka" data-auto-odeslat="1200">
	<?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Pokračovat')) ?></button></p>
</form>

<?php endif ?>
