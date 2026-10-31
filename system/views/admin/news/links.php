<?php
/**
 * Broken links across the site: news items, page builds and collection items (Core\Links).
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var string $csrf
 * @var list<array<string, mixed>> $links
 * @var int $checked
 * @var int $total
 * @var bool $isEnabled
 */
?>
<?php if ($app->request->get('module') === Kaleta\Admin\Modules\News::IDENT): /* 3.20 (C20): opened from Site care → Broken links, the screen is its own – no "back to news" */ ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to the news list')) ?></a></p>
<?php endif ?>
<?php if (!$isEnabled): ?>
<p class="hlaska"><?= e(t('Link checking is off (Settings → General → More options).')) ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t('In the background, the system goes through published news, pages and collection items – one every five minutes, each once a month – and checks whether their links still work. Checked: %s of %s.', $checked, $total)) ?></p>
<?php if ($links === []): ?>
<p><?= e(t('No broken links found.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Where')) ?></th><th scope="col"><?= e(t('Link')) ?></th><th scope="col"><?= e(t('Problem')) ?></th><th scope="col"><?= e(t('Found')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php $kinds = ['news' => 'News item', 'page' => 'Page', 'item' => 'Item']; foreach ($links as $o): ?>
<tr>
	<td><span class="smltxt"><?= e(t($kinds[$o['kind']] ?? 'Page')) ?>:</span> <a href="<?= e($o['edit']) ?>"><?= e($o['title']) ?></a><?= $o['element'] !== '' ? ' <span class="smltxt">(' . e(t('element %s', $o['element'])) . ')</span>' : '' ?></td>
	<td style="word-break:break-all"><a href="<?= e($o['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(mb_strimwidth($o['url'], 0, 90, '…')) ?></a></td>
	<td><?= e($o['status'] === 0 ? t('server does not respond') : ($o['status'] === 404 ? t('page does not exist (404)') : t('error %s', $o['status']))) ?></td>
	<td class="cislo"><?= e(format_date($o['found'])) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($module->url('links')) ?>"><?= $csrf ?><input type="hidden" name="kind" value="<?= e($o['kind']) ?>"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="navigace" type="submit"><?= e(t('Check again')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
