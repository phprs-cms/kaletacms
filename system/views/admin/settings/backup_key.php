<?php
/**
 * 3.28 (D-W12, Core\RecoveryKey): the recovery key of the encrypted off-site backups – shown on this page only, until the
 * administrator confirms they saved it; it is kept nowhere else that the administration shows (not in the session, a log
 * or the change log).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Module $module
 * @var string $csrf
 * @var string $key
 */
?>
<div class="hlaska hlaska-varovani">
	<p><strong><?= e(t('Save this recovery key now, somewhere away from this server.')) ?></strong> <?= e(t('Off-site copies of the database are encrypted with it. Without it an off-site backup is useless – nobody can open it, not even the authors of Kaleta. If this server is lost, the key is the only way to restore the site from the off-site copy.')) ?></p>
	<p><code id="recovery-key" class="recovery-key"><?= e($key) ?></code> <button class="navigace" type="button" data-kopirovat="#recovery-key"><?= e(t('Copy')) ?></button></p>
	<p class="napoveda"><?= e(t('Keep it in a password manager, or print it and file it with the site\'s records. Anyone who has the key and an off-site copy can read the database, so do not send it by e-mail. Once you confirm below, the administration does not show it again.')) ?></p>
</div>
<form method="post" action="<?= e($module->url('backup_key_download')) ?>"><?= $csrf ?><p><button class="navigace" type="submit"><?= e(t('Download the recovery key (.txt)')) ?></button></p></form>
<form method="post" action="<?= e($module->url('backup_key_confirm')) ?>"><?= $csrf ?>
	<p><label><input type="checkbox" name="saved" value="1" required> <?= e(t('I have saved the recovery key somewhere away from this server')) ?></label></p>
	<p><button class="tl" type="submit"><?= e(t('Confirm and start the off-site copies')) ?></button>
	<a class="navigace" href="<?= e($module->url('', ['tab' => 'backups'])) ?>"><?= e(t('Back to Backups and updates')) ?></a></p>
</form>
