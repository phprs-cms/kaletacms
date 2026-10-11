<?php
/**
 * Consent to connecting an app via OAuth (the Claude connector and other MCP clients). Since 3.3.4 (N65) it leads with
 * the host the app returns to, warns when that is not one of Claude's own apps, and marks an app this site never
 * approved: anyone can register an app under any name, so the name alone proves nothing.
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array{nazev:string, redirect_uri:string} $pending
 * @var string $nonce the request this consent belongs to (N14)
 * @var array<string, mixed> $user
 * @var string $url  the host the app returns to after consent
 * @var bool $claudeHost whether that host belongs to Claude's own apps (Front\OAuth::CLAUDE_HOSTS)
 * @var bool $approved whether someone on this site has allowed this app before
 * @var string $selected the access chosen in advance
 * @var string $toolSet the tool set chosen in advance (3.10)
 */
$role = t(Kaleta\Core\Auth::TYPES[(int) $user['admin']] ?? '');
?>
<div class="oauth-souhlas">
	<p class="oauth-navrat"><?= e(t('After you allow it, the application returns to')) ?> <strong><?= e($url) ?></strong></p>
<?php if (!$claudeHost): ?>
	<p class="hlaska hlaska-varovani" role="alert"><?= e(t('%s is not an address of Claude’s own apps. Allow access only if you started this connection yourself and know this application – if a message or someone else sent you here, choose Deny.', $url)) ?></p>
<?php endif ?>
	<p class="oauth-kdo"><strong><?= e($pending['nazev']) ?></strong> <?= e(t('wants to work with the website %s.', $app->settings()->get('site_name'))) ?><?php if (!$approved): ?> <span class="stitek stitek-koncept"><?= e(t('new, not verified')) ?></span><?php endif ?></p>
<?php if (!$approved): ?>
	<p class="napoveda"><?= e(t('This website has not connected this application before. An application chooses its name itself, so the name is not verified – check the address above.')) ?></p>
<?php endif ?>
	<p><?= e(t('It will act with your account %s (%s) – never with more than your role allows.', (string) $user['user'], $role)) ?></p>
	<form method="post" action="<?= e($app->url('admin.php?action=oauth')) ?>">
		<?= $csrf ?>
		<input type="hidden" name="request" value="<?= e($nonce) ?>">
		<?= $app->view->render('admin/connection-access', ['role' => $role, 'selected' => $selected, 'claude' => $claudeHost, 'toolSet' => $toolSet, 'recommended' => 'drafts']) ?>
		<p class="napoveda"><?= e(t('You can revoke the connection at any time in My account → Connected applications.')) ?></p>
		<div class="tlacitka">
		<button class="tl" type="submit" name="povolit" value="1"><?= e(t('Allow access')) ?></button>
		<button class="navigace" type="submit" name="povolit" value="0"><?= e(t('Deny')) ?></button>
		</div>
	</form>
</div>
