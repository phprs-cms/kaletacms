<?php
/**
 * A new contact by hand (3.28) – for a customer who called or came in person; the others come from the site by themselves.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var array<string, mixed> $draft
 * @var list<array<string, mixed>> $companies
 * @var array<int, string> $people
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All customers')) ?></a></p>
<p class="napoveda"><?= e(t('Enquiries, bookings and newsletter subscriptions create their customer by themselves. Add one here for someone who called or came in person – an e-mail address, a name or a phone number is enough.')) ?></p>
<?= $app->view->render('admin/contacts/form', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'c' => ['id' => 0], 'draft' => array_map('strval', $draft), 'companies' => $companies, 'people' => $people]) ?>
