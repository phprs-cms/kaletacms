<?php
/**
 * A new company (3.28).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var array<string, string> $draft
 * @var array<int, string> $people
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('companies')) ?>">← <?= e(t('All companies')) ?></a></p>
<?= $app->view->render('admin/contacts/company_form', ['module' => $module, 'csrf' => $csrf, 'co' => ['id' => 0], 'draft' => $draft, 'people' => $people]) ?>
