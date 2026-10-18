<?php
/**
 * E-mail signature of a person (2.10) from Collections – the shared screen admin/email_signature (My account uses it too).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 * @var array{html: string, text: string} $signature
 */
?>
<?= $app->view->render('admin/email_signature', ['signature' => $signature,
    'intro' => t('Made from the record of %s in the look of your site. When the record or the site changes, open this page again and copy the signature anew.', $p['nazev']),
    'back' => [$module->url('item', ['id' => (int) $k['idk'], 'item' => (int) $p['idp']]), t('Back to the item')]]) ?>
