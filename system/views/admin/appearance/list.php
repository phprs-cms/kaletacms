<?php
/**
 * Site appearance: styles (ready-made sets of colours, fonts and corner rounding – DesignSystem::PRESETS) and the design system
 * in tabs (colours, dark mode, font and sizes, shapes, brand, import and export) with a live preview of the real home page.
 * Tabs are switched by image/admin.js (data-zalozky); without the script the whole form is visible at once.
 * The preview is handled by image/admin.js (data-vzhled): after every change it requests the token CSS (action nahled) and puts it into the iframe.
 * While a draft look exists, the iframe (?preview=vzhled) renders it (Front\Kernel::startSitePreview) and the bar says so.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Appearance $module
 * @var string $csrf
 * @var array<string, mixed> $ds
 * @var list<array{popis:string, pomer:float, ok:bool, min:float}> $contrasts
 * @var list<array{popis:string, pomer:float, ok:bool, min:float}> $darkContrasts readability of the dark mode palette (3.6)
 * @var array<string, string> $darkColors the dark mode palette with the derived primary and secondary (DesignSystem::darkColors)
 * @var array<string, array{nazev:string, popis:string, ds:array<string, mixed>}> $presets
 * @var array<string, string> $values
 * @var list<array{id: int, summary: string, created: string, author: ?string}> $versions earlier published looks (Core\Look)
 */
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\DesignSystem;

$px = fn (float $rem): string => (string) round($rem * 16);
$contrastsHtml = function (array $contrasts): string {
    $html = '';
    foreach ($contrasts as $k) {
        $html .= '<li class="' . ($k['ok'] ? 'ok' : 'spatne') . '"><span>' . e(t($k['popis'])) . '</span><strong>' . e(t('%s:1', format_number($k['pomer']))) . '</strong></li>';
    }

    return $html;
};
// the style the current appearance is based on (colours and heading font as in the style)
$current = null;
foreach ($presets as $key => $p) {
    if ($p['ds']['barvy']['primarni'] === $ds['barvy']['primarni'] && $p['ds']['barvy']['sekundarni'] === $ds['barvy']['sekundarni'] && $p['ds']['pismo_titulky'] === $ds['pismo_titulky']) {
        $current = $key;
        break;
    }
}
$tabs = ['styl' => 'Styl', 'barvy' => 'Colours', 'tmavy' => 'Dark mode', 'pismo' => 'Fonts and sizes', 'tvary' => 'Shapes', 'znacka' => 'Logo and icon', 'export' => 'Import and export'];
?>
<div class="vzhled" data-zalozky>
<div class="zalozky" role="tablist" aria-label="<?= e(t('Parts of the appearance')) ?>">
<?php foreach ($tabs as $key => $name): ?>
	<button type="button" role="tab" id="zalozka-<?= $key ?>" aria-controls="panel-<?= $key ?>" aria-selected="<?= $key === 'styl' ? 'true' : 'false' ?>"<?= $key === 'styl' ? '' : ' tabindex="-1"' ?>><?= e(t($name)) ?></button>
<?php endforeach ?>
</div>
<form class="formular vzhled-formular" method="post" action="<?= e($module->url('save')) ?>" data-vzhled data-nahled-url="<?= e($module->url('preview')) ?>">
<?= $csrf ?>

<div role="tabpanel" id="panel-styl" aria-labelledby="zalozka-styl">
<fieldset>
<legend><?= e(t('Styles')) ?></legend>
<p class="napoveda"><?= e(t('A style is a ready set of colours, fonts, sizes and corner radius. Pick one with a click and fine-tune it in the other tabs – the content of the site does not change.')) ?></p>
<div class="vzhled-predvolby">
<?php foreach ($presets as $key => $p): ?>
	<button type="button" class="vzhled-predvolba" data-predvolba="<?= e((string) json_encode($p['ds'], JSON_UNESCAPED_SLASHES)) ?>">
		<span class="vzhled-vzorky"><?php foreach (['primarni', 'sekundarni', 'text', 'plocha'] as $b): ?><i style="background:<?= e($p['ds']['barvy'][$b]) ?>"></i><?php endforeach ?></span>
		<strong style="font-family:<?= e(SiteIdentity::TITLE_FONTS[$p['ds']['pismo_titulky']][2]) ?>"><?= e(t($p['nazev'])) ?></strong>
		<small><?= e(t($p['popis'])) ?></small>
