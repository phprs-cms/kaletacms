<?php
/**
 * The shared design kit of the fleet (2.16, Fleet\Kit): choose what the console shares, publish a new version, see which
 * version each site applied.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var array<string, mixed> $designSystem the console's published design system
 * @var array<string, array{styl: array<string, mixed>, css: string, draft: bool}> $classes published classes
 * @var list<array<string, mixed>> $components rows of ka_komponenty
 * @var list<array<string, mixed>> $sections idx, nazev
 * @var list<array{version: int, created_at: string, summary: string, author: ?string}> $kits newest first
 * @var list<array<string, mixed>> $sites rows of ka_fleet_sites
 * @var array<int, int> $applied site id => the kit version it applied (0 = none)
 * @var array<int, string> $customers id => name (3.17: a kit version for one customer's sites)
 */
use Kaleta\Builder\DesignSystem;

$newest = $kits[0]['version'] ?? 0;
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'kit']) ?>
<p class="hlaska"><?= e(t('The kit carries the design of this console – its design system, shared classes, components and saved sections – to the sites that chose to receive it (Settings → Fleet console on the site). A site gets each version as drafts only: the look draft, component drafts and its Sections library. Its people review and publish; nothing goes live by itself. Custom code never travels in a kit.')) ?></p>
<form class="formular" method="post" action="<?= e($module->url('kit_publish')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('What the kit contains')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Design system')) ?></span><div class="volby"><label><input type="checkbox" name="design_system" value="1" checked> <?= e(t('Colours, fonts and sizes')) ?></label>
<span class="napoveda"><?= e(implode(' · ', array_map(fn (string $k, string $label): string => t($label) . ' ' . $designSystem['barvy'][$k], array_keys(DesignSystem::COLORS), DesignSystem::COLORS))) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Shared classes')) ?></span><div class="volby">
<?php if ($classes === []): ?><span class="smltxt"><?= e(t('The console has no shared classes.')) ?></span><?php else: ?>
<label><input type="checkbox" name="classes_all" value="1"> <?= e(t('All classes (%d)', count($classes))) ?></label>
<?php foreach ($classes as $name => $c): ?><label><input type="checkbox" name="classes[]" value="<?= e($name) ?>"> <code><?= e($name) ?></code></label> <?php endforeach ?>
<?php endif ?></div></div>
<div class="radek"><span class="popisek"><?= e(t('Components')) ?></span><div class="volby">
<?php if ($components === []): ?><span class="smltxt"><?= e(t('The console has no components.')) ?></span><?php endif ?>
<?php foreach ($components as $k): ?><label><input type="checkbox" name="components[]" value="<?= (int) $k['idm'] ?>"> <?= e((string) $k['nazev']) ?><?= $k['stavba'] === null ? ' <span class="smltxt">(' . e(t('draft only')) . ')</span>' : '' ?></label> <?php endforeach ?>
</div></div>
<div class="radek"><span class="popisek"><?= e(t('Saved sections')) ?></span><div class="volby">
<?php if ($sections === []): ?><span class="smltxt"><?= e(t('The console has no saved sections.')) ?></span><?php endif ?>
<?php foreach ($sections as $sec): ?><label><input type="checkbox" name="sections[]" value="<?= (int) $sec['idx'] ?>"> <?= e((string) $sec['nazev']) ?></label> <?php endforeach ?>
</div></div>
<?php if ($customers !== []): ?>
<div class="radek"><label for="kit-customer"><?= e(t('Who receives it')) ?></label><div><select id="kit-customer" name="customer">
	<option value="0"><?= e(t('every site that receives the kit')) ?></option>
<?php foreach ($customers as $id => $name): ?>
	<option value="<?= (int) $id ?>"><?= e(t('only the sites of %s', $name)) ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('A version for one customer reaches only that customer\'s sites; the others stay on the newest version meant for everyone.')) ?></span></div></div>
<?php endif ?>
<p><button class="tl" type="submit" data-potvrdit="<?= e(t('Publish kit version %d? Every site that receives the kit gets it as drafts with its next report.', $newest + 1)) ?>"><?= e(t('Publish a new kit version')) ?></button>
<span class="napoveda"><?= e(t('A component or section keeps a stable key (from its name): a site updates its copy instead of adding another.')) ?></span></p>
</fieldset>
</form>
<?php if ($kits !== []): ?>
<h2><?= e(t('Published versions')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('Published')) ?></th><th scope="col"><?= e(t('Contents')) ?></th><th scope="col"><?= e(t('Sites on this version')) ?></th></tr></thead>
<tbody>
<?php foreach ($kits as $kit): ?>
<tr><td><?= (int) $kit['version'] ?></td><td><?= e(format_date(new DateTimeImmutable($kit['created_at']), true)) ?><?= $kit['author'] !== null ? ' · ' . e((string) $kit['author']) : '' ?></td><td><?= e($kit['summary']) ?><?= $kit['customer_id'] !== null ? ' · ' . e(t('only the sites of %s', $customers[$kit['customer_id']] ?? '#' . $kit['customer_id'])) : '' ?></td><td class="stred"><?= count(array_keys($applied, (int) $kit['version'], true)) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php if ($sites !== []): ?>
<h2><?= e(t('Which version each site applied')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Kit version')) ?></th></tr></thead>
<tbody>
<?php foreach ($sites as $site): $v = $applied[(int) $site['id']] ?? 0; ?>
<tr><td><a href="<?= e($module->url('detail', ['id' => (int) $site['id']])) ?>"><?= e((string) $site['name'] !== '' ? (string) $site['name'] : (string) $site['url']) ?></a></td>
<td><?php if ($v === 0): ?><span class="smltxt"><?= e(t('does not receive the kit')) ?></span><?php else: ?><?= $v ?><?= $v < $newest ? ' <span class="stitek stitek-koncept">' . e(t('older')) . '</span>' : '' ?><?php endif ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="smltxt"><?= e(t('A site reports the version it applied with its next report; a site that did not switch the kit on shows none.')) ?></p>
<?php endif ?>
