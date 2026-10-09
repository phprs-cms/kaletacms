<?php
/**
 * WordPress import, step 2: preview – what the file contains, what will not be converted, and the import options. Nothing has been written to the database yet.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  import state (Core\WpImport::newState)
 * @var list<string> $languages  language versions of the site, the first one is the default
 * @var list<array{idt:int, nazev:string, jazyk:string}> $categories  news categories
 * @var bool $redirectsEnabled
 */
$p = $state['prehled'];
$options = $state['volby'];
$statuses = ['publish' => 'vydané', 'future' => 'naplánované', 'draft' => 'koncepty', 'pending' => 'pending review', 'private' => 'soukromé', 'trash' => 'in trash', 'auto-draft' => 'auto-drafts', 'inherit' => 'revize'];
$byStatus = function (array $counts) use ($statuses): string {
    $parts = [];
    foreach ($counts as $s => $count) {
        $parts[] = (int) $count . ' ' . t($statuses[$s] ?? 'jiné');
    }

    return implode(', ', $parts);
};
$converts = fn (array $counts): int => array_sum(array_intersect_key($counts, ['publish' => 1, 'future' => 1, 'draft' => 1, 'pending' => 1]));
?>
<?= $app->view->render('admin/transfer/steps', ['step' => 2]) ?>
<p><?= e(t('File %s – site “%s” (%s). Nothing has been imported yet; this is only an overview of what the file contains.', $state['soubor'], $state['web']['nazev'], $state['web']['adresa'])) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['clanky']) ?></strong><span><?= e(t('Posts')) ?><?= $p['clanky'] !== [] ? ': ' . e($byStatus($p['clanky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['stranky']) ?></strong><span><?= e(t('Pages')) ?><?= $p['stranky'] !== [] ? ': ' . e($byStatus($p['stranky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['rubriky'] ?></strong><span><?= e(t('Categories (those with posts are created)')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['stitky'] ?></strong><span><?= e(t('Tags')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['prilohy'] ?></strong><span><?= e(t('Files in the media library')) ?> · <?= e(t('images in texts: %s', (int) $p['obrazky'])) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['autori'] ?></strong><span><?= e(t('Authors')) ?></span></div>
<?php if (($p['menu'] ?? []) !== []): ?>
	<div class="dlazdice-polozka"><strong><?= count($p['menu']) ?></strong><span><?= e(t('Menus')) ?></span></div>
<?php endif ?>
</div>
<?php if (($p['menu'] ?? []) !== []): ?>
<?php $menuPlaces = Kaleta\Core\WpImport::previewMenus($state, $languages[0]); // 3.9: the locations within each language version ?>
<p><?= e(t('Menus go to the draft look with links to the new addresses: the navigation on the site changes only when you publish the look.')) ?></p>
<ul>
<?php foreach ($p['menu'] as $slug => $m): ?>
<?php [$menuPlace, $menuLanguage] = $menuPlaces[(string) $slug] ?? ['', '']; ?>
	<li><?= e(t('Menu “%s” (%s items): %s', (string) $m['nazev'], (int) $m['polozky'], $menuPlace !== '' ? t(Kaleta\Core\Menu::LOCATIONS[$menuPlace]) : t('left out – this site has a main and a footer menu'))) ?><?= $menuLanguage !== '' ? ' (' . e(Kaleta\Core\Language::AVAILABLE[$menuLanguage][0] ?? $menuLanguage) . ')' : '' ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php if (Kaleta\Core\WpImport::isMultilingual($state)): // 3.9: Polylang or WPML ?>
<?php $found = (array) $state['jazyky']['nalezeno']; $missing = array_diff(array_map('strval', array_keys($found)), $languages); ?>
<p><?= e(t('Multilingual site (%s): %s.', $state['jazyky']['plugin'] === 'wpml' ? 'WPML' : ($state['jazyky']['plugin'] === 'polylang' ? 'Polylang' : t('languages in the addresses')),
    $found === [] ? t('no language named') : implode(', ', array_map(fn (string $code, int $count): string => (Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code) . ' ' . $count, array_map('strval', array_keys($found)), array_map('intval', $found))))) ?>
	<?= e(t('Each post, page and category arrives in its language version and is linked to its original in the default language.')) ?>
<?php if ($missing !== []): ?>
	<?= e(t('Language versions the site does not have yet: %s – add them below, otherwise their posts are left out.', implode(', ', array_map(fn (string $code): string => Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code, $missing)))) ?>
<?php endif ?></p>
<?php if (($state['jazyky']['nepodporovane'] ?? []) !== []): ?>
<p class="hlaska hlaska-varovani"><?= e(t('Not imported – this site cannot offer these languages yet: %s.', implode(', ', array_map(fn (string $language, int $count): string => $language . ' (' . $count . ')', array_map('strval', array_keys($state['jazyky']['nepodporovane'])), array_map('intval', $state['jazyky']['nepodporovane']))))) ?></p>
<?php endif ?>
<?php endif ?>
<?php foreach ($p['stavitele'] ?? [] as $builder => $count): ?>
<p class="hlaska hlaska-varovani"><?= e(t('%s pages and posts were laid out with %s: the layout is not in the export, only the text in the post content comes over.', (int) $count, (string) $builder)) ?></p>
<?php endforeach ?>
<?php foreach ($p['typy'] ?? [] as $type => $t): ?>
<?php $prefixes = $t['predpony']; arsort($prefixes); $address = (string) (array_key_first($prefixes) ?? $type); ?>
<p><?= e(t('Custom post type “%s”: %s items become a collection with item pages at /%s/…, with the fields %s.', $type, (int) $t['pocet'], $address,
    $t['pole'] === [] ? t('none') : implode(', ', array_map(fn (string $key, array $votes): string => $key . ' (' . t(Kaleta\Builder\Collections::FIELD_TYPES[Kaleta\Core\WpTypes::fieldType($votes)] ?? 'text') . ')', array_keys($t['pole']), $t['pole'])))) ?>
<?php if ($t['vynechano'] !== []): ?> <?= e(t('Left out (repeaters, galleries or relationships – Claude can move them by hand): %s.', implode(', ', array_keys($t['vynechano'])))) ?><?php endif ?></p>
<?php endforeach ?>
<?php foreach ($p['seo'] ?? [] as $plugin => $n): ?>
<p><?= e(t('SEO plugin %s: %s custom titles, %s meta descriptions, %s noindex – they go into the SEO fields of the news items and pages. A title made only of the plugin’s variables is skipped; the site builds it itself.', $plugin, (int) $n['title'], (int) $n['description'], (int) $n['noindex'])) ?><?= (int) $n['canonical'] > 0 ? ' ' . e(t('Canonical URLs (%s) are not transferred.', (int) $n['canonical'])) : '' ?></p>
<?php endforeach ?>

<div class="hlaska hlaska-varovani">
<p><strong><?= e(t('What will not be converted')) ?></strong></p>
<ul>
	<li><?= e(t('User accounts and passwords – no account is created. A news item belongs to the user here with the same e-mail as its WordPress author, otherwise to you. Comments are not transferred.')) ?></li>
	<li><?= e(t('Widgets, appearance and plugin settings.')) ?></li>
	<li><?= e(t('Redirects managed by SEO plugins (SmartCrawl, Yoast SEO, Rank Math) are not part of the export – they come over separately.')) ?></li>
	<li><?= e(t('Private posts, trash, revisions and auto-drafts. A password-protected post is imported as a draft.')) ?></li>
<?php if ($p['jine'] !== []): ?>
	<li><?= e(t('Custom content types:')) ?> <?= e(implode(', ', array_map(fn (string $type, int $count): string => $type . ' (' . $count . ')', array_keys($p['jine']), $p['jine']))) ?></li>
<?php endif ?>
<?php if ($p['zkratky'] !== []): ?>
	<li><?= e(t('Plug-in shortcodes (forms, page builders…) – the tag disappears, the text inside stays:')) ?> <?= e(implode(', ', array_map(fn (string $z, int $count): string => '[' . $z . '] ' . $count . '×', array_keys($p['zkratky']), $p['zkratky']))) ?></li>
<?php endif ?>
	<li><?= e(t('Images stay on the old site for now; after the import you can download them to your site with one button.')) ?></li>
</ul>
</div>

<form class="formular" method="post" action="<?= e($module->url('run')) ?>">
<?= $csrf ?>
<input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
<fieldset>
<legend><?= e(t('Import options')) ?></legend>
<?php if (count($languages) > 1): ?>
<div class="radek"><label for="jazyk"><?= e(t('Language version')) ?></label><div><select id="jazyk" name="jazyk">
<?php foreach ($languages as $i => $code): ?>
	<option value="<?= $i === 0 ? '' : e($code) ?>"<?= ($i === 0 ? '' : $code) === $options['jazyk'] ? ' selected' : '' ?>><?= e(Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code) ?><?= $i === 0 ? ' – ' . e(t('default site language')) : '' ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('Which language version of the site the new categories and pages belong to.')) ?></span></div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('What to import')) ?></span><div class="volby">
	<label><input type="checkbox" name="koncepty" value="1"<?= $options['koncepty'] ? ' checked' : '' ?>> <?= e(t('drafts and posts pending review (%s)', (int) (($p['clanky']['draft'] ?? 0) + ($p['clanky']['pending'] ?? 0)))) ?></label>
	<label><input type="checkbox" name="stranky" value="1"<?= $options['stranky'] ? ' checked' : '' ?>> <?= e(t('pages (%s)', $converts($p['stranky']))) ?></label>
	<label><input type="checkbox" name="stavitel" value="1"<?= ($options['stavitel'] ?? true) ? ' checked' : '' ?>> <?= e(t('pages straight into the builder – edit them visually; the original text stays as a backup')) ?></label>
	<label><input type="checkbox" name="presmerovani" value="1"<?= $options['presmerovani'] ? ' checked' : '' ?>> <?= e(t('redirects from old addresses to new ones')) ?></label>
<?php if (($p['typy'] ?? []) !== []): ?>
	<label><input type="checkbox" name="kolekce" value="1"<?= ($options['kolekce'] ?? true) ? ' checked' : '' ?>> <?= e(t('custom post types as collections (%s)', implode(', ', array_keys($p['typy'])))) ?></label>
<?php endif ?>
<?php if (($p['menu'] ?? []) !== []): ?>
	<label><input type="checkbox" name="menu" value="1"<?= ($options['menu'] ?? true) ? ' checked' : '' ?>> <?= e(t('navigation menus (%s) into the draft look', count($p['menu']))) ?></label>
<?php endif ?>
<?php if (($missing ?? []) !== []): ?>
	<label><input type="checkbox" name="jazyky_pridat" value="1"<?= ($options['jazyky_pridat'] ?? false) ? ' checked' : '' ?>> <?= e(t('add the language versions %s to the site – they show in the language switcher at once', implode(', ', array_map(fn (string $code): string => Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code, $missing)))) ?></label>
<?php endif ?>
	<label><input type="checkbox" name="skryte" value="1"<?= ($options['skryte'] ?? false) ? ' checked' : '' ?>> <?= e(t('everything hidden – news as drafts, pages hidden; publish them when they are ready')) ?></label>
</div></div>
<?php if (!$redirectsEnabled): ?>
<p class="napoveda"><?= e(t('The redirects will be saved but only take effect once you turn on the Redirects feature.')) ?></p>
<?php endif ?>
<div class="radek"><label for="rubrika"><?= e(t('Put posts without a category into')) ?></label><div><select id="rubrika" name="rubrika">
	<option value="0"><?= e(t('a new “Uncategorised” category')) ?></option>
<?php foreach ($categories as $r): ?>
	<option value="<?= (int) $r['idt'] ?>"<?= (int) $r['idt'] === (int) $options['rubrika'] ? ' selected' : '' ?>><?= e($r['nazev']) ?><?= $r['jazyk'] !== '' ? ' (' . e($r['jazyk']) . ')' : '' ?></option>
<?php endforeach ?>
</select></div></div>
</fieldset>
<p class="napoveda"><?= e(t('Imported news is not announced: no webhook or IndexNow. Before a larger import, create a database backup in Settings → Backups and updates.')) ?></p>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Start import')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
