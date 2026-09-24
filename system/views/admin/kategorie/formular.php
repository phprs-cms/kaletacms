<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Kategorie $modul
 * @var string $csrf
 * @var array<string, mixed> $kategorie
 * @var array<string, string> $chyby
 */
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idt" value="<?= (int) $kategorie['idt'] ?>">
<div class="radek">
	<label for="nazev"><?= e(t('Název kategorie')) ?></label>
	<div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($kategorie['nazev']) ?>" maxlength="100" required><?= $chyba('nazev') ?></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($kategorie['seo_link']) ?>" maxlength="110" placeholder="<?= e(t('vytvoří se z názvu')) ?>">
	<span class="napoveda"><?= e(t('Část adresy za /novinky/kategorie/.')) ?></span></div>
</div>
<div class="radek">
	<label for="popis"><?= e(t('Popis')) ?></label>
	<div><textarea class="textbox" id="popis" name="popis" rows="4"><?= e($kategorie['popis']) ?></textarea>
	<span class="napoveda"><?= e(t('Zobrazí se nad výpisem novinek kategorie a použije se jako popis pro vyhledávače.')) ?></span></div>
</div>
<div class="radek">
	<label for="hodnost"><?= e(t('Pořadí')) ?></label>
	<div><input class="textpole" type="number" id="hodnost" name="hodnost" value="<?= (int) $kategorie['hodnost'] ?>" min="0" max="65535">
	<span class="napoveda"><?= e(t('Vyšší číslo = výš v seznamu.')) ?></span></div>
</div>
<?= $app->view->render('admin/jazyk_pole', ['app' => $app, 'hodnota' => (string) ($kategorie['jazyk'] ?? ''), 'prekladZ' => (int) ($kategorie['preklad_z'] ?? 0), 'originaly' => $app->db()->pairs("SELECT idt, nazev FROM {topic} WHERE jazyk = '' ORDER BY nazev"), 'napoveda' => t('Novinky v kategorii patří do této jazykové verze webu.')]) ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($kategorie['idt'] ? 'Uložit' : 'Přidat')) ?>"></p>
</form>
