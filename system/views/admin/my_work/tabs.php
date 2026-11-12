<?php
/**
 * The tabs of My work: their own work; 3.41 the team summary (counts only – everyone, per person for administrators);
 * for administrators (3.27) the open tasks of the whole team.
 *
 * @var Kaleta\Admin\Modules\MyWork $module
 * @var string $current '' = mine, 'summary', 'team'
 * @var bool $team the Team tab (administrators)
 */
$team ??= false;
?>
<nav class="zalozky" aria-label="<?= e(t('My work')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $current === '' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Mine')) ?></a>
	<a href="<?= e($module->url('summary')) ?>"<?= $current === 'summary' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Summary')) ?></a>
<?php if ($team): ?>
	<a href="<?= e($module->url('team')) ?>"<?= $current === 'team' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Team')) ?></a>
<?php endif ?>
</nav>
