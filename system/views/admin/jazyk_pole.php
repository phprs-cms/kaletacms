<?php
/**
 * Řádek formuláře "Jazyková verze" - jen když má web další jazyky (rozšíření Jazykové verze).
 *
 * @var Kaleta\Core\App $app
 * @var string $hodnota  aktuální hodnota sloupce jazyk ('' = výchozí jazyk)
 * @var string $napoveda
 * @var array<int, string> $originaly  položky ve výchozím jazyce, ze kterých jde vybrat originál překladu
 * @var int $prekladZ
 */
use Kaleta\Core\Jazyk;

$dalsi = Jazyk::dalsi($app->settings());
if ($dalsi === []) {
    return;
}
?>
<div class="radek">
	<label for="jazyk"><?= e(t('Jazyková verze')) ?></label>
	<div><select id="jazyk" name="jazyk">
		<option value=""><?= e(Jazyk::DOSTUPNE[Jazyk::vychozi($app->settings())][0]) ?> (<?= e(t('výchozí')) ?>)</option>
<?php foreach ($dalsi as $kod): ?>
		<option value="<?= e($kod) ?>"<?= $hodnota === $kod ? ' selected' : '' ?>><?= e(Jazyk::DOSTUPNE[$kod][0]) ?> – /<?= e($kod) ?>/</option>
<?php endforeach ?>
	</select>
<?php if (($napoveda ?? '') !== ''): ?>
	<span class="napoveda"><?= e($napoveda) ?></span>
<?php endif ?>
	</div>
</div>
<?php if (($originaly ?? []) !== []): ?>
<div class="radek">
	<label for="preklad_z"><?= e(t('Je překladem')) ?></label>
	<div><select id="preklad_z" name="preklad_z">
		<option value="0"><?= e(t('– není překlad –')) ?></option>
<?php foreach ($originaly as $idOriginalu => $nazevOriginalu): ?>
		<option value="<?= (int) $idOriginalu ?>"<?= (int) ($prekladZ ?? 0) === (int) $idOriginalu ? ' selected' : '' ?>><?= e($nazevOriginalu) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Vyplňte u položky v jiné jazykové verzi: přepínač jazyků pak vede přímo na protějšek a vyhledávače dostanou značky hreflang.')) ?></span></div>
</div>
<?php endif ?>
