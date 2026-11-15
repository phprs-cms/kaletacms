<?php
/**
 * Media clean-up (2.14): unused files, oversized images, duplicates and images without a description.
 * The checkboxes of unused files and duplicate copies all belong to one delete form under the lists (form="smazani"),
 * so the tables can keep their own small forms (make smaller).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Media $module
 * @var string $csrf
 * @var list<array<string, mixed>> $unused
 * @var list<array<string, mixed>> $oversized
 * @var list<list<array<string, mixed>>> $duplicates
 * @var list<array<string, mixed>> $withoutAlt      images without alt the signed-in user may describe (at most Media::ALT_BATCH)
 * @var int $withoutAltTotal
 * @var int $total
 * @var bool $canShrink
 * @var callable $canEdit  fn (array $row): bool
 */
use Kaleta\Core\Files;
use Kaleta\Core\MediaHygiene;

$thumbnail = fn (array $o): string => $o['nahl_poloha'] === ''
    ? '<span class="galerie-soubor cistka-soubor">' . e(strtoupper(pathinfo($o['obr_poloha'], PATHINFO_EXTENSION))) . '</span>'
    : '<img src="' . e($app->url($o['nahl_poloha'])) . '" alt="" loading="lazy" width="80">';
$file = fn (array $o): string => '<a href="' . e($app->url($o['obr_poloha'])) . '" target="_blank" rel="noopener">' . e(basename($o['obr_poloha'])) . '</a>'
    . '<br><small>' . ($o['nahl_poloha'] === '' ? '' : (int) $o['obr_width'] . '&times;' . (int) $o['obr_height'] . ' &middot; ') . e(Files::size((int) $o['obr_vel'])) . ' &middot; ' . e(format_date($o['datum'])) . '</small>';
$deletable = 0;
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to media')) ?></a></p>
<p class="smltxt"><?= e(t('%d files in Media. The site found what can be cleaned up – nothing changes until you decide.', $total)) ?></p>

<h2><?= e(t('Unused files')) ?> (<?= count($unused) ?>)</h2>
<?php if ($unused === []): ?>
<p class="napoveda"><?= e(t('Every file is used somewhere on the site.')) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Nothing on the site points at these files: no page, build, news item, collection item, component, pop-up, newsletter or setting. Media has no trash – deleted files cannot be restored.')) ?></p>
<div class="tab-obal">
<table class="vypis cistka">
<thead><tr><th scope="col"><input type="checkbox" data-vybrat-vse="smazani" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Name (alternative text)')) ?></th></tr></thead>
<tbody>
<?php foreach ($unused as $o): $editable = $canEdit($o); $deletable += $editable ? 1 : 0; ?>
<tr>
	<td><?php if ($editable): ?><input type="checkbox" name="oznacene[]" value="<?= (int) $o['ido'] ?>" form="smazani" aria-label="<?= e(t('Delete %s', basename($o['obr_poloha']))) ?>"><?php endif ?></td>
	<td><?= $thumbnail($o) ?></td>
	<td><?= $file($o) ?></td>
	<td><?= e($o['nazev'] !== '' ? $o['nazev'] : t('untitled')) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<h2><?= e(t('Duplicates')) ?> (<?= count($duplicates) ?>)</h2>
<?php if ($duplicates === []): ?>
<p class="napoveda"><?= e(t('No file is uploaded twice.')) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('The same file uploaded more than once. Keep the one the site uses; an unused copy can be ticked and deleted below.')) ?></p>
<div class="tab-obal">
<table class="vypis cistka">
<thead><tr><th scope="col"></th><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Used')) ?></th></tr></thead>
<tbody>
<?php foreach ($duplicates as $i => $group): foreach ($group as $j => $o): $editable = $canEdit($o) && $o['pouzito'] === 0; $deletable += $editable ? 1 : 0; ?>
<tr<?= $j === 0 && $i > 0 ? ' class="cistka-skupina"' : '' ?>>
	<td><?php if ($editable): ?><input type="checkbox" name="oznacene[]" value="<?= (int) $o['ido'] ?>" form="smazani" aria-label="<?= e(t('Delete %s', basename($o['obr_poloha']))) ?>"><?php endif ?></td>
	<td><?= $thumbnail($o) ?></td>
	<td><?= $file($o) ?></td>
	<td><?= $o['pouzito'] > 0 ? e(t('used %s×', (int) $o['pouzito'])) . ($o['kde'] !== [] ? '<br><small>' . e(implode(', ', $o['kde'])) . '</small>' : '') : e(t('unused')) ?></td>
