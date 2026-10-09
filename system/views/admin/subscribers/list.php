<?php
/**
 * @var Kaleta\Admin\Modules\Subscribers $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $subscribers
 * @var int $total
 * @var int $confirmed
 * @var string $search
 * @var int $pageNumber
 * @var string $service connected mailing service (empty = none)
 * @var array{ceka: ?string, chyby: ?string} $queue
 */
use Kaleta\Core\Newsletter;

$admin = $app->auth()->isAdmin();
?>
<p class="smltxt"><?= e(t('Addresses from the Newsletter sign-up element. Only people who confirmed by the link in the e-mail count as subscribers. Send them news under Newsletters, or with your own tool – the export includes the unsubscribe link.')) ?></p>
<?php if ($service !== ''): ?>
<div class="hlaska">
	<p><?= e(t('Confirmed subscribers go to %s automatically; those who unsubscribe are removed from it.', t(Newsletter::SERVICES[$service][0]))) ?>
	<?= (int) $queue['ceka'] > 0 ? e(t('Waiting to be sent: %d.', (int) $queue['ceka'])) : '' ?> <?= (int) $queue['chyby'] > 0 ? '<strong>' . e(t('Failed: %d.', (int) $queue['chyby'])) . '</strong>' : '' ?></p>
<?php if ($admin): ?>
	<p><form class="vradku" method="post" action="<?= e($module->url('sync')) ?>"><?= $csrf ?><button class="navigace" type="submit"><?= e(t('Send all confirmed subscribers to the service')) ?></button></form>
	<?php if ((int) $queue['chyby'] > 0): ?><form class="vradku" method="post" action="<?= e($module->url('retry')) ?>"><?= $csrf ?><button class="navigace" type="submit"><?= e(t('Try the failed ones again')) ?></button></form><?php endif ?>
	<a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Service settings')) ?></a></p>
<?php endif ?>
</div>
<?php elseif ($admin): ?>
<p class="smltxt"><?= e(t('Using Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing? Once connected in Features, the site sends confirmed subscribers straight to your list.')) ?> <a href="<?= e($app->url('admin.php?module=extensions#newsletter')) ?>"><?= e(t('Connect a service')) ?></a></p>
<?php endif ?>
<form class="navigace-radek" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="subscribers">
	<input class="textpole" type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Search e-mail')) ?>" aria-label="<?= e(t('Search e-mail')) ?>">
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button>
<?php if ($confirmed > 0): ?>
	<a class="tl" href="<?= e($module->url('csv')) ?>"><?= e(t('Export confirmed (CSV)')) ?> · <?= $confirmed ?></a>
<?php else: ?>
	<button class="tl" type="button" disabled><?= e(t('Export confirmed (CSV)')) ?> · 0</button>
<?php endif ?>
</form>
<?php if ($subscribers === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'newsletter', 'heading' => t($search !== '' ? 'Nothing found.' : 'No subscribers yet.'), 'text' => t('Put the Newsletter sign-up element on the website – for example in the footer.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Status')) ?></th><?php if ($service !== ''): ?><th scope="col"><?= e(t('Service')) ?></th><?php endif ?><th scope="col"><?= e(t('Subscribed')) ?></th><th scope="col"><?= e(t('Page')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($subscribers as $o): ?>
<tr<?= (int) $o['stav'] === 1 ? '' : ' class="nevydany"' ?>>
	<td><?= e($o['email']) ?></td>
	<td><?= (int) $o['stav'] === 1 ? '<span class="stitek stitek-vydano">' . e(t('confirmed')) . '</span>' : e(t('awaiting confirmation')) ?></td>
<?php if ($service !== ''): ?>
	<td><?= match ((string) $o['sync']) {
        'ok' => '<span class="stitek stitek-vydano">' . e(t('sent')) . '</span>',
        'ceka' => '<span class="stitek">' . e(t('waiting')) . '</span>',
        'chyba' => '<span class="stitek stitek-koncept" title="' . e(t((string) $o['sync_chyba'])) . '">' . e(t('error')) . '</span> <span class="napoveda">' . e(mb_strimwidth(t((string) $o['sync_chyba']), 0, 80, '…')) . '</span>',
        default => '—',
    } ?></td>
<?php endif ?>
	<td class="cislo"><?= e(format_date($o['datum'], true)) ?></td>
	<td class="smltxt"><?= e($o['zdroj']) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Remove the address from the subscriber list?')) ?>"><?= $csrf ?><input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php if ($total > 100): ?>
<p class="navigace-radek">
<?php if ($pageNumber > 1): ?><a class="navigace" href="<?= e($module->url('', ['search' => $search, 'page' => $pageNumber - 1])) ?>"><?= e(t('Previous')) ?></a><?php endif ?>
<?php if ($pageNumber * 100 < $total): ?><a class="navigace" href="<?= e($module->url('', ['search' => $search, 'page' => $pageNumber + 1])) ?>"><?= e(t('Next')) ?></a><?php endif ?>
</p>
<?php endif ?>
<?php endif ?>
