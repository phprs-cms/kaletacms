<?php
/**
 * My account → My e-mail signature (3.14): the signature from the user's own record in the Team collection, with the same
 * preview, Copy button and help as in Collections (admin/email_signature).
 *
 * @var Kaleta\Core\App $app
 * @var bool $teamExists the site has a people collection
 * @var array{collection: array<string, mixed>, item: array<string, mixed>, chosen: bool}|null $record the user's record (Builder\TeamRecord)
 * @var array{html: string, text: string}|null $signature
 */
?>
<?= $app->view->render('admin/account_tabs', ['app' => $app, 'current' => 'signature']) ?>
<?php if ($record !== null && $signature !== null): ?>
<?= $app->view->render('admin/email_signature', ['signature' => $signature,
    'intro' => t('Made from your record in %s (%s) in the look of your site. When your record or the site changes, open this page again and copy the signature anew.', (string) $record['collection']['nazev'], (string) $record['item']['nazev']),
    'back' => null]) ?>
<?php elseif ($teamExists): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('You are not linked to a record yet.'),
    'text' => t('Ask an administrator to link you to your record in the Team collection.')]) ?>
<?php else: ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('This site has no Team collection.'),
    'text' => t('E-mail signatures are made from the records of people in a Team collection.')]) ?>
<?php endif ?>