<?php if ($key === $current): ?>		<span class="stitek stitek-vydano"><?= e(t('current')) ?></span>
<?php endif ?>
	</button>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('You do not have to put a look for your brand together by hand: connect Claude and write, for example, “Set the look of the site to our brand – primary colour #0E6E6E, serif headings, subtle rounding”. It saves the colours and fonts to the design system, and you see the result here.')) ?> <a href="<?= e($app->url('admin.php?module=extensions#claude')) ?>"><?= e(t('How to connect Claude')) ?></a></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-barvy" aria-labelledby="zalozka-barvy">
<fieldset>
<legend><?= e(t('Colours')) ?></legend>
<div class="vzhled-barvy">
<?php foreach (DesignSystem::COLORS as $key => $name): ?>
	<label class="vzhled-barva">
		<input type="color" name="ds[barvy][<?= e($key) ?>]" value="<?= e($ds['barvy'][$key]) ?>">
		<span><?= e(t($name)) ?><small data-hex><?= e($ds['barvy'][$key]) ?></small></span>
	</label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Shades (muted text, lines, soft primary colour) and the button text colour are derived automatically.')) ?></p>
<h2 class="vzhled-podnadpis"><?= e(t('Readability')) ?></h2>
<ul class="vzhled-kontrasty" data-kontrasty><?= $contrastsHtml($contrasts) ?></ul>
<p class="napoveda"><?= e(t('Text should have a contrast of at least 4.5 : 1 (WCAG AA), a focus ring at least 3 : 1. Pairs marked in red will be hard to read for some visitors.')) ?></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-tmavy" aria-labelledby="zalozka-tmavy">
<fieldset>
<legend><?= e(t('Dark mode')) ?></legend>
<div class="volby">
	<label><input type="radio" name="dark_mode" value="vypnuto" data-prepni="tmave:0"<?= !in_array($values['dark_mode'], ['auto', 'tmavy'], true) ? ' checked' : '' ?>> <?= e(t('off – the site is always light')) ?></label><br>
	<label><input type="radio" name="dark_mode" value="auto" data-prepni="tmave:1"<?= $values['dark_mode'] === 'auto' ? ' checked' : '' ?>> <?= e(t('according to the visitor\'s device')) ?></label><br>
	<label><input type="radio" name="dark_mode" value="tmavy" data-prepni="tmave:1"<?= $values['dark_mode'] === 'tmavy' ? ' checked' : '' ?>> <?= e(t('always dark')) ?></label>
</div>
<div data-sekce="tmave"<?= !in_array($values['dark_mode'], ['auto', 'tmavy'], true) ? ' hidden' : '' ?>>
<div class="vzhled-barvy">
<?php foreach (DesignSystem::DARK_COLORS as $key => $name): $derived = in_array($key, DesignSystem::DARK_DERIVED, true); ?>
	<div class="vzhled-barva-obal">
	<label class="vzhled-barva">
		<input type="color" name="ds[barvy_tmave][<?= e($key) ?>]" value="<?= e($darkColors[$key]) ?>"<?= $derived ? ' data-tmava-barva="' . e($key) . '"' : '' ?>>
		<span><?= e(t($name)) ?><small data-hex><?= e($darkColors[$key]) ?></small></span>
	</label>
<?php if ($derived): // 3.6: derived from the light colour until the administrator picks one ?>
	<label class="vzhled-auto"><input type="checkbox" name="ds[tmave_auto][]" value="<?= e($key) ?>"<?= isset($ds['barvy_tmave'][$key]) ? '' : ' checked' ?>> <?= e(t('automatic')) ?></label>
<?php endif ?>
	</div>
