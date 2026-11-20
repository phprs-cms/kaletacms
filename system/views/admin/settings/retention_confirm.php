<?php
/**
 * 3.48 B (U40): confirming a shorter retention period (Settings::actionRetention) – the way a new time zone is confirmed.
 * The proposed numbers come in the address and go back as hidden fields; the POST checks them again, nothing is kept in
 * the session.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Settings $module
 * @var string $csrf
 * @var array<string, string> $proposed setting => value
 * @var list<array{key: string, setting: string, from: int|string, to: int|string, unit: string, harsher: bool, consequence: bool}> $changes
 * @var array<string, int|null> $past period key => records past the new limit today (null = not counted)
 */
use Kaleta\Core\Retention;

$own = array_filter($changes, fn (array $c): bool => !$c['consequence']);
$follow = array_filter($changes, fn (array $c): bool => $c['consequence']);
?>
<form class="formular" method="post" action="<?= e($module->url('retention')) ?>" data-retention-confirm>
<?= $csrf ?>
<?php foreach ($proposed as $setting => $value): ?>
<input type="hidden" name="<?= e($setting) ?>" value="<?= e($value) ?>">
<?php endforeach ?>
<p><?= e(t('You are changing how long the site keeps personal data. Data past a shorter period is deleted or anonymised by the next daily clean-up – it cannot be brought back.')) ?></p>
<ul>
<?php foreach ($own as $c): ?>
	<li<?= $c['harsher'] ? ' data-retention-harsher' : '' ?>><strong><?= e(Retention::changeLine($c)) ?></strong><?php if (isset($past[$c['key']])): ?> – <?= e(t('%d records are past the new limit', (int) $past[$c['key']])) ?><?php endif ?></li>
<?php endforeach ?>
</ul>
<?php if ($follow !== []): ?>
<p><?= e(t('This also changes:')) ?></p>
<ul>
<?php foreach ($follow as $c): ?>
	<li><?= e(Retention::changeLine($c)) ?><?php if (isset($past[$c['key']])): ?> – <?= e(t('%d records are past the new limit', (int) $past[$c['key']])) ?><?php endif ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<p class="napoveda"><?= e(t('Check the periods your national law and your contracts require. The other settings of the tab are already saved.')) ?></p>
<p class="tlacitka">
	<button class="tl" type="submit" name="choice" value="apply"><?= e(t('Shorten the retention')) ?></button>
	<button class="navigace" type="submit" name="choice" value="cancel"><?= e(t('Keep the current periods')) ?></button>
</p>
</form>
