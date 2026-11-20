<?php
/**
 * Absences of one person (3.42, Core\Absences) – the current and future ones with their deputy and reason, a remove button
 * each, and the form to add one. Shared by My account (own absences) and Users (an administrator, anybody's).
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array{id: int, user_id: int, starts_on: string, ends_on: string, deputy_id: int|null, reason: string}> $absences
 * @var array<int, string> $people  who may stand in (active accounts without the person), id => name
 * @var string $addUrl  where the add form posts
 * @var string $deleteUrl  where a remove button posts
 * @var string $hidden  extra hidden inputs (HTML), e.g. the account in Users or the step in My account
 * @var string $deleteHidden  extra hidden inputs of a remove button
 * @var bool $own  3.47: the person's own absences (My account) – the deputy's empty choice speaks in the first person
 */
$today = Kaleta\Core\Clock::today();
$own ??= false;
?>
<fieldset id="absences">
<legend><?= e(t('Absence')) ?></legend>
<p class="napoveda"><?= e(t('While someone is away, their notifications also go to their deputy (without one, to whom they report), and new enquiries routed to them go to the deputy instead. What they are responsible for stays theirs.')) ?></p>
<?php if ($absences !== []): ?>
<ul class="nepritomnosti" aria-label="<?= e(t('Recorded absences')) ?>">
<?php foreach ($absences as $a): ?>
	<li><form class="vradku" method="post" action="<?= e($deleteUrl) ?>" data-potvrdit="<?= e(t('Remove this absence?')) ?>"><?= $csrf ?><?= $deleteHidden ?><input type="hidden" name="absence" value="<?= $a['id'] ?>">
		<strong><?= e(format_date($a['starts_on']) . ' – ' . format_date($a['ends_on'])) ?></strong>
		<span class="stitek"><?= e(Kaleta\Core\Absences::reasonLabel($a['reason'])) ?></span><?= $a['starts_on'] <= $today ? ' <span class="stitek stitek-vydano">' . e(t('away now')) . '</span>' : '' ?>
		<?php if ($a['deputy_id'] !== null): ?><span class="smltxt"><?= e(t('Deputy: %s', $people[$a['deputy_id']] ?? Kaleta\Core\Assignment::label($app->db(), $a['deputy_id']))) ?></span><?php endif ?>
		<button class="navigace nebezpecne" type="submit"><?= e(t('Remove')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<form class="formular" method="post" action="<?= e($addUrl) ?>"><?= $csrf ?><?= $hidden ?>
<div class="radek"><label for="absence-from"><?= e(t('First day')) ?></label><input class="textpole" type="date" id="absence-from" name="starts_on" min="<?= e($today) ?>" required data-date-min-for="absence-to"></div>
<div class="radek"><label for="absence-to"><?= e(t('Last day')) ?></label><input class="textpole" type="date" id="absence-to" name="ends_on" min="<?= e($today) ?>" required></div>
<div class="radek"><label for="absence-reason"><?= e(t('Reason')) ?></label><div><select id="absence-reason" name="reason">
<?php foreach (Kaleta\Core\Absences::REASONS as $key => $label): ?>
	<option value="<?= e($key) ?>"><?= e(t($label)) ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('Only these choices – never write health or other private details.')) ?></span></div></div>
<div class="radek"><label for="absence-deputy"><?= e(t('Deputy')) ?></label><div><select id="absence-deputy" name="deputy_id">
	<option value=""><?= e(t($own ? 'nobody – whom I report to' : 'nobody – whom they report to')) ?></option>
<?php foreach ($people as $uid => $name): ?>
	<option value="<?= $uid ?>"><?= e($name) ?></option>
<?php endforeach ?>
</select></div></div>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Add the absence')) ?></button></p>
</form>
</fieldset>
