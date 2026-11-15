<?php
/**
 * The site's popups with counters.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Popups $module
 * @var string $csrf
 * @var list<array<string, mixed>> $popups
 */
use Kaleta\Builder\Popups;

?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New pop-up')) ?></a></p>
<?php if ($popups === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'popupy', 'heading' => t('No pop-ups yet.'), 'text' => t('A window over the page for a newsletter sign-up, a download, an announcement or an offer. You build the content in the builder and set when and where it shows. The counters work without cookies.'), 'action' => [$module->url('new'), t('Create a pop-up')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Pop-up')) ?></th><th scope="col"><?= e(t('When it shows')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Views')) ?></th><th scope="col"><?= e(t('Closes')) ?></th><th scope="col"><?= e(t('Conversions')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($popups as $p): ?>
<?php
    $unit = Popups::TRIGGERS[$p['spoustec']][1] ?? '';
    $when = t(Popups::TRIGGERS[$p['spoustec']][0] ?? '') . ($unit !== '' ? ': ' . $p['hodnota'] . ' ' . t($unit) : '');
?>
<tr>
	<td><a href="<?= e($module->url('builder', ['id' => $p['idpp']])) ?>"><strong><?= e($p['nazev']) ?></strong></a><br><span class="napoveda"><?= e(t(Popups::TYPES[$p['typ']][0] ?? '')) ?> · <code>#popup-<?= e($p['adresa']) ?></code></span></td>
	<td><?= e($when) ?><br><span class="napoveda"><?= e($p['pravidla']['kde'] === 'vse' ? t('on the whole site') : t('in selected places')) ?><?= $p['spoustec'] !== 'klik' ? ' · ' . e(t(Popups::FREQUENCIES[$p['cetnost']] ?? '')) : '' ?></span></td>
	<td><?php if ($p['aktivni']): ?><span class="stitek stitek-vydano"><?= e(t('on')) ?></span><?php elseif ($p['stavba'] === null): ?><span class="stitek stitek-koncept"><?= e(t('unpublished')) ?></span><?php else: ?><span class="stitek"><?= e(t('off')) ?></span><?php endif ?><?= $p['stavba_koncept'] !== null && $p['stavba'] !== null && $p['stavba_koncept'] !== $p['stavba'] ? ' <span class="stitek stitek-koncept">' . e(t('unpublished changes')) . '</span>' : '' ?><?= $p['valid_until'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($p['valid_until']))) . '</span>' : '' ?><?= $p['review_by'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($p['review_by']))) . '</span>' : '' ?></td>
	<td class="cislo"><?= (int) $p['zobrazeni'] ?></td>
	<td class="cislo"><?= (int) $p['zavreni'] ?></td>
	<td class="cislo"><?= (int) $p['konverze'] ?><?= $p['zobrazeni'] > 0 ? ' <span class="napoveda">(' . e(t('%d%%', (int) round($p['konverze'] / $p['zobrazeni'] * 100))) . ')</span>' : '' ?></td>
	<td class="akce">
		<a href="<?= e($module->url('builder', ['id' => $p['idpp']])) ?>"><?= e(t('Edit in the builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $p['idpp']])) ?>"><?= e(t('Settings')) ?></a>
		· <form class="vradku" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="idpp" value="<?= (int) $p['idpp'] ?>"><button class="navigace" type="submit"><?= e($p['aktivni'] ? t('Turn off') : t('Turn on')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="napoveda"><?= e(t('A conversion is a form sent or a newsletter sign-up in the pop-up. The counters work without cookies and without any visitor data.')) ?></p>
<?php endif ?>
