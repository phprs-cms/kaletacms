<?php
/**
 * Site parts: header, footer and wrappers – status (from the layout / from the builder) and the way into the builder.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var array<string, array{0:string, 1:string}> $types
 * @var list<string> $languages  '' = default language of the site
 * @var array<string, string> $languageNames
 * @var array<string, array<string, mixed>> $rows  "typ:jazyk" => status of the part
 * @var array<string, list<array<string, mixed>>> $variants  "typ:jazyk" => variants (header, footer)
 * @var array<int, string> $pageNames
 * @var array<string, string> $collectionNames
 */
?>
<p class="napoveda"><?= e(t('The header and footer appear on every page. Wrappers add sections around content assembled by the system – news items, lists and the 404 message. Until you publish a part from the builder, it has its default design.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Part')) ?></th><?php if (count($languages) > 1): ?><th scope="col"><?= e(t('Language')) ?></th><?php endif ?><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($types as $type => [$name, $description]): ?>
<?php foreach ($languages as $language): $r = $rows[$type . ':' . $language] ?? null; $params = ['type' => $type, 'language' => $language]; ?>
<tr>
	<td><a href="<?= e($module->url('builder', $params)) ?>"><strong><?= e(t($name)) ?></strong></a><br><small class="napoveda"><?= e(t($description)) ?></small></td>
<?php if (count($languages) > 1): ?>
	<td><?= e($languageNames[$language]) ?></td>
<?php endif ?>
	<td><?php if ($r !== null && $r['publikovana']): ?><span class="stitek stitek-vydano"><?= e(t('from the builder')) ?></span><?php else: ?><span class="stitek"><?= e(t('default')) ?></span><?php endif ?><?= $r !== null && $r['zmeny'] ? ' <span class="stitek stitek-koncept">' . e(t('unpublished changes')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($module->url('builder', $params)) ?>"><?= e(t($r === null ? 'Edit in the builder' : 'Builder')) ?></a><?php if (Kaleta\Builder\PartTemplates::LIST[$type] ?? []): ?> · <a href="<?= e($module->url('templates', $params)) ?>"><?= e(t('Start from a template')) ?></a><?php endif ?><?php if (in_array($type, Kaleta\Builder\SiteParts::WITH_VARIANTS, true)): ?> · <a href="<?= e($module->url('variant', $params)) ?>"><?= e(t('Add variant')) ?></a><?php endif ?><?php if ($r !== null): ?> ·
		<form class="vradku" method="post" action="<?= e($module->url('template', $params)) ?>" data-potvrdit="<?= e(t('Revert this part to its default design? The builder version stays in the history.')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Revert to default')) ?></button></form><?php endif ?></td>
</tr>
<?php foreach ($variants[$type . ':' . $language] ?? [] as $v): $variantParams = $params + ['variant' => $v['varianta']]; $onPages = array_filter(array_map(fn (int $i): ?string => $pageNames[$i] ?? null, array_map('intval', json_decode((string) $v['stranky'], true) ?: [])));
	$rules = Kaleta\Builder\SiteParts::sanitizeRules(json_decode((string) ($v['pravidla'] ?? ''), true));
	$onContent = array_merge($rules['novinky'] ? [t('news items')] : [], $rules['vypis'] ? [t('the news list')] : [],
		array_map(fn (string $c): string => t('items of %s', $collectionNames[$c] ?? $c), $rules['kolekce']),
		array_map(fn (int $i): string => t('pages under %s', $pageNames[$i] ?? '#' . $i), $rules['nadrazene'])); ?>
<tr>
	<td>↳ <a href="<?= e($module->url('builder', $variantParams)) ?>"><?= e($v['nazev']) ?></a><br><small class="napoveda"><?= $onPages === [] && $onContent === [] ? e(t('not on any page yet')) : e(implode(' · ', array_merge($onPages !== [] ? [t('on pages: %s', implode(', ', $onPages))] : [], $onContent !== [] ? [t('also: %s', implode(', ', $onContent))] : []))) ?></small></td>
<?php if (count($languages) > 1): ?>
	<td></td>
<?php endif ?>
	<td><?php if ($v['publikovana']): ?><span class="stitek stitek-vydano"><?= e(t('variant')) ?></span><?php else: ?><span class="stitek stitek-koncept"><?= e(t('nepublikovaná')) ?></span><?php endif ?><?= $v['zmeny'] && $v['publikovana'] ? ' <span class="stitek stitek-koncept">' . e(t('unpublished changes')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($module->url('builder', $variantParams)) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('templates', $variantParams)) ?>"><?= e(t('Start from a template')) ?></a> · <a href="<?= e($module->url('variant', $variantParams)) ?>"><?= e(t('Pages')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('template', $variantParams)) ?>" data-potvrdit="<?= e(t('Delete the variant? The selected pages will get the default version.')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
<?php endforeach ?>
<?php endforeach ?>
</tbody>
</table>
</div>
