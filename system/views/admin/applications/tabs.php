<?php
/**
 * The tabs of Applications (3.34 B, 3.40): the list, the board of one position, the talent pool and the statistics for
 * everyone with the section; the settings for administrators (3.47 U64: the move of enquiries by form is linked from the
 * settings and shows this strip with Settings current).
 *
 * @var Kaleta\Admin\Modules\Applications $module
 * @var string $current list | board | pool | stats | settings
 * @var bool $admin the signed-in person is an administrator
 */
$tabs = ['list' => [$module->url(), t('Applications')], 'board' => [$module->url('board'), t('Board')], 'pool' => [$module->url('pool'), t('Talent pool')],
    'stats' => [$module->url('stats'), t('Statistics')]] + ($admin ? ['settings' => [$module->url('settings'), t('Settings')]] : []);
?>
<nav class="zalozky" aria-label="<?= e(t('Applications sections')) ?>" data-applications-admin-tabs>
<?php foreach ($tabs as $key => [$url, $label]): ?>
	<a href="<?= e($url) ?>"<?= $current === $key ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</nav>
