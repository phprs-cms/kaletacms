<?php
/**
 * My account → My e-mail signature (3.14): the signature from the user's own record in the Team collection, with the same
 * preview, Copy button and help as in Collections (admin/email_signature). 3.37: without a record, the fallback signature
 * from the account and the company details (Builder\UserSignature) – the same one replies from Kaleta carry.
 *
 * @var Kaleta\Core\App $app
 * @var bool $teamExists the site has a people collection
 * @var array{collection: array<string, mixed>, item: array<string, mixed>, chosen: bool}|null $record the user's record (Builder\TeamRecord)
 * @var array{html: string, text: string} $signature
 */
$intro = $record !== null
    ? t('Made from your record in %s (%s) in the look of your site. When your record or the site changes, open this page again and copy the signature anew.', (string) $record['collection']['nazev'], (string) $record['item']['nazev'])
    : t('Made from your details in My account (name, position, work phone, e-mail) and the company details, in the look of your site. When they change, open this page again and copy the signature anew. Replies you send from Kaleta carry it.');
?>
<?= $app->view->render('admin/account_tabs', ['app' => $app, 'current' => 'signature']) ?>
<?php if ($record === null && $teamExists): ?>
<p class="hlaska"><?= e(t('You are not linked to a record in the Team collection yet, so this is the common signature of the site. Ask an administrator to link you to your record.')) ?></p>
<?php endif ?>
<?= $app->view->render('admin/email_signature', ['signature' => $signature, 'intro' => $intro, 'back' => null]) ?>
