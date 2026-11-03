<?php
/**
 * A new deal (3.31) – from the board, or from a customer's or a company's page (they are filled in). An enquiry has its own
 * "Create deal", which takes the customer and the person handling it.
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
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('The pipeline')) ?></a></p>
<?= $app->view->render('admin/pipeline/form', ['module' => $module, 'csrf' => $csrf, 'd' => ['id' => 0], 'draft' => $draft, 'contacts' => $contacts, 'companies' => $companies,
    'people' => $people, 'stages' => $stages, 'canContacts' => $canContacts, 'me' => $me]) ?>