<?php endforeach ?>
</div>
	<p class="napoveda"><?= e(t('Automatic primary and secondary colours keep the hue of the light ones, made lighter until links, buttons and focus rings are readable on the dark background. Pick a colour to set your own.')) ?></p>
	<p class="napoveda"><?= e(t('Check your logo: a dark logo on a transparent background would disappear on a dark site.')) ?></p>
	<h2 class="vzhled-podnadpis"><?= e(t('Readability in dark mode')) ?></h2>
	<ul class="vzhled-kontrasty" data-kontrasty-tmave><?= $contrastsHtml($darkContrasts) ?></ul>
</div>
<label class="vzhled-prepinac" data-sekce="tmave"<?= !in_array($values['dark_mode'], ['auto', 'tmavy'], true) ? ' hidden' : '' ?>><input type="checkbox" name="theme_switcher" value="1"<?= $values['theme_switcher'] === '1' ? ' checked' : '' ?>> <?= e(t('Switcher for visitors – in the header they choose light, dark or matching their device (the choice is remembered in their browser)')) ?></label>
</fieldset>
</div>

<div role="tabpanel" id="panel-pismo" aria-labelledby="zalozka-pismo">
<fieldset>
<legend><?= e(t('Font')) ?></legend>
<div class="radek">
	<label for="ds-pismo-titulky"><?= e(t('Headings')) ?></label>
	<select id="ds-pismo-titulky" name="ds[pismo_titulky]">
<?php foreach (SiteIdentity::TITLE_FONTS as $key => [$name, $description]): if ($key === 'vychozi') { continue; } ?>
		<option value="<?= e($key) ?>"<?= $ds['pismo_titulky'] === $key ? ' selected' : '' ?>><?= e(t($name) . ' – ' . t($description)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['vlastni_pisma'] as $i => $vp): ?>
		<option value="vlastni-<?= $i + 1 ?>"<?= $ds['pismo_titulky'] === 'vlastni-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['nazev'] . ' – ' . t('custom font')) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<label for="ds-pismo-text"><?= e(t('Text')) ?></label>
	<div><select id="ds-pismo-text" name="ds[pismo_text]">
<?php foreach (SiteIdentity::TEXT_FONTS as $key => [$name, $description]): if ($key === 'vychozi') { continue; } ?>
		<option value="<?= e($key) ?>"<?= $ds['pismo_text'] === $key ? ' selected' : '' ?>><?= e(t($name) . ' – ' . t($description)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['vlastni_pisma'] as $i => $vp): ?>
		<option value="vlastni-<?= $i + 1 ?>"<?= $ds['pismo_text'] === 'vlastni-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['nazev'] . ' – ' . t('custom font')) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('System fonts download nothing. A custom font is stored on your own server – it does not need visitor consent either.')) ?></span></div>
</div>
<details class="pokrocile"<?= $ds['vlastni_pisma'] !== [] ? ' open' : '' ?>>
<summary><?= e(t('Brand fonts (WOFF2)')) ?></summary>
<p class="napoveda"><?= e(t('Upload the font files (.woff2) to Media and paste their address here. One variable font file is enough, or a regular and a bold weight. After saving, choose the font above.')) ?></p>
<?php for ($i = 0; $i < 3; $i++): $vp = $ds['vlastni_pisma'][$i] ?? ['nazev' => '', 'soubor' => '', 'tucny' => '']; ?>
<div class="radek">
	<span class="popisek"><?= e(t('Font %d', $i + 1)) ?></span>
	<div class="pole-vedle">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][nazev]" value="<?= e($vp['nazev']) ?>" maxlength="40" placeholder="<?= e(t('name, e.g. Bricolage Grotesque')) ?>" aria-label="<?= e(t('Name of font %d', $i + 1)) ?>">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][soubor]" value="<?= e($vp['soubor']) ?>" placeholder="media/…/font.woff2" aria-label="<?= e(t('File of font %d', $i + 1)) ?>">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][tucny]" value="<?= e($vp['tucny']) ?>" placeholder="<?= e(t('bold weight (optional)')) ?>" aria-label="<?= e(t('Bold weight of font %d', $i + 1)) ?>">
	</div>
</div>
<?php endfor ?>
</details>
</fieldset>

