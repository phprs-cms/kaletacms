<?php
/**
 * The tabs of Applications for administrators (3.34 B): the applications, their settings and the move of enquiries by form.
 *
 * @var Kaleta\Admin\Modules\Applications $module
 * @var string $current list | settings | move
 */
?>
<nav class="zalozky" aria-label="<?= e(t('Applications')) ?>" data-applications-admin-tabs>
	<a href="<?= e($module->url()) ?>"<?= $current === 'list' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Applications')) ?></a>
	<a href="<?= e($module->url('settings')) ?>"<?= $current === 'settings' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Settings')) ?></a>
</nav>
