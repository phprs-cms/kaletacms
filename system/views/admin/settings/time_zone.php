<?php
/**
 * Confirming a new time zone (3.12, Core\TimeZoneConversion): the saved moments move to the new zone's clock, or they keep
 * the clock time they were written with. Calendar dates and civil times never move.
 *
 * @var Kaleta\Admin\Modules\Settings $module
 * @var string $csrf
 * @var string $from the zone of today
 * @var string $to the new zone
 */
$noon = new DateTimeImmutable('today 12:00', new DateTimeZone($from));
$converted = $noon->setTimezone(new DateTimeZone($to))->format('G:i');
?>
<form class="formular" method="post" action="<?= e($module->url('time_zone')) ?>">
<?= $csrf ?>
<input type="hidden" name="zone" value="<?= e($to) ?>">
<p><?= e(t('You are changing the site’s time zone from %s to %s.', str_replace('_', ' ', $from), str_replace('_', ' ', $to))) ?>
	<?= e(t('The dates saved so far were written on the clock of %s. What should happen to them?', str_replace('_', ' ', $from))) ?></p>
<ul>
	<li><strong><?= e(t('Convert saved dates')) ?></strong> – <?= e(t('scheduled publishing, newsletters, creation and change times, sign-ins and logs move to the new clock and keep their moment: a news item scheduled for 12:00 in %s is then scheduled for %s in %s. It runs in batches; on a large site it finishes in the background.', str_replace('_', ' ', $from), $converted, str_replace('_', ' ', $to))) ?></li>
	<li><strong><?= e(t('Keep their clock time')) ?></strong> – <?= e(t('every saved date stays as written: a news item scheduled for 12:00 is published at 12:00 in %s.', str_replace('_', ' ', $to))) ?></li>
</ul>
<p class="napoveda"><?= e(t('Never converted, whichever you choose: calendar days (visible until, review by, the days of statistics), opening hours and their exceptions, bookings and days off, and the times of scheduled Claude runs – they mean the clock where the business is.')) ?></p>
<p class="tlacitka">
	<button class="tl" type="submit" name="choice" value="convert"><?= e(t('Convert saved dates')) ?></button>
	<button class="navigace" type="submit" name="choice" value="keep"><?= e(t('Keep their clock time')) ?></button>
	<a href="<?= e($module->url('', ['tab' => 'general'])) ?>"><?= e(t('Cancel')) ?></a>
</p>
</form>
