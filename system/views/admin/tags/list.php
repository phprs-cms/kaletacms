<?php
/**
 * @var Kaleta\Admin\Modules\Tags $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $tags
 * @var array<string, mixed>|null $edit
 */
?>
<p class="smltxt"><?= e(t('Tags are created automatically as you write news. When you add a description to a tag, its page becomes a topic – an introduction to a field or project with all its news in one place.')) ?></p>
<?php if ($edit !== null): ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" id="uprav">
<?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $edit['ids'] ?>">
<fieldset>
<legend><?= e(t('Edit tag')) ?></legend>
<div class="radek"><label for="nazev"><?= e(t('Název')) ?></label><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($edit['nazev']) ?>" maxlength="80" required></div>
<div class="radek"><label for="popis"><?= e(t('Topic introduction')) ?></label><div><textarea class="textbox" id="popis" name="popis" rows="5" data-editor="maly"><?= e((string) $edit['popis']) ?></textarea><span class="napoveda"><?= e(t('Optional. Shown above the news list and as the description for search engines.')) ?></span></div></div>
<div class="radek"><label for="obrazek"><?= e(t('Topic image')) ?></label><input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($edit['obrazek']) ?>" maxlength="255" data-obrazek></div>
<details class="pokrocile">
<summary><?= e(t('Merge with another tag')) ?></summary>
<div class="radek"><label for="sloucit_do"><?= e(t('Merge into')) ?></label><div><select id="sloucit_do" name="sloucit_do">
	<option value="0"><?= e(t('– do not merge –')) ?></option>
<?php foreach ($tags as $s): if ((int) $s['ids'] !== (int) $edit['ids']): ?>
	<option value="<?= (int) $s['ids'] ?>"><?= e($s['nazev']) ?> (<?= (int) $s['pocet'] ?>)</option>
<?php endif; endforeach ?>
</select><span class="napoveda"><?= e(t('The news items get the selected tag, this one is removed and its address redirects. Useful for typos and duplicate spellings.')) ?></span></div></div>
</details>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Cancel')) ?></a></p>
</form>
<?php endif ?>
<?php if ($tags === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stitky', 'heading' => t('No tags yet.'), 'text' => t('Add them in the news editor in the Tags field. Here you can then merge them and turn them into topic pages.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Štítek')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Topic')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($tags as $s): ?>
<tr>
	<td><a href="<?= e($app->url('novinky/stitek/' . $s['seo_link'])) ?>" target="_blank" rel="noopener">#<?= e($s['nazev']) ?></a></td>
	<td class="cislo"><?= (int) $s['pocet'] ?></td>
	<td><?= trim((string) $s['popis']) !== '' ? '<span class="stitek stitek-vydano">' . e(t('has an intro')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($module->url('', ['edit' => $s['ids']])) ?>#uprav"><?= e(t('Edit')) ?></a>
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the tag? The news items stay, they just lose this tag.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
