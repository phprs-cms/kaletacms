<?php
/**
 * A personal data request (2.14): find, export or erase everything about one e-mail address.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Enquiries $module
 * @var string $csrf
 * @var string $email
 * @var array<string, mixed>|null $found Core\PersonalData::find
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All enquiries')) ?></a></p>
<p><?= e(t('When someone asks what the site keeps about them, or asks to be deleted: enter their e-mail address. The search covers enquiries (the sender and every field), the newsletter subscription, e-mails waiting to be sent, testimonial requests and bookings of appointments.')) ?></p>
<form class="formular" method="post" action="<?= e($module->url('personal')) ?>">
<?= $csrf ?>
<div class="radek"><label for="osobni-email"><?= e(t('E-mail address')) ?></label><div><input class="textpole" type="email" id="osobni-email" name="email" value="<?= e($email) ?>" required> <button class="tl" name="provest" value="find"><?= e(t('Find')) ?></button></div></div>
</form>
<?php if ($found !== null): ?>
<?php $counts = Kaleta\Core\PersonalData::counts($found); ?>
<h2><?= e(t('What the site keeps about %s', $email)) ?></h2>
<?php if (array_sum($counts) === 0): ?>
<p><?= e(t('Nothing – the site keeps no data about this address.')) ?></p>
<?php else: ?>
<ul>
<?php if ($counts['enquiries'] > 0): ?><li><?= e(t('Enquiries: %d', $counts['enquiries'])) ?> (<?= implode(', ', array_map(fn (array $r): string => '<a href="' . e($module->url('detail', ['id' => $r['idp']])) . '">#' . (int) $r['idp'] . '</a>', $found['enquiries'])) ?>)</li><?php endif ?>
<?php if ($found['subscriber'] !== null): ?><li><?= e(t('Newsletter subscription since %s', format_date((string) $found['subscriber']['datum']))) ?><?= (int) $found['subscriber']['stav'] === 1 ? '' : ' (' . e(t('not confirmed')) . ')' ?></li><?php endif ?>
<?php if ($counts['mail'] > 0): ?><li><?= e(t('E-mails in the outgoing queue: %d', $counts['mail'])) ?></li><?php endif ?>
<?php if ($counts['testimonials'] > 0): ?><li><?= e(t('Testimonial requests: %d', $counts['testimonials'])) ?></li><?php endif ?>
<?php if ($counts['bookings'] > 0): ?><li><?= e(t('Bookings of appointments: %d', $counts['bookings'])) ?> (<?= implode(', ', array_map(fn (array $r): string => '<a href="' . e($app->url('admin.php?module=bookings&action=detail&id=' . (int) $r['id'])) . '">#' . (int) $r['id'] . '</a>', $found['bookings'])) ?>)</li><?php endif ?>
<?php if (($counts['contacts'] ?? 0) > 0): // 3.28: the customer records (Core\Contacts) ?><li><?= e(t('Customer records: %d', $counts['contacts'])) ?> (<?= implode(', ', array_map(fn (array $c): string => '<a href="' . e($app->url(Kaleta\Core\Contacts::adminPath((int) $c['id']))) . '">#' . (int) $c['id'] . '</a>', $found['contacts'])) ?>)</li><?php endif ?>
<?php if (($counts['applications'] ?? 0) > 0): // 3.34: job applications (Core\Applications) ?><li data-personal-applications><?= e(t('Job applications: %d', $counts['applications'])) ?> (<?= implode(', ', array_map(fn (array $x): string => '<a href="' . e($app->url(Kaleta\Core\Applications::adminPath((int) $x['id']))) . '">#' . (int) $x['id'] . '</a>', $found['applications'])) ?>)</li><?php endif ?>
<?php if ($found['account'] !== null): ?><li><?= e(t('An account of the administration (%s) – change or remove it in Users; it is not erased here.', (string) $found['account']['jmeno'])) ?></li><?php endif ?>
</ul>
<form class="formular" method="post" action="<?= e($module->url('personal')) ?>">
<?= $csrf ?><input type="hidden" name="email" value="<?= e($email) ?>">
<div class="radek"><span></span><div><button class="tl" name="provest" value="export"><?= e(t('Download as a file for the person (JSON)')) ?></button></div></div>
</form>
<form class="formular" method="post" action="<?= e($module->url('personal')) ?>" data-potvrdit="<?= e(t('Erase everything listed above for good? This cannot be undone.')) ?>">
<?= $csrf ?><input type="hidden" name="email" value="<?= e($email) ?>">
<div class="radek"><span></span><div><label><input type="checkbox" name="potvrzeno" value="1"> <?= e(t('I want to erase this data for good')) ?></label> <button class="navigace nebezpecne" name="provest" value="erase"><?= e(t('Erase')) ?></button>
<span class="napoveda"><?= e(t('Enquiries with their attachments, the subscription (also in the connected mailing service), queued e-mails, testimonial requests and bookings are deleted. Backups keep older copies until they expire.')) ?></span></div></div>
</form>
<?php endif ?>
<?php endif ?>
