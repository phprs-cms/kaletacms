<?php
/**
 * Collection definition: name, slug, item pages and fields.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, string> $otherCollections other collections for item links (2.10): address => name
 */
use Kaleta\Builder\CollectionSchema;
use Kaleta\Builder\Collections;

$field = array_merge($k['pole'], array_fill(0, 3, ['klic' => '', 'popisek' => '', 'typ' => 'text']));
$schema = CollectionSchema::of($k) ?? ['typ' => '', 'pole' => [], 'mena' => ''];
// every property once, with the types that have it (the form shows only the rows of the chosen type)
$properties = [];
foreach (CollectionSchema::TYPES as $type => [, $props]) {
    foreach ($props as $property => $label) {
        $properties[$property] ??= [$label, []];
        $properties[$property][1][] = $type;
    }
}
?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" data-prepinac="schema[typ]">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Collection name')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($k['nazev']) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Testimonials, Team, Products')) ?>"></div></div>
<div class="radek"><label for="seo_link"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="seo_link" name="seo_link" value="<?= e($k['seo_link']) ?>" maxlength="110"><span class="napoveda"><?= e(t('From the name if left empty. Item pages will then be at /address/item-name.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Item pages')) ?></span><div class="volby"><label><input type="checkbox" name="detail" value="1"<?= $k['detail'] ? ' checked' : '' ?>> <?= e(t('each item has its own page (detail)')) ?></label>
<span class="napoveda"><?= e(t('Design the detail page in the builder (Detail template). Without a detail page, items are just cards in the list.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Admin menu')) ?></span><div class="volby"><label><input type="checkbox" name="admin_menu" value="1"<?= ($k['admin_menu'] ?? 1) ? ' checked' : '' ?>> <?= e(t('Show in the admin menu')) ?></label>
<span class="napoveda"><?= e(t('A direct link to its items in the left menu, under Collections. The menu shows at most %d collections; the others stay under Collections.', Kaleta\Admin\Modules\Collections::MENU_LIMIT)) ?></span></div></div>
<div class="radek"><label for="hidden_redirect"><?= e(t('Hidden items redirect to')) ?></label><div><input class="textpole" id="hidden_redirect" name="hidden_redirect" value="<?= e((string) ($k['hidden_redirect'] ?? '')) ?>" maxlength="255" placeholder="/<?= e($k['seo_link'] !== '' ? $k['seo_link'] : 'team') ?>">
<span class="napoveda"><?= e(t('When an item is hidden or deleted – a person who left, a product no longer sold – its page leads here (301) instead of “page not found”. Empty = page not found.')) ?></span></div></div>
<fieldset>
<legend><?= e(t('Item fields')) ?></legend>
<p class="napoveda"><?= e(t('Every item always has a name. Add the fields you need – in the builder you insert them with a {{key}} tag. An empty label removes the field; the key of a saved field never changes.')) ?></p>
<p class="napoveda"><?= e(t('An item of another collection links two collections – a person to a branch, a reference to a service: choose the type and the linked collection. {{key}} shows the linked item\'s name, {{key_url}} its page. On the page of the linked item, a Collection list filtered by the field with the value {{seo}} lists everything linked to it.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Label')) ?></th><th scope="col"><?= e(t('Typ')) ?></th><th scope="col"><?= e(t('Linked collection or options')) ?></th><th scope="col"><?= e(t('Značka')) ?></th></tr></thead>
<tbody>
<?php foreach ($field as $i => $p): ?>
<tr>
	<td><input class="textpole" name="pole[<?= $i ?>][popisek]" value="<?= e($p['popisek']) ?>" maxlength="80" aria-label="<?= e(t('Label')) ?>"><input type="hidden" name="pole[<?= $i ?>][klic]" value="<?= e($p['klic']) ?>"></td>
	<td><select name="pole[<?= $i ?>][typ]" aria-label="<?= e(t('Typ')) ?>">
<?php foreach (Collections::FIELD_TYPES as $type => $name): ?>
		<option value="<?= e($type) ?>"<?= $p['typ'] === $type ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></td>
	<td><select name="pole[<?= $i ?>][kolekce]" aria-label="<?= e(t('Linked collection')) ?>"><option value="">–</option>
<?php foreach ($otherCollections as $slug => $name): ?>
		<option value="<?= e($slug) ?>"<?= ($p['kolekce'] ?? '') === $slug ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select>
	<textarea class="textbox nizky" name="pole[<?= $i ?>][moznosti]" rows="2" aria-label="<?= e(t('Options of a choice (one per line)')) ?>" placeholder="<?= e(t('Options of a choice (one per line)')) ?>"><?= e(implode("\n", (array) ($p['moznosti'] ?? []))) ?></textarea></td>
	<td><?= $p['klic'] !== '' ? '<code>{{' . e($p['klic']) . '}}</code>' . match ($p['typ']) {
        'polozka' => ' <code>{{' . e($p['klic']) . '_url}}</code> <code>{{' . e($p['klic']) . '_seo}}</code>',
        'termin' => ' <code>{{' . e($p['klic']) . '_iso}}</code>',
        'soubor' => ' <code>{{' . e($p['klic']) . '_name}}</code>',
        default => '',
    } : '<span class="napoveda">' . e(t('created from the label')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<?php if ($k['pole'] !== []): ?>
<details class="pokrocile"<?= $schema['typ'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Structured data for search engines')) ?></summary>
<p class="napoveda"><?= e(t('Tell search engines what the items are. Item pages then carry the schema.org data; the name, address, description and image come from the item itself. Save new fields first – then you can pick them here.')) ?></p>
<div class="radek"><label for="schema-typ"><?= e(t('Items are')) ?></label><div><select id="schema-typ" name="schema[typ]">
	<option value=""><?= e(t('nothing specific (no structured data)')) ?></option>
<?php foreach (CollectionSchema::TYPES as $type => [$label]): ?>
	<option value="<?= e($type) ?>"<?= $schema['typ'] === $type ? ' selected' : '' ?>><?= e(t($label)) ?> (<?= e($type) ?>)</option>
<?php endforeach ?>
</select></div></div>
<?php foreach ($properties as $property => [$label, $types]): ?>
<div class="radek" data-pro="<?= e(implode(' ', $types)) ?>"><label for="schema-<?= e($property) ?>"><?= e(t($label)) ?></label><div><select id="schema-<?= e($property) ?>" name="schema[pole][<?= e($property) ?>]">
	<option value=""><?= e(t('– none –')) ?></option>
<?php foreach ($k['pole'] as $f): ?>
	<option value="<?= e($f['klic']) ?>"<?= ($schema['pole'][$property] ?? '') === $f['klic'] ? ' selected' : '' ?>><?= e($f['popisek']) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endforeach ?>
<div class="radek" data-pro="Service Product Event JobPosting"><label for="schema-mena"><?= e(t('Currency of the price')) ?></label><div><input class="textpole" id="schema-mena" name="schema[mena]" value="<?= e($schema['mena']) ?>" maxlength="3" size="5" placeholder="EUR">
	<span class="napoveda"><?= e(t('A three-letter code (EUR, CZK, USD). Without it the price is not passed on.')) ?></span></div></div>
</details>
<?php endif ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save collection')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
<?php if ($k['idk'] > 0): ?>
<div class="navigace-radek akce-dole"><form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the collection with all its items? Lists on the site will disappear.')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete collection')) ?></button></form></div>
<?php endif ?>
