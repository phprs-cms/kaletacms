<?php
/**
 * Online bookings (3.0): by day, with filters; the set-up links and the settings for administrators.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, list<array<string, mixed>>> $byDay day => bookings
 * @var string $shown nadchazejici | dnes | minule | vse
 * @var array{from: string, to: string, status: string, staff: int, service: int} $filter
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $staff
 * @var array{lead: int, horizon: int, cancel: int, reminder: int, hold: int, pendingThanks: string, pendingMail: string, declinedMail: string} $settings
 * @var int $waiting requests waiting for the provider's answer
 * @var int $months
 * @var string $expiry
 * @var bool $isAdmin
 * @var array{person: bool, service: bool, page: bool} $setup how far the set-up is (3.5)
 * @var array{0: string, 1: string}|null $noFreeTime why visitors could book nothing in the next 14 days (3.5)
 */
use Kaleta\Core\Booking;

$query = array_filter(['view' => $shown === 'nadchazejici' ? '' : $shown, 'staff' => $filter['staff'] ?: '', 'service' => $filter['service'] ?: '']);
?>
<?php if ($isAdmin && in_array(false, $setup, true)): // the set-up card (3.5): one order – a person, a service, a page ?>
<section class="pruvodce" aria-labelledby="rezervace-nastaveni">
	<div class="pruvodce-hlava"><h2 id="rezervace-nastaveni"><?= e(t('Set up Bookings')) ?> <small><?= count(array_filter($setup)) ?> / 3</small></h2></div>
	<ol class="pruvodce-kroky">
		<li class="<?= $setup['person'] ? 'hotovo' : '' ?>"><a href="<?= e($module->url($setup['person'] ? 'staff' : 'staff_edit')) ?>"><strong><?= e(t('Add a person')) ?></strong><span><?= e(t('Who takes the bookings – or one entry for the whole business – with their weekly hours.')) ?></span></a></li>
		<li class="<?= $setup['service'] ? 'hotovo' : '' ?>"><a href="<?= e($module->url('services', $setup['service'] ? [] : ['new' => 1])) ?>"><strong><?= e(t('Add a service')) ?></strong><span><?= e(t('What visitors book and how long it takes – tick the person who offers it.')) ?></span></a></li>
		<li class="<?= $setup['page'] ? 'hotovo' : '' ?>"><?php if ($setup['page']): ?><a href="<?= e($app->url('admin.php?module=pages')) ?>"><strong><?= e(t('Create a Book page')) ?></strong><span><?= e(t('A page with the Booking element, where visitors pick a time.')) ?></span></a><?php else: ?>
			<form method="post" action="<?= e($module->url('book_page')) ?>"><?= $csrf ?><button type="submit"><strong><?= e(t('Create a Book page')) ?></strong><span><?= e(t('A page with the Booking element opens in the builder. It stays hidden until you publish it.')) ?></span></button></form><?php endif ?></li>
	</ol>