</tr>
<?php endforeach; endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<?php if ($deletable > 0): ?>
<form id="smazani" class="media-hromadne" method="post" action="<?= e($module->url('bulk')) ?>" data-potvrdit="<?= e(t('Delete the selected files for good? Media has no trash. Files the site still uses are skipped.')) ?>">
	<?= $csrf ?>
	<input type="hidden" name="provest" value="smaz">
	<input type="hidden" name="zpet" value="cleanup">
	<button class="navigace nebezpecne" type="submit"><?= e(t('Delete selected files')) ?></button>
</form>
<?php endif ?>

<h2><?= e(t('Oversized images')) ?> (<?= count($oversized) ?>)</h2>
<?php if ($oversized === []): ?>
<p class="napoveda"><?= e(t('No image is larger than %s or wider than %d px.', Files::size(MediaHygiene::OVERSIZED_BYTES), MediaHygiene::OVERSIZED_WIDTH)) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Images over %s or wider than %d px slow pages down. “Make smaller” re-encodes the image to %d px in place – its address and every page that shows it stay.', Files::size(MediaHygiene::OVERSIZED_BYTES), MediaHygiene::OVERSIZED_WIDTH, Kaleta\Core\Images::MAX_SIDE)) ?></p>
<div class="tab-obal">
<table class="vypis cistka">
<thead><tr><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Used')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($oversized as $o): ?>
<tr>
	<td><?= $thumbnail($o) ?></td>
	<td><?= $file($o) ?></td>
	<td><?= e($o['pouzito'] > 0 ? t('used %s×', (int) $o['pouzito']) : t('unused')) ?></td>
	<td class="akce"><?php if ($canShrink && $canEdit($o) && preg_match('/\.(jpg|png|webp)$/', $o['obr_poloha'])): ?>
		<form class="vradku" method="post" action="<?= e($module->url('shrink')) ?>"><?= $csrf ?><input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>"><button class="navigace" type="submit"><?= e(t('Make smaller')) ?></button></form>
<?php else: ?><span class="napoveda"><?= e(t('cannot be made smaller here')) ?></span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<h2><?= e(t('Images without a description')) ?> (<?= $withoutAltTotal ?>)</h2>
<?php if ($withoutAlt === []): ?>
<p class="napoveda"><?= e($withoutAltTotal === 0 ? t('Every image has a description for blind visitors (alt).') : t('Only the owner of an image or an administrator can describe it.')) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Describe what each image shows – screen readers and search engines read it. Empty fields are skipped; the rest is saved together.')) ?><?= $withoutAltTotal > count($withoutAlt) ? ' ' . e(t('Showing the first %d of %d.', count($withoutAlt), $withoutAltTotal)) : '' ?></p>
<form class="formular" method="post" action="<?= e($module->url('save_alts')) ?>">
	<?= $csrf ?>
	<div class="tab-obal">
	<table class="vypis cistka">
	<thead><tr><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Description for screen readers (alt)')) ?></th></tr></thead>
	<tbody>
<?php foreach ($withoutAlt as $o): ?>
	<tr>
		<td><?= $thumbnail($o) ?></td>
		<td><?= $file($o) ?></td>
		<td><input class="textpole siroke" type="text" name="alt[<?= (int) $o['ido'] ?>]" maxlength="150" aria-label="<?= e(t('Description of image %s', basename($o['obr_poloha']))) ?>"></td>
	</tr>
<?php endforeach ?>
	</tbody>
	</table>
	</div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save descriptions')) ?></button></p>
</form>
<?php endif ?>
