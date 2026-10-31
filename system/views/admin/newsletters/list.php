<?php
/**
 * Newsletters: drafts, scheduled, being sent and sent, with counts.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Newsletters $module
 * @var string $csrf
 * @var list<array<string, mixed>> $newsletters
 * @var int $confirmed confirmed subscribers
 * @var ?string $problem why the site cannot send now
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New newsletter')) ?></a>
	<a class="navigace" href="<?= e($app->url('admin.php?module=subscribers')) ?>"><?= e(t('Confirmed subscribers: %d', $confirmed)) ?></a></p>
<?php if ($problem !== null): ?>
<p class="hlaska hlaska-varovani"><?= $module->hint(t($problem)) ?></p>
<?php endif ?>
<?php if ($newsletters === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'rozesilka', 'heading' => t('No newsletters yet.'), 'text' => t('Send your latest news to subscribers in an e-mail that follows the look of your site – colours, fonts and logo come from the design system.'), 'action' => [$module->url('new'), t('Write a newsletter')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Subject')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Recipients')) ?></th><th scope="col"><?= e(t('Sent')) ?></th><th scope="col"><?= e(t('Failed')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($newsletters as $n): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $n['id']])) ?>"><strong><?= e($n['subject']) ?></strong></a></td>
	<td><?= match ($n['status']) {
        'sent' => '<span class="stitek stitek-vydano">' . e(t('Sent')) . '</span> <span class="napoveda">' . e(format_date((string) $n['finished_at'], true)) . '</span>',
        'sending' => '<span class="stitek">' . e(t('Sending')) . '</span> <span class="napoveda">' . e(t('%d of %d', (int) $n['sent_count'] + (int) $n['failed_count'], (int) $n['recipients'])) . '</span>',
        'scheduled' => '<span class="stitek">' . e(t('Scheduled')) . '</span> <span class="napoveda">' . e(format_date((string) $n['scheduled_at'], true)) . '</span>',
        default => '<span class="stitek stitek-koncept">' . e(t('Draft')) . '</span>',
    } ?></td>
	<td class="cislo"><?= $n['status'] === 'sending' || $n['status'] === 'sent' ? (int) $n['recipients'] : '—' ?></td>
	<td class="cislo"><?= $n['status'] === 'sending' || $n['status'] === 'sent' ? (int) $n['sent_count'] : '—' ?></td>
	<td class="cislo"><?= (int) $n['failed_count'] > 0 ? '<strong>' . (int) $n['failed_count'] . '</strong>' : ($n['status'] === 'sent' ? '0' : '—') ?></td>
	<td class="akce"><a href="<?= e($module->url('edit', ['id' => $n['id']])) ?>"><?= e(in_array($n['status'], ['draft', 'scheduled'], true) ? t('Edit') : t('View')) ?></a>
<?php if ($n['status'] !== 'sending'): ?>
		· <form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the newsletter?')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="napoveda"><?= e(t('Nothing tracks whether subscribers open the e-mail. Links to the site carry utm parameters, so Statistics show the visits the newsletter brought.')) ?></p>
<?php endif ?>
