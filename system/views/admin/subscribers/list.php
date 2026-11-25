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
 * @var int $pageCount
 * @var string $service connected mailing service (empty = none)
 * @var array{ceka: ?string, chyby: ?string} $queue
 * @var list<array{language: string, confirmed: ?string, waiting: ?string}> $languages confirmed and waiting per language version (3.51)
 * @var array{bounced: int, refused: int} $register addresses on the send register (3.51, Core\SendRegister)
 * @var array<int, string> $marks the register's kind of a listed row (ido => bounced | refused)
 */
use Kaleta\Admin\Modules\Subscribers;
use Kaleta\Core\Language;
use Kaleta\Core\Newsletter;

$admin = $app->auth()->isAdmin();
$defaultLanguage = Language::defaults($app->settings());
$languageName = fn (string $code): string => Language::AVAILABLE[$code !== '' ? $code : $defaultLanguage][0] ?? $code; // the language's own name
$multilingual = count($languages) > 1 || ($languages !== [] && (string) $languages[0]['language'] !== '');
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
<?php if ($languages !== [] || $register['bounced'] + $register['refused'] > 0): ?>
<p class="smltxt" data-subscriber-counts><?= e(implode(' · ', array_map(fn (array $l): string => t('%s: %d confirmed, %d waiting', $languageName((string) $l['language']), (int) $l['confirmed'], (int) $l['waiting']), $languages))) ?>
<?php if ($register['bounced'] + $register['refused'] > 0): ?><?= $languages !== [] ? ' · ' : '' ?><?= e(t('on the register: %d bounced, %d refused', $register['bounced'], $register['refused'])) ?><?php endif ?></p>
<p class="napoveda"><?= e(t('A bounced address stays subscribed and is skipped until an administrator lifts the bounce. An address that refused our e-mails comes back only by a new confirmed sign-up.')) ?></p>
<?php endif ?>
<form class="navigace-radek" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="subscribers">
	<input class="textpole" type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Search e-mail')) ?>" aria-label="<?= e(t('Search e-mail')) ?>">
	<button class="navigace" type="submit"><?= e(t('Filter')) ?></button>
<?php if ($search !== ''): ?>
	<span class="smltxt" data-found><?= e(t('Found: %s', format_count($total))) ?></span>
<?php endif ?>
<?php if ($confirmed > 0): ?>
	<a class="tl" href="<?= e($module->url('csv')) ?>"><?= e(t('Export confirmed (CSV)')) ?> · <?= $confirmed ?></a>
<?php else: ?>
	<button class="tl" type="button" disabled><?= e(t('Export confirmed (CSV)')) ?> · 0</button>
<?php endif ?>
</form>
<?php if ($subscribers === [] && $search !== ''): /* 3.52 (A8): a search without a match is not "no subscribers yet" */ ?>
<?= $app->view->render('admin/empty', ['icon' => 'newsletter', 'heading' => t('Nothing matches the filter.'), 'text' => t('Try another word.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($subscribers === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'newsletter', 'heading' => t('No subscribers yet.'), 'text' => t('Put the Newsletter sign-up element on the website – for example in the footer.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Status')) ?></th><?php if ($multilingual): ?><th scope="col"><?= e(t('Language')) ?></th><?php endif ?><?php if ($service !== ''): ?><th scope="col"><?= e(t('Service')) ?></th><?php endif ?><th scope="col"><?= e(t('Subscribed')) ?></th><th scope="col"><?= e(t('Page')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($subscribers as $o): ?>
<tr<?= (int) $o['stav'] === 1 ? '' : ' class="nevydany"' ?>>
	<td><bdi><?= mark_words((string) $o['email'], $search) ?></bdi></td>
	<td><?= (int) $o['stav'] === 1 ? '<span class="stitek stitek-vydano">' . e(t('confirmed')) . '</span>' : e(t('awaiting confirmation')) ?>
<?php if (isset($marks[(int) $o['ido']])): ?> <span class="stitek stitek-koncept" data-register="<?= e($marks[(int) $o['ido']]) ?>"><?= e($marks[(int) $o['ido']] === 'bounced' ? t('bounced') : t('refused')) ?></span><?php endif ?></td>
<?php if ($multilingual): ?>	<td><?= e($languageName((string) $o['language'])) ?></td>
<?php endif ?><?php if ($service !== ''): ?>
	<td><?= match ((string) $o['sync']) {
        'ok' => '<span class="stitek stitek-vydano">' . e(t('sent')) . '</span>',
        'ceka' => '<span class="stitek">' . e(t('waiting')) . '</span>',
        'chyba' => '<span class="stitek stitek-koncept" title="' . e(t((string) $o['sync_chyba'])) . '">' . e(t('error')) . '</span> <span class="napoveda">' . e(mb_strimwidth(t((string) $o['sync_chyba']), 0, 80, '…')) . '</span>',
        default => '—',
    } ?></td>
<?php endif ?>
	<td class="cislo"><?= e(format_date($o['datum'], true)) ?></td>
	<td class="smltxt"><?= e($o['zdroj']) ?>
<?php /* 3.54: the proof of consent – the wording the person agreed to and the form or element it was given on */ ?>
		<details data-consent><summary><?= e(t('Consent')) ?></summary>
<?php if ((string) $o['consent_text'] !== ''): ?>
		<p>“<?= e((string) $o['consent_text']) ?>”</p>
<?php else: ?>
		<p><?= e(t('No wording stored – signed up before 3.54, or the sign-up form could not be read.')) ?></p>
<?php endif ?>
<?php if (Subscribers::consentForm($o) !== ''): ?>
		<p><?= e(t('Form: %s', Subscribers::consentForm($o))) ?></p>
<?php endif ?>
		</details></td>
	<td class="akce"><?php if ($admin && ($marks[(int) $o['ido']] ?? '') === 'bounced'): ?><form class="vradku" method="post" action="<?= e($module->url('lift')) ?>" data-potvrdit="<?= e(t('Newsletters will be sent to this address again. Lift the bounce?')) ?>"><?= $csrf ?><input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>"><button class="navigace" type="submit"><?= e(t('Lift the bounce')) ?></button></form> <?php endif ?><form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Remove the address from the subscriber list?')) ?>"><?= $csrf ?><input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?= $app->view->render('admin/paging', ['module' => $module, 'query' => ['search' => $search], 'page' => $pageNumber, 'pages' => $pageCount]) ?>
<?php endif ?>
