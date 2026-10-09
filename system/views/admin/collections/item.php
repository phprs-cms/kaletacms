<?php
/**
 * Collection item form – fields according to the collection definition.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 * @var list<array{idr: int, datum: string, kdo: ?string}> $versions  earlier versions of the item (1.9)
 * @var list<array<string, mixed>> $noticeLog  the audit trail of a notice (2.11, Core\Notices), newest first
 * @var list<array{id: int, parent_id: ?int, name: string, visible: bool}> $categories  the collection's categories to tick (3.7)
 * @var list<int> $assigned  the categories the item is in
 */
use Kaleta\Core\Language;

$languages = Language::additional($app->settings());
?>
<form class="formular" method="post" action="<?= e($module->url('save_item')) ?>">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Název')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($p['nazev']) ?>" maxlength="200" required></div></div>
<?php foreach ($k['pole'] as $field): $h = (string) ($p['data'][$field['klic']] ?? ''); $id = 'pole-' . $field['klic']; $displayName = 'data[' . $field['klic'] . ']'; ?>
<div class="radek<?= $field['typ'] === 'html' ? ' pres-celou' : '' ?>">
	<label for="<?= e($id) ?>"><?= e($field['popisek']) ?></label>
	<div><?= match ($field['typ']) {
        'radky' => '<textarea class="textbox nizky" id="' . e($id) . '" name="' . e($displayName) . '" rows="4">' . e($h) . '</textarea>',
        'html' => '<textarea class="textbox" id="' . e($id) . '" name="' . e($displayName) . '" rows="10" data-editor>' . e($h) . '</textarea>',
        'obrazek' => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" data-obrazek>',
        'odkaz' => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" placeholder="' . e(t('https://… or /page')) . '">',
        'cislo' => '<input class="textpole" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" inputmode="decimal" size="12">',
        'datum' => '<input class="textpole" type="date" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '">',
        // a whole day is stored without a time; the input shows it at midnight, which saves back as the whole day (Collections::cleanDateTime)
        'termin' => '<input class="textpole" type="datetime-local" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h === '' ? '' : (strlen($h) === 10 ? $h . 'T00:00' : str_replace(' ', 'T', $h))) . '"> <span class="napoveda">' . e(t('00:00 = the whole day')) . '</span>',
        'soubor' => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" data-soubor>',
        'poloha' => '<input class="textpole" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="40" placeholder="50.0875, 14.4214" inputmode="decimal">',
        'volba' => '<select id="' . e($id) . '" name="' . e($displayName) . '"><option value="">–</option>' . implode('', array_map(fn (string $option): string => '<option value="' . e($option) . '"' . ($option === $h ? ' selected' : '') . '>' . e(t($option)) . '</option>',
            (array) ($field['moznosti'] ?? []))) . '</select>',
        'polozka' => '<select id="' . e($id) . '" name="' . e($displayName) . '"><option value="">–</option>' . implode('', array_map(fn (string $slug, string $name): string => '<option value="' . e($slug) . '"' . ($slug === $h ? ' selected' : '') . '>' . e($name) . '</option>',
            array_keys($choices = Kaleta\Builder\Collections::choices($app->db(), (string) ($field['kolekce'] ?? ''))), $choices)) . '</select>',
        default => '<input class="textpole siroke" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500">',
    } ?> <code class="napoveda">{{<?= e($field['klic']) ?>}}</code></div>
</div>
<?php endforeach ?>
<?php if ($categories !== []): ?>
<div class="radek"><span class="popisek" id="kategorie-popisek"><?= e(t('Categories')) ?></span><div class="volby kategorie-polozky" role="group" aria-labelledby="kategorie-popisek"><input type="hidden" name="kategorie_formular" value="1">
<?php foreach ($categories as $c): ?>
	<label<?= $c['parent_id'] !== null ? ' class="podkategorie"' : '' ?>><input type="checkbox" name="kategorie[]" value="<?= (int) $c['id'] ?>"<?= in_array($c['id'], $assigned, true) ? ' checked' : '' ?>> <?= e($c['name']) ?><?= $c['visible'] ? '' : ' <span class="napoveda">(' . e(t('hidden')) . ')</span>' ?></label>
<?php endforeach ?>
	<span class="napoveda"><?= e(t('The item is listed on the pages of the ticked categories (a top-level category page also lists the items of its subcategories).')) ?> <a href="<?= e($module->url('categories', ['id' => $k['idk']])) ?>"><?= e(t('Manage categories')) ?></a></span></div></div>
<?php endif ?>
<?php if ($k['detail']): ?>
<details class="pokrocile"<?= $p['popis'] !== '' || $p['seo_titulek'] !== '' || $p['obrazek'] !== '' || $p['noindex'] ? ' open' : '' ?>>
<summary><?= e(t('Search engines and sharing')) ?></summary>
<div class="radek"><label for="seo_titulek"><?= e(t('Search engine title')) ?></label><div><input class="textpole siroke" id="seo_titulek" name="seo_titulek" value="<?= e($p['seo_titulek']) ?>" maxlength="200" placeholder="<?= e(t('empty = the item name')) ?>"></div></div>
<div class="radek"><label for="popis"><?= e(t('Search engine description')) ?></label><div><input class="textpole siroke" id="popis" name="popis" value="<?= e($p['popis']) ?>" maxlength="300">
	<span class="napoveda"><?= e(t('One or two sentences for search results (up to 160 characters). Empty = the beginning of the first longer text field.')) ?></span></div></div>
