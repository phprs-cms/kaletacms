<?php
/**
 * The tabs of My work for administrators (3.27): their own work, and the open tasks of the whole team.
 *
 * @var Kaleta\Admin\Modules\MyWork $module
 * @var string $current '' = mine, 'team'
 */
?>
<nav class="zalozky" aria-label="<?= e(t('My work')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $current === '' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Mine')) ?></a>
	<a href="<?= e($module->url('team')) ?>"<?= $current === 'team' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Team')) ?></a>
</nav>