</section>
<?php endif ?>
<nav class="zalozky" aria-label="<?= e(t('Bookings')) ?>">
<?php foreach (['nadchazejici' => 'Upcoming', 'dnes' => 'Today', 'minule' => 'Last 30 days', 'vse' => 'All'] as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['view' => $key === 'nadchazejici' ? '' : $key]) + array_diff_key($query, ['view' => 1]))) ?>"<?= $shown === $key ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="module" value="bookings"><input type="hidden" name="view" value="<?= e($shown === 'nadchazejici' ? '' : $shown) ?>">
	<label><?= e(t('Person')) ?> <select name="staff"><option value="0"><?= e(t('everyone')) ?></option><?php foreach ($staff as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $filter['staff'] === $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach ?></select></label>
	<label><?= e(t('Service')) ?> <select name="service"><option value="0"><?= e(t('all services')) ?></option><?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $filter['service'] === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach ?></select></label>
	<label><?= e(t('Status')) ?> <select name="status"><option value=""><?= e($shown === 'nadchazejici' ? t('confirmed and waiting') : t('any')) ?></option><?php foreach (Booking::STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $filter['status'] === $key && $shown !== 'nadchazejici' ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?></select></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>">
</form>
<?php if ($noFreeTime !== null): ?>
<p class="hlaska hlaska-varovani"><?= e(t($noFreeTime[0], $noFreeTime[1])) ?><?php if ($isAdmin): ?> <a href="<?= e($module->url('staff')) ?>"><?= e(t('People')) ?></a><?php endif ?></p>
<?php endif ?>
<?php if ($waiting > 0): ?>
<p class="hlaska hlaska-varovani"><a href="<?= e($module->url('', ['status' => 'pending', 'view' => 'vse'])) ?>"><?= e(t('%d requests are waiting for your answer.', $waiting)) ?></a></p>
<?php endif ?>
<p><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New booking')) ?></a>
<?php if ($isAdmin): ?> <a class="navigace" href="<?= e($module->url('services')) ?>"><?= e(t('Services')) ?> (<?= count($services) ?>)</a> <a class="navigace" href="<?= e($module->url('staff')) ?>"><?= e(t('People')) ?> (<?= count($staff) ?>)</a><?php endif ?></p>
<?php if (!$setup['service']): ?>
<?= $app->view->render('admin/empty', ['icon' => 'rezervace', 'heading' => t('Set up the booking first.'), 'text' => $isAdmin ? t('Follow the three steps above: a person, a service, a page. Visitors then pick a service, a person, a day and a free time.') : t('An administrator adds the people, the services and the booking page. Bookings then appear here.'), 'action' => null]) ?>
<?php elseif ($byDay === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'rezervace', 'heading' => t('No bookings here.'), 'text' => t('Nothing booked for these days and filters.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php else: ?>
<?php foreach ($byDay as $day => $rows): ?>
<h2><?= e(format_date_long($day)) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Time')) ?></th><th scope="col"><?= e(t('Service')) ?></th><th scope="col"><?= e(t('Person')) ?></th><th scope="col"><?= e(t('Customer')) ?></th><th scope="col"><?= e(t('Status')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $b): ?>
<tr<?= !in_array($b['status'], ['confirmed', 'pending'], true) ? ' class="nevydany"' : '' ?>>
	<td><a href="<?= e($module->url('detail', ['id' => (int) $b['id']])) ?>"><strong><?= e(substr((string) $b['starts_at'], 11, 5)) ?>–<?= e(substr((string) $b['ends_at'], 11, 5)) ?></strong></a></td>
	<td><?= e((string) ($b['service'] ?? '')) ?></td>
	<td><?= e((string) ($b['staff'] ?? '')) ?></td>
	<td><?= $b['anonymised_at'] !== null ? '<span class="smltxt">' . e(t('anonymised')) . '</span>' : e((string) $b['name']) . ((string) $b['phone'] !== '' ? ' · ' . e((string) $b['phone']) : '') ?></td>
	<td><?= e(t(Booking::STATUSES[$b['status']] ?? $b['status'])) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endforeach ?>
<?php endif ?>
<?php if ($isAdmin): ?>
<h2><?= e(t('Settings')) ?></h2>
<form class="formular" method="post" action="<?= e($module->url('settings')) ?>">
<?= $csrf ?>
<div class="radek"><label for="lead"><?= e(t('Earliest booking')) ?></label><div><input class="textpole kratke" type="number" id="lead" name="lead" min="0" max="720" value="<?= $settings['lead'] ?>"> <?= e(t('hours ahead')) ?></div></div>
<div class="radek"><label for="horizon"><?= e(t('Bookable ahead')) ?></label><div><input class="textpole kratke" type="number" id="horizon" name="horizon" min="1" max="365" value="<?= $settings['horizon'] ?>"> <?= e(t('days')) ?></div></div>
<div class="radek"><label for="cancel"><?= e(t('Cancel link works until')) ?></label><div><input class="textpole kratke" type="number" id="cancel" name="cancel" min="0" max="720" value="<?= $settings['cancel'] ?>"> <?= e(t('hours before the start')) ?></div></div>
<div class="radek"><label for="reminder"><?= e(t('Reminder e-mail')) ?></label><div><input class="textpole kratke" type="number" id="reminder" name="reminder" min="0" max="168" value="<?= $settings['reminder'] ?>"> <?= e(t('hours before the start (0 = none)')) ?></div></div>
<div class="radek"><label for="hold"><?= e(t('Hold a request for')) ?></label><div><input class="textpole kratke" type="number" id="hold" name="hold" min="1" max="720" value="<?= $settings['hold'] ?>"> <?= e(t('hours')) ?>
<span class="napoveda"><?= e(t('Services that need confirmation: the requested time is held this long. Then it is free again and you get a reminder; the customer hears nothing until you answer.')) ?></span></div></div>
<div class="radek"><label for="pending_thanks"><?= e(t('Thank-you after a request')) ?></label><div><input class="textpole siroke" id="pending_thanks" name="pending_thanks" maxlength="400" value="<?= e($settings['pendingThanks']) ?>" placeholder="<?= e(t('empty = the built-in text')) ?>"></div></div>
<div class="radek"><label for="pending_mail"><?= e(t('Acknowledgement e-mail')) ?></label><div><textarea class="textpole siroke" id="pending_mail" name="pending_mail" rows="3" maxlength="1000" placeholder="<?= e(t('empty = the built-in text')) ?>"><?= e($settings['pendingMail']) ?></textarea>
<span class="napoveda"><?= e(t('The opening text of the e-mail; the details follow. Write it in your own tone and form of address; {name} is the customer\'s name.')) ?></span></div></div>
<div class="radek"><label for="declined_mail"><?= e(t('Decline e-mail')) ?></label><div><textarea class="textpole siroke" id="declined_mail" name="declined_mail" rows="3" maxlength="1000" placeholder="<?= e(t('empty = the built-in text')) ?>"><?= e($settings['declinedMail']) ?></textarea>
<span class="napoveda"><?= e(t('The opening text; your personal message from the request follows it.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<p class="smltxt"><?= e($months > 0 ? t('Bookings follow the enquiry retention: %d months after the appointment they are %s (Enquiries → settings).', $months, t($expiry === 'anonymise' ? 'anonymised' : 'deleted')) : t('Bookings are kept for good – set a retention in Enquiries so personal data does not stay forever.')) ?> <?= e(t('Claude can check free times and set up services and people; reading bookings is recorded in the change log.')) ?></p>
<?php endif ?>
