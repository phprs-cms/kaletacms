<?php
/**
 * Výzva pod ukázkou zamčeného článku.
 *
 * @var bool $predplatne  článek je jen pro předplatitele
 * @var bool $prihlasen   čtenář je přihlášený (ale nemá předplatné)
 * @var string $text      vlastní text výzvy z Nastavení
 * @var string $ucet      adresa přihlášení s návratem na článek
 * @var string $predplatneUrl  kde získat předplatné (prázdné = web to neříká)
 * @var bool $registrace
 * @var array{precteno:int, limit:int, vycerpano:bool}|null $zdarma  stav měkkého paywallu
 */
?>
<aside class="mc-zamek" aria-label="<?= e(t('Zamčený obsah')) ?>">
	<strong><?= e(t($predplatne ? 'Tento článek je pro předplatitele' : 'Pokračování je pro přihlášené čtenáře')) ?></strong>
	<p><?= e($text !== '' ? $text : t($predplatne ? 'Předplatné podporuje naši redakci. Děkujeme, že nás čtete.' : 'Registrace je zdarma a zabere minutu.')) ?></p>
<?php if ($zdarma !== null && $zdarma['vycerpano']): ?>
	<p><small><?= e(t('Tento měsíc jste už přečetli všech %s článků zdarma.', $zdarma['limit'])) ?></small></p>
<?php endif ?>
<?php if ($predplatne && ($predplatneUrl ?? '') !== ''): ?>
	<p><a class="mc-tl" href="<?= e($predplatneUrl) ?>"><?= e(t('Získat předplatné')) ?></a><?php if (!$prihlasen): ?> <a class="mc-tl mc-tl-vedlejsi" href="<?= e($ucet) ?>"><?= e(t('Už předplatné mám – přihlásit se')) ?></a><?php endif ?></p>
<?php elseif (!$prihlasen): ?>
	<p><a class="mc-tl" href="<?= e($ucet) ?>"><?= e(t('Přihlásit se')) ?></a><?php if ($registrace): ?> <a class="mc-tl mc-tl-vedlejsi" href="<?= e($ucet) ?>"><?= e(t('Zaregistrovat se')) ?></a><?php endif ?></p>
<?php endif ?>
</aside>
