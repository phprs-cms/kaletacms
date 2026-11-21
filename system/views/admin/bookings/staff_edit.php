<?php
/**
 * One person who takes bookings (3.0): the services, the weekly hours and the days off.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, mixed> $m the person ([] = a new one)
 * @var list<array<string, mixed>> $services
 * @var array<int, string> $hours weekday => ranges as text
 * @var array<int, array<int, string>> $serviceHours service id => weekday => ranges as text (only services with their own hours)
 * @var list<array<string, mixed>> $offs
 * @var array<string, list<array{0: string, 1: string}>> $siteWeek the site's opening hours
 * @var array<int, string> $users
 * @var list<array{from: string, to: string}> $absences 3.49 B: the current and future absences of the linked account (days only)
 */
use Kaleta\Core\Booking;
use Kaleta\Core\Hours;

$isNew = $m === [];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('staff')) ?>">← <?= e(t('People')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('staff_save')) ?>">
<?= $csrf ?><input type="hidden" name="id" value="<?= (int) ($m['id'] ?? 0) ?>">
<fieldset><legend><?= e($isNew ? t('New person') : t('Person')) ?></legend>
<div class="radek"><label for="name"><?= e(t('Name')) ?></label><div><input class="textpole siroke" id="name" name="name" required maxlength="150" value="<?= e((string) ($m['name'] ?? '')) ?>"></div></div>
<div class="radek"><label for="email"><?= e(t('E-mail for notifications')) ?></label><div><input class="textpole siroke" type="email" id="email" name="email" maxlength="190" value="<?= e((string) ($m['email'] ?? '')) ?>"><span class="napoveda"><?= e(t('New and cancelled bookings arrive here; empty = the site e-mail from Settings.')) ?></span></div></div>
<div class="radek"><label for="user_id"><?= e(t('Account')) ?></label><div><select id="user_id" name="user_id"><option value="0">—</option>
<?php foreach ($users as $idu => $displayName): ?><option value="<?= (int) $idu ?>"<?= (int) ($m['user_id'] ?? 0) === (int) $idu ? ' selected' : '' ?>><?= e($displayName) ?></option><?php endforeach ?>
</select><span class="napoveda"><?= e(t('Optional: the administration user this person is.')) ?></span></div></div>
<div class="radek"><span><?= e(t('Services')) ?></span><div>
<?php if ($services === []): ?><span class="napoveda"><?= e(t('No services yet – save the person, then add a service and tick them in it.')) ?></span><?php endif ?>
<?php foreach ($services as $s): ?><label class="vradku"><input type="checkbox" name="services[]" value="<?= (int) $s['id'] ?>"<?= in_array($s['id'], $m['services'] ?? [], true) ? ' checked' : '' ?>> <?= e($s['name']) ?></label> <?php endforeach ?>
</div></div>
<div class="radek"><label for="sort_order"><?= e(t('Order')) ?></label><div><input class="textpole kratke" type="number" id="sort_order" name="sort_order" value="<?= (int) ($m['sort_order'] ?? 0) ?>"></div></div>
<div class="radek"><span></span><div><label><input type="checkbox" name="active" value="1"<?= ($m['active'] ?? true) ? ' checked' : '' ?>> <?= e(t('Takes bookings')) ?></label></div></div>
</fieldset>
<fieldset><legend><?= e(t('Weekly hours')) ?></legend>
<p class="napoveda"><?= e(t('Ranges like 9:00-12:00, 13:00-17:00; an empty day = no bookings that day. All empty = the opening hours of the site (Business details) with their exceptions.')) ?></p>
<?php foreach (Booking::WEEKDAYS as $d => $dayName): ?>
<div class="radek"><label for="hours_<?= $d ?>"><?= e(t($dayName)) ?></label><div><input class="textpole" id="hours_<?= $d ?>" name="hours_<?= $d ?>" maxlength="100" value="<?= e($hours[$d] ?? '') ?>" placeholder="<?= e(Hours::rangesText($siteWeek[$dayName] ?? [])) ?>"></div></div>
<?php endforeach ?>
</fieldset>
<?php foreach ($services as $s): if (!in_array($s['id'], $m['services'] ?? [], true)): continue; endif ?>
<fieldset><legend><?= e(t('Hours for %s', $s['name'])) ?></legend>
<p class="napoveda"><?= e(t('Only for this service: when set, these hours replace the weekly hours above for it. All empty = the weekly hours above. Bookings of all services share the same calendar of the person, so times never overlap.')) ?></p>
<?php foreach (Booking::WEEKDAYS as $d => $dayName): ?>
<div class="radek"><label for="hours_<?= (int) $s['id'] ?>_<?= $d ?>"><?= e(t($dayName)) ?></label><div><input class="textpole" id="hours_<?= (int) $s['id'] ?>_<?= $d ?>" name="hours_<?= (int) $s['id'] ?>_<?= $d ?>" maxlength="100" value="<?= e($serviceHours[$s['id']][$d] ?? '') ?>"></div></div>
<?php endforeach ?>
</fieldset>
<?php endforeach ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<?php if (!$isNew): ?>
<h2><?= e(t('Days off')) ?></h2>
<?php if ($absences !== []): /* 3.49 B: read here, set in the account – never the reason */ ?>
<section data-staff-absences>
<h3><?= e(t('Absences from the account')) ?></h3>
<ul>
<?php foreach ($absences as $a): ?>
<li><?= e(format_date($a['from'])) ?> – <?= e(format_date($a['to'])) ?></li>
<?php endforeach ?>
</ul>
<p class="napoveda"><?= e(t('Set in My account or Users; they close this person\'s times.')) ?></p>
</section>
<?php endif ?>
<?php if ($offs !== []): ?>
<ul>
<?php foreach ($offs as $o): ?>
<li><?= e(format_date($o['from'], true)) ?> – <?= e(format_date($o['to'], true)) ?><?= $o['staff_id'] === null ? ' (' . e(t('everyone')) . ')' : '' ?><?= $o['note'] !== '' ? ': ' . e($o['note']) : '' ?>
<?php if ($o['staff_id'] !== null): ?><form class="vradku" method="post" action="<?= e($module->url('off_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="staff_id" value="<?= (int) $m['id'] ?>"><button class="navigace" type="submit"><?= e(t('Remove')) ?></button></form><?php endif ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('off_save')) ?>">
<?= $csrf ?><input type="hidden" name="staff_id" value="<?= (int) $m['id'] ?>">
<div class="radek"><label for="off_from"><?= e(t('Off from')) ?></label><div><input class="textpole" type="date" id="off_from" name="off_from" required> <label><?= e(t('to')) ?> <input class="textpole" type="date" name="off_to"></label> <label><?= e(t('Note')) ?> <input class="textpole" name="note" maxlength="150" placeholder="<?= e(t('e.g. holiday')) ?>"></label> <input class="tl" type="submit" value="<?= e(t('Add')) ?>"></div></div>
</form>
<?php endif ?>
