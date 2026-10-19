<?php
/**
 * 3.15 (N42): config.php could not be written – the line to add, shown once on this page (it is kept nowhere: not in the
 * session, a log or the change log). Nothing has changed yet; the button runs the move again once the line is there.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Module $module
 * @var string $csrf
 * @var string $line
 */
?>
<div class="hlaska hlaska-varovani">
	<p><?= e(t('config.php cannot be written, so nothing has changed. Add this line to config.php right after “return [” (or “return array (”), then press the button again:')) ?></p>
	<p><code id="vault-radek"><?= e($line) ?></code> <button class="navigace" type="button" data-kopirovat="#vault-radek"><?= e(t('Copy')) ?></button></p>
	<p class="napoveda"><?= e(t('This line is shown only now. If you leave this page without adding it, press the button again – a new key is made and nothing is lost.')) ?></p>
</div>
<form method="post" action="<?= e($module->url('vault_move')) ?>"><?= $csrf ?><button class="tl" type="submit"><?= e(t('Move the encryption key out of the database')) ?></button>
	<a class="navigace" href="<?= e($module->url('', ['tab' => 'backups'])) ?>"><?= e(t('Back to Backups and updates')) ?></a></form>
