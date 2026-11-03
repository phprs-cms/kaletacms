<?php
/**
 * The fields of a maintenance window (3.30, Fleet\Rollout): the days of the week and the time range in the console's time
 * zone. Shared by a site's page and the customers' windows on the Rollout tab.
 *
 * @var array{days: list<int>, from: int, to: int}|null $window null = any time (no day ticked)
 * @var string $prefix makes the ids unique on a page
 */
$names = [1 => t('Mon'), 2 => t('Tue'), 3 => t('Wed'), 4 => t('Thu'), 5 => t('Fri'), 6 => t('Sat'), 7 => t('Sun')];
$hm = fn (int $min): string => sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
?>
<div class="flotila-dny" role="group" aria-label="<?= e(t('Days')) ?>">
<?php foreach ($names as $day => $name): ?>
	<label><input type="checkbox" name="window_days[]" value="<?= $day ?>"<?= $window !== null && in_array($day, $window['days'], true) ? ' checked' : '' ?>> <?= e($name) ?></label>
<?php endforeach ?>
</div>
<p class="flotila-cas"><label for="<?= e($prefix) ?>-from"><?= e(t('From')) ?></label> <input class="textpole" type="time" id="<?= e($prefix) ?>-from" name="window_from" value="<?= e($window !== null ? $hm($window['from']) : '22:00') ?>">
<label for="<?= e($prefix) ?>-to"><?= e(t('to')) ?></label> <input class="textpole" type="time" id="<?= e($prefix) ?>-to" name="window_to" value="<?= e($window !== null ? $hm($window['to']) : '05:00') ?>"></p>
