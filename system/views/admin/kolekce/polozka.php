<?php
/**
 * Formulář položky kolekce – pole podle definice kolekce.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Kolekce $modul
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 */
use Kaleta\Core\Jazyk;

$jazyky = Jazyk::dalsi($app->settings());
?>
<form class="formular" method="post" action="<?= e($modul->url('uloz_polozku')) ?>">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Název')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($p['nazev']) ?>" maxlength="200" required></div></div>
<?php foreach ($k['pole'] as $pole): $h = (string) ($p['data'][$pole['klic']] ?? ''); $id = 'pole-' . $pole['klic']; $jmeno = 'data[' . $pole['klic'] . ']'; ?>
<div class="radek<?= $pole['typ'] === 'html' ? ' pres-celou' : '' ?>">
	<label for="<?= e($id) ?>"><?= e($pole['popisek']) ?></label>
	<div><?= match ($pole['typ']) {
        'radky' => '<textarea class="textbox nizky" id="' . e($id) . '" name="' . e($jmeno) . '" rows="4">' . e($h) . '</textarea>',
        'html' => '<textarea class="textbox" id="' . e($id) . '" name="' . e($jmeno) . '" rows="10" data-editor>' . e($h) . '</textarea>',
        'obrazek' => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($jmeno) . '" value="' . e($h) . '" maxlength="500" data-obrazek>',
        'odkaz' => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($jmeno) . '" value="' . e($h) . '" maxlength="500" placeholder="https://… ' . e(t('nebo')) . ' /stranka">',
        'cislo' => '<input class="textpole" id="' . e($id) . '" name="' . e($jmeno) . '" value="' . e($h) . '" inputmode="decimal" size="12">',
        'datum' => '<input class="textpole" type="date" id="' . e($id) . '" name="' . e($jmeno) . '" value="' . e($h) . '">',
        default => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($jmeno) . '" value="' . e($h) . '" maxlength="500">',
    } ?> <code class="napoveda">{{<?= e($pole['klic']) ?>}}</code></div>
</div>
<?php endforeach ?>
<details class="pokrocile">
<summary><?= e(t('Adresa, pořadí a zobrazení')) ?></summary>
<?php if ($k['detail']): ?>
<div class="radek"><label for="seo_link"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="seo_link" name="seo_link" value="<?= e($p['seo_link']) ?>" maxlength="150"><span class="napoveda">/<?= e($k['seo_link']) ?>/…</span></div></div>
<?php else: ?>
<input type="hidden" name="seo_link" value="<?= e($p['seo_link']) ?>">
<?php endif ?>
<div class="radek"><label for="poradi"><?= e(t('Pořadí')) ?></label><div><input class="textpole" type="number" id="poradi" name="poradi" value="<?= (int) $p['poradi'] ?>" min="-9999" max="9999"><span class="napoveda"><?= e(t('Menší číslo = dřív ve výpisu.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Zobrazení')) ?></span><div class="volby"><label><input type="checkbox" name="zobrazit" value="1"<?= $p['zobrazit'] ? ' checked' : '' ?>> <?= e(t('zveřejněná na webu')) ?></label></div></div>
<?php if ($jazyky !== []): ?>
<div class="radek"><label for="jazyk"><?= e(t('Jazyk')) ?></label><div><select id="jazyk" name="jazyk">
	<option value=""><?= e(Jazyk::DOSTUPNE[Jazyk::vychozi($app->settings())][0]) ?></option>
<?php foreach ($jazyky as $j): ?>
	<option value="<?= e($j) ?>"<?= $p['jazyk'] === $j ? ' selected' : '' ?>><?= e(Jazyk::DOSTUPNE[$j][0]) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endif ?>
</details>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit položku')) ?>"> <a class="navigace" href="<?= e($modul->url('polozky', ['id' => $k['idk']])) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
