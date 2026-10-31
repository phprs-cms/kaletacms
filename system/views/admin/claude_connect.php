<?php
/**
 * "Connect Claude" on the dashboard (3.5): until a Claude connection has ever called the site, the dashboard leads with this
 * card – the MCP address with Copy, three short steps and a warning when the site is not on HTTPS – then First steps,
 * and Ask Claude below (Core\AskClaude::connected).
 *
 * @var Kaleta\Core\App $app
 * @var array{url: string, admin: bool} $connect
 */
?>
<section class="ask-claude pripojit-claude" id="pripojit-claude" aria-labelledby="pripojit-claude-nadpis">
	<h2 id="pripojit-claude-nadpis"><?= e(t('Connect Claude')) ?></h2>
	<p class="smltxt"><?= e(t('Build and edit the site by talking to Claude. Connect it once – it takes a minute – and Claude works on the site in your name and with your permissions.')) ?></p>
	<?= $app->view->render('admin/mcp_address', ['url' => $connect['url'], 'id' => 'mcp-adresa-prehled']) ?>
	<ol>
		<li><?= e(t('In the Claude app open Settings → Connectors → Add custom connector.')) ?></li>
		<li><?= e(t('Paste the address above as the connector URL and add it.')) ?></li>
		<li><?= e(t('Claude sends you here to sign in. Choose what it may do – then ask it anything about your site.')) ?></li>
	</ol>
	<p class="smltxt"><?= e(t('Claude Code or another tool without sign-in? Create a personal token in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.<?php if ($connect['admin']): ?> <a href="<?= e($app->url('admin.php?module=claude_settings#how-to-connect')) ?>"><?= e(t('Claude settings')) ?></a><?php endif ?></p>
</section>