<fieldset>
<legend><?= e(t('Sizes')) ?></legend>
<p class="napoveda"><?= e(t('Type and spacing grow smoothly with the window width – from phone to large monitor. Headings are multiples of the base font size.')) ?></p>
<div class="vzhled-mrizka">
	<label><span><?= e(t('Base font size on phones')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[zaklad_min]" value="<?= e($px($ds['zaklad_min'])) ?>" min="13" max="24" step="1"> px</span></label>
	<label><span><?= e(t('Base font size on monitors')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[zaklad_max]" value="<?= e($px($ds['zaklad_max'])) ?>" min="13" max="25" step="1"> px</span></label>
<?php foreach (['pomer_min' => 'Headings on phones', 'pomer_max' => 'Headings on monitors'] as $key => $labelText): ?>
	<label><span><?= e(t($labelText)) ?></span><select name="ds[<?= $key ?>]">
<?php foreach (DesignSystem::RATIOS as $value => $name): ?>
		<option value="<?= e($value) ?>"<?= abs((float) $value - $ds[$key]) < 0.001 ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></label>
<?php endforeach ?>
	<label><span><?= e(t('Content width')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[sirka]" value="<?= e($px($ds['sirka'])) ?>" min="640" max="1920" step="16"> px</span></label>
	<label><span><?= e(t('Text width (news and text pages)')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[sirka_textu]" value="<?= e($px($ds['sirka_textu'])) ?>" min="448" max="960" step="16"> px</span></label>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Typography styles')) ?></legend>
<p class="napoveda"><?= e(t('Named text styles you pick for an element in the builder (Style → Typography). A change here applies everywhere the style is used.')) ?></p>
<div class="tab-obal"><table class="vypis vzhled-typografie">
<thead><tr><th scope="col"><?= e(t('Styl')) ?></th><th scope="col"><?= e(t('Size (scale step)')) ?></th><th scope="col"><?= e(t('Weight')) ?></th></tr></thead>
<tbody>
<?php foreach (DesignSystem::TYPOGRAPHY as $key => [$name, $step, $weight, $lineHeight, $forHeadings]): $custom = $ds['typografie'][$key] ?? []; ?>
<tr>
	<th scope="row"><span style="font: var(--ka-typ-<?= e($key) ?>, inherit)<?= $key === 'nadtitulek' ? ';text-transform:uppercase;letter-spacing:.08em' : '' ?>"><?= e(t($name)) ?></span></th>
	<td><select name="ds[typografie][<?= e($key) ?>][krok]" aria-label="<?= e(t('Size: %s', t($name))) ?>">
<?php foreach (DesignSystem::STEPS as $k): ?>
		<option value="<?= e($k) ?>"<?= ($custom['krok'] ?? $step) === $k ? ' selected' : '' ?>><?= e($k === '0' ? t('0 – base font') : $k) ?></option>
<?php endforeach ?>
	</select></td>
	<td><select name="ds[typografie][<?= e($key) ?>][tloustka]" aria-label="<?= e(t('Weight: %s', t($name))) ?>">
<?php foreach (DesignSystem::FONT_WEIGHTS as $w => $weightName): ?>
		<option value="<?= $w ?>"<?= (int) ($custom['tloustka'] ?? $weight) === $w ? ' selected' : '' ?>><?= e(t($weightName)) ?></option>
<?php endforeach ?>
	</select></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
</fieldset>
</div>

<div role="tabpanel" id="panel-tvary" aria-labelledby="zalozka-tvary">
<fieldset>
<legend><?= e(t('Corner radius')) ?></legend>
<div class="vzhled-zaobleni">
<?php foreach (DesignSystem::RADIUS_NAMES as $key => $name): ?>
	<label><input type="radio" name="ds[zaobleni]" value="<?= e($key) ?>"<?= $ds['zaobleni'] === $key ? ' checked' : '' ?>><i style="border-radius:<?= e($key === 'plne' ? '999px' : DesignSystem::RADII[$key]) ?>"></i><?= e(t($name)) ?></label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Buttons, cards, images and form fields across the site get this radius.')) ?></p>
</fieldset>
</div>

<div role="tabpanel" id="panel-znacka" aria-labelledby="zalozka-znacka">
<fieldset>
<legend><?= e(t('Logo and icon')) ?></legend>
<div class="radek"><label for="logo"><?= e(t('Logo')) ?></label><div><input class="textpole siroke" type="text" id="logo" name="logo" value="<?= e($values['logo']) ?>" maxlength="255" placeholder="<?= e(t('without a logo, the site name is shown in the header')) ?>" data-obrazek><span class="napoveda"><?= e(t('Preferably a PNG with a transparent background, at least 120 px high.')) ?></span></div></div>
<div class="radek"><label for="favicon"><?= e(t('Site icon')) ?></label><div><input class="textpole siroke" type="text" id="favicon" name="favicon" value="<?= e($values['favicon']) ?>" maxlength="255" data-obrazek><span class="napoveda"><?= e(t('A small square image shown on the browser tab and in bookmarks. 256×256 px is enough.')) ?></span></div></div>
</fieldset>

</div>

<p class="napoveda"><?= e(t('Colours, fonts, sizes and shapes go to the draft look first – with changes of shared classes and menus. Visitors see them once you publish the look.')) ?></p>
<p class="tlacitka vzhled-ulozit"><input class="tl" type="submit" value="<?= e(t('Save appearance')) ?>"> <span class="napoveda" data-neulozeno hidden><?= e(t('The preview shows unsaved changes.')) ?></span></p>
</form>

<div role="tabpanel" id="panel-export" aria-labelledby="zalozka-export" class="vzhled-export">
<fieldset>
<legend><?= e(t('Design tokens (Figma, Tokens Studio)')) ?></legend>
<p class="napoveda"><?= e(t('Colours, fonts, sizes and typography styles in the W3C Design Tokens format (DTCG). A Kaleta export can be loaded back in full; from another tool the colours are taken.')) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('tokens')) ?>"><?= e(t('Download tokens (.tokens.json)')) ?></a></p>
<form class="navigace-radek" method="post" action="<?= e($module->url('tokens_import')) ?>" enctype="multipart/form-data" data-potvrdit="<?= e(t('Load tokens? They will overwrite the appearance settings above.')) ?>">
	<?= $csrf ?>
	<input type="file" name="tokeny" accept=".json,application/json" required aria-label="<?= e(t('Tokens file')) ?>">
	<button class="navigace" type="submit"><?= e(t('Load tokens')) ?></button>
</form>
</fieldset>
<fieldset>
<legend><?= e(t('Earlier looks')) ?></legend>
<?php if ($versions === []): ?>
<p class="napoveda"><?= e(t('Each time you publish the look, the one before is kept here (the last 20).')) ?></p>
<?php else: ?>
<ul class="vzhled-verze">
<?php foreach ($versions as $v): ?>
	<li><strong><?= e(format_date($v['created'], true)) ?></strong><?= $v['author'] !== null ? ' · ' . e((string) $v['author']) : '' ?><br><span class="napoveda"><?= e(t('before: %s', $v['summary'])) ?></span>
		<form class="vradku" method="post" action="<?= e($module->url('restore_look')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button class="navigace" type="submit"><?= e(t('Back to this look')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</fieldset>
</div>

<aside class="vzhled-nahled">
	<div class="vzhled-nahled-lista">
		<span><?= e(Kaleta\Core\Look::hasDraft($app->settings()) ? t('Preview: draft look') : t('Home page preview')) ?></span>
		<span class="vzhled-zarizeni" role="group" aria-label="<?= e(t('Zařízení')) ?>">
			<button type="button" data-zarizeni="pocitac" aria-pressed="true"><?= e(t('Desktop')) ?></button>
			<button type="button" data-zarizeni="mobil" aria-pressed="false"><?= e(t('Phone')) ?></button>
		</span>
	</div>
	<div class="vzhled-ramec" data-ramec><iframe src="<?= e($app->url('') . '?preview=vzhled') ?>" title="<?= e(t('Home page preview')) ?>" data-nahled></iframe></div>
</aside>
</div>
