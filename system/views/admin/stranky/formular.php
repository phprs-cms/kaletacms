<?php
/**
 * @var MiroCMS\Admin\Moduly\Stranky $modul
 * @var string $csrf
 * @var array<string, mixed> $stranka
 * @var array<string, string> $chyby
 */
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>" data-koncept="stranka-<?= (int) $stranka['ids'] ?>">
<?= $csrf ?>
<input type="hidden" name="ids" value="<?= (int) $stranka['ids'] ?>">
<div class="radek pres-celou">
	<label for="titulek"><?= e(t('Název stránky')) ?></label>
	<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($stranka['titulek']) ?>" maxlength="200" required><?= $chyba('titulek') ?>
</div>
<?php if (($stranka['stavba'] ?? null) !== null): ?>
<div class="radek pres-celou">
	<p class="hlaska"><?= e(t('Obsah této stránky se skládá ve staviteli.')) ?> <a class="tl" href="<?= e($modul->url('stavitel', ['id' => (int) $stranka['ids']])) ?>"><?= e(t('Otevřít stavitel')) ?></a></p>
	<input type="hidden" name="text" value="<?= e($stranka['text']) ?>">
</div>
<?php else: ?>
<div class="radek pres-celou">
	<label for="text"><?= e(t('Obsah')) ?></label>
	<textarea class="textbox vysoky" id="text" name="text" rows="18" data-editor><?= e($stranka['text']) ?></textarea>
<?php if ($stranka['ids']): ?>
	<span class="napoveda"><?= e(t('Chcete stránku poskládat ze sekcí, sloupců a tlačítek?')) ?> <a href="<?= e($modul->url('stavitel', ['id' => (int) $stranka['ids']])) ?>"><?= e(t('Otevřít ve staviteli')) ?></a></span>
<?php endif ?>
</div>
<?php endif ?>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($stranka['seo_link']) ?>" maxlength="110" placeholder="<?= e(t('vytvoří se z názvu, např. o-nas')) ?>"><?= $chyba('seo_link') ?></div>
</div>
<div class="radek">
	<label for="popis"><?= e(t('Popis pro vyhledávače')) ?></label>
	<input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($stranka['popis']) ?>" maxlength="300">
</div>
<?= $app->view->render('admin/jazyk_pole', ['app' => $app, 'hodnota' => (string) ($stranka['jazyk'] ?? ''), 'prekladZ' => (int) ($stranka['preklad_z'] ?? 0), 'originaly' => $app->db()->pairs("SELECT ids, titulek FROM {stranky} WHERE jazyk = '' ORDER BY titulek"), 'napoveda' => '']) ?>
<div class="radek">
	<span class="popisek"><?= e(t('Zobrazení')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="zobrazit" value="1"<?= $stranka['zobrazit'] ? ' checked' : '' ?>> <?= e(t('Zveřejnit stránku')) ?></label><br>
		<label><input type="checkbox" name="v_menu" value="1"<?= $stranka['v_menu'] ? ' checked' : '' ?>> <?= e(t('Zobrazit v hlavní navigaci webu')) ?></label>
	</div>
</div>
<div class="radek">
	<label for="poradi"><?= e(t('Pořadí v navigaci')) ?></label>
	<input class="textpole" type="number" id="poradi" name="poradi" value="<?= (int) $stranka['poradi'] ?>" min="0" max="65535">
</div>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Uložit')) ?></button><?php if (($stranka['stavba'] ?? null) === null): ?> <button class="navigace" type="submit" name="po_ulozeni" value="stavitel"><?= e(t('Uložit a otevřít ve staviteli')) ?></button><?php endif ?></p>
</form>
<?php if (($stranka['stavba'] ?? null) !== null): ?>
<form class="vradku" method="post" action="<?= e($modul->url('stavba_text')) ?>" data-potvrdit="<?= e(t('Vrátit stránku k obyčejnému textu? Stavba zůstane ve verzích a můžete se k ní vrátit.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $stranka['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Vrátit stránku k textu')) ?></button></form>
<?php endif ?>