<div class="radek"><label for="obrazek"><?= e(t('Sharing image')) ?></label><div><input class="textpole siroke" id="obrazek" name="obrazek" value="<?= e($p['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('empty = the first image field')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Shown when the link is shared on Facebook, LinkedIn or Teams (ideally 1200 × 630 px).')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Options')) ?></span><div class="volby"><label><input type="checkbox" name="noindex" value="1"<?= $p['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label>
	<span class="napoveda"><?= e(t('The item page stays reachable, but it is left out of search engines, the sitemap, llms.txt and site search.')) ?></span></div></div>
</details>
<?php endif ?>
<details class="pokrocile"<?= ($p['zverejnit_od'] ?? null) !== null || ($p['valid_until'] ?? null) !== null || ($p['review_by'] ?? null) !== null ? ' open' : '' ?>>
<summary><?= e(t('Address, order and visibility')) ?></summary>
<?php if ($k['detail']): ?>
<div class="radek"><label for="seo_link"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="seo_link" name="seo_link" value="<?= e($p['seo_link']) ?>" maxlength="150"><span class="napoveda">/<?= e($k['seo_link']) ?>/…</span></div></div>
<?php else: ?>
<input type="hidden" name="seo_link" value="<?= e($p['seo_link']) ?>">
<?php endif ?>
<div class="radek"><label for="poradi"><?= e(t('Pořadí')) ?></label><div><input class="textpole" type="number" id="poradi" name="poradi" value="<?= (int) $p['poradi'] ?>" min="-9999" max="9999"><span class="napoveda"><?= e(t('Smaller number = earlier in the list.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Display')) ?></span><div class="volby"><label><input type="checkbox" name="zobrazit" value="1"<?= $p['zobrazit'] ? ' checked' : '' ?>> <?= e(t('published on the site')) ?></label><br>
	<span class="napoveda" data-aktivni-kdyz="zobrazit="><label for="zverejnit_od"><?= e(t('Publish the hidden item automatically at:')) ?></label> <input class="textpole" type="datetime-local" id="zverejnit_od" name="zverejnit_od" value="<?= e(($p['zverejnit_od'] ?? null) ? date('Y-m-d\TH:i', strtotime($p['zverejnit_od'])) : '') ?>"></span></div></div>
<div class="radek">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textpole" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($p['valid_until'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('After this day the item hides itself. Empty = always.')) ?></span></div>
</div>
<div class="radek">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textpole" type="date" id="review_by" name="review_by" value="<?= e((string) ($p['review_by'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<?php if ($languages !== []): ?>
<div class="radek"><label for="jazyk"><?= e(t('Language')) ?></label><div><select id="jazyk" name="jazyk">
	<option value=""><?= e(Language::AVAILABLE[Language::defaults($app->settings())][0]) ?></option>
<?php foreach ($languages as $j): ?>
	<option value="<?= e($j) ?>"<?= $p['jazyk'] === $j ? ' selected' : '' ?>><?= e(Language::AVAILABLE[$j][0]) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endif ?>
</details>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save item')) ?>"> <a class="navigace" href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><?= e(t('Back')) ?></a><?php if ($p['idp'] > 0 && Kaleta\Builder\EmailSignature::isPeople($k)): ?>
	<a class="navigace" href="<?= e($module->url('signature', ['id' => (int) $k['idk'], 'item' => (int) $p['idp']])) ?>"><?= e(t('E-mail signature')) ?></a><?php endif ?></p>
</form>
<?php if (($versions ?? []) !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Item history (%s)', count($versions))) ?></summary>
<ul class="revize">
<?php foreach ($versions as $v): ?>
	<li><?= e(format_date($v['datum'], true)) ?><?= $v['kdo'] ? ' · ' . e($v['kdo']) : '' ?>
		<form class="vradku" method="post" action="<?= e($module->url('restore_item_version')) ?>" data-potvrdit="<?= e(t('Restore this version of the item? The current version stays in the history.')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><input type="hidden" name="idr" value="<?= (int) $v['idr'] ?>"><button class="navigace" type="submit"><?= e(t('Restore')) ?></button></form></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<?php if (($noticeLog ?? []) !== []): $actions = ['created' => t('Created'), 'changed' => t('Changed'), 'posted' => t('Posted'), 'taken_down' => t('Taken down')]; ?>
<details class="pokrocile" open>
<summary><?= e(t('Notice log (%s)', count($noticeLog))) ?></summary>
<p class="napoveda"><?= e(t('Every creation and change of the notice and the day it was posted and taken down. The log is append-only – nothing in it can be edited or deleted.')) ?>
<?php if ($app->auth()->isAdmin()): ?> <a href="<?= e($module->url('notice_log', ['id' => (int) $k['idk']])) ?>"><?= e(t('Download the whole log as CSV')) ?></a><?php endif ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Action')) ?></th><th scope="col"><?= e(t('By')) ?></th><th scope="col"><?= e(t('Changes')) ?></th></tr></thead>
<tbody>
<?php foreach ($noticeLog as $l): ?>
<tr><td><?= e(format_date($l['at'], true)) ?></td><td><?= e($actions[$l['action']] ?? $l['action']) ?></td><td><?= e($l['by']) ?></td><td><?= e(Kaleta\Core\Notices::changesText($l['fields'])) ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</details>
<?php endif ?>
