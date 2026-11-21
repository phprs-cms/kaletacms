<?php
/**
 * Business facts (2.10): the site's own facts and the built-in ones from the settings, with where each is used.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Facts $module
 * @var array<string, array<string, mixed>> $facts
 * @var array<string, int> $usage
 * @var list<array{token: string, value: string, about: string}> $computed the computed tokens with an example and its value now
 */
use Kaleta\Core\Facts;

$own = array_filter($facts, fn (array $f): bool => !$f['builtIn']);
$builtIn = array_filter($facts, fn (array $f): bool => $f['builtIn']);
?>
<p class="hlaska"><?= e(t('Write a fact once and use it everywhere: {{fact.key}} in a text, a button or a link (tel:{{fact.company_phone}}). The site fills in the value when a page is shown; change it here and every page says the new value.')) ?></p>
<p><a class="tl" href="<?= e($module->url('edit')) ?>"><?= e(t('New fact')) ?></a> <a class="navigace" href="<?= e($module->url('claims')) ?>"><?= e(t('Sentences with numbers that are not facts yet')) ?></a></p>
<?php if ($own === []): // 3.50.1 (UX-l3) ?>
<?= $app->view->render('admin/empty', ['icon' => 'fakta', 'heading' => t('No facts yet.'), 'text' => t('No facts yet. Typical ones: the year the company was founded, the number of projects or clients, a price from, the warranty, the area you serve.'),
    'action' => [$module->url('edit'), t('New fact')]]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Fact')) ?></th><th scope="col"><?= e(t('Value')) ?></th><th scope="col"><?= e(t('Token')) ?></th><th scope="col"><?= e(t('Used')) ?></th><th scope="col"><?= e(t('Changed')) ?></th></tr></thead>
<tbody>
<?php foreach ($own as $f): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['key' => $f['key']])) ?>"><strong><?= e($f['label']) ?></strong></a><br><span class="smltxt"><?= e(t(Facts::TYPES[$f['type']] ?? $f['type'])) ?><?= $f['schema'] !== '' ? ' · schema.org ' . e($f['schema']) : '' ?></span></td>
	<td><?= e($f['display']) ?></td>
	<td><code>{{fact.<?= e($f['key']) ?>}}</code></td>
	<td class="stred"><?= (int) ($usage[$f['key']] ?? 0) ?></td>
	<td><?= $f['updated'] !== null ? e(format_date((string) $f['updated'])) : '' ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<h2><?= e(t('From the settings')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Fact')) ?></th><th scope="col"><?= e(t('Value')) ?></th><th scope="col"><?= e(t('Token')) ?></th><th scope="col"><?= e(t('Used')) ?></th></tr></thead>
<tbody>
<?php foreach ($builtIn as $f): ?>
<tr><td><?= e($f['label']) ?></td><td><?= $f['display'] !== '' ? e($f['display']) : '<span class="smltxt">' . e(t('not filled in')) . '</span>' ?></td><td><code>{{fact.<?= e($f['key']) ?>}}</code></td><td class="stred"><?= (int) ($usage[$f['key']] ?? 0) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="smltxt"><?= e(t('The company details are changed in Business details. Claude reads and changes facts with list_facts and save_fact.')) ?></p>
<h2><?= e(t('Computed')) ?></h2>
<p><?= e(t('Numbers the site works out when a page is shown, so they never go stale – years since a date and counts of what is on the site. Write the token into a text, or into the number of a counter.')) ?></p>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Token')) ?></th><th scope="col"><?= e(t('Shows now')) ?></th><th scope="col"><?= e(t('What it counts')) ?></th></tr></thead>
<tbody>
<?php foreach ($computed as $c): ?>
<tr><td><code><?= e($c['token']) ?></code></td><td><?= $c['value'] !== '' ? e($c['value']) : '<span class="smltxt">' . e(t('nothing – the site has no such collection or fact yet')) . '</span>' ?></td><td><?= e(t($c['about'])) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
