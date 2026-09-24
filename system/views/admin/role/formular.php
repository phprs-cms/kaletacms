<?php
/**
 * @var Kaleta\Admin\Moduly\Role $modul
 * @var string $csrf
 * @var array<string, mixed> $role
 * @var array<string, string> $chyby
 * @var array<string, string> $sekce  ident => název
 * @var list<string> $vybrane
 * @var list<array<string, mixed>> $clenove
 */
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na role')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idr" value="<?= (int) $role['idr'] ?>">
<div class="radek">
	<label for="nazev"><?= e(t('Název role')) ?></label>
	<div><input class="textpole" type="text" id="nazev" name="nazev" value="<?= e($role['nazev']) ?>" maxlength="60" required placeholder="<?= e(t('např. Obchodník')) ?>"><?= $chyba('nazev') ?></div>
</div>
<div class="radek">
	<label for="popis"><?= e(t('Popis')) ?></label>
	<input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($role['popis']) ?>" maxlength="200">
</div>
<fieldset>
<legend><?= e(t('Novinky')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach (Kaleta\Admin\Moduly\Role::UROVNE as $hodnota => [$nazev, $popis]): ?>
	<label class="karta-volba"><input type="radio" name="uroven" value="<?= $hodnota ?>"<?= (int) $role['uroven'] === $hodnota ? ' checked' : '' ?>><strong><?= e(t($nazev)) ?></strong><span><?= e(t($popis)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
<fieldset>
<legend><?= e(t('Přístup do sekcí')) ?></legend>
<div class="volby">
<?php foreach ($sekce as $ident => $nazev): ?>
	<label><input type="checkbox" name="moduly[]" value="<?= e($ident) ?>"<?= in_array($ident, $vybrane, true) ? ' checked' : '' ?>> <?= e(t($nazev)) ?></label><br>
<?php endforeach ?>
	<?= $chyba('moduly') ?>
	<span class="napoveda"><?= e(t('Nastavení webu, uživatele a vzhled zůstávají správci.')) ?></span>
</div>
</fieldset>
<?php if ($clenove !== []): ?>
<p class="smltxt"><?= e(t('Uložení změní práva i těmto uživatelům:')) ?> <?= e(implode(', ', array_map(fn (array $u): string => $u['jmeno'] !== '' ? $u['jmeno'] : $u['user'], $clenove))) ?></p>
<?php endif ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"></p>
</form>
