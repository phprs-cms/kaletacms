<?php
/**
 * A new deal (3.31) – from the board, or from a customer's or a company's page (they are filled in); 3.32: from an enquiry's
 * "Create deal" ($enquiry), prefilled from it, the value and the close left for the person.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $csrf
 * @var array<string, string> $draft
 * @var list<array{key: string, label: string, kind: string, custom: bool}> $stages
 * @var array<int, string> $people
 * @var array<int, string> $contacts
 * @var array<int, string> $companies
 * @var bool $canContacts
 * @var int $me
 * @var int $enquiry the enquiry the deal comes from (3.32), 0 = none
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('The pipeline')) ?></a><?php if ($enquiry > 0): ?> <a class="navigace" href="<?= e($app->url('admin.php?module=enquiries&action=detail&id=' . $enquiry)) ?>">← <?= e(t('Back to the enquiry')) ?></a><?php endif ?></p>
<?php if ($enquiry > 0): ?>
<p class="napoveda" data-deal-from-enquiry="<?= $enquiry ?>"><?= e(t('Filled in from the enquiry – check the name, add the value and the expected close if you know them, and create the deal.')) ?></p>
<?php endif ?>
<?= $app->view->render('admin/pipeline/form', ['module' => $module, 'csrf' => $csrf, 'd' => ['id' => 0], 'draft' => $draft, 'contacts' => $contacts, 'companies' => $companies,
    'people' => $people, 'stages' => $stages, 'canContacts' => $canContacts, 'me' => $me, 'enquiry' => $enquiry]) ?>
